<?php
require_once __DIR__ . '/../includes/auth.php';
require_permission('kitchen.view');

$page_title = 'Kitchen Display';
$base = '../';
$admin = true;
$page = 'kitchen';
$body_attrs = ['csrf' => csrf_token(), 'can-update' => can('kitchen.update') ? '1' : '0'];
$extra_scripts = ['assets/js/kitchen.js'];
include __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid py-3 kds">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 m-0"><i class="bi bi-fire"></i> Kitchen Display</h1>
    <div class="d-flex flex-wrap align-items-center gap-2">
      <div class="btn-group" role="group" aria-label="Area produksi" id="kds-station">
        <button class="btn btn-dark" data-st="ALL">Semua</button>
        <button class="btn btn-outline-dark" data-st="KITCHEN">Kitchen</button>
        <button class="btn btn-outline-dark" data-st="BAR">Bar</button>
      </div>
      <button class="btn btn-warning" id="kds-sound"><i class="bi bi-volume-up"></i> Aktifkan suara</button>
      <span class="small text-muted" id="kds-clock"></span>
    </div>
  </div>
  <div class="kds-board">
    <section class="kds-col" aria-labelledby="kc-new"><h2 class="kds-head kds-NEW" id="kc-new">Baru <span class="badge text-bg-light" id="kn-NEW">0</span></h2><div id="kl-NEW" class="kds-list"></div></section>
    <section class="kds-col" aria-labelledby="kc-prep"><h2 class="kds-head kds-PREPARING" id="kc-prep">Disiapkan <span class="badge text-bg-light" id="kn-PREPARING">0</span></h2><div id="kl-PREPARING" class="kds-list"></div></section>
    <section class="kds-col" aria-labelledby="kc-ready"><h2 class="kds-head kds-READY" id="kc-ready">Siap Disajikan <span class="badge text-bg-light" id="kn-READY">0</span></h2><div id="kl-READY" class="kds-list"></div></section>
    <section class="kds-col" aria-labelledby="kc-done"><h2 class="kds-head kds-SERVED" id="kc-done">Selesai (30 mnt) <span class="badge text-bg-light" id="kn-SERVED">0</span></h2><div id="kl-SERVED" class="kds-list"></div></section>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
