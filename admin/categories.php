<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/upload.php';
require_login();

$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        flash_set('err', 'Sesi tidak valid, muat ulang halaman.');
        redirect('categories.php');
    }
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $id = filter_var(isset($_POST['id']) ? $_POST['id'] : null, FILTER_VALIDATE_INT);
    $name = clean_input(isset($_POST['name']) ? $_POST['name'] : '', 50);
    try {
        if (($action === 'add' || $action === 'rename') && $name === '') {
            throw new RuntimeException('Nama kategori wajib diisi.');
        }
        if ($action === 'add') {
            list($icon, $err) = save_category_icon(isset($_FILES['icon']) ? $_FILES['icon'] : []);
            if ($err) {
                throw new RuntimeException($err);
            }
            $pdo->prepare('INSERT INTO categories (name, icon) VALUES (?, ?)')->execute([$name, $icon]);
            flash_set('ok', 'Kategori ditambahkan.');
        } elseif ($action === 'rename' && $id) {
            $old = $pdo->prepare('SELECT icon FROM categories WHERE id = ?');
            $old->execute([$id]);
            $oldIcon = $old->fetchColumn();
            if ($oldIcon === false) {
                throw new RuntimeException('Kategori tidak ditemukan.');
            }
            list($icon, $err) = save_category_icon(isset($_FILES['icon']) ? $_FILES['icon'] : []);
            if ($err) {
                throw new RuntimeException($err);
            }
            $newIcon = $oldIcon;
            if ($icon) {
                $newIcon = $icon;                    // icon baru menggantikan yang lama
            } elseif (!empty($_POST['remove_icon'])) {
                $newIcon = null;                     // hapus icon -> tampil icon umum
            }
            $pdo->prepare('UPDATE categories SET name = ?, icon = ? WHERE id = ?')->execute([$name, $newIcon, $id]);
            if ($newIcon !== $oldIcon) {
                delete_upload_image($oldIcon ?: null);
            }
            flash_set('ok', 'Kategori diubah.');
        } elseif ($action === 'toggle' && $id) {
            $pdo->prepare('UPDATE categories SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
            flash_set('ok', 'Status kategori diubah (menu di kategori nonaktif tidak tampil ke customer).');
        }
    } catch (RuntimeException $ex) {
        flash_set('err', $ex->getMessage());
    } catch (Throwable $ex) {
        error_log($ex->getMessage());
        flash_set('err', 'Terjadi kesalahan sistem.');
    }
    redirect('categories.php');
}
$cats = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS n FROM categories c ORDER BY c.id')->fetchAll();

$page_title = 'Kategori';
$base = '../';
$admin = true;
include __DIR__ . '/../includes/header.php';
?>
<div class="container py-3" style="max-width:760px">
  <h1 class="h4 mb-1">Kategori</h1>
  <p class="text-muted small">Icon kategori berupa file <strong>.ico</strong> atau <strong>.png</strong> (maks 512KB, maks 1024&times;1024 px; disarankan persegi dengan latar transparan).</p>
  <form method="post" enctype="multipart/form-data" class="card card-body mb-4 row g-2 flex-row align-items-end"><?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="col-12 col-md-5"><label class="form-label small" for="new-name">Nama kategori baru</label>
      <input class="form-control" id="new-name" name="name" maxlength="50" required></div>
    <div class="col-12 col-md-5"><label class="form-label small" for="new-icon">Icon (.ico / .png)</label>
      <input class="form-control" id="new-icon" type="file" name="icon" accept=".ico,.png,image/png,image/x-icon,image/vnd.microsoft.icon"></div>
    <div class="col-12 col-md-2"><button class="btn btn-dark w-100">Tambah</button></div>
  </form>
  <ul class="list-group">
    <?php foreach ($cats as $c): $fid = 'c' . (int) $c['id']; ?>
      <li class="list-group-item">
        <form method="post" enctype="multipart/form-data" class="cat-row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
          <span class="cat-row-icon"><?= category_icon_html($c['icon'], '../', 'cat-ico-lg') ?></span>
          <div class="cat-row-fields">
            <input class="form-control form-control-sm mb-1" name="name" maxlength="50" value="<?= e($c['name']) ?>" aria-label="Nama kategori">
            <input class="form-control form-control-sm" type="file" name="icon" accept=".ico,.png,image/png,image/x-icon,image/vnd.microsoft.icon" aria-label="Ganti icon <?= e($c['name']) ?> (.ico / .png)">
            <?php if ($c['icon']): ?><div class="form-check small mt-1"><input class="form-check-input" type="checkbox" name="remove_icon" value="1" id="rm<?= $fid ?>"><label class="form-check-label" for="rm<?= $fid ?>">Hapus icon</label></div><?php endif; ?>
          </div>
          <div class="cat-row-actions">
            <span class="badge text-bg-light border"><?= (int) $c['n'] ?> menu</span>
            <button class="btn btn-sm btn-outline-primary" name="action" value="rename">Simpan</button>
            <button class="btn btn-sm btn-outline-<?= $c['is_active'] ? 'secondary' : 'success' ?>" name="action" value="toggle" formnovalidate><?= $c['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button>
          </div>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
