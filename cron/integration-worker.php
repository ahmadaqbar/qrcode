<?php
/**
 * Worker antrean integrasi Accurate (CLI). Jalankan via cron, mis. setiap menit:
 *   * * * * * php /path/ke/app/cron/integration-worker.php >> /var/log/qrpos-integration.log 2>&1
 * Aman dijalankan paralel; baris antrean diklaim secara atomik.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require_once __DIR__ . '/../includes/integration.php';
$limit = isset($argv[1]) ? max(1, (int) $argv[1]) : 50;
try {
    $r = integration_process(db(), $limit);
    echo date('c') . ' ' . json_encode($r) . PHP_EOL;
} catch (Throwable $ex) {
    fwrite(STDERR, date('c') . ' ERROR ' . $ex->getMessage() . PHP_EOL);
    exit(1);
}
