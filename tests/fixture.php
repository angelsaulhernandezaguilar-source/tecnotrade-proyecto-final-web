<?php
declare(strict_types=1);
// Sólo fixtures de pruebas. Nunca se ejecuta por HTTP ni sobre una BD comercial.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/server/common.php';
$config = require dirname(__DIR__) . '/server/config.local.php';
if (!is_array($config) || !str_ends_with($config['db_name'] ?? '', '_qa')) {
    fwrite(STDERR, "Fixtures permitidos únicamente en una base cuyo nombre termina en _qa.\n"); exit(2);
}
$db = connectDatabase($config);
$body = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
$op = $body['op'] ?? '';
if ($op === 'clear_rate') {
    $db->exec('DELETE FROM rate_limits');
    echo '{"ok":true}';
} elseif ($op === 'reset_token') {
    $u = queryOne($db, 'SELECT id FROM users WHERE email=?', [$body['email'] ?? '']);
    if (!$u || !is_string($body['token'] ?? null) || strlen($body['token']) < 20) exit(3);
    execute($db, 'INSERT INTO password_resets (token_hash,user_id,expires_at,created_at) VALUES (?,?,?,?)', [hash('sha256', $body['token']), $u['id'], (int)floor(microtime(true)*1000) + 600000, now()]);
    echo '{"ok":true}';
} elseif ($op === 'constraints') {
    $checks = [];
    $db->beginTransaction();
    try {
        $product = queryOne($db, 'SELECT id FROM products LIMIT 1');
        $attempts = [
            ['stock_check', 'UPDATE products SET stock=-1 WHERE id=?', [$product['id']], [4025,3819]],
            ['price_check', 'UPDATE products SET price_cents=-1 WHERE id=?', [$product['id']], [4025,3819]],
            ['foreign_key', "INSERT INTO carts(user_id,items) VALUES(?,'[]')", ['qa-missing-user'], [1452]],
        ];
        foreach ($attempts as [$name,$sql,$params,$expectedCodes]) {
            try { execute($db, $sql, $params); $checks[$name]=false; }
            catch (PDOException $e) { $checks[$name]=in_array((int)($e->errorInfo[1]??0),$expectedCodes,true); }
        }
    } finally { $db->rollBack(); }
    echo json_encode($checks, JSON_THROW_ON_ERROR);
} elseif ($op === 'invariants') {
    $negative = queryOne($db, 'SELECT COUNT(*) n FROM wallet_accounts WHERE balance_cents<0')['n'];
    $badLedger = queryOne($db, 'SELECT COUNT(*) n FROM wallet_accounts w WHERE w.balance_cents<>(SELECT COALESCE(SUM(e.delta_cents),0) FROM wallet_entries e WHERE e.user_id=w.user_id)')['n'];
    $badStock = queryOne($db, 'SELECT COUNT(*) n FROM products WHERE stock<0')['n'];
    $plainTokens = queryOne($db, 'SELECT COUNT(*) n FROM sessions WHERE LENGTH(token_hash)<>64')['n'];
    $columns = queryAll($db, 'SHOW TABLES');
    echo json_encode(['negativeWallets'=>(int)$negative,'mismatchedLedgers'=>(int)$badLedger,'negativeStock'=>(int)$badStock,'unhashedSessions'=>(int)$plainTokens,'tables'=>count($columns)], JSON_THROW_ON_ERROR);
} else { fwrite(STDERR, "Operación desconocida.\n"); exit(4); }
