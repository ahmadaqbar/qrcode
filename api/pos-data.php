<?php
/** GET (POS): katalog, meja + status, tarif berlaku, izin user. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/orders.php';
require_permission('pos.use', true);

try {
    $pdo = db();
    $cats = $pdo->query('SELECT id, name, icon, station FROM categories WHERE is_active = 1 ORDER BY id')->fetchAll();
    $products = [];
    foreach ($pdo->query('SELECT p.id, p.category_id, p.sku, p.name, p.price, p.image, p.is_available
                          FROM products p JOIN categories c ON c.id = p.category_id AND c.is_active = 1
                          WHERE p.is_active = 1 ORDER BY p.name') as $p) {
        $products[] = ['id' => (int) $p['id'], 'category_id' => (int) $p['category_id'], 'sku' => (string) $p['sku'], 'name' => $p['name'],
                       'price' => (int) $p['price'], 'image' => $p['image'] ?: 'placeholder.svg', 'available' => (bool) $p['is_available']];
    }
    foreach ($cats as &$c) {
        $c['id'] = (int) $c['id'];
        $c['icon'] = category_icon($c['icon']);
    }
    unset($c);
    $rates = [];
    foreach (array_keys(ORDER_TYPES) as $t) {
        $rates[$t] = pricing_snapshot($t);
    }
    $shift = null;
    if (table_exists($pdo, 'cash_shifts')) {
        $s = $pdo->prepare("SELECT id, opened_at FROM cash_shifts WHERE user_id = ? AND status = 'OPEN'");
        $s->execute([current_user()['id']]);
        $shift = $s->fetch() ?: null;
    }
    $methods = table_exists($pdo, 'payment_methods')
        ? $pdo->query('SELECT code, name, type, requires_reference FROM payment_methods WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll() : [];
    json_response([
        'ok' => true, 'categories' => $cats, 'products' => $products, 'tables' => table_overview($pdo),
        'rates' => $rates, 'delivery_fee_default' => (int) setting('delivery_fee_default', '0'),
        'permissions' => user_permissions(), 'shift' => $shift, 'payment_methods' => $methods,
        'table_labels' => TABLE_STATUS_LABELS,
    ]);
} catch (Throwable $ex) {
    error_log('pos-data: ' . $ex->getMessage());
    json_response(['ok' => false, 'error' => 'Gagal memuat data POS.'], 500);
}
