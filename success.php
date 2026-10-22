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
<main class="q-wrap q-main q-narrow q-success">
  <?= q_mark_svg(true) ?>
  <h1 class="q-title">Pesanan Berhasil</h1>
  <p class="q-muted">Pesanan Anda telah diterima.</p>

  <ol class="q-steps" id="order-steps" data-status="<?= e($order['status']) ?>" aria-label="Status pesanan">
    <li data-step="1"><span class="q-dot"></span><span class="q-step-label">Diterima</span></li>
    <li data-step="2"><span class="q-dot"></span><span class="q-step-label">Diproses</span></li>
    <li data-step="3"><span class="q-dot"></span><span class="q-step-label">Selesai</span></li>
  </ol>
  <p class="q-wait" id="wait-msg">Mohon menunggu.</p>

  <div class="q-card q-order-card text-start">
    <div class="q-label-sm">Nomor Pesanan</div>
    <div class="q-order-no"><?= e($order['order_number']) ?></div>
    <div class="q-order-meta">
      <div><div class="q-label-sm">Meja</div><div class="fw-semibold"><?= e($order['table_number']) ?></div></div>
      <div><div class="q-label-sm">Status</div><span class="status-badge" id="order-status" data-status="<?= e($order['status']) ?>"><?= e(status_label($order['status'])) ?></span></div>
    </div>
  </div>
  <a href="order.php?table=<?= e($order['table_number']) ?>" class="btn btn-outline-dark">Pesan Lagi</a>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
