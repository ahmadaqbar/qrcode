<?php
require_once __DIR__ . '/includes/functions.php';

$number = isset($_GET['table']) ? trim((string) $_GET['table']) : '';
try {
    $table = find_active_table($number);
    if (!$table) {
        fail_page('Meja tidak ditemukan', 404);
    }
    $categories = db()->query('SELECT id, name, icon FROM categories WHERE is_active = 1 ORDER BY id')->fetchAll();
    $products = db()->query('SELECT p.id, p.category_id, p.name, p.description, p.price, p.image, p.is_available
                             FROM products p JOIN categories c ON c.id = p.category_id AND c.is_active = 1
                             ORDER BY p.category_id, p.name')->fetchAll();
} catch (Throwable $ex) {
    error_log($ex->getMessage());
    fail_page('Layanan sedang tidak tersedia', 503);
}

// Fitur tambahan: kegagalan di sini (mis. migrasi belum dijalankan) tidak boleh merusak menu.
$flyer = null;
$topSellers = [];
$promos = [];
$detailProducts = [];   // data untuk modal detail produk (semua produk, termasuk yang tidak tersedia)
try {
    $flyer = db()->query('SELECT * FROM flyers WHERE ' . SQL_ACTIVE_PERIOD . ' ORDER BY id DESC LIMIT 1')->fetch() ?: null;
    $promos = db()->query('SELECT * FROM promos WHERE ' . SQL_ACTIVE_PERIOD . ' ORDER BY sort_order ASC, id DESC LIMIT 10')->fetchAll();
    $topSellers = db()->query('SELECT p.id, p.name, p.price, p.image FROM products p
                               JOIN categories c ON c.id = p.category_id AND c.is_active = 1
                               WHERE p.is_top_seller = 1 AND p.is_available = 1
                               ORDER BY p.top_seller_order ASC, p.id ASC LIMIT 8')->fetchAll();
    foreach (db()->query('SELECT p.id, p.name, p.description, p.price, p.image, (p.is_available = 1 AND c.is_active = 1) AS orderable
                          FROM products p JOIN categories c ON c.id = p.category_id')->fetchAll() as $dp) {
        $detailProducts[(int) $dp['id']] = [
            'id' => (int) $dp['id'], 'name' => $dp['name'], 'desc' => (string) $dp['description'], 'price' => (int) $dp['price'],
            'price_text' => rupiah($dp['price']), 'image' => $dp['image'] ?: 'placeholder.svg', 'orderable' => (bool) $dp['orderable'],
        ];
    }
} catch (Throwable $ex) {
    error_log('order extras: ' . $ex->getMessage());
}
// Link promo/flyer hanya valid bila produknya ada di data detail (tidak pernah menghasilkan link rusak)
$linkable = function ($pid) use ($detailProducts) { return $pid && isset($detailProducts[(int) $pid]) ? (int) $pid : 0; };
// Link langsung (promo/flyer): divalidasi ulang saat tampil; dibuka di tab baru agar pesanan customer tidak hilang
$directLink = function ($row) { return isset($row['link_url']) ? safe_link_url($row['link_url']) : null; };

$page_title = 'Menu Meja ' . $table['table_number'];
$base = '';
$page = 'menu';
$body_attrs = ['table' => $table['table_number']];
include __DIR__ . '/includes/header.php';
?>
<header class="q-header q-header-hero" id="q-header">
  <div class="q-wrap q-hero-extra" aria-hidden="true">Selamat datang di</div>
  <div class="q-wrap q-bar">
    <?php if ($logo = theme_logo()): ?><img class="q-logo" src="<?= $base ?>assets/<?= e($logo) ?>" alt="" height="32"><?php endif; ?>
    <div class="q-name"><?= e(APP_NAME) ?></div>
    <span class="q-table">Meja <?= e($table['table_number']) ?></span>
  </div>
</header>

<nav class="q-cats" aria-label="Kategori menu">
  <div class="q-wrap">
    <div class="cat-tabs">
      <button class="btn btn-sm btn-dark" data-cat="all" aria-pressed="true"><i class="bi bi-grid-fill" aria-hidden="true"></i> Semua</button>
      <?php foreach ($categories as $c): ?>
        <button class="btn btn-sm btn-outline-dark" data-cat="<?= (int) $c['id'] ?>" aria-pressed="false"><?= category_icon_html($c['icon'], $base) ?> <?= e($c['name']) ?></button>
      <?php endforeach; ?>
    </div>
  </div>
</nav>

<main class="q-wrap q-main has-cartbar">
  <?php if ($promos): ?>
  <section id="promo" class="q-section" aria-label="Promo">
    <div id="promo-carousel" class="carousel slide promo-carousel" data-bs-ride="carousel" data-bs-interval="4500" data-bs-touch="true">
      <div class="carousel-inner">
        <?php foreach ($promos as $i => $pr): ?>
          <div class="carousel-item<?= $i === 0 ? ' active' : '' ?>">
            <?php $lu = $directLink($pr); $lp = $lu === null ? $linkable($pr['product_id']) : 0; ?>
            <?php if ($lu !== null): ?><a class="promo-link" href="<?= e($lu) ?>" target="_blank" rel="noopener noreferrer" aria-label="<?= e($pr['title']) ?> (buka tautan di tab baru)"><?php endif; ?>
            <img class="promo-img d-block w-100<?= $lp ? ' open-product' : '' ?>" alt="<?= e($pr['title']) ?>" src="<?= $base ?>assets/<?= e($pr['image']) ?>"
                 <?= $lp ? 'role="button" tabindex="0" data-open="' . $lp . '"' : '' ?>>
            <?php if ($lu !== null): ?></a><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if (count($promos) > 1): ?>
      <div class="carousel-indicators">
        <?php foreach ($promos as $i => $pr): ?>
          <button type="button" data-bs-target="#promo-carousel" data-bs-slide-to="<?= $i ?>"<?= $i === 0 ? ' class="active" aria-current="true"' : '' ?> aria-label="Promo <?= $i + 1 ?>"></button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($topSellers): ?>
  <section id="top-seller" class="q-section" aria-labelledby="ts-title">
    <h2 id="ts-title" class="q-section-title">Top Seller</h2>
    <div class="hscroll" tabindex="0" aria-label="Top seller, geser ke kiri atau kanan">
      <?php foreach ($topSellers as $t): ?>
        <div class="card menu-card top-card" data-id="<?= (int) $t['id'] ?>" data-name="<?= e($t['name']) ?>" data-price="<?= (int) $t['price'] ?>">
          <div class="top-photo">
            <img class="top-img open-product" alt="" loading="lazy" role="button" tabindex="0" data-open="<?= (int) $t['id'] ?>" src="<?= $base ?>assets/images/<?= e($t['image'] ?: 'placeholder.svg') ?>">
            <span class="q-badge" aria-hidden="true">Top Seller</span>
          </div>
          <div class="top-body">
            <div class="top-name open-product" role="button" tabindex="0" data-open="<?= (int) $t['id'] ?>"><?= e($t['name']) ?></div>
            <div class="top-foot">
              <span class="q-price"><?= e(rupiah($t['price'])) ?></span>
              <div class="qty-control" data-qty-control></div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <section id="menu" class="q-section" aria-label="Daftar menu">
  <?php $catName = []; foreach ($categories as $c) { $catName[(int) $c['id']] = $c['name']; } $lastCat = null; ?>
  <div class="q-grid">
    <?php foreach ($products as $p): ?>
      <?php if ($lastCat !== (int) $p['category_id']): $lastCat = (int) $p['category_id']; ?>
        <div class="menu-cat-head" data-cat="<?= $lastCat ?>"><h2 class="menu-cat-title"><?= e(isset($catName[$lastCat]) ? $catName[$lastCat] : '') ?></h2></div>
      <?php endif; ?>
      <div class="menu-item" data-cat="<?= (int) $p['category_id'] ?>">
        <div class="card menu-card<?= $p['is_available'] ? '' : ' is-off' ?>"
             data-id="<?= (int) $p['id'] ?>" data-name="<?= e($p['name']) ?>" data-price="<?= (int) $p['price'] ?>">
          <img class="menu-img open-product" alt="" loading="lazy" role="button" tabindex="0" data-open="<?= (int) $p['id'] ?>"
               src="<?= $base ?>assets/images/<?= e($p['image'] ?: 'placeholder.svg') ?>">
          <div class="menu-info">
            <div class="menu-name open-product" role="button" tabindex="0" data-open="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></div>
            <?php if (trim((string) $p['description']) !== ''): ?><div class="menu-desc"><?= e($p['description']) ?></div><?php endif; ?>
            <div class="menu-foot">
              <span class="q-price"><?= e(rupiah($p['price'])) ?></span>
              <?php if ($p['is_available']): ?>
                <div class="qty-control" data-qty-control></div>
              <?php else: ?>
                <span class="q-habis">Habis</span>
                <button type="button" class="btn btn-qty" disabled aria-label="Menu habis">+</button>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if (!$products): ?><p class="q-muted text-center">Belum ada menu.</p><?php endif; ?>
  <?php if (($note = price_note_text()) !== ''): ?><p class="price-note"><?= e($note) ?></p><?php endif; ?>
  </section>
</main>

<a href="cart.php?table=<?= e($table['table_number']) ?>" id="cart-bar" class="cart-fab is-empty" aria-live="polite">
  <span class="cart-fab-main">
    <svg class="cart-ico" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.4a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L21 7H6"/></svg>
    <span id="cart-label">Belum ada pesanan</span>
    <span class="cart-badge" id="cart-badge"><span id="cart-count">0</span></span>
  </span>
  <span class="cart-fab-total" id="cart-total"></span>
</a>

<?php if ($flyer): ?>
<?php $flyerLink = $directLink($flyer); $flyerProduct = $flyerLink === null ? $linkable($flyer['product_id']) : 0; ?>
<div class="modal fade" id="flyer-modal" tabindex="-1" aria-labelledby="flyer-title" aria-hidden="true" data-flyer-id="<?= (int) $flyer['id'] ?>" data-product="<?= $flyerProduct ?>">
  <div class="modal-dialog modal-dialog-centered flyer-dialog">
    <div class="modal-content flyer-content">
      <button type="button" class="btn-close flyer-x" data-bs-dismiss="modal" aria-label="Tutup"></button>
      <div class="flyer-media">
        <?php if ($flyerLink !== null): ?><a class="flyer-link" href="<?= e($flyerLink) ?>" target="_blank" rel="noopener noreferrer" aria-label="<?= e($flyer['title']) ?> (buka tautan di tab baru)"><?php endif; ?>
        <img class="flyer-img<?= $flyerProduct ? ' open-product' : '' ?>" src="<?= $base ?>assets/<?= e($flyer['image']) ?>" alt="<?= e($flyer['title']) ?>"
             <?= $flyerProduct ? 'role="button" tabindex="0"' : '' ?>>
        <?php if ($flyerLink !== null): ?></a><?php endif; ?>
        <?php if ($flyerProduct): ?><span class="flyer-cta">Lihat menu <i class="bi bi-chevron-right" aria-hidden="true"></i></span>
        <?php elseif ($flyerLink !== null): ?><span class="flyer-cta">Buka tautan <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></span><?php endif; ?>
      </div>
      <div class="flyer-foot">
        <div id="flyer-title" class="fw-bold text-truncate"><?= e($flyer['title']) ?></div>
        <button type="button" class="btn btn-dark px-4" data-bs-dismiss="modal">TUTUP</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php if ($detailProducts): ?>
<script type="application/json" id="products-data"><?= json_encode(array_values($detailProducts), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<div class="modal fade" id="product-modal" tabindex="-1" aria-labelledby="pd-name" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable pd-dialog">
    <div class="modal-content pd-content">
      <div class="pd-media" id="pd-media">
        <span class="pd-handle" aria-hidden="true"></span>
        <img id="pd-img" alt="" src="">
        <button type="button" class="btn-close pd-x" data-bs-dismiss="modal" aria-label="Tutup"></button>
        <button type="button" class="pd-nav pd-prev" id="pd-prev" aria-label="Menu sebelumnya"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
        <button type="button" class="pd-nav pd-next" id="pd-next" aria-label="Menu berikutnya"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
        <span class="pd-count" id="pd-count"></span>
      </div>
      <div class="modal-body pd-body">
        <h2 id="pd-name" class="h4 fw-bold mb-1"></h2>
        <div class="pd-price" id="pd-price"></div>
        <p class="pd-desc" id="pd-desc"></p>
        <div id="pd-note" class="alert alert-secondary py-2 small d-none mb-0">Menu ini sedang tidak tersedia.</div>
      </div>
      <div class="pd-foot">
        <div class="pd-inorder small" id="pd-inorder"></div>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-outline-dark btn-qty pd-minus d-none" id="pd-minus" aria-label="Kurangi pesanan">&minus;</button>
          <button type="button" class="btn btn-accent flex-grow-1" id="pd-add">+ Tambah Pesanan</button>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
