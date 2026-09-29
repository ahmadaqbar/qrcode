<?php
require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim(isset($_POST['username']) ? (string) $_POST['username'] : '');
    $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
    if (!csrf_valid()) {
        $error = 'Sesi tidak valid, silakan coba lagi.';
    } elseif (login_locked()) {
        $error = 'Terlalu banyak percobaan login. Coba lagi dalam ' . LOGIN_WINDOW_MIN . ' menit.';
    } elseif ($username === '' || $password === '' || strlen($username) > 50) {
        $error = 'Username dan password wajib diisi.';
    } else {
        try {
            if (attempt_login($username, $password)) {
                header('Location: dashboard.php');
                exit;
            }
            $error = 'Username atau password salah.';
        } catch (Throwable $ex) {
            error_log($ex->getMessage());
            $error = 'Terjadi kesalahan sistem.';
        }
    }
}

$page_title = 'Login Staff';
$base = '../';
$admin = true;
include __DIR__ . '/../includes/header.php';
?>
<div class="container login-wrap">
  <div class="card shadow-sm mx-auto" style="max-width:380px">
    <div class="card-body p-4">
      <h1 class="h4 text-center mb-1"><?= e(APP_NAME) ?></h1>
      <p class="text-center text-muted mb-4">Login Staff</p>
      <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label" for="username">Username</label>
          <input class="form-control form-control-lg" id="username" name="username" maxlength="50" required autofocus>
        </div>
        <div class="mb-4">
          <label class="form-label" for="password">Password</label>
          <input class="form-control form-control-lg" type="password" id="password" name="password" required>
        </div>
        <button class="btn btn-primary btn-lg w-100">Login</button>
      </form>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
