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
<header class="q-header">
  <div class="q-wrap q-bar">
    <a href="cart.php?table=<?= e($table['table_number']) ?>" class="q-back" aria-label="Kembali"><i class="bi bi-arrow-left" aria-hidden="true"></i></a>
    <div class="q-name">Konfirmasi Pesanan</div>
    <span class="q-table">Meja <?= e($table['table_number']) ?></span>
  </div>
</header>

<main class="q-wrap q-main q-narrow has-cartbar">
  <div class="q-card q-table-card">
    <div class="q-table-big" aria-hidden="true"><?= e($table['table_number']) ?></div>
    <div>
      <div class="q-label-sm">Meja</div>
      <div class="q-table-name">Meja <?= e($table['table_number']) ?></div>
      <div id="row-name" class="d-none"><div class="q-label-sm mt-2">Nama</div><div id="c-name"></div></div>
    </div>
  </div>

  <div class="q-card">
    <div class="q-label-sm mb-2">Pesanan</div>
    <div id="c-items" class="q-lines"></div>
    <div id="row-note" class="d-none q-note-box"><div class="q-label-sm">Catatan untuk dapur</div><div id="c-note" class="text-break"></div></div>
    <div class="q-sum-row q-sum-total"><span>Total</span><strong id="c-total">Rp 0</strong></div>
    <?php if (($note = price_note_text()) !== ''): ?><p class="q-sum-note"><?= e($note) ?></p><?php endif; ?>
  </div>

  <div id="c-error" class="alert alert-danger d-none"></div>
</main>

<div class="q-actionbar">
  <div class="q-wrap q-actions">
    <a href="cart.php?table=<?= e($table['table_number']) ?>" class="btn btn-outline-dark">Kembali</a>
    <button class="btn btn-accent flex-grow-1" id="btn-order">Kirim Pesanan</button>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
