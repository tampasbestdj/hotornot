<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$id = hon_require_login();
$pdo = hon_db();
$user = hon_user_by_id($pdo, $id);
if (!$user) {
    hon_error('Please log in first.', 401);
}
if (hon_needs_see_prefs($user)) {
    hon_json([
        'ok' => true,
        'photo' => null,
        'needs_prefs' => true,
        'message' => 'Please choose who you want to rate',
    ]);
}
$photo = hon_get_pool($pdo, $user);
if (!$photo) {
    hon_json(['ok' => true, 'photo' => null, 'message' => 'No photos in this set yet']);
}
hon_json(['ok' => true, 'photo' => $photo]);
