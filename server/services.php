<?php
/** Business rules shared by the PHP API. All money is integer MXN cents.
 * The dispatcher holds the application mutation lock and transaction before writes.
 */
declare(strict_types=1);

function serviceLower(string $value): string {
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function encodeData($value): string {
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function audit(PDO $db, ?array $user, string $action, ?string $entityId, array $detail = []): void {
    execute($db, 'INSERT INTO audit_events(id,actor_id,action,entity_id,detail,created_at) VALUES(?,?,?,?,?,?)',
        [uid('audit'), $user['id'] ?? null, $action, $entityId, encodeData($detail ?: (object) []), now()]);
}

function categoryView(array $row): array {
    return ['id'=>$row['id'], 'name'=>$row['name'], 'icon'=>$row['icon'], 'position'=>(int)$row['position']];
}

function promotionView(array $row): array {
    return ['id'=>$row['id'], 'title'=>$row['title'], 'scope'=>$row['scope'], 'categoryId'=>$row['category_id'],
        'productIds'=>jsonValue($row['product_ids']), 'discount'=>(int)$row['discount'],
        'start'=>$row['start'], 'end'=>$row['end'], 'active'=>(bool)$row['active']];
}

function price(PDO $db, array $product): int {
    $today = substr(now(), 0, 10);
    $discount = 0;
    foreach (queryAll($db, 'SELECT * FROM promotions WHERE active=1 AND `start`<=? AND `end`>=?', [$today,$today]) as $promo) {
        if ($promo['scope'] === 'all'
            || ($promo['scope'] === 'category' && $promo['category_id'] === $product['category_id'])
            || ($promo['scope'] === 'products' && in_array($product['id'], jsonValue($promo['product_ids']), true))) {
            $discount = max($discount, (int)$promo['discount']);
        }
    }
    return intdiv((int)$product['price_cents'] * (100 - $discount) + 50, 100);
}

function productView(PDO $db, ?array $product): ?array {
    if (!$product) return null;
    $review = $product['condition'] === 'Seminuevo'
        ? queryOne($db, "SELECT battery_percent,grade,notes FROM reviews WHERE product_id=? AND status='Aprobado' ORDER BY reviewed_at DESC,seq DESC LIMIT 1", [$product['id']]) : null;
    return ['batteryPercent'=>isset($review['battery_percent']) ? (int)$review['battery_percent'] : null,
        'grade'=>$review['grade'] ?? null, 'technicalNotes'=>$review['notes'] ?? '',
        'id'=>$product['id'], 'name'=>$product['name'], 'categoryId'=>$product['category_id'],
        'condition'=>$product['condition'], 'description'=>$product['description'],
        'priceCents'=>(int)$product['price_cents'],
        'oldPriceCents'=>$product['old_price_cents'] === null ? null : (int)$product['old_price_cents'],
        'finalPriceCents'=>price($db,$product), 'stock'=>(int)$product['stock'],
        'warrantyMonths'=>(int)$product['warranty_months'], 'storage'=>$product['storage'],
        'screen'=>$product['screen'], 'camera'=>$product['camera'], 'image'=>$product['image'],
        'published'=>(bool)$product['published'], 'approved'=>(bool)$product['approved'], 'archived'=>(bool)$product['archived']];
}

function publicProduct(PDO $db, $id): array {
    $id = text($id, 'Producto', 100, 1);
    $p = queryOne($db, 'SELECT * FROM products WHERE id=? AND archived=0 AND published=1', [$id]);
    if (!$p || ($p['condition'] === 'Seminuevo' && !$p['approved'])) fail(404, 'Producto no disponible.');
    return $p;
}

function wallet(PDO $db, string $userId): array {
    $balance = queryOne($db, 'SELECT balance_cents FROM wallet_accounts WHERE user_id=?', [$userId]);
    $entries = [];
    foreach (queryAll($db, 'SELECT * FROM wallet_entries WHERE user_id=? ORDER BY seq DESC LIMIT 100', [$userId]) as $r) {
        $entries[] = ['id'=>$r['id'], 'deltaCents'=>(int)$r['delta_cents'], 'balanceAfterCents'=>(int)$r['balance_after_cents'],
            'reason'=>$r['reason'], 'orderId'=>$r['order_id'], 'tradeinId'=>$r['tradein_id'], 'createdAt'=>$r['created_at']];
    }
    return ['balanceCents'=>(int)($balance['balance_cents'] ?? 0), 'entries'=>$entries];
}

function walletEntry(PDO $db, string $userId, $delta, string $reason, string $sourceKey, ?string $orderId = null, ?string $tradeinId = null): void {
    if (!is_int($delta)) fail(500, 'Importe interno inválido.');
    $existing = queryOne($db, 'SELECT * FROM wallet_entries WHERE source_key=?', [$sourceKey]);
    if ($existing) {
        if ($existing['user_id'] !== $userId || (int)$existing['delta_cents'] !== $delta
            || $existing['order_id'] !== $orderId || $existing['tradein_id'] !== $tradeinId) {
            fail(409, 'La operación de saldo ya corresponde a otro movimiento.', 'idempotency_conflict');
        }
        return;
    }
    execute($db, 'INSERT INTO wallet_accounts(user_id,balance_cents) VALUES(?,0) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)', [$userId]);
    $balance = (int)queryOne($db, 'SELECT balance_cents FROM wallet_accounts WHERE user_id=? FOR UPDATE', [$userId])['balance_cents'];
    $next = $balance + $delta;
    if (!is_int($next) || $next < 0 || $next > 9007199254740991) fail(409, 'Saldo insuficiente o importe fuera de límite.');
    execute($db, 'UPDATE wallet_accounts SET balance_cents=? WHERE user_id=?', [$next,$userId]);
    execute($db, 'INSERT INTO wallet_entries(id,user_id,delta_cents,balance_after_cents,reason,source_key,order_id,tradein_id,created_at) VALUES(?,?,?,?,?,?,?,?,?)',
        [uid('wal'),$userId,$delta,$next,$reason,$sourceKey,$orderId,$tradeinId,now()]);
}

function address($value): array {
    if (!is_array($value)) fail(400, 'Falta dirección de entrega.');
    $a = ['recipient'=>text($value['recipient'] ?? null, 'Destinatario',100,2),
        'phone'=>text($value['phone'] ?? null, 'Teléfono',20,10),
        'line'=>text($value['line'] ?? null, 'Calle y número',220,3),
        'city'=>text($value['city'] ?? null, 'Ciudad',100,2),
        'postalCode'=>text($value['postalCode'] ?? null, 'Código postal',5,5)];
    if (strlen(preg_replace('/\D/', '', $a['phone'])) !== 10 || !preg_match('/^\d{5}$/D', $a['postalCode'])) {
        fail(400, 'Teléfono de 10 dígitos y código postal de 5 dígitos requeridos.');
    }
    return $a;
}

function orderView(PDO $db, ?array $order): ?array {
    if (!$order) return null;
    $paid = (int)queryOne($db, 'SELECT COALESCE(SUM(amount_cents),0) amount FROM payments WHERE order_id=?', [$order['id']])['amount'];
    $items = [];
    foreach (queryAll($db, 'SELECT * FROM order_items WHERE order_id=? ORDER BY id', [$order['id']]) as $r) {
        $items[] = ['id'=>$r['id'], 'productId'=>$r['product_id'], 'name'=>$r['name'], 'condition'=>$r['condition'],
            'quantity'=>(int)$r['quantity'], 'unitPriceCents'=>(int)$r['unit_price_cents']];
    }
    $history = [];
    foreach (queryAll($db, 'SELECT * FROM order_events WHERE order_id=? ORDER BY seq', [$order['id']]) as $r) {
        $history[] = ['status'=>$r['status'], 'location'=>$r['location'], 'createdAt'=>$r['created_at']];
    }
    return ['id'=>$order['id'], 'userId'=>$order['user_id'], 'customer'=>$order['customer'],
        'address'=>jsonValue($order['address']), 'items'=>$items, 'status'=>$order['status'],
        'subtotalCents'=>(int)$order['subtotal_cents'], 'shippingCents'=>(int)$order['shipping_cents'],
        'totalCents'=>(int)$order['total_cents'], 'walletAppliedCents'=>(int)$order['wallet_applied_cents'],
        'amountDueCents'=>max(0,(int)$order['amount_due_cents']-$paid), 'paidCents'=>$paid,
        'tradeinId'=>$order['tradein_id'], 'createdAt'=>$order['created_at'], 'location'=>$order['location'],
        'carrier'=>$order['carrier'], 'tracking'=>$order['tracking'], 'history'=>$history];
}

/** Caller holds one transaction for stock, order and wallet movements together. */
function createOrderInTransaction(PDO $db, array $user, array $body, array $options = []): array {
    $tradeinId = $options['tradeinId'] ?? null;
    $maxWalletCents = $options['maxWalletCents'] ?? null;
    $key = text($body['idempotencyKey'] ?? null, 'Identificador de operación',120,8);
    $shippingAddress = address($body['address'] ?? null);
    $useWallet = booleanValue($body['useWallet'] ?? false);
    $requested = $body['items'] ?? null;
    if (!is_array($requested) || !array_is_list($requested) || count($requested) < 1 || count($requested) > 30) {
        fail(400, 'Agrega entre 1 y 30 productos.');
    }
    $normalized = [];
    $unique = [];
    foreach ($requested as $entry) {
        if (!is_array($entry)) fail(400, 'Producto inválido.');
        $id = text($entry['productId'] ?? null, 'Producto',100,1);
        if (isset($unique[$id])) fail(400, 'Agrupa las cantidades de un mismo producto.');
        $unique[$id] = true;
        $normalized[] = ['productId'=>$id, 'quantity'=>integer($entry['quantity'] ?? null, 'Cantidad',1,100)];
    }
    $fingerprint = hash('sha256', encodeData(['items'=>$normalized, 'address'=>$shippingAddress, 'useWallet'=>$useWallet, 'tradeinId'=>$tradeinId]));
    $old = queryOne($db, 'SELECT * FROM orders WHERE user_id=? AND idempotency_key=?', [$user['id'],$key]);
    if ($old) {
        if ($old['tradein_id'] !== $tradeinId || $old['request_hash'] !== $fingerprint) {
            fail(409, 'Este identificador ya corresponde a otra operación.', 'idempotency_conflict');
        }
        return orderView($db,$old);
    }
    $subtotal = 0;
    $items = [];
    foreach ($normalized as $entry) {
        $product = publicProduct($db,$entry['productId']);
        $quantity = $entry['quantity'];
        if ((int)$product['stock'] < $quantity) fail(409, 'Inventario insuficiente para '.$product['name'], 'stock_changed');
        $unit = price($db,$product);
        if ($unit <= 0) fail(409, 'Este producto aún no tiene precio de venta.');
        $subtotal += $unit * $quantity;
        if (!is_int($subtotal) || $subtotal > 1000000000) fail(400, 'Pedido fuera del límite permitido.');
        $items[] = ['product'=>$product,'quantity'=>$quantity,'unit'=>$unit];
    }
    $total = $subtotal;
    $balance = wallet($db,$user['id'])['balanceCents'];
    $cap = $maxWalletCents ?? ($useWallet ? $balance : 0);
    if (!is_int($cap) || $cap < 0) fail(500, 'Límite de saldo inválido.');
    $applied = min($balance,$total,$cap);
    $due = $total-$applied;
    $id = 'TT-'.gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
    $stamp = now();
    $status = $due === 0 ? 'Pagado' : 'Pendiente de pago';
    execute($db, 'INSERT INTO orders(id,user_id,idempotency_key,request_hash,status,subtotal_cents,total_cents,wallet_applied_cents,amount_due_cents,customer,address,tradein_id,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$id,$user['id'],$key,$fingerprint,$status,$subtotal,$total,$applied,$due,$shippingAddress['recipient'],encodeData($shippingAddress),$tradeinId,$stamp,$stamp]);
    foreach ($items as $entry) {
        $p = $entry['product']; $qty = $entry['quantity'];
        $changed = execute($db, 'UPDATE products SET stock=stock-? WHERE id=? AND stock>=?', [$qty,$p['id'],$qty]);
        if (!$changed) fail(409, 'Las existencias cambiaron.', 'stock_changed');
        execute($db, 'INSERT INTO order_items(id,order_id,product_id,name,`condition`,quantity,unit_price_cents) VALUES(?,?,?,?,?,?,?)',
            [uid('item'),$id,$p['id'],$p['name'],$p['condition'],$qty,$entry['unit']]);
    }
    if ($applied) walletEntry($db,$user['id'],-$applied,'Aplicación de saldo a compra','order-debit:'.$id,$id,$tradeinId);
    execute($db, 'INSERT INTO order_events(id,order_id,actor_id,status,location,created_at) VALUES(?,?,?,?,?,?)',
        [uid('event'),$id,$user['id'],$status,'Pedido registrado',$stamp]);
    execute($db, 'DELETE FROM carts WHERE user_id=?', [$user['id']]);
    audit($db,$user,'order.create',$id,['totalCents'=>$total,'walletAppliedCents'=>$applied]);
    return orderView($db,queryOne($db, 'SELECT * FROM orders WHERE id=?', [$id]));
}

function createOrder(PDO $db, array $user, array $body): array {
    return transact($db, fn() => createOrderInTransaction($db,$user,$body));
}

function cancelOrder(PDO $db, array $user, string $id): array {
    return transact($db, function() use ($db,$user,$id) {
        $order = queryOne($db, 'SELECT * FROM orders WHERE id=? FOR UPDATE', [$id]);
        if (!$order || ($order['user_id'] !== $user['id'] && $user['role'] !== 'admin')) fail(404, 'Pedido no encontrado.');
        if ($order['status'] === 'Cancelado') return orderView($db,$order);
        if ($order['tradein_id']) fail(409, 'Un canje aceptado requiere gestionar su devolución con atención al cliente.');
        if ($order['status'] !== 'Pendiente de pago' || queryOne($db, 'SELECT id FROM payments WHERE order_id=? LIMIT 1', [$id])) {
            fail(409, 'Sólo puedes cancelar pedidos pendientes sin pagos externos.');
        }
        if (!$order['stock_restored']) {
            foreach (queryAll($db, 'SELECT * FROM order_items WHERE order_id=?', [$id]) as $item) {
                execute($db, 'UPDATE products SET stock=stock+? WHERE id=?', [(int)$item['quantity'],$item['product_id']]);
            }
            if ($order['wallet_applied_cents']) {
                walletEntry($db,$order['user_id'],(int)$order['wallet_applied_cents'],'Devolución de saldo por cancelación','order-refund:'.$id,$id);
            }
            execute($db, "UPDATE orders SET status='Cancelado',stock_restored=1,updated_at=? WHERE id=?", [now(),$id]);
            execute($db, 'INSERT INTO order_events(id,order_id,actor_id,status,location,created_at) VALUES(?,?,?,?,?,?)',
                [uid('event'),$id,$user['id'],'Cancelado','Cancelado antes del pago',now()]);
            audit($db,$user,'order.cancel',$id);
        }
        return orderView($db,queryOne($db, 'SELECT * FROM orders WHERE id=?', [$id]));
    });
}

function references(PDO $db): array {
    $result = [];
    foreach (queryAll($db, 'SELECT * FROM market_references WHERE active=1 ORDER BY model_key,seller,checked_at', []) as $r) {
        $confirmed = (bool)$r['availability_confirmed'];
        $result[] = ['id'=>$r['id'], 'modelKey'=>$r['model_key'], 'model'=>$r['model'], 'storage'=>$r['storage'],
            'category'=>$r['category'], 'amountCents'=>(int)$r['amount_cents'], 'currency'=>'MXN', 'sourceUrl'=>$r['source_url'],
            'sourceTitle'=>$r['source_title'], 'condition'=>$r['condition'], 'seller'=>$r['seller'], 'availability'=>$r['availability'],
            'availabilityConfirmed'=>$confirmed, 'available'=>$confirmed && in_array($r['availability'],['listed_available','listed_for_purchase'],true),
            'checkedAt'=>$r['checked_at'], 'expiresAt'=>$r['expires_at'], 'notes'=>$r['notes']];
    }
    return $result;
}

function models(PDO $db): array {
    $grouped = [];
    foreach (references($db) as $r) $grouped[$r['modelKey']][] = $r;
    $result = [];
    $current = microtime(true);
    foreach ($grouped as $key => $all) {
        $sellers = [];
        foreach ($all as $r) {
            $checked = strtotime($r['checkedAt']); $expires = strtotime($r['expiresAt']);
            if (!$r['available'] || $checked === false || $expires === false
                || $checked > $current+300 || $checked < $current-30*86400 || $expires <= $current) continue;
            $seller = serviceLower(trim($r['seller']));
            if (!isset($sellers[$seller]) || strcmp($r['checkedAt'],$sellers[$seller]['checkedAt']) > 0) $sellers[$seller] = $r;
        }
        $grades = [];
        foreach ($sellers as $r) {
            // Model key should already identify storage; this also prevents accidental mixed references.
            $group = serviceLower(trim($r['condition']).'|'.trim($r['storage']).'|'.trim($r['category']));
            $grades[$group][] = $r;
        }
        $comps = [];
        foreach ($grades as $set) if (count($set) > count($comps)) $comps = $set;
        $values = array_map(fn($r) => $r['amountCents'], $comps);
        sort($values,SORT_NUMERIC);
        $count = count($values);
        $half = intdiv($count,2);
        $median = $count ? ($count % 2 ? $values[$half] : intdiv($values[$half-1]+$values[$half]+1,2)) : null;
        $result[] = ['modelKey'=>$key, 'model'=>$all[0]['model'], 'storage'=>$all[0]['storage'], 'category'=>$all[0]['category'],
            'references'=>$all, 'availableReferenceCount'=>$count, 'retailMedianCents'=>$median, 'canQuote'=>$count >= 2,
            'reason'=>$count >= 2 ? '' : 'Se requieren dos vendedores independientes con referencias disponibles, vigentes y comparables. Solicita una valoración manual.'];
    }
    return $result;
}

function quote(PDO $db, $modelKey, $condition): array {
    $model = null;
    foreach (models($db) as $m) if ($m['modelKey'] === $modelKey) { $model=$m; break; }
    if (!$model) fail(400, 'Selecciona un modelo conocido.');
    $factors = ['excelente'=>0.70,'bueno'=>0.60,'regular'=>0.45];
    if (!is_string($condition) || !array_key_exists($condition,$factors)) fail(400, 'Condición inválida.');
    $configured = getenv('TRADE_FACTOR_'.strtoupper($condition));
    $factor = $configured === false || $configured === '' ? $factors[$condition] : (float)$configured;
    if (!is_finite($factor) || $factor <= 0 || $factor >= 1) fail(500, 'Configuración de valoración inválida.');
    if (!$model['canQuote']) fail(422, $model['reason'], 'manual_valuation_required');
    return ['modelKey'=>$modelKey, 'condition'=>$condition, 'estimatedCents'=>(int)round($model['retailMedianCents']*$factor),
        'retailMedianCents'=>$model['retailMedianCents'], 'factor'=>$factor, 'referenceCount'=>$model['availableReferenceCount'],
        'asOf'=>now(), 'provisional'=>true];
}

function tradeinView(PDO $db, ?array $r): ?array {
    if (!$r) return null;
    return ['id'=>$r['id'], 'userId'=>$r['user_id'], 'targetProductId'=>$r['target_product_id'], 'modelKey'=>$r['model_key'],
        'condition'=>$r['condition'], 'notes'=>$r['notes'], 'photos'=>array_map(fn($id) => ['id'=>$id,'url'=>'/media/'.$id],jsonValue($r['photo_ids'])),
        'estimatedCents'=>$r['estimated_cents'] === null ? null : (int)$r['estimated_cents'],
        'offerCents'=>$r['offer_cents'] === null ? null : (int)$r['offer_cents'], 'status'=>$r['status'],
        'inspection'=>$r['inspection'] === null ? null : jsonValue($r['inspection']), 'orderId'=>$r['order_id'], 'createdAt'=>$r['created_at'],
        'targetProduct'=>productView($db,queryOne($db, 'SELECT * FROM products WHERE id=?', [$r['target_product_id']]))];
}

function inspection(array $body): array {
    $decision = $body['decision'] ?? null;
    if (!in_array($decision,['approve','reject'],true)) fail(400, 'Dictamen inválido.');
    $serial = text($body['serial'] ?? '', 'Serie/IMEI',100,$decision === 'approve' ? 3 : 0);
    $notes = text($body['notes'] ?? '', 'Observaciones',3000,$decision === 'reject' ? 10 : 0);
    $checks = $body['checks'] ?? null;
    if (!is_array($checks) || !array_is_list($checks) || count($checks) !== 6) fail(400, 'Registra las seis pruebas.');
    foreach ($checks as $check) if (!is_bool($check)) fail(400, 'Registra las seis pruebas.');
    if ($decision === 'approve' && in_array(false,$checks,true)) fail(400, 'La aprobación requiere todas las pruebas correctas.');
    return ['decision'=>$decision, 'serial'=>$serial, 'notes'=>$notes, 'checks'=>$checks,
        'batteryPercent'=>integer($body['batteryPercent'] ?? null, 'Batería',0,100),
        'grade'=>text($body['grade'] ?? 'B', 'Grado estético',80,1)];
}

function acceptTradein(PDO $db, array $user, string $id, array $body): array {
    return transact($db, function() use ($db,$user,$id,$body) {
        $r = queryOne($db, 'SELECT * FROM tradeins WHERE id=? AND user_id=? FOR UPDATE', [$id,$user['id']]);
        if (!$r) fail(404, 'Canje no encontrado.');
        if ($r['status'] === 'Aceptado') {
            return ['tradein'=>tradeinView($db,$r), 'order'=>orderView($db,queryOne($db, 'SELECT * FROM orders WHERE id=?', [$r['order_id']])), 'wallet'=>wallet($db,$user['id'])];
        }
        if ($r['status'] !== 'Oferta disponible' || $r['offer_cents'] === null || (int)$r['offer_cents'] <= 0) {
            fail(409, 'El canje aún no tiene una oferta aprobada.');
        }
        $offer = (int)$r['offer_cents'];
        if ($offer !== integer($body['expectedOfferCents'] ?? null, 'Oferta esperada',1)) {
            fail(409, 'La oferta cambió. Revisa el valor antes de aceptar.', 'offer_changed');
        }
        $key = text($body['idempotencyKey'] ?? null, 'Identificador de operación',120,8);
        $duplicate = queryOne($db, 'SELECT * FROM orders WHERE user_id=? AND idempotency_key=?', [$user['id'],$key]);
        if ($duplicate && $duplicate['tradein_id'] !== $r['id']) fail(409, 'Identificador utilizado en otra operación.', 'idempotency_conflict');
        $p = publicProduct($db,$r['target_product_id']);
        if (price($db,$p) !== integer($body['expectedPriceCents'] ?? null, 'Precio esperado',1)) {
            fail(409, 'El precio cambió. Revisa el importe antes de aceptar.', 'price_changed');
        }
        if ((int)$p['stock'] < 1) fail(409, 'El producto seleccionado ya no tiene existencias.', 'stock_changed');
        $useWallet = booleanValue($body['useWallet'] ?? false);
        walletEntry($db,$user['id'],$offer,'Valor de equipo entregado en canje','trade-credit:'.$r['id'],null,$r['id']);
        $cap = $useWallet ? wallet($db,$user['id'])['balanceCents'] : $offer;
        $orderBody = $body;
        $orderBody['items'] = [['productId'=>$p['id'],'quantity'=>1]];
        $orderBody['useWallet'] = $useWallet;
        $order = createOrderInTransaction($db,$user,$orderBody,['tradeinId'=>$r['id'],'maxWalletCents'=>$cap]);
        execute($db, "UPDATE tradeins SET status='Aceptado',order_id=?,updated_at=? WHERE id=? AND status='Oferta disponible'", [$order['id'],now(),$r['id']]);
        audit($db,$user,'tradein.accept',$r['id'],['offerCents'=>$offer,'orderId'=>$order['id']]);
        return ['tradein'=>tradeinView($db,queryOne($db, 'SELECT * FROM tradeins WHERE id=?', [$r['id']])), 'order'=>$order, 'wallet'=>wallet($db,$user['id'])];
    });
}

function reviewView(PDO $db, array $r): array {
    return ['id'=>$r['id'], 'productId'=>$r['product_id'], 'product'=>productView($db,queryOne($db, 'SELECT * FROM products WHERE id=?', [$r['product_id']])),
        'status'=>$r['status'], 'serial'=>$r['serial'], 'checks'=>jsonValue($r['checks']),
        'batteryPercent'=>$r['battery_percent'] === null ? null : (int)$r['battery_percent'], 'grade'=>$r['grade'], 'notes'=>$r['notes'],
        'createdAt'=>$r['created_at'], 'reviewedAt'=>$r['reviewed_at']];
}

function ticketView(PDO $db, array $r): array {
    $customer = queryOne($db, 'SELECT name FROM users WHERE id=?', [$r['user_id']]);
    $messages = [];
    foreach (queryAll($db, 'SELECT m.*,u.name,u.role FROM ticket_messages m JOIN users u ON u.id=m.author_id WHERE m.ticket_id=? ORDER BY m.seq', [$r['id']]) as $message) {
        $messages[] = ['id'=>$message['id'], 'author'=>$message['name'], 'role'=>$message['role'], 'text'=>$message['text'], 'createdAt'=>$message['created_at']];
    }
    return ['id'=>$r['id'], 'userId'=>$r['user_id'], 'orderId'=>$r['order_id'], 'reason'=>$r['reason'], 'status'=>$r['status'],
        'createdAt'=>$r['created_at'], 'customer'=>$customer['name'] ?? null, 'messages'=>$messages];
}
