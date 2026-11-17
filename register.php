<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    hon_error('Method not allowed.', 405);
}

$input = hon_json_input();
$name = trim((string) ($input['name'] ?? $_POST['name'] ?? ''));
$password = (string) ($input['password'] ?? $_POST['password'] ?? '');
$gender = hon_normalize_gender((string) ($input['gender'] ?? $_POST['gender'] ?? 'm'));
$rating = hon_normalize_rating((string) ($input['tr_rating'] ?? $_POST['tr_rating'] ?? 'sfw'));
$isCouple = ($gender === 'couple') || in_array((string) ($input['is_couple'] ?? $_POST['is_couple'] ?? '0'), ['1', 'true', 'on'], true);

if (strlen($name) < 2 || strlen($name) > 80) {
    hon_error('Please choose a name between 2 and 80 characters.');
}
if (strlen($password) < 8) {
    hon_error('Password must be at least 8 characters.');
}
if (empty($_FILES['photo'])) {
    hon_error('Please upload a photo to join.');
}

$pdo = hon_db();
$exists = $pdo->prepare('SELECT 1 FROM users WHERE name = ?');
$exists->execute([$name]);
if ($exists->fetchColumn()) {
    hon_error('That name is already taken.');
}

$path = hon_store_upload($_FILES['photo']);
$pdo->beginTransaction();
try {
    $ins = $pdo->prepare('INSERT INTO users (name, password_hash, gender) VALUES (?, ?, ?)');
    $ins->execute([$name, password_hash($password, PASSWORD_DEFAULT), $gender]);
    $userId = (int) $pdo->lastInsertId();
    $photo = $pdo->prepare('INSERT INTO photos (user_id, file_path, tr_rating, is_couple, is_primary) VALUES (?, ?, ?, ?, 1)');
    $photo->execute([$userId, $path, $rating, $isCouple ? 1 : 0]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $full = hon_uploads_dir() . '/' . $path;
    if (is_file($full)) {
        @unlink($full);
    }
    error_log('register failed: ' . $e->getMessage());
    hon_error('Could not create your account. Try again.');
}

hon_login_user($userId);
$user = hon_user_by_id($pdo, $userId);
hon_json(['ok' => true, 'user' => hon_public_user($user)]);
