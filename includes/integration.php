<?php
/**
 * Integrasi Accurate — antrean, payload, adapter, worker.
 *
 * PRINSIP
 *  - Transaksi POS adalah sumber kebenaran dan TIDAK PERNAH diubah di sini.
 *  - Satu transaksi = satu idempotency key yang konsisten (POS-ORD-000123-SALE). Retry memakai key yang sama.
 *  - Bila hasil kiriman sebelumnya tidak pasti (timeout / proses terhenti), worker WAJIB memverifikasi
 *    (lookup by key) sebelum mengirim ulang -> tidak membuat dokumen ganda.
 *  - SUCCESS hanya bila target mengembalikan bukti (referensi dokumen). Mode simulasi ditandai jelas.
 *  - Kredensial bridge dibaca dari konfigurasi server (env / config/local.php), tidak dari database / frontend.
 *  - Tidak ada akses langsung ke database internal Accurate.
 */
require_once __DIR__ . '/orders.php';

const INTEGRATION_MAX_ATTEMPTS = 8;
const INTEGRATION_LOCK_MINUTES = 5;

/** Kunci mapping yang wajib (selain akun per metode pembayaran & kode barang per produk). */
function accurate_mapping_keys(PDO $pdo): array
{
    $keys = [
        'branch_code' => 'Kode cabang / outlet di Accurate',
        'warehouse_code' => 'Kode gudang',
        'customer_default' => 'Kode pelanggan umum (walk-in)',
        'tax_code' => 'Kode pajak (bila pajak dipakai)',
        'service_item_code' => 'Kode barang/jasa untuk service charge',
        'delivery_item_code' => 'Kode barang/jasa untuk ongkos kirim',
        'other_fee_item_code' => 'Kode barang/jasa untuk biaya lain',
    ];
    foreach ($pdo->query('SELECT code, name FROM payment_methods ORDER BY sort_order') as $m) {
        $keys['payment_account.' . $m['code']] = 'Akun kas/bank untuk ' . $m['name'];
    }
    return $keys;
}

function accurate_mapping(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query('SELECT map_key, map_value FROM accurate_account_mapping') as $r) {
        $out[$r['map_key']] = $r['map_value'];
    }
    return $out;
}

/** Konfigurasi bridge dari server (env atau config/local.php). Token tidak pernah dikirim ke browser. */
function accurate_bridge_config(): array
{
    $local = is_file(ROOT_PATH . '/config/local.php') ? require ROOT_PATH . '/config/local.php' : [];
    $get = function ($env, $key, $def) use ($local) {
        $v = getenv($env);
        if ($v !== false && $v !== '') {
            return $v;
        }
        return isset($local[$key]) ? $local[$key] : $def;
    };
    return [
        'url' => rtrim((string) $get('ACCURATE_BRIDGE_URL', 'accurate_bridge_url', ''), '/'),
        'token' => (string) $get('ACCURATE_BRIDGE_TOKEN', 'accurate_bridge_token', ''),
        'timeout' => max(1, (int) $get('ACCURATE_BRIDGE_TIMEOUT', 'accurate_bridge_timeout', 15)),
    ];
}

function integration_key(string $orderNumber, string $docType): string
{
    return 'POS-' . $orderNumber . '-' . $docType;
}

/** Masukkan pesanan ke antrean (dipanggil di dalam transaksi pembayaran/refund). Aman dipanggil berulang. */
function integration_enqueue(PDO $pdo, int $orderId, string $docType, bool $manual = false): bool
{
    $mode = setting('accurate_mode', 'off');
    if ($mode === 'off' || (!$manual && setting('accurate_send_on', 'paid') !== 'paid')) {
        return false;
    }
    $n = $pdo->prepare('SELECT order_number FROM orders WHERE id = ?');
    $n->execute([$orderId]);
    $num = $n->fetchColumn();
    if (!$num) {
        return false;
    }
    $st = $pdo->prepare("INSERT IGNORE INTO integration_queue (order_id, doc_type, idempotency_key, status, next_attempt_at) VALUES (?, ?, ?, 'PENDING', NOW())");
    $st->execute([$orderId, $docType, integration_key($num, $docType)]);
    return $st->rowCount() > 0;
}

