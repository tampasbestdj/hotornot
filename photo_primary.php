<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    hon_error('Method not allowed.', 405);
}
$id = hon_require_login();
$input = hon_json_input();
$photoId = (int) ($input['photo_id'] ?? 0);
if ($photoId < 1) {
    hon_error('Missing photo.');
}
$pdo = hon_db();
hon_set_primary($pdo, $id, $photoId);
hon_json(['ok' => true]);
