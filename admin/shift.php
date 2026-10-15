<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/shift.php';
require_permission(['shift.manage', 'shift.override']);

$pdo = db();
$me = current_user();
$return = isset($_GET['return']) && preg_match('#^pos\.php(\?order=\d+)?$#', $_GET['return']) ? $_GET['return'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $self = 'shift.php' . ($return ? '?return=' . rawurlencode($return) : '');
    if (!csrf_valid()) {
        flash_set('err', 'Sesi tidak valid, coba lagi.');
        redirect($self);
    }
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    try {
        if ($action === 'open') {
            require_permission('shift.manage');
            $cash = filter_var(isset($_POST['opening_cash']) ? $_POST['opening_cash'] : '', FILTER_VALIDATE_INT);
            if ($cash === false || $cash < 0 || $cash > 100000000) {
                throw new RuntimeException('Modal awal harus angka 0 - 100.000.000.');
            }
            if (open_shift_for($pdo, (int) $me['id'])) {
                throw new RuntimeException('Anda sudah memiliki shift yang terbuka.');
            }
            try {
                $pdo->prepare("INSERT INTO cash_shifts (user_id, status, opened_at, opening_cash) VALUES (?, 'OPEN', NOW(), ?)")->execute([$me['id'], $cash]);
            } catch (PDOException $ex) {
                throw new RuntimeException('Anda sudah memiliki shift yang terbuka.');   // unique (user_id, open_marker)
            }
            $sid = (int) $pdo->lastInsertId();
            audit('shift.open', 'shift', $sid, ['opening_cash' => $cash]);
            flash_set('ok', 'Shift dibuka dengan modal awal ' . rupiah($cash) . '.');
            redirect($return ?: 'shift.php');
        }
        $shift = open_shift_for($pdo, (int) $me['id']);
        if (!$shift) {
            throw new RuntimeException('Tidak ada shift terbuka.');
        }
        if ($action === 'movement') {
            $type = isset($_POST['type']) && in_array($_POST['type'], ['IN', 'OUT'], true) ? $_POST['type'] : null;
            $amt = filter_var(isset($_POST['amount']) ? $_POST['amount'] : '', FILTER_VALIDATE_INT);
            $note = clean_input(isset($_POST['note']) ? $_POST['note'] : '', 255);
            if (!$type || $amt === false || $amt < 1 || $amt > 100000000 || $note === '') {
                throw new RuntimeException('Jenis, nominal, dan keterangan kas wajib diisi.');
            }
            $pdo->prepare('INSERT INTO cash_movements (shift_id, type, amount, note, user_id) VALUES (?, ?, ?, ?, ?)')->execute([$shift['id'], $type, $amt, $note, $me['id']]);
            audit('shift.cash_' . strtolower($type), 'shift', (int) $shift['id'], ['amount' => $amt, 'note' => $note]);
            flash_set('ok', 'Kas ' . ($type === 'IN' ? 'masuk' : 'keluar') . ' ' . rupiah($amt) . ' dicatat.');
        } elseif ($action === 'close') {
            $counted = filter_var(isset($_POST['counted_cash']) ? $_POST['counted_cash'] : '', FILTER_VALIDATE_INT);
            $note = clean_input(isset($_POST['note']) ? $_POST['note'] : '', 500);
            if ($counted === false || $counted < 0 || $counted > 1000000000) {
                throw new RuntimeException('Masukkan jumlah uang tunai aktual di laci.');
            }
            $pdo->beginTransaction();
            $lk = $pdo->prepare("SELECT * FROM cash_shifts WHERE id = ? AND status = 'OPEN' FOR UPDATE");
            $lk->execute([$shift['id']]);
            $shift = $lk->fetch();
            if (!$shift) {
                $pdo->rollBack();
                throw new RuntimeException('Shift sudah ditutup.');
            }
            $blockers = shift_blockers($pdo, $shift);
            $overrideBy = null;
            $overrideReason = null;
            if ($blockers) {
                $overrideReason = clean_input(isset($_POST['override_reason']) ? $_POST['override_reason'] : '', 255);
                if ($overrideReason === '') {
                    $pdo->rollBack();
                    throw new RuntimeException('Shift belum dapat ditutup: ' . implode('; ', $blockers) . '. Selesaikan dulu, atau override dengan otorisasi manager + alasan.');
                }
                if (can('shift.override')) {
                    $overrideBy = (int) $me['id'];
                } else {
                    list($sup, $okPass, $okPerm) = check_supervisor((string) (isset($_POST['sup_user']) ? $_POST['sup_user'] : ''), (string) (isset($_POST['sup_pass']) ? $_POST['sup_pass'] : ''), 'shift.override');
                    if (!$okPass || !$okPerm) {
                        $pdo->rollBack();
                        $pdo->prepare('INSERT INTO login_attempts (ip) VALUES (?)')->execute([client_ip()]);
                        throw new RuntimeException('Otorisasi manager gagal.');
                    }
                    $overrideBy = (int) $sup['id'];
                }
            }
            $s = shift_summary($pdo, $shift);
            $pdo->prepare("UPDATE cash_shifts SET status = 'CLOSED', closed_at = NOW(), closed_by = ?, cash_sales = ?, noncash_sales = ?, refunds_cash = ?,
                           refunds_noncash = ?, cash_in = ?, cash_out = ?, expected_cash = ?, counted_cash = ?, difference = ?, note = ?,
                           override_by = ?, override_reason = ? WHERE id = ?")
                ->execute([$me['id'], $s['cash_sales'], $s['noncash_sales'], $s['refunds_cash'], $s['refunds_noncash'], $s['cash_in'], $s['cash_out'],
                           $s['expected_cash'], $counted, $counted - $s['expected_cash'], $note ?: null, $overrideBy, $overrideReason, $shift['id']]);
            $pdo->commit();
            audit('shift.close', 'shift', (int) $shift['id'], ['expected' => $s['expected_cash'], 'counted' => $counted, 'difference' => $counted - $s['expected_cash']]);
            if ($overrideBy) {
                audit('shift.override', 'shift', (int) $shift['id'], ['by' => $overrideBy, 'reason' => $overrideReason, 'blockers' => $blockers]);
            }
            flash_set('ok', 'Shift ditutup. Selisih kas: ' . rupiah($counted - $s['expected_cash']) . '.');
            redirect('shift-report.php?id=' . (int) $shift['id']);
        }
    } catch (RuntimeException $ex) {
        flash_set('err', $ex->getMessage());
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('shift: ' . $ex->getMessage());
        flash_set('err', 'Terjadi kesalahan sistem.');
    }
    redirect($self);
}

$shift = open_shift_for($pdo, (int) $me['id']);
$sum = $shift ? shift_summary($pdo, $shift) : null;
$blockers = $shift ? shift_blockers($pdo, $shift) : [];
$movements = [];
if ($shift) {
    $mv = $pdo->prepare('SELECT * FROM cash_movements WHERE shift_id = ? ORDER BY id DESC');
    $mv->execute([$shift['id']]);
    $movements = $mv->fetchAll();
}
// Riwayat: milik sendiri, atau semua kasir untuk shift.override
$all = can('shift.override');
$fUser = $all && isset($_GET['user']) ? (int) $_GET['user'] : 0;
$fFrom = parse_date_input(isset($_GET['from']) ? $_GET['from'] : '') ?: date('Y-m-d', strtotime('-7 days'));
$fTo = parse_date_input(isset($_GET['to']) ? $_GET['to'] : '') ?: date('Y-m-d');
$where = 'DATE(s.opened_at) BETWEEN ? AND ?';
$params = [$fFrom, $fTo];
if (!$all) {
    $where .= ' AND s.user_id = ?';
    $params[] = $me['id'];
} elseif ($fUser) {
    $where .= ' AND s.user_id = ?';
    $params[] = $fUser;
}
$hs = $pdo->prepare("SELECT s.*, u.name FROM cash_shifts s JOIN users u ON u.id = s.user_id WHERE $where ORDER BY s.id DESC LIMIT 200");
$hs->execute($params);
$history = $hs->fetchAll();
$cashiers = $all ? $pdo->query('SELECT DISTINCT u.id, u.name FROM cash_shifts s JOIN users u ON u.id = s.user_id ORDER BY u.name')->fetchAll() : [];

$page_title = 'Shift Kasir';
$base = '../';
$admin = true;
include __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid py-3" style="max-width:1100px">
  <h1 class="h4 mb-3"><i class="bi bi-cash-stack"></i> Shift Kasir</h1>
<?php if (!$shift): ?>
  <?php if (can('shift.manage')): ?>
  <div class="card mb-4" style="max-width:480px"><div class="card-body">
    <h2 class="h5">Buka Shift</h2>
    <p class="text-muted small">Kasir: <strong><?= e($me['name']) ?></strong> · <?= e(date('d/m/Y H:i')) ?></p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="open">
      <label class="form-label" for="opening_cash">Modal awal (uang tunai di laci)</label>
      <div class="input-group mb-3"><span class="input-group-text">Rp</span>
        <input class="form-control form-control-lg" type="number" min="0" step="1" name="opening_cash" id="opening_cash" required inputmode="numeric"></div>
      <button class="btn btn-dark btn-lg w-100">Buka Shift</button>
    </form>
  </div></div>
  <?php endif; ?>
<?php else: ?>
  <div class="alert alert-success d-flex flex-wrap justify-content-between gap-2">
    <span>Shift <strong>#<?= (int) $shift['id'] ?></strong> terbuka sejak <?= e(date('d/m/Y H:i', strtotime($shift['opened_at']))) ?></span>
    <a class="btn btn-sm btn-success" href="pos.php"><i class="bi bi-calculator"></i> Ke POS</a>
  </div>
  <div class="row g-2 mb-3">
    <?php foreach ([['Modal awal', $sum['opening_cash']], ['Penjualan tunai', $sum['cash_sales']], ['Penjualan non-tunai', $sum['noncash_sales']],
                    ['Refund', $sum['refunds_cash'] + $sum['refunds_noncash']], ['Kas masuk / keluar', $sum['cash_in'] - $sum['cash_out']],
                    ['Uang tunai seharusnya', $sum['expected_cash']]] as $k): ?>
      <div class="col-6 col-md-4 col-xl-2"><div class="card h-100"><div class="card-body py-2">
        <div class="small text-muted"><?= e($k[0]) ?></div><div class="fs-5 fw-bold"><?= e(rupiah($k[1])) ?></div></div></div></div>
    <?php endforeach; ?>
  </div>
  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card h-100"><div class="card-body">
        <h2 class="h6">Per metode pembayaran (<?= (int) $sum['transactions'] ?> transaksi)</h2>
        <table class="table table-sm mb-0"><tbody>
          <?php foreach ($sum['by_method'] as $m): ?><tr><td><?= e($m['name']) ?></td><td class="text-end"><?= e(rupiah($m['payments'])) ?></td><td class="text-end text-danger"><?= $m['refunds'] ? '−' . e(rupiah($m['refunds'])) : '' ?></td></tr><?php endforeach; ?>
          <?php if (!$sum['by_method']): ?><tr><td class="text-muted">Belum ada pembayaran.</td></tr><?php endif; ?>
        </tbody></table>
        <h2 class="h6 mt-3">Kas masuk / keluar</h2>
        <form method="post" class="row g-2 mb-2"><?= csrf_field() ?><input type="hidden" name="action" value="movement">
          <div class="col-4"><select class="form-select" name="type" aria-label="Jenis"><option value="IN">Masuk</option><option value="OUT">Keluar</option></select></div>
          <div class="col-8"><input class="form-control" type="number" name="amount" min="1" placeholder="Nominal" required aria-label="Nominal"></div>
          <div class="col-9"><input class="form-control" name="note" maxlength="255" placeholder="Keterangan (wajib), mis. beli es batu" required aria-label="Keterangan"></div>
          <div class="col-3"><button class="btn btn-outline-dark w-100">Catat</button></div>
        </form>
        <ul class="list-group list-group-flush small">
          <?php foreach ($movements as $m): ?><li class="list-group-item px-0 d-flex justify-content-between"><span><?= e(date('H:i', strtotime($m['created_at']))) ?> · <?= e($m['note']) ?></span><span class="<?= $m['type'] === 'IN' ? 'text-success' : 'text-danger' ?>"><?= $m['type'] === 'IN' ? '+' : '−' ?><?= e(rupiah($m['amount'])) ?></span></li><?php endforeach; ?>
        </ul>
      </div></div>
    </div>
    <div class="col-lg-6">
      <div class="card h-100 border-dark"><div class="card-body">
        <h2 class="h5">Tutup Shift</h2>
        <?php if ($blockers): ?>
          <div class="alert alert-warning small"><strong>Perlu diselesaikan sebelum tutup shift:</strong>
            <ul class="mb-0"><?php foreach ($blockers as $b): ?><li><?= e($b) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <form method="post" onsubmit="return confirm('Tutup shift sekarang? Angka shift akan dikunci.')"><?= csrf_field() ?><input type="hidden" name="action" value="close">
          <div class="mb-2 small">Uang tunai seharusnya: <strong id="exp" data-v="<?= (int) $sum['expected_cash'] ?>"><?= e(rupiah($sum['expected_cash'])) ?></strong></div>
          <label class="form-label" for="counted_cash">Uang tunai aktual (hasil hitung)</label>
          <div class="input-group mb-2"><span class="input-group-text">Rp</span><input class="form-control form-control-lg" type="number" min="0" name="counted_cash" id="counted_cash" required inputmode="numeric"></div>
          <div class="small mb-2" id="diff" aria-live="polite"></div>
          <label class="form-label" for="note">Catatan kasir</label>
          <textarea class="form-control mb-3" name="note" id="note" maxlength="500" rows="2"></textarea>
          <?php if ($blockers): ?>
            <fieldset class="border rounded p-2 mb-3"><legend class="float-none w-auto px-1 small fw-semibold">Override (Manager)</legend>
              <input class="form-control mb-2" name="override_reason" maxlength="255" placeholder="Alasan override (wajib)" aria-label="Alasan override">
              <?php if (!can('shift.override')): ?>
                <input class="form-control mb-2" name="sup_user" autocomplete="off" placeholder="Username manager" aria-label="Username manager">
                <input class="form-control" type="password" name="sup_pass" autocomplete="new-password" placeholder="Password manager" aria-label="Password manager">
              <?php endif; ?>
            </fieldset>
          <?php endif; ?>
          <button class="btn btn-dark btn-lg w-100">Tutup Shift</button>
        </form>
      </div></div>
    </div>
  </div>
  <script>
    (function () { var c = document.getElementById('counted_cash'), d = document.getElementById('diff'), e = +document.getElementById('exp').getAttribute('data-v');
      c.addEventListener('input', function () { if (c.value === '') { d.textContent = ''; return; } var x = +c.value - e;
        d.textContent = 'Selisih: ' + (x < 0 ? '-' : '') + 'Rp' + Math.abs(x).toLocaleString('id-ID') + (x === 0 ? ' (sesuai)' : (x < 0 ? ' (kurang)' : ' (lebih)'));
        d.className = 'small mb-2 fw-bold ' + (x === 0 ? 'text-success' : 'text-danger'); }); })();
  </script>
<?php endif; ?>

  <h2 class="h5 mt-4">Riwayat Shift<?= $all ? ' (semua kasir)' : '' ?></h2>
  <form class="row g-2 mb-2" method="get">
    <div class="col-6 col-md-3"><input class="form-control" type="date" name="from" value="<?= e($fFrom) ?>" aria-label="Dari"></div>
    <div class="col-6 col-md-3"><input class="form-control" type="date" name="to" value="<?= e($fTo) ?>" aria-label="Sampai"></div>
    <?php if ($all): ?><div class="col-8 col-md-4"><select class="form-select" name="user" aria-label="Kasir"><option value="0">Semua kasir</option>
      <?php foreach ($cashiers as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $fUser === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <div class="col-4 col-md-2"><button class="btn btn-outline-dark w-100">Filter</button></div>
  </form>
  <div class="table-responsive"><table class="table table-sm align-middle bg-white">
    <thead><tr><th>#</th><th>Kasir</th><th>Buka</th><th>Tutup</th><th class="text-end">Penjualan</th><th class="text-end">Selisih kas</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($history as $h): ?>
      <tr><td><?= (int) $h['id'] ?></td><td><?= e($h['name']) ?></td><td><?= e(date('d/m H:i', strtotime($h['opened_at']))) ?></td>
        <td><?= $h['closed_at'] ? e(date('d/m H:i', strtotime($h['closed_at']))) : '<span class="badge text-bg-success">OPEN</span>' ?></td>
        <td class="text-end"><?= $h['status'] === 'CLOSED' ? e(rupiah($h['cash_sales'] + $h['noncash_sales'])) : '-' ?></td>
        <td class="text-end <?= (int) $h['difference'] < 0 ? 'text-danger' : '' ?>"><?= $h['status'] === 'CLOSED' ? e(rupiah($h['difference'])) : '-' ?><?= $h['override_by'] ? ' <span class="badge text-bg-warning">override</span>' : '' ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="shift-report.php?id=<?= (int) $h['id'] ?>">Laporan</a></td></tr>
    <?php endforeach; ?>
    <?php if (!$history): ?><tr><td colspan="7" class="text-muted text-center py-3">Belum ada shift.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
