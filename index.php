<?php
require_once __DIR__ . '/includes/functions.php';
$page_title = 'Selamat Datang';
$base = '';
include __DIR__ . '/includes/header.php';
?>
<main class="q-wrap q-empty"><div class="q-empty-card">
  <?= q_mark_svg() ?>
  <h1 class="q-title"><?= e(APP_NAME) ?></h1>
  <p class="q-muted">Silakan scan QR Code yang tersedia di meja Anda untuk mulai memesan.</p>
  <a href="admin/login.php" class="btn btn-outline-dark btn-sm mt-3">Login Staff</a>
</div></main>
<?php include __DIR__ . '/includes/footer.php'; ?>
