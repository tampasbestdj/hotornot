<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$id = hon_current_user_id();
if (!$id) {
    http_response_code(401);
    header('Content-Type: text/plain');
    echo 'Login required';
    exit;
}
$photoId = (int) ($_GET['id'] ?? 0);
if ($photoId < 1) {
    http_response_code(404);
    exit;
}
$pdo = hon_db();
$row = hon_get_photo($pdo, $photoId);
if (!$row) {
    http_response_code(404);
    exit;
}
$path = hon_resolve_photo_file($row['file_path']);
if (!$path) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/jpeg');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
header('Content-Length: ' . (string) filesize($path));
readfile($path);
exit;
