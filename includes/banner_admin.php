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
                $params = [$title, $active, $start, $end];
                if ($cfg['sort']) { $params[] = $sort; }
                $params[] = $img;
                $params[] = $id;
                $pdo->prepare("UPDATE $T SET title = ?, is_active = ?, start_date = ?, end_date = ?$cols, image = COALESCE(?, image) WHERE id = ?")
                    ->execute($params);
                if ($img) {
                    delete_upload_image($old);
                }
            } else {
                $icols = $cfg['sort'] ? ', sort_order' : '';
                $iq = $cfg['sort'] ? ', ?' : '';
                $params = [$title, $img, $active, $start, $end];
                if ($cfg['sort']) { $params[] = $sort; }
                $pdo->prepare("INSERT INTO $T (title, image, is_active, start_date, end_date$icols) VALUES (?, ?, ?, ?, ?$iq)")->execute($params);
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
$rows = $pdo->query("SELECT * FROM $T ORDER BY $order")->fetchAll();
$edit = null;
if (isset($_GET['edit'])) {
    foreach ($rows as $r) {
        if ((int) $r['id'] === (int) $_GET['edit']) { $edit = $r; }
    }
}

$page_title = $cfg['title'];
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

  <div class="row g-3">
    <?php foreach ($rows as $r): list($label, $cls) = period_status($r); ?>
      <div class="col-12 col-md-6 col-xl-4">
        <div class="card h-100">
          <img class="card-img-top banner-thumb" alt="" src="../assets/<?= e($r['image']) ?>">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
              <div class="fw-semibold text-break"><?= e($r['title']) ?></div>
              <span class="badge text-bg-<?= e($cls) ?>"><?= e(strtoupper($label)) ?></span>
            </div>
            <div class="small text-muted mt-1">Start: <?= e(date_id($r['start_date'])) ?> &middot; End: <?= e(date_id($r['end_date'])) ?></div>
            <?php if ($cfg['sort']): ?><div class="small text-muted">Order: <?= (int) $r['sort_order'] ?></div><?php endif; ?>
          </div>
          <div class="card-footer bg-white d-flex gap-2 flex-wrap">
            <a class="btn btn-sm btn-outline-primary" href="<?= e($cfg['self']) ?>?edit=<?= (int) $r['id'] ?>">Edit</a>
            <form method="post" class="m-0"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn btn-sm btn-outline-secondary"><?= $r['is_active'] ? 'Disable' : 'Enable' ?></button></form>
            <form method="post" class="m-0" onsubmit="return confirm('Hapus data ini?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger">Delete</button></form>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$rows): ?><p class="text-muted">Belum ada data.</p><?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>
