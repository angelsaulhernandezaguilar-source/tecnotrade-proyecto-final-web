<?php
declare(strict_types=1);

/** Account endpoints. All mutations run inside the dispatcher's SQL transaction. */
function routeCustomer(PDO $db, string $path, string $method, ?array $session, array $body, array $query, array $config): ?array
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if ($path === '/api/health' && $method === 'GET') {
        return [200, ['ok' => true]];
    }
    if ($path === '/api/session' && $method === 'GET') {
        if ($session === null) {
            rate($db, 'anonymous-session:' . $ip, 60, 3600000);
            $session = createSession($db, null, $config);
        }
        return [200, ['user' => userView($session['user']), 'csrfToken' => $session['csrfToken']]];
    }
    if ($path === '/api/auth/register' && $method === 'POST') {
        rate($db, 'register:' . $ip, 8, 3600000);
        // The public route deliberately ignores any role supplied in JSON.
        $user = createUser($db, $body, 'cliente');
        if ($session !== null) {
            execute($db, 'DELETE FROM sessions WHERE token_hash=?', [$session['tokenHash']]);
        }
        $session = createSession($db, $user['id'], $config);
        audit($db, $user, 'user.register', $user['id']);
        return [201, ['user' => userView($session['user']), 'csrfToken' => $session['csrfToken']]];
    }
    if ($path === '/api/auth/login' && $method === 'POST') {
        $email = authLower(trim(is_string($body['email'] ?? null) ? $body['email'] : ''));
        if (strlen($email) > 1024) {
            $email = '';
        }
        rate($db, 'login-ip:' . $ip, 30, 900000);
        rate($db, 'login:' . $ip . ':' . $email, 5, 900000);
        $user = queryOne($db, 'SELECT * FROM users WHERE email=?', [$email]);
        if (!verifyPassword($body['password'] ?? null, $user) || $user === null || !(bool) $user['active']) {
            fail(401, 'Correo o contraseña incorrectos, o cuenta inactiva.', 'invalid_credentials');
        }
        clearRate($db, 'login:' . $ip . ':' . $email);
        if ($session !== null) {
            execute($db, 'DELETE FROM sessions WHERE token_hash=?', [$session['tokenHash']]);
        }
        $session = createSession($db, $user['id'], $config);
        audit($db, $user, 'user.login', $user['id']);
        return [200, ['user' => userView($session['user']), 'csrfToken' => $session['csrfToken']]];
    }
    if ($path === '/api/auth/logout' && $method === 'POST') {
        if ($session !== null) {
            execute($db, 'DELETE FROM sessions WHERE token_hash=?', [$session['tokenHash']]);
        }
        $session = createSession($db, null, $config);
        return [200, ['ok' => true, 'csrfToken' => $session['csrfToken']]];
    }
    if ($path === '/api/auth/forgot-password' && $method === 'POST') {
        $email = authLower(trim(is_string($body['email'] ?? null) ? $body['email'] : ''));
        if (strlen($email) > 1024) {
            $email = '';
        }
        rate($db, 'forgot:' . $ip, 5, 900000);
        rate($db, 'forgot-email:' . $email, 3, 900000);
        return [200, forgotPassword($db, $email, $config)];
    }
    if ($path === '/api/auth/reset-password' && $method === 'POST') {
        rate($db, 'reset:' . $ip, 10, 900000);
        return [200, resetPassword($db, $body)];
    }
    if ($path === '/api/me') {
        $user = requireUser($session);
        if ($method === 'GET') {
            return [200, ['user' => userView($user)]];
        }
        if ($method === 'PATCH') {
            $name = text($body['name'] ?? $user['name'], 'Nombre', 100, 2);
            $phone = text($body['phone'] ?? $user['phone'], 'Teléfono', 30);
            $address = is_string($body['address'] ?? null) ? text($body['address'], 'Dirección', 500) : $user['address'];
            execute($db, 'UPDATE users SET name=?,phone=?,address=? WHERE id=?', [$name, $phone, $address, $user['id']]);
            return [200, ['user' => userView(queryOne($db, 'SELECT * FROM users WHERE id=?', [$user['id']]))]];
        }
    }
    if ($path === '/api/cart' || $path === '/api/favorites') {
        $user = requireUser($session, ['cliente']);
        $isCart = $path === '/api/cart';
        $field = $isCart ? 'items' : 'productIds';
        $table = $isCart ? 'carts' : 'favorites';
        $column = $isCart ? 'items' : 'product_ids';
        if ($method === 'GET') {
            $row = queryOne($db, "SELECT `$column` FROM `$table` WHERE user_id=?", [$user['id']]);
            return [200, [$field => $row === null ? [] : jsonValue($row[$column])]];
        }
        if ($method === 'PUT') {
            $list = $body[$field] ?? null;
            if (!is_array($list) || !array_is_list($list) || count($list) > 100) {
                fail(400, 'Lista inválida.');
            }
            $normalized = [];
            $seen = [];
            foreach ($list as $item) {
                if ($isCart) {
                    if (!is_array($item)) {
                        fail(400, 'Producto de carrito inválido.');
                    }
                    $id = text($item['productId'] ?? null, 'Producto', 100, 1);
                    publicProduct($db, $id);
                    $quantity = integer($item['quantity'] ?? null, 'Cantidad', 1, 100);
                    if (isset($seen[$id])) {
                        fail(400, 'No repitas productos.');
                    }
                    $seen[$id] = true;
                    $normalized[] = ['productId' => $id, 'quantity' => $quantity];
                } else {
                    $product = publicProduct($db, text($item, 'Producto', 100, 1));
                    if (!isset($seen[$product['id']])) {
                        $seen[$product['id']] = true;
                        $normalized[] = $product['id'];
                    }
                }
            }
            execute($db, "INSERT INTO `$table` (user_id,`$column`) VALUES (?,?) ON DUPLICATE KEY UPDATE `$column`=VALUES(`$column`)", [
                $user['id'], json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            return [200, [$field => $normalized]];
        }
    }
    if ($path === '/api/wallet' && $method === 'GET') {
        $user = requireUser($session, ['cliente']);
        return [200, wallet($db, $user['id'])];
    }
    return null;
}
