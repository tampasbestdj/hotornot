<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$pdo = hon_db();
$id = hon_current_user_id();
if (!$id) {
    hon_json(['ok' => true, 'user' => null, 'csrf' => $_SESSION['csrf'] ?? '']);
}
$user = hon_user_by_id($pdo, $id);
if (!$user) {
    unset($_SESSION['user_id']);
    hon_json(['ok' => true, 'user' => null, 'csrf' => $_SESSION['csrf'] ?? '']);
}
hon_json(['ok' => true, 'user' => hon_public_user($user)]);
