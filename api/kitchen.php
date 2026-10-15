<?php
/**
 * Kitchen Display.
 *  GET  ?station=ALL|KITCHEN|BAR  -> tiket aktif (Baru / Disiapkan / Siap) + selesai 30 menit terakhir
 *  POST JSON {order_id, station, batch, to: PREPARING|READY|SERVED}
 * Tiket = item satu pesanan, satu area produksi, satu kali kirim (batch). Status dapur TIDAK mengubah status pembayaran.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/orders.php';

const KDS_RANK = ['NEW' => 1, 'PREPARING' => 2, 'READY' => 3, 'SERVED' => 4];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('kitchen.update', true);
    require_post_csrf();
    $in = json_input();
    $orderId = (int) filter_var(isset($in['order_id']) ? $in['order_id'] : 0, FILTER_VALIDATE_INT);
    $station = isset($in['station']) && in_array($in['station'], ['KITCHEN', 'BAR'], true) ? $in['station'] : null;
    $batch = (int) filter_var(isset($in['batch']) ? $in['batch'] : 0, FILTER_VALIDATE_INT);
    $to = isset($in['to']) && in_array($in['to'], ['PREPARING', 'READY', 'SERVED'], true) ? $in['to'] : null;
    if (!$orderId || !$station || $batch < 1 || !$to) {
        json_response(['ok' => false, 'error' => 'Data tidak valid.'], 422);
    }
    // Hanya maju: Baru -> Disiapkan -> Siap -> Selesai (disajikan / diambil kurir)
    $from = ['PREPARING' => ['NEW'], 'READY' => ['NEW', 'PREPARING'], 'SERVED' => ['READY']][$to];
    $col = ['PREPARING' => 'started_at', 'READY' => 'ready_at', 'SERVED' => 'served_at'][$to];
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $o = $pdo->prepare('SELECT status, order_type FROM orders WHERE id = ? FOR UPDATE');
        $o->execute([$orderId]);
        $order = $o->fetch();
        if (!$order || in_array($order['status'], ['CANCELLED', 'DRAFT'], true)) {
            $pdo->rollBack();
            json_response(['ok' => false, 'error' => 'Pesanan dibatalkan / tidak aktif.'], 409);
        }
        $in_sql = "'" . implode("','", $from) . "'";
        $u = $pdo->prepare("UPDATE order_items SET kitchen_status = ?, $col = NOW(), started_at = COALESCE(started_at, NOW())
                            WHERE order_id = ? AND station = ? AND kitchen_batch = ? AND kitchen_status IN ($in_sql)");
        $u->execute([$to, $orderId, $station, $batch]);
        if ($u->rowCount() === 0) {
            $pdo->rollBack();
            json_response(['ok' => false, 'error' => 'Status tiket sudah berubah. Layar akan diperbarui.'], 409);
        }
        // Sinkron delivery: mulai dimasak -> Preparing; semua item siap -> Ready for Pickup
        if ($order['order_type'] === 'DELIVERY') {
            if ($to === 'PREPARING') {
                delivery_advance($pdo, $orderId, 'CONFIRMED', 'PREPARING', 'Dapur mulai memproses', (int) current_user()['id']);
            }
            $left = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE order_id = ? AND kitchen_status IN ('PENDING','NEW','PREPARING')");
            $left->execute([$orderId]);
            if ((int) $left->fetchColumn() === 0) {
                if (!delivery_advance($pdo, $orderId, 'PREPARING', 'READY_FOR_PICKUP', 'Semua item siap', (int) current_user()['id'])) {
                    delivery_advance($pdo, $orderId, 'CONFIRMED', 'READY_FOR_PICKUP', 'Semua item siap', (int) current_user()['id']);
                }
            }
        }
        $pdo->commit();
        json_response(['ok' => true]);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('kitchen: ' . $ex->getMessage());
        json_response(['ok' => false, 'error' => 'Gagal memperbarui status.'], 500);
    }
}

require_permission('kitchen.view', true);
$station = isset($_GET['station']) && in_array($_GET['station'], ['KITCHEN', 'BAR'], true) ? $_GET['station'] : null;
try {
    $pdo = db();
    $sql = "SELECT i.*, o.order_number, o.order_type, o.note AS order_note, o.source, o.customer_name, t.table_number
            FROM order_items i JOIN orders o ON o.id = i.order_id LEFT JOIN tables t ON t.id = o.table_id
            WHERE i.kitchen_batch > 0 AND o.status NOT IN ('CANCELLED','DRAFT')
              AND (i.kitchen_status IN ('NEW','PREPARING','READY') OR (i.kitchen_status = 'SERVED' AND i.served_at > NOW() - INTERVAL 30 MINUTE))"
         . ($station ? ' AND i.station = ?' : '') . ' ORDER BY i.sent_at, i.id';
    $st = $pdo->prepare($sql);
    $st->execute($station ? [$station] : []);
    $tickets = [];
    $now = time();
    foreach ($st->fetchAll() as $i) {
        $key = $i['order_id'] . '-' . $i['station'] . '-' . $i['kitchen_batch'];
        if (!isset($tickets[$key])) {
            $tickets[$key] = [
                'key' => $key, 'order_id' => (int) $i['order_id'], 'order_number' => $i['order_number'], 'station' => $i['station'],
                'batch' => (int) $i['kitchen_batch'], 'place' => place_label($i), 'source' => $i['source'], 'note' => $i['order_note'],
                'customer' => $i['order_type'] === 'DELIVERY' ? $i['customer_name'] : null,
                'sent_at' => $i['sent_at'], 'items' => [], 'rank' => 9, 'done_at' => null,
            ];
        }
        $t = &$tickets[$key];
        $t['items'][] = ['name' => $i['product_name'], 'qty' => (int) $i['quantity'], 'note' => $i['note'], 'status' => $i['kitchen_status']];
        $t['rank'] = min($t['rank'], KDS_RANK[$i['kitchen_status']]);
        if ($i['served_at'] && (!$t['done_at'] || $i['served_at'] > $t['done_at'])) {
            $t['done_at'] = $i['served_at'];
        }
        unset($t);
    }
    $out = [];
    foreach ($tickets as $t) {
        $t['status'] = array_search($t['rank'], KDS_RANK, true);
        $end = $t['status'] === 'SERVED' && $t['done_at'] ? strtotime($t['done_at']) : $now;
        $t['minutes'] = max(0, (int) floor(($end - strtotime($t['sent_at'])) / 60));
        $t['time'] = date('H:i', strtotime($t['sent_at']));
        unset($t['rank'], $t['done_at']);
        $out[] = $t;
    }
    json_response(['ok' => true, 'tickets' => $out, 'server_time' => date('H:i:s')]);
} catch (Throwable $ex) {
    error_log('kitchen: ' . $ex->getMessage());
    json_response(['ok' => false, 'error' => 'Gagal memuat tiket.'], 500);
}
