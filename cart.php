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

$page_title = 'Daftar Pesanan';
$base = '';
$page = 'cart';
$body_attrs = ['table' => $table['table_number']];
include __DIR__ . '/includes/header.php';
?>
<header class="cust-header sticky-top">
  <div class="container d-flex align-items-center">
    <a href="order.php?table=<?= e($table['table_number']) ?>" class="btn btn-sm btn-light me-2" id="back-link">&larr;</a>
    <div>
      <div class="fw-semibold" id="step-title">Daftar Pesanan</div>
      <div class="small text-muted">Meja <?= e($table['table_number']) ?></div>
    </div>
  </div>
</header>

<main class="container py-3 has-cartbar">
  <!-- Langkah 1: daftar pesanan -->
  <section id="step-cart">
    <div id="cart-empty" class="text-center text-muted py-5 d-none">
      Pesanan masih kosong.<br>
      <a href="order.php?table=<?= e($table['table_number']) ?>" class="btn btn-dark mt-3">Lihat Menu</a>
    </div>
    <div id="cart-list" class="list-group mb-3"></div>
  </section>

  <!-- Langkah 2: catatan -->
  <section id="step-note" class="d-none">
    <label class="form-label fw-semibold" for="note">Catatan untuk Pelayan</label>
    <textarea id="note" class="form-control mb-3" rows="3" maxlength="500"
              placeholder="Contoh: Tidak pedas, sedikit gula, tanpa es"></textarea>
    <label class="form-label fw-semibold" for="cust-name">Nama Customer <span class="text-muted fw-normal">(Opsional)</span></label>
    <input id="cust-name" class="form-control" maxlength="100" autocomplete="name">
  </section>
</main>

<div class="cart-bar static-bar d-none" id="cart-footer">
  <span>Total <span class="fw-semibold" id="cart-total">Rp0</span></span>
  <button class="btn btn-light fw-semibold" id="btn-next">Lanjutkan</button>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
