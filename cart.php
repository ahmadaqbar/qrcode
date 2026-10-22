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

// Foto kecil untuk baris pesanan (tampilan saja); kegagalan di sini tidak boleh merusak halaman.
$cartImages = [];
try {
    foreach (db()->query('SELECT id, image FROM products')->fetchAll() as $r) {
        $cartImages[(int) $r['id']] = (string) ($r['image'] ?: 'placeholder.svg');
    }
} catch (Throwable $ex) {
    error_log('cart images: ' . $ex->getMessage());
}

$page_title = 'Daftar Pesanan';
$base = '';
$page = 'cart';
$body_attrs = ['table' => $table['table_number']];
include __DIR__ . '/includes/header.php';
?>
<header class="q-header">
  <div class="q-wrap q-bar">
    <a href="order.php?table=<?= e($table['table_number']) ?>" class="q-back" id="back-link" aria-label="Kembali"><i class="bi bi-arrow-left" aria-hidden="true"></i></a>
    <div class="q-name" id="step-title">Daftar Pesanan</div>
    <span class="q-table">Meja <?= e($table['table_number']) ?></span>
  </div>
</header>

<main class="q-wrap q-main q-narrow has-cartbar">
  <!-- Langkah 1: daftar pesanan -->
  <section id="step-cart">
    <div id="cart-empty" class="q-empty-inline d-none">
      <?= q_mark_svg() ?>
      <p class="q-muted">Pesanan masih kosong.</p>
      <a href="order.php?table=<?= e($table['table_number']) ?>" class="btn btn-dark">Lihat Menu</a>
    </div>
    <div id="cart-list" class="q-rows"></div>
  </section>

  <!-- Langkah 2: catatan -->
  <section id="step-note" class="d-none">
    <div class="q-card">
      <label class="q-label" for="note">Catatan untuk dapur</label>
      <textarea id="note" class="form-control" rows="5" maxlength="500"
                placeholder="Contoh: Tidak pedas, sedikit gula, tanpa es"></textarea>
      <label class="q-label mt-3" for="cust-name">Nama Customer <span class="q-muted fw-normal">(opsional)</span></label>
      <input id="cust-name" class="form-control" maxlength="100" autocomplete="name">
    </div>
  </section>

  <div id="cart-summary" class="q-card q-summary d-none">
    <div class="q-sum-row"><span>Subtotal</span><strong id="cart-total">Rp 0</strong></div>
    <?php if (($note = price_note_text()) !== ''): ?><p class="q-sum-note"><?= e($note) ?></p><?php endif; ?>
  </div>
  <script type="application/json" id="cart-images"><?= json_encode($cartImages, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</main>

<div class="q-actionbar d-none" id="cart-footer">
  <div class="q-wrap"><button class="btn btn-accent w-100" id="btn-next">Lanjutkan</button></div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
