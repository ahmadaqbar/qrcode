<?php
require_once __DIR__ . '/../includes/auth.php';
require_permission('audit.view');

$pdo = db();
$fAction = isset($_GET['action']) ? preg_replace('/[^a-z_.]/', '', (string) $_GET['action']) : '';
$fUser = isset($_GET['user']) ? (int) $_GET['user'] : 0;
$fFrom = parse_date_input(isset($_GET['from']) ? $_GET['from'] : '') ?: date('Y-m-d', strtotime('-7 days'));
$fTo = parse_date_input(isset($_GET['to']) ? $_GET['to'] : '') ?: date('Y-m-d');
$where = ['DATE(a.created_at) BETWEEN ? AND ?'];
$params = [$fFrom, $fTo];
if ($fAction !== '') {
    $where[] = 'a.action LIKE ?';
    $params[] = $fAction . '%';
}
if ($fUser) {
    $where[] = 'a.user_id = ?';
    $params[] = $fUser;
}
$st = $pdo->prepare('SELECT a.*, u.name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE ' . implode(' AND ', $where) . ' ORDER BY a.id DESC LIMIT 300');
$st->execute($params);
$rows = $st->fetchAll();
$actions = $pdo->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$users = $pdo->query('SELECT id, name FROM users ORDER BY name')->fetchAll();

$page_title = 'Audit Log';
$base = '../';
$admin = true;
include __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid py-3">
  <h1 class="h4"><i class="bi bi-journal-text"></i> Audit Log</h1>
  <form class="row g-2 mb-3" method="get">
    <div class="col-6 col-md-3"><select class="form-select" name="action" aria-label="Aksi"><option value="">Semua aksi</option>
      <?php foreach ($actions as $a): ?><option value="<?= e($a) ?>" <?= $fAction === $a ? 'selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-3"><select class="form-select" name="user" aria-label="User"><option value="0">Semua user</option>
      <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $fUser === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><input class="form-control" type="date" name="from" value="<?= e($fFrom) ?>" aria-label="Dari"></div>
    <div class="col-6 col-md-2"><input class="form-control" type="date" name="to" value="<?= e($fTo) ?>" aria-label="Sampai"></div>
    <div class="col-12 col-md-2"><button class="btn btn-dark w-100">Filter</button></div>
  </form>
  <div class="table-responsive"><table class="table table-sm align-middle bg-white">
    <thead><tr><th>Waktu</th><th>User</th><th>Aksi</th><th>Objek</th><th>Detail</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td class="text-nowrap small"><?= e(date('d/m H:i:s', strtotime($r['created_at']))) ?></td><td class="small"><?= e($r['name'] ?: '-') ?></td>
        <td><code><?= e($r['action']) ?></code></td><td class="small"><?= e($r['entity']) ?><?= $r['entity_id'] ? ' #' . (int) $r['entity_id'] : '' ?></td>
        <td class="small text-break" style="max-width:480px"><?= e($r['detail']) ?></td><td class="small text-muted"><?= e($r['ip']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="text-muted text-center py-3">Tidak ada catatan.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
