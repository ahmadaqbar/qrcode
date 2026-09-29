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
    if (is_logged_in()) {
        return;
    }
    if ($json) {
        json_response(['ok' => false, 'error' => 'Sesi berakhir, silakan login ulang.'], 401);
    }
    header('Location: login.php');
    exit;
}

/** Verifikasi username+password. Return true bila sukses (session di-regenerate). */
function attempt_login(string $username, string $password): bool
{
    $st = db()->prepare('SELECT id, password, name FROM users WHERE username = ?');
    $st->execute([$username]);
    $u = $st->fetch();
    // password_verify tetap dijalankan walau user tidak ada (mengurangi timing leak)
    $hash = $u ? $u['password'] : '$2y$10$usesomesillystringforsalt.abcdefghijklmnopqrstuvwxyzABCD';
    if (!password_verify($password, $hash) || !$u) {
        usleep(400000);
        return false;
    }
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
