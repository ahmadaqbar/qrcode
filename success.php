<?php
require_once __DIR__ . '/includes/functions.php';

// Halaman diakses lewat token order (bukan nomor urut) agar order orang lain tidak bisa ditebak.
$token = isset($_GET['t']) ? (string) $_GET['t'] : '';
$order = null;
if (preg_match('/^[a-f0-9]{32}$/', $token)) {
    try {
        $st = db()->prepare('SELECT o.order_number, o.status, t.table_number
                             FROM orders o JOIN tables t ON t.id = o.table_id WHERE o.order_token = ?');
        $st->execute([$token]);
        $order = $st->fetch();
    } catch (Throwable $ex) {
        error_log($ex->getMessage());
        fail_page('Layanan sedang tidak tersedia', 503);
    }
}
if (!$order) {
    fail_page('Pesanan tidak ditemukan', 404);
}

$page_title = 'Pesanan Berhasil';
$base = '';
$page = 'success';
$body_attrs = ['table' => $order['table_number'], 'token' => $token, 'status' => $order['status']];
include __DIR__ . '/includes/header.php';
?>
<main class="container py-5 text-center" style="max-width:480px">
  <div class="success-icon mb-3">&#10003;</div>
  <h1 class="h3 fw-bold">PESANAN BERHASIL</h1>
  <p class="text-muted">Pesanan Anda telah diterima.</p>
  <div class="card text-start mt-4"><div class="card-body">
    <div class="small text-muted">Nomor Pesanan</div>
    <div class="fs-4 fw-bold mb-2"><?= e($order['order_number']) ?></div>
    <div class="small text-muted">Meja</div>
    <div class="fw-semibold mb-2"><?= e($order['table_number']) ?></div>
    <div class="small text-muted">Status</div>
    <div class="fw-semibold" id="order-status"><?= e(status_label($order['status'])) ?></div>
  </div></div>
  <p class="mt-4" id="wait-msg">Mohon menunggu.</p>
  <a href="order.php?table=<?= e($order['table_number']) ?>" class="btn btn-outline-dark mt-2">Pesan Lagi</a>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
