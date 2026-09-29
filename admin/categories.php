<?php
require_once __DIR__ . '/../includes/auth.php';
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
        if ($action === 'add' && $name !== '') {
            $pdo->prepare('INSERT INTO categories (name) VALUES (?)')->execute([$name]);
            flash_set('ok', 'Kategori ditambahkan.');
        } elseif ($action === 'rename' && $id && $name !== '') {
            $pdo->prepare('UPDATE categories SET name = ? WHERE id = ?')->execute([$name, $id]);
            flash_set('ok', 'Kategori diubah.');
        } elseif ($action === 'toggle' && $id) {
            $pdo->prepare('UPDATE categories SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
            flash_set('ok', 'Status kategori diubah (menu di kategori nonaktif tidak tampil ke customer).');
        } else {
            flash_set('err', 'Nama kategori wajib diisi.');
        }
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
<div class="container py-3" style="max-width:720px">
  <h1 class="h4 mb-3">Kategori</h1>
  <form method="post" class="input-group mb-4"><?= csrf_field() ?><input type="hidden" name="action" value="add">
    <input class="form-control" name="name" maxlength="50" placeholder="Nama kategori baru" required>
    <button class="btn btn-dark">Tambah</button></form>
  <ul class="list-group">
    <?php foreach ($cats as $c): ?>
      <li class="list-group-item">
        <form method="post" class="d-flex gap-2 align-items-center"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
          <input class="form-control" name="name" maxlength="50" value="<?= e($c['name']) ?>">
          <span class="badge text-bg-light border"><?= (int) $c['n'] ?> menu</span>
          <button class="btn btn-sm btn-outline-primary" name="action" value="rename">Simpan</button>
          <button class="btn btn-sm btn-outline-<?= $c['is_active'] ? 'secondary' : 'success' ?>" name="action" value="toggle"><?= $c['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
