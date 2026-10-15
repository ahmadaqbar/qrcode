<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/integration.php';
require_permission('integration.manage');

$pdo = db();
$me = current_user();
$keys = accurate_mapping_keys($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        flash_set('err', 'Sesi tidak valid, coba lagi.');
        redirect('accurate.php');
    }
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    try {
        if ($action === 'config') {
            $mode = isset($_POST['mode']) && in_array($_POST['mode'], ['off', 'simulation', 'bridge'], true) ? $_POST['mode'] : 'off';
            $on = isset($_POST['send_on']) && in_array($_POST['send_on'], ['paid', 'manual'], true) ? $_POST['send_on'] : 'paid';
            $sim = isset($_POST['sim_behavior']) && in_array($_POST['sim_behavior'], ['ok', 'fail', 'reject', 'timeout_after_commit'], true) ? $_POST['sim_behavior'] : 'ok';
            $old = ['mode' => setting('accurate_mode'), 'send_on' => setting('accurate_send_on'), 'sim' => setting('accurate_sim_behavior')];
            set_setting('accurate_mode', $mode);
            set_setting('accurate_send_on', $on);
            set_setting('accurate_sim_behavior', $sim);
            audit('integration.config', 'settings', null, ['from' => $old, 'to' => ['mode' => $mode, 'send_on' => $on, 'sim' => $sim]]);
            flash_set('ok', 'Konfigurasi integrasi disimpan.');
        } elseif ($action === 'mapping') {
            $in = isset($_POST['map']) && is_array($_POST['map']) ? $_POST['map'] : [];
            $st = $pdo->prepare('INSERT INTO accurate_account_mapping (map_key, map_value, updated_by) VALUES (?, ?, ?)
                                 ON DUPLICATE KEY UPDATE map_value = VALUES(map_value), updated_by = VALUES(updated_by)');
            $changed = [];
            $cur = accurate_mapping($pdo);
            foreach ($keys as $k => $label) {
                $v = clean_input(isset($in[$k]) ? $in[$k] : '', 100);
                if ($v !== '' && !preg_match('/^[A-Za-z0-9._\/ -]{1,100}$/', $v)) {
                    throw new RuntimeException('Kode untuk "' . $label . '" mengandung karakter tidak valid.');
                }
                if ((isset($cur[$k]) ? $cur[$k] : '') !== $v) {
                    $st->execute([$k, $v, $me['id']]);
                    $changed[$k] = $v;
                }
            }
            if ($changed) {
                audit('integration.mapping', 'settings', null, $changed);
            }
            flash_set('ok', $changed ? 'Mapping disimpan (' . count($changed) . ' perubahan).' : 'Tidak ada perubahan mapping.');
        } elseif ($action === 'process') {
            $r = integration_process($pdo, 20);
            audit('integration.process', 'integration', null, $r);
            flash_set('ok', isset($r['message']) ? $r['message'] : sprintf('Diproses %d: berhasil %d, dijadwalkan ulang %d, gagal %d.', $r['processed'], $r['success'], $r['retry'], $r['failed']));
        } elseif ($action === 'retry') {
            $id = (int) (isset($_POST['id']) ? $_POST['id'] : 0);
            // Retry aman: key sama; worker memverifikasi dulu sebelum mengirim ulang
            $st = $pdo->prepare("UPDATE integration_queue SET status = 'RETRY_SCHEDULED', next_attempt_at = NOW(), attempts = LEAST(attempts, 1), needs_verification = 1
                                 WHERE id = ? AND status IN ('FAILED','RETRY_SCHEDULED','PENDING')");
            $st->execute([$id]);
            if ($st->rowCount()) {
                integration_log($pdo, $id, 0, 'RETRY', 'MANUAL', null, 'Dijadwalkan ulang oleh ' . $me['name']);
                audit('integration.retry', 'integration', $id);
                flash_set('ok', 'Dijadwalkan ulang. Akan diverifikasi sebelum dikirim.');
            } else {
                flash_set('err', 'Item tidak dapat di-retry (sudah berhasil atau sedang diproses).');
            }
        } elseif ($action === 'enqueue_range') {
            $from = parse_date_input(isset($_POST['from']) ? $_POST['from'] : '');
            $to = parse_date_input(isset($_POST['to']) ? $_POST['to'] : '');
            if (!$from || !$to) {
                throw new RuntimeException('Isi rentang tanggal.');
            }
            if (setting('accurate_mode', 'off') === 'off') {
                throw new RuntimeException('Aktifkan mode integrasi terlebih dahulu.');
            }
            $ids = $pdo->prepare("SELECT o.id FROM orders o WHERE o.status = 'COMPLETED' AND o.payment_status IN ('PAID','REFUNDED')
                                  AND EXISTS (SELECT 1 FROM payments p WHERE p.order_id = o.id) AND DATE(o.closed_at) BETWEEN ? AND ?");
            $ids->execute([$from, $to]);
            $n = 0;
            foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $oid) {
                $n += integration_enqueue($pdo, (int) $oid, 'SALE', true) ? 1 : 0;
            }
            audit('integration.enqueue_range', 'integration', null, ['from' => $from, 'to' => $to, 'added' => $n]);
            flash_set('ok', $n . ' transaksi ditambahkan ke antrean (yang sudah ada tidak diduplikasi).');
        } elseif ($action === 'requeue_simulated') {
            if (setting('accurate_mode') !== 'bridge') {
                throw new RuntimeException('Hanya untuk mode bridge (live): transaksi yang "berhasil" di simulasi belum pernah masuk Accurate.');
            }
            $st = $pdo->prepare("UPDATE integration_queue SET status = 'PENDING', attempts = 0, needs_verification = 1, external_ref = NULL, succeeded_at = NULL,
                                 next_attempt_at = NOW(), response_message = 'Diantre ulang dari simulasi ke mode live.' WHERE status = 'SUCCESS' AND mode = 'simulation'");
            $st->execute();
            audit('integration.requeue_simulated', 'integration', null, ['count' => $st->rowCount()]);
            flash_set('ok', $st->rowCount() . ' transaksi hasil simulasi diantre ulang untuk mode live.');
        } elseif ($action === 'health') {
            $h = (new BridgeAdapter(accurate_bridge_config()))->health();
            flash_set($h['ok'] ? 'ok' : 'err', 'Tes koneksi bridge: ' . $h['message']);
        }
    } catch (RuntimeException $ex) {
        flash_set('err', $ex->getMessage());
    } catch (Throwable $ex) {
        error_log('accurate: ' . $ex->getMessage());
        flash_set('err', 'Terjadi kesalahan sistem.');
    }
    redirect('accurate.php');
}

$mode = setting('accurate_mode', 'off');
$cfg = accurate_bridge_config();
$map = accurate_mapping($pdo);
$missing = array_filter(array_keys($keys), function ($k) use ($map) { return !isset($map[$k]) || $map[$k] === ''; });
$noCode = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE is_active = 1 AND (accurate_item_code IS NULL OR accurate_item_code = '')")->fetchColumn();
$counts = $pdo->query('SELECT status, COUNT(*) FROM integration_queue GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$lastOk = $pdo->query("SELECT MAX(succeeded_at) FROM integration_queue WHERE status = 'SUCCESS'")->fetchColumn();
$lastRun = $pdo->query('SELECT MAX(last_attempt_at) FROM integration_queue')->fetchColumn();
$simSuccess = (int) $pdo->query("SELECT COUNT(*) FROM integration_queue WHERE status = 'SUCCESS' AND mode = 'simulation'")->fetchColumn();

$fStatus = isset($_GET['status']) && in_array($_GET['status'], ['PENDING', 'PROCESSING', 'SUCCESS', 'FAILED', 'RETRY_SCHEDULED'], true) ? $_GET['status'] : '';
$fFrom = parse_date_input(isset($_GET['from']) ? $_GET['from'] : '') ?: date('Y-m-d', strtotime('-7 days'));
$fTo = parse_date_input(isset($_GET['to']) ? $_GET['to'] : '') ?: date('Y-m-d');
$where = ['DATE(q.queued_at) BETWEEN ? AND ?'];
$params = [$fFrom, $fTo];
if ($fStatus) {
    $where[] = 'q.status = ?';
    $params[] = $fStatus;
}
$st = $pdo->prepare('SELECT q.*, o.order_number, o.total FROM integration_queue q JOIN orders o ON o.id = q.order_id WHERE ' . implode(' AND ', $where) . ' ORDER BY q.id DESC LIMIT 200');
$st->execute($params);
$rows = $st->fetchAll();
$logs = [];
if ($rows) {
    $ids = array_column($rows, 'id');
    $l = $pdo->prepare('SELECT * FROM integration_logs WHERE queue_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id');
    $l->execute($ids);
    foreach ($l->fetchAll() as $r) {
        $logs[$r['queue_id']][] = $r;
    }
}
$badge = ['PENDING' => 'secondary', 'PROCESSING' => 'info', 'SUCCESS' => 'success', 'FAILED' => 'danger', 'RETRY_SCHEDULED' => 'warning'];

$page_title = 'Integrasi Accurate';
$base = '../';
$admin = true;
include __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid py-3" style="max-width:1280px">
  <h1 class="h4"><i class="bi bi-arrow-left-right"></i> Integrasi Accurate</h1>
  <?php if ($mode === 'simulation'): ?>
    <div class="alert alert-warning"><strong>MODE SIMULASI.</strong> Tidak ada data yang dikirim ke Accurate. Status "berhasil" di sini hanya membuktikan alur antrean, validasi, idempotensi, dan retry — <strong>bukan</strong> bukti dokumen tercatat di Accurate.</div>
  <?php elseif ($mode === 'off'): ?>
    <div class="alert alert-secondary">Integrasi <strong>nonaktif</strong>. Transaksi tidak masuk antrean.</div>
  <?php else: ?>
    <div class="alert alert-info">Mode <strong>bridge</strong>: transaksi dikirim ke layanan bridge di <code><?= e($cfg['url'] ?: '(belum dikonfigurasi)') ?></code>. Status berhasil hanya setelah bridge mengembalikan nomor/ID dokumen.</div>
  <?php endif; ?>

  <div class="row g-2 mb-3">
    <?php foreach ([['Pending', (isset($counts['PENDING']) ? $counts['PENDING'] : 0) + (isset($counts['PROCESSING']) ? $counts['PROCESSING'] : 0), 'q-pending'],
                    ['Dijadwalkan ulang', isset($counts['RETRY_SCHEDULED']) ? $counts['RETRY_SCHEDULED'] : 0, 'q-retry'],
                    ['Berhasil', isset($counts['SUCCESS']) ? $counts['SUCCESS'] : 0, 'q-success'], ['Gagal', isset($counts['FAILED']) ? $counts['FAILED'] : 0, 'q-failed']] as $t): ?>
      <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-2"><div class="small text-muted"><?= e($t[0]) ?></div><div class="fs-4 fw-bold" id="<?= $t[2] ?>"><?= (int) $t[1] ?></div></div></div></div>
    <?php endforeach; ?>
  </div>
  <p class="small text-muted">Sinkronisasi berhasil terakhir: <strong><?= $lastOk ? e(date('d/m/Y H:i', strtotime($lastOk))) : '-' ?></strong> · Proses terakhir: <?= $lastRun ? e(date('d/m/Y H:i', strtotime($lastRun))) : '-' ?>
    · Worker cron: <code>php cron/integration-worker.php</code></p>

  <div class="row g-3 mb-3">
    <div class="col-lg-5">
      <form method="post" class="card h-100"><div class="card-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="config">
        <h2 class="h6">Status koneksi & konfigurasi</h2>
        <ul class="small list-unstyled mb-3">
          <li>Bridge URL (server): <?= $cfg['url'] ? '<code>' . e($cfg['url']) . '</code>' : '<span class="text-danger">belum diatur</span>' ?></li>
          <li>Token bridge (server): <?= $cfg['token'] ? '<span class="text-success">terpasang</span> (tidak ditampilkan)' : '<span class="text-danger">belum diatur</span>' ?></li>
          <li>Mapping wajib belum lengkap: <strong class="<?= $missing ? 'text-danger' : 'text-success' ?>"><?= count($missing) ?></strong></li>
          <li>Produk aktif tanpa kode barang Accurate: <strong class="<?= $noCode ? 'text-danger' : 'text-success' ?>"><?= $noCode ?></strong> <a href="products.php">atur</a></li>
          <li>Produk/versi Accurate terverifikasi: <strong class="text-danger">BELUM</strong> — lihat <code>docs/ACCURATE_INTEGRATION.md</code></li>
        </ul>
        <label class="form-label small" for="mode">Mode</label>
        <select class="form-select mb-2" name="mode" id="mode">
          <?php foreach (['off' => 'Nonaktif', 'simulation' => 'Simulasi (tanpa Accurate)', 'bridge' => 'Bridge (live)'] as $k => $v): ?><option value="<?= $k ?>" <?= $mode === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
        <label class="form-label small" for="send_on">Kapan transaksi masuk antrean</label>
        <select class="form-select mb-2" name="send_on" id="send_on">
          <option value="paid" <?= setting('accurate_send_on') === 'paid' ? 'selected' : '' ?>>Otomatis saat pembayaran lunas (direkomendasikan)</option>
          <option value="manual" <?= setting('accurate_send_on') === 'manual' ? 'selected' : '' ?>>Manual per rentang tanggal (batch harian/shift)</option></select>
        <label class="form-label small" for="sim_behavior">Perilaku simulasi (pengujian)</label>
        <select class="form-select mb-3" name="sim_behavior" id="sim_behavior">
          <?php foreach (['ok' => 'Normal', 'fail' => 'Gagal sementara', 'reject' => 'Ditolak target', 'timeout_after_commit' => 'Timeout setelah dokumen terbuat'] as $k => $v): ?><option value="<?= $k ?>" <?= setting('accurate_sim_behavior') === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
        <button class="btn btn-dark">Simpan konfigurasi</button>
      </div></form>
    </div>
    <div class="col-lg-7">
      <form method="post" class="card h-100"><div class="card-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="mapping">
        <h2 class="h6">Mapping kode Accurate</h2>
        <p class="small text-muted">Isi <strong>hanya</strong> dengan kode yang disetujui pengelola Accurate. Transaksi tidak dikirim selama mapping wajib kosong.</p>
        <div class="row g-2">
          <?php foreach ($keys as $k => $label): ?>
            <div class="col-md-6"><label class="form-label small mb-0" for="m-<?= e($k) ?>"><?= e($label) ?></label>
              <input class="form-control form-control-sm <?= in_array($k, $missing, true) ? 'border-warning' : '' ?>" id="m-<?= e($k) ?>" name="map[<?= e($k) ?>]" value="<?= e(isset($map[$k]) ? $map[$k] : '') ?>" maxlength="100"></div>
          <?php endforeach; ?>
        </div>
        <button class="btn btn-dark mt-3">Simpan mapping</button>
      </div></form>
    </div>
  </div>

  <div class="d-flex flex-wrap gap-2 mb-3">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="process"><button class="btn btn-accent"><i class="bi bi-play-circle"></i> Proses antrean sekarang</button></form>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="health"><button class="btn btn-outline-dark"><i class="bi bi-plug"></i> Tes koneksi bridge</button></form>
    <form method="post" class="d-flex flex-wrap gap-1 enqueue-range"><?= csrf_field() ?><input type="hidden" name="action" value="enqueue_range">
      <input class="form-control form-control-sm" type="date" name="from" value="<?= e(date('Y-m-d')) ?>" aria-label="Dari"><input class="form-control form-control-sm" type="date" name="to" value="<?= e(date('Y-m-d')) ?>" aria-label="Sampai">
      <button class="btn btn-outline-dark btn-sm text-nowrap">Antrekan rentang</button></form>
    <?php if ($simSuccess && $mode === 'bridge'): ?>
      <form method="post" onsubmit="return confirm('Antre ulang <?= $simSuccess ?> transaksi hasil simulasi ke mode live?')"><?= csrf_field() ?><input type="hidden" name="action" value="requeue_simulated"><button class="btn btn-warning">Antre ulang <?= $simSuccess ?> hasil simulasi</button></form>
    <?php endif; ?>
  </div>

  <form class="row g-2 mb-2" method="get">
    <div class="col-6 col-md-3"><select class="form-select" name="status" aria-label="Status"><option value="">Semua status</option>
      <?php foreach ($badge as $k => $v): ?><option value="<?= $k ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= e($k) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-3"><input class="form-control" type="date" name="from" value="<?= e($fFrom) ?>" aria-label="Dari"></div>
    <div class="col-6 col-md-3"><input class="form-control" type="date" name="to" value="<?= e($fTo) ?>" aria-label="Sampai"></div>
    <div class="col-6 col-md-3"><button class="btn btn-outline-dark w-100">Filter</button></div>
  </form>
  <div class="table-responsive"><table class="table table-sm align-middle bg-white" id="queue-table">
    <thead><tr><th>Transaksi</th><th>Dokumen</th><th>Status</th><th class="text-center">Percobaan</th><th>Terakhir</th><th>Referensi Accurate</th><th>Pesan</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr data-key="<?= e($r['idempotency_key']) ?>">
        <td><strong><?= e($r['order_number']) ?></strong><div class="small text-muted"><?= e(rupiah($r['total'])) ?> · antre <?= e(date('d/m H:i', strtotime($r['queued_at']))) ?></div></td>
        <td><?= e($r['doc_type']) ?><div class="small text-muted"><code><?= e($r['idempotency_key']) ?></code></div></td>
        <td><span class="badge text-bg-<?= $badge[$r['status']] ?>"><?= e($r['status']) ?></span><?= $r['mode'] === 'simulation' && $r['status'] === 'SUCCESS' ? ' <span class="badge text-bg-warning">SIMULASI</span>' : '' ?></td>
        <td class="text-center"><?= (int) $r['attempts'] ?></td>
        <td class="small"><?= $r['last_attempt_at'] ? e(date('d/m H:i:s', strtotime($r['last_attempt_at']))) : '-' ?><?= $r['status'] === 'RETRY_SCHEDULED' && $r['next_attempt_at'] ? '<div class="text-muted">berikut ' . e(date('H:i', strtotime($r['next_attempt_at']))) . '</div>' : '' ?></td>
        <td><?= $r['external_ref'] ? '<code>' . e($r['external_ref']) . '</code>' : '-' ?></td>
        <td class="small text-break" style="max-width:320px"><?= e($r['response_message']) ?></td>
        <td class="text-nowrap">
          <?php if (in_array($r['status'], ['FAILED', 'RETRY_SCHEDULED', 'PENDING'], true)): ?>
            <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="retry"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-sm btn-outline-primary">Retry</button></form>
          <?php endif; ?>
          <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#lg<?= (int) $r['id'] ?>">Log</button>
        </td>
      </tr>
      <tr class="collapse" id="lg<?= (int) $r['id'] ?>"><td colspan="8"><ul class="small mb-0">
        <?php foreach (isset($logs[$r['id']]) ? $logs[$r['id']] : [] as $l): ?>
          <li><?= e(date('d/m H:i:s', strtotime($l['created_at']))) ?> · #<?= (int) $l['attempt'] ?> · <strong><?= e($l['action']) ?></strong> → <?= e($l['result']) ?><?= $l['mode'] ? ' (' . e($l['mode']) . ')' : '' ?><?= $l['duration_ms'] !== null ? ' · ' . (int) $l['duration_ms'] . ' ms' : '' ?>
            <div class="text-muted text-break"><?= e($l['response']) ?></div></li>
        <?php endforeach; ?>
        <?php if (empty($logs[$r['id']])): ?><li class="text-muted">Belum ada log.</li><?php endif; ?>
      </ul></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="text-muted text-center py-3">Antrean kosong.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
