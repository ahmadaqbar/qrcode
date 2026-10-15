<?php
/**
 * POST JSON (POS): simpan transaksi baru / ubah pesanan yang ada (termasuk pesanan QR).
 * {
 *   order_id: null|int, version: int, token: "32hex" (wajib utk transaksi baru, kunci idempotensi),
 *   action: "hold" | "save" | "send",            hold = tahan (DRAFT), send = kirim item baru ke dapur
 *   order_type: DINE_IN|TAKEAWAY|DELIVERY, table_id, separate: bool (izinkan transaksi terpisah di meja yang sudah terisi),
 *   customer_name, note, discount: {type, value}, other_fee, other_fee_label, delivery_fee,
 *   delivery: {customer_name, phone, address, landmark, delivery_note, source},
 *   lines: [{id|null, product_id, qty, note, discount_type, discount_value}], void_reason
 * }
 * Harga SELALU dari database. Item yang sudah dikirim ke dapur hanya bisa dikurangi dengan izin order.void + alasan.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/orders.php';
require_permission('pos.use', true);
require_post_csrf();
$in = json_input();

function fail(string $msg, int $code = 422, array $extra = []): void
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(array_merge(['ok' => false, 'error' => $msg], $extra), $code);
}

$orderId = isset($in['order_id']) ? filter_var($in['order_id'], FILTER_VALIDATE_INT) : null;
$version = isset($in['version']) ? (int) $in['version'] : 0;
$action = isset($in['action']) && in_array($in['action'], ['hold', 'save', 'send'], true) ? $in['action'] : null;
$type = isset($in['order_type']) && isset(ORDER_TYPES[$in['order_type']]) ? $in['order_type'] : null;
$tableId = isset($in['table_id']) && $in['table_id'] !== null ? filter_var($in['table_id'], FILTER_VALIDATE_INT) : null;
$separate = !empty($in['separate']);
$token = isset($in['token']) && is_string($in['token']) ? $in['token'] : '';
$customer = clean_input(isset($in['customer_name']) ? $in['customer_name'] : '', 100);
$note = clean_input(isset($in['note']) ? $in['note'] : '', 500);
$voidReason = clean_input(isset($in['void_reason']) ? $in['void_reason'] : '', 255);
$disc = isset($in['discount']) && is_array($in['discount']) ? $in['discount'] : [];
$discType = isset($disc['type']) && in_array($disc['type'], ['NONE', 'PERCENT', 'AMOUNT'], true) ? $disc['type'] : 'NONE';
$discValue = $discType === 'NONE' ? 0 : (int) filter_var(isset($disc['value']) ? $disc['value'] : 0, FILTER_VALIDATE_INT);
$otherFee = (int) filter_var(isset($in['other_fee']) ? $in['other_fee'] : 0, FILTER_VALIDATE_INT);
$otherLabel = clean_input(isset($in['other_fee_label']) ? $in['other_fee_label'] : '', 50);
$deliveryFee = (int) filter_var(isset($in['delivery_fee']) ? $in['delivery_fee'] : 0, FILTER_VALIDATE_INT);
$lines = isset($in['lines']) && is_array($in['lines']) ? $in['lines'] : [];

if ($orderId === false || $action === null || $type === null || $tableId === false) {
    fail('Data transaksi tidak valid.');
}
if ($discValue < 0 || ($discType === 'PERCENT' && $discValue > 100) || $discValue > 100000000) {
    fail('Nilai diskon tidak valid.');
}
if ($otherFee < 0 || $otherFee > 10000000 || $deliveryFee < 0 || $deliveryFee > 10000000) {
    fail('Biaya tambahan tidak valid.');
}
if (count($lines) > 100) {
    fail('Terlalu banyak baris pesanan.');
}
if (!$orderId && !preg_match('/^[a-f0-9]{32}$/', $token)) {
    fail('Token transaksi tidak valid. Muat ulang halaman.');
}

// Normalisasi baris
$clean = [];
foreach ($lines as $l) {
    $lid = isset($l['id']) && $l['id'] !== null ? filter_var($l['id'], FILTER_VALIDATE_INT) : null;
    $pid = filter_var(isset($l['product_id']) ? $l['product_id'] : null, FILTER_VALIDATE_INT);
    $qty = filter_var(isset($l['qty']) ? $l['qty'] : null, FILTER_VALIDATE_INT);
    $dt = isset($l['discount_type']) && in_array($l['discount_type'], ['NONE', 'PERCENT', 'AMOUNT'], true) ? $l['discount_type'] : 'NONE';
    $dv = $dt === 'NONE' ? 0 : (int) filter_var(isset($l['discount_value']) ? $l['discount_value'] : 0, FILTER_VALIDATE_INT);
    if ($lid === false || !$pid || $qty === false || $qty < 0 || $qty > 99 || $dv < 0 || ($dt === 'PERCENT' && $dv > 100)) {
        fail('Item pesanan tidak valid.');
    }
    $clean[] = ['id' => $lid, 'product_id' => $pid, 'qty' => $qty, 'note' => clean_input(isset($l['note']) ? $l['note'] : '', 255),
                'discount_type' => $dt, 'discount_value' => $dv];
}

// Delivery
$delivery = null;
if ($type === 'DELIVERY') {
    $d = isset($in['delivery']) && is_array($in['delivery']) ? $in['delivery'] : [];
    $delivery = [
        'customer_name' => clean_input(isset($d['customer_name']) ? $d['customer_name'] : '', 100),
        'phone' => clean_input(isset($d['phone']) ? $d['phone'] : '', 20),
        'address' => clean_input(isset($d['address']) ? $d['address'] : '', 255),
        'landmark' => clean_input(isset($d['landmark']) ? $d['landmark'] : '', 150),
        'delivery_note' => clean_input(isset($d['delivery_note']) ? $d['delivery_note'] : '', 255),
        'source' => clean_input(isset($d['source']) ? $d['source'] : 'Telepon', 30) ?: 'Telepon',
    ];
    if ($delivery['customer_name'] === '' || $delivery['address'] === '' || !preg_match('/^\+?[0-9][0-9 ()-]{5,19}$/', $delivery['phone'])) {
        fail('Delivery: nama pelanggan, nomor telepon yang valid, dan alamat wajib diisi.');
    }
    if ($customer === '') {
        $customer = $delivery['customer_name'];
    }
} else {
    $deliveryFee = 0;
}

$pdo = db();
$uid = (int) current_user()['id'];
$order = null;
try {
    $pdo->beginTransaction();

    $order = null;
    if ($orderId) {
        $st = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
        $st->execute([$orderId]);
        $order = $st->fetch();
        if (!$order) {
            fail('Pesanan tidak ditemukan.', 404);
        }
        if (!in_array($order['status'], ['DRAFT', 'NEW', 'PROCESSING'], true)) {
            fail('Pesanan sudah ' . strtolower(status_label($order['status'])) . ' dan tidak dapat diubah.', 409);
        }
        if ($order['payment_status'] !== 'UNPAID') {
            fail('Pesanan sudah memiliki pembayaran. Perubahan harus melalui pembatalan/refund yang tercatat.', 409);
        }
        if ($order['order_type'] !== $type && $order['status'] === 'PROCESSING') {
            fail('Tipe pesanan tidak dapat diubah setelah pesanan dikirim ke dapur.');
        }
        if ((int) $order['version'] !== $version) {
            fail('Pesanan ini baru saja diubah di perangkat lain. Muat ulang pesanan sebelum menyimpan.', 409, ['code' => 'CONFLICT']);
        }
    } else {
        // Idempoten: klik ganda / retry dengan token yang sama -> pesanan yang sama
        $st = $pdo->prepare('SELECT id FROM orders WHERE order_token = ?');
        $st->execute([$token]);
        if ($existingId = $st->fetchColumn()) {
            $pdo->commit();
            json_response(['ok' => true, 'duplicate' => true, 'order' => order_full($pdo, (int) $existingId)]);
        }
    }

    // Meja
    if ($type === 'DINE_IN') {
        if (!$tableId) {
            fail('Pilih meja untuk pesanan dine-in.');
        }
        $tb = $pdo->prepare('SELECT id FROM tables WHERE id = ? AND is_active = 1');
        $tb->execute([$tableId]);
        if (!$tb->fetchColumn()) {
            fail('Meja tidak valid.');
        }
        $tableChanged = !$order || (int) $order['table_id'] !== $tableId || $order['order_type'] !== 'DINE_IN';
        if ($order && $tableChanged && $order['status'] !== 'DRAFT' && $order['order_type'] === 'DINE_IN') {
            fail('Gunakan menu "Pindah Meja" untuk memindahkan pesanan yang sudah berjalan.');
        }
        if ($tableChanged && !$separate) {
            $busy = active_orders_on_table($pdo, $tableId, $order ? (int) $order['id'] : 0);
            if ($busy) {
                fail('Meja ini sudah memiliki pesanan aktif.', 409, ['code' => 'TABLE_BUSY', 'orders' => $busy]);
            }
        }
    } else {
        $tableId = null;
    }

    // Diskon butuh izin
    $existingItems = [];
    if ($order) {
        $it = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? FOR UPDATE');
        $it->execute([$order['id']]);
        foreach ($it->fetchAll() as $i) {
            $existingItems[(int) $i['id']] = $i;
        }
    }
    $discountChanged = $order ? ($order['discount_type'] !== $discType || (int) $order['discount_value'] !== $discValue) : $discType !== 'NONE';
    foreach ($clean as $l) {
        $old = $l['id'] && isset($existingItems[$l['id']]) ? $existingItems[$l['id']] : null;
        if ($old ? ($old['discount_type'] !== $l['discount_type'] || (int) $old['discount_value'] !== $l['discount_value']) : $l['discount_type'] !== 'NONE') {
            $discountChanged = true;
        }
    }
    if ($discountChanged && !can('pos.discount')) {
        fail('Anda tidak memiliki izin memberi diskon.', 403);
    }

    // Produk (harga, status, station) dari DB
    $pids = array_unique(array_column($clean, 'product_id'));
    $products = [];
    if ($pids) {
        $ps = $pdo->prepare('SELECT p.id, p.name, p.price, p.is_available, p.is_active, c.is_active AS cat_active, c.station
                             FROM products p JOIN categories c ON c.id = p.category_id WHERE p.id IN (' . implode(',', array_fill(0, count($pids), '?')) . ')');
        $ps->execute(array_values($pids));
        foreach ($ps->fetchAll() as $p) {
            $products[(int) $p['id']] = $p;
        }
    }
    $orderable = function ($pid) use ($products) {
        if (!isset($products[$pid])) {
            fail('Produk tidak ditemukan.');
        }
        $p = $products[$pid];
        if (!$p['is_available'] || !$p['is_active'] || !$p['cat_active']) {
            fail($p['name'] . ' sedang tidak tersedia.');
        }
        return $p;
    };

    // Simpan header pesanan
    $statusFor = function ($cur) use ($action) {
        if ($action === 'send') {
            return 'PROCESSING';
        }
        return $cur === null ? 'DRAFT' : $cur;   // hold/save tidak memajukan status
    };
    if (!$order) {
        $rates = pricing_snapshot($type);
        $orderId = insert_order($pdo, [
            'order_token' => $token, 'table_id' => $tableId, 'order_type' => $type, 'source' => 'POS', 'customer_name' => $customer ?: null,
            'note' => $note ?: null, 'status' => $statusFor(null), 'discount_type' => $discType, 'discount_value' => $discValue,
            'service_rate' => $rates['service_rate'], 'tax_rate' => $rates['tax_rate'], 'tax_include_service' => $rates['tax_include_service'],
            'delivery_fee' => $deliveryFee, 'other_fee' => $otherFee, 'other_fee_label' => $otherLabel ?: null, 'created_by' => $uid,
            'subtotal' => 0, 'total' => 0,
        ]);
        audit('order.create', 'order', $orderId, ['source' => 'POS', 'type' => $type]);
    } else {
        $rates = $order['order_type'] !== $type ? pricing_snapshot($type)
            : ['service_rate' => $order['service_rate'], 'tax_rate' => $order['tax_rate'], 'tax_include_service' => $order['tax_include_service']];
        $pdo->prepare('UPDATE orders SET order_type = ?, table_id = ?, customer_name = ?, note = ?, status = ?, discount_type = ?, discount_value = ?,
                       service_rate = ?, tax_rate = ?, tax_include_service = ?, delivery_fee = ?, other_fee = ?, other_fee_label = ? WHERE id = ?')
            ->execute([$type, $tableId, $customer ?: null, $note ?: null, $statusFor($order['status']), $discType, $discValue,
                       $rates['service_rate'], $rates['tax_rate'], $rates['tax_include_service'], $deliveryFee, $otherFee, $otherLabel ?: null, $orderId]);
        if ($discountChanged) {
            audit('order.discount', 'order', $orderId, ['type' => $discType, 'value' => $discValue]);
        }
    }

    // Baris pesanan
    $ins = $pdo->prepare('INSERT INTO order_items (order_id, product_id, product_name, price, quantity, subtotal, note, discount_type, discount_value, discount_amount, station)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $addLine = function ($pid, $qty, $lnote, $dt, $dv) use ($ins, $orderId, $orderable) {
        $p = $orderable($pid);
        $da = line_discount((int) $p['price'], $qty, $dt, $dv);
        $ins->execute([$orderId, $pid, $p['name'], (int) $p['price'], $qty, (int) $p['price'] * $qty - $da, $lnote ?: null, $dt, $dv, $da, $p['station']]);
    };
    $seen = [];
    $voided = [];
    foreach ($clean as $l) {
        if (!$l['id']) {
            if ($l['qty'] > 0) {
                $addLine($l['product_id'], $l['qty'], $l['note'], $l['discount_type'], $l['discount_value']);
            }
            continue;
        }
        if (!isset($existingItems[$l['id']]) || (int) $existingItems[$l['id']]['product_id'] !== $l['product_id']) {
            fail('Item pesanan tidak cocok dengan pesanan ini. Muat ulang pesanan.', 409, ['code' => 'CONFLICT']);
        }
        $seen[$l['id']] = true;
        $old = $existingItems[$l['id']];
        if ($old['kitchen_status'] === 'CANCELLED') {
            continue;
        }
        $oldQty = (int) $old['quantity'];
        $price = (int) $old['price'];   // harga baris yang sudah ada = snapshot saat dipesan
        if ($old['kitchen_status'] === 'PENDING') {
            if ($l['qty'] === 0) {
                $pdo->prepare('DELETE FROM order_items WHERE id = ?')->execute([$l['id']]);
                continue;
            }
            $da = line_discount($price, $l['qty'], $l['discount_type'], $l['discount_value']);
            $pdo->prepare('UPDATE order_items SET quantity = ?, note = ?, discount_type = ?, discount_value = ?, discount_amount = ?, subtotal = ? WHERE id = ?')
                ->execute([$l['qty'], $l['note'] ?: null, $l['discount_type'], $l['discount_value'], $da, $price * $l['qty'] - $da, $l['id']]);
            continue;
        }
        // Sudah dikirim ke dapur
        $qty = $l['qty'];
        if ($qty > $oldQty) {
            // Tambahan jadi tiket baru; baris lama tidak diubah
            $addLine($l['product_id'], $qty - $oldQty, $l['note'], 'NONE', 0);
            $qty = $oldQty;
        } elseif ($qty < $oldQty) {
            $voided[] = ['item_id' => (int) $l['id'], 'product' => $old['product_name'], 'from' => $oldQty, 'to' => $qty];
        }
        $da = line_discount($price, $qty ?: $oldQty, $l['discount_type'], $l['discount_value']);
        if ($qty === 0) {
            $pdo->prepare("UPDATE order_items SET kitchen_status = 'CANCELLED' WHERE id = ?")->execute([$l['id']]);
        } else {
            $pdo->prepare('UPDATE order_items SET quantity = ?, discount_type = ?, discount_value = ?, discount_amount = ?, subtotal = ? WHERE id = ?')
                ->execute([$qty, $l['discount_type'], $l['discount_value'], $da, $price * $qty - $da, $l['id']]);
        }
    }
    // Baris lama yang tidak dikirim client = dihapus
    foreach ($existingItems as $iid => $old) {
        if (isset($seen[$iid]) || $old['kitchen_status'] === 'CANCELLED') {
            continue;
        }
        if ($old['kitchen_status'] === 'PENDING') {
            $pdo->prepare('DELETE FROM order_items WHERE id = ?')->execute([$iid]);
        } else {
            $voided[] = ['item_id' => $iid, 'product' => $old['product_name'], 'from' => (int) $old['quantity'], 'to' => 0];
            $pdo->prepare("UPDATE order_items SET kitchen_status = 'CANCELLED' WHERE id = ?")->execute([$iid]);
        }
    }
    if ($voided) {
        if ($voidReason === '') {
            fail('Alasan pembatalan item wajib diisi.', 422, ['code' => 'VOID_REASON']);
        }
        $approver = authorize('order.void', $in);   // izin sendiri, atau otorisasi supervisor
        audit('order.item_void', 'order', $orderId, ['reason' => $voidReason, 'items' => $voided, 'approved_by' => $approver['name']]);
    }

    // Delivery
    if ($delivery) {
        $pdo->prepare('INSERT INTO delivery_details (order_id, customer_name, phone, address, landmark, delivery_note, source)
                       VALUES (?, ?, ?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE customer_name = VALUES(customer_name), phone = VALUES(phone), address = VALUES(address),
                       landmark = VALUES(landmark), delivery_note = VALUES(delivery_note), source = VALUES(source)')
            ->execute([$orderId, $delivery['customer_name'], $delivery['phone'], $delivery['address'], $delivery['landmark'] ?: null,
                       $delivery['delivery_note'] ?: null, $delivery['source']]);
        $h = $pdo->prepare('SELECT COUNT(*) FROM delivery_status_history WHERE order_id = ?');
        $h->execute([$orderId]);
        if (!$h->fetchColumn()) {
            $pdo->prepare("INSERT INTO delivery_status_history (order_id, from_status, to_status, user_id, note) VALUES (?, NULL, 'NEW', ?, 'Pesanan delivery dibuat')")
                ->execute([$orderId, $uid]);
        }
    } else {
        $pdo->prepare("DELETE FROM delivery_details WHERE order_id = ? AND delivery_status = 'NEW'")->execute([$orderId]);
    }

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE order_id = ? AND kitchen_status <> 'CANCELLED'");
    $cnt->execute([$orderId]);
    if (!(int) $cnt->fetchColumn()) {
        fail('Pesanan masih kosong. Tambahkan minimal satu menu.');
    }
    if ($action === 'send') {
        send_to_kitchen($pdo, $orderId);
    }
    recalc_order($pdo, $orderId);
    $pdo->prepare('UPDATE orders SET version = version + 1 WHERE id = ?')->execute([$orderId]);
    $pdo->commit();
    json_response(['ok' => true, 'order' => order_full($pdo, $orderId)]);
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Race token kembar
    if (!$orderId || $order === null) {
        $st = $pdo->prepare('SELECT id FROM orders WHERE order_token = ?');
        $st->execute([$token]);
        if ($existingId = $st->fetchColumn()) {
            json_response(['ok' => true, 'duplicate' => true, 'order' => order_full($pdo, (int) $existingId)]);
        }
    }
    error_log('pos-save: ' . $ex->getMessage());
    json_response(['ok' => false, 'error' => 'Gagal menyimpan transaksi.'], 500);
}
