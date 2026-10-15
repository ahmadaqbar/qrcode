<?php
/** Shift kasir: ringkasan kas & kondisi yang menghalangi tutup shift. */
require_once __DIR__ . '/orders.php';

function open_shift_for(PDO $pdo, int $userId): ?array
{
    $st = $pdo->prepare("SELECT * FROM cash_shifts WHERE user_id = ? AND status = 'OPEN'");
    $st->execute([$userId]);
    return $st->fetch() ?: null;
}

/** Hitung ringkasan dari data transaksi (dipakai saat shift berjalan & saat ditutup). */
function shift_summary(PDO $pdo, array $shift): array
{
    $id = (int) $shift['id'];
    $st = $pdo->prepare("SELECT p.kind, m.type, p.method_code, m.name, SUM(p.amount) AS total, COUNT(*) AS n
                         FROM payments p JOIN payment_methods m ON m.code = p.method_code
                         WHERE p.shift_id = ? GROUP BY p.kind, m.type, p.method_code, m.name ORDER BY m.sort_order");
    $st->execute([$id]);
    $s = ['cash_sales' => 0, 'noncash_sales' => 0, 'refunds_cash' => 0, 'refunds_noncash' => 0, 'by_method' => [], 'cash_in' => 0, 'cash_out' => 0];
    foreach ($st->fetchAll() as $r) {
        $amt = (int) $r['total'];
        if ($r['kind'] === 'PAYMENT') {
            $s[$r['type'] === 'CASH' ? 'cash_sales' : 'noncash_sales'] += $amt;
        } else {
            $s[$r['type'] === 'CASH' ? 'refunds_cash' : 'refunds_noncash'] += -$amt;
        }
        $k = $r['method_code'];
        if (!isset($s['by_method'][$k])) {
            $s['by_method'][$k] = ['name' => $r['name'], 'payments' => 0, 'refunds' => 0, 'count' => 0];
        }
        $s['by_method'][$k][$r['kind'] === 'PAYMENT' ? 'payments' : 'refunds'] += abs($amt);
        $s['by_method'][$k]['count'] += (int) $r['n'];
    }
    $m = $pdo->prepare('SELECT type, COALESCE(SUM(amount), 0) FROM cash_movements WHERE shift_id = ? GROUP BY type');
    $m->execute([$id]);
    foreach ($m->fetchAll(PDO::FETCH_KEY_PAIR) as $t => $v) {
        $s[$t === 'IN' ? 'cash_in' : 'cash_out'] = (int) $v;
    }
    $s['opening_cash'] = (int) $shift['opening_cash'];
    $s['total_sales'] = $s['cash_sales'] + $s['noncash_sales'];
    $s['net_sales'] = $s['total_sales'] - $s['refunds_cash'] - $s['refunds_noncash'];
    $s['expected_cash'] = $s['opening_cash'] + $s['cash_sales'] - $s['refunds_cash'] + $s['cash_in'] - $s['cash_out'];
    $end = $shift['closed_at'] ?: date('Y-m-d H:i:s');
    $c = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(total), 0) FROM orders WHERE status = 'CANCELLED' AND merged_into_id IS NULL
                        AND cancelled_by = ? AND cancelled_at BETWEEN ? AND ?");
    $c->execute([$shift['user_id'], $shift['opened_at'], $end]);
    list($s['cancel_count'], $s['cancel_total']) = array_map('intval', $c->fetch(PDO::FETCH_NUM));
    $t = $pdo->prepare("SELECT COUNT(DISTINCT order_id) FROM payments WHERE shift_id = ? AND kind = 'PAYMENT'");
    $t->execute([$id]);
    $s['transactions'] = (int) $t->fetchColumn();
    return $s;
}

/** Kondisi yang harus diselesaikan sebelum tutup shift (atau di-override manager). */
function shift_blockers(PDO $pdo, array $shift): array
{
    $out = [];
    $d = $pdo->prepare("SELECT order_number FROM orders WHERE status = 'DRAFT' AND created_by = ?");
    $d->execute([$shift['user_id']]);
    if ($held = $d->fetchAll(PDO::FETCH_COLUMN)) {
        $out[] = 'Transaksi masih ditahan (hold): ' . implode(', ', $held);
    }
    $p = $pdo->prepare("SELECT DISTINCT o.order_number FROM payments p JOIN orders o ON o.id = p.order_id
                        WHERE p.shift_id = ? AND o.payment_status = 'PARTIAL' AND o.status <> 'CANCELLED'");
    $p->execute([$shift['id']]);
    if ($partial = $p->fetchAll(PDO::FETCH_COLUMN)) {
        $out[] = 'Pembayaran sebagian belum lunas: ' . implode(', ', $partial);
    }
    return $out;
}
