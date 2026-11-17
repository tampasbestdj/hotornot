<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    hon_error('Method not allowed.', 405);
}
$id = hon_require_login();
$input = hon_json_input();
$password = (string) ($input['password'] ?? '');
if ($password === '') {
    hon_error('Password required.');
}
$pdo = hon_db();
hon_delete_account($pdo, $id, $password);
$_SESSION = [];
session_destroy();
hon_json(['ok' => true]);
