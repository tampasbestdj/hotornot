<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    hon_error('Method not allowed.', 405);
}
$id = hon_require_login();
if (empty($_FILES['photo'])) {
    hon_error('Please choose a photo.');
}
$pdo = hon_db();
$user = hon_user_by_id($pdo, $id);
$rating = hon_normalize_rating((string) ($_POST['tr_rating'] ?? 'sfw'));
$isCouple = ($user['gender'] ?? '') === 'couple' || in_array((string) ($_POST['is_couple'] ?? '0'), ['1', 'true', 'on'], true);
$photo = hon_add_photo($pdo, $id, $_FILES['photo'], $rating, $isCouple);
hon_json(['ok' => true, 'photo' => $photo, 'user' => hon_public_user(hon_user_by_id($pdo, $id))]);