function integration_log(PDO $pdo, int $qid, int $attempt, string $action, string $result, $summary, $response, ?int $ms = null, ?string $mode = null): void
{
    $u = function_exists('current_user') && session_status() === PHP_SESSION_ACTIVE ? current_user() : null;
    $pdo->prepare('INSERT INTO integration_logs (queue_id, attempt, action, result, mode, request_summary, response, duration_ms, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$qid, $attempt, $action, $result, $mode, is_string($summary) || $summary === null ? $summary : json_encode($summary, JSON_UNESCAPED_UNICODE),
                   is_string($response) || $response === null ? $response : json_encode($response, JSON_UNESCAPED_UNICODE), $ms, $u ? $u['id'] : null]);
}

/**
 * Bangun payload dokumen + validasi. Return [payload|null, error[]].
 * Format ini adalah KONTRAK INTERNAL ke bridge; bridge yang memetakan ke dokumen Accurate yang sudah disetujui.
 */
function integration_build_payload(PDO $pdo, array $q): array
{
    $o = order_full($pdo, (int) $q['order_id']);
    $map = accurate_mapping($pdo);
    $err = [];
    if (!$o) {
        return [null, ['Pesanan tidak ditemukan.']];
    }
    $need = function ($key, $why) use ($map, &$err) {
        if (!isset($map[$key]) || trim($map[$key]) === '') {
            $err[] = 'Mapping "' . $key . '" belum diisi (' . $why . ').';
            return null;
        }
        return trim($map[$key]);
    };
    $base = [
        'idempotency_key' => $q['idempotency_key'], 'document_type' => $q['doc_type'], 'source' => 'QR-POS',
        'source_reference' => $o['order_number'], 'outlet' => setting('outlet_name') ?: APP_NAME,
        'branch_code' => $need('branch_code', 'cabang'), 'warehouse_code' => $need('warehouse_code', 'gudang'),
        'customer_code' => $need('customer_default', 'pelanggan'),
    ];
    if ($q['doc_type'] === 'SALE') {
        if ($o['payment_status'] !== 'PAID' && $o['payment_status'] !== 'REFUNDED') {
            $err[] = 'Pesanan belum lunas; tidak dikirim.';
        }
        $lines = [];
        $sum = 0;
        $codes = $pdo->prepare('SELECT id, accurate_item_code FROM products WHERE id = ?');
        foreach ($o['items'] as $i) {
            if ($i['kitchen_status'] === 'CANCELLED') {
                continue;
            }
            $codes->execute([$i['product_id']]);
            $code = trim((string) $codes->fetch()['accurate_item_code']);
            if ($code === '') {
                $err[] = 'Kode barang Accurate belum diisi untuk "' . $i['name'] . '".';
            }
            $lines[] = ['item_code' => $code, 'name' => $i['name'], 'quantity' => $i['qty'], 'unit_price' => $i['price'], 'discount' => $i['discount_amount'], 'amount' => $i['subtotal']];
            $sum += $i['subtotal'];
        }
        $extras = [];
        foreach ([['service_amount', 'service_item_code', 'Service charge'], ['delivery_fee', 'delivery_item_code', 'Ongkos kirim'], ['other_fee', 'other_fee_item_code', 'Biaya lain']] as $x) {
            if ($o[$x[0]] > 0) {
                $extras[] = ['item_code' => $need($x[1], $x[2]), 'name' => $x[2], 'amount' => $o[$x[0]]];
            }
        }
        if ($o['tax_amount'] > 0) {
            $need('tax_code', 'pajak');
        }
        $payments = [];
        $paid = 0;
        foreach ($o['payments'] as $p) {
            if ($p['kind'] !== 'PAYMENT') {
                continue;
            }
            $payments[] = ['method' => $p['method_code'], 'account_code' => $need('payment_account.' . $p['method_code'], 'akun ' . $p['method_name']),
                           'amount' => (int) $p['amount'], 'reference' => $p['reference'], 'paid_at' => $p['created_at']];
            $paid += (int) $p['amount'];
        }
        // Validasi aritmetika: dokumen harus sama persis dengan transaksi POS
        $calc = $sum - $o['discount_amount'] + $o['service_amount'] + $o['tax_amount'] + $o['delivery_fee'] + $o['other_fee'];
        if ($calc !== $o['total']) {
            $err[] = 'Total tidak konsisten (hitung ' . $calc . ' vs pesanan ' . $o['total'] . ').';
        }
        if ($o['total'] > 0 && $paid !== $o['total']) {
            $err[] = 'Total pembayaran (' . $paid . ') tidak sama dengan total pesanan (' . $o['total'] . ').';
        }
        $payload = $base + [
            'transaction_date' => $o['closed_at'] ?: $o['created_at'], 'order_type' => $o['order_type'],
            'lines' => $lines, 'subtotal' => $o['subtotal'],
            'discount' => ['amount' => $o['discount_amount'], 'type' => $o['discount_type'], 'value' => $o['discount_value']],
            'tax' => ['code' => isset($map['tax_code']) ? $map['tax_code'] : null, 'rate' => $o['tax_rate'], 'amount' => $o['tax_amount'], 'includes_service' => (bool) $o['tax_include_service']],
            'charges' => $extras, 'total' => $o['total'], 'payments' => $payments,
        ];
    } else {
        $refunds = [];
        $amt = 0;
        foreach ($o['payments'] as $p) {
            if ($p['kind'] === 'REFUND') {
                $refunds[] = ['method' => $p['method_code'], 'account_code' => $need('payment_account.' . $p['method_code'], 'akun ' . $p['method_name']), 'amount' => -(int) $p['amount'], 'refunded_at' => $p['created_at'], 'reason' => $p['note']];
                $amt += -(int) $p['amount'];
            }
        }
        if (!$refunds) {
            $err[] = 'Tidak ada data refund.';
        }
        $payload = $base + ['original_key' => integration_key($o['order_number'], 'SALE'), 'refund_total' => $amt, 'refunds' => $refunds, 'transaction_date' => $refunds ? end($refunds)['refunded_at'] : null];
    }
    return [$err ? null : $payload, array_values(array_unique($err))];
}

