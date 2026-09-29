<?php
/**
 * Variabel: $page_title, $base (prefix path ke root, '' atau '../'),
 *           $page (nama halaman untuk app.js), $body_attrs (array data-*), $admin (bool)
 */
$base = isset($base) ? $base : '';
$page = isset($page) ? $page : '';
$body_attrs = isset($body_attrs) ? $body_attrs : [];
$admin = !empty($admin);
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(isset($page_title) ? $page_title . ' - ' : '') . e(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= $base ?>assets/vendor/bootstrap.min.css">
  <link rel="stylesheet" href="<?= $base ?>assets/css/style.css">
</head>
<body class="<?= $admin ? 'admin' : 'customer' ?>" data-page="<?= e($page) ?>" data-base="<?= e($base) ?>"
<?php foreach ($body_attrs as $k => $v): ?> data-<?= e($k) ?>="<?= e($v) ?>"<?php endforeach; ?>>
<?php if ($admin && is_logged_in()): ?>
<nav class="navbar navbar-expand navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand fw-semibold" href="dashboard.php"><?= e(APP_NAME) ?></a>
    <ul class="navbar-nav me-auto flex-row gap-3">
      <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
      <li class="nav-item"><a class="nav-link" href="orders.php">Pesanan</a></li>
      <li class="nav-item"><a class="nav-link" href="qr.php">QR Meja</a></li>
    </ul>
    <form method="post" action="logout.php" class="m-0">
      <?= csrf_field() ?>
      <button class="btn btn-outline-light btn-sm">Logout</button>
    </form>
  </div>
</nav>
<?php endif; ?>
