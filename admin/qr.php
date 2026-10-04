<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    if (!csrf_valid()) {
        flash_set('err', 'Sesi tidak valid, coba lagi.');
    } elseif ($action === 'reset') {
        try {
            db()->prepare('DELETE FROM settings WHERE setting_key = ?')->execute(['site_url']);
            flash_set('ok', 'Base URL dikembalikan ke alamat otomatis. QR dibuat ulang.');
        } catch (Throwable $ex) {
            error_log($ex->getMessage());
            flash_set('err', 'Terjadi kesalahan sistem.');
        }
    } else {
        $url = normalize_site_url(isset($_POST['site_url']) ? (string) $_POST['site_url'] : '');
        if ($url === null) {
            flash_set('err', 'Base URL tidak valid. Contoh: http://192.168.1.100/qrcode-scenic atau https://restaurant.example.com (hanya http/https, tanpa spasi, tanda kutip, <, >, ? atau #).');
        } else {
            try {
                set_setting('site_url', $url);
                flash_set('ok', 'Base URL disimpan: ' . $url . ' - QR dibuat ulang.');
            } catch (Throwable $ex) {
                error_log($ex->getMessage());
                flash_set('err', 'Terjadi kesalahan sistem.');
            }
        }
    }
    redirect('qr.php');
}

try {
    $tables = db()->query('SELECT table_number FROM tables WHERE is_active = 1 ORDER BY table_number')->fetchAll();
} catch (Throwable $ex) {
    error_log($ex->getMessage());
    $tables = [];
}
$root = site_url();
$detected = app_base_url();

$page_title = 'QR Meja';
$base = '../';
$admin = true;
$page = 'qr';
$extra_scripts = ['assets/vendor/qrcode.js'];
include __DIR__ . '/../includes/header.php';
?>
<div class="container py-3">
  <h1 class="h4 mb-3">QR Code Management</h1>
  <div class="card mb-4"><div class="card-body">
    <form method="post" class="row g-2 align-items-end">
      <?= csrf_field() ?><input type="hidden" name="action" value="save">
      <div class="col-12 col-lg">
        <label class="form-label fw-semibold" for="site_url">Base URL</label>
        <input class="form-control" id="site_url" name="site_url" type="url" maxlength="200" required value="<?= e($root) ?>"
               placeholder="http://192.168.1.100/qrcode-scenic">
        <div class="form-text">Alamat yang dibuka HP customer (IP lokal atau domain). Garis miring di akhir dihapus otomatis.
          Terdeteksi otomatis: <code><?= e($detected) ?></code></div>
      </div>
      <div class="col-12 col-lg-auto d-flex gap-2">
        <button class="btn btn-dark">UPDATE BASE URL &amp; REGENERATE QR</button>
      </div>
    </form>
    <form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="action" value="reset">
      <button class="btn btn-link btn-sm p-0">Gunakan alamat otomatis</button></form>
  </div></div>
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
