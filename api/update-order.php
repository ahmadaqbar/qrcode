<?php
/**
 * POST (staff, CSRF): order_id, status  -> ubah status sesuai alur.
 * NEW -> PROCESSING -> COMPLETED, atau NEW -> CANCELLED.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login(true);

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
    $st = $pdo->prepare('SELECT status FROM orders WHERE id = ? FOR UPDATE');
    $st->execute([$id]);
    $cur = $st->fetchColumn();
    if ($cur === false) {
        $pdo->rollBack();
        json_response(['ok' => false, 'error' => 'Pesanan tidak ditemukan.'], 404);
    }
    if (!in_array($new, STATUS_TRANSITIONS[$cur], true)) {
        $pdo->rollBack();
        json_response(['ok' => false, 'error' => 'Status sudah berubah (' . status_label($cur) . '). Muat ulang halaman.'], 409);
    }
    $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$new, $id]);
    $pdo->commit();
    json_response(['ok' => true, 'status' => $new, 'status_label' => status_label($new)]);
} catch (Throwable $ex) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('update-order: ' . $ex->getMessage());
    json_response(['ok' => false, 'error' => 'Gagal memperbarui status.'], 500);
}
