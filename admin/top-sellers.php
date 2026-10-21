<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

const TOP_SELLER_LIMIT = 8; // jumlah maksimum yang tampil ke customer

$pdo = db();
$q = clean_input(isset($_GET['q']) ? $_GET['q'] : '', 50);
$self = 'top-sellers.php' . ($q !== '' ? '?q=' . rawurlencode($q) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        flash_set('err', 'Sesi tidak valid, muat ulang halaman.');
        redirect($self);
    }
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $id = filter_var(isset($_POST['id']) ? $_POST['id'] : null, FILTER_VALIDATE_INT);
    try {
        if ($action === 'set' && $id) {
            $next = (int) $pdo->query('SELECT COALESCE(MAX(top_seller_order), 0) + 1 FROM products WHERE is_top_seller = 1')->fetchColumn();
            $pdo->prepare('UPDATE products SET is_top_seller = 1, top_seller_order = ? WHERE id = ? AND is_top_seller = 0')->execute([$next, $id]);
            flash_set('ok', 'Ditambahkan ke Top Seller.');
        } elseif ($action === 'remove' && $id) {
            $pdo->prepare('UPDATE products SET is_top_seller = 0, top_seller_order = 0 WHERE id = ?')->execute([$id]);
            flash_set('ok', 'Dihapus dari Top Seller.');
        } elseif ($action === 'order' && $id) {
            $o = filter_var(isset($_POST['top_seller_order']) ? $_POST['top_seller_order'] : null, FILTER_VALIDATE_INT);
            if ($o === false || $o < 0 || $o > 9999) {
                flash_set('err', 'Urutan harus angka 0-9999.');
            } else {
                $pdo->prepare('UPDATE products SET top_seller_order = ? WHERE id = ? AND is_top_seller = 1')->execute([$o, $id]);
                flash_set('ok', 'Urutan disimpan.');
            }
        }
    } catch (Throwable $ex) {
        error_log($ex->getMessage());
        flash_set('err', 'Terjadi kesalahan sistem.');
    }
    redirect($self);
}

$tops = $pdo->query('SELECT p.*, c.name AS cat FROM products p JOIN categories c ON c.id = p.category_id
                     WHERE p.is_top_seller = 1 ORDER BY p.top_seller_order ASC, p.id ASC')->fetchAll();
// Pencarian: escape wildcard LIKE agar % dan _ dicari apa adanya
$like = '%' . addcslashes($q, '%_\\') . '%';
$st = $pdo->prepare('SELECT p.*, c.name AS cat FROM products p JOIN categories c ON c.id = p.category_id
                     WHERE p.name LIKE ? ORDER BY p.is_top_seller DESC, p.top_seller_order ASC, p.name LIMIT 100');
$st->execute([$like]);
$rows = $st->fetchAll();

$page_title = 'Top Seller';
$base = '../';
$admin = true;
include __DIR__ . '/../includes/header.php';

function ts_row(array $p, string $self): void { ?>
  <tr>
    <td style="width:56px"><img src="../assets/images/<?= e($p['image'] ?: 'placeholder.svg') ?>" alt="" width="44" height="44" style="object-fit:cover;border-radius:6px"></td>
    <td><div class="fw-semibold"><?= e($p['name']) ?></div><div class="small text-muted"><?= e($p['cat']) ?> &middot; <?= e(rupiah($p['price'])) ?><?= $p['is_available'] ? '' : ' &middot; <span class="text-danger">Habis</span>' ?></div></td>
    <td class="text-end text-nowrap">
      <?php if ($p['is_top_seller']): ?>
        <form method="post" action="<?= e($self) ?>" class="d-inline-flex gap-1 align-items-center"><?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <span class="badge text-bg-success me-1">YES</span>
          <input class="form-control form-control-sm" style="width:72px" type="number" min="0" max="9999" name="top_seller_order" value="<?= (int) $p['top_seller_order'] ?>" aria-label="Urutan">
          <button class="btn btn-sm btn-outline-primary" name="action" value="order">Simpan</button>
          <button class="btn btn-sm btn-outline-danger" name="action" value="remove">Remove</button>
        </form>
      <?php else: ?>
        <form method="post" action="<?= e($self) ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <button class="btn btn-sm btn-dark" name="action" value="set">Set Top Seller</button></form>
      <?php endif; ?>
    </td>
  </tr>
<?php } ?>
<div class="container-fluid py-3" style="max-width:900px">
  <h1 class="h4 mb-1">Top Seller</h1>
  <p class="text-muted small">Customer melihat maksimal <?= TOP_SELLER_LIMIT ?> menu (yang tersedia), diurutkan dari angka Urutan terkecil.
    Saat ini <?= count($tops) ?> menu ditandai<?= count($tops) > TOP_SELLER_LIMIT ? ' &mdash; sisanya tidak ditampilkan' : '' ?>.</p>

  <form method="get" class="input-group mb-3">
    <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Cari menu..." maxlength="50">
    <button class="btn btn-dark">Cari</button>
    <?php if ($q !== ''): ?><a class="btn btn-outline-secondary" href="top-sellers.php">Reset</a><?php endif; ?>
  </form>

  <div class="table-responsive"><table class="table align-middle bg-white mb-0">
    <tbody>
      <?php foreach ($rows as $p) { ts_row($p, $self); } ?>
      <?php if (!$rows): ?><tr><td class="text-center text-muted py-4">Menu tidak ditemukan.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
