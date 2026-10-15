<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/upload.php';
require_permission('catalog.manage');

$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        flash_set('err', 'Sesi tidak valid, muat ulang halaman.');
        redirect('products.php');
    }
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $id = filter_var(isset($_POST['id']) ? $_POST['id'] : null, FILTER_VALIDATE_INT);
    try {
        if ($action === 'save') {
            $name = clean_input(isset($_POST['name']) ? $_POST['name'] : '', 100);
            $desc = clean_input(isset($_POST['description']) ? $_POST['description'] : '', 255);
            $cat = filter_var(isset($_POST['category_id']) ? $_POST['category_id'] : null, FILTER_VALIDATE_INT);
            $price = filter_var(isset($_POST['price']) ? $_POST['price'] : null, FILTER_VALIDATE_INT);
            $avail = !empty($_POST['is_available']) ? 1 : 0;
            $active = !empty($_POST['is_active']) ? 1 : 0;
            $sku = strtoupper(clean_input(isset($_POST['sku']) ? $_POST['sku'] : '', 30));
            $acc = clean_input(isset($_POST['accurate_item_code']) ? $_POST['accurate_item_code'] : '', 50);
            if ($sku !== '' && !preg_match('/^[A-Z0-9._-]{1,30}$/', $sku)) {
                throw new RuntimeException('Kode produk (SKU) hanya huruf, angka, titik, minus, underscore.');
            }
            if ($sku !== '') {
                $dup = $pdo->prepare('SELECT COUNT(*) FROM products WHERE sku = ? AND id <> ?');
                $dup->execute([$sku, (int) $id]);
                if ($dup->fetchColumn()) {
                    throw new RuntimeException('Kode produk (SKU) ' . $sku . ' sudah dipakai produk lain.');
                }
            }
            $chk = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE id = ?');
            $chk->execute([(int) $cat]);
            if ($name === '' || $price === false || $price < 0 || $price > 100000000 || !$cat || !$chk->fetchColumn()) {
                throw new RuntimeException('Nama, kategori, dan harga (angka Rupiah) wajib diisi dengan benar.');
            }
            list($img, $imgErr) = save_menu_image(isset($_FILES['image']) ? $_FILES['image'] : []);
            if ($imgErr) {
                throw new RuntimeException($imgErr);
            }
            if ($id) {
                $old = $pdo->prepare('SELECT image FROM products WHERE id = ?');
                $old->execute([$id]);
                $oldImg = $old->fetchColumn();
                if ($oldImg === false) {
                    throw new RuntimeException('Menu tidak ditemukan.');
                }
                $oldp = $pdo->prepare('SELECT price FROM products WHERE id = ?');
                $oldp->execute([$id]);
                $oldPrice = (int) $oldp->fetchColumn();
                $pdo->prepare('UPDATE products SET category_id=?, name=?, description=?, price=?, is_available=?, is_active=?, sku=?, accurate_item_code=?, image=COALESCE(?, image) WHERE id=?')
                    ->execute([$cat, $name, $desc ?: null, $price, $avail, $active, $sku ?: null, $acc ?: null, $img, $id]);
                if ($oldPrice !== (int) $price) {
                    audit('product.price', 'product', (int) $id, ['from' => $oldPrice, 'to' => (int) $price]);   // harga transaksi lama tidak berubah (snapshot)
                }
                if ($img) {
                    delete_menu_image($oldImg);
                }
            } else {
                $pdo->prepare('INSERT INTO products (category_id, name, description, price, is_available, is_active, sku, accurate_item_code, image) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$cat, $name, $desc ?: null, $price, $avail, $active, $sku ?: null, $acc ?: null, $img]);
            }
            flash_set('ok', 'Menu disimpan.');
        } elseif ($action === 'toggle' && $id) {
            $pdo->prepare('UPDATE products SET is_available = 1 - is_available WHERE id = ?')->execute([$id]);
            flash_set('ok', 'Ketersediaan menu diubah.');
        } elseif ($action === 'delete' && $id) {
            $q = $pdo->prepare('SELECT image FROM products WHERE id = ?');
            $q->execute([$id]);
            $img = $q->fetchColumn();
            try {
                $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
                delete_menu_image($img ?: null);
                flash_set('ok', 'Menu dihapus.');
            } catch (PDOException $ex) {
                // Dipakai order_items / promos / flyers (FK): cukup nonaktifkan agar riwayat & relasi tetap utuh
                $pdo->prepare('UPDATE products SET is_available = 0 WHERE id = ?')->execute([$id]);
                flash_set('err', 'Menu sudah pernah dipesan atau dipakai promo/flyer sehingga tidak bisa dihapus; ditandai Habis.');
            }
        }
    } catch (RuntimeException $ex) {
        flash_set('err', $ex->getMessage());
    } catch (Throwable $ex) {
        error_log($ex->getMessage());
        flash_set('err', 'Terjadi kesalahan sistem.');
    }
    redirect('products.php');
}

