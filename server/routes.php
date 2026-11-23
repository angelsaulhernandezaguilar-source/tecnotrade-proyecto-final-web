<?php
declare(strict_types=1);

/** Commerce routes. The dispatcher owns the request transaction and write lock. */
function commerceOrder(PDO $db, array $user, string $id): array
{
    $row = queryOne($db, 'SELECT * FROM orders WHERE id=?', [$id]);
    if (!$row || !in_array($user['role'], ['cliente', 'admin', 'logistica', 'soporte'], true)
        || ($user['role'] === 'cliente' && $row['user_id'] !== $user['id'])) {
        fail(404, 'Pedido no encontrado.');
    }
    return $row;
}

function commerceTrade(PDO $db, array $user, string $id): array
{
    $row = queryOne($db, 'SELECT * FROM tradeins WHERE id=?', [$id]);
    if (!$row || !in_array($user['role'], ['cliente', 'admin', 'tecnico'], true)
        || ($user['role'] === 'cliente' && $row['user_id'] !== $user['id'])) {
        fail(404, 'Canje no encontrado.');
    }
    return $row;
}

function commerceTicket(PDO $db, array $user, string $id): array
{
    $row = queryOne($db, 'SELECT * FROM tickets WHERE id=?', [$id]);
    if (!$row || (!in_array($user['role'], ['admin', 'soporte'], true) && $row['user_id'] !== $user['id'])) {
        fail(404, 'Consulta no encontrada.');
    }
    return $row;
}

function commerceLower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function commerceDate(mixed $value): ?DateTimeImmutable
{
    if (!is_string($value) || strlen($value) > 50
        || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
        return null;
    }
    try {
        $date = new DateTimeImmutable($value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) {
            return null;
        }
        return $date->setTimezone(new DateTimeZone('UTC'));
    } catch (Throwable $error) {
        return null;
    }
}

function commerceDay(mixed $value): bool
{
    if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts)) {
        return false;
    }
    return checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]);
}

