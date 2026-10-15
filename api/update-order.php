<?php
/**
 * POST (staff, CSRF): order_id, status  -> ubah status sesuai alur.
 * NEW -> PROCESSING -> COMPLETED, atau NEW -> CANCELLED.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/orders.php';
require_permission('orders.manage', true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Metode tidak diizinkan.'], 405);
}
if (!csrf_valid()) {
    json_response(['ok' => false, 'error' => 'Token keamanan tidak valid. Muat ulang halaman.'], 403);
}

$id = filter_var(isset($_POST['order_id']) ? $_POST['order_id'] : null, FILTER_VALIDATE_INT);
$new = isset($_POST['status']) ? strtoupper((string) $_POST['status']) : '';
if ($id === false || $id < 1 || !in_array($new, ORDER_STATUSES, true)) {
    json_response(['ok' => false, 'error' => 'Data tidak valid.'], 422);
}

try {
    $pdo = db();
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT status, payment_status, total FROM orders WHERE id = ? FOR UPDATE');
    $st->execute([$id]);
    $row = $st->fetch();
    $cur = $row ? $row['status'] : false;
    if ($cur === false) {
        $pdo->rollBack();
        json_response(['ok' => false, 'error' => 'Pesanan tidak ditemukan.'], 404);
    }
    if (!in_array($new, STATUS_TRANSITIONS[$cur], true)) {
        $pdo->rollBack();
        json_response(['ok' => false, 'error' => 'Status sudah berubah (' . status_label($cur) . '). Muat ulang halaman.'], 409);
    }
    // Status dapur / penyajian bukan status pembayaran: pesanan hanya ditutup bila sudah lunas.
    if ($new === 'COMPLETED' && $row['payment_status'] !== 'PAID' && (int) $row['total'] > 0) {
        $pdo->rollBack();
        json_response(['ok' => false, 'code' => 'UNPAID', 'error' => 'Pesanan belum dibayar. Selesaikan pembayaran di POS Kasir.'], 409);
    }
    $uid = current_user()['id'];
    if ($new === 'PROCESSING') {
        // Pesanan diterima -> item masuk ke layar dapur/bar
        send_to_kitchen($pdo, $id);
    } elseif ($new === 'CANCELLED') {
        $pdo->prepare("UPDATE order_items SET kitchen_status = 'CANCELLED' WHERE order_id = ?")->execute([$id]);
        $pdo->prepare('UPDATE orders SET cancel_reason = ?, cancelled_by = ?, cancelled_at = NOW(), table_released_at = NOW() WHERE id = ?')
            ->execute(['Ditolak staf sebelum diproses', $uid, $id]);
        $pdo->prepare("UPDATE delivery_details SET delivery_status = 'CANCELLED' WHERE order_id = ?")->execute([$id]);
    } elseif ($new === 'COMPLETED') {
        $pdo->prepare('UPDATE orders SET closed_at = NOW(), closed_by = ? WHERE id = ?')->execute([$uid, $id]);
    }
    $pdo->prepare('UPDATE orders SET status = ?, version = version + 1 WHERE id = ?')->execute([$new, $id]);
    $pdo->commit();
    audit('order.status', 'order', $id, ['from' => $cur, 'to' => $new]);
    json_response(['ok' => true, 'status' => $new, 'status_label' => status_label($new)]);
} catch (Throwable $ex) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('update-order: ' . $ex->getMessage());
    json_response(['ok' => false, 'error' => 'Gagal memperbarui status.'], 500);
}
