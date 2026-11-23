<?php
declare(strict_types=1);

/** Authentication for the XAMPP edition. Raw session/reset tokens never enter SQL. */
function digest(string $value): string
{
    return hash('sha256', $value);
}

function authToken(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function authMillis(): int
{
    return (int) floor(microtime(true) * 1000);
}

function authLower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function passwordMaterial(string $password): string
{
    // Bcrypt considers only 72 bytes. Prehashing preserves every character of an
    // accepted 128-character password, including UTF-8, without silent truncation.
    return base64_encode(hash('sha256', $password, true));
}

function hashPassword(mixed $password): array
{
    if (!is_string($password) || preg_match('//u', $password) !== 1) {
        fail(400, 'La contraseña debe tener entre 12 y 128 caracteres.');
    }
    $length = preg_match_all('/./us', $password);
    if ($length === false || $length < 12 || $length > 128) {
        fail(400, 'La contraseña debe tener entre 12 y 128 caracteres.');
    }
    $hash = password_hash(passwordMaterial($password), PASSWORD_DEFAULT, ['cost' => 12]);
    if (!is_string($hash)) {
        fail(500, 'No se pudo proteger la contraseña.');
    }
    return ['hash' => $hash, 'salt' => 'php-password-sha256-v1'];
}

function verifyPassword(mixed $password, ?array $user): bool
{
    if (!is_string($password) || strlen($password) > 512 || preg_match('//u', $password) !== 1) {
        return false;
    }
    $length = preg_match_all('/./us', $password);
    if ($length === false || $length > 128) {
        return false;
    }
    // Keep a real bcrypt operation for unknown accounts. This is not an account
    // password and is never inserted into the database.
    $dummyHash = '$2y$12$8tFa5Z5eJIRvgiMglfp36.GNGPyMSRtjijV2.kmQfYSNn6Z.K5.DS';
    $hash = $user['password_hash'] ?? $dummyHash;
    $valid = password_verify(passwordMaterial($password), $hash);
    return $valid && $user !== null && ($user['salt'] ?? '') === 'php-password-sha256-v1';
}

function normalizeEmail(mixed $value): string
{
    $email = authLower(text($value, 'Correo', 254, 3));
    if (preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/uD', $email) !== 1) {
        fail(400, 'Escribe un correo válido.');
    }
    return $email;
}

function createUser(PDO $db, array $body, string $role = 'cliente', ?callable $authorize = null): array
{
    $email = normalizeEmail($body['email'] ?? null);
    $name = text($body['name'] ?? null, 'Nombre', 100, 2);
    $password = hashPassword($body['password'] ?? null);
    if (!in_array($role, ['cliente', 'admin', 'logistica', 'soporte', 'tecnico'], true)) {
        fail(400, 'Rol inválido.');
    }
    if ($authorize !== null) {
        $authorize();
    }
    $phone = text($body['phone'] ?? '', 'Teléfono', 30);
    $address = is_string($body['address'] ?? null) ? text($body['address'], 'Dirección', 500) : '';
    $id = uid('usr');
    try {
        execute($db, 'INSERT INTO users (id,name,email,password_hash,salt,role,phone,address,created_at) VALUES (?,?,?,?,?,?,?,?,?)', [
            $id, $name, $email, $password['hash'], $password['salt'], $role, $phone, $address, now(),
        ]);
    } catch (PDOException $error) {
        if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
            fail(409, 'No se pudo crear la cuenta con ese correo.', 'email_exists');
        }
        throw $error;
    }
    $user = queryOne($db, 'SELECT * FROM users WHERE id=?', [$id]);
    if ($user === null) {
        fail(500, 'No se pudo crear la cuenta.');
    }
    return $user;
}

