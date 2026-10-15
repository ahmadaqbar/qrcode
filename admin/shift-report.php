<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/shift.php';
require_permission(['shift.manage', 'shift.override', 'reports.view']);

$pdo = db();
$id = (int) filter_var(isset($_GET['id']) ? $_GET['id'] : 0, FILTER_VALIDATE_INT);
$st = $pdo->prepare('SELECT s.*, u.name, c.name AS closer, o.name AS overrider FROM cash_shifts s JOIN users u ON u.id = s.user_id
                     LEFT JOIN users c ON c.id = s.closed_by LEFT JOIN users o ON o.id = s.override_by WHERE s.id = ?');
$st->execute([$id]);
$shift = $st->fetch();
if (!$shift || (!can(['shift.override', 'reports.view']) && (int) $shift['user_id'] !== (int) current_user()['id'])) {
    http_response_code(404);
    exit('Shift tidak ditemukan.');
}
$live = shift_summary($pdo, $shift);
// Shift tertutup: pakai angka yang dikunci saat tutup shift
$s = $shift['status'] === 'CLOSED' ? array_merge($live, [
    'cash_sales' => (int) $shift['cash_sales'], 'noncash_sales' => (int) $shift['noncash_sales'], 'refunds_cash' => (int) $shift['refunds_cash'],
    'refunds_noncash' => (int) $shift['refunds_noncash'], 'cash_in' => (int) $shift['cash_in'], 'cash_out' => (int) $shift['cash_out'],
    'expected_cash' => (int) $shift['expected_cash'],
]) : $live;
$s['total_sales'] = $s['cash_sales'] + $s['noncash_sales'];
$s['net_sales'] = $s['total_sales'] - $s['refunds_cash'] - $s['refunds_noncash'];

$page_title = 'Laporan Shift #' . $id;
$base = '../';
$admin = true;
include __DIR__ . '/../includes/header.php';
$row = function ($label, $value, $cls = '') { echo '<tr class="' . $cls . '"><td>' . e($label) . '</td><td class="text-end">' . e($value) . '</td></tr>'; };
?>
<div class="container py-3 shift-report" style="max-width:640px">
  <div class="d-flex justify-content-between align-items-center mb-3 no-print">
    <a href="shift.php" class="btn btn-sm btn-outline-secondary">&larr; Shift</a>
    <button class="btn btn-sm btn-dark" onclick="window.print()"><i class="bi bi-printer"></i> Cetak</button>
  </div>
  <div class="card"><div class="card-body">
    <h1 class="h5 mb-1">Laporan Shift #<?= (int) $shift['id'] ?> <?= $shift['status'] === 'OPEN' ? '<span class="badge text-bg-success">OPEN (sementara)</span>' : '' ?></h1>
    <div class="small text-muted mb-3">Kasir: <strong><?= e($shift['name']) ?></strong> ·
      <?= e(date('d/m/Y H:i', strtotime($shift['opened_at']))) ?> – <?= $shift['closed_at'] ? e(date('d/m/Y H:i', strtotime($shift['closed_at']))) : 'sekarang' ?></div>
    <table class="table table-sm"><tbody>
      <?php
      $row('Modal awal', rupiah($s['opening_cash']));
      $row('Jumlah transaksi dibayar', (string) $s['transactions']);
      $row('Total penjualan (bruto)', rupiah($s['total_sales']), 'fw-semibold');
      $row('  Tunai', rupiah($s['cash_sales']));
      $row('  Non-tunai', rupiah($s['noncash_sales']));
      $row('Refund tunai', '−' . rupiah($s['refunds_cash']));
      $row('Refund non-tunai', '−' . rupiah($s['refunds_noncash']));
      $row('Penjualan bersih', rupiah($s['net_sales']), 'fw-semibold');
      $row('Pembatalan (void) oleh kasir', $s['cancel_count'] . ' pesanan · ' . rupiah($s['cancel_total']));
      $row('Kas masuk', rupiah($s['cash_in']));
      $row('Kas keluar', '−' . rupiah($s['cash_out']));
      $row('Uang tunai seharusnya', rupiah($s['expected_cash']), 'fw-bold');
      if ($shift['status'] === 'CLOSED') {
          $row('Uang tunai aktual', rupiah($shift['counted_cash']), 'fw-bold');
          $row('Selisih kas', rupiah($shift['difference']) . ((int) $shift['difference'] === 0 ? ' (sesuai)' : ((int) $shift['difference'] < 0 ? ' (kurang)' : ' (lebih)')), 'fw-bold ' . ((int) $shift['difference'] < 0 ? 'table-danger' : ''));
      }
      ?>
    </tbody></table>
    <h2 class="h6">Per metode pembayaran</h2>
    <table class="table table-sm"><thead><tr><th>Metode</th><th class="text-end">Pembayaran</th><th class="text-end">Refund</th></tr></thead><tbody>
      <?php foreach ($s['by_method'] as $m): ?><tr><td><?= e($m['name']) ?></td><td class="text-end"><?= e(rupiah($m['payments'])) ?></td><td class="text-end"><?= e(rupiah($m['refunds'])) ?></td></tr><?php endforeach; ?>
      <?php if (!$s['by_method']): ?><tr><td colspan="3" class="text-muted">Tidak ada pembayaran.</td></tr><?php endif; ?>
    </tbody></table>
    <?php if ($shift['note']): ?><p class="small"><strong>Catatan kasir:</strong> <?= e($shift['note']) ?></p><?php endif; ?>
    <?php if ($shift['override_by']): ?><div class="alert alert-warning small py-2">Ditutup dengan <strong>override</strong> oleh <?= e($shift['overrider']) ?>: <?= e($shift['override_reason']) ?></div><?php endif; ?>
    <?php if ($shift['closer']): ?><p class="small text-muted mb-0">Ditutup oleh <?= e($shift['closer']) ?>.</p><?php endif; ?>
  </div></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
