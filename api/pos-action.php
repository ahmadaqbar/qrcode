<?php
/**
 * POST JSON (POS): aksi terhadap pesanan. {action, order_id, version, ...}
 * Setiap aksi memeriksa izinnya sendiri di server dan dicatat di audit log.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/shift.php';
foreach (['inventory', 'integration'] as $mod) {
    if (is_file(__DIR__ . '/../includes/' . $mod . '.php')) {
        require_once __DIR__ . '/../includes/' . $mod . '.php';
    }
}
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
    if ($wasSent && $reason === '') {
        act_fail('Alasan pembatalan wajib diisi.', 422, ['code' => 'REASON']);
    }
    $approver = $wasSent ? authorize('order.void', $in) : current_user();
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
    audit('order.void', 'order', $orderId, ['reason' => $reason, 'after_kitchen' => $wasSent, 'total' => (int) $o['total'], 'approved_by' => $approver['name']]);
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

/* ---------- Cetak tagihan (meja -> Waiting Payment) ---------- */
$handlers['print_bill'] = function () use ($pdo, $orderId) {
    require_permission('pos.use', true);
    $o = lock_order($pdo, $orderId);
    if (!in_array($o['status'], ['NEW', 'PROCESSING'], true)) {
        act_fail('Tagihan hanya untuk pesanan yang sedang berjalan.', 409);
    }
    $pdo->prepare('UPDATE orders SET bill_printed_at = NOW() WHERE id = ?')->execute([$orderId]);
    return ['order' => order_full($pdo, $orderId)];
};

