<?php
/**
 * MOCK bridge — HANYA untuk pengujian kontrak HTTP bridge. INI BUKAN ACCURATE.
 *   php -S 127.0.0.1:8099 tools/mock-accurate-bridge/index.php
 * Env: MOCK_TOKEN (wajib cocok), MOCK_STORE (file JSON), MOCK_FAULT=none|timeout|error500|reject|down
 *   timeout : dokumen DISIMPAN lalu respons ditahan melebihi timeout klien (menguji verify-before-retry)
 */
$token = getenv('MOCK_TOKEN') ?: 'test-token';
$store = getenv('MOCK_STORE') ?: sys_get_temp_dir() . '/mock-accurate-bridge.json';
$faultFile = $store . '.fault';
$fault = is_file($faultFile) ? trim(file_get_contents($faultFile)) : (getenv('MOCK_FAULT') ?: 'none');
header('Content-Type: application/json');
$send = function ($code, $data) { http_response_code($code); echo json_encode($data); exit; };
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/health') {
    $send(200, ['ok' => true, 'target' => 'MOCK (bukan Accurate)']);
}
if ((isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '') !== 'Bearer ' . $token) {
    $send(401, ['error' => 'unauthorized']);
}
if ($fault === 'down') {
    $send(503, ['error' => 'service unavailable']);
}
$db = is_file($store) ? json_decode(file_get_contents($store), true) : [];
if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('#^/documents/(.+)$#', $path, $m)) {
    $k = rawurldecode($m[1]);
    isset($db[$k]) ? $send(200, ['reference' => $db[$k]['reference']]) : $send(404, ['error' => 'not found']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/documents') {
    $k = isset($_SERVER['HTTP_IDEMPOTENCY_KEY']) ? $_SERVER['HTTP_IDEMPOTENCY_KEY'] : '';
    $body = json_decode(file_get_contents('php://input'), true);
    if ($k === '' || !is_array($body) || $body['idempotency_key'] !== $k) {
        $send(422, ['error' => 'idempotency key / body tidak valid']);
    }
    if ($fault === 'reject') {
        $send(422, ['error' => 'dokumen ditolak (mock)']);
    }
    if ($fault === 'error500') {
        $send(500, ['error' => 'internal error']);
    }
    if (isset($db[$k])) {
        $send(409, ['reference' => $db[$k]['reference'], 'message' => 'already exists']);
    }
    $ref = 'MOCK-' . (count($db) + 1);
    $db[$k] = ['reference' => $ref, 'total' => isset($body['total']) ? $body['total'] : null, 'type' => $body['document_type'], 'at' => date('c')];
    file_put_contents($store, json_encode($db, JSON_PRETTY_PRINT), LOCK_EX);
    if ($fault === 'timeout') {
        sleep(4);
    }
    $send(201, ['reference' => $ref, 'message' => 'created (mock)']);
}
$send(404, ['error' => 'route not found']);
