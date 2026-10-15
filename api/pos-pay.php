<?php
/**
 * POST JSON (POS): catat pembayaran. {order_id, method, amount, tendered, reference, idem_key}
 * - Pesanan dikunci (SELECT ... FOR UPDATE) -> dua kasir tidak bisa membayar sisa yang sama.
 * - idem_key unik -> klik ganda / retry tidak membuat pembayaran ganda.
 * - Nominal tidak boleh melebihi sisa tagihan. Tunai: uang diterima >= nominal, kembalian dicatat.
 * - Non-tunai dicatat MANUAL (tidak ada payment gateway). Jangan input data kartu, hanya no. referensi.
 * - Lunas -> pesanan ditutup (COMPLETED). Status dapur tidak dianggap status pembayaran.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/shift.php';
foreach (['inventory', 'integration'] as $mod) {
    if (is_file(__DIR__ . '/../includes/' . $mod . '.php')) {
        require_once __DIR__ . '/../includes/' . $mod . '.php';
    }
}
require_permission('payment.take', true);
require_post_csrf();
$in = json_input();

$orderId = (int) filter_var(isset($in['order_id']) ? $in['order_id'] : 0, FILTER_VALIDATE_INT);
$method = isset($in['method']) ? (string) $in['method'] : '';
$amount = filter_var(isset($in['amount']) ? $in['amount'] : null, FILTER_VALIDATE_INT);
$tendered = filter_var(isset($in['tendered']) ? $in['tendered'] : 0, FILTER_VALIDATE_INT);
$reference = clean_input(isset($in['reference']) ? $in['reference'] : '', 100);
$idem = isset($in['idem_key']) && is_string($in['idem_key']) ? $in['idem_key'] : '';

if (!$orderId || $amount === false || $amount < 0 || $tendered === false || $tendered < 0 || !preg_match('/^[a-f0-9]{32}$/', $idem)) {
    json_response(['ok' => false, 'error' => 'Data pembayaran tidak valid.'], 422);
}

$pdo = db();
$u = current_user();
function pay_fail(string $msg, int $code = 422, array $extra = []): void
{
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    json_response(array_merge(['ok' => false, 'error' => $msg], $extra), $code);
}

try {
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
    $st->execute([$orderId]);
    $o = $st->fetch();
    if (!$o) {
        pay_fail('Pesanan tidak ditemukan.', 404);
    }
    // Idempoten: kunci yang sama sudah tercatat -> kembalikan hasil yang sama
    $dup = $pdo->prepare('SELECT * FROM payments WHERE idem_key = ?');
    $dup->execute([$idem]);
    if ($prev = $dup->fetch()) {
        $pdo->commit();
        json_response(['ok' => true, 'duplicate' => true, 'payment' => $prev, 'change' => (int) $prev['change_amount'], 'order' => order_full($pdo, $orderId)]);
    }
    if (!in_array($o['status'], ['DRAFT', 'NEW', 'PROCESSING'], true) || !in_array($o['payment_status'], ['UNPAID', 'PARTIAL'], true)) {
        pay_fail('Pesanan ini sudah ' . ($o['payment_status'] === 'PAID' ? 'lunas' : strtolower(status_label($o['status']))) . '. Pembayaran tidak dicatat ulang.', 409, ['code' => 'ALREADY_PAID']);
    }
    $shift = open_shift_for($pdo, (int) $u['id']);
    if (!$shift) {
        pay_fail('Buka shift kasir terlebih dahulu sebelum menerima pembayaran.', 409, ['code' => 'NO_SHIFT']);
    }
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE order_id = ? AND kitchen_status <> 'CANCELLED'");
    $cnt->execute([$orderId]);
    if (!(int) $cnt->fetchColumn()) {
        pay_fail('Pesanan kosong tidak dapat dibayar.');
    }
    $m = $pdo->prepare('SELECT * FROM payment_methods WHERE code = ? AND is_active = 1');
    $m->execute([$method]);
    $pm = $m->fetch();
    if (!$pm) {
        pay_fail('Metode pembayaran tidak valid.');
    }
    $remaining = (int) $o['total'] - (int) $o['paid_amount'];
    if ($remaining > 0 && ($amount < 1 || $amount > $remaining)) {
        pay_fail('Nominal pembayaran harus antara Rp1 dan sisa tagihan ' . rupiah($remaining) . '.');
    }
    if ($pm['type'] === 'CASH') {
        if ($tendered === 0) {
            $tendered = $amount;
        }
        if ($tendered < $amount || $tendered > $amount + 10000000) {
            pay_fail('Uang diterima kurang dari nominal pembayaran.');
        }
    } else {
        $tendered = $amount;
        if ($pm['requires_reference'] && $reference === '') {
            pay_fail('Nomor referensi wajib diisi untuk ' . $pm['name'] . '.', 422, ['code' => 'NEED_REFERENCE']);
        }
    }
    $change = $tendered - $amount;

    // Item yang belum dikirim ke dapur ikut dikirim saat dibayar
    send_to_kitchen($pdo, $orderId);
    if ($amount > 0) {
        $pdo->prepare('INSERT INTO payments (order_id, shift_id, method_code, kind, amount, tendered, change_amount, reference, idem_key, created_by)
                       VALUES (?, ?, ?, \'PAYMENT\', ?, ?, ?, ?, ?, ?)')
            ->execute([$orderId, $shift['id'], $pm['code'], $amount, $tendered, $change, $reference ?: null, $idem, $u['id']]);
    }
    $paid = (int) $o['paid_amount'] + $amount;
    $full = $paid >= (int) $o['total'];
    $pdo->prepare('UPDATE orders SET paid_amount = ?, payment_status = ?, status = ?, closed_at = ?, closed_by = ?, table_released_at = ?, version = version + 1 WHERE id = ?')
        ->execute([$paid, $full ? 'PAID' : 'PARTIAL', $full ? 'COMPLETED' : 'PROCESSING', $full ? date('Y-m-d H:i:s') : null, $full ? $u['id'] : null,
                   ($full && setting('table_auto_release', '0') === '1') ? date('Y-m-d H:i:s') : null, $orderId]);
    if ($full) {
        on_order_paid($pdo, $orderId);
    }
    $pdo->commit();
    audit('payment.create', 'order', $orderId, ['method' => $pm['code'], 'amount' => $amount, 'tendered' => $tendered, 'change' => $change,
                                                'reference' => $reference, 'shift_id' => (int) $shift['id'], 'paid_full' => $full]);
    json_response(['ok' => true, 'change' => $change, 'paid_full' => $full, 'order' => order_full($pdo, $orderId)]);
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Race: request kembar dengan idem_key sama
    $dup = $pdo->prepare('SELECT * FROM payments WHERE idem_key = ?');
    $dup->execute([$idem]);
    if ($prev = $dup->fetch()) {
        json_response(['ok' => true, 'duplicate' => true, 'payment' => $prev, 'change' => (int) $prev['change_amount'], 'order' => order_full($pdo, $orderId)]);
    }
    error_log('pos-pay: ' . $ex->getMessage());
    json_response(['ok' => false, 'error' => 'Pembayaran gagal dicatat. Tidak ada uang yang tercatat; silakan coba lagi.'], 500);
}
