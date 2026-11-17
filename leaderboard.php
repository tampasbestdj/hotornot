<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

hon_require_login();
$pdo = hon_db();
hon_json(['ok' => true, 'users' => hon_leaderboard($pdo)]);
