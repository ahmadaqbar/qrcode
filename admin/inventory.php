<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory.php';
require_permission('inventory.manage');

$pdo = db();
$me = current_user();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        flash_set('err', 'Sesi tidak valid, coba lagi.');
        redirect('inventory.php');
    }
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $pid = (int) filter_var(isset($_POST['product_id']) ? $_POST['product_id'] : 0, FILTER_VALIDATE_INT);
    try {
        $p = $pdo->prepare('SELECT id, name FROM products WHERE id = ?');
        $p->execute([$pid]);
        $prod = $p->fetch();
        if (!$prod) {
            throw new RuntimeException('Produk tidak ditemukan.');
        }
        if ($action === 'config') {
            $min = (int) filter_var(isset($_POST['stock_min']) ? $_POST['stock_min'] : 0, FILTER_VALIDATE_INT);
            $pdo->prepare('UPDATE products SET track_stock = ?, stock_min = ? WHERE id = ?')->execute([!empty($_POST['track_stock']) ? 1 : 0, max(0, $min), $pid]);
            audit('inventory.config', 'product', $pid, ['track' => !empty($_POST['track_stock']), 'min' => max(0, $min)]);
            flash_set('ok', 'Pengaturan stok ' . $prod['name'] . ' disimpan.');
        } elseif ($action === 'movement') {
            $type = isset($_POST['type']) && in_array($_POST['type'], ['OPENING', 'IN', 'OUT', 'ADJUSTMENT'], true) ? $_POST['type'] : null;
            $qty = filter_var(isset($_POST['qty']) ? $_POST['qty'] : '', FILTER_VALIDATE_INT);
            $note = clean_input(isset($_POST['note']) ? $_POST['note'] : '', 255);
            if (!$type || $qty === false || $qty < 0 || $qty > 1000000 || ($type !== 'ADJUSTMENT' && $qty === 0)) {
                throw new RuntimeException('Jenis dan jumlah wajib diisi dengan benar.');
            }
            if (in_array($type, ['OUT', 'ADJUSTMENT'], true) && $note === '') {
                throw new RuntimeException('Keterangan wajib untuk stok keluar / penyesuaian.');
            }
            $pdo->beginTransaction();
            $bal = $pdo->prepare('SELECT COALESCE(SUM(qty), 0) FROM inventory_movements WHERE product_id = ? FOR UPDATE');
            $bal->execute([$pid]);
            $balance = (int) $bal->fetchColumn();
            // ADJUSTMENT: qty = hasil hitung fisik; yang disimpan adalah selisihnya
            $delta = $type === 'ADJUSTMENT' ? $qty - $balance : ($type === 'OUT' ? -$qty : $qty);
            if ($delta === 0) {
                $pdo->rollBack();
                throw new RuntimeException('Stok fisik sama dengan saldo sistem; tidak ada penyesuaian.');
            }
            $pdo->prepare('INSERT INTO inventory_movements (product_id, type, qty, note, user_id) VALUES (?, ?, ?, ?, ?)')
                ->execute([$pid, $type, $delta, $note ?: null, $me['id']]);
            $pdo->prepare('UPDATE products SET track_stock = 1 WHERE id = ?')->execute([$pid]);
            $pdo->commit();
            audit('inventory.' . strtolower($type), 'product', $pid, ['delta' => $delta, 'balance_after' => $balance + $delta, 'note' => $note]);
            flash_set('ok', $prod['name'] . ': ' . ($delta > 0 ? '+' : '') . $delta . ' (saldo ' . ($balance + $delta) . ').');
        }
    } catch (RuntimeException $ex) {
        flash_set('err', $ex->getMessage());
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('inventory: ' . $ex->getMessage());
        flash_set('err', 'Terjadi kesalahan sistem.');
    }
    redirect('inventory.php' . ($pid ? '?product=' . $pid : ''));
}

$rows = stock_balances($pdo);
$sel = isset($_GET['product']) ? (int) $_GET['product'] : 0;
$hist = $pdo->prepare('SELECT m.*, p.name, u.name AS user_name, o.order_number FROM inventory_movements m JOIN products p ON p.id = m.product_id
                       LEFT JOIN users u ON u.id = m.user_id LEFT JOIN orders o ON o.id = m.order_id'
                     . ($sel ? ' WHERE m.product_id = ?' : '') . ' ORDER BY m.id DESC LIMIT 100');
