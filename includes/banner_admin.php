<?php
/**
 * CRUD gambar berperiode (flyer & promo). Dipakai admin/flyers.php dan admin/promos.php.
 * Variabel wajib dari pemanggil: $cfg = [table, folder, title, self, sort(bool), hint, ratio_hint]
 * Nama tabel/folder berasal dari konfigurasi di kode (bukan input user).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/upload.php';
require_login();

$pdo = db();
$T = $cfg['table'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        flash_set('err', 'Sesi tidak valid, muat ulang halaman.');
        redirect($cfg['self']);
    }
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $id = filter_var(isset($_POST['id']) ? $_POST['id'] : null, FILTER_VALIDATE_INT);
    try {
        if ($action === 'save') {
            $title = clean_input(isset($_POST['title']) ? $_POST['title'] : '', 100);
            $start = parse_date_input(isset($_POST['start_date']) ? $_POST['start_date'] : '');
            $end = parse_date_input(isset($_POST['end_date']) ? $_POST['end_date'] : '');
            $active = !empty($_POST['is_active']) ? 1 : 0;
            $sort = $cfg['sort'] ? filter_var(isset($_POST['sort_order']) ? $_POST['sort_order'] : 0, FILTER_VALIDATE_INT) : 0;
            if ($title === '') {
                throw new RuntimeException('Judul wajib diisi.');
            }
            // product_id dari client TIDAK dipercaya: harus angka dan benar-benar ada di tabel products
            $pid = filter_var(isset($_POST['product_id']) ? $_POST['product_id'] : null, FILTER_VALIDATE_INT);
            if (!$pid || $pid < 1) {
                throw new RuntimeException('Produk wajib dipilih.');
            }
            $pchk = $pdo->prepare('SELECT COUNT(*) FROM products WHERE id = ?');
            $pchk->execute([$pid]);
            if (!$pchk->fetchColumn()) {
                throw new RuntimeException('Produk yang dipilih tidak ditemukan.');
            }
            if ($start === false || $end === false) {
                throw new RuntimeException('Format tanggal tidak valid.');
            }
            if ($start && $end && $end < $start) {
                throw new RuntimeException('Tanggal selesai tidak boleh sebelum tanggal mulai.');
            }
            if ($sort === false || $sort < 0 || $sort > 9999) {
                throw new RuntimeException('Urutan harus angka 0-9999.');
            }
            $old = null;
            if ($id) {
                $q = $pdo->prepare("SELECT image FROM $T WHERE id = ?");
                $q->execute([$id]);
                $old = $q->fetchColumn();
                if ($old === false) {
                    throw new RuntimeException('Data tidak ditemukan.');
                }
            }
            list($img, $imgErr) = save_upload_image(isset($_FILES['image']) ? $_FILES['image'] : [], $cfg['folder']);
            if ($imgErr) {
                throw new RuntimeException($imgErr);
            }
            if (!$id && !$img) {
                throw new RuntimeException('Gambar wajib diunggah.');
            }
            $cols = $cfg['sort'] ? ', sort_order = ?' : '';
            if ($id) {
                $params = [$title, $pid, $active, $start, $end];
                if ($cfg['sort']) { $params[] = $sort; }
                $params[] = $img;
                $params[] = $id;
                $pdo->prepare("UPDATE $T SET title = ?, product_id = ?, is_active = ?, start_date = ?, end_date = ?$cols, image = COALESCE(?, image) WHERE id = ?")
                    ->execute($params);
                if ($img) {
                    delete_upload_image($old);
                }
            } else {
                $icols = $cfg['sort'] ? ', sort_order' : '';
                $iq = $cfg['sort'] ? ', ?' : '';
                $params = [$title, $pid, $img, $active, $start, $end];
                if ($cfg['sort']) { $params[] = $sort; }
                $pdo->prepare("INSERT INTO $T (title, product_id, image, is_active, start_date, end_date$icols) VALUES (?, ?, ?, ?, ?, ?$iq)")->execute($params);
            }
            flash_set('ok', $cfg['title'] . ' disimpan.');
        } elseif ($action === 'toggle' && $id) {
            $pdo->prepare("UPDATE $T SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
            flash_set('ok', 'Status diubah.');
        } elseif ($action === 'delete' && $id) {
            $q = $pdo->prepare("SELECT image FROM $T WHERE id = ?");
            $q->execute([$id]);
            $img = $q->fetchColumn();
            $pdo->prepare("DELETE FROM $T WHERE id = ?")->execute([$id]);
            delete_upload_image($img ?: null);
            flash_set('ok', 'Data dihapus.');
        }
    } catch (RuntimeException $ex) {
        flash_set('err', $ex->getMessage());
    } catch (Throwable $ex) {
        error_log($ex->getMessage());
        flash_set('err', 'Terjadi kesalahan sistem.');
    }
    redirect($cfg['self']);
}

$order = $cfg['sort'] ? 'sort_order ASC, id DESC' : 'id DESC';
$rows = $pdo->query("SELECT t.*, p.name AS p_name, p.price AS p_price, p.is_available AS p_avail
                     FROM $T t LEFT JOIN products p ON p.id = t.product_id ORDER BY $order")->fetchAll();
$products = $pdo->query('SELECT id, name, price, image, is_available FROM products ORDER BY name')->fetchAll();
$edit = null;
if (isset($_GET['edit'])) {
    foreach ($rows as $r) {
        if ((int) $r['id'] === (int) $_GET['edit']) { $edit = $r; }
    }
}

$page_title = $cfg['title'];
$page = 'banner-admin';
$base = '../';
$admin = true;
include __DIR__ . '/header.php';
?>
<div class="container-fluid py-3">
  <h1 class="h4 mb-1"><?= e($cfg['title']) ?></h1>
  <p class="text-muted small"><?= e($cfg['hint']) ?> Tanggal kosong = tanpa batas.</p>

  <div class="card mb-4"><div class="card-body">
    <h2 class="h6"><?= $edit ? 'Edit' : 'Tambah' ?></h2>
    <form method="post" enctype="multipart/form-data" class="row g-2">
      <?= csrf_field() ?><input type="hidden" name="action" value="save">
      <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
      <div class="col-12 col-md-6"><label class="form-label small">Judul</label>
        <input class="form-control" name="title" maxlength="100" required value="<?= e($edit ? $edit['title'] : '') ?>"></div>
      <div class="col-12 col-md-6"><label class="form-label small">Gambar (JPG/PNG/WebP, maks 2MB; <?= e($cfg['ratio_hint']) ?>)<?= $edit ? ' - kosongkan bila tidak diganti' : '' ?></label>
        <input class="form-control" type="file" name="image" accept="image/jpeg,image/png,image/webp" <?= $edit ? '' : 'required' ?>></div>
      <div class="col-12 col-lg-6"><label class="form-label small" for="product_id">Produk tujuan (wajib)</label>
        <select class="form-select" name="product_id" id="product_id" required>
          <option value="">&mdash; Pilih produk &mdash;</option>
          <?php foreach ($products as $p): ?>
            <option value="<?= (int) $p['id'] ?>" data-name="<?= e($p['name']) ?>" data-price="<?= e(rupiah($p['price'])) ?>"
                    data-img="<?= e('../assets/images/' . ($p['image'] ?: 'placeholder.svg')) ?>" data-avail="<?= (int) $p['is_available'] ?>"
                    <?= $edit && (int) $edit['product_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?> &middot; <?= e(rupiah($p['price'])) ?><?= $p['is_available'] ? '' : ' (Habis)' ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Customer yang mengetuk gambar akan diarahkan ke detail produk ini.</div></div>
      <div class="col-12 col-lg-6">
        <div id="product-preview" class="product-preview d-none" aria-live="polite">
          <img id="pp-img" alt="" width="64" height="64">
          <div class="min-w-0"><div class="fw-semibold text-truncate" id="pp-name"></div>
            <div class="small" id="pp-price"></div><span class="badge" id="pp-status"></span></div>
        </div>
      </div>
      <div class="col-6 col-md-3"><label class="form-label small">Mulai</label>
        <input class="form-control" type="date" name="start_date" value="<?= e($edit ? $edit['start_date'] : '') ?>"></div>
      <div class="col-6 col-md-3"><label class="form-label small">Selesai</label>
        <input class="form-control" type="date" name="end_date" value="<?= e($edit ? $edit['end_date'] : '') ?>"></div>
      <?php if ($cfg['sort']): ?>
      <div class="col-6 col-md-3"><label class="form-label small">Urutan (kecil tampil dulu)</label>
        <input class="form-control" type="number" name="sort_order" min="0" max="9999" value="<?= e($edit ? $edit['sort_order'] : 0) ?>"></div>
      <?php endif; ?>
      <div class="col-6 col-md-3 d-flex align-items-end">
        <div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" id="act" value="1" <?= !$edit || $edit['is_active'] ? 'checked' : '' ?>>
          <label class="form-check-label" for="act">Aktif</label></div>
      </div>
      <div class="col-12">
        <button class="btn btn-dark">Simpan</button>
        <?php if ($edit): ?><a href="<?= e($cfg['self']) ?>" class="btn btn-outline-secondary">Batal</a><?php endif; ?>
      </div>
    </form>
  </div></div>

  <div class="table-responsive">
    <table class="table table-hover align-middle bg-white banner-table">
      <thead><tr><th>Image</th><th>Title</th><th>Product</th><th class="text-end">Price</th><th>Status</th><th>Period</th><?php if ($cfg['sort']): ?><th class="text-center">Order</th><?php endif; ?><th class="text-end">Action</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): list($label, $cls) = period_status($r); ?>
        <tr>
          <td><img class="banner-thumb-sm" alt="" src="../assets/<?= e($r['image']) ?>"></td>
          <td class="fw-semibold text-break"><?= e($r['title']) ?></td>
          <td><?php if ($r['p_name'] !== null): ?><?= e($r['p_name']) ?><?= $r['p_avail'] ? '' : ' <span class="badge text-bg-secondary">Habis</span>' ?>
              <?php else: ?><span class="text-danger small">Belum dikaitkan &mdash; edit &amp; pilih produk</span><?php endif; ?></td>
          <td class="text-end text-nowrap"><?= $r['p_price'] !== null ? e(rupiah($r['p_price'])) : '-' ?></td>
          <td><span class="badge text-bg-<?= e($cls) ?>"><?= e(strtoupper($label)) ?></span></td>
          <td class="small text-nowrap"><?= e(date_id($r['start_date'])) ?><br><?= e(date_id($r['end_date'])) ?></td>
          <?php if ($cfg['sort']): ?><td class="text-center"><?= (int) $r['sort_order'] ?></td><?php endif; ?>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="<?= e($cfg['self']) ?>?edit=<?= (int) $r['id'] ?>">Edit</a>
            <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn btn-sm btn-outline-secondary"><?= $r['is_active'] ? 'Disable' : 'Enable' ?></button></form>
            <form method="post" class="d-inline" onsubmit="return confirm('Hapus data ini?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger">Delete</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">Belum ada data.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>
