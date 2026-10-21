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
  <div class="container">
    <div class="cust-brand">
      <a href="order.php?table=<?= e($table['table_number']) ?>" class="btn btn-light btn-back" id="back-link" aria-label="Kembali">&larr;</a>
      <div class="cust-name" id="step-title">Daftar Pesanan</div>
      <span class="stamp table-stamp">Meja <?= e($table['table_number']) ?></span>
    </div>
  </div>
</header>

<main class="container py-3 has-cartbar paper-panel">
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
  <?php if (($note = price_note_text()) !== ''): ?><p class="price-note"><?= e($note) ?></p><?php endif; ?>
</main>

<div class="cart-bar static-bar d-none" id="cart-footer">
  <span>Total <span class="fw-semibold" id="cart-total">Rp0</span></span>
  <button class="btn btn-light fw-semibold" id="btn-next">Lanjutkan</button>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
