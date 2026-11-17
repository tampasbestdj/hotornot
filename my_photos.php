<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$id = hon_require_login();
$pdo = hon_db();
$data = hon_my_photos($pdo, $id);
hon_json(['ok' => true] + $data);
