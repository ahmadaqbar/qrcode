<?php
/**
 * Konfigurasi database. Kredensial TIDAK ditulis di sini.
 * Isi lewat environment variable (DB_HOST, DB_NAME, DB_USER, DB_PASS)
 * atau buat file config/local.php (tidak ikut git) yang me-return array:
 *   <?php return ['host' => '127.0.0.1', 'name' => 'restaurant_ordering', 'user' => 'app', 'pass' => 'rahasia'];
 */
$local = is_file(__DIR__ . '/local.php') ? require __DIR__ . '/local.php' : [];

return [
    'host' => getenv('DB_HOST') ?: (isset($local['host']) ? $local['host'] : '127.0.0.1'),
    'name' => getenv('DB_NAME') ?: (isset($local['name']) ? $local['name'] : 'restaurant_ordering'),
    'user' => getenv('DB_USER') ?: (isset($local['user']) ? $local['user'] : 'root'),
    'pass' => getenv('DB_PASS') !== false ? getenv('DB_PASS') : (isset($local['pass']) ? $local['pass'] : ''),
];