$categories = $pdo->query('SELECT id, name, is_active FROM categories ORDER BY id')->fetchAll();
$products = $pdo->query('SELECT p.*, c.name AS cat FROM products p JOIN categories c ON c.id = p.category_id ORDER BY c.id, p.name')->fetchAll();
$edit = null;
if (isset($_GET['edit'])) {
    foreach ($products as $p) {
        if ((int) $p['id'] === (int) $_GET['edit']) { $edit = $p; }
    }
}

$page_title = 'Kelola Menu';
$base = '../';
$admin = true;
include __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid py-3">
  <h1 class="h4 mb-3">Kelola Menu</h1>
  <div class="card mb-4"><div class="card-body">
    <h2 class="h6"><?= $edit ? 'Edit Menu' : 'Tambah Menu' ?></h2>
    <form method="post" enctype="multipart/form-data" class="row g-2">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
      <div class="col-12 col-md-4"><label class="form-label small">Nama</label>
        <input class="form-control" name="name" maxlength="100" required value="<?= e($edit ? $edit['name'] : '') ?>"></div>
      <div class="col-6 col-md-3"><label class="form-label small">Kategori</label>
        <select class="form-select" name="category_id" required>
          <?php foreach ($categories as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= $edit && (int) $edit['category_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="col-6 col-md-2"><label class="form-label small">Harga (Rp)</label>
        <input class="form-control" name="price" type="number" min="0" step="500" required value="<?= e($edit ? $edit['price'] : '') ?>"></div>
      <div class="col-12 col-md-3"><label class="form-label small">Foto (JPG/PNG/WebP, maks 2MB)</label>
        <input class="form-control" type="file" name="image" accept="image/jpeg,image/png,image/webp"></div>
      <div class="col-12 col-md-9"><label class="form-label small">Deskripsi</label>
        <input class="form-control" name="description" maxlength="255" value="<?= e($edit ? $edit['description'] : '') ?>"></div>
      <div class="col-6 col-md-3"><label class="form-label small" for="sku">Kode produk (SKU)</label>
        <input class="form-control" name="sku" id="sku" maxlength="30" value="<?= e($edit ? $edit['sku'] : '') ?>" placeholder="mis. MKN-001"></div>
      <div class="col-6 col-md-3"><label class="form-label small" for="acc">Kode barang Accurate</label>
        <input class="form-control" name="accurate_item_code" id="acc" maxlength="50" value="<?= e($edit ? $edit['accurate_item_code'] : '') ?>" placeholder="dari pengelola Accurate"></div>
      <div class="col-12 col-md-6 d-flex align-items-end gap-3">
        <div class="form-check"><input class="form-check-input" type="checkbox" name="is_available" id="av" value="1" <?= !$edit || $edit['is_available'] ? 'checked' : '' ?>>
          <label class="form-check-label" for="av">Tersedia (stok/porsi ada)</label></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" id="act" value="1" <?= !$edit || $edit['is_active'] ? 'checked' : '' ?>>
          <label class="form-check-label" for="act">Aktif (tampil di menu & POS)</label></div>
      </div>
      <div class="col-12">
        <button class="btn btn-dark">Simpan</button>
        <?php if ($edit): ?><a href="products.php" class="btn btn-outline-secondary">Batal</a><?php endif; ?>
      </div>
    </form>
  </div></div>

  <div class="table-responsive">
    <table class="table align-middle bg-white">
      <thead><tr><th></th><th>Nama</th><th>Kategori</th><th class="text-end">Harga</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($products as $p): ?>
        <tr>
          <td><img src="../assets/images/<?= e($p['image'] ?: 'placeholder.svg') ?>" alt="" width="48" height="48" style="object-fit:cover;border-radius:6px"></td>
          <td><?= e($p['name']) ?><?= $p['sku'] ? ' <code class="small">' . e($p['sku']) . '</code>' : '' ?><div class="small text-muted"><?= e($p['description']) ?></div></td>
          <td><?= e($p['cat']) ?></td>
          <td class="text-end"><?= e(rupiah($p['price'])) ?></td>
          <td><span class="badge text-bg-<?= $p['is_available'] ? 'success' : 'secondary' ?>"><?= $p['is_available'] ? 'Tersedia' : 'Habis' ?></span>
            <?php if (!$p['is_active']): ?><span class="badge text-bg-dark">Nonaktif</span><?php endif; ?>
            <?php if (!$p['accurate_item_code']): ?><span class="badge text-bg-light border text-muted" title="Kode barang Accurate belum diisi">tanpa kode Accurate</span><?php endif; ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="products.php?edit=<?= (int) $p['id'] ?>">Edit</a>
            <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <button class="btn btn-sm btn-outline-secondary"><?= $p['is_available'] ? 'Set Habis' : 'Set Tersedia' ?></button></form>
            <form method="post" class="d-inline" onsubmit="return confirm('Hapus menu ini?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <button class="btn btn-sm btn-outline-danger">Hapus</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
