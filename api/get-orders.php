<?php
/**
 * GET (staff): ?status=active|ALL|NEW|PROCESSING|COMPLETED|CANCELLED
 * Return: {ok, max_id, orders:[...]}. Dipakai polling dashboard.
 * `max_id` = id order terbesar di seluruh tabel (untuk deteksi order baru).
 */
require_once __DIR__ . '/../includes/auth.php';
require_login(true);

$status = isset($_GET['status']) ? strtoupper((string) $_GET['status']) : 'ACTIVE';

try {
    $where = '';
    $params = [];
    if ($status === 'ACTIVE') {
        $where = "WHERE o.status IN ('NEW','PROCESSING')";
    } elseif (in_array($status, ORDER_STATUSES, true)) {
        $where = 'WHERE o.status = ?';
        $params[] = $status;
    } elseif ($status !== 'ALL') {
        json_response(['ok' => false, 'error' => 'Filter tidak valid.'], 400);
    }

    $st = db()->prepare("SELECT o.*, t.table_number FROM orders o JOIN tables t ON t.id = o.table_id
                         $where ORDER BY o.id DESC LIMIT 200");
    $st->execute($params);
    $rows = $st->fetchAll();
    $items = fetch_items_for_orders(array_column($rows, 'id'));

    $orders = [];
    foreach ($rows as $r) {
        $orders[] = format_order($r, isset($items[$r['id']]) ? $items[$r['id']] : []);
    }
    $max = (int) db()->query('SELECT COALESCE(MAX(id),0) FROM orders')->fetchColumn();

    json_response(['ok' => true, 'max_id' => $max, 'orders' => $orders]);
} catch (Throwable $ex) {
    error_log('get-orders: ' . $ex->getMessage());
    json_response(['ok' => false, 'error' => 'Gagal memuat pesanan.'], 500);
}