/* ---------- Refund penuh (otorisasi + alasan; pembayaran asli tidak diubah) ---------- */
$handlers['refund'] = function () use ($pdo, $in, $orderId, $reason, $uid) {
    require_permission(['pos.use', 'payment.take', 'payment.refund'], true);
    $o = lock_order($pdo, $orderId);
    if (!in_array($o['payment_status'], ['PAID', 'PARTIAL'], true)) {
        act_fail('Tidak ada pembayaran yang dapat direfund.', 409);
    }
    if ($reason === '') {
        act_fail('Alasan refund wajib diisi.', 422, ['code' => 'REASON']);
    }
    $approver = authorize('payment.refund', $in);
    $shift = open_shift_for($pdo, $uid);
    if (!$shift) {
        act_fail('Buka shift kasir terlebih dahulu (refund tunai mengurangi kas laci).', 409, ['code' => 'NO_SHIFT']);
    }
    $ps = $pdo->prepare("SELECT p.*, (SELECT COALESCE(SUM(-r.amount), 0) FROM payments r WHERE r.refund_of = p.id) AS refunded
                         FROM payments p WHERE p.order_id = ? AND p.kind = 'PAYMENT'");
    $ps->execute([$orderId]);
    $ins = $pdo->prepare("INSERT INTO payments (order_id, shift_id, method_code, kind, amount, tendered, change_amount, reference, idem_key, refund_of, note, created_by)
                          VALUES (?, ?, ?, 'REFUND', ?, 0, 0, ?, ?, ?, ?, ?)");
    $total = 0;
    foreach ($ps->fetchAll() as $p) {
        $left = (int) $p['amount'] - (int) $p['refunded'];
        if ($left <= 0) {
            continue;
        }
        $ins->execute([$orderId, $shift['id'], $p['method_code'], -$left, $p['reference'], random_token(), $p['id'], $reason, $uid]);
        $total += $left;
    }
    // Lunas -> tetap COMPLETED (penjualan tercatat lalu direfund). Bayar sebagian -> pesanan dibatalkan.
    $newStatus = $o['status'] === 'COMPLETED' ? 'COMPLETED' : 'CANCELLED';
    $pdo->prepare("UPDATE orders SET payment_status = 'REFUNDED', paid_amount = 0, status = ?, version = version + 1,
                   cancel_reason = IF(? = 'CANCELLED', ?, cancel_reason), cancelled_at = IF(? = 'CANCELLED', NOW(), cancelled_at),
                   cancelled_by = IF(? = 'CANCELLED', ?, cancelled_by), table_released_at = COALESCE(table_released_at, IF(? = 'CANCELLED', NOW(), NULL)) WHERE id = ?")
        ->execute([$newStatus, $newStatus, 'Refund: ' . $reason, $newStatus, $newStatus, $uid, $newStatus, $orderId]);
    if ($newStatus === 'CANCELLED') {
        $pdo->prepare("UPDATE order_items SET kitchen_status = 'CANCELLED' WHERE order_id = ? AND kitchen_status IN ('PENDING','NEW')")->execute([$orderId]);
    }
    on_order_refunded($pdo, $orderId);
    audit('payment.refund', 'order', $orderId, ['reason' => $reason, 'amount' => $total, 'approved_by' => $approver['name'], 'shift_id' => (int) $shift['id']]);
    return ['refunded' => $total, 'order' => order_full($pdo, $orderId)];
};

/* ---------- Pindah meja ---------- */
$handlers['move'] = function () use ($pdo, $in, $orderId) {
    require_permission('pos.use', true);
    $o = lock_order($pdo, $orderId, isset($in['version']) ? $in['version'] : null);
    $target = (int) filter_var(isset($in['table_id']) ? $in['table_id'] : 0, FILTER_VALIDATE_INT);
    if ($o['order_type'] !== 'DINE_IN' || !in_array($o['status'], ['DRAFT', 'NEW', 'PROCESSING'], true)) {
        act_fail('Hanya pesanan dine-in yang masih berjalan yang dapat dipindah.', 409);
    }
    $t = $pdo->prepare('SELECT table_number FROM tables WHERE id = ? AND is_active = 1');
    $t->execute([$target]);
    $tn = $t->fetchColumn();
    if (!$tn || $target === (int) $o['table_id']) {
        act_fail('Meja tujuan tidak valid.');
    }
    if ($busy = active_orders_on_table($pdo, $target, $orderId)) {
        act_fail('Meja ' . $tn . ' sudah memiliki pesanan (' . $busy[0]['order_number'] . '). Gunakan "Gabung tagihan".', 409);
    }
    $approver = authorize('table.move', $in);
    $pdo->prepare('UPDATE orders SET table_id = ?, version = version + 1 WHERE id = ?')->execute([$target, $orderId]);
    audit('order.move', 'order', $orderId, ['from_table' => (int) $o['table_id'], 'to_table' => $target, 'approved_by' => $approver['name']]);
    return ['order' => order_full($pdo, $orderId)];
};

/* ---------- Gabung tagihan: semua item pesanan ini dipindah ke pesanan tujuan ---------- */
$handlers['merge'] = function () use ($pdo, $in, $orderId, $uid) {
    require_permission('pos.use', true);
    $targetId = (int) filter_var(isset($in['target_order_id']) ? $in['target_order_id'] : 0, FILTER_VALIDATE_INT);
    if (!$targetId || $targetId === $orderId) {
        act_fail('Pilih pesanan tujuan yang berbeda.');
    }
    // Kunci berurutan menurut id untuk menghindari deadlock
    $ids = [$orderId, $targetId];
    sort($ids);
    $locked = [];
    foreach ($ids as $i) {
        $locked[$i] = lock_order($pdo, $i);
    }
    $src = $locked[$orderId];
    $dst = $locked[$targetId];
    foreach ([$src, $dst] as $o) {
        if (!in_array($o['status'], ['DRAFT', 'NEW', 'PROCESSING'], true) || $o['payment_status'] !== 'UNPAID' || $o['order_type'] === 'DELIVERY') {
            act_fail('Gabung hanya untuk pesanan dine-in/takeaway yang belum dibayar (' . $o['order_number'] . ').', 409);
        }
    }
    $approver = authorize('table.move', $in);
    $mv = $pdo->prepare("UPDATE order_items SET order_id = ? WHERE order_id = ? AND kitchen_status <> 'CANCELLED'");
    $mv->execute([$targetId, $orderId]);
    $sent = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE order_id = ? AND kitchen_status NOT IN ('PENDING','CANCELLED')");
    $sent->execute([$targetId]);
    if ($dst['status'] === 'DRAFT' && (int) $sent->fetchColumn() > 0) {
        $pdo->prepare("UPDATE orders SET status = 'PROCESSING' WHERE id = ?")->execute([$targetId]);
    }
    $pdo->prepare("UPDATE orders SET status = 'CANCELLED', merged_into_id = ?, cancel_reason = ?, cancelled_by = ?, cancelled_at = NOW(),
                   table_released_at = NOW(), subtotal = 0, discount_amount = 0, service_amount = 0, tax_amount = 0, total = 0, version = version + 1 WHERE id = ?")
        ->execute([$targetId, 'Digabung ke ' . $dst['order_number'], $uid, $orderId]);
    recalc_order($pdo, $targetId);
    $pdo->prepare('UPDATE orders SET version = version + 1 WHERE id = ?')->execute([$targetId]);
    audit('order.merge', 'order', $targetId, ['merged_from' => $src['order_number'], 'items' => $mv->rowCount(), 'approved_by' => $approver['name']]);
    return ['order' => order_full($pdo, $targetId)];
};

/* ---------- Pisah tagihan: item (dan qty) tertentu dipindah ke pesanan baru ---------- */
$handlers['split'] = function () use ($pdo, $in, $orderId, $uid) {
    require_permission('pos.use', true);
    $o = lock_order($pdo, $orderId, isset($in['version']) ? $in['version'] : null);
    if (!in_array($o['status'], ['DRAFT', 'NEW', 'PROCESSING'], true) || $o['payment_status'] !== 'UNPAID' || $o['order_type'] === 'DELIVERY') {
        act_fail('Pisah tagihan hanya untuk pesanan dine-in/takeaway yang belum dibayar.', 409);
    }
    $want = isset($in['items']) && is_array($in['items']) ? $in['items'] : [];
    $it = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ? AND kitchen_status <> 'CANCELLED' FOR UPDATE");
    $it->execute([$orderId]);
    $items = [];
    $totalQty = 0;
    foreach ($it->fetchAll() as $i) {
        $items[(int) $i['id']] = $i;
        $totalQty += (int) $i['quantity'];
    }
    $moves = [];
    $moveQty = 0;
    foreach ($want as $w) {
        $iid = (int) (isset($w['item_id']) ? $w['item_id'] : 0);
        $q = (int) (isset($w['qty']) ? $w['qty'] : 0);
        if ($q <= 0) {
            continue;
        }
        if (!isset($items[$iid]) || $q > (int) $items[$iid]['quantity']) {
            act_fail('Item atau jumlah yang dipisah tidak valid.');
        }
        $moves[$iid] = $q;
        $moveQty += $q;
    }
    if (!$moves || $moveQty >= $totalQty) {
        act_fail('Pilih sebagian item untuk dipisah (minimal satu item tetap di tagihan asal).');
    }
    $approver = authorize('table.move', $in);
    $newId = insert_order($pdo, [
        'order_token' => random_token(), 'table_id' => $o['table_id'], 'order_type' => $o['order_type'], 'source' => 'POS',
        'customer_name' => $o['customer_name'], 'status' => $o['status'] === 'NEW' ? 'PROCESSING' : $o['status'],
        'service_rate' => $o['service_rate'], 'tax_rate' => $o['tax_rate'], 'tax_include_service' => $o['tax_include_service'],
        'created_by' => $uid, 'subtotal' => 0, 'total' => 0,
    ]);
    $cols = 'product_id, product_name, price, quantity, subtotal, note, discount_type, discount_value, discount_amount, station, kitchen_status, kitchen_batch, sent_at, started_at, ready_at, served_at';
    foreach ($moves as $iid => $q) {
        $i = $items[$iid];
        if ($q === (int) $i['quantity']) {
            $pdo->prepare('UPDATE order_items SET order_id = ? WHERE id = ?')->execute([$newId, $iid]);
            continue;
        }
        // Pecah baris: diskon persen ikut proporsional; diskon nominal tetap di baris asal
        $keep = (int) $i['quantity'] - $q;
        $dtNew = $i['discount_type'] === 'PERCENT' ? 'PERCENT' : 'NONE';
        $dvNew = $dtNew === 'PERCENT' ? (int) $i['discount_value'] : 0;
        $daNew = line_discount((int) $i['price'], $q, $dtNew, $dvNew);
        $daKeep = line_discount((int) $i['price'], $keep, $i['discount_type'], (int) $i['discount_value']);
        $pdo->prepare('UPDATE order_items SET quantity = ?, discount_amount = ?, subtotal = ? WHERE id = ?')
            ->execute([$keep, $daKeep, (int) $i['price'] * $keep - $daKeep, $iid]);
        $pdo->prepare("INSERT INTO order_items (order_id, $cols) SELECT ?, product_id, product_name, price, ?, ?, note, ?, ?, ?, station, kitchen_status, kitchen_batch, sent_at, started_at, ready_at, served_at FROM order_items WHERE id = ?")
            ->execute([$newId, $q, (int) $i['price'] * $q - $daNew, $dtNew, $dvNew, $daNew, $iid]);
    }
    recalc_order($pdo, $orderId);
    recalc_order($pdo, $newId);
    $pdo->prepare('UPDATE orders SET version = version + 1 WHERE id IN (?, ?)')->execute([$orderId, $newId]);
    $n = $pdo->prepare('SELECT order_number FROM orders WHERE id = ?');
    $n->execute([$newId]);
    audit('order.split', 'order', $orderId, ['new_order' => $n->fetchColumn(), 'items' => $moves, 'approved_by' => $approver['name']]);
    return ['order' => order_full($pdo, $orderId), 'new_order' => order_full($pdo, $newId)];
};

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
