<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    hon_error('Method not allowed.', 405);
}
$id = hon_require_login();
$input = hon_json_input();
$pdo = hon_db();
$user = hon_user_by_id($pdo, $id);
if (!$user) {
    hon_error('Please log in first.', 401);
}

$gender = strtolower(trim((string) ($input['filter_gender'] ?? $user['filter_gender'] ?? 'both')));
if (!in_array($gender, HON_GENDER_FILTERS, true)) {
    $gender = 'both';
}
$confirmed = (int) $user['adult_confirmed'] === 1 || in_array((string) ($input['adult_confirmed'] ?? ''), ['1', 'true'], true);
$ratings = hon_normalize_ratings_list((string) ($input['filter_ratings'] ?? $user['filter_ratings'] ?? 'sfw'), $confirmed);

$stmt = $pdo->prepare('UPDATE users SET filter_gender = ?, filter_ratings = ?, adult_confirmed = ? WHERE id = ?');
$stmt->execute([$gender, implode(',', $ratings), $confirmed ? 1 : 0, $id]);
$user = hon_user_by_id($pdo, $id);
hon_json(['ok' => true, 'user' => hon_public_user($user)]);
