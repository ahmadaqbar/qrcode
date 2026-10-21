<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        flash_set('err', 'Sesi tidak valid, muat ulang halaman.');
        redirect('tables.php');
    }
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    try {
        if ($action === 'add') {
            $no = clean_input(isset($_POST['table_number']) ? $_POST['table_number'] : '', 10);
            if (!preg_match('/^[0-9A-Za-z]{1,10}$/', $no)) {
                flash_set('err', 'Nomor meja hanya huruf/angka, maks 10 karakter.');
            } else {
                $pdo->prepare('INSERT INTO tables (table_number) VALUES (?)')->execute([$no]);
                flash_set('ok', 'Meja ' . $no . ' ditambahkan.');
            }
        } elseif ($action === 'toggle') {
            $id = filter_var(isset($_POST['id']) ? $_POST['id'] : null, FILTER_VALIDATE_INT);
            if ($id) {
                $pdo->prepare('UPDATE tables SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
                flash_set('ok', 'Status meja diubah.');
            }
        }
    } catch (PDOException $ex) {
        flash_set('err', $ex->getCode() === '23000' ? 'Nomor meja sudah ada.' : 'Terjadi kesalahan sistem.');
        if ($ex->getCode() !== '23000') { error_log($ex->getMessage()); }
    }
    redirect('tables.php');
}
$tables = $pdo->query('SELECT * FROM tables ORDER BY table_number')->fetchAll();

$page_title = 'Meja';
$base = '../';
$admin = true;
include __DIR__ . '/../includes/header.php';
?>
<div class="container py-3" style="max-width:560px">
  <h1 class="h4 mb-3">Meja</h1>
  <form method="post" class="input-group mb-4"><?= csrf_field() ?><input type="hidden" name="action" value="add">
    <input class="form-control" name="table_number" maxlength="10" placeholder="Nomor meja, mis. 06" required>
    <button class="btn btn-dark">Tambah</button></form>
  <ul class="list-group">
    <?php foreach ($tables as $t): ?>
      <li class="list-group-item d-flex justify-content-between align-items-center">
        <span>Meja <strong><?= e($t['table_number']) ?></strong>
          <span class="badge text-bg-<?= $t['is_active'] ? 'success' : 'secondary' ?> ms-2"><?= $t['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></span>
        <form method="post" class="m-0"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
          <button class="btn btn-sm btn-outline-secondary"><?= $t['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button></form>
      </li>
    <?php endforeach; ?>
  </ul>
  <p class="text-muted small mt-3">Meja nonaktif tidak bisa dipakai memesan (QR-nya menampilkan "Meja tidak ditemukan"). Cetak QR di menu QR.</p>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
