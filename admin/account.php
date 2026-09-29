<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cur = isset($_POST['current']) ? (string) $_POST['current'] : '';
    $new = isset($_POST['new']) ? (string) $_POST['new'] : '';
    $rep = isset($_POST['repeat']) ? (string) $_POST['repeat'] : '';
    try {
        $st = db()->prepare('SELECT password FROM users WHERE id = ?');
        $st->execute([$_SESSION['user_id']]);
        $hash = $st->fetchColumn();
        if (!csrf_valid()) {
            flash_set('err', 'Sesi tidak valid, coba lagi.');
        } elseif (!$hash || !password_verify($cur, $hash)) {
            flash_set('err', 'Password saat ini salah.');
        } elseif (strlen($new) < 8 || strlen($new) > 72) {
            flash_set('err', 'Password baru minimal 8 karakter (maks 72).');
        } elseif ($new !== $rep) {
            flash_set('err', 'Konfirmasi password tidak sama.');
        } else {
            db()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['user_id']]);
            session_regenerate_id(true);
            flash_set('ok', 'Password berhasil diganti.');
        }
    } catch (Throwable $ex) {
        error_log($ex->getMessage());
        flash_set('err', 'Terjadi kesalahan sistem.');
    }
    redirect('account.php');
}

$page_title = 'Akun';
$base = '../';
$admin = true;
include __DIR__ . '/../includes/header.php';
?>
<div class="container py-3" style="max-width:420px">
  <h1 class="h4 mb-3">Ganti Password</h1>
  <form method="post" autocomplete="off"><?= csrf_field() ?>
    <div class="mb-3"><label class="form-label">Password saat ini</label><input class="form-control" type="password" name="current" required></div>
    <div class="mb-3"><label class="form-label">Password baru (min. 8 karakter)</label><input class="form-control" type="password" name="new" minlength="8" maxlength="72" required></div>
    <div class="mb-3"><label class="form-label">Ulangi password baru</label><input class="form-control" type="password" name="repeat" required></div>
    <button class="btn btn-dark">Simpan</button>
  </form>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
