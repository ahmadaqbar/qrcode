<?php
require_once __DIR__ . '/includes/functions.php';
$page_title = 'Selamat Datang';
$base = '';
include __DIR__ . '/includes/header.php';
?>
<div class="container py-5 text-center paper-panel panel-lone" style="max-width:480px">
  <h1 class="h3 mb-2"><?= e(APP_NAME) ?></h1>
  <div class="ornament-rule" role="presentation"></div>
  <p class="text-muted">Silakan scan QR Code yang tersedia di meja Anda untuk mulai memesan.</p>
  <a href="admin/login.php" class="btn btn-outline-secondary btn-sm mt-4">Login Staff</a>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
