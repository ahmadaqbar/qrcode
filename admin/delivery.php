<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/orders.php';
require_permission(['delivery.view', 'delivery.courier']);

$pdo = db();
$me = current_user();
$isCourierOnly = !can(['delivery.view']);
$canManage = can('delivery.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = 'delivery.php' . (!empty($_POST['qs']) && preg_match('/^[a-zA-Z0-9=&_%.-]*$/', $_POST['qs']) ? '?' . $_POST['qs'] : '');
    if (!csrf_valid()) {
        flash_set('err', 'Sesi tidak valid, coba lagi.');
        redirect($back);
    }
    $orderId = (int) filter_var(isset($_POST['order_id']) ? $_POST['order_id'] : 0, FILTER_VALIDATE_INT);
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT d.*, o.order_number, o.status AS order_status FROM delivery_details d JOIN orders o ON o.id = d.order_id WHERE d.order_id = ? FOR UPDATE');
        $st->execute([$orderId]);
        $d = $st->fetch();
        if (!$d) {
            throw new RuntimeException('Pesanan delivery tidak ditemukan.');
        }
        if ($action === 'assign') {
            if (!$canManage) {
                throw new RuntimeException('Anda tidak memiliki izin menugaskan kurir.');
            }
            $cid = (int) filter_var(isset($_POST['courier_id']) ? $_POST['courier_id'] : 0, FILTER_VALIDATE_INT);
            if (in_array($d['delivery_status'], ['DELIVERED', 'CANCELLED'], true)) {
                throw new RuntimeException('Pengiriman sudah selesai/dibatalkan.');
            }
            if ($cid) {
                $c = $pdo->prepare('SELECT u.name FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN role_permissions rp ON rp.role_id = r.id AND rp.permission = \'delivery.courier\'
                                    WHERE u.id = ? AND u.is_active = 1 AND (rp.permission IS NOT NULL OR r.code = \'ADMIN\')');
                $c->execute([$cid]);
                $cname = $c->fetchColumn();
                if (!$cname) {
                    throw new RuntimeException('User tersebut bukan petugas pengantar.');
                }
            }
            $pdo->prepare('UPDATE delivery_details SET courier_id = ? WHERE order_id = ?')->execute([$cid ?: null, $orderId]);
            $pdo->prepare('INSERT INTO delivery_status_history (order_id, from_status, to_status, user_id, note) VALUES (?, ?, ?, ?, ?)')
                ->execute([$orderId, $d['delivery_status'], $d['delivery_status'], $me['id'], $cid ? 'Kurir: ' . $cname : 'Kurir dilepas']);
            audit('delivery.assign', 'order', $orderId, ['courier_id' => $cid ?: null]);
            $msg = $cid ? 'Kurir ' . $cname . ' ditugaskan ke ' . $d['order_number'] . '.' : 'Kurir dilepas.';
        } elseif ($action === 'status') {
            $to = isset($_POST['to']) ? (string) $_POST['to'] : '';
            if (!in_array($to, DELIVERY_TRANSITIONS[$d['delivery_status']], true)) {
                throw new RuntimeException('Status sudah berubah atau tidak valid (' . DELIVERY_STATUS_LABELS[$d['delivery_status']] . ').');
            }
            if ($d['order_status'] === 'CANCELLED') {
                throw new RuntimeException('Pesanan dibatalkan.');
            }
            $mine = (int) $d['courier_id'] === (int) $me['id'];
            $courierStep = in_array($to, ['OUT_FOR_DELIVERY', 'DELIVERED'], true);
            if (!$canManage && !($mine && $courierStep && can('delivery.courier'))) {
                throw new RuntimeException('Anda hanya dapat memperbarui pengiriman yang ditugaskan kepada Anda.');
            }
            if ($to === 'OUT_FOR_DELIVERY' && !$d['courier_id']) {
                throw new RuntimeException('Tugaskan kurir terlebih dahulu.');
            }
            $note = clean_input(isset($_POST['note']) ? $_POST['note'] : '', 255);
            delivery_advance($pdo, $orderId, $d['delivery_status'], $to, $note ?: 'Status diperbarui', (int) $me['id']);
            audit('delivery.status', 'order', $orderId, ['from' => $d['delivery_status'], 'to' => $to]);
            $msg = $d['order_number'] . ': ' . DELIVERY_STATUS_LABELS[$to] . '.';
        } else {
            throw new RuntimeException('Aksi tidak dikenal.');
        }
        $pdo->commit();
        flash_set('ok', $msg);
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash_set('err', $ex->getMessage());
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('delivery: ' . $ex->getMessage());
        flash_set('err', 'Terjadi kesalahan sistem.');
    }
    redirect($back);
}

