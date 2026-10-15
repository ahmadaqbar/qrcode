<?php
/**
 * Logika pesanan bersama untuk QR ordering & POS (satu sumber data: orders + order_items).
 * Harga, pajak, service, diskon dihitung DI SINI saja agar QR dan POS selalu konsisten.
 */
require_once __DIR__ . '/functions.php';

const ORDER_TYPES = ['DINE_IN' => 'Dine-in', 'TAKEAWAY' => 'Takeaway', 'DELIVERY' => 'Delivery'];
const PAYMENT_STATUS_LABELS = ['UNPAID' => 'Belum bayar', 'PARTIAL' => 'Bayar sebagian', 'PAID' => 'Lunas', 'REFUNDED' => 'Refund'];
const KITCHEN_STATUS_LABELS = ['PENDING' => 'Belum dikirim', 'NEW' => 'Baru', 'PREPARING' => 'Disiapkan', 'READY' => 'Siap', 'SERVED' => 'Selesai', 'CANCELLED' => 'Batal'];
const DELIVERY_STATUS_LABELS = [
    'NEW' => 'New', 'CONFIRMED' => 'Confirmed', 'PREPARING' => 'Preparing', 'READY_FOR_PICKUP' => 'Ready for Pickup',
    'OUT_FOR_DELIVERY' => 'Out for Delivery', 'DELIVERED' => 'Delivered', 'CANCELLED' => 'Cancelled',
];
// Alur maju saja. CANCELLED hanya lewat pembatalan pesanan (agar tidak bertentangan dengan pembayaran).
const DELIVERY_TRANSITIONS = [
    'NEW' => ['CONFIRMED'],
    'CONFIRMED' => ['PREPARING', 'READY_FOR_PICKUP'],
    'PREPARING' => ['READY_FOR_PICKUP'],
    'READY_FOR_PICKUP' => ['OUT_FOR_DELIVERY'],
    'OUT_FOR_DELIVERY' => ['DELIVERED'],
    'DELIVERED' => [],
    'CANCELLED' => [],
];

/** Semua settings (cache per request). */
function settings_all(): array
{
    static $all = null;
    if ($all === null) {
        $all = [];
        foreach (db()->query('SELECT setting_key, setting_value FROM settings') as $r) {
            $all[$r['setting_key']] = $r['setting_value'];
        }
    }
    return $all;
}

function setting(string $key, string $default = ''): string
{
    $a = settings_all();
    return isset($a[$key]) ? $a[$key] : $default;
}

/** Tarif yang berlaku SEKARANG untuk tipe pesanan (disimpan ke pesanan sebagai snapshot). */
function pricing_snapshot(string $type): array
{
    $service = (float) setting('service_rate', '0');
    if (setting('service_dine_in_only', '1') === '1' && $type !== 'DINE_IN') {
        $service = 0.0;
    }
    return [
        'service_rate' => max(0, min(100, $service)),
        'tax_rate' => max(0, min(100, (float) setting('tax_rate', '0'))),
        'tax_include_service' => setting('tax_include_service', '1') === '1' ? 1 : 0,
    ];
}

function money_round(float $v): int
{
    return (int) round($v, 0, PHP_ROUND_HALF_UP);
}

/** Diskon baris (nominal) dari tipe & nilai. Tidak pernah melebihi harga baris. */
function line_discount(int $price, int $qty, string $type, int $value): int
{
    $gross = $price * $qty;
    if ($type === 'PERCENT') {
        return min($gross, money_round($gross * min(100, $value) / 100));
    }
    if ($type === 'AMOUNT') {
        return min($gross, $value);
    }
    return 0;
}

/**
 * Hitung total. $items: [price, quantity, discount_amount, kitchen_status]; $o: tarif snapshot + diskon + biaya.
 * Urutan: subtotal (setelah diskon item) - diskon transaksi = dasar; service dari dasar; pajak dari dasar (+ service bila diatur).
 */
