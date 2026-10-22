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
// Kolom link_url dibuat oleh migrasi 005; tanpa migrasi, form tetap berfungsi hanya dengan pilihan Produk.
try {
    $hasLink = (bool) $pdo->query("SHOW COLUMNS FROM $T LIKE 'link_url'")->fetchColumn();
} catch (Throwable $ex) {
    $hasLink = false;
}

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
            // Tujuan klik: SALAH SATU produk (wajib dipilih) atau link langsung. Input client tidak dipercaya.
            $dest = $hasLink && isset($_POST['dest']) && $_POST['dest'] === 'link' ? 'link' : 'product';
            $pid = null;
            $url = null;
            if ($dest === 'link') {
                $url = safe_link_url(clean_input(isset($_POST['link_url']) ? $_POST['link_url'] : '', 500));
                if ($url === null) {
                    throw new RuntimeException('Link langsung wajib diisi dengan alamat http:// atau https:// yang valid (tanpa spasi, maks 500 karakter).');
                }
            } else {
                $pid = filter_var(isset($_POST['product_id']) ? $_POST['product_id'] : null, FILTER_VALIDATE_INT);
                if (!$pid || $pid < 1) {
                    throw new RuntimeException('Produk wajib dipilih (atau pilih tujuan Link langsung).');
                }
                $pchk = $pdo->prepare('SELECT COUNT(*) FROM products WHERE id = ?');
                $pchk->execute([$pid]);
                if (!$pchk->fetchColumn()) {
                    throw new RuntimeException('Produk yang dipilih tidak ditemukan.');
                }
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
            $lcol = $hasLink ? ', link_url = ?' : '';
            if ($id) {
                $params = [$title, $pid];
                if ($hasLink) { $params[] = $url; }
                array_push($params, $active, $start, $end);
                if ($cfg['sort']) { $params[] = $sort; }
                $params[] = $img;
                $params[] = $id;
                $pdo->prepare("UPDATE $T SET title = ?, product_id = ?$lcol, is_active = ?, start_date = ?, end_date = ?$cols, image = COALESCE(?, image) WHERE id = ?")
                    ->execute($params);
                if ($img) {
                    delete_upload_image($old);
                }
            } else {
                $icols = $cfg['sort'] ? ', sort_order' : '';
                $iq = $cfg['sort'] ? ', ?' : '';
                $params = [$title, $pid];
                if ($hasLink) { $params[] = $url; }
                array_push($params, $img, $active, $start, $end);
                if ($cfg['sort']) { $params[] = $sort; }
                $lins = $hasLink ? ', link_url' : '';
                $lq = $hasLink ? ', ?' : '';
                $pdo->prepare("INSERT INTO $T (title, product_id$lins, image, is_active, start_date, end_date$icols) VALUES (?, ?$lq, ?, ?, ?, ?$iq)")->execute($params);
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
      <?php $editLink = $edit && $hasLink && !empty($edit['link_url']); ?>
      <div class="col-12">
        <div class="form-label small mb-1" id="dest-label">Tujuan saat gambar diketuk</div>
        <?php if ($hasLink): ?>
        <div class="btn-group dest-switch" role="group" aria-labelledby="dest-label">
          <input type="radio" class="btn-check" name="dest" id="dest-product" value="product" autocomplete="off" <?= $editLink ? '' : 'checked' ?>>
          <label class="btn btn-outline-dark btn-sm" for="dest-product"><i class="bi bi-box-seam" aria-hidden="true"></i> Produk</label>
          <input type="radio" class="btn-check" name="dest" id="dest-link" value="link" autocomplete="off" <?= $editLink ? 'checked' : '' ?>>
          <label class="btn btn-outline-dark btn-sm" for="dest-link"><i class="bi bi-link-45deg" aria-hidden="true"></i> Link langsung</label>
        </div>
        <?php endif; ?>
      </div>
      <div class="col-12 col-lg-6" id="dest-product-box"><label class="form-label small" for="product_id">Produk tujuan (wajib)</label>
        <select class="form-select" name="product_id" id="product_id" <?= $editLink ? '' : 'required' ?>>
          <option value="">&mdash; Pilih produk &mdash;</option>
          <?php foreach ($products as $p): ?>
            <option value="<?= (int) $p['id'] ?>" data-name="<?= e($p['name']) ?>" data-price="<?= e(rupiah($p['price'])) ?>"
                    data-img="<?= e('../assets/images/' . ($p['image'] ?: 'placeholder.svg')) ?>" data-avail="<?= (int) $p['is_available'] ?>"
                    <?= $edit && (int) $edit['product_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?> &middot; <?= e(rupiah($p['price'])) ?><?= $p['is_available'] ? '' : ' (Habis)' ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Customer yang mengetuk gambar akan diarahkan ke detail produk ini.</div></div>
      <div class="col-12 col-lg-6" id="product-preview-box">
        <div id="product-preview" class="product-preview d-none" aria-live="polite">
          <img id="pp-img" alt="" width="64" height="64">
          <div class="min-w-0"><div class="fw-semibold text-truncate" id="pp-name"></div>
            <div class="small" id="pp-price"></div><span class="badge" id="pp-status"></span></div>
        </div>
      </div>
      <?php if ($hasLink): ?>
      <div class="col-12 <?= $editLink ? '' : 'd-none' ?>" id="dest-link-box"><label class="form-label small" for="link_url">Link langsung (wajib)</label>
        <input class="form-control" type="url" name="link_url" id="link_url" maxlength="500" inputmode="url" placeholder="https://contoh.com/halaman" value="<?= e($editLink ? $edit['link_url'] : '') ?>" <?= $editLink ? 'required' : 'disabled' ?>>
        <div class="form-text">Hanya alamat <code>http://</code> atau <code>https://</code>. Dibuka di tab baru supaya pesanan customer tidak hilang.</div></div>
      <?php else: ?>
      <div class="col-12"><div class="alert alert-warning py-2 small mb-0">Pilihan <strong>Link langsung</strong> belum aktif: jalankan migrasi <code>database/migrations/005_banner_direct_link.sql</code> sekali.</div></div>
      <?php endif; ?>
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
      <thead><tr><th>Image</th><th>Title</th><th>Destination</th><th class="text-end">Price</th><th>Status</th><th>Period</th><?php if ($cfg['sort']): ?><th class="text-center">Order</th><?php endif; ?><th class="text-end">Action</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): list($label, $cls) = period_status($r); ?>
        <tr>
          <td><img class="banner-thumb-sm" alt="" src="../assets/<?= e($r['image']) ?>"></td>
          <td class="fw-semibold text-break"><?= e($r['title']) ?></td>
          <?php $rowLink = $hasLink && !empty($r['link_url']) ? safe_link_url($r['link_url']) : null; ?>
          <td><?php if ($rowLink !== null): ?><span class="badge text-bg-info me-1">Link</span><a class="text-break small" href="<?= e($rowLink) ?>" target="_blank" rel="noopener noreferrer"><?= e((string) parse_url($rowLink, PHP_URL_HOST)) ?></a>
              <?php elseif ($r['p_name'] !== null): ?><?= e($r['p_name']) ?><?= $r['p_avail'] ? '' : ' <span class="badge text-bg-secondary">Habis</span>' ?>
              <?php else: ?><span class="text-danger small">Belum ada tujuan &mdash; edit &amp; pilih produk atau link</span><?php endif; ?></td>
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
