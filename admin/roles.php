<?php
require_once __DIR__ . '/../includes/auth.php';
require_permission('users.manage');

$pdo = db();
$roles = $pdo->query("SELECT id, code, name FROM roles WHERE code <> 'ADMIN' ORDER BY id")->fetchAll();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        flash_set('err', 'Sesi tidak valid, coba lagi.');
        redirect('roles.php');
    }
    $in = isset($_POST['p']) && is_array($_POST['p']) ? $_POST['p'] : [];
    try {
        $pdo->beginTransaction();
        $changes = [];
        foreach ($roles as $r) {
            $want = isset($in[$r['id']]) && is_array($in[$r['id']]) ? array_values(array_intersect(array_keys($in[$r['id']]), array_keys(PERMISSIONS))) : [];
            $old = $pdo->prepare('SELECT permission FROM role_permissions WHERE role_id = ?');
            $old->execute([$r['id']]);
            $have = $old->fetchAll(PDO::FETCH_COLUMN);
            $add = array_diff($want, $have);
            $del = array_diff($have, $want);
            if ($add || $del) {
                $changes[$r['code']] = ['added' => array_values($add), 'removed' => array_values($del)];
            }
            $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$r['id']]);
            $ins = $pdo->prepare('INSERT INTO role_permissions (role_id, permission) VALUES (?, ?)');
            foreach ($want as $p) {
                $ins->execute([$r['id'], $p]);
            }
        }
        $pdo->commit();
        if ($changes) {
            audit('role.permissions', 'role', null, $changes);
        }
        flash_set('ok', $changes ? 'Hak akses diperbarui.' : 'Tidak ada perubahan.');
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('roles: ' . $ex->getMessage());
        flash_set('err', 'Terjadi kesalahan sistem.');
    }
    redirect('roles.php');
}
$have = [];
foreach ($pdo->query('SELECT role_id, permission FROM role_permissions') as $r) {
    $have[(int) $r['role_id']][$r['permission']] = true;
}
$page_title = 'Hak Akses';
$base = '../';
$admin = true;
include __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid py-3">
  <a href="users.php" class="btn btn-sm btn-outline-secondary mb-2">&larr; Users</a>
  <h1 class="h4">Hak akses per role</h1>
  <p class="small text-muted">Administrator selalu memiliki semua izin. Izin diperiksa di server untuk setiap halaman & API (tidak hanya menyembunyikan tombol).</p>
  <form method="post"><?= csrf_field() ?>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle bg-white perm-table">
      <thead><tr><th>Izin</th><th class="text-center">Admin</th><?php foreach ($roles as $r): ?><th class="text-center"><?= e($r['name']) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach (PERMISSIONS as $k => $label): ?>
        <tr><td><?= e($label) ?><div class="small text-muted"><code><?= e($k) ?></code></div></td>
          <td class="text-center"><i class="bi bi-check-lg text-success" aria-label="ya"></i></td>
          <?php foreach ($roles as $r): ?><td class="text-center"><input class="form-check-input" type="checkbox" name="p[<?= (int) $r['id'] ?>][<?= e($k) ?>]" value="1" <?= isset($have[(int) $r['id']][$k]) ? 'checked' : '' ?> aria-label="<?= e($r['name'] . ': ' . $label) ?>"></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <button class="btn btn-dark">Simpan hak akses</button>
  </form>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
