<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
require_once __DIR__ . '/server/common.php';
require_once __DIR__ . '/server/auth.php';
require_once __DIR__ . '/server/services.php';
securityHeaders();
header('Cache-Control: no-store');
$installed = is_file(__DIR__ . '/server/config.local.php');
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
$local = in_array($remote, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true);
if (!$installed && !$local) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('La primera instalación debe hacerse desde la computadora de XAMPP: abre http://localhost/tecnotrade/instalar.php.');
}
$base = basePath();
$secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
if (!preg_match('/^(?:localhost|127\.0\.0\.1|\[::1\])(?::\d{1,5})?$/iD', $host)) $host = 'localhost';
$defaultUrl = ($secure ? 'https://' : 'http://') . $host . $base;
$values = ['db_host' => '127.0.0.1', 'db_port' => '3306', 'db_name' => 'tecnotrade', 'db_user' => 'root', 'app_url' => $defaultUrl, 'owner_name' => '', 'owner_email' => ''];
$errorMessage = '';
$success = false;
$requirements = PHP_VERSION_ID >= 80100 && extension_loaded('pdo_mysql') && extension_loaded('fileinfo');
if (!$installed) {
    session_name('tt_xampp_install');
    session_start(['use_strict_mode' => true, 'cookie_httponly' => true, 'cookie_samesite' => 'Strict', 'cookie_secure' => $secure, 'cookie_path' => $base]);
    if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = authToken();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $lockFile = null;
        $db = null;
        $dbLock = null;
        $configWritten = false;
        $commitAttempted = false;
        try {
            if (!$requirements) fail(400, 'Necesitas PHP 8.1 o posterior con las extensiones PDO MySQL y Fileinfo. XAMPP 8.2 las incluye.');
            if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) fail(403, 'La sesión del instalador cambió. Recarga esta página.');
            foreach ($values as $key => $default) $values[$key] = text($_POST[$key] ?? '', $key, $key === 'app_url' ? 500 : 254);
            if (!preg_match('/^[a-zA-Z0-9_]{1,48}$/D', $values['db_name'])) fail(400, 'Nombre de base inválido: usa letras, números y guion bajo.');
            if (!preg_match('/^[a-zA-Z0-9._:-]{1,253}$/D', $values['db_host'])) fail(400, 'Servidor de base de datos inválido.');
            if (!ctype_digit($values['db_port']) || (int)$values['db_port'] < 1 || (int)$values['db_port'] > 65535) fail(400, 'Puerto MySQL inválido.');
            $appUrl = rtrim($values['app_url'], '/') . '/';
            $url = parse_url($appUrl);
            if (!$url || !in_array($url['scheme'] ?? '', ['http', 'https'], true) || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || ($url['path'] ?? '/') !== $base) fail(400, 'La URL debe incluir la carpeta de esta instalación; por ejemplo ' . $defaultUrl);
            $ownerPassword = $_POST['owner_password'] ?? null;
            if (!is_string($ownerPassword) || $ownerPassword !== ($_POST['owner_password_confirm'] ?? null)) fail(400, 'Las contraseñas no coinciden.');
            // Validate credentials before making DDL or saving settings.
            hashPassword($ownerPassword);
            normalizeEmail($values['owner_email']);
            text($values['owner_name'], 'Nombre del administrador', 100, 2);
            if (!is_string($_POST['db_password'] ?? null) || strlen($_POST['db_password']) > 1024) fail(400, 'Contraseña MySQL inválida.');
            $config = ['db_host' => $values['db_host'], 'db_port' => (int)$values['db_port'], 'db_name' => $values['db_name'], 'db_user' => text($values['db_user'], 'Usuario MySQL', 100, 1), 'db_password' => $_POST['db_password'], 'app_url' => $appUrl, 'mail_key' => '', 'mail_from' => ''];
            $lockFile = fopen(__DIR__ . '/server/install.lock', 'c');
            if (!$lockFile || !flock($lockFile, LOCK_EX | LOCK_NB)) fail(409, 'Hay una instalación en curso. Espera a que termine.');
            if (is_file(__DIR__ . '/server/config.local.php')) fail(409, 'La instalación ya está completada.');
            $server = connectDatabase($config, false);
            // Database identifier was limited to ASCII letters/digits/underscore.
            $server->exec('CREATE DATABASE IF NOT EXISTS `' . $config['db_name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $server = null;
            $db = connectDatabase($config);
            $dbLock = mutationLock($db, $config);
            $hasUsers = queryOne($db, "SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema=? AND table_name='users'", [$config['db_name']]);
            if ((int)$hasUsers['n'] > 0 && (int)queryOne($db, 'SELECT COUNT(*) AS n FROM users')['n'] > 0) fail(409, 'Esta base ya tiene usuarios. Elige una base vacía para conservar los datos existentes.');
            $schema = file_get_contents(__DIR__ . '/database/schema.sql');
            if ($schema === false) throw new RuntimeException('Missing schema');
            // All schema statements are bundled trusted DDL; no user values here.
            $db->exec($schema);
            $db->beginTransaction();
            $categories = [['telefonos','Teléfonos','phone'],['computadoras','Computadoras','laptop'],['tablets','Tablets','tablet'],['smartwatches','Smartwatches','watch'],['audio','Audio','headphones'],['hogar','Hogar inteligente','home'],['accesorios','Accesorios','cable']];
            foreach ($categories as $i => [$id, $name, $icon]) execute($db, 'INSERT IGNORE INTO categories(id,name,icon,position) VALUES(?,?,?,?)', [$id,$name,$icon,$i+1]);
            foreach ([['telefono-generico','Teléfono por configurar','telefonos','telefono.svg'],['laptop-generica','Laptop por configurar','computadoras','laptop.svg'],['reloj-generico','Reloj por configurar','smartwatches','reloj.svg']] as [$id,$name,$category,$image]) {
                execute($db, "INSERT IGNORE INTO products(id,name,category_id,`condition`,image,created_at) VALUES(?,?,?,'Nuevo',?,?)", [$id,$name,$category,'/assets/'.$image,now()]);
            }
            $references = json_decode(file_get_contents(__DIR__ . '/database/market-references.json'), true, 64, JSON_THROW_ON_ERROR);
            foreach ($references as $r) {
                $rid = substr(hash('sha256', $r['modelKey'] . $r['sourceUrl']), 0, 24);
                $expires = $r['expiresAt'] ?? gmdate('Y-m-d\TH:i:s.000\Z', strtotime($r['checkedAt']) + 30*86400);
                execute($db, 'INSERT IGNORE INTO market_references(id,model_key,model,storage,category,amount_cents,source_url,source_title,`condition`,seller,availability,availability_confirmed,checked_at,expires_at,notes,active) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)', [$rid,$r['modelKey'],$r['model'],$r['storage']??'',$r['category']??'telefonos',$r['amountCents'],$r['sourceUrl'],$r['sourceTitle']??'',$r['condition']??'',$r['seller'],$r['availability'],!empty($r['availabilityConfirmed']) && ($r['available']??true) ? 1 : 0,$r['checkedAt'],$expires,$r['notes']??'']);
            }
            $owner = createUser($db, ['name' => $values['owner_name'], 'email' => $values['owner_email'], 'password' => $ownerPassword], 'admin');
            audit($db, $owner, 'installation.owner', $owner['id']);
            $serialized = "<?php\n// Configuración privada de esta instalación; no compartir.\nreturn " . var_export($config, true) . ";\n";
            $configFile = __DIR__ . '/server/config.local.php';
            $handle = fopen($configFile, 'xb');
            if (!$handle) throw new RuntimeException('Cannot save local configuration');
            $configWritten = true;
            try { $written = fwrite($handle, $serialized); }
            finally { fclose($handle); }
            @chmod($configFile, 0600);
            if ($written !== strlen($serialized)) throw new RuntimeException('Configuration incomplete');
            $commitAttempted = true;
            $db->commit();
            releaseMutationLock($db, $dbLock);
            $dbLock = null;
            $_SESSION = [];
            session_destroy();
            $success = true;
            $installed = true;
        } catch (Throwable $error) {
            if ($db instanceof PDO) safeRollback($db);
            // Do not delete configuration after an uncertain COMMIT acknowledgment.
            if ($configWritten && !$commitAttempted) @unlink(__DIR__ . '/server/config.local.php');
            if ($db instanceof PDO) releaseMutationLock($db, $dbLock);
            http_response_code($error instanceof ApiError ? $error->httpStatus : 500);
            $errorMessage = $commitAttempted ? 'Se perdió la confirmación de MySQL. La configuración se conservó: inicia MySQL y comprueba si puedes entrar con la cuenta que elegiste antes de repetir cualquier instalación.' : ($error instanceof ApiError ? $error->getMessage() : 'No se pudo completar la instalación. Verifica que MySQL esté iniciado, los datos de conexión y el permiso de escritura en la carpeta server.');
            error_log('TecnoTrade installer result: ' . get_class($error));
        } finally {
            if (is_resource($lockFile)) { flock($lockFile, LOCK_UN); fclose($lockFile); }
        }
    }
}
$escape = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Instalar TecnoTrade · XAMPP</title><link rel="stylesheet" href="instalar.css"></head>
<body><main><a class="brand" href="./"><span>TT</span>TecnoTrade<span class="dot">.</span></a><p class="eyebrow">EDICIÓN XAMPP · PHP + MYSQL</p>
<?php if ($installed): ?>
<h1><?= $success ? 'TecnoTrade está lista.' : 'La instalación está completada.' ?></h1>
<p>Tu base de datos local está configurada. El instalador está cerrado para proteger la cuenta del administrador.</p>
<p>Inicia sesión con el correo y la contraseña que elegiste. Desde el panel de administración puedes cargar productos, precios, inventario y cuentas del equipo.</p>
<a class="button" href="./#/login">Entrar a TecnoTrade →</a>
<p class="note">Esta edición tiene su propia base de datos local; no sincroniza información con la tienda de Internet.</p>
<?php else: ?>
<h1>Tu tienda, en tu computadora.</h1><p>Inicia <strong>Apache</strong> y <strong>MySQL</strong> en XAMPP. Este asistente creará las tablas y tu primera cuenta de administrador.</p>
<?php if (!$requirements): ?><p class="error">Necesitas PHP 8.1 o posterior, PDO MySQL y Fileinfo. Instala XAMPP 8.2 con sus extensiones predeterminadas.</p><?php endif ?>
<?php if ($errorMessage !== ''): ?><p class="error" role="alert"><?= $escape($errorMessage) ?></p><?php endif ?>
<form method="post" action="instalar.php"><input type="hidden" name="csrf" value="<?= $escape($_SESSION['csrf'] ?? '') ?>">
<fieldset><legend>01 · Conexión MySQL</legend><p class="note">En una instalación nueva de XAMPP: servidor 127.0.0.1, puerto 3306, usuario root y contraseña vacía. Si cambiaste esos datos, usa los tuyos.</p><div class="grid">
<label>Servidor<input name="db_host" value="<?= $escape($values['db_host']) ?>" required maxlength="253"></label><label>Puerto<input name="db_port" type="number" min="1" max="65535" value="<?= $escape($values['db_port']) ?>" required></label>
<label>Base de datos<input name="db_name" value="<?= $escape($values['db_name']) ?>" pattern="[a-zA-Z0-9_]{1,48}" required maxlength="48"></label><label>Usuario MySQL<input name="db_user" value="<?= $escape($values['db_user']) ?>" required maxlength="100" autocomplete="off"></label>
<label class="wide">Contraseña MySQL<input name="db_password" type="password" autocomplete="off" value=""></label><label class="wide">URL de esta instalación<input name="app_url" type="url" value="<?= $escape($values['app_url']) ?>" required maxlength="500"><small>Para usar otro puerto o acceder por red local, configura la dirección exacta.</small></label></div></fieldset>
<fieldset><legend>02 · Tu cuenta de administrador</legend><div class="grid"><label class="wide">Tu nombre<input name="owner_name" value="<?= $escape($values['owner_name']) ?>" required minlength="2" maxlength="100" autocomplete="name"></label><label class="wide">Correo<input name="owner_email" type="email" value="<?= $escape($values['owner_email']) ?>" required maxlength="254" autocomplete="email"></label><label>Contraseña<input name="owner_password" type="password" minlength="12" maxlength="128" required autocomplete="new-password"><small>Entre 12 y 128 caracteres.</small></label><label>Confirma tu contraseña<input name="owner_password_confirm" type="password" minlength="12" maxlength="128" required autocomplete="new-password"></label></div></fieldset>
<button type="submit"<?= !$requirements ? ' disabled' : '' ?>>Crear base de datos y cuenta →</button>
</form><p class="note">El catálogo inicia con tres borradores sin precio ni existencias. Publica tus productos reales desde el panel. El correo de recuperación requiere configurar un proveedor; no se realizan cobros con tarjeta.</p>
<?php endif ?>
</main></body></html>
