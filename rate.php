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
$isHot = in_array($input['is_hot'] ?? 0, [1, '1', true, 'true'], true);
$pdo = hon_db();
$photo = hon_rate_photo($pdo, $id, $photoId, $isHot);
hon_json(['ok' => true, 'photo' => $photo, 'user' => hon_public_user(hon_user_by_id($pdo, $id))]);