function createSession(PDO $db, ?string $userId, array $config): array
{
    $millis = authMillis();
    execute($db, 'DELETE FROM sessions WHERE expires_at<=?', [$millis]);
    $token = authToken();
    $csrf = authToken(24);
    $expires = $millis + 12 * 3600000;
    execute($db, 'INSERT INTO sessions (token_hash,user_id,csrf_token,expires_at,created_at) VALUES (?,?,?,?,?)', [
        digest($token), $userId, $csrf, $expires, now(),
    ]);
    $user = $userId === null ? null : queryOne($db, 'SELECT * FROM users WHERE id=? AND active=1', [$userId]);
    if ($userId !== null && $user === null) {
        fail(401, 'La cuenta no está disponible.', 'authentication_required');
    }
    $secure = strtolower((string) parse_url($config['app_url'], PHP_URL_SCHEME)) === 'https';
    setcookie('tt_xampp_session', $token, [
        'expires' => time() + 43200,
        'path' => $config['base_path'] ?? '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    return ['tokenHash' => digest($token), 'csrfToken' => $csrf, 'user' => $user, 'expiresAt' => $expires];
}

function readSession(PDO $db, array $config): ?array
{
    $token = $_COOKIE['tt_xampp_session'] ?? null;
    if (!is_string($token) || preg_match('/^[A-Za-z0-9_-]{30,60}$/D', $token) !== 1) {
        return null;
    }
    $session = queryOne($db, 'SELECT * FROM sessions WHERE token_hash=? AND expires_at>?', [digest($token), authMillis()]);
    if ($session === null) {
        return null;
    }
    $user = $session['user_id'] === null ? null : queryOne($db, 'SELECT * FROM users WHERE id=? AND active=1', [$session['user_id']]);
    // An inactive/deleted account must not keep an authenticated session or its
    // CSRF token. A later /session request may create a new anonymous session.
    if ($session['user_id'] !== null && $user === null) {
        return null;
    }
    return [
        'tokenHash' => $session['token_hash'],
        'csrfToken' => $session['csrf_token'],
        'user' => $user,
        'expiresAt' => (int) $session['expires_at'],
    ];
}

function requireUser(?array $session, ?array $roles = null): array
{
    if (!isset($session['user']) || !is_array($session['user'])) {
        fail(401, 'Inicia sesión para continuar.', 'authentication_required');
    }
    $user = $session['user'];
    if ($roles !== null && !in_array($user['role'], $roles, true)) {
        fail(403, 'Tu perfil no tiene permiso para esta acción.', 'forbidden');
    }
    return $user;
}

/** Queues mail after the token's SQL transaction is safely committed. */
function forgotPassword(PDO $db, string $email, array $config): array
{
    $result = ['ok' => true, 'message' => 'Si existe una cuenta y el correo está configurado, recibirás instrucciones para recuperar tu acceso.'];
    try {
        $email = normalizeEmail($email);
    } catch (ApiError $error) {
        return $result;
    }
    $user = queryOne($db, 'SELECT * FROM users WHERE email=? AND active=1', [$email]);
    if ($user === null || empty($config['mail_key']) || empty($config['mail_from'])) {
        return $result;
    }
    $token = authToken();
    $tokenHash = digest($token);
    execute($db, 'INSERT INTO password_resets (token_hash,user_id,expires_at,used_at,created_at) VALUES (?,?,?,?,?)', [
        $tokenHash, $user['id'], authMillis() + 30 * 60000, null, now(),
    ]);
    $link = rtrim($config['app_url'], '/') . '/#/reset?token=' . rawurlencode($token);
    $payload = [
        'from' => $config['mail_from'],
        'to' => [$user['email']],
        'subject' => 'Recupera tu acceso a TecnoTrade',
        'text' => "Solicitaste recuperar tu acceso a TecnoTrade. Este enlace expira en 30 minutos y sólo puede usarse una vez:\n\n" . $link . "\n\nSi no lo solicitaste, ignora este mensaje.",
    ];
    $GLOBALS['tt_after_commit'][] = static function () use ($config, $tokenHash, $payload, $user): void {
        $sent = false;
        try {
            if (function_exists('curl_init')) {
                $curl = curl_init('https://api.resend.com/emails');
                if ($curl === false) {
                    throw new RuntimeException('mail_init_failed');
                }
                curl_setopt_array($curl, [
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $config['mail_key'], 'Content-Type: application/json'],
                    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                ]);
                $response = curl_exec($curl);
                $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                $sent = $response !== false && $status >= 200 && $status < 300;
                curl_close($curl);
            }
        } catch (Throwable $error) {
            $sent = false;
        }
        try {
            // Do not reuse a connection whose request transaction already ended.
            $mailDb = connectDatabase($config);
            transact($mailDb, static function () use ($mailDb, $tokenHash, $user, $sent): void {
                if (!$sent) {
                    execute($mailDb, 'UPDATE password_resets SET used_at=? WHERE token_hash=? AND used_at IS NULL', [now(), $tokenHash]);
                    return;
                }
                $current = queryOne($mailDb, 'SELECT seq FROM password_resets WHERE token_hash=?', [$tokenHash]);
                if ($current !== null) {
                    execute($mailDb, 'UPDATE password_resets SET used_at=? WHERE user_id=? AND used_at IS NULL AND seq<?', [now(), $user['id'], $current['seq']]);
                }
            });
        } catch (Throwable $error) {
            // Avoid printing recipients, reset URLs, credential values or tokens.
            error_log('TecnoTrade: no se pudo actualizar el resultado del correo de recuperación.');
        }
        if (!$sent) {
            error_log('TecnoTrade: no se pudo enviar el correo de recuperación; revisa la configuración de correo.');
        }
    };
    return $result;
}

function flushAfterCommitMail(): void
{
    $pending = $GLOBALS['tt_after_commit'] ?? [];
    $GLOBALS['tt_after_commit'] = [];
    foreach ($pending as $callback) {
        try {
            $callback();
        } catch (Throwable $error) {
            error_log('TecnoTrade: falló una tarea de correo.');
        }
    }
}

function resetPassword(PDO $db, array $body): array
{
    $token = text($body['token'] ?? null, 'Enlace de recuperación', 200, 20);
    $tokenHash = digest($token);
    $reset = queryOne($db, 'SELECT * FROM password_resets WHERE token_hash=? AND used_at IS NULL AND expires_at>?', [$tokenHash, authMillis()]);
    if ($reset === null) {
        fail(400, 'El enlace no es válido o ya expiró.', 'invalid_reset_token');
    }
    $password = hashPassword($body['password'] ?? null);
    return transact($db, static function () use ($db, $tokenHash, $reset, $password): array {
        $changed = execute($db, 'UPDATE password_resets SET used_at=? WHERE token_hash=? AND used_at IS NULL AND expires_at>?', [now(), $tokenHash, authMillis()]);
        if ($changed !== 1) {
            fail(400, 'El enlace ya se utilizó o expiró.', 'invalid_reset_token');
        }
        execute($db, 'UPDATE users SET password_hash=?,salt=? WHERE id=?', [$password['hash'], $password['salt'], $reset['user_id']]);
        execute($db, 'UPDATE password_resets SET used_at=? WHERE user_id=? AND used_at IS NULL', [now(), $reset['user_id']]);
        execute($db, 'DELETE FROM sessions WHERE user_id=?', [$reset['user_id']]);
        audit($db, ['id' => $reset['user_id']], 'password.reset', $reset['user_id']);
        return ['ok' => true];
    });
}
