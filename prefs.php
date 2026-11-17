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

$see = hon_parse_see_flags($input, [
    'see_men' => (int) ($user['see_men'] ?? 0),
    'see_women' => (int) ($user['see_women'] ?? 0),
    'see_couples' => (int) ($user['see_couples'] ?? 0),
]);
if (!hon_see_selected($see)) {
    hon_error('Please pick at least one of Men, Women, or Couples to rate.');
}

$confirmed = (int) $user['adult_confirmed'] === 1 || hon_flag_on($input['adult_confirmed'] ?? '');
$ratings = hon_normalize_ratings_list((string) ($input['filter_ratings'] ?? $user['filter_ratings'] ?? 'sfw'), $confirmed);

$stmt = $pdo->prepare('UPDATE users SET see_men = ?, see_women = ?, see_couples = ?, filter_gender = ?, filter_ratings = ?, adult_confirmed = ? WHERE id = ?');
$stmt->execute([
    $see['see_men'],
    $see['see_women'],
    $see['see_couples'],
    hon_derived_filter_gender($see),
    implode(',', $ratings),
    $confirmed ? 1 : 0,
    $id,
]);
$user = hon_user_by_id($pdo, $id);
hon_json(['ok' => true, 'user' => hon_public_user($user)]);
