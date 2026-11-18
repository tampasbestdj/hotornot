<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    $cookie = [
        'expires' => time() - 3600,
        'path' => $params['path'] ?? '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if (!empty($params['domain'])) {
        $cookie['domain'] = $params['domain'];
    }
    setcookie(session_name(), '', $cookie);
}
session_destroy();
hon_json(['ok' => true]);
