<?php
require_once __DIR__ . '/includes/functions.php';

$number = isset($_GET['table']) ? trim((string) $_GET['table']) : '';
try {
    $table = find_active_table($number);
    if (!$table) {
        fail_page('Meja tidak ditemukan', 404);
    }
    $categories = db()->query('SELECT id, name FROM categories WHERE is_active = 1 ORDER BY id')->fetchAll();
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
try {
    $flyer = db()->query('SELECT id, title, image FROM flyers WHERE ' . SQL_ACTIVE_PERIOD . ' ORDER BY id DESC LIMIT 1')->fetch() ?: null;
    $promos = db()->query('SELECT id, title, image FROM promos WHERE ' . SQL_ACTIVE_PERIOD . ' ORDER BY sort_order ASC, id DESC LIMIT 10')->fetchAll();
    $topSellers = db()->query('SELECT p.id, p.name, p.price, p.image FROM products p
                               JOIN categories c ON c.id = p.category_id AND c.is_active = 1
                               WHERE p.is_top_seller = 1 AND p.is_available = 1
                               ORDER BY p.top_seller_order ASC, p.id ASC LIMIT 8')->fetchAll();
} catch (Throwable $ex) {
    error_log('order extras: ' . $ex->getMessage());
}

$page_title = 'Menu Meja ' . $table['table_number'];
$base = '';
$page = 'menu';
$body_attrs = ['table' => $table['table_number']];
include __DIR__ . '/includes/header.php';
?>
<header class="cust-header sticky-top">
  <div class="container">
    <div class="d-flex justify-content-between align-items-center">
      <div>
        <div class="fw-semibold"><?= e(APP_NAME) ?></div>
        <div class="small text-muted">Meja <?= e($table['table_number']) ?></div>
      </div>
    </div>
    <div class="cat-tabs mt-2">
      <button class="btn btn-sm btn-dark" data-cat="all">Semua</button>
      <?php foreach ($categories as $c): ?>
        <button class="btn btn-sm btn-outline-dark" data-cat="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></button>
      <?php endforeach; ?>
    </div>
  </div>
</header>

<main class="container py-3 has-cartbar">
  <?php if ($topSellers): ?>
  <section id="top-seller" class="mb-4" aria-labelledby="ts-title">
    <h2 id="ts-title" class="section-title">TOP SELLER</h2>
    <div class="hscroll" tabindex="0" aria-label="Top seller, geser ke kiri atau kanan">
      <?php foreach ($topSellers as $t): ?>
        <div class="card menu-card top-card" data-id="<?= (int) $t['id'] ?>" data-name="<?= e($t['name']) ?>" data-price="<?= (int) $t['price'] ?>">
          <img class="top-img" alt="" loading="lazy" src="<?= $base ?>assets/images/<?= e($t['image'] ?: 'placeholder.svg') ?>">
          <div class="p-2 d-flex flex-column flex-grow-1">
            <div class="fw-semibold top-name"><?= e($t['name']) ?></div>
            <div class="small fw-bold mb-2"><?= e(rupiah($t['price'])) ?></div>
            <div class="qty-control mt-auto" data-qty-control></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="swipe-hint text-muted small">&larr; geser &rarr;</div>
  </section>
  <?php endif; ?>

  <?php if ($promos): ?>
  <section id="promo" class="mb-4" aria-labelledby="promo-title">
    <h2 id="promo-title" class="section-title">PROMO</h2>
    <div id="promo-carousel" class="carousel slide promo-carousel" data-bs-ride="carousel" data-bs-interval="4500" data-bs-touch="true">
      <div class="carousel-inner">
        <?php foreach ($promos as $i => $pr): ?>
          <div class="carousel-item<?= $i === 0 ? ' active' : '' ?>">
            <img class="promo-img d-block w-100" alt="<?= e($pr['title']) ?>" src="<?= $base ?>assets/<?= e($pr['image']) ?>">
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

  <section id="menu" aria-labelledby="menu-title">
  <h2 id="menu-title" class="section-title">MENU</h2>
  <div class="row g-3">
    <?php foreach ($products as $p): ?>
      <div class="col-12 col-md-6 menu-item" data-cat="<?= (int) $p['category_id'] ?>">
        <div class="card menu-card h-100"
             data-id="<?= (int) $p['id'] ?>" data-name="<?= e($p['name']) ?>" data-price="<?= (int) $p['price'] ?>">
          <div class="d-flex">
            <img class="menu-img" alt="" loading="lazy"
                 src="<?= $base ?>assets/images/<?= e($p['image'] ?: 'placeholder.svg') ?>">
            <div class="p-3 flex-grow-1 d-flex flex-column">
              <div class="fw-semibold"><?= e($p['name']) ?></div>
              <div class="small text-muted mb-1"><?= e($p['description']) ?></div>
              <div class="mt-auto d-flex justify-content-between align-items-center">
                <span class="fw-bold"><?= e(rupiah($p['price'])) ?></span>
                <?php if ($p['is_available']): ?>
                  <div class="qty-control" data-qty-control></div>
                <?php else: ?>
                  <span class="badge text-bg-secondary">Habis</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$products): ?><p class="text-center text-muted">Belum ada menu.</p><?php endif; ?>
  </div>
  </section>
</main>

<a href="cart.php?table=<?= e($table['table_number']) ?>" id="cart-bar" class="cart-fab is-empty" aria-live="polite">
  <span class="cart-fab-main">
    <svg class="cart-ico" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.4a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L21 7H6"/></svg>
    <span id="cart-label">Keranjang kosong</span>
    <span class="cart-badge" id="cart-badge"><span id="cart-count">0</span></span>
  </span>
  <span class="cart-fab-total" id="cart-total"></span>
</a>

<?php if ($flyer): ?>
<div class="modal fade" id="flyer-modal" tabindex="-1" aria-labelledby="flyer-title" aria-hidden="true" data-flyer-id="<?= (int) $flyer['id'] ?>">
  <div class="modal-dialog modal-dialog-centered flyer-dialog">
    <div class="modal-content flyer-content">
      <button type="button" class="btn-close flyer-x" data-bs-dismiss="modal" aria-label="Tutup"></button>
      <img class="flyer-img" src="<?= $base ?>assets/<?= e($flyer['image']) ?>" alt="<?= e($flyer['title']) ?>">
      <div class="flyer-foot">
        <div id="flyer-title" class="fw-bold text-truncate"><?= e($flyer['title']) ?></div>
        <button type="button" class="btn btn-dark px-4" data-bs-dismiss="modal">TUTUP</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