// Filter
$fStatus = isset($_GET['status']) && (isset(DELIVERY_STATUS_LABELS[$_GET['status']]) || $_GET['status'] === 'ACTIVE') ? $_GET['status'] : 'ACTIVE';
$fFrom = parse_date_input(isset($_GET['from']) ? $_GET['from'] : '') ?: date('Y-m-d');
$fTo = parse_date_input(isset($_GET['to']) ? $_GET['to'] : '') ?: date('Y-m-d');
$fCourier = isset($_GET['courier']) ? (int) $_GET['courier'] : 0;
$where = ['DATE(o.created_at) BETWEEN ? AND ?'];
$params = [$fFrom, $fTo];
if ($fStatus === 'ACTIVE') {
    $where[] = "d.delivery_status NOT IN ('DELIVERED','CANCELLED')";
} else {
    $where[] = 'd.delivery_status = ?';
    $params[] = $fStatus;
}
if ($isCourierOnly) {
    $where[] = 'd.courier_id = ?';
    $params[] = $me['id'];
} elseif ($fCourier) {
    $where[] = 'd.courier_id = ?';
    $params[] = $fCourier;
}
$st = $pdo->prepare('SELECT d.*, o.order_number, o.total, o.paid_amount, o.payment_status, o.status AS order_status, o.created_at, u.name AS courier_name
                     FROM delivery_details d JOIN orders o ON o.id = d.order_id LEFT JOIN users u ON u.id = d.courier_id
                     WHERE ' . implode(' AND ', $where) . ' ORDER BY o.id DESC LIMIT 200');
$st->execute($params);
$rows = $st->fetchAll();
$hist = [];
if ($rows) {
    $ids = array_column($rows, 'order_id');
    $h = $pdo->prepare('SELECT h.*, u.name FROM delivery_status_history h LEFT JOIN users u ON u.id = h.user_id WHERE h.order_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY h.id');
    $h->execute($ids);
    foreach ($h->fetchAll() as $r) {
        $hist[$r['order_id']][] = $r;
    }
}
$couriers = $pdo->query("SELECT DISTINCT u.id, u.name FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN role_permissions rp ON rp.role_id = r.id
                         WHERE u.is_active = 1 AND rp.permission = 'delivery.courier' ORDER BY u.name")->fetchAll();
$qs = http_build_query(['status' => $fStatus, 'from' => $fFrom, 'to' => $fTo, 'courier' => $fCourier]);
$badge = ['NEW' => 'secondary', 'CONFIRMED' => 'primary', 'PREPARING' => 'warning', 'READY_FOR_PICKUP' => 'info', 'OUT_FOR_DELIVERY' => 'dark', 'DELIVERED' => 'success', 'CANCELLED' => 'danger'];

$page_title = 'Delivery';
$base = '../';
$admin = true;
include __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid py-3">
  <h1 class="h4 mb-3"><i class="bi bi-bicycle"></i> Delivery <?= $isCourierOnly ? '<small class="text-muted fs-6">— tugas saya</small>' : '' ?></h1>
  <form class="row g-2 mb-3" method="get">
    <div class="col-6 col-md-3"><select class="form-select" name="status" aria-label="Status">
      <option value="ACTIVE">Aktif (belum selesai)</option>
      <?php foreach (DELIVERY_STATUS_LABELS as $k => $v): ?><option value="<?= e($k) ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><input class="form-control" type="date" name="from" value="<?= e($fFrom) ?>" aria-label="Dari tanggal"></div>
    <div class="col-6 col-md-2"><input class="form-control" type="date" name="to" value="<?= e($fTo) ?>" aria-label="Sampai tanggal"></div>
    <?php if (!$isCourierOnly): ?><div class="col-6 col-md-3"><select class="form-select" name="courier" aria-label="Kurir"><option value="0">Semua kurir</option>
      <?php foreach ($couriers as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $fCourier === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <div class="col-12 col-md-2"><button class="btn btn-dark w-100">Filter</button></div>
  </form>

  <div class="row g-3">
  <?php foreach ($rows as $r): $next = DELIVERY_TRANSITIONS[$r['delivery_status']]; $due = (int) $r['total'] - (int) $r['paid_amount']; ?>
    <div class="col-12 col-md-6 col-xl-4">
      <div class="card h-100 delivery-card"><div class="card-body d-flex flex-column">
        <div class="d-flex justify-content-between align-items-start mb-2">
          <div><div class="fw-bold"><?= e($r['order_number']) ?></div><div class="small text-muted"><?= e(date('d/m H:i', strtotime($r['created_at']))) ?> · <?= e($r['source']) ?></div></div>
          <span class="badge text-bg-<?= e($badge[$r['delivery_status']]) ?>"><?= e(DELIVERY_STATUS_LABELS[$r['delivery_status']]) ?></span>
        </div>
        <div class="fw-semibold"><?= e($r['customer_name']) ?> · <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $r['phone'])) ?>"><?= e($r['phone']) ?></a></div>
        <div class="small text-break"><?= e($r['address']) ?></div>
        <?php if ($r['landmark']): ?><div class="small text-muted">Patokan: <?= e($r['landmark']) ?></div><?php endif; ?>
        <?php if ($r['delivery_note']): ?><div class="small fst-italic">“<?= e($r['delivery_note']) ?>”</div><?php endif; ?>
        <div class="d-flex justify-content-between align-items-center my-2">
          <span class="fw-bold"><?= e(rupiah($r['total'])) ?></span>
          <span class="badge <?= $r['payment_status'] === 'PAID' ? 'text-bg-success' : 'text-bg-warning' ?>"><?= e(PAYMENT_STATUS_LABELS[$r['payment_status']]) ?><?= $due > 0 && $r['payment_status'] !== 'REFUNDED' ? ' · tagih ' . e(rupiah($due)) : '' ?></span>
        </div>
        <div class="small mb-2">Kurir: <strong><?= e($r['courier_name'] ?: '-') ?></strong></div>
        <div class="mt-auto d-grid gap-2">
          <?php if ($canManage && !in_array($r['delivery_status'], ['DELIVERED', 'CANCELLED'], true)): ?>
            <form method="post" class="input-group input-group-sm"><?= csrf_field() ?><input type="hidden" name="qs" value="<?= e($qs) ?>"><input type="hidden" name="action" value="assign"><input type="hidden" name="order_id" value="<?= (int) $r['order_id'] ?>">
              <select class="form-select" name="courier_id" aria-label="Tugaskan kurir <?= e($r['order_number']) ?>"><option value="0">— Pilih kurir —</option>
                <?php foreach ($couriers as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $r['courier_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
              <button class="btn btn-outline-dark">Tugaskan</button></form>
          <?php endif; ?>
          <?php foreach ($next as $to): if (!$canManage && !((int) $r['courier_id'] === (int) $me['id'] && in_array($to, ['OUT_FOR_DELIVERY', 'DELIVERED'], true))) { continue; } ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="qs" value="<?= e($qs) ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="order_id" value="<?= (int) $r['order_id'] ?>"><input type="hidden" name="to" value="<?= e($to) ?>">
              <button class="btn <?= $to === 'DELIVERED' ? 'btn-success' : 'btn-dark' ?> w-100">→ <?= e(DELIVERY_STATUS_LABELS[$to]) ?></button></form>
          <?php endforeach; ?>
          <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-secondary flex-fill" target="_blank" rel="noopener" href="receipt.php?id=<?= (int) $r['order_id'] ?>&amp;type=delivery"><i class="bi bi-printer"></i> Struk</a>
            <button class="btn btn-sm btn-outline-secondary flex-fill" type="button" data-bs-toggle="collapse" data-bs-target="#h<?= (int) $r['order_id'] ?>" aria-expanded="false">Riwayat</button>
          </div>
          <div class="collapse" id="h<?= (int) $r['order_id'] ?>"><ul class="list-group list-group-flush small">
            <?php foreach (isset($hist[$r['order_id']]) ? $hist[$r['order_id']] : [] as $h): ?>
              <li class="list-group-item px-0"><?= e(date('d/m H:i', strtotime($h['created_at']))) ?> · <strong><?= e(DELIVERY_STATUS_LABELS[$h['to_status']]) ?></strong> · <?= e($h['name'] ?: 'sistem') ?><?= $h['note'] ? ' — ' . e($h['note']) : '' ?></li>
            <?php endforeach; ?>
          </ul></div>
        </div>
      </div></div>
    </div>
  <?php endforeach; ?>
  <?php if (!$rows): ?><p class="text-muted">Tidak ada pesanan delivery.</p><?php endif; ?>
  </div>
  <p class="small text-muted mt-3">Pelacakan GPS real-time tidak tersedia. Pembayaran COD dicatat kasir di POS setelah kurir kembali.</p>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
