<?php
require_once __DIR__ . '/../includes/auth.php';
require_permission('users.manage');

$pdo = db();
$me = current_user();
$roles = $pdo->query('SELECT id, code, name FROM roles ORDER BY id')->fetchAll();
$roleIds = array_column($roles, 'id');
$adminRole = (int) $pdo->query("SELECT id FROM roles WHERE code = 'ADMIN'")->fetchColumn();

/** Pastikan tetap ada minimal satu Administrator aktif. */
function admins_left(PDO $pdo, int $adminRole, int $exceptId): int
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role_id = ? AND is_active = 1 AND id <> ?');
    $st->execute([$adminRole, $exceptId]);
    return (int) $st->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        flash_set('err', 'Sesi tidak valid, coba lagi.');
        redirect('users.php');
    }
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    try {
        $name = clean_input(isset($_POST['name']) ? $_POST['name'] : '', 100);
        $role = (int) (isset($_POST['role_id']) ? $_POST['role_id'] : 0);
        $pass = isset($_POST['password']) ? (string) $_POST['password'] : '';
        if ($action === 'create') {
            $username = strtolower(clean_input(isset($_POST['username']) ? $_POST['username'] : '', 50));
            if (!preg_match('/^[a-z0-9._-]{3,50}$/', $username)) {
                throw new RuntimeException('Username 3-50 karakter: huruf kecil, angka, titik, minus, underscore.');
            }
            if ($name === '' || !in_array($role, array_map('intval', $roleIds), true)) {
                throw new RuntimeException('Nama dan role wajib diisi.');
            }
            if (strlen($pass) < 8 || strlen($pass) > 72) {
                throw new RuntimeException('Password minimal 8 karakter.');
            }
            try {
                $pdo->prepare('INSERT INTO users (username, password, name, role_id) VALUES (?, ?, ?, ?)')->execute([$username, password_hash($pass, PASSWORD_DEFAULT), $name, $role]);
            } catch (PDOException $ex) {
                throw new RuntimeException('Username sudah dipakai.');
            }
            audit('user.create', 'user', (int) $pdo->lastInsertId(), ['username' => $username, 'role_id' => $role]);
            flash_set('ok', 'User ' . $username . ' dibuat.');
        } elseif ($action === 'update') {
            $id = (int) (isset($_POST['id']) ? $_POST['id'] : 0);
            $active = !empty($_POST['is_active']) ? 1 : 0;
            $cur = $pdo->prepare('SELECT * FROM users WHERE id = ?');
            $cur->execute([$id]);
            $u = $cur->fetch();
            if (!$u || $name === '' || !in_array($role, array_map('intval', $roleIds), true)) {
                throw new RuntimeException('Data user tidak valid.');
            }
            if ($id === (int) $me['id'] && (!$active || $role !== (int) $u['role_id'])) {
                throw new RuntimeException('Anda tidak dapat menonaktifkan atau mengubah role akun Anda sendiri.');
            }
            if ((int) $u['role_id'] === $adminRole && ($role !== $adminRole || !$active) && admins_left($pdo, $adminRole, $id) === 0) {
                throw new RuntimeException('Harus ada minimal satu Administrator aktif.');
            }
            if ($pass !== '' && (strlen($pass) < 8 || strlen($pass) > 72)) {
                throw new RuntimeException('Password baru minimal 8 karakter.');
            }
            $pdo->prepare('UPDATE users SET name = ?, role_id = ?, is_active = ? WHERE id = ?')->execute([$name, $role, $active, $id]);
            $changes = ['name' => $name, 'role_id' => $role, 'is_active' => $active];
            if ($pass !== '') {
                $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
                $changes['password_reset'] = true;   // nilai password tidak pernah dicatat
            }
            audit('user.update', 'user', $id, $changes);
            flash_set('ok', 'User ' . $u['username'] . ' diperbarui.');
        }
    } catch (RuntimeException $ex) {
        flash_set('err', $ex->getMessage());
    } catch (Throwable $ex) {
        error_log('users: ' . $ex->getMessage());
        flash_set('err', 'Terjadi kesalahan sistem.');
    }
    redirect('users.php');
}

$users = $pdo->query('SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id ORDER BY u.is_active DESC, r.id, u.name')->fetchAll();
$page_title = 'Users & Roles';
$base = '../';
$admin = true;
include __DIR__ . '/../includes/header.php';
$roleSelect = function ($sel) use ($roles) {
    $h = '';
    foreach ($roles as $r) {
        $h .= '<option value="' . (int) $r['id'] . '"' . ((int) $sel === (int) $r['id'] ? ' selected' : '') . '>' . e($r['name']) . '</option>';
    }
    return $h;
};
?>
<div class="container-fluid py-3" style="max-width:1100px">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 m-0"><i class="bi bi-people"></i> Users</h1>
    <a class="btn btn-outline-dark" href="roles.php"><i class="bi bi-shield-lock"></i> Hak akses per role</a>
  </div>
  <form method="post" class="card mb-4"><div class="card-body row g-2 align-items-end">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <div class="col-6 col-md-2"><label class="form-label small" for="nu">Username</label><input class="form-control" id="nu" name="username" required maxlength="50" autocomplete="off"></div>
    <div class="col-6 col-md-3"><label class="form-label small" for="nn">Nama</label><input class="form-control" id="nn" name="name" required maxlength="100"></div>
    <div class="col-6 col-md-2"><label class="form-label small" for="nr">Role</label><select class="form-select" id="nr" name="role_id"><?= $roleSelect(0) ?></select></div>
    <div class="col-6 col-md-3"><label class="form-label small" for="np">Password (min. 8)</label><input class="form-control" id="np" type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password"></div>
    <div class="col-12 col-md-2"><button class="btn btn-dark w-100">Tambah user</button></div>
  </div></form>
  <div class="table-responsive"><table class="table align-middle bg-white">
    <thead><tr><th>Username</th><th>Nama</th><th>Role</th><th class="text-center">Aktif</th><th>Reset password</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): $f = 'u' . (int) $u['id']; ?>
      <tr class="<?= $u['is_active'] ? '' : 'text-muted' ?>">
        <td><code><?= e($u['username']) ?></code><?= (int) $u['id'] === (int) $me['id'] ? ' <span class="badge text-bg-info">Anda</span>' : '' ?></td>
        <td><input form="<?= $f ?>" class="form-control form-control-sm" name="name" value="<?= e($u['name']) ?>" maxlength="100" aria-label="Nama <?= e($u['username']) ?>"></td>
        <td><select form="<?= $f ?>" class="form-select form-select-sm" name="role_id" aria-label="Role <?= e($u['username']) ?>"><?= $roleSelect($u['role_id']) ?></select></td>
        <td class="text-center"><input form="<?= $f ?>" class="form-check-input" type="checkbox" name="is_active" value="1" <?= $u['is_active'] ? 'checked' : '' ?> aria-label="Aktif <?= e($u['username']) ?>"></td>
        <td><input form="<?= $f ?>" class="form-control form-control-sm" type="password" name="password" minlength="8" maxlength="72" placeholder="kosongkan = tetap" autocomplete="new-password" aria-label="Password baru <?= e($u['username']) ?>"></td>
        <td><form method="post" id="<?= $f ?>"><?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><button class="btn btn-sm btn-outline-primary">Simpan</button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