$hist->execute($sel ? [$sel] : []);
$history = $hist->fetchAll();
$labels = ['OPENING' => 'Stok awal', 'IN' => 'Masuk', 'OUT' => 'Keluar', 'ADJUSTMENT' => 'Penyesuaian', 'SALE' => 'Penjualan'];

$page_title = 'Inventory';
$base = '../';
$admin = true;
include __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid py-3" style="max-width:1100px">
  <h1 class="h4 mb-1"><i class="bi bi-boxes"></i> Inventory</h1>
  <div class="alert alert-info small py-2">Stok <strong>produk jadi per unit jual</strong> (mis. minuman kemasan, kue). Penjualan mengurangi stok saat pesanan lunas.
    Ini <strong>belum</strong> menghitung food-cost / bahan baku / resep (BOM) — modul bahan baku perlu dikembangkan terpisah.</div>
  <div class="row g-3">
    <div class="col-lg-7">
      <div class="table-responsive"><table class="table table-sm align-middle bg-white">
        <thead><tr><th>Produk</th><th class="text-end">Saldo</th><th class="text-end">Min</th><th>Lacak</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $low = $r['track_stock'] && (int) $r['balance'] <= (int) $r['stock_min']; ?>
          <tr class="<?= $low ? 'table-warning' : '' ?>">
            <td><a href="?product=<?= (int) $r['id'] ?>" class="text-decoration-none"><?= e($r['name']) ?></a><div class="small text-muted"><?= e($r['cat']) ?><?= $r['sku'] ? ' · ' . e($r['sku']) : '' ?></div></td>
            <td class="text-end fw-bold"><?= $r['track_stock'] ? (int) $r['balance'] : '<span class="text-muted">-</span>' ?><?= $low ? ' <span class="badge text-bg-warning">Stok menipis</span>' : '' ?></td>
            <td colspan="3">
              <form method="post" class="d-flex gap-1 justify-content-end align-items-center"><?= csrf_field() ?><input type="hidden" name="action" value="config"><input type="hidden" name="product_id" value="<?= (int) $r['id'] ?>">
                <input class="form-control form-control-sm" style="width:70px" type="number" min="0" name="stock_min" value="<?= (int) $r['stock_min'] ?>" aria-label="Stok minimum <?= e($r['name']) ?>">
                <input class="form-check-input" type="checkbox" name="track_stock" value="1" <?= $r['track_stock'] ? 'checked' : '' ?> aria-label="Lacak stok <?= e($r['name']) ?>">
                <button class="btn btn-sm btn-outline-secondary">Simpan</button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>
    <div class="col-lg-5">
      <form method="post" class="card mb-3"><div class="card-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="movement">
        <h2 class="h6">Catat pergerakan stok</h2>
        <select class="form-select mb-2" name="product_id" required aria-label="Produk">
          <?php foreach ($rows as $r): ?><option value="<?= (int) $r['id'] ?>" <?= $sel === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select mb-2" name="type" aria-label="Jenis"><option value="OPENING">Stok awal</option><option value="IN">Stok masuk</option><option value="OUT">Stok keluar (rusak/terbuang)</option><option value="ADJUSTMENT">Penyesuaian (isi hasil hitung fisik)</option></select>
        <input class="form-control mb-2" type="number" min="0" name="qty" placeholder="Jumlah" required aria-label="Jumlah">
        <input class="form-control mb-2" name="note" maxlength="255" placeholder="Keterangan (wajib untuk keluar/penyesuaian)" aria-label="Keterangan">
        <button class="btn btn-dark w-100">Simpan</button>
      </div></form>
      <h2 class="h6">Riwayat <?= $sel ? '' : '(semua produk)' ?></h2>
      <ul class="list-group small">
        <?php foreach ($history as $h): ?>
          <li class="list-group-item d-flex justify-content-between gap-2"><span><?= e(date('d/m H:i', strtotime($h['created_at']))) ?> · <?= e($h['name']) ?> · <?= e($labels[$h['type']]) ?>
            <?= $h['order_number'] ? '(' . e($h['order_number']) . ')' : '' ?><?= $h['note'] && $h['type'] !== 'SALE' ? ' — ' . e($h['note']) : '' ?></span>
            <span class="fw-bold <?= (int) $h['qty'] < 0 ? 'text-danger' : 'text-success' ?>"><?= (int) $h['qty'] > 0 ? '+' : '' ?><?= (int) $h['qty'] ?></span></li>
        <?php endforeach; ?>
        <?php if (!$history): ?><li class="list-group-item text-muted">Belum ada pergerakan.</li><?php endif; ?>
      </ul>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
