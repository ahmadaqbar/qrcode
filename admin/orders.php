<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$filters = ['ALL' => 'Semua', 'NEW' => 'Baru', 'PROCESSING' => 'Diproses', 'COMPLETED' => 'Selesai', 'CANCELLED' => 'Dibatalkan'];
$f = isset($_GET['status']) ? strtoupper((string) $_GET['status']) : 'ALL';
if (!isset($filters[$f])) {
    $f = 'ALL';
}

try {
    $sql = 'SELECT o.*, t.table_number FROM orders o JOIN tables t ON t.id = o.table_id'
         . ($f === 'ALL' ? '' : ' WHERE o.status = ?') . ' ORDER BY o.id DESC LIMIT 200';
    $st = db()->prepare($sql);
    $st->execute($f === 'ALL' ? [] : [$f]);
    $orders = $st->fetchAll();
    $items = fetch_items_for_orders(array_column($orders, 'id'));
} catch (Throwable $ex) {
    error_log($ex->getMessage());
    $orders = [];
    $items = [];
    $dbError = true;
}

$badge = ['NEW' => 'danger', 'PROCESSING' => 'warning', 'COMPLETED' => 'success', 'CANCELLED' => 'secondary'];
$page_title = 'Pesanan';
$base = '../';
$admin = true;
include __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid py-3">
  <h1 class="h4 mb-3">Daftar Pesanan</h1>
  <div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach ($filters as $k => $label): ?>
      <a class="btn btn-sm <?= $f === $k ? 'btn-dark' : 'btn-outline-dark' ?>" href="orders.php?status=<?= e($k) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
  <?php if (!empty($dbError)): ?><div class="alert alert-danger">Gagal memuat data.</div><?php endif; ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle bg-white">
      <thead><tr><th>Order</th><th>Meja</th><th>Waktu</th><th>Item</th><th class="text-end">Total</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($orders as $o):
          $its = isset($items[$o['id']]) ? $items[$o['id']] : [];
          $summary = implode(', ', array_map(function ($i) { return $i['product_name'] . ' x' . $i['quantity']; }, $its)); ?>
        <tr>
          <td class="fw-semibold"><?= e($o['order_number']) ?></td>
          <td><?= e($o['table_number']) ?></td>
          <td><?= e(date('d/m H:i', strtotime($o['created_at']))) ?></td>
          <td class="small text-muted"><?= e(mb_strimwidth($summary, 0, 60, '…', 'UTF-8')) ?></td>
          <td class="text-end"><?= e(rupiah($o['total'])) ?></td>
          <td><span class="badge text-bg-<?= $badge[$o['status']] ?>"><?= e(status_label($o['status'])) ?></span></td>
          <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="order-detail.php?id=<?= (int) $o['id'] ?>">Detail</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$orders): ?><tr><td colspan="7" class="text-center text-muted py-4">Tidak ada pesanan.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
