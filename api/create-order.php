<?php
/**
 * POST JSON: {table, token, items:[{id, qty}], note, name}
 * Harga SELALU diambil dari database, bukan dari client.
 * `token` (32 hex, dibuat client) bersifat idempoten: kirim ulang token yang sama
 * (double click / refresh / retry) tidak membuat order ganda.
 */
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Metode tidak diizinkan.'], 405);
}

$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) {
    json_response(['ok' => false, 'error' => 'Data tidak valid.'], 400);
}

function clean_text($v, int $max): ?string
{
    $v = is_string($v) ? trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v)) : '';
    if ($v === '') {
        return null;
    }
    return mb_strlen($v, 'UTF-8') > $max ? false : $v;
}

$tableNo = isset($in['table']) && is_string($in['table']) ? trim($in['table']) : '';
$token   = isset($in['token']) && is_string($in['token']) ? $in['token'] : '';
$note    = clean_text(isset($in['note']) ? $in['note'] : '', 500);
$name    = clean_text(isset($in['name']) ? $in['name'] : '', 100);
$rawItems = isset($in['items']) && is_array($in['items']) ? $in['items'] : [];

if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
    json_response(['ok' => false, 'error' => 'Permintaan tidak valid.'], 400);
}
if ($note === false || $name === false) {
    json_response(['ok' => false, 'error' => 'Catatan atau nama terlalu panjang.'], 422);
}
if (!$rawItems) {
    json_response(['ok' => false, 'error' => 'Keranjang kosong. Pilih minimal satu menu.'], 422);
}
if (count($rawItems) > 50) {
    json_response(['ok' => false, 'error' => 'Terlalu banyak item.'], 422);
}

// Gabungkan qty per produk & validasi
$qtyById = [];
foreach ($rawItems as $it) {
    $id  = isset($it['id']) ? filter_var($it['id'], FILTER_VALIDATE_INT) : false;
    $qty = isset($it['qty']) ? filter_var($it['qty'], FILTER_VALIDATE_INT) : false;
    if ($id === false || $qty === false || $id < 1 || $qty < 1 || $qty > 99) {
        json_response(['ok' => false, 'error' => 'Item pesanan tidak valid.'], 422);
    }
    $qtyById[$id] = (isset($qtyById[$id]) ? $qtyById[$id] : 0) + $qty;
    if ($qtyById[$id] > 99) {
        json_response(['ok' => false, 'error' => 'Jumlah maksimal 99 per menu.'], 422);
    }
}

try {
    $pdo = db();

    // Idempoten: token sudah pernah dipakai -> kembalikan order yang sama
    $find = $pdo->prepare('SELECT order_number FROM orders WHERE order_token = ?');
    $find->execute([$token]);
    if ($existing = $find->fetch()) {
        json_response(['ok' => true, 'duplicate' => true, 'order_number' => $existing['order_number'], 'token' => $token]);
    }

    $table = find_active_table($tableNo);
    if (!$table) {
        json_response(['ok' => false, 'error' => 'Meja tidak valid.'], 422);
    }

    $ids = array_keys($qtyById);
    $in_sql = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT p.id, p.name, p.price, p.is_available
                         FROM products p JOIN categories c ON c.id = p.category_id AND c.is_active = 1
                         WHERE p.id IN ($in_sql)");
    $st->execute($ids);
    $products = [];
    foreach ($st->fetchAll() as $p) {
        $products[(int) $p['id']] = $p;
    }

    $lines = [];
    $total = 0;
    foreach ($qtyById as $id => $qty) {
        if (!isset($products[$id]) || !$products[$id]['is_available']) {
            $label = isset($products[$id]) ? $products[$id]['name'] : 'Menu';
            json_response(['ok' => false, 'error' => $label . ' sedang tidak tersedia. Silakan perbarui keranjang.'], 422);
        }
        $p = $products[$id];
        $sub = (int) $p['price'] * $qty;
        $total += $sub;
        $lines[] = [$id, $p['name'], (int) $p['price'], $qty, $sub];
    }

    $pdo->beginTransaction();
    try {
        // Nomor sementara, diganti ORD-xxxxxx dari id setelah insert
        $pdo->prepare('INSERT INTO orders (order_number, order_token, table_id, customer_name, note, total, status)
                       VALUES (?, ?, ?, ?, ?, ?, \'NEW\')')
            ->execute(['T' . substr($token, 0, 15), $token, $table['id'], $name, $note, $total]);
        $orderId = (int) $pdo->lastInsertId();
        $number = sprintf('ORD-%06d', $orderId);
        $pdo->prepare('UPDATE orders SET order_number = ? WHERE id = ?')->execute([$number, $orderId]);

        $ins = $pdo->prepare('INSERT INTO order_items (order_id, product_id, product_name, price, quantity, subtotal)
                              VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($lines as $l) {
            $ins->execute([$orderId, $l[0], $l[1], $l[2], $l[3], $l[4]]);
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        // Race: request kembar dengan token sama -> unique key bentrok
        $find->execute([$token]);
        if ($existing = $find->fetch()) {
            json_response(['ok' => true, 'duplicate' => true, 'order_number' => $existing['order_number'], 'token' => $token]);
        }
        throw $ex;
    }

    json_response(['ok' => true, 'order_number' => $number, 'token' => $token], 201);
} catch (Throwable $ex) {
    error_log('create-order: ' . $ex->getMessage());
    json_response(['ok' => false, 'error' => 'Maaf, pesanan gagal diproses. Silakan coba lagi.'], 500);
}
