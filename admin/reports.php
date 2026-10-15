<?php
/**
 * Dashboard laporan.
 * Aturan (ditampilkan juga di halaman):
 *  - Penjualan sah  = pesanan COMPLETED + PAID, berdasarkan waktu ditutup (closed_at). Void, refund, dan belum bayar TIDAK dihitung.
 *  - Refund         = baris refund pada periode (pesanan yang direfund otomatis keluar dari penjualan sah).
 *  - Per metode     = pembayaran yang diterima pada periode (dikurangi refund per metode).
 *  - Dibatalkan     = pesanan CANCELLED pada periode (tidak termasuk tagihan yang digabung).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/orders.php';
require_permission('reports.view');

$pdo = db();
$from = parse_date_input(isset($_GET['from']) ? $_GET['from'] : '') ?: date('Y-m-d');
$to = parse_date_input(isset($_GET['to']) ? $_GET['to'] : '') ?: date('Y-m-d');
if ($to < $from) {
    $to = $from;
}
$cashier = isset($_GET['cashier']) ? (int) $_GET['cashier'] : 0;
$shiftId = isset($_GET['shift']) ? (int) $_GET['shift'] : 0;
$rangeA = $from . ' 00:00:00';
$rangeB = $to . ' 23:59:59';

// Filter pesanan sah
$ow = ["o.status = 'COMPLETED'", "o.payment_status = 'PAID'", 'o.closed_at BETWEEN ? AND ?'];
$op = [$rangeA, $rangeB];
if ($cashier) {
    $ow[] = 'o.closed_by = ?';
    $op[] = $cashier;
}
if ($shiftId) {
    $ow[] = "EXISTS (SELECT 1 FROM payments sp WHERE sp.order_id = o.id AND sp.shift_id = ? AND sp.kind = 'PAYMENT')";
    $op[] = $shiftId;
}
$OW = implode(' AND ', $ow);
$q = function ($sql, $params) use ($pdo) {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st;
};
$k = $q("SELECT COUNT(*) n, COALESCE(SUM(total),0) total, COALESCE(SUM(discount_amount),0) disc, COALESCE(SUM(tax_amount),0) tax,
                COALESCE(SUM(service_amount),0) svc, COALESCE(SUM(delivery_fee),0) del, COALESCE(SUM(subtotal),0) sub
         FROM orders o WHERE $OW", $op)->fetch();
$avg = $k['n'] ? (int) round($k['total'] / $k['n']) : 0;

// Pembayaran per metode (kas masuk pada periode)
$pw = ['p.created_at BETWEEN ? AND ?'];
$pp = [$rangeA, $rangeB];
if ($cashier) {
    $pw[] = 'p.created_by = ?';
    $pp[] = $cashier;
}
if ($shiftId) {
    $pw[] = 'p.shift_id = ?';
    $pp[] = $shiftId;
}
$methods = $q('SELECT m.name, SUM(CASE WHEN p.kind = \'PAYMENT\' THEN p.amount ELSE 0 END) AS paid, SUM(CASE WHEN p.kind = \'REFUND\' THEN -p.amount ELSE 0 END) AS refunded
               FROM payments p JOIN payment_methods m ON m.code = p.method_code WHERE ' . implode(' AND ', $pw) . ' GROUP BY m.code, m.name ORDER BY paid DESC', $pp)->fetchAll();
$legacy = $q("SELECT COALESCE(SUM(o.total),0) FROM orders o WHERE $OW AND NOT EXISTS (SELECT 1 FROM payments x WHERE x.order_id = o.id)", $op)->fetchColumn();
$refund = $q("SELECT COUNT(DISTINCT p.order_id) n, COALESCE(SUM(-p.amount),0) amt FROM payments p WHERE p.kind = 'REFUND' AND " . implode(' AND ', $pw), $pp)->fetch();

$byCat = $q("SELECT c.name, SUM(i.quantity) qty, SUM(i.subtotal) amt FROM order_items i JOIN orders o ON o.id = i.order_id
             JOIN products p ON p.id = i.product_id JOIN categories c ON c.id = p.category_id
             WHERE $OW AND i.kitchen_status <> 'CANCELLED' GROUP BY c.id, c.name ORDER BY amt DESC", $op)->fetchAll();
$top = $q("SELECT i.product_name name, SUM(i.quantity) qty, SUM(i.subtotal) amt FROM order_items i JOIN orders o ON o.id = i.order_id
           WHERE $OW AND i.kitchen_status <> 'CANCELLED' GROUP BY i.product_id, i.product_name ORDER BY qty DESC, amt DESC LIMIT 10", $op)->fetchAll();
$byType = $q("SELECT o.order_type, COUNT(*) n, SUM(o.total) amt FROM orders o WHERE $OW GROUP BY o.order_type ORDER BY amt DESC", $op)->fetchAll();

$cw = ["o.status = 'CANCELLED'", 'o.merged_into_id IS NULL', 'o.cancelled_at BETWEEN ? AND ?'];
$cp = [$rangeA, $rangeB];
if ($cashier) {
    $cw[] = 'o.cancelled_by = ?';
    $cp[] = $cashier;
}
$cancel = $q('SELECT COUNT(*) n, COALESCE(SUM(o.total),0) amt FROM orders o WHERE ' . implode(' AND ', $cw), $cp)->fetch();
$open = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(total - paid_amount),0) amt, SUM(status = 'DRAFT') held, SUM(payment_status = 'PARTIAL') partial
                     FROM orders WHERE status IN ('DRAFT','NEW','PROCESSING')")->fetch();
$sw = ['s.opened_at BETWEEN ? AND ?'];
$sp = [$rangeA, $rangeB];
if ($cashier) {
    $sw[] = 's.user_id = ?';
    $sp[] = $cashier;
}
$shifts = $q('SELECT s.*, u.name FROM cash_shifts s JOIN users u ON u.id = s.user_id WHERE ' . implode(' AND ', $sw) . ' ORDER BY s.id DESC', $sp)->fetchAll();
$cashiers = $pdo->query('SELECT DISTINCT u.id, u.name FROM users u JOIN cash_shifts s ON s.user_id = u.id ORDER BY u.name')->fetchAll();
$shiftList = $pdo->query('SELECT s.id, s.opened_at, u.name FROM cash_shifts s JOIN users u ON u.id = s.user_id ORDER BY s.id DESC LIMIT 100')->fetchAll();

/** Bar horizontal satu seri: label | bar | nilai (teks netral). Nilai juga tertulis -> tetap terbaca tanpa warna. */
function bars(array $rows, string $valueKey, callable $fmt, ?callable $sub = null): void
{
    if (!$rows) {
        echo '<p class="text-muted small mb-0">Belum ada data pada periode ini.</p>';
        return;
    }
    $max = max(1, max(array_map(function ($r) use ($valueKey) { return (int) $r[$valueKey]; }, $rows)));
    echo '<ul class="viz-bars">';
    foreach ($rows as $r) {
        $v = (int) $r[$valueKey];
        $pct = max($v > 0 ? 1 : 0, round($v / $max * 100, 1));
        $tip = $r['label'] . ': ' . $fmt($v) . ($sub ? ' · ' . $sub($r) : '');
        echo '<li title="' . e($tip) . '"><span class="viz-label">' . e($r['label']) . '</span>'
           . '<span class="viz-track"><span class="viz-bar" style="width:' . $pct . '%"></span></span>'
           . '<span class="viz-value">' . e($fmt($v)) . ($sub ? '<small>' . e($sub($r)) . '</small>' : '') . '</span></li>';
    }
    echo '</ul>';
}
$rp = function ($v) { return rupiah($v); };

