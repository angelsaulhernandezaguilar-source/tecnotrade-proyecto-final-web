<?php
declare(strict_types=1);

final class ApiError extends RuntimeException {
    public int $httpStatus;
    public string $errorCode;
    public function __construct(int $status, string $message, string $code = 'invalid_request') {
        parent::__construct($message);
        $this->httpStatus = $status;
        $this->errorCode = $code;
    }
}
function fail(int $status, string $message, string $code = 'invalid_request'): never {
    throw new ApiError($status, $message, $code);
}
function text(mixed $value, string $name, int $max = 500, int $min = 0): string {
    if (!is_string($value)) fail(400, $name . ': valor inválido.');
    $value = trim($value);
    $length = preg_match_all('/./us', $value, $unused);
    if ($length === false || $length < $min || $length > $max || str_contains($value, "\0")) {
        fail(400, $name . ': valor inválido.');
    }
    return $value;
}
function integer(mixed $value, string $name, int $min = 0, int $max = 1000000000): int {
    if (!is_int($value) || $value < $min || $value > $max) fail(400, $name . ": debe ser un entero entre $min y $max.");
    return $value;
}
function booleanValue(mixed $value, bool $fallback = false): bool {
    if ($value === null) return $fallback;
    if (!is_bool($value)) fail(400, 'Se esperaba un valor booleano.');
    return $value;
}
function now(): string {
    $date = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    return $date->format('Y-m-d\TH:i:s.v\Z');
}
function uid(string $prefix): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    $hex = bin2hex($data);
    return $prefix . '-' . substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}
function queryOne(PDO $db, string $sql, array $args = []): ?array {
    $stmt = $db->prepare($sql);
    $stmt->execute($args);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}
