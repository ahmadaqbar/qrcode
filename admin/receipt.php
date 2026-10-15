<?php
/**
 * Struk / tiket untuk dicetak lewat browser (window.print).
 * type: bill (tagihan sebelum bayar) | customer (struk pembayaran) | kitchen (tiket dapur/bar) | delivery (struk antar)
 * Lebar kertas mengikuti Settings (58 / 80 mm). Lihat docs/PRINTING.md untuk batasan printer & browser.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/orders.php';

$type = isset($_GET['type']) ? (string) $_GET['type'] : 'customer';
$perms = ['bill' => ['pos.use', 'orders.view', 'payment.take'], 'customer' => ['pos.use', 'orders.view', 'payment.take'],
          'kitchen' => ['kitchen.view', 'pos.use'], 'delivery' => ['delivery.view', 'delivery.courier', 'pos.use']];
if (!isset($perms[$type])) {
    $type = 'customer';
}
require_permission($perms[$type]);

$pdo = db();
$id = (int) filter_var(isset($_GET['id']) ? $_GET['id'] : 0, FILTER_VALIDATE_INT);
$o = $id ? order_full($pdo, $id) : null;
if (!$o) {
    http_response_code(404);
    exit('Pesanan tidak ditemukan.');
}
// Kurir hanya boleh melihat pesanan yang ditugaskan kepadanya
if ($type === 'delivery' && !can(['delivery.view', 'pos.use']) && (!$o['delivery'] || (int) $o['delivery']['courier_id'] !== (int) current_user()['id'])) {
    http_response_code(403);
    exit('Akses ditolak.');
}
$width = setting('receipt_width', '80') === '58' ? 58 : 80;
$outlet = setting('outlet_name') ?: APP_NAME;
$userName = function ($uid) use ($pdo) {
    if (!$uid) {
        return '-';
    }
    $s = $pdo->prepare('SELECT name FROM users WHERE id = ?');
    $s->execute([$uid]);
    return $s->fetchColumn() ?: '-';
};
$items = array_values(array_filter($o['items'], function ($i) { return $i['kitchen_status'] !== 'CANCELLED'; }));
$titles = ['bill' => 'TAGIHAN', 'customer' => 'STRUK PEMBAYARAN', 'kitchen' => 'TIKET DAPUR', 'delivery' => 'DELIVERY'];

// Tiket dapur: per station & batch (default: semua tiket yang masih aktif, atau batch tertentu)
$tickets = [];
if ($type === 'kitchen') {
    $wantStation = isset($_GET['station']) ? (string) $_GET['station'] : '';
    $wantBatch = isset($_GET['batch']) ? (int) $_GET['batch'] : 0;
    foreach ($items as $i) {
        if ($i['batch'] < 1 || ($wantStation && $i['station'] !== $wantStation) || ($wantBatch && $i['batch'] !== $wantBatch)) {
            continue;
        }
        $tickets[$i['station'] . '#' . $i['batch']][] = $i;
    }
    ksort($tickets);
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titles[$type] . ' ' . $o['order_number']) ?></title>
<style>
  @page { size: <?= $width ?>mm auto; margin: 0; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #eee; font: 12px/1.35 "DejaVu Sans Mono", "Courier New", monospace; color: #000; }
  .paper { width: <?= $width ?>mm; margin: 12px auto; background: #fff; padding: 3mm 3mm 6mm; }
  .c { text-align: center; } .r { text-align: right; } .b { font-weight: 700; } .big { font-size: 16px; } .huge { font-size: 20px; font-weight: 800; }
  hr { border: 0; border-top: 1px dashed #000; margin: 4px 0; }
  table { width: 100%; border-collapse: collapse; } td { vertical-align: top; padding: 0; }
  .row { display: flex; justify-content: space-between; gap: 4px; }
  .note { font-style: italic; }
  .ticket { page-break-after: always; } .ticket:last-child { page-break-after: auto; }
  .tools { text-align: center; margin: 12px; font-family: sans-serif; }
  .tools button { font-size: 14px; padding: 8px 16px; margin: 0 4px; }
  @media print { body { background: #fff; } .paper { margin: 0; width: auto; } .tools { display: none; } }
</style>
</head>
<body>
<div class="tools">
  <button type="button" onclick="window.print()">Cetak</button>
  <button type="button" onclick="window.close()">Tutup</button>
</div>
<div class="paper">
<?php if ($type === 'kitchen'): ?>
  <?php if (!$tickets): ?><p class="c">Belum ada item yang dikirim ke dapur.</p><?php endif; ?>
  <?php foreach ($tickets as $key => $list): list($station, $batch) = explode('#', $key); ?>
    <div class="ticket">
      <div class="c huge"><?= $station === 'BAR' ? 'BAR' : 'DAPUR' ?></div>
      <div class="c b big"><?= e($o['order_number']) ?></div>
      <div class="c b big"><?= e($o['place_label']) ?></div>
      <div class="c">Masuk <?= e(date('d/m/Y H:i', strtotime($list[0]['sent_at'] ?: $o['created_at']))) ?><?= (int) $batch > 1 ? ' · TAMBAHAN #' . (int) $batch : '' ?></div>
      <hr>
      <?php foreach ($list as $i): ?>
        <div class="big b"><?= (int) $i['qty'] ?> x <?= e($i['name']) ?></div>
        <?php if ($i['note']): ?><div class="note">  &gt; <?= e($i['note']) ?></div><?php endif; ?>
      <?php endforeach; ?>
      <?php if ($o['note']): ?><hr><div class="note">Catatan: <?= e($o['note']) ?></div><?php endif; ?>
      <hr><div class="c">Dicetak <?= e(date('H:i')) ?></div>
    </div>
  <?php endforeach; ?>
<?php else: ?>
  <div class="c b big"><?= e($outlet) ?></div>
  <?php if (setting('outlet_address')): ?><div class="c"><?= e(setting('outlet_address')) ?></div><?php endif; ?>
  <?php if (setting('outlet_phone')): ?><div class="c">Telp <?= e(setting('outlet_phone')) ?></div><?php endif; ?>
  <hr>
  <div class="c b"><?= e($titles[$type]) ?></div>
  <div class="row"><span>No</span><span><?= e($o['order_number']) ?></span></div>
  <div class="row"><span>Tanggal</span><span><?= e(date('d/m/Y H:i', strtotime($type === 'customer' && $o['closed_at'] ? $o['closed_at'] : $o['created_at']))) ?></span></div>
  <div class="row"><span>Kasir</span><span><?= e($type === 'customer' ? $userName($o['closed_by'] ?: current_user()['id']) : current_user()['name']) ?></span></div>
  <div class="row"><span>Tipe</span><span><?= e($o['place_label']) ?></span></div>
  <?php if ($o['customer_name']): ?><div class="row"><span>Pelanggan</span><span><?= e($o['customer_name']) ?></span></div><?php endif; ?>
  <?php if ($type === 'delivery' && $o['delivery']): $d = $o['delivery']; ?>
    <hr>
    <div class="b">Kirim ke:</div>
    <div><?= e($d['customer_name']) ?> · <?= e($d['phone']) ?></div>
    <div><?= e($d['address']) ?></div>
    <?php if ($d['landmark']): ?><div>Patokan: <?= e($d['landmark']) ?></div><?php endif; ?>
    <?php if ($d['delivery_note']): ?><div class="note">Catatan: <?= e($d['delivery_note']) ?></div><?php endif; ?>
    <div>Sumber: <?= e($d['source']) ?> · Kurir: <?= e($d['courier_name'] ?: '-') ?></div>
  <?php endif; ?>
  <hr>
  <?php foreach ($items as $i): ?>
    <div><?= e($i['name']) ?></div>
    <div class="row"><span>  <?= (int) $i['qty'] ?> x <?= e(number_format($i['price'], 0, ',', '.')) ?></span><span><?= e(number_format($i['price'] * $i['qty'], 0, ',', '.')) ?></span></div>
    <?php if ($i['discount_amount']): ?><div class="row"><span>  Diskon</span><span>-<?= e(number_format($i['discount_amount'], 0, ',', '.')) ?></span></div><?php endif; ?>
    <?php if ($i['note']): ?><div class="note">  &gt; <?= e($i['note']) ?></div><?php endif; ?>
  <?php endforeach; ?>
  <hr>
  <div class="row"><span>Subtotal</span><span><?= e(rupiah($o['subtotal'])) ?></span></div>
  <?php if ($o['discount_amount']): ?><div class="row"><span>Diskon<?= $o['discount_type'] === 'PERCENT' ? ' ' . (int) $o['discount_value'] . '%' : '' ?></span><span>-<?= e(rupiah($o['discount_amount'])) ?></span></div><?php endif; ?>
  <?php if ($o['service_amount']): ?><div class="row"><span>Service <?= e(rtrim(rtrim(number_format($o['service_rate'], 2, ',', ''), '0'), ',')) ?>%</span><span><?= e(rupiah($o['service_amount'])) ?></span></div><?php endif; ?>
  <?php if ($o['tax_amount']): ?><div class="row"><span>Pajak <?= e(rtrim(rtrim(number_format($o['tax_rate'], 2, ',', ''), '0'), ',')) ?>%</span><span><?= e(rupiah($o['tax_amount'])) ?></span></div><?php endif; ?>
  <?php if ($o['delivery_fee']): ?><div class="row"><span>Ongkos kirim</span><span><?= e(rupiah($o['delivery_fee'])) ?></span></div><?php endif; ?>
  <?php if ($o['other_fee']): ?><div class="row"><span><?= e($o['other_fee_label'] ?: 'Biaya lain') ?></span><span><?= e(rupiah($o['other_fee'])) ?></span></div><?php endif; ?>
  <div class="row b big"><span>TOTAL</span><span><?= e(rupiah($o['total'])) ?></span></div>
  <?php if ($o['payments']): ?>
    <hr>
    <?php foreach ($o['payments'] as $p): ?>
      <div class="row"><span><?= $p['kind'] === 'REFUND' ? 'REFUND ' : '' ?><?= e($p['method_name']) ?></span><span><?= e(rupiah($p['amount'])) ?></span></div>
      <?php if ($p['kind'] === 'PAYMENT' && (int) $p['tendered'] > (int) $p['amount']): ?>
        <div class="row"><span>  Diterima</span><span><?= e(rupiah($p['tendered'])) ?></span></div>
        <div class="row"><span>  Kembali</span><span><?= e(rupiah($p['change_amount'])) ?></span></div>
      <?php endif; ?>
      <?php if ($p['reference']): ?><div>  Ref: <?= e($p['reference']) ?></div><?php endif; ?>
    <?php endforeach; ?>
  <?php endif; ?>
  <hr>
  <div class="row b"><span>Status</span><span><?= e(strtoupper(PAYMENT_STATUS_LABELS[$o['payment_status']])) ?></span></div>
  <?php if ($o['payment_status'] !== 'PAID' && $o['payment_status'] !== 'REFUNDED'): ?>
    <div class="row b"><span>Sisa tagihan</span><span><?= e(rupiah($o['remaining'])) ?></span></div>
  <?php endif; ?>
  <?php if ($type === 'bill'): ?><div class="c">*** BELUM LUNAS — bukan bukti pembayaran ***</div><?php endif; ?>
  <hr>
  <div class="c"><?= e(setting('receipt_footer', 'Terima kasih')) ?></div>
<?php endif; ?>
</div>
<?php if (!empty($_GET['autoprint'])): ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 250); });</script>
<?php endif; ?>
</body>
</html>
