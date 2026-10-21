<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$id = filter_var(isset($_GET['id']) ? $_GET['id'] : null, FILTER_VALIDATE_INT);
$order = null;
if ($id !== false && $id > 0) {
    try {
        $st = db()->prepare('SELECT o.*, t.table_number FROM orders o JOIN tables t ON t.id = o.table_id WHERE o.id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            $items = fetch_items_for_orders([$row['id']]);
            $order = format_order($row, isset($items[$row['id']]) ? $items[$row['id']] : []);
        }
    } catch (Throwable $ex) {
        error_log($ex->getMessage());
    }
}
if (!$order) {
    http_response_code(404);
}

$badge = ['NEW' => 'danger', 'PROCESSING' => 'warning', 'COMPLETED' => 'success', 'CANCELLED' => 'secondary'];
$page_title = 'Detail Pesanan';
$base = '../';
$admin = true;
$page = 'order-detail';
$body_attrs = ['csrf' => csrf_token()];
include __DIR__ . '/../includes/header.php';
?>
<div class="container py-3" style="max-width:720px">
  <a href="orders.php" class="btn btn-sm btn-outline-secondary mb-3">&larr; Kembali</a>
<?php if (!$order): ?>
  <div class="alert alert-warning">Pesanan tidak ditemukan.</div>
<?php else: ?>
  <div class="card"><div class="card-body">
    <div class="d-flex justify-content-between align-items-start mb-3">
      <div>
        <div class="small text-muted">Order Number</div>
        <div class="fs-4 fw-bold"><?= e($order['order_number']) ?></div>
      </div>
      <span class="badge fs-6 text-bg-<?= $badge[$order['status']] ?>" id="order-status"><?= e($order['status_label']) ?></span>
    </div>
    <div class="row g-3 mb-3">
      <div class="col-4"><div class="small text-muted">Table Number</div><div class="fw-semibold"><?= e($order['table_number']) ?></div></div>
      <div class="col-4"><div class="small text-muted">Time</div><div class="fw-semibold"><?= e(date('d/m/Y H:i', strtotime($order['created_at']))) ?></div></div>
      <div class="col-4"><div class="small text-muted">Customer Name</div><div class="fw-semibold"><?= e($order['customer_name'] ?: '-') ?></div></div>
    </div>
    <table class="table table-sm">
      <thead><tr><th>Items</th><th class="text-center">Quantity</th><th class="text-end">Subtotal</th></tr></thead>
      <tbody>
      <?php foreach ($order['items'] as $i): ?>
        <tr><td><?= e($i['name']) ?><div class="small text-muted"><?= e($i['price_text']) ?></div></td>
            <td class="text-center"><?= (int) $i['quantity'] ?></td><td class="text-end"><?= e($i['subtotal_text']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr class="fw-bold"><td colspan="2">Total</td><td class="text-end"><?= e($order['total_text']) ?></td></tr></tfoot>
    </table>
    <div class="mb-4"><div class="small text-muted">Notes</div><div class="text-break"><?= nl2br(e($order['note'] ?: '-')) ?></div></div>

    <div class="d-grid gap-2" id="actions" data-order-id="<?= (int) $order['id'] ?>">
      <?php if ($order['status'] === 'NEW'): ?>
        <button class="btn btn-success btn-lg" data-set-status="PROCESSING">TERIMA PESANAN</button>
        <button class="btn btn-outline-danger" data-set-status="CANCELLED" data-confirm="Batalkan pesanan ini?">BATALKAN PESANAN</button>
      <?php elseif ($order['status'] === 'PROCESSING'): ?>
        <button class="btn btn-primary btn-lg" data-set-status="COMPLETED">SELESAIKAN PESANAN</button>
      <?php endif; ?>
    </div>
    <div id="action-error" class="alert alert-danger mt-3 d-none"></div>
  </div></div>
<?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