/* ===================== Adapter ===================== */

interface AccurateAdapter
{
    /** Return ['status' => success|error|unknown, 'ref' => ?, 'message' => string, 'retryable' => bool] */
    public function send(array $payload, string $key): array;

    /** Return ['found' => true|false|null(tidak pasti), 'ref' => ?, 'message' => string] */
    public function lookup(string $key): array;

    public function mode(): string;
}

/** MODE SIMULASI: tidak menghubungi Accurate. Dokumen tiruan disimpan di integration_sim_documents. */
class SimulationAdapter implements AccurateAdapter
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function mode(): string
    {
        return 'simulation';
    }

    public function send(array $payload, string $key): array
    {
        $behavior = setting('accurate_sim_behavior', 'ok');
        if ($behavior === 'fail') {
            return ['status' => 'error', 'ref' => null, 'message' => 'SIMULASI: target tidak dapat dihubungi (gagal sementara).', 'retryable' => true];
        }
        if ($behavior === 'reject') {
            return ['status' => 'error', 'ref' => null, 'message' => 'SIMULASI: dokumen ditolak target (validasi).', 'retryable' => false];
        }
        $existing = $this->lookup($key);
        if ($existing['found']) {
            return ['status' => 'success', 'ref' => $existing['ref'], 'message' => 'SIMULASI: dokumen dengan key ini sudah ada (tidak dibuat ulang).', 'retryable' => false];
        }
        $ref = 'SIM-' . strtoupper(substr(hash('sha256', $key), 0, 10));
        $this->pdo->prepare('INSERT INTO integration_sim_documents (idempotency_key, reference, payload_hash) VALUES (?, ?, ?)')
            ->execute([$key, $ref, hash('sha256', json_encode($payload))]);
        if ($behavior === 'timeout_after_commit') {
            // Dokumen TERBUAT tetapi respons "hilang" -> worker harus memverifikasi, bukan mengirim ulang
            return ['status' => 'unknown', 'ref' => null, 'message' => 'SIMULASI: timeout menunggu respons (hasil tidak pasti).', 'retryable' => true];
        }
        return ['status' => 'success', 'ref' => $ref, 'message' => 'SIMULASI: dokumen tiruan dibuat (BUKAN dokumen Accurate).', 'retryable' => false];
    }

    public function lookup(string $key): array
    {
        $st = $this->pdo->prepare('SELECT reference FROM integration_sim_documents WHERE idempotency_key = ?');
        $st->execute([$key]);
        $ref = $st->fetchColumn();
        return ['found' => (bool) $ref, 'ref' => $ref ?: null, 'message' => $ref ? 'SIMULASI: ditemukan' : 'SIMULASI: belum ada'];
    }
}

