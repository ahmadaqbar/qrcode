<?php
/** Stok sederhana untuk produk menu (bukan bahan baku / resep). */
require_once __DIR__ . '/orders.php';

/** Saat pesanan lunas: kurangi stok produk yang dilacak (sekali per pesanan). */
function inventory_on_paid(PDO $pdo, int $orderId): void
{
    $ex = $pdo->prepare("SELECT COUNT(*) FROM inventory_movements WHERE order_id = ? AND type = 'SALE'");
    $ex->execute([$orderId]);
    if ((int) $ex->fetchColumn() > 0) {
        return;
    }
    $st = $pdo->prepare("SELECT i.product_id, SUM(i.quantity) AS q FROM order_items i JOIN products p ON p.id = i.product_id
                         WHERE i.order_id = ? AND i.kitchen_status <> 'CANCELLED' AND p.track_stock = 1 GROUP BY i.product_id");
    $st->execute([$orderId]);
    $ins = $pdo->prepare("INSERT INTO inventory_movements (product_id, type, qty, note, order_id) VALUES (?, 'SALE', ?, 'Penjualan', ?)");
    foreach ($st->fetchAll() as $r) {
        $ins->execute([$r['product_id'], -(int) $r['q'], $orderId]);
    }
}

function stock_balances(PDO $pdo): array
{
    return $pdo->query('SELECT p.id, p.name, p.sku, p.track_stock, p.stock_min, c.name AS cat, COALESCE(SUM(m.qty), 0) AS balance
                        FROM products p JOIN categories c ON c.id = p.category_id LEFT JOIN inventory_movements m ON m.product_id = p.id
                        GROUP BY p.id ORDER BY p.track_stock DESC, p.name')->fetchAll();
}
