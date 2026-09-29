<?php
/** GET ?t=<order_token> -> status order (untuk halaman sukses customer). Token acak 32 hex, tidak bisa ditebak. */
require_once __DIR__ . '/../includes/functions.php';

$token = isset($_GET['t']) ? (string) $_GET['t'] : '';
if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
    json_response(['ok' => false], 400);
}
try {
    $st = db()->prepare('SELECT status FROM orders WHERE order_token = ?');
    $st->execute([$token]);
    $s = $st->fetchColumn();
    if ($s === false) {
        json_response(['ok' => false], 404);
    }
    json_response(['ok' => true, 'status' => $s, 'status_label' => status_label($s)]);
} catch (Throwable $ex) {
    error_log('order-status: ' . $ex->getMessage());
    json_response(['ok' => false], 500);
}
