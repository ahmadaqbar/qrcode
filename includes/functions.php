<?php
/**
 * Fungsi umum yang dipakai customer & admin.
 * Kompatibel PHP 7.4.
 */

define('APP_NAME', getenv('APP_NAME') ?: 'Warung Nusantara');
define('ROOT_PATH', dirname(__DIR__));

date_default_timezone_set('Asia/Jakarta');
ini_set('display_errors', '0');   // jangan tampilkan error ke customer
ini_set('log_errors', '1');

const ORDER_STATUSES = ['NEW', 'PROCESSING', 'COMPLETED', 'CANCELLED'];
const STATUS_LABELS = [
    'NEW'        => 'Pesanan Baru',
    'PROCESSING' => 'Diproses',
    'COMPLETED'  => 'Selesai',
    'CANCELLED'  => 'Dibatalkan',
];
// Transisi status yang diizinkan: NEW -> PROCESSING -> COMPLETED, atau NEW -> CANCELLED
const STATUS_TRANSITIONS = [
    'NEW'        => ['PROCESSING', 'CANCELLED'],
    'PROCESSING' => ['COMPLETED'],
    'COMPLETED'  => [],
    'CANCELLED'  => [],
];

/** Koneksi PDO (singleton). */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = require ROOT_PATH . '/config/database.php';
        $dsn = 'mysql:host=' . $c['host'] . ';dbname=' . $c['name'] . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+07:00'",
        ]);
    }
    return $pdo;
}

/** Escape HTML. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rupiah($n): string
{
    return 'Rp' . number_format((int) $n, 0, ',', '.');
}

function status_label(string $status): string
{
    return isset(STATUS_LABELS[$status]) ? STATUS_LABELS[$status] : $status;
}

/** Kirim JSON lalu berhenti. */
function json_response(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Tampilkan halaman error sederhana untuk customer (tanpa detail teknis). */
function fail_page(string $message, int $code = 400): void
{
    http_response_code($code);
    $page_title = 'Terjadi Kesalahan';
    $base = '';
    include ROOT_PATH . '/includes/header.php';
    echo '<div class="container py-5 text-center"><h1 class="h4 mb-3">' . e($message) . '</h1>'
       . '<p class="text-muted">Silakan scan ulang QR Code di meja Anda.</p></div>';
    include ROOT_PATH . '/includes/footer.php';
    exit;
}

/** Ambil meja aktif berdasarkan nomor (mis. "01"). */
function find_active_table(string $number): ?array
{
    if (!preg_match('/^[0-9A-Za-z]{1,10}$/', $number)) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM tables WHERE table_number = ? AND is_active = 1');
    $st->execute([$number]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Ambil item untuk banyak order sekaligus: [order_id => [items...]]. */
function fetch_items_for_orders(array $orderIds): array
{
    if (!$orderIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($orderIds), '?'));
    $st = db()->prepare("SELECT order_id, product_name, price, quantity, subtotal, note
                         FROM order_items WHERE order_id IN ($in) ORDER BY id");
    $st->execute(array_values($orderIds));
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[$r['order_id']][] = $r;
    }
    return $out;
}

/** Bentuk order untuk output (API & tampilan). */
function format_order(array $o, array $items): array
{
    return [
        'id'            => (int) $o['id'],
        'order_number'  => $o['order_number'],
        'table_number'  => $o['table_number'],
        'customer_name' => $o['customer_name'],
        'note'          => $o['note'],
        'total'         => (int) $o['total'],
        'total_text'    => rupiah($o['total']),
        'status'        => $o['status'],
        'status_label'  => status_label($o['status']),
        'time'          => date('H:i', strtotime($o['created_at'])),
        'created_at'    => $o['created_at'],
        'item_count'    => array_sum(array_map(function ($i) { return (int) $i['quantity']; }, $items)),
        'items'         => array_map(function ($i) {
            return [
                'name'          => $i['product_name'],
                'quantity'      => (int) $i['quantity'],
                'price_text'    => rupiah($i['price']),
                'subtotal_text' => rupiah($i['subtotal']),
            ];
        }, $items),
    ];
}

/** Base URL aplikasi, mis. http://host/restaurant-ordering (untuk QR). */
function app_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    $dir = preg_replace('#/(admin|api)$#', '', $dir);
    return ($https ? 'https://' : 'http://') . $host . $dir;
}

/* ---------- Session & CSRF (admin) ---------- */

function start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
        session_name('QRORDER_STAFF');
        session_start();
    }
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Cek token dari POST field atau header X-CSRF-Token. */
function csrf_valid(): bool
{
    start_session();
    $sent = isset($_POST['csrf_token']) ? $_POST['csrf_token']
        : (isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '');
    return !empty($_SESSION['csrf']) && is_string($sent) && hash_equals($_SESSION['csrf'], $sent);
}

/* ---------- Tahap 2: util tambahan ---------- */

/** IP client (sengaja hanya REMOTE_ADDR: header proxy bisa dipalsukan). */
function client_ip(): string
{
    return isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : '0.0.0.0';
}

function flash_set(string $type, string $msg): void
{
    start_session();
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

/** Ambil & hapus pesan flash: ['type'=>..,'msg'=>..] atau null. */
function flash_get(): ?array
{
    start_session();
    $f = isset($_SESSION['flash']) ? $_SESSION['flash'] : null;
    unset($_SESSION['flash']);
    return $f;
}

/** Trim + buang karakter kontrol. Return '' bila kosong. */
function clean_input($v, int $max = 255): string
{
    $v = is_string($v) ? trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v)) : '';
    return mb_substr($v, 0, $max, 'UTF-8');
}

/** Redirect + exit. */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}
