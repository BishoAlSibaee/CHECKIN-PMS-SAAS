<?php

/**
 * Temporary PHP diagnostics — upload to public/ then open in browser.
 * DELETE this file immediately after fixing PDO.
 */
header('Content-Type: application/json; charset=utf-8');

$modulePaths = [
    '/opt/alt/php83/usr/lib64/php/modules/pdo.so',
    '/opt/alt/php83/usr/lib64/php/modules/pdo_mysql.so',
    '/opt/alt/php82/usr/lib64/php/modules/pdo.so',
    '/opt/alt/php82/usr/lib64/php/modules/pdo_mysql.so',
];
$modulesOnDisk = [];
foreach ($modulePaths as $path) {
    $modulesOnDisk[$path] = file_exists($path);
}

echo json_encode([
    'php_version' => PHP_VERSION,
    'sapi' => php_sapi_name(),
    'pdo_loaded' => extension_loaded('pdo'),
    'pdo_mysql_loaded' => extension_loaded('pdo_mysql'),
    'mysqli_loaded' => extension_loaded('mysqli'),
    'openssl_loaded' => extension_loaded('openssl'),
    'mbstring_loaded' => extension_loaded('mbstring'),
    'loaded_ini' => php_ini_loaded_file() ?: null,
    'user_ini' => ini_get('user_ini.filename') ?: null,
    'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? null,
    'module_files_on_server' => $modulesOnDisk,
    'ready_for_laravel' => extension_loaded('pdo') && extension_loaded('pdo_mysql'),
    'fix' => extension_loaded('pdo')
        ? 'PDO OK — delete this file'
        : 'cPanel → Select PHP Version → domain hotelsystemback → Extensions: enable pdo + pdo_mysql. Upload public/.user.ini. Or ask host to install alt-php83-php-pdo packages.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
