<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

try {
    $tables = db()->query('SELECT table_number FROM tables WHERE is_active = 1 ORDER BY table_number')->fetchAll();
} catch (Throwable $ex) {
    error_log($ex->getMessage());
    $tables = [];
}
$root = app_base_url();

$page_title = 'QR Meja';
$base = '../';
$admin = true;
$page = 'qr';
$extra_scripts = ['assets/vendor/qrcode.js'];
include __DIR__ . '/../includes/header.php';
?>
<div class="container py-3">
  <h1 class="h4 mb-1">QR Code Meja</h1>
  <p class="text-muted small">URL dasar: <code><?= e($root) ?></code> (pastikan alamat ini bisa dibuka dari HP customer).</p>
  <div class="row g-3">
    <?php foreach ($tables as $t): $url = $root . '/order.php?table=' . rawurlencode($t['table_number']); ?>
      <div class="col-6 col-md-4 col-lg-3">
        <div class="card text-center h-100"><div class="card-body">
          <div class="fw-bold mb-2">Meja <?= e($t['table_number']) ?></div>
          <div class="qr-holder mx-auto mb-2" data-url="<?= e($url) ?>" data-table="<?= e($t['table_number']) ?>"></div>
          <div class="small text-muted text-break mb-2"><?= e($url) ?></div>
          <button class="btn btn-sm btn-dark" data-download>Download QR</button>
        </div></div>
      </div>
    <?php endforeach; ?>
    <?php if (!$tables): ?><p class="text-muted">Belum ada meja aktif.</p><?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