function compute_totals(array $items, array $o): array
{
    $subtotal = 0;
    foreach ($items as $i) {
        if (isset($i['kitchen_status']) && $i['kitchen_status'] === 'CANCELLED') {
            continue;
        }
        $subtotal += max(0, (int) $i['price'] * (int) $i['quantity'] - (int) (isset($i['discount_amount']) ? $i['discount_amount'] : 0));
    }
    $dtype = isset($o['discount_type']) ? $o['discount_type'] : 'NONE';
    $dval = isset($o['discount_value']) ? (int) $o['discount_value'] : 0;
    $disc = $dtype === 'PERCENT' ? min($subtotal, money_round($subtotal * min(100, $dval) / 100))
          : ($dtype === 'AMOUNT' ? min($subtotal, $dval) : 0);
    $base = $subtotal - $disc;
    $service = money_round($base * (float) $o['service_rate'] / 100);
    $tax = money_round(($base + (!empty($o['tax_include_service']) ? $service : 0)) * (float) $o['tax_rate'] / 100);
    $delivery = isset($o['delivery_fee']) ? (int) $o['delivery_fee'] : 0;
    $other = isset($o['other_fee']) ? (int) $o['other_fee'] : 0;
    return [
        'subtotal' => $subtotal, 'discount_amount' => $disc, 'service_amount' => $service, 'tax_amount' => $tax,
        'delivery_fee' => $delivery, 'other_fee' => $other, 'total' => $base + $service + $tax + $delivery + $other,
    ];
}

/** Hitung ulang & simpan total pesanan dari item + snapshot tarifnya. */
function recalc_order(PDO $pdo, int $orderId): array
{
    $o = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $o->execute([$orderId]);
    $order = $o->fetch();
    $it = $pdo->prepare('SELECT price, quantity, discount_amount, kitchen_status FROM order_items WHERE order_id = ?');
    $it->execute([$orderId]);
    $t = compute_totals($it->fetchAll(), $order);
    $pdo->prepare('UPDATE orders SET subtotal = ?, discount_amount = ?, service_amount = ?, tax_amount = ?, total = ? WHERE id = ?')
        ->execute([$t['subtotal'], $t['discount_amount'], $t['service_amount'], $t['tax_amount'], $t['total'], $orderId]);
    return $t;
}

