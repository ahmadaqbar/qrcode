<?php
require_once __DIR__ . '/includes/functions.php';

$number = isset($_GET['table']) ? trim((string) $_GET['table']) : '';
try {
    $table = find_active_table($number);
} catch (Throwable $ex) {
    error_log($ex->getMessage());
    fail_page('Layanan sedang tidak tersedia', 503);
}
if (!$table) {
    fail_page('Meja tidak ditemukan', 404);
}

$page_title = 'Konfirmasi Pesanan';
$base = '';
$page = 'checkout';
$body_attrs = ['table' => $table['table_number']];
include __DIR__ . '/includes/header.php';
?>
<header class="cust-header sticky-top">
  <div class="container d-flex align-items-center">
    <a href="cart.php?table=<?= e($table['table_number']) ?>" class="btn btn-sm btn-light me-2">&larr;</a>
    <div class="fw-semibold">Konfirmasi Pesanan</div>
  </div>
</header>

<main class="container py-3 has-cartbar">
  <div class="card mb-3"><div class="card-body">
    <div class="small text-muted">Meja</div>
    <div class="fw-semibold fs-5"><?= e($table['table_number']) ?></div>
    <div id="row-name" class="d-none mt-2"><div class="small text-muted">Nama</div><div id="c-name"></div></div>
  </div></div>

  <div class="card mb-3"><div class="card-body">
    <div class="small text-muted mb-1">Pesanan</div>
    <div id="c-items"></div>
    <div id="row-note" class="d-none mt-3"><div class="small text-muted">Catatan</div><div id="c-note" class="text-break"></div></div>
    <hr>
    <div class="d-flex justify-content-between fw-bold fs-5"><span>Total</span><span id="c-total">Rp0</span></div>
  </div></div>

  <div id="c-error" class="alert alert-danger d-none"></div>
</main>

<div class="cart-bar static-bar">
  <button class="btn btn-light btn-lg w-100 fw-bold" id="btn-order">PESAN SEKARANG</button>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
