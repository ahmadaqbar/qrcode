<?php
/**
 * GET (POS)
 *   ?id=N           -> pesanan lengkap (untuk dibuka/resume di POS)
 *   (tanpa id)      -> antrean: pesanan QR baru, transaksi ditahan, tagihan aktif belum lunas
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/orders.php';
require_permission('pos.use', true);

try {
    $pdo = db();
    if (isset($_GET['id'])) {
        $id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
        $o = $id ? order_full($pdo, $id) : null;
        if (!$o) {
            json_response(['ok' => false, 'error' => 'Pesanan tidak ditemukan.'], 404);
        }
        json_response(['ok' => true, 'order' => $o]);
    }
    $rows = $pdo->query("SELECT o.id, o.order_number, o.status, o.source, o.order_type, o.payment_status, o.total, o.paid_amount,
                                o.customer_name, o.created_at, t.table_number,
                                (SELECT COALESCE(SUM(i.quantity), 0) FROM order_items i WHERE i.order_id = o.id AND i.kitchen_status <> 'CANCELLED') AS qty
                         FROM orders o LEFT JOIN tables t ON t.id = o.table_id
                         WHERE o.status IN ('DRAFT','NEW','PROCESSING')
                         ORDER BY FIELD(o.status, 'NEW', 'DRAFT', 'PROCESSING'), o.id DESC LIMIT 300")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int) $r['id'], 'number' => $r['order_number'], 'status' => $r['status'], 'status_label' => status_label($r['status']),
            'source' => $r['source'], 'order_type' => $r['order_type'], 'place_label' => place_label($r),
            'payment_status' => $r['payment_status'], 'payment_label' => PAYMENT_STATUS_LABELS[$r['payment_status']],
            'total' => (int) $r['total'], 'remaining' => (int) $r['total'] - (int) $r['paid_amount'], 'qty' => (int) $r['qty'],
            'customer_name' => $r['customer_name'], 'time' => date('H:i', strtotime($r['created_at'])),
        ];
    }
    json_response(['ok' => true, 'orders' => $out, 'max_id' => (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM orders')->fetchColumn()]);
} catch (Throwable $ex) {
    error_log('pos-orders: ' . $ex->getMessage());
    json_response(['ok' => false, 'error' => 'Gagal memuat pesanan.'], 500);
}