function routeCommerce(PDO $db, string $path, string $method, ?array $session, array $body, array $query, array $config): ?array
{
    if ($path === '/api/categories' && $method === 'GET') {
        return [200, ['categories' => array_map('categoryView', queryAll($db, 'SELECT * FROM categories ORDER BY position,name'))]];
    }

    if (in_array($path, ['/api/products', '/api/search/suggestions'], true) && $method === 'GET') {
        $items = queryAll($db, "SELECT * FROM products WHERE archived=0 AND published=1 AND (`condition`='Nuevo' OR approved=1)");
        $search = commerceLower(text($query['q'] ?? '', 'Búsqueda', 200));
        $category = text($query['category'] ?? '', 'Categoría', 100);
        $condition = text($query['condition'] ?? '', 'Condición', 30);
        $items = array_values(array_filter($items, static function (array $product) use ($search, $category, $condition): bool {
            return ($search === '' || str_contains(commerceLower($product['name'] . ' ' . $product['description']), $search))
                && ($category === '' || $product['category_id'] === $category)
                && ($condition === '' || $product['condition'] === $condition);
        }));
        $sort = $query['sort'] ?? '';
        usort($items, static function (array $a, array $b) use ($db, $sort): int {
            if ($sort === 'price_asc') return price($db, $a) <=> price($db, $b);
            if ($sort === 'price_desc') return price($db, $b) <=> price($db, $a);
            return strnatcasecmp($a['name'], $b['name']);
        });
        if ($path === '/api/search/suggestions') {
            return [200, ['suggestions' => array_map(static fn(array $p): array => [
                'id' => $p['id'], 'name' => $p['name'], 'priceCents' => price($db, $p), 'image' => $p['image'],
            ], array_slice($items, 0, 7))]];
        }
        return [200, ['products' => array_map(static fn(array $p): array => productView($db, $p), $items)]];
    }

    if (preg_match('~^/api/products/([^/]+)$~D', $path, $match) && $method === 'GET') {
        return [200, ['product' => productView($db, publicProduct($db, $match[1]))]];
    }

    if ($path === '/api/orders') {
        $user = requireUser($session, ['cliente', 'admin', 'logistica', 'soporte']);
        if ($method === 'GET') {
            $rows = $user['role'] === 'cliente'
                ? queryAll($db, 'SELECT * FROM orders WHERE user_id=? ORDER BY created_at DESC', [$user['id']])
                : queryAll($db, 'SELECT * FROM orders ORDER BY created_at DESC LIMIT 300');
            return [200, ['orders' => array_map(static fn(array $o): array => orderView($db, $o), $rows)]];
        }
        if ($method === 'POST') {
            requireUser($session, ['cliente']);
            $order = createOrder($db, $user, $body);
            return [201, ['order' => $order, 'wallet' => wallet($db, $user['id'])]];
        }
    }

    if (preg_match('~^/api/orders/([^/]+)(?:/(cancel|status))?$~D', $path, $match)) {
        $user = requireUser($session);
        $order = commerceOrder($db, $user, $match[1]);
        $action = $match[2] ?? '';
        if ($action === '' && $method === 'GET') {
            return [200, ['order' => orderView($db, $order)]];
        }
        if ($action === 'cancel' && $method === 'POST') {
            requireUser($session, ['cliente', 'admin']);
            $updated = cancelOrder($db, $user, $order['id']);
            return [200, ['order' => $updated, 'wallet' => wallet($db, $order['user_id'])]];
        }
        if ($action === 'status' && $method === 'PATCH') {
            requireUser($session, ['admin', 'logistica']);
            $next = text($body['status'] ?? null, 'Estado', 50, 1);
            $transitions = [
                'Pagado' => ['Pagado', 'Preparando'],
                'Preparando' => ['Preparando', 'En camino'],
                'En camino' => ['En camino', 'Entregado'],
            ];
            if (!in_array($next, $transitions[$order['status']] ?? [], true)) {
                fail(409, 'Transición de pedido no permitida.');
            }
            $location = text($body['location'] ?? '', 'Ubicación', 240, 2);
            $carrier = text($body['carrier'] ?? 'Reparto propio', 'Paquetería', 80);
            $tracking = text($body['tracking'] ?? '', 'Guía', 100);
            if ($next === 'En camino' && $carrier !== 'Reparto propio' && $tracking === '') {
                fail(400, 'Registra una guía antes de despachar con paquetería externa.');
            }
            transact($db, static function () use ($db, $user, $order, $next, $transitions, $location, $carrier, $tracking, $body): void {
                $current = queryOne($db, 'SELECT * FROM orders WHERE id=? FOR UPDATE', [$order['id']]);
                if (!$current || !in_array($next, $transitions[$current['status']] ?? [], true)) {
                    fail(409, 'El estado cambió mientras actualizabas el pedido.');
                }
                execute($db, 'UPDATE orders SET status=?,location=?,carrier=?,tracking=?,note=?,updated_at=? WHERE id=? AND status=?', [
                    $next, $location, $carrier, $tracking, text($body['note'] ?? '', 'Nota interna', 2000), now(), $order['id'], $current['status'],
                ]);
                execute($db, 'INSERT INTO order_events(id,order_id,actor_id,status,location,created_at) VALUES(?,?,?,?,?,?)', [
                    uid('event'), $order['id'], $user['id'], $next, $location, now(),
                ]);
                audit($db, $user, 'order.status', $order['id'], ['status' => $next]);
            });
            return [200, ['order' => orderView($db, queryOne($db, 'SELECT * FROM orders WHERE id=?', [$order['id']]))]];
        }
    }

    if ($path === '/api/models' && $method === 'GET') {
        return [200, ['models' => models($db)]];
    }

    if ($path === '/api/tradeins/quote' && $method === 'POST') {
        requireUser($session, ['cliente']);
        return [200, ['quote' => quote($db, text($body['modelKey'] ?? null, 'Modelo', 100, 1), text($body['condition'] ?? null, 'Condición', 30, 1))]];
    }

    if ($path === '/api/tradeins') {
        $user = requireUser($session, ['cliente', 'admin', 'tecnico']);
        if ($method === 'GET') {
            $rows = $user['role'] === 'cliente'
                ? queryAll($db, 'SELECT * FROM tradeins WHERE user_id=? ORDER BY created_at DESC', [$user['id']])
                : queryAll($db, 'SELECT * FROM tradeins ORDER BY created_at DESC LIMIT 300');
            return [200, ['tradeins' => array_map(static fn(array $r): array => tradeinView($db, $r), $rows)]];
        }
        if ($method === 'POST') {
            requireUser($session, ['cliente']);
            $product = publicProduct($db, text($body['targetProductId'] ?? null, 'Producto seleccionado', 100, 1));
            if ($product['stock'] < 1) fail(409, 'El equipo seleccionado está agotado.');
            $modelKey = text($body['modelKey'] ?? null, 'Modelo', 100, 1);
            if (!array_filter(models($db), static fn(array $m): bool => $m['modelKey'] === $modelKey)) {
                fail(400, 'Selecciona el modelo que vas a entregar.');
            }
            $condition = $body['condition'] ?? '';
            if (!in_array($condition, ['excelente', 'bueno', 'regular'], true)) fail(400, 'Condición inválida.');
            $photos = $body['photoIds'] ?? [];
            if (!is_array($photos) || !array_is_list($photos) || count($photos) > 3) fail(400, 'Máximo tres fotografías.');
            $photos = array_values(array_unique(array_map(static fn(mixed $id): string => text($id, 'Fotografía', 100, 1), $photos)));
            foreach ($photos as $photoId) {
                if (!queryOne($db, "SELECT id FROM uploads WHERE id=? AND user_id=? AND purpose='tradein'", [$photoId, $user['id']])) {
                    fail(400, 'La fotografía no pertenece a tu cuenta.');
                }
            }
            $estimate = null;
            try {
                $estimate = quote($db, $modelKey, $condition)['estimatedCents'];
            } catch (ApiError $error) {
                if ($error->errorCode !== 'manual_valuation_required') throw $error;
            }
            $id = uid('canje');
            execute($db, 'INSERT INTO tradeins(id,user_id,target_product_id,model_key,`condition`,notes,photo_ids,estimated_cents,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)', [
                $id, $user['id'], $product['id'], $modelKey, $condition, text($body['notes'] ?? '', 'Descripción', 2000),
                json_encode($photos, JSON_THROW_ON_ERROR), $estimate, 'Solicitado', now(), now(),
            ]);
            return [201, ['tradein' => tradeinView($db, queryOne($db, 'SELECT * FROM tradeins WHERE id=?', [$id]))]];
        }
    }

    if (preg_match('~^/api/tradeins/([^/]+)(?:/(inspection|accept))?$~D', $path, $match)) {
        $user = requireUser($session);
        $trade = commerceTrade($db, $user, $match[1]);
        $action = $match[2] ?? '';
        if ($action === '' && $method === 'GET') return [200, ['tradein' => tradeinView($db, $trade)]];
        if ($action === 'inspection' && $method === 'PATCH') {
            requireUser($session, ['tecnico']);
            if (in_array($trade['status'], ['Aceptado', 'Rechazado'], true)) fail(409, 'Este canje ya está cerrado.');
            $check = inspection($body);
            $offer = $check['decision'] === 'approve' ? integer($body['offerCents'] ?? null, 'Oferta', 1) : null;
            transact($db, static function () use ($db, $user, $trade, $check, $offer): void {
                $current = queryOne($db, 'SELECT * FROM tradeins WHERE id=? FOR UPDATE', [$trade['id']]);
                if (!$current || in_array($current['status'], ['Aceptado', 'Rechazado'], true)) fail(409, 'El canje ya está cerrado.');
                $serial = $check['decision'] === 'approve' ? preg_replace('/[^A-Z0-9]/', '', strtoupper($check['serial'])) : null;
                if ($check['decision'] === 'approve' && strlen($serial) < 3) fail(400, 'Serie/IMEI inválido.');
                execute($db, 'UPDATE tradeins SET offer_cents=?,status=?,serial_normalized=?,inspection=?,technician_id=?,updated_at=? WHERE id=? AND status=?', [
                    $offer, $check['decision'] === 'approve' ? 'Oferta disponible' : 'Rechazado', $serial,
                    json_encode($check, JSON_THROW_ON_ERROR), $user['id'], now(), $trade['id'], $current['status'],
                ]);
                audit($db, $user, 'tradein.inspect', $trade['id'], ['decision' => $check['decision'], 'offerCents' => $offer]);
            });
            return [200, ['tradein' => tradeinView($db, queryOne($db, 'SELECT * FROM tradeins WHERE id=?', [$trade['id']]))]];
        }
        if ($action === 'accept' && $method === 'POST') {
            requireUser($session, ['cliente']);
            return [200, acceptTradein($db, $user, $trade['id'], $body)];
        }
    }

    if ($path === '/api/tickets') {
        $user = requireUser($session, ['cliente', 'soporte', 'admin']);
        if ($method === 'GET') {
            $rows = $user['role'] === 'cliente'
                ? queryAll($db, 'SELECT * FROM tickets WHERE user_id=? ORDER BY created_at DESC', [$user['id']])
                : queryAll($db, 'SELECT * FROM tickets ORDER BY created_at DESC LIMIT 300');
            return [200, ['tickets' => array_map(static fn(array $r): array => ticketView($db, $r), $rows)]];
        }
        if ($method === 'POST') {
            requireUser($session, ['cliente']);
            $orderId = $body['orderId'] ?? null;
            if ($orderId !== null && $orderId !== '') {
                $orderId = text($orderId, 'Pedido', 100, 1);
                if (!queryOne($db, 'SELECT id FROM orders WHERE id=? AND user_id=?', [$orderId, $user['id']])) {
                    fail(400, 'Selecciona un pedido de tu cuenta.');
                }
            } else $orderId = null;
            $id = uid('sop');
            $message = text($body['message'] ?? null, 'Mensaje', 4000, 10);
            transact($db, static function () use ($db, $id, $user, $orderId, $body, $message): void {
                execute($db, 'INSERT INTO tickets(id,user_id,order_id,reason,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?)', [
                    $id, $user['id'], $orderId, text($body['reason'] ?? null, 'Motivo', 100, 2), 'Abierto', now(), now(),
                ]);
                execute($db, 'INSERT INTO ticket_messages(id,ticket_id,author_id,text,created_at) VALUES(?,?,?,?,?)', [uid('msg'), $id, $user['id'], $message, now()]);
            });
            return [201, ['ticket' => ticketView($db, queryOne($db, 'SELECT * FROM tickets WHERE id=?', [$id]))]];
        }
    }

    if (preg_match('~^/api/tickets/([^/]+)(?:/(messages))?$~D', $path, $match)) {
        $user = requireUser($session, ['cliente', 'soporte', 'admin']);
        $ticket = commerceTicket($db, $user, $match[1]);
        $action = $match[2] ?? '';
        if ($action === '' && $method === 'GET') return [200, ['ticket' => ticketView($db, $ticket)]];
        if ($action === 'messages' && $method === 'POST') {
            $message = text($body['message'] ?? null, 'Mensaje', 4000, 2);
            $status = $ticket['status'];
            if (array_key_exists('status', $body)) {
                requireUser($session, ['soporte', 'admin']);
                if (!in_array($body['status'], ['Abierto', 'En proceso', 'Resuelto'], true)) fail(400, 'Estado inválido.');
                $status = $body['status'];
            } elseif ($user['role'] === 'cliente' && $status === 'Resuelto') {
                $status = 'Abierto';
            }
            transact($db, static function () use ($db, $ticket, $user, $message, $status): void {
                execute($db, 'INSERT INTO ticket_messages(id,ticket_id,author_id,text,created_at) VALUES(?,?,?,?,?)', [uid('msg'), $ticket['id'], $user['id'], $message, now()]);
                execute($db, 'UPDATE tickets SET status=?,updated_at=? WHERE id=?', [$status, now(), $ticket['id']]);
            });
            return [200, ['ticket' => ticketView($db, queryOne($db, 'SELECT * FROM tickets WHERE id=?', [$ticket['id']]))]];
        }
    }

    if ($path === '/api/reviews' && $method === 'GET') {
        requireUser($session, ['tecnico', 'admin']);
        return [200, ['reviews' => array_map(static fn(array $r): array => reviewView($db, $r), queryAll($db, 'SELECT * FROM reviews ORDER BY created_at DESC,seq DESC'))]];
    }

    if (preg_match('~^/api/reviews/([^/]+)$~D', $path, $match) && $method === 'PATCH') {
        $user = requireUser($session, ['tecnico']);
        $review = queryOne($db, 'SELECT * FROM reviews WHERE id=?', [$match[1]]);
        if (!$review) fail(404, 'Revisión no encontrada.');
        if (in_array($review['status'], ['Aprobado', 'Rechazado'], true)) fail(409, 'La revisión ya tiene dictamen.');
        $check = inspection($body);
        transact($db, static function () use ($db, $user, $review, $check): void {
            $current = queryOne($db, 'SELECT * FROM reviews WHERE id=? FOR UPDATE', [$review['id']]);
            if (!$current || in_array($current['status'], ['Aprobado', 'Rechazado'], true)) fail(409, 'La revisión ya está cerrada.');
            execute($db, 'UPDATE reviews SET technician_id=?,status=?,serial=?,checks=?,battery_percent=?,grade=?,notes=?,reviewed_at=? WHERE id=? AND status=?', [
                $user['id'], $check['decision'] === 'approve' ? 'Aprobado' : 'Rechazado', $check['serial'] ?: null,
                json_encode($check['checks'], JSON_THROW_ON_ERROR), $check['batteryPercent'], $check['grade'], $check['notes'], now(), $review['id'], $current['status'],
            ]);
            execute($db, 'UPDATE products SET approved=?,published=0 WHERE id=?', [$check['decision'] === 'approve' ? 1 : 0, $review['product_id']]);
            audit($db, $user, 'review.inspect', $review['id'], ['decision' => $check['decision']]);
        });
        return [200, ['review' => reviewView($db, queryOne($db, 'SELECT * FROM reviews WHERE id=?', [$review['id']]))]];
    }

    if (str_starts_with($path, '/api/admin/')) {
        $user = requireUser($session, ['admin']);
        if ($path === '/api/admin/overview' && $method === 'GET') {
            $orders = queryAll($db, 'SELECT * FROM orders ORDER BY created_at DESC');
            $countStatus = static fn(string $status): int => count(array_filter($orders, static fn(array $o): bool => $o['status'] === $status));
            return [200, [
                'stats' => [
                    'orders' => count($orders), 'pending' => $countStatus('Pendiente de pago'),
                    'toPrepare' => $countStatus('Pagado') + $countStatus('Preparando'),
                    'revenueCents' => (int)(queryOne($db, 'SELECT COALESCE(SUM(amount_cents),0) total FROM payments')['total'] ?? 0),
                    'products' => (int)(queryOne($db, 'SELECT COUNT(*) n FROM products WHERE archived=0')['n'] ?? 0),
                    'customers' => (int)(queryOne($db, "SELECT COUNT(*) n FROM users WHERE role='cliente'")['n'] ?? 0),
                ],
                'orderStatuses' => array_map(static fn(string $status): array => ['status' => $status, 'count' => $countStatus($status)], ['Pendiente de pago', 'Pagado', 'Preparando', 'En camino', 'Entregado', 'Cancelado']),
                'recentOrders' => array_map(static fn(array $o): array => orderView($db, $o), array_slice($orders, 0, 6)),
            ]];
        }

        if (preg_match('~^/api/admin/(products|categories|promotions|users|market-references)(?:/([^/]+))?$~D', $path, $match)) {
            $type = $match[1];
            $id = $match[2] ?? null;

            if ($type === 'products') {
                if ($method === 'GET') return [200, ['products' => array_map(static fn(array $p): array => productView($db, $p), queryAll($db, 'SELECT * FROM products WHERE archived=0 ORDER BY created_at DESC'))]];
                $old = $id !== null ? queryOne($db, 'SELECT * FROM products WHERE id=?', [$id]) : null;
                if ($id !== null && !$old) fail(404, 'Producto no encontrado.');
                if ($method === 'DELETE' && $id !== null) {
                    execute($db, 'UPDATE products SET published=0,archived=1 WHERE id=?', [$id]);
                    audit($db, $user, 'product.archive', $id);
                    return [200, ['ok' => true]];
                }
                if (($method === 'POST' && $id === null) || ($method === 'PATCH' && $id !== null)) {
                    $condition = $body['condition'] ?? $old['condition'] ?? 'Nuevo';
                    if (!in_array($condition, ['Nuevo', 'Seminuevo'], true)) fail(400, 'Condición inválida.');
                    $approved = $condition === 'Nuevo' || (($old['condition'] ?? '') === 'Seminuevo' && (bool)$old['approved']);
                    $published = booleanValue($body['published'] ?? null, (bool)($old['published'] ?? false));
                    $priceCents = integer($body['priceCents'] ?? $old['price_cents'] ?? 0, 'Precio');
                    $stock = integer($body['stock'] ?? $old['stock'] ?? 0, 'Inventario', 0, 10000);
                    if ($condition === 'Seminuevo' && $stock > 1) fail(400, 'Registra cada seminuevo por separado (stock máximo 1).');
                    if ($published && (!$approved || $priceCents < 1)) fail(409, 'Para publicar necesitas precio positivo y, si es seminuevo, revisión aprobada.');
                    $category = text($body['categoryId'] ?? $old['category_id'] ?? null, 'Categoría', 100, 1);
                    if (!queryOne($db, 'SELECT id FROM categories WHERE id=?', [$category])) fail(400, 'Categoría no válida.');
                    $oldPrice = array_key_exists('oldPriceCents', $body) ? $body['oldPriceCents'] : ($old['old_price_cents'] ?? null);
                    if ($oldPrice !== null) {
                        integer($oldPrice, 'Precio anterior', 1);
                        if ($oldPrice <= $priceCents) fail(400, 'El precio anterior debe ser mayor al de venta.');
                    }
                    $image = text($body['image'] ?? $old['image'] ?? '', 'Imagen', 200);
                    if ($image !== '' && (!preg_match('~^/(assets|media)/[a-zA-Z0-9_./-]+$~D', $image) || str_contains($image, '..'))) fail(400, 'Selecciona una imagen local válida.');
                    if (str_starts_with($image, '/media/') && !queryOne($db, "SELECT id FROM uploads WHERE id=? AND purpose='product'", [substr($image, 7)])) {
                        fail(400, 'Selecciona una imagen de producto válida.');
                    }
                    $values = [
                        text($body['name'] ?? $old['name'] ?? null, 'Nombre', 180, 2), $category, $condition,
                        text($body['description'] ?? $old['description'] ?? '', 'Descripción', 3000), $priceCents, $oldPrice, $stock,
                        integer($body['warrantyMonths'] ?? $old['warranty_months'] ?? 0, 'Garantía', 0, 120),
                        text($body['storage'] ?? $old['storage'] ?? '', 'Almacenamiento', 100),
                        text($body['screen'] ?? $old['screen'] ?? '', 'Pantalla', 100),
                        text($body['camera'] ?? $old['camera'] ?? '', 'Cámara', 100), $image, $published ? 1 : 0, $approved ? 1 : 0,
                    ];
                    $productId = $id ?? uid('prod');
                    transact($db, static function () use ($db, $user, $old, $productId, $values, $condition, $approved): void {
                        if ($old) {
                            execute($db, 'UPDATE products SET name=?,category_id=?,`condition`=?,description=?,price_cents=?,old_price_cents=?,stock=?,warranty_months=?,storage=?,screen=?,camera=?,image=?,published=?,approved=? WHERE id=?', [...$values, $productId]);
                        } else {
                            execute($db, 'INSERT INTO products(id,name,category_id,`condition`,description,price_cents,old_price_cents,stock,warranty_months,storage,screen,camera,image,published,approved,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [$productId, ...$values, now()]);
                        }
                        if ($condition === 'Seminuevo' && !$approved && !queryOne($db, "SELECT id FROM reviews WHERE product_id=? AND status='Pendiente'", [$productId])) {
                            execute($db, 'INSERT INTO reviews(id,product_id,created_at) VALUES(?,?,?)', [uid('rev'), $productId, now()]);
                        }
                        audit($db, $user, 'product.save', $productId);
                    });
                    return [$old ? 200 : 201, ['product' => productView($db, queryOne($db, 'SELECT * FROM products WHERE id=?', [$productId]))]];
                }
            }

            if ($type === 'categories') {
                if ($method === 'GET') return [200, ['categories' => array_map('categoryView', queryAll($db, 'SELECT * FROM categories ORDER BY position,name'))]];
                $old = $id !== null ? queryOne($db, 'SELECT * FROM categories WHERE id=?', [$id]) : null;
                if ($id !== null && !$old) fail(404, 'Categoría no encontrada.');
                if ($method === 'DELETE' && $id !== null) {
                    if (queryOne($db, 'SELECT id FROM products WHERE category_id=? LIMIT 1', [$id]) || queryOne($db, 'SELECT id FROM promotions WHERE category_id=? LIMIT 1', [$id])) fail(409, 'La categoría tiene productos o promociones vinculadas.');
                    execute($db, 'DELETE FROM categories WHERE id=?', [$id]);
                    audit($db, $user, 'category.delete', $id);
                    return [200, ['ok' => true]];
                }
                if (($method === 'POST' && $id === null) || ($method === 'PATCH' && $id !== null)) {
                    $categoryId = $id ?? uid('cat');
                    $values = [text($body['name'] ?? $old['name'] ?? null, 'Nombre', 70, 2), text($body['icon'] ?? $old['icon'] ?? 'grid', 'Icono', 30), integer($body['position'] ?? $old['position'] ?? 1, 'Posición', 0, 1000)];
                    if ($old) execute($db, 'UPDATE categories SET name=?,icon=?,position=? WHERE id=?', [...$values, $categoryId]);
                    else execute($db, 'INSERT INTO categories(id,name,icon,position) VALUES(?,?,?,?)', [$categoryId, ...$values]);
                    audit($db, $user, 'category.save', $categoryId);
                    return [$old ? 200 : 201, ['category' => categoryView(queryOne($db, 'SELECT * FROM categories WHERE id=?', [$categoryId]))]];
                }
            }

            if ($type === 'promotions') {
                if ($method === 'GET') return [200, ['promotions' => array_map('promotionView', queryAll($db, 'SELECT * FROM promotions ORDER BY `start` DESC'))]];
                $old = $id !== null ? queryOne($db, 'SELECT * FROM promotions WHERE id=?', [$id]) : null;
                if ($id !== null && !$old) fail(404, 'Promoción no encontrada.');
                if ($method === 'DELETE' && $id !== null) {
                    execute($db, 'UPDATE promotions SET active=0 WHERE id=?', [$id]);
                    audit($db, $user, 'promotion.archive', $id);
                    return [200, ['ok' => true]];
                }
                if (($method === 'POST' && $id === null) || ($method === 'PATCH' && $id !== null)) {
                    $scope = $body['scope'] ?? $old['scope'] ?? 'all';
                    $categoryId = $body['categoryId'] ?? $old['category_id'] ?? null;
                    $products = $body['productIds'] ?? ($old ? jsonValue($old['product_ids']) : []);
                    $start = $body['start'] ?? $old['start'] ?? null;
                    $end = $body['end'] ?? $old['end'] ?? null;
                    if (!in_array($scope, ['all', 'category', 'products'], true) || !commerceDay($start) || !commerceDay($end) || $start > $end) fail(400, 'Alcance o vigencia inválidos.');
                    if ($scope === 'category' && (!is_string($categoryId) || !queryOne($db, 'SELECT id FROM categories WHERE id=?', [$categoryId]))) fail(400, 'Categoría no válida.');
                    if (!is_array($products) || !array_is_list($products) || count($products) > 100 || ($scope === 'products' && count($products) === 0)) fail(400, 'Selecciona productos válidos.');
                    foreach ($products as $productId) {
                        if (!is_string($productId) || !queryOne($db, 'SELECT id FROM products WHERE id=?', [$productId])) fail(400, 'Producto de promoción no válido.');
                    }
                    $values = [
                        text($body['title'] ?? $old['title'] ?? null, 'Título', 180, 2), $scope, $scope === 'category' ? $categoryId : null,
                        json_encode($scope === 'products' ? array_values(array_unique($products)) : [], JSON_THROW_ON_ERROR),
                        integer($body['discount'] ?? $old['discount'] ?? null, 'Descuento', 1, 90), $start, $end,
                        booleanValue($body['active'] ?? null, (bool)($old['active'] ?? true)) ? 1 : 0,
                    ];
                    $promotionId = $id ?? uid('promo');
                    if ($old) execute($db, 'UPDATE promotions SET title=?,scope=?,category_id=?,product_ids=?,discount=?,`start`=?,`end`=?,active=? WHERE id=?', [...$values, $promotionId]);
                    else execute($db, 'INSERT INTO promotions(id,title,scope,category_id,product_ids,discount,`start`,`end`,active) VALUES(?,?,?,?,?,?,?,?,?)', [$promotionId, ...$values]);
                    audit($db, $user, 'promotion.save', $promotionId);
                    return [$old ? 200 : 201, ['promotion' => promotionView(queryOne($db, 'SELECT * FROM promotions WHERE id=?', [$promotionId]))]];
                }
            }

            if ($type === 'users') {
                if ($method === 'GET') return [200, ['users' => array_map('userView', queryAll($db, 'SELECT * FROM users ORDER BY created_at'))]];
                if ($method === 'POST' && $id === null) {
                    $created = createUser($db, $body, text($body['role'] ?? 'cliente', 'Rol', 30, 1));
                    audit($db, $user, 'user.create', $created['id'], ['role' => $created['role']]);
                    return [201, ['user' => userView($created)]];
                }
                if ($method === 'PATCH' && $id !== null) {
                    $updated = transact($db, static function () use ($db, $id, $user, $body): array {
                        $old = queryOne($db, 'SELECT * FROM users WHERE id=? FOR UPDATE', [$id]);
                        if (!$old) fail(404, 'Usuario no encontrado.');
                        $role = $body['role'] ?? $old['role'];
                        $active = booleanValue($body['active'] ?? null, (bool)$old['active']);
                        if (!in_array($role, ['cliente', 'admin', 'logistica', 'soporte', 'tecnico'], true)) fail(400, 'Rol inválido.');
                        if ($id === $user['id'] && ($role !== $user['role'] || !$active)) fail(409, 'No puedes desactivar tu cuenta ni cambiar tu propio rol.');
                        if ($old['role'] === 'admin' && $old['active'] && ($role !== 'admin' || !$active)
                            && (int)(queryOne($db, "SELECT COUNT(*) n FROM users WHERE role='admin' AND active=1")['n'] ?? 0) <= 1) {
                            fail(409, 'Debe permanecer un administrador activo.');
                        }
                        execute($db, 'UPDATE users SET role=?,active=? WHERE id=?', [$role, $active ? 1 : 0, $id]);
                        if (!$active || $role !== $old['role']) execute($db, 'DELETE FROM sessions WHERE user_id=?', [$id]);
                        audit($db, $user, 'user.update', $id, ['role' => $role, 'active' => $active]);
                        return userView(queryOne($db, 'SELECT * FROM users WHERE id=?', [$id]));
                    });
                    return [200, ['user' => $updated]];
                }
            }

            if ($type === 'market-references') {
                if ($method === 'GET') return [200, ['references' => references($db)]];
                $old = $id !== null ? queryOne($db, 'SELECT * FROM market_references WHERE id=?', [$id]) : null;
                if ($id !== null && !$old) fail(404, 'Referencia no encontrada.');
                if ($method === 'DELETE' && $id !== null) {
                    execute($db, 'UPDATE market_references SET active=0 WHERE id=?', [$id]);
                    audit($db, $user, 'reference.archive', $id);
                    return [200, ['ok' => true]];
                }
                if (($method === 'POST' && $id === null) || ($method === 'PATCH' && $id !== null)) {
                    $modelKey = text($body['modelKey'] ?? $old['model_key'] ?? null, 'Clave del modelo', 100, 2);
                    $sourceUrl = text($body['sourceUrl'] ?? $old['source_url'] ?? null, 'URL de la fuente', 1000, 8);
                    $source = parse_url($sourceUrl);
                    if ($source === false || ($source['scheme'] ?? '') !== 'https' || empty($source['host']) || !filter_var($sourceUrl, FILTER_VALIDATE_URL)) fail(400, 'La fuente debe ser una URL HTTPS válida.');
                    $checked = $body['checkedAt'] ?? $old['checked_at'] ?? now();
                    $checkedDate = commerceDate($checked);
                    $maxExpiry = $checkedDate ? $checkedDate->modify('+30 days') : null;
                    $expires = $body['expiresAt'] ?? $old['expires_at'] ?? ($maxExpiry ? $maxExpiry->format('Y-m-d\TH:i:s.v\Z') : '');
                    $expiresDate = commerceDate($expires);
                    if (!$checkedDate || !$expiresDate || $checkedDate->getTimestamp() > time() + 300 || $expiresDate > $maxExpiry || $expiresDate <= $checkedDate) {
                        fail(400, 'Verificación y vencimiento deben ser fechas válidas con máximo 30 días de vigencia.');
                    }
                    $checked = $checkedDate->format('Y-m-d\TH:i:s.v\Z');
                    $expires = $expiresDate->format('Y-m-d\TH:i:s.v\Z');
                    $available = array_key_exists('available', $body) ? booleanValue($body['available'])
                        : (array_key_exists('availabilityConfirmed', $body) ? booleanValue($body['availabilityConfirmed']) : (bool)($old['availability_confirmed'] ?? false));
                    $availability = $body['availability'] ?? (array_key_exists('available', $body)
                        ? ($available ? 'listed_available' : 'unknown') : ($old['availability'] ?? 'unknown'));
                    $referenceId = $id ?? uid('ref');
                    $values = [
                        $modelKey, text($body['model'] ?? $old['model'] ?? null, 'Modelo', 180, 2),
                        text($body['storage'] ?? $old['storage'] ?? '', 'Almacenamiento', 100),
                        text($body['category'] ?? $old['category'] ?? 'telefonos', 'Categoría', 100, 1),
                        integer($body['amountCents'] ?? $old['amount_cents'] ?? null, 'Precio de referencia', 1), $sourceUrl,
                        text($body['sourceTitle'] ?? $old['source_title'] ?? '', 'Título de fuente', 250),
                        text($body['condition'] ?? $old['condition'] ?? 'Reacondicionado', 'Condición comparable', 100, 2),
                        text($body['seller'] ?? $old['seller'] ?? null, 'Vendedor', 180, 2),
                        text($availability, 'Disponibilidad', 80, 2), $available ? 1 : 0, $checked, $expires,
                        text($body['notes'] ?? $old['notes'] ?? '', 'Notas', 2000),
                    ];
                    if ($old) execute($db, 'UPDATE market_references SET model_key=?,model=?,storage=?,category=?,amount_cents=?,source_url=?,source_title=?,`condition`=?,seller=?,availability=?,availability_confirmed=?,checked_at=?,expires_at=?,notes=? WHERE id=?', [...$values, $referenceId]);
                    else execute($db, 'INSERT INTO market_references(id,model_key,model,storage,category,amount_cents,source_url,source_title,`condition`,seller,availability,availability_confirmed,checked_at,expires_at,notes,active) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)', [$referenceId, ...$values]);
                    audit($db, $user, 'reference.save', $referenceId);
                    $reference = array_values(array_filter(references($db), static fn(array $r): bool => $r['id'] === $referenceId))[0] ?? null;
                    return [$old ? 200 : 201, ['reference' => $reference]];
                }
            }
        }

        if (preg_match('~^/api/admin/orders/([^/]+)/payments$~D', $path, $match) && $method === 'POST') {
            $amount = integer($body['amountCents'] ?? null, 'Pago', 1);
            $reference = text($body['reference'] ?? null, 'Referencia', 160, 3);
            $order = transact($db, static function () use ($db, $match, $user, $body, $amount, $reference): array {
                $order = queryOne($db, 'SELECT * FROM orders WHERE id=? FOR UPDATE', [$match[1]]);
                if (!$order) fail(404, 'Pedido no encontrado.');
                if ($order['status'] !== 'Pendiente de pago') fail(409, 'El pedido ya no admite registro de pago.');
                $due = orderView($db, $order)['amountDueCents'];
                if ($amount > $due) fail(409, 'El pago excede el importe pendiente.');
                execute($db, 'INSERT INTO payments(id,order_id,actor_id,amount_cents,reference,note,created_at) VALUES(?,?,?,?,?,?,?)', [
                    uid('pay'), $order['id'], $user['id'], $amount, $reference, text($body['note'] ?? '', 'Nota', 1000), now(),
                ]);
                if ($amount === $due) {
                    execute($db, "UPDATE orders SET status='Pagado',updated_at=? WHERE id=?", [now(), $order['id']]);
                    execute($db, 'INSERT INTO order_events(id,order_id,actor_id,status,location,created_at) VALUES(?,?,?,?,?,?)', [uid('event'), $order['id'], $user['id'], 'Pagado', 'Pago manual registrado por administración', now()]);
                }
                audit($db, $user, 'payment.manual', $order['id'], ['amountCents' => $amount, 'reference' => $reference]);
                return orderView($db, queryOne($db, 'SELECT * FROM orders WHERE id=?', [$order['id']]));
            });
            return [201, ['order' => $order]];
        }
    }
    return null;
}
