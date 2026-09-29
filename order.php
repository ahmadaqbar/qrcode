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
</main>

<a href="cart.php?table=<?= e($table['table_number']) ?>" id="cart-bar" class="cart-bar d-none">
  <span><span id="cart-count">0</span> item</span>
  <span class="fw-semibold">Lihat Keranjang &middot; <span id="cart-total">Rp0</span></span>
</a>
<?php include __DIR__ . '/includes/footer.php'; ?>
