<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/orders.php';
require_permission('settings.manage');

$pdo = db();
// [key => [label, tipe, aturan]]
$fields = [
    'outlet_name' => ['Nama outlet (struk)', 'text', 100],
    'outlet_address' => ['Alamat outlet', 'text', 200],
    'outlet_phone' => ['Telepon outlet', 'text', 30],
    'receipt_footer' => ['Catatan kaki struk', 'text', 200],
    'receipt_width' => ['Lebar kertas struk', 'select', ['80' => '80 mm', '58' => '58 mm']],
    'tax_rate' => ['Pajak (%)', 'percent', null],
    'tax_include_service' => ['Pajak dihitung termasuk service charge', 'bool', null],
    'service_rate' => ['Service charge (%)', 'percent', null],
    'service_dine_in_only' => ['Service charge hanya untuk dine-in', 'bool', null],
    'delivery_fee_default' => ['Ongkos kirim default (Rp)', 'money', null],
    'table_auto_release' => ['Meja otomatis tersedia setelah lunas', 'bool', null],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        flash_set('err', 'Sesi tidak valid, coba lagi.');
        redirect('settings.php');
    }
    $section = isset($_POST['section']) ? $_POST['section'] : '';
    try {
        if ($section === 'general') {
            $changes = [];
            foreach ($fields as $k => $f) {
                $raw = isset($_POST[$k]) ? (string) $_POST[$k] : '';
                if ($f[1] === 'bool') {
                    $v = isset($_POST[$k]) ? '1' : '0';
                } elseif ($f[1] === 'percent') {
                    if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $raw) || (float) $raw > 100) {
                        throw new RuntimeException($f[0] . ' harus angka 0 - 100 (maks 2 desimal).');
                    }
                    $v = rtrim(rtrim(number_format((float) $raw, 2, '.', ''), '0'), '.');
                } elseif ($f[1] === 'money') {
                    if (!preg_match('/^\d{1,9}$/', $raw)) {
                        throw new RuntimeException($f[0] . ' harus angka bulat.');
                    }
                    $v = (string) (int) $raw;
                } elseif ($f[1] === 'select') {
                    if (!isset($f[2][$raw])) {
                        throw new RuntimeException($f[0] . ' tidak valid.');
                    }
                    $v = $raw;
                } else {
                    $v = clean_input($raw, $f[2]);
                }
                $old = setting($k);
                if ($old !== $v) {
                    set_setting($k, $v);
                    $changes[$k] = ['from' => $old, 'to' => $v];
                }
            }
            if ($changes) {
                audit('settings.update', 'settings', null, $changes);
            }
            flash_set('ok', $changes ? 'Pengaturan disimpan (' . count($changes) . ' perubahan). Transaksi lama tetap memakai tarif saat transaksi dibuat.' : 'Tidak ada perubahan.');
        } elseif ($section === 'methods') {
            $rows = isset($_POST['m']) && is_array($_POST['m']) ? $_POST['m'] : [];
            foreach ($rows as $code => $r) {
                $name = clean_input(isset($r['name']) ? $r['name'] : '', 50);
                if ($name === '') {
                    throw new RuntimeException('Nama metode wajib diisi.');
                }
                $pdo->prepare('UPDATE payment_methods SET name = ?, is_active = ?, requires_reference = ?, sort_order = ? WHERE code = ?')
                    ->execute([$name, !empty($r['active']) ? 1 : 0, !empty($r['ref']) ? 1 : 0, max(0, min(999, (int) (isset($r['sort']) ? $r['sort'] : 0))), (string) $code]);
            }
            $newCode = strtoupper(clean_input(isset($_POST['new_code']) ? $_POST['new_code'] : '', 20));
            if ($newCode !== '') {
                $newName = clean_input(isset($_POST['new_name']) ? $_POST['new_name'] : '', 50);
                if (!preg_match('/^[A-Z0-9_]{2,20}$/', $newCode) || $newName === '') {
                    throw new RuntimeException('Kode metode baru: huruf besar/angka/underscore (2-20), nama wajib.');
                }
                try {
                    $pdo->prepare("INSERT INTO payment_methods (code, name, type, requires_reference, sort_order) VALUES (?, ?, 'NONCASH', ?, 50)")
                        ->execute([$newCode, $newName, !empty($_POST['new_ref']) ? 1 : 0]);
                } catch (PDOException $ex) {
                    throw new RuntimeException('Kode metode sudah ada.');
                }
            }
            if (!(int) $pdo->query('SELECT COUNT(*) FROM payment_methods WHERE is_active = 1')->fetchColumn()) {
                $pdo->exec("UPDATE payment_methods SET is_active = 1 WHERE code = 'CASH'");
                throw new RuntimeException('Minimal satu metode pembayaran harus aktif (Tunai diaktifkan kembali).');
            }
            audit('settings.payment_methods', 'settings', null, ['methods' => array_keys($rows), 'added' => $newCode]);
            flash_set('ok', 'Metode pembayaran disimpan.');
        }
    } catch (RuntimeException $ex) {
        flash_set('err', $ex->getMessage());
    } catch (Throwable $ex) {
        error_log('settings: ' . $ex->getMessage());
        flash_set('err', 'Terjadi kesalahan sistem.');
    }
    redirect('settings.php');
}

