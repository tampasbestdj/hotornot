<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    hon_error('Method not allowed.', 405);
}

$input = hon_json_input();
$name = trim((string) ($input['name'] ?? ''));
$password = (string) ($input['password'] ?? '');
if ($name === '' || $password === '') {
    hon_error('Enter your name and password.');
}

$pdo = hon_db();
$stmt = $pdo->prepare('SELECT id, password_hash, is_seed FROM users WHERE name = ?');
$stmt->execute([$name]);
$user = $stmt->fetch();
if (!$user || (int) $user['is_seed'] === 1 || !password_verify($password, $user['password_hash'])) {
    hon_error('Wrong name or password.', 401);
}

hon_login_user((int) $user['id']);
$full = hon_user_by_id($pdo, (int) $user['id']);
hon_json(['ok' => true, 'user' => hon_public_user($full)]);