$page_title = 'Reports';
$base = '../';
$admin = true;
include __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid py-3 reports" style="max-width:1280px">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
    <h1 class="h4 m-0"><i class="bi bi-graph-up"></i> Laporan Operasional</h1>
    <button class="btn btn-sm btn-outline-dark no-print" onclick="window.print()"><i class="bi bi-printer"></i> Cetak</button>
  </div>
  <form class="row g-2 mb-3 no-print" method="get" aria-label="Filter laporan">
    <div class="col-6 col-md-2"><label class="form-label small mb-0" for="from">Dari</label><input class="form-control" type="date" id="from" name="from" value="<?= e($from) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-0" for="to">Sampai</label><input class="form-control" type="date" id="to" name="to" value="<?= e($to) ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label small mb-0" for="cashier">Kasir</label><select class="form-select" id="cashier" name="cashier"><option value="0">Semua kasir</option>
      <?php foreach ($cashiers as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $cashier === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-3"><label class="form-label small mb-0" for="shift">Shift</label><select class="form-select" id="shift" name="shift"><option value="0">Semua shift</option>
      <?php foreach ($shiftList as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $shiftId === (int) $s['id'] ? 'selected' : '' ?>>#<?= (int) $s['id'] ?> · <?= e($s['name']) ?> · <?= e(date('d/m H:i', strtotime($s['opened_at']))) ?></option><?php endforeach; ?></select></div>
    <div class="col-12 col-md-2 d-flex align-items-end"><button class="btn btn-dark w-100">Terapkan</button></div>
  </form>
  <p class="small text-muted">Periode <?= e(date('d/m/Y', strtotime($from))) ?> – <?= e(date('d/m/Y', strtotime($to))) ?>. Outlet tunggal (modul multi-outlet belum tersedia).</p>

  <div class="row g-3 mb-3">
    <div class="col-12 col-lg-4">
      <div class="card h-100 viz-hero"><div class="card-body">
        <div class="viz-hero-label">Penjualan sah</div>
        <div class="viz-hero-value" id="kpi-sales"><?= e(rupiah($k['total'])) ?></div>
        <div class="small text-muted">Lunas &amp; ditutup pada periode — tanpa void, refund, atau tagihan belum dibayar.</div>
      </div></div>
    </div>
    <div class="col-12 col-lg-8"><div class="row g-2">
      <?php foreach ([['Transaksi', number_format($k['n'], 0, ',', '.'), 'kpi-count'], ['Rata-rata transaksi', rupiah($avg), 'kpi-avg'],
                      ['Diskon', rupiah($k['disc']), ''], ['Pajak', rupiah($k['tax']), ''], ['Service charge', rupiah($k['svc']), ''], ['Ongkos kirim', rupiah($k['del']), ''],
                      ['Dibatalkan (void)', $cancel['n'] . ' · ' . rupiah($cancel['amt']), 'kpi-cancel'], ['Refund', $refund['n'] . ' · ' . rupiah($refund['amt']), 'kpi-refund'],
                      ['Belum selesai (saat ini)', $open['n'] . ' · ' . rupiah($open['amt']), 'kpi-open']] as $t): ?>
        <div class="col-6 col-md-4"><div class="card h-100 viz-tile"><div class="card-body py-2">
          <div class="viz-tile-label"><?= e($t[0]) ?></div><div class="viz-tile-value"<?= $t[2] ? ' id="' . $t[2] . '"' : '' ?>><?= e($t[1]) ?></div></div></div></div>
      <?php endforeach; ?>
    </div></div>
  </div>
  <?php if ($open['n']): ?><div class="alert alert-warning small py-2">Transaksi belum selesai saat ini: <?= (int) $open['n'] ?> (<?= (int) $open['held'] ?> ditahan, <?= (int) $open['partial'] ?> bayar sebagian). <a href="orders.php?status=PROCESSING">Lihat</a></div><?php endif; ?>

  <div class="row g-3">
    <div class="col-12 col-xl-6"><div class="card h-100"><div class="card-body">
      <h2 class="h6">Penjualan per metode pembayaran</h2>
      <?php $mr = array_map(function ($m) { return ['label' => $m['name'], 'net' => (int) $m['paid'] - (int) $m['refunded'], 'refunded' => (int) $m['refunded']]; }, $methods);
            if ((int) $legacy) { $mr[] = ['label' => 'Tanpa metode (data lama)', 'net' => (int) $legacy, 'refunded' => 0]; }
            bars($mr, 'net', $rp, function ($r) { return $r['refunded'] ? 'refund ' . rupiah($r['refunded']) : ''; }); ?>
    </div></div></div>
    <div class="col-12 col-xl-6"><div class="card h-100"><div class="card-body">
      <h2 class="h6">Penjualan per tipe pesanan</h2>
      <?php bars(array_map(function ($t) { return ['label' => ORDER_TYPES[$t['order_type']], 'amt' => (int) $t['amt'], 'n' => (int) $t['n']]; }, $byType), 'amt', $rp,
                 function ($r) { return $r['n'] . ' transaksi'; }); ?>
    </div></div></div>
    <div class="col-12 col-xl-6"><div class="card h-100"><div class="card-body">
      <h2 class="h6">Penjualan per kategori</h2>
      <?php bars(array_map(function ($c) { return ['label' => $c['name'], 'amt' => (int) $c['amt'], 'qty' => (int) $c['qty']]; }, $byCat), 'amt', $rp,
                 function ($r) { return $r['qty'] . ' item'; }); ?>
      <p class="small text-muted mb-0 mt-2">Nilai item setelah diskon item, sebelum diskon transaksi/pajak/service.</p>
    </div></div></div>
    <div class="col-12 col-xl-6"><div class="card h-100"><div class="card-body">
      <h2 class="h6">Produk terlaris (jumlah terjual)</h2>
      <?php bars(array_map(function ($t) { return ['label' => $t['name'], 'qty' => (int) $t['qty'], 'amt' => (int) $t['amt']]; }, $top), 'qty',
                 function ($v) { return $v . ' porsi'; }, function ($r) { return rupiah($r['amt']); }); ?>
    </div></div></div>
    <div class="col-12"><div class="card"><div class="card-body">
      <h2 class="h6">Ringkasan shift kasir</h2>
      <div class="table-responsive"><table class="table table-sm mb-0">
        <thead><tr><th>#</th><th>Kasir</th><th>Buka</th><th>Tutup</th><th class="text-end">Tunai</th><th class="text-end">Non-tunai</th><th class="text-end">Refund</th><th class="text-end">Selisih kas</th></tr></thead>
        <tbody>
        <?php foreach ($shifts as $s): ?>
          <tr><td><a href="shift-report.php?id=<?= (int) $s['id'] ?>"><?= (int) $s['id'] ?></a></td><td><?= e($s['name']) ?></td><td><?= e(date('d/m H:i', strtotime($s['opened_at']))) ?></td>
            <td><?= $s['closed_at'] ? e(date('d/m H:i', strtotime($s['closed_at']))) : '<span class="badge text-bg-success">OPEN</span>' ?></td>
            <td class="text-end"><?= $s['status'] === 'CLOSED' ? e(rupiah($s['cash_sales'])) : '-' ?></td><td class="text-end"><?= $s['status'] === 'CLOSED' ? e(rupiah($s['noncash_sales'])) : '-' ?></td>
            <td class="text-end"><?= $s['status'] === 'CLOSED' ? e(rupiah((int) $s['refunds_cash'] + (int) $s['refunds_noncash'])) : '-' ?></td>
            <td class="text-end <?= (int) $s['difference'] < 0 ? 'text-danger fw-bold' : '' ?>"><?= $s['status'] === 'CLOSED' ? e(rupiah($s['difference'])) : '-' ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$shifts): ?><tr><td colspan="8" class="text-muted">Tidak ada shift pada periode ini.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div></div></div>
  </div>
  <details class="small text-muted mt-3"><summary>Aturan perhitungan</summary>
    <ul class="mb-0"><li>Penjualan sah = pesanan lunas yang ditutup pada periode. Pesanan void, direfund, atau belum dibayar tidak dihitung.</li>
      <li>Per metode = uang yang diterima pada periode dikurangi refund per metode (basis kas). "Data lama" = pesanan sebelum modul pembayaran ada.</li>
      <li>Tagihan yang digabung tidak dihitung sebagai pembatalan. Filter shift: pesanan yang dibayar di shift tersebut.</li></ul></details>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
