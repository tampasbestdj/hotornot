<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_init.php';

hon_security_headers();
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(404);
    echo '{"ok":false}';
    exit;
}

$cfg = hon_config();
$secret = (string) ($cfg['stats_secret'] ?? '');
if ($secret === '') {
    http_response_code(404);
    echo '{"ok":false}';
    exit;
}

$given = (string) ($_GET['key'] ?? '');
if ($given === '') {
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (stripos($auth, 'Bearer ') === 0) {
        $given = trim(substr($auth, 7));
    }
}

if (!hash_equals($secret, $given)) {
    http_response_code(404);
    echo '{"ok":false}';
    exit;
}

require_once HON_ROOT . '/stats_collect.php';

try {
    $payload = hon_stats_collect();
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Stats collection failed.',
    ]);
}