/** Kirim item PENDING ke dapur/bar sebagai tiket (batch) baru. Return jumlah item terkirim. */
function send_to_kitchen(PDO $pdo, int $orderId): int
{
    $b = $pdo->prepare('SELECT COALESCE(MAX(kitchen_batch), 0) + 1 FROM order_items WHERE order_id = ?');
    $b->execute([$orderId]);
    $batch = (int) $b->fetchColumn();
    $u = $pdo->prepare("UPDATE order_items SET kitchen_status = 'NEW', kitchen_batch = ?, sent_at = NOW()
                        WHERE order_id = ? AND kitchen_status = 'PENDING'");
    $u->execute([$batch, $orderId]);
    $n = $u->rowCount();
    if ($n > 0) {
        // Delivery: pesanan dikonfirmasi saat masuk dapur
        delivery_advance($pdo, $orderId, 'NEW', 'CONFIRMED', 'Pesanan dikirim ke dapur');
    }
    return $n;
}

/** Ubah status delivery bila status saat ini = $from (dipakai sinkronisasi otomatis dengan dapur). */
function delivery_advance(PDO $pdo, int $orderId, string $from, string $to, string $note, ?int $userId = null): bool
{
    $st = $pdo->prepare('UPDATE delivery_details SET delivery_status = ? WHERE order_id = ? AND delivery_status = ?');
    $st->execute([$to, $orderId, $from]);
    if ($st->rowCount() === 0) {
        return false;
    }
    $pdo->prepare('INSERT INTO delivery_status_history (order_id, from_status, to_status, user_id, note) VALUES (?, ?, ?, ?, ?)')
        ->execute([$orderId, $from, $to, $userId, $note]);
    return true;
}

/** Label tempat: "Meja 01" / "Takeaway" / "Delivery". */
function place_label(array $o): string
{
    if ($o['order_type'] === 'DINE_IN') {
        return !empty($o['table_number']) ? 'Meja ' . $o['table_number'] : 'Dine-in';
    }
    return ORDER_TYPES[$o['order_type']];
}

/** Token acak 32 hex (dipakai sebagai order_token / kunci idempotensi). */
function random_token(): string
{
    return bin2hex(random_bytes(16));
}

/** Insert baris pesanan baru (ORD-xxxxxx dari id; unik walau banyak kasir bersamaan). Return id. */
function insert_order(PDO $pdo, array $f): int
{
    $cols = array_keys($f);
    $pdo->prepare('INSERT INTO orders (order_number, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ')')
        ->execute(array_merge(['T' . substr($f['order_token'], 0, 15)], array_values($f)));
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE orders SET order_number = ? WHERE id = ?')->execute([sprintf('ORD-%06d', $id), $id]);
    return $id;
}

/** Pesanan lengkap (untuk POS/API). Null bila tidak ada. */
function order_full(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT o.*, t.table_number FROM orders o LEFT JOIN tables t ON t.id = o.table_id WHERE o.id = ?');
    $st->execute([$id]);
    $o = $st->fetch();
    if (!$o) {
        return null;
    }
    $it = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
    $it->execute([$id]);
    $items = [];
    foreach ($it->fetchAll() as $i) {
        $items[] = [
            'id' => (int) $i['id'], 'product_id' => (int) $i['product_id'], 'name' => $i['product_name'], 'price' => (int) $i['price'],
            'qty' => (int) $i['quantity'], 'note' => (string) $i['note'], 'subtotal' => (int) $i['subtotal'],
            'discount_type' => $i['discount_type'], 'discount_value' => (int) $i['discount_value'], 'discount_amount' => (int) $i['discount_amount'],
            'station' => $i['station'], 'kitchen_status' => $i['kitchen_status'], 'kitchen_label' => KITCHEN_STATUS_LABELS[$i['kitchen_status']],
            'batch' => (int) $i['kitchen_batch'], 'sent_at' => $i['sent_at'],
        ];
    }
    $d = $pdo->prepare('SELECT d.*, u.name AS courier_name FROM delivery_details d LEFT JOIN users u ON u.id = d.courier_id WHERE d.order_id = ?');
    $d->execute([$id]);
    $delivery = $d->fetch() ?: null;
    $payments = [];
    if (table_exists($pdo, 'payments')) {
        $p = $pdo->prepare('SELECT p.*, m.name AS method_name, u.name AS user_name FROM payments p
                            LEFT JOIN payment_methods m ON m.code = p.method_code LEFT JOIN users u ON u.id = p.created_by
                            WHERE p.order_id = ? ORDER BY p.id');
        $p->execute([$id]);
        $payments = $p->fetchAll();
    }
    $int = ['id', 'table_id', 'version', 'discount_value', 'discount_amount', 'subtotal', 'service_amount', 'tax_amount', 'delivery_fee',
            'other_fee', 'total', 'paid_amount', 'tax_include_service'];
    foreach ($int as $k) {
        $o[$k] = $o[$k] === null ? null : (int) $o[$k];
    }
    $o['service_rate'] = (float) $o['service_rate'];
    $o['tax_rate'] = (float) $o['tax_rate'];
    $o['status_label'] = status_label($o['status']);
    $o['payment_label'] = PAYMENT_STATUS_LABELS[$o['payment_status']];
    $o['place_label'] = place_label($o);
    $o['remaining'] = max(0, $o['total'] - $o['paid_amount']);
    $o['editable'] = in_array($o['status'], ['DRAFT', 'NEW', 'PROCESSING'], true) && $o['payment_status'] === 'UNPAID';
    unset($o['order_token'], $o['client_ip']);   // token = akses halaman sukses customer; tidak perlu ke POS
    $o['items'] = $items;
    $o['delivery'] = $delivery;
    $o['payments'] = $payments;
    return $o;
}

function table_exists(PDO $pdo, string $name): bool
{
    static $cache = [];
    if (!isset($cache[$name])) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $st->execute([$name]);
        $cache[$name] = (bool) $st->fetchColumn();
    }
    return $cache[$name];
}

/** Pesanan yang masih "menempati" meja: belum selesai, atau sudah dibayar tapi meja belum ditandai tersedia. */
const SQL_TABLE_OCCUPYING = "o.order_type = 'DINE_IN' AND o.table_id IS NOT NULL
    AND (o.status IN ('DRAFT','NEW','PROCESSING') OR (o.status = 'COMPLETED' AND o.table_released_at IS NULL))";

/**
 * Status tiap meja aktif, diturunkan dari transaksi sebenarnya:
 * ORDERING (ada pesanan QR baru / transaksi ditahan) > WAITING_PAYMENT (tagihan dicetak / bayar sebagian)
 * > OCCUPIED (pesanan berjalan) > PAID (lunas, meja belum dikosongkan) > AVAILABLE.
 */
function table_overview(PDO $pdo): array
{
    $tables = [];
    foreach ($pdo->query('SELECT id, table_number FROM tables WHERE is_active = 1 ORDER BY table_number') as $t) {
        $tables[(int) $t['id']] = ['id' => (int) $t['id'], 'table_number' => $t['table_number'], 'status' => 'AVAILABLE', 'orders' => [], 'total' => 0, 'since' => null];
    }
    $rows = $pdo->query('SELECT o.id, o.order_number, o.table_id, o.status, o.source, o.payment_status, o.total, o.paid_amount, o.bill_printed_at, o.created_at
                         FROM orders o WHERE ' . SQL_TABLE_OCCUPYING . ' ORDER BY o.id')->fetchAll();
    $rank = ['AVAILABLE' => 0, 'PAID' => 1, 'OCCUPIED' => 2, 'WAITING_PAYMENT' => 3, 'ORDERING' => 4];
    foreach ($rows as $r) {
        $tid = (int) $r['table_id'];
        if (!isset($tables[$tid])) {
            continue;
        }
        if (in_array($r['status'], ['NEW', 'DRAFT'], true)) {
            $s = 'ORDERING';
        } elseif ($r['status'] === 'PROCESSING') {
            $s = ($r['bill_printed_at'] || $r['payment_status'] === 'PARTIAL') ? 'WAITING_PAYMENT' : 'OCCUPIED';
        } else {
            $s = 'PAID';
        }
        if ($rank[$s] > $rank[$tables[$tid]['status']]) {
            $tables[$tid]['status'] = $s;
        }
        if ($r['status'] !== 'COMPLETED') {
            $tables[$tid]['total'] += (int) $r['total'] - (int) $r['paid_amount'];
        }
        if ($tables[$tid]['since'] === null) {
            $tables[$tid]['since'] = $r['created_at'];
        }
        $tables[$tid]['orders'][] = ['id' => (int) $r['id'], 'number' => $r['order_number'], 'status' => $r['status'], 'source' => $r['source'],
                                     'payment_status' => $r['payment_status'], 'total' => (int) $r['total']];
    }
    return array_values($tables);
}

const TABLE_STATUS_LABELS = ['AVAILABLE' => 'Available', 'OCCUPIED' => 'Occupied', 'ORDERING' => 'Ordering', 'WAITING_PAYMENT' => 'Waiting Payment', 'PAID' => 'Paid'];

/** Pesanan aktif yang belum dibayar penuh di meja (untuk mencegah transaksi ganda di meja yang sama). */
function active_orders_on_table(PDO $pdo, int $tableId, int $exceptId = 0): array
{
    $st = $pdo->prepare("SELECT id, order_number, status, source, total FROM orders
                         WHERE table_id = ? AND order_type = 'DINE_IN' AND status IN ('DRAFT','NEW','PROCESSING') AND id <> ? ORDER BY id");
    $st->execute([$tableId, $exceptId]);
    return $st->fetchAll();
}

/** Dipanggil (di dalam transaksi DB) saat pesanan menjadi LUNAS. Modul stok & integrasi menambahkan prosesnya di sini. */
function on_order_paid(PDO $pdo, int $orderId): void
{
    if (function_exists('inventory_on_paid')) {
        inventory_on_paid($pdo, $orderId);
    }
    if (function_exists('integration_enqueue')) {
        integration_enqueue($pdo, $orderId, 'SALE');
    }
}

/** Dipanggil (di dalam transaksi DB) saat pembayaran pesanan direfund. */
function on_order_refunded(PDO $pdo, int $orderId): void
{
    if (function_exists('integration_enqueue')) {
        integration_enqueue($pdo, $orderId, 'REFUND');
    }
}
