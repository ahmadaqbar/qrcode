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
<?php
$admin_shell = $admin && is_logged_in();
if ($admin_shell):
    $cur = basename($_SERVER['SCRIPT_NAME']);
    if ($cur === 'order-detail.php') { $cur = 'orders.php'; }
    $nav = [
        ['dashboard.php', 'Dashboard'], null,
        ['orders.php', 'Orders'], ['tables.php', 'Tables'], ['categories.php', 'Categories'], ['products.php', 'Products'], null,
        ['top-sellers.php', 'Top Seller'], ['promos.php', 'Promo Banner'], ['flyers.php', 'New Menu Flyer'], null,
        ['qr.php', 'QR Code'], ['account.php', 'Settings'],
    ];
?>
<div class="admin-shell">
  <nav class="navbar navbar-dark bg-dark d-lg-none px-3">
    <button class="btn btn-outline-light btn-sm" type="button" data-bs-toggle="offcanvas" data-bs-target="#adminNav" aria-controls="adminNav">&#9776; Menu</button>
    <span class="navbar-brand fw-semibold m-0"><?= e(APP_NAME) ?></span>
  </nav>
  <aside class="offcanvas-lg offcanvas-start admin-sidebar bg-dark text-white" tabindex="-1" id="adminNav" aria-label="Navigasi admin">
    <div class="offcanvas-header">
      <span class="fw-semibold"><?= e(APP_NAME) ?></span>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#adminNav" aria-label="Tutup"></button>
    </div>
    <div class="offcanvas-body flex-column p-0">
      <a class="side-brand d-none d-lg-block" href="dashboard.php"><?= e(APP_NAME) ?></a>
      <ul class="nav flex-column side-nav">
        <?php foreach ($nav as $n): if ($n === null): ?>
          <li class="side-sep" role="separator"></li>
        <?php else: ?>
          <li><a class="side-link<?= $cur === $n[0] ? ' active' : '' ?>" href="<?= e($n[0]) ?>"><?= e($n[1]) ?></a></li>
        <?php endif; endforeach; ?>
        <li class="side-sep" role="separator"></li>
        <li>
          <form method="post" action="logout.php" class="m-0"><?= csrf_field() ?>
            <button class="side-link w-100 text-start">Logout</button></form>
        </li>
      </ul>
    </div>
  </aside>
  <div class="admin-main">
<?php if ($f = flash_get()): ?>
<div class="container-fluid pt-3"><div class="alert alert-<?= $f['type'] === 'ok' ? 'success' : 'danger' ?> mb-0"><?= e($f['msg']) ?></div></div>
<?php endif; ?>
<?php endif; ?>