function queryAll(PDO $db, string $sql, array $args = []): array {
    $stmt = $db->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function execute(PDO $db, string $sql, array $args = []): int {
    $stmt = $db->prepare($sql);
    $stmt->execute($args);
    return $stmt->rowCount();
}
function jsonValue(?string $json): array {
    $value = json_decode($json ?? '[]', true, 64, JSON_THROW_ON_ERROR);
    return is_array($value) ? $value : [];
}
function transact(PDO $db, callable $fn): mixed {
    if ($db->inTransaction()) return $fn();
    $db->beginTransaction();
    try {
        $result = $fn();
        $db->commit();
        return $result;
    } catch (Throwable $error) {
        safeRollback($db);
        throw $error;
    }
}
function safeRollback(PDO $db): bool {
    try {
        if ($db->inTransaction()) $db->rollBack();
        return true;
    } catch (Throwable $ignored) {
        // Preserve the original failure if the database connection has gone away.
        return false;
    }
}
function releaseMutationLock(PDO $db, ?string $lock): void {
    if ($lock === null) return;
    try { queryOne($db, 'SELECT RELEASE_LOCK(?) AS released', [$lock]); }
    catch (Throwable $ignored) { error_log('TecnoTrade: database lock released on connection close.'); }
}
function userView(?array $user): ?array {
    if (!$user) return null;
    return ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'role' => $user['role'], 'active' => (bool)$user['active'], 'phone' => $user['phone'], 'address' => $user['address']];
}
function basePath(): string {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    // En Windows dirname('/index.php') devuelve '\': se normaliza para que la
    // tienda tambien funcione servida desde la raiz (http://localhost:8088/).
    $base = rtrim(str_replace('\\', '/', dirname($script)), '/.');
    return ($base === '' ? '' : $base) . '/';
}
function connectDatabase(array $config, bool $withDatabase = true): PDO {
    $host = $config['db_host'];
    $port = (int)$config['db_port'];
    if (!preg_match('/^[a-zA-Z0-9._:-]+$/D', $host) || $port < 1 || $port > 65535) throw new RuntimeException('Invalid database settings');
    $dsn = 'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4';
    if ($withDatabase) {
        if (!preg_match('/^[a-zA-Z0-9_]{1,48}$/D', $config['db_name'])) throw new RuntimeException('Invalid database name');
        $dsn .= ';dbname=' . $config['db_name'];
    }
    $pdo = new PDO($dsn, $config['db_user'], $config['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_TIMEOUT => 8,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}
function loadConfig(): array {
    $file = __DIR__ . '/config.local.php';
    if (!is_file($file)) fail(503, 'Primero configura TecnoTrade desde instalar.php.', 'not_installed');
    $config = require $file;
    if (!is_array($config)) throw new RuntimeException('Invalid configuration');
    $config['base_path'] = basePath();
    $config['upload_dir'] = __DIR__ . '/storage/uploads';
    return $config;
}
function mutationLock(PDO $db, array $config): string {
    $key = 'tecnotrade:' . substr(hash('sha256', (string)$config['db_name']), 0, 40);
    $row = queryOne($db, 'SELECT GET_LOCK(?, 8) AS acquired', [$key]);
    if (!$row || (int)$row['acquired'] !== 1) fail(503, 'La tienda está ocupada; vuelve a intentar en unos segundos.', 'busy');
    return $key;
}
function rate(PDO $unused, string $key, int $max, int $windowMs): void {
    $db = $GLOBALS['tt_rate_db'] ?? null;
    if (!$db instanceof PDO) throw new RuntimeException('Missing rate database');
    $bucket = hash('sha256', $key);
    $stamp = (int)floor(microtime(true) * 1000);
    $db->beginTransaction();
    try {
        execute($db, "INSERT IGNORE INTO rate_limits(bucket_key,hits_json) VALUES(?,'[]')", [$bucket]);
        $row = queryOne($db, 'SELECT hits_json FROM rate_limits WHERE bucket_key=? FOR UPDATE', [$bucket]);
        $hits = array_values(array_filter(jsonValue($row['hits_json']), fn($n) => is_int($n) && $n > $stamp - $windowMs));
        if (count($hits) >= $max) {
            $db->commit();
            fail(429, 'Demasiados intentos. Intenta de nuevo más tarde.', 'rate_limited');
        }
        $hits[] = $stamp;
        execute($db, 'UPDATE rate_limits SET hits_json=? WHERE bucket_key=?', [json_encode($hits, JSON_THROW_ON_ERROR), $bucket]);
        $db->commit();
    } catch (Throwable $error) {
        safeRollback($db);
        throw $error;
    }
}
function clearRate(PDO $unused, string $key): void {
    $db = $GLOBALS['tt_rate_db'] ?? null;
    if ($db instanceof PDO) execute($db, 'DELETE FROM rate_limits WHERE bucket_key=?', [hash('sha256', $key)]);
}
function securityHeaders(): void {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
}
function requestOrigin(array $config): void {
    $received = $_SERVER['HTTP_ORIGIN'] ?? '';
    $expected = parse_url($config['app_url']);
    if ($received === '') fail(403, 'Se requiere una solicitud desde la tienda.', 'origin_required');
    $actual = parse_url($received);
    if (!$actual || isset($actual['user']) || isset($actual['pass']) || isset($actual['query']) || isset($actual['fragment'])) fail(403, 'Origen no permitido.', 'invalid_origin');
    $originParts = fn($v) => [strtolower($v['scheme'] ?? ''), strtolower($v['host'] ?? ''), $v['port'] ?? (($v['scheme'] ?? '') === 'https' ? 443 : 80)];
    if ($originParts($actual) !== $originParts($expected)) fail(403, 'Origen no permitido.', 'invalid_origin');
}
function localUrl(string $url, array $config): string {
    if (preg_match('~^/assets/[a-zA-Z0-9_./-]+$~D', $url) && !str_contains($url, '..')) {
        return $config['base_path'] . 'index.php?file=' . rawurlencode(ltrim($url, '/'));
    }
    if (preg_match('~^/media/[a-zA-Z0-9-]{1,100}$~D', $url)) {
        return $config['base_path'] . 'api.php?path=' . rawurlencode($url);
    }
    return $url;
}
function publicUrls(mixed $value, array $config): mixed {
    if (!is_array($value)) return $value;
    foreach ($value as $key => $child) {
        if (is_string($child) && in_array($key, ['image', 'url'], true)) $value[$key] = localUrl($child, $config);
        elseif (is_array($child)) $value[$key] = publicUrls($child, $config);
    }
    return $value;
}
function sendJson(int $status, array $data, ?string $encoded = null): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($status === 429) header('Retry-After: 900');
    if ($status === 503) header('Retry-After: 1');
    echo $encoded ?? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
function errorResponse(Throwable $error): void {
    if ($error instanceof ApiError) {
        sendJson($error->httpStatus, ['error' => ['code' => $error->errorCode, 'message' => $error->getMessage()]]);
        return;
    }
    $status = 500;
    $code = 'request_failed';
    $message = 'No se pudo completar la solicitud. Revisa que MySQL esté iniciado.';
    if ($error instanceof PDOException) {
        $driverCode = (int)($error->errorInfo[1] ?? 0);
        if ($driverCode === 1062) { $status = 409; $message = 'Ese dato ya está registrado.'; }
        elseif (in_array($driverCode, [1451, 1452, 3819, 4025], true)) { $status = 400; $message = 'Los datos no cumplen las reglas del registro.'; }
        elseif (in_array($driverCode, [1205, 1213], true)) { $status = 409; $code = 'state_conflict'; $message = 'Otra operación cambió los datos. Vuelve a intentar.'; }
        elseif (in_array($driverCode, [1045, 1049, 2002], true)) { $status = 503; $code = 'database_unavailable'; }
    }
    error_log('TecnoTrade request failed: ' . get_class($error));
    sendJson($status, ['error' => ['code' => $code, 'message' => $message]]);
}
