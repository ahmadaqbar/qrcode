<?php
/**
 * POST JSON (POS): aksi terhadap pesanan. {action, order_id, version, ...}
 * Setiap aksi memeriksa izinnya sendiri di server dan dicatat di audit log.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/orders.php';
require_login(true);
require_post_csrf();
$in = json_input();

$action = isset($in['action']) ? (string) $in['action'] : '';
$orderId = (int) filter_var(isset($in['order_id']) ? $in['order_id'] : 0, FILTER_VALIDATE_INT);
$reason = clean_input(isset($in['reason']) ? $in['reason'] : '', 255);
$pdo = db();
$uid = (int) current_user()['id'];

function act_fail(string $msg, int $code = 422, array $extra = []): void
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(array_merge(['ok' => false, 'error' => $msg], $extra), $code);
}

/** Kunci pesanan (FOR UPDATE) + cek versi bila dikirim client. */
function lock_order(PDO $pdo, int $id, $version = null): array
{
    $st = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
    $st->execute([$id]);
    $o = $st->fetch();
    if (!$o) {
        act_fail('Pesanan tidak ditemukan.', 404);
    }
    if ($version !== null && (int) $o['version'] !== (int) $version) {
        act_fail('Pesanan ini baru saja diubah di perangkat lain. Muat ulang pesanan.', 409, ['code' => 'CONFLICT']);
    }
    return $o;
}

$handlers = [];

/* ---------- Batalkan seluruh transaksi ---------- */
$handlers['cancel'] = function () use ($pdo, $in, $orderId, $reason, $uid) {
    require_permission('pos.use', true);
    $o = lock_order($pdo, $orderId, isset($in['version']) ? $in['version'] : null);
    if (!in_array($o['status'], ['DRAFT', 'NEW', 'PROCESSING'], true)) {
        act_fail('Pesanan ini sudah ' . strtolower(status_label($o['status'])) . '.', 409);
    }
    if ($o['payment_status'] !== 'UNPAID') {
        act_fail('Pesanan sudah ada pembayaran. Lakukan refund terlebih dahulu (butuh otorisasi).', 409);
    }
    $sent = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE order_id = ? AND kitchen_status NOT IN ('PENDING','CANCELLED')");
    $sent->execute([$orderId]);
    $wasSent = (int) $sent->fetchColumn() > 0;
    if ($wasSent && !can('order.void')) {
        act_fail('Pesanan sudah masuk dapur. Pembatalan butuh izin void (Manager).', 403);
    }
    if ($wasSent && $reason === '') {
        act_fail('Alasan pembatalan wajib diisi.', 422, ['code' => 'REASON']);
    }
    $pdo->prepare("UPDATE order_items SET kitchen_status = 'CANCELLED' WHERE order_id = ? AND kitchen_status <> 'SERVED'")->execute([$orderId]);
    $pdo->prepare("UPDATE orders SET status = 'CANCELLED', cancel_reason = ?, cancelled_by = ?, cancelled_at = NOW(), table_released_at = NOW(),
                   version = version + 1 WHERE id = ?")->execute([$reason ?: 'Dibatalkan kasir', $uid, $orderId]);
    $d = $pdo->prepare('SELECT delivery_status FROM delivery_details WHERE order_id = ?');
    $d->execute([$orderId]);
    if (($ds = $d->fetchColumn()) && $ds !== 'CANCELLED') {
        $pdo->prepare("UPDATE delivery_details SET delivery_status = 'CANCELLED' WHERE order_id = ?")->execute([$orderId]);
        $pdo->prepare("INSERT INTO delivery_status_history (order_id, from_status, to_status, user_id, note) VALUES (?, ?, 'CANCELLED', ?, ?)")
            ->execute([$orderId, $ds, $uid, $reason ?: 'Pesanan dibatalkan']);
    }
    audit('order.void', 'order', $orderId, ['reason' => $reason, 'after_kitchen' => $wasSent, 'total' => (int) $o['total']]);
    return ['order' => order_full($pdo, $orderId)];
};

/* ---------- Tandai meja tersedia (setelah transaksi selesai) ---------- */
$handlers['release_table'] = function () use ($pdo, $in) {
    require_permission('pos.use', true);
    $tableId = (int) filter_var(isset($in['table_id']) ? $in['table_id'] : 0, FILTER_VALIDATE_INT);
    $active = active_orders_on_table($pdo, $tableId);
    if ($active) {
        act_fail('Meja masih memiliki pesanan yang belum selesai/lunas (' . $active[0]['order_number'] . ').', 409);
    }
    $st = $pdo->prepare("UPDATE orders SET table_released_at = NOW() WHERE table_id = ? AND table_released_at IS NULL AND status IN ('COMPLETED','CANCELLED')");
    $st->execute([$tableId]);
    audit('table.release', 'table', $tableId, ['orders' => $st->rowCount()]);
    return ['released' => $st->rowCount()];
};

/* @@PHASE2_ACTIONS@@ */

if (!isset($handlers[$action])) {
    json_response(['ok' => false, 'error' => 'Aksi tidak dikenal.'], 400);
}
try {
    $pdo->beginTransaction();
    $result = $handlers[$action]();
    $pdo->commit();
    json_response(array_merge(['ok' => true], $result));
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('pos-action ' . $action . ': ' . $ex->getMessage());
    json_response(['ok' => false, 'error' => 'Aksi gagal diproses.'], 500);
}
