<?php
require_once __DIR__ . '/functions.php';

function is_logged_in(): bool
{
    start_session();
    return !empty($_SESSION['user_id']);
}

/** Wajib login. $json=true untuk endpoint API (balas 401, bukan redirect). */
function require_login(bool $json = false): void
{
    if (is_logged_in() && current_user()) {
        return;
    }
    if ($json) {
        json_response(['ok' => false, 'error' => 'Sesi berakhir, silakan login ulang.'], 401);
    }
    header('Location: login.php');
    exit;
}

const LOGIN_MAX_FAILS = 5;      // percobaan gagal
const LOGIN_WINDOW_MIN = 10;    // dalam menit

function login_locked(): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > (NOW() - INTERVAL ' . LOGIN_WINDOW_MIN . ' MINUTE)');
    $st->execute([client_ip()]);
    return (int) $st->fetchColumn() >= LOGIN_MAX_FAILS;
}

/** Verifikasi username+password. Return true bila sukses (session di-regenerate). */
function attempt_login(string $username, string $password): bool
{
    $st = db()->prepare('SELECT id, password, name, is_active FROM users WHERE username = ?');
    $st->execute([$username]);
    $u = $st->fetch();
    // password_verify tetap dijalankan walau user tidak ada (mengurangi timing leak)
    $hash = $u ? $u['password'] : '$2y$10$usesomesillystringforsalt.abcdefghijklmnopqrstuvwxyzABCD';
    if (!password_verify($password, $hash) || !$u || !$u['is_active']) {
        db()->prepare('INSERT INTO login_attempts (ip) VALUES (?)')->execute([client_ip()]);
        usleep(400000);
        return false;
    }
    db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([client_ip()]);
    db()->exec('DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)');
    start_session();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $u['id'];
    $_SESSION['user_name'] = $u['name'];
    return true;
}

function logout(): void
{
    start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* ================= Role & permission (divalidasi di SERVER, bukan hanya UI) ================= */

const PERMISSIONS = [
    'dashboard.view' => 'Lihat dashboard pesanan baru',
    'orders.view' => 'Lihat daftar & detail pesanan',
    'orders.manage' => 'Terima / proses pesanan QR',
    'pos.use' => 'Gunakan POS (buat & ubah pesanan)',
    'pos.discount' => 'Beri diskon',
    'order.void' => 'Batalkan pesanan / item yang sudah dikirim ke dapur',
    'table.move' => 'Pindah, gabung & pisah tagihan',
    'payment.take' => 'Terima pembayaran',
    'payment.refund' => 'Refund pembayaran',
    'shift.manage' => 'Buka & tutup shift kasir sendiri',
    'shift.override' => 'Override tutup shift & lihat semua shift',
    'kitchen.view' => 'Lihat layar dapur/bar',
    'kitchen.update' => 'Ubah status produksi dapur/bar',
    'delivery.view' => 'Lihat semua pesanan delivery',
    'delivery.manage' => 'Kelola delivery (tugaskan kurir, ubah status)',
    'delivery.courier' => 'Tugas pengantaran milik sendiri',
    'reports.view' => 'Lihat laporan',
    'catalog.manage' => 'Kelola produk, kategori, top seller, promo, flyer',
    'inventory.manage' => 'Kelola stok',
    'tables.manage' => 'Kelola master meja & QR',
    'settings.manage' => 'Pengaturan sistem (pajak, service, pembayaran)',
    'users.manage' => 'Kelola user & hak akses',
    'integration.manage' => 'Integrasi Accurate',
    'audit.view' => 'Lihat audit log',
];

/** User yang sedang login (+ role). Null bila tidak login / dinonaktifkan. */
function current_user(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    start_session();
    $cache = null;
    if (!empty($_SESSION['user_id'])) {
        $st = db()->prepare('SELECT u.id, u.username, u.name, u.is_active, u.role_id, r.code AS role_code, r.name AS role_name
                             FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
        $st->execute([$_SESSION['user_id']]);
        $u = $st->fetch();
        if ($u && $u['is_active']) {
            $cache = $u;
        } else {
            $_SESSION = [];   // user dihapus/dinonaktifkan: sesi langsung tidak berlaku
        }
    }
    return $cache;
}

/** Daftar izin user saat ini. Administrator selalu punya semua izin. */
function user_permissions(): array
{
    static $perms = null;
    if ($perms !== null) {
        return $perms;
    }
    $u = current_user();
    if (!$u) {
        return $perms = [];
    }
    if ($u['role_code'] === 'ADMIN') {
        return $perms = array_keys(PERMISSIONS);
    }
    $st = db()->prepare('SELECT permission FROM role_permissions WHERE role_id = ?');
    $st->execute([$u['role_id']]);
    return $perms = $st->fetchAll(PDO::FETCH_COLUMN);
}

/** true bila user punya SALAH SATU izin yang diberikan. */
function can($perm): bool
{
    $have = user_permissions();
    foreach ((array) $perm as $p) {
        if (in_array($p, $have, true)) {
            return true;
        }
    }
    return false;
}

/** Wajib login + izin. Halaman: tampilkan 403; API ($json): balas JSON 403. */
function require_permission($perm, bool $json = false): void
{
    require_login($json);
    if (can($perm)) {
        return;
    }
    if ($json) {
        json_response(['ok' => false, 'error' => 'Anda tidak memiliki izin untuk tindakan ini.'], 403);
    }
    http_response_code(403);
    $page_title = 'Akses Ditolak';
    $base = '../';
    $admin = true;
    include ROOT_PATH . '/includes/header.php';
    echo '<div class="container py-5 text-center"><i class="bi bi-shield-lock fs-1 text-muted"></i>'
       . '<h1 class="h4 mt-3">Akses ditolak</h1><p class="text-muted">Role Anda tidak memiliki izin untuk halaman ini.</p>'
       . '<a class="btn btn-dark" href="' . e(home_page()) . '">Kembali</a></div>';
    include ROOT_PATH . '/includes/footer.php';
    exit;
}

/** Halaman awal sesuai izin (setelah login). */
function home_page(): string
{
    $map = ['dashboard.view' => 'dashboard.php', 'pos.use' => 'pos.php', 'kitchen.view' => 'kitchen.php',
            'delivery.view' => 'delivery.php', 'delivery.courier' => 'delivery.php', 'reports.view' => 'reports.php'];
    foreach ($map as $perm => $page) {
        if (can($perm)) {
            return $page;
        }
    }
    return 'account.php';
}

/** Catat tindakan penting. $detail tidak boleh berisi password/secret. */
function audit(string $action, string $entity, $entityId = null, array $detail = []): void
{
    try {
        $u = current_user();
        db()->prepare('INSERT INTO audit_logs (user_id, action, entity, entity_id, detail, ip) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$u ? $u['id'] : null, $action, $entity, $entityId, $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null, client_ip()]);
    } catch (Throwable $ex) {
        error_log('audit: ' . $ex->getMessage());
    }
}

/** Untuk API JSON: wajib POST + CSRF. */
function require_post_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['ok' => false, 'error' => 'Metode tidak diizinkan.'], 405);
    }
    if (!csrf_valid()) {
        json_response(['ok' => false, 'error' => 'Token keamanan tidak valid. Muat ulang halaman.'], 403);
    }
}

/** Body JSON request (array) atau 400. */
function json_input(): array
{
    $in = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in)) {
        json_response(['ok' => false, 'error' => 'Data tidak valid.'], 400);
    }
    return $in;
}