/**
 * HTTP BRIDGE: layanan terpisah di sisi server Accurate (lihat docs/ACCURATE_INTEGRATION.md untuk kontraknya).
 *   POST {url}/documents          body = payload JSON, header Idempotency-Key  -> 201/200 {reference}; 409 = sudah ada {reference}; 422 = ditolak
 *   GET  {url}/documents/{key}    -> 200 {reference} | 404
 *   GET  {url}/health             -> 200
 */
class BridgeAdapter implements AccurateAdapter
{
    private $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function mode(): string
    {
        return 'bridge';
    }

    private function request(string $method, string $path, ?array $body, string $key = ''): array
    {
        if ($this->cfg['url'] === '' || $this->cfg['token'] === '') {
            return [0, null, 'Bridge belum dikonfigurasi (ACCURATE_BRIDGE_URL / ACCURATE_BRIDGE_TOKEN di server).'];
        }
        $ch = curl_init($this->cfg['url'] . $path);
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $this->cfg['token']];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        if ($key !== '') {
            $headers[] = 'Idempotency-Key: ' . $key;
        }
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_CONNECTTIMEOUT => min(5, $this->cfg['timeout']), CURLOPT_TIMEOUT => $this->cfg['timeout'],
                                CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = $raw === false ? curl_error($ch) : '';
        curl_close($ch);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        return [$raw === false ? 0 : $code, is_array($json) ? $json : null, $error];
    }

    public function send(array $payload, string $key): array
    {
        list($code, $j, $error) = $this->request('POST', '/documents', $payload, $key);
        $ref = $j && !empty($j['reference']) ? (string) $j['reference'] : null;
        if (($code === 200 || $code === 201 || $code === 409) && $ref) {
            return ['status' => 'success', 'ref' => $ref, 'message' => $code === 409 ? 'Sudah tercatat di target (tidak dibuat ulang).' : (isset($j['message']) ? (string) $j['message'] : 'Dokumen dibuat.'), 'retryable' => false];
        }
        if ($code >= 400 && $code < 500 && $code !== 408 && $code !== 429) {
            return ['status' => 'error', 'ref' => null, 'message' => 'Ditolak target (HTTP ' . $code . '): ' . ($j && isset($j['error']) ? $j['error'] : 'tanpa pesan'), 'retryable' => false];
        }
        // Timeout / 5xx / koneksi putus: dokumen MUNGKIN sudah dibuat -> tidak pasti
        return ['status' => 'unknown', 'ref' => null, 'message' => 'Hasil tidak pasti: ' . ($error ?: 'HTTP ' . $code), 'retryable' => true];
    }

    public function lookup(string $key): array
    {
        list($code, $j, $error) = $this->request('GET', '/documents/' . rawurlencode($key), null);
        if ($code === 200 && $j && !empty($j['reference'])) {
            return ['found' => true, 'ref' => (string) $j['reference'], 'message' => 'Ditemukan di target.'];
        }
        if ($code === 404) {
            return ['found' => false, 'ref' => null, 'message' => 'Belum ada di target.'];
        }
        return ['found' => null, 'ref' => null, 'message' => 'Verifikasi gagal: ' . ($error ?: 'HTTP ' . $code)];
    }

    public function health(): array
    {
        list($code, $j, $error) = $this->request('GET', '/health', null);
        return ['ok' => $code === 200, 'message' => $code === 200 ? ('OK' . ($j && isset($j['target']) ? ' — target: ' . $j['target'] : '')) : ($error ?: 'HTTP ' . $code)];
    }
}

