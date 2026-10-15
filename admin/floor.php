<?php
require_once __DIR__ . '/../includes/auth.php';
require_permission('pos.use');

$page_title = 'Meja (Denah)';
$base = '../';
$admin = true;
$page = 'floor';
$body_attrs = ['csrf' => csrf_token()];
$extra_scripts = ['assets/js/floor.js'];
include __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid py-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
    <h1 class="h4 m-0"><i class="bi bi-grid-1x2"></i> Meja</h1>
    <div class="small" id="floor-legend"></div>
  </div>
  <div class="row g-2 mb-3" id="floor-stats"></div>
  <div class="table-grid floor-grid" id="floor-grid" aria-live="polite"></div>
</div>

<div class="offcanvas offcanvas-end" tabindex="-1" id="tablePanel" aria-labelledby="tp-title">
  <div class="offcanvas-header"><h2 class="offcanvas-title h5" id="tp-title">Meja</h2><button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Tutup"></button></div>
  <div class="offcanvas-body" id="tp-body"></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
