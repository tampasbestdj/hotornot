<?php
declare(strict_types=1);
$honLib = getenv('HON_LIB') ?: '/home1/vhuwyfmy/private/newhotornot/lib/boot.php';
if (!is_readable($honLib)) {
    $fallback = __DIR__ . '/lib/boot.php';
    $honLib = is_readable($fallback) ? $fallback : $honLib;
}
if (!is_readable($honLib)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Server is not configured yet.']);
    exit;
}
require $honLib;