function integration_adapter(PDO $pdo): ?AccurateAdapter
{
    $mode = setting('accurate_mode', 'off');
    if ($mode === 'simulation') {
        return new SimulationAdapter($pdo);
    }
    if ($mode === 'bridge') {
        return new BridgeAdapter(accurate_bridge_config());
    }
    return null;
}

/* ===================== Worker ===================== */

/** Proses antrean. Aman dijalankan paralel (klaim baris atomik). Return ringkasan. */
function integration_process(PDO $pdo, int $limit = 20): array
{
    $adapter = integration_adapter($pdo);
    $sum = ['processed' => 0, 'success' => 0, 'failed' => 0, 'retry' => 0, 'skipped' => 0];
    if (!$adapter) {
        return $sum + ['message' => 'Integrasi nonaktif (mode off).'];
    }
    // Proses yang terhenti di tengah (lock kedaluwarsa) -> hasil tidak pasti, wajib verifikasi
    $pdo->exec("UPDATE integration_queue SET status = 'RETRY_SCHEDULED', needs_verification = 1, next_attempt_at = NOW(),
                response_message = 'Proses sebelumnya terhenti; akan diverifikasi sebelum dikirim ulang.' WHERE status = 'PROCESSING' AND locked_until < NOW()");
    $ids = $pdo->query("SELECT id FROM integration_queue WHERE status IN ('PENDING','RETRY_SCHEDULED') AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
                        ORDER BY id LIMIT " . (int) $limit)->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $claim = $pdo->prepare("UPDATE integration_queue SET status = 'PROCESSING', attempts = attempts + 1, last_attempt_at = NOW(),
                                locked_until = NOW() + INTERVAL " . INTEGRATION_LOCK_MINUTES . " MINUTE WHERE id = ? AND status IN ('PENDING','RETRY_SCHEDULED')");
        $claim->execute([$id]);
        if ($claim->rowCount() !== 1) {
            $sum['skipped']++;   // diambil worker lain
            continue;
        }
        $sum['processed']++;
        $st = $pdo->prepare('SELECT * FROM integration_queue WHERE id = ?');
        $st->execute([$id]);
        $q = $st->fetch();
        $attempt = (int) $q['attempts'];
        $set = function (string $status, array $f = []) use ($pdo, $id) {
            $cols = ['status = ?', 'locked_until = NULL'];
            $vals = [$status];
            foreach ($f as $k => $v) {
                $cols[] = $k . ' = ?';
                $vals[] = $v;
            }
            $vals[] = $id;
            $pdo->prepare('UPDATE integration_queue SET ' . implode(', ', $cols) . ' WHERE id = ?')->execute($vals);
        };
        $backoff = function ($n) { return date('Y-m-d H:i:s', time() + 60 * min(60, (int) pow(2, max(0, $n - 1)))); };

        // Refund menunggu dokumen penjualan aslinya berhasil
        if ($q['doc_type'] === 'REFUND') {
            $s = $pdo->prepare("SELECT status FROM integration_queue WHERE order_id = ? AND doc_type = 'SALE'");
            $s->execute([$q['order_id']]);
            if ($s->fetchColumn() !== 'SUCCESS') {
                $set('PENDING', ['attempts' => max(0, $attempt - 1), 'next_attempt_at' => date('Y-m-d H:i:s', time() + 300), 'response_message' => 'Menunggu dokumen penjualan asli berhasil terkirim.']);
                $sum['skipped']++;
                continue;
            }
        }
        list($payload, $errors) = integration_build_payload($pdo, $q);
        if (!$payload) {
            $set('FAILED', ['response_message' => 'Validasi: ' . implode(' ', $errors)]);
            integration_log($pdo, $id, $attempt, 'VALIDATE', 'FAILED', null, $errors, null, $adapter->mode());
            $sum['failed']++;
            continue;
        }
        $hash = hash('sha256', json_encode($payload));
        $summary = ['key' => $q['idempotency_key'], 'type' => $q['doc_type'], 'total' => isset($payload['total']) ? $payload['total'] : $payload['refund_total'],
                    'lines' => isset($payload['lines']) ? count($payload['lines']) : 0, 'payload_sha256' => $hash];

        // Verifikasi sebelum kirim ulang: jangan membuat dokumen baru hanya karena respons sebelumnya hilang
        if ($attempt > 1 || $q['needs_verification']) {
            $t0 = microtime(true);
            $v = $adapter->lookup($q['idempotency_key']);
            integration_log($pdo, $id, $attempt, 'VERIFY', $v['found'] === true ? 'FOUND' : ($v['found'] === false ? 'NOT_FOUND' : 'UNKNOWN'), $summary, $v, (int) ((microtime(true) - $t0) * 1000), $adapter->mode());
            if ($v['found'] === true) {
                $set('SUCCESS', ['external_ref' => $v['ref'], 'succeeded_at' => date('Y-m-d H:i:s'), 'mode' => $adapter->mode(), 'needs_verification' => 0,
                                 'payload_hash' => $hash, 'response_message' => 'Terverifikasi sudah tercatat; tidak dikirim ulang.']);
                $sum['success']++;
                continue;
            }
            if ($v['found'] === null) {
                $final = $attempt >= INTEGRATION_MAX_ATTEMPTS;
                $set($final ? 'FAILED' : 'RETRY_SCHEDULED', ['next_attempt_at' => $backoff($attempt), 'response_message' => $v['message'] . ' — belum dikirim ulang.']);
                $sum[$final ? 'failed' : 'retry']++;
                continue;
            }
        }
        $t0 = microtime(true);
        try {
            $r = $adapter->send($payload, $q['idempotency_key']);
        } catch (Throwable $ex) {
            $r = ['status' => 'unknown', 'ref' => null, 'message' => 'Exception: ' . $ex->getMessage(), 'retryable' => true];
        }
        $ms = (int) ((microtime(true) - $t0) * 1000);
        integration_log($pdo, $id, $attempt, 'SEND', strtoupper($r['status']), $summary, $r, $ms, $adapter->mode());
        if ($r['status'] === 'success' && $r['ref']) {
            $set('SUCCESS', ['external_ref' => $r['ref'], 'succeeded_at' => date('Y-m-d H:i:s'), 'mode' => $adapter->mode(), 'needs_verification' => 0,
                             'payload_hash' => $hash, 'response_message' => $r['message']]);
            $sum['success']++;
        } elseif ($r['status'] === 'error' && !$r['retryable']) {
            $set('FAILED', ['response_message' => $r['message'], 'mode' => $adapter->mode()]);
            $sum['failed']++;
        } else {
            $final = $attempt >= INTEGRATION_MAX_ATTEMPTS;
            $set($final ? 'FAILED' : 'RETRY_SCHEDULED', ['next_attempt_at' => $backoff($attempt), 'needs_verification' => $r['status'] === 'unknown' ? 1 : (int) $q['needs_verification'],
                                                          'response_message' => $r['message'] . ($final ? ' — batas percobaan tercapai.' : ''), 'mode' => $adapter->mode()]);
            $sum[$final ? 'failed' : 'retry']++;
        }
    }
    return $sum;
}
