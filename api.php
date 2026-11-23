<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
require_once __DIR__ . '/server/common.php';
require_once __DIR__ . '/server/auth.php';
require_once __DIR__ . '/server/services.php';
require_once __DIR__ . '/server/customer.php';
require_once __DIR__ . '/server/routes.php';
securityHeaders();
$GLOBALS['tt_after_commit'] = [];
$db = null;
$lock = null;
$createdFiles = [];
$commitStarted = false;
$commitConfirmed = false;
try {
    $config = loadConfig();
    $rawPath = $_GET['path'] ?? '/api/health';
    if (!is_string($rawPath) || strlen($rawPath) > 2048 || str_contains($rawPath, "\0")) fail(400, 'Ruta inválida.');
    $parts = parse_url($rawPath);
    if (!$parts || isset($parts['host']) || isset($parts['scheme']) || isset($parts['fragment'])) fail(400, 'Ruta inválida.');
    $path = rawurldecode($parts['path'] ?? '');
    if (!str_starts_with($path, '/api/') && !str_starts_with($path, '/media/')) fail(404, 'Ruta no encontrada.');
    $query = [];
    parse_str($parts['query'] ?? '', $query);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) fail(405, 'Método no permitido.');
    $mutable = !in_array($method, ['GET', 'HEAD'], true) || ($path === '/api/session' && $method === 'GET');
    if (!in_array($method, ['GET', 'HEAD'], true)) requestOrigin($config);
    $body = [];
    $bytes = null;
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        $limit = $path === '/api/uploads' ? 5 * 1024 * 1024 : 65536;
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $limit) fail(413, 'El archivo o formulario excede el tamaño permitido.');
        $input = fopen('php://input', 'rb');
        if (!$input) fail(400, 'No se pudo leer el formulario.');
        $bytes = stream_get_contents($input, $limit + 1);
        fclose($input);
        if ($bytes === false || strlen($bytes) > $limit) fail(413, 'El archivo o formulario excede el tamaño permitido.');
        if ($path !== '/api/uploads') {
            if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) fail(415, 'Envía JSON con Content-Type application/json.');
            try { $object = json_decode($bytes, false, 64, JSON_THROW_ON_ERROR); }
            catch (JsonException $error) { fail(400, 'JSON inválido.'); }
            if (!$object instanceof stdClass) fail(400, 'El formulario JSON debe ser un objeto.');
            $body = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        }
    }
    $db = connectDatabase($config);
    $GLOBALS['tt_rate_db'] = connectDatabase($config);
    if ($mutable) $lock = mutationLock($db, $config);
    $db->beginTransaction();
    $session = readSession($db, $config);
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!$session || !is_string($token) || !hash_equals($session['csrfToken'], $token)) fail(403, 'La sesión del formulario cambió. Recarga e intenta de nuevo.', 'csrf_invalid');
    }
    if (str_starts_with($path, '/media/')) {
        if (!in_array($method, ['GET', 'HEAD'], true)) fail(405, 'Método no permitido.');
        $id = substr($path, 7);
        if (!preg_match('/^[a-zA-Z0-9-]{1,100}$/D', $id)) fail(404, 'Imagen no encontrada.');
        $image = queryOne($db, 'SELECT * FROM uploads WHERE id=?', [$id]);
        if (!$image) fail(404, 'Imagen no encontrada.');
        if ($image['purpose'] !== 'product') {
            $user = requireUser($session, ['cliente', 'admin', 'tecnico']);
            if ($user['id'] !== $image['user_id'] && !in_array($user['role'], ['admin', 'tecnico'], true)) fail(404, 'Imagen no encontrada.');
        }
        if (!preg_match('/^img-[a-zA-Z0-9-]+\.(jpg|png|webp)$/D', $image['filename'])) fail(404, 'Imagen no encontrada.');
        $file = $config['upload_dir'] . '/' . $image['filename'];
        if (!is_file($file)) fail(404, 'Imagen no encontrada.');
        $db->commit();
        header('Content-Type: ' . $image['mime']);
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: ' . ($image['purpose'] === 'product' ? 'public,max-age=3600' : 'private,no-store'));
        if ($method !== 'HEAD') readfile($file);
        exit;
    }
    if ($path === '/api/uploads' && $method === 'POST') {
        $purpose = $query['purpose'] ?? 'tradein';
        if (!in_array($purpose, ['product', 'tradein'], true)) fail(400, 'Destino de imagen inválido.');
        $user = requireUser($session, $purpose === 'product' ? ['admin'] : ['cliente']);
        rate($db, 'upload:' . $user['id'], 20, 3600000);
        $mime = strtolower(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]);
        $extensions = ['image/png' => '.png', 'image/jpeg' => '.jpg', 'image/webp' => '.webp'];
        $size = strlen($bytes ?? '');
        if (!isset($extensions[$mime]) || $size < 32) fail(415, 'Sólo se permiten archivos JPG, PNG o WebP con contenido válido.');
        $info = @getimagesizefromstring($bytes);
        $detected = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!$info || ($info['mime'] ?? '') !== $mime || $detected !== $mime || $info[0] * $info[1] > 40000000) fail(415, 'La imagen no es válida o sus dimensiones son demasiado grandes.');
        $daily = queryOne($db, 'SELECT COUNT(*) AS n FROM uploads WHERE user_id=? AND created_at>=?', [$user['id'], gmdate('Y-m-d\TH:i:s.000\Z', time() - 86400)]);
        $total = queryOne($db, 'SELECT COUNT(*) AS n,COALESCE(SUM(size),0) AS bytes FROM uploads');
        if ((int)$daily['n'] >= 30 || (int)$total['n'] >= 200 || (int)$total['bytes'] + $size > 500 * 1024 * 1024) fail(429, 'Se alcanzó la cuota de imágenes. Contacta al administrador.', 'upload_quota');
        $id = uid('img');
        $filename = $id . $extensions[$mime];
        if (!is_dir($config['upload_dir']) && !mkdir($config['upload_dir'], 0700, true) && !is_dir($config['upload_dir'])) throw new RuntimeException('Upload directory unavailable');
        $file = $config['upload_dir'] . '/' . $filename;
        $handle = fopen($file, 'xb');
        if (!$handle) throw new RuntimeException('Upload unavailable');
        $createdFiles[] = $file;
        try { $written = fwrite($handle, $bytes); }
        finally { fclose($handle); }
        @chmod($file, 0600);
        if ($written !== $size) throw new RuntimeException('Upload incomplete');
        execute($db, 'INSERT INTO uploads(id,user_id,purpose,mime,filename,size,created_at) VALUES(?,?,?,?,?,?,?)', [$id, $user['id'], $purpose, $mime, $filename, $size, now()]);
        $result = [201, ['photo' => ['id' => $id, 'url' => '/media/' . $id, 'mime' => $mime, 'size' => $size]]];
    } else {
        $result = routeCustomer($db, $path, $method, $session, $body, $query, $config)
            ?? routeCommerce($db, $path, $method, $session, $body, $query, $config);
    }
    if (!$result) fail(404, 'Ruta no encontrada.');
    $responseData = publicUrls($result[1], $config);
    $responseBody = json_encode($responseData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $commitStarted = true;
    $db->commit();
    $commitConfirmed = true;
    releaseMutationLock($db, $lock);
    $lock = null;
    sendJson($result[0], $responseData, $responseBody);
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    flushAfterCommitMail();
} catch (Throwable $error) {
    $GLOBALS['tt_after_commit'] = [];
    if ($db instanceof PDO) safeRollback($db);
    // A failed COMMIT acknowledgment can be ambiguous: preserve files in that case.
    if (!$commitStarted) foreach ($createdFiles as $file) @unlink($file);
    if ($db instanceof PDO) releaseMutationLock($db, $lock);
    if ($commitStarted && !$commitConfirmed) {
        // A lost acknowledgment must never advertise a retryable rollback.
        $error = new ApiError(503, 'No pudimos confirmar el resultado. Revisa tus pedidos o el estado de la operación antes de volver a enviarla.', 'commit_unknown');
    }
    errorResponse($error);
}