$methods = $pdo->query('SELECT * FROM payment_methods ORDER BY sort_order, name')->fetchAll();
$page_title = 'Settings';
$base = '../';
$admin = true;
include __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid py-3" style="max-width:960px">
  <h1 class="h4 mb-3"><i class="bi bi-gear"></i> Settings</h1>
  <form method="post" class="card mb-4"><div class="card-body">
    <?= csrf_field() ?><input type="hidden" name="section" value="general">
    <h2 class="h6">Outlet, struk, pajak & biaya</h2>
    <p class="small text-muted">Tarif disimpan pada setiap transaksi saat dibuat, sehingga laporan lama tidak berubah ketika tarif diganti.</p>
    <div class="row g-3">
      <?php foreach ($fields as $k => $f): $v = setting($k); ?>
        <div class="col-12 col-md-6">
          <?php if ($f[1] === 'bool'): ?>
            <div class="form-check form-switch mt-md-4"><input class="form-check-input" type="checkbox" role="switch" name="<?= e($k) ?>" id="s-<?= e($k) ?>" value="1" <?= $v === '1' ? 'checked' : '' ?>>
              <label class="form-check-label" for="s-<?= e($k) ?>"><?= e($f[0]) ?></label></div>
          <?php else: ?>
            <label class="form-label small fw-semibold" for="s-<?= e($k) ?>"><?= e($f[0]) ?></label>
            <?php if ($f[1] === 'select'): ?>
              <select class="form-select" name="<?= e($k) ?>" id="s-<?= e($k) ?>"><?php foreach ($f[2] as $ov => $ol): ?><option value="<?= e($ov) ?>" <?= $v === (string) $ov ? 'selected' : '' ?>><?= e($ol) ?></option><?php endforeach; ?></select>
            <?php else: ?>
              <input class="form-control" name="<?= e($k) ?>" id="s-<?= e($k) ?>" value="<?= e($v) ?>"
                <?= $f[1] === 'percent' ? 'inputmode="decimal" pattern="\d{1,3}(\.\d{1,2})?"' : ($f[1] === 'money' ? 'inputmode="numeric" pattern="\d{1,9}"' : 'maxlength="' . (int) $f[2] . '"') ?>>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <button class="btn btn-dark mt-3">Simpan</button>
  </div></form>

  <form method="post" class="card mb-4"><div class="card-body">
    <?= csrf_field() ?><input type="hidden" name="section" value="methods">
    <h2 class="h6">Metode pembayaran</h2>
    <p class="small text-muted">Semua metode non-tunai dicatat <strong>manual</strong> oleh kasir (belum ada integrasi payment gateway). Jangan menyimpan data kartu.</p>
    <div class="table-responsive"><table class="table table-sm align-middle">
      <thead><tr><th>Kode</th><th>Nama</th><th>Jenis</th><th class="text-center">Aktif</th><th class="text-center">Wajib referensi</th><th>Urutan</th></tr></thead>
      <tbody>
      <?php foreach ($methods as $m): $c = e($m['code']); ?>
        <tr><td><code><?= $c ?></code></td>
          <td><input class="form-control form-control-sm" name="m[<?= $c ?>][name]" value="<?= e($m['name']) ?>" maxlength="50" aria-label="Nama <?= $c ?>"></td>
          <td><?= $m['type'] === 'CASH' ? 'Tunai' : 'Non-tunai' ?></td>
          <td class="text-center"><input class="form-check-input" type="checkbox" name="m[<?= $c ?>][active]" value="1" <?= $m['is_active'] ? 'checked' : '' ?> aria-label="Aktif <?= $c ?>"></td>
          <td class="text-center"><?php if ($m['type'] !== 'CASH'): ?><input class="form-check-input" type="checkbox" name="m[<?= $c ?>][ref]" value="1" <?= $m['requires_reference'] ? 'checked' : '' ?> aria-label="Referensi <?= $c ?>"><?php endif; ?></td>
          <td style="width:90px"><input class="form-control form-control-sm" type="number" min="0" max="999" name="m[<?= $c ?>][sort]" value="<?= (int) $m['sort_order'] ?>" aria-label="Urutan <?= $c ?>"></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <div class="row g-2 align-items-center">
      <div class="col-4 col-md-2"><input class="form-control form-control-sm" name="new_code" maxlength="20" placeholder="KODE baru" aria-label="Kode metode baru"></div>
      <div class="col-8 col-md-4"><input class="form-control form-control-sm" name="new_name" maxlength="50" placeholder="Nama metode baru (non-tunai)" aria-label="Nama metode baru"></div>
      <div class="col-6 col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="new_ref" value="1" id="new_ref"><label class="form-check-label small" for="new_ref">Wajib referensi</label></div></div>
      <div class="col-6 col-md-3"><button class="btn btn-dark btn-sm w-100">Simpan metode</button></div>
    </div>
  </div></form>

  <div class="d-flex gap-2 flex-wrap">
    <?php if (can('tables.manage')): ?><a class="btn btn-outline-dark" href="qr.php"><i class="bi bi-qr-code"></i> Base URL QR</a><?php endif; ?>
    <a class="btn btn-outline-dark" href="account.php"><i class="bi bi-person-circle"></i> Akun saya / password</a>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
