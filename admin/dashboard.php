<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$page_title = 'Dashboard';
$base = '../';
$admin = true;
$page = 'dashboard';
$body_attrs = ['csrf' => csrf_token()];
include __DIR__ . '/../includes/header.php';
?>
<!-- Ajakan aktifkan notifikasi (butuh klik user agar audio boleh diputar) -->
<div id="notif-banner" class="alert alert-warning d-none rounded-0 mb-0 text-center">
  <div class="fw-semibold mb-2">Aktifkan Notifikasi Pesanan</div>
  <button class="btn btn-dark" id="btn-enable-notif">AKTIFKAN</button>
</div>

<div class="container-fluid py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 m-0">Pesanan Baru</h1>
    <div class="d-flex align-items-center gap-2">
      <span id="notif-state" class="badge text-bg-secondary">Notifikasi: mati</span>
      <button class="btn btn-sm btn-outline-secondary d-none" id="btn-test-sound">Tes suara</button>
      <span id="conn-state" class="small text-muted"></span>
    </div>
  </div>
  <div id="list-new" class="row g-3"></div>
  <div id="empty-new" class="text-muted py-4 d-none">Belum ada pesanan baru.</div>

  <h2 class="h5 mt-4 mb-3">Sedang Diproses</h2>
  <div id="list-proc" class="row g-3"></div>
  <div id="empty-proc" class="text-muted py-2 d-none">Tidak ada pesanan yang diproses.</div>
</div>

<!-- Popup pesanan baru -->
<div class="toast-container position-fixed top-0 end-0 p-3" id="toasts"></div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
