<?php
declare(strict_types=1);

const HON_ROOT = __DIR__;

function hon_config(): array {
    static $config;
    if ($config !== null) {
        return $config;
    }
    $candidates = [
        dirname(__DIR__) . '/config.php',
        '/home1/vhuwyfmy/private/newhotornot/config.php',
    ];
    foreach ($candidates as $path) {
        if (is_readable($path)) {
            $config = require $path;
            return $config;
        }
    }
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Server is not configured yet.']);
    exit;
}

function hon_db(): PDO {
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $cfg = hon_config();
    $dsn = $cfg['dsn'] ?? '';
    if (str_starts_with($dsn, 'sqlite:')) {
        $path = substr($dsn, 7);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
    } else {
        $pdo = new PDO($dsn, $cfg['user'] ?? '', $cfg['pass'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('SET NAMES utf8mb4');
    }
    require_once HON_ROOT . '/migrate.php';
    hon_migrate($pdo);
    return $pdo;
}

function hon_uploads_dir(): string {
    $dir = hon_config()['uploads_dir'] ?? (dirname(__DIR__) . '/uploads');
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    return $dir;
}

function hon_seeds_dir(): string {
    return hon_config()['seeds_dir'] ?? dirname(__DIR__, 2) . '/images';
}

function hon_cookie_domain(): string {
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if (str_contains($host, ':')) {
        $host = explode(':', $host, 2)[0];
    }
    if ($host === 'newhotornot.com' || $host === 'www.newhotornot.com') {
        return '.newhotornot.com';
    }
    return '';
}

function hon_boot_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = true;
    session_name('hon_session');
    $params = [
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    $domain = hon_cookie_domain();
    if ($domain !== '') {
        $params['domain'] = $domain;
    }
    session_set_cookie_params($params);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', '1');
    session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function hon_security_headers(): void {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    header('Cache-Control: no-store');
}

function hon_allowed_origins(): array {
    return hon_config()['allowed_origins'] ?? [
        'https://newhotornot.com',
        'https://www.newhotornot.com',
    ];
}

function hon_request_origin(): ?string {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        return rtrim($origin, '/');
    }
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if ($referer === '') {
        return null;
    }
    $parts = parse_url($referer);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return null;
    }
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    return $parts['scheme'] . '://' . $parts['host'] . $port;
}

function hon_csrf_ok(): bool {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return true;
    }
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    if ($token !== '' && hash_equals($_SESSION['csrf'] ?? '', $token)) {
        return true;
    }
    $origin = hon_request_origin();
    return $origin !== null && in_array($origin, hon_allowed_origins(), true);
}

function hon_json_input(): array {
    $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ctype, 'application/json') !== false) {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
    return $_POST;
}

function hon_json(array $payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

class HonException extends RuntimeException {
    public int $status;
    public function __construct(string $message, int $status = 400) {
        parent::__construct($message, $status);
        $this->status = $status;
    }
}

function hon_error(string $message, int $code = 400): void {
    if (defined('HON_THROW_ERRORS') && HON_THROW_ERRORS) {
        throw new HonException($message, $code);
    }
    hon_json(['ok' => false, 'error' => $message], $code);
}

function hon_current_user_id(): ?int {
    $id = $_SESSION['user_id'] ?? null;
    return is_int($id) || is_numeric($id) ? (int) $id : null;
}

function hon_require_login(): int {
    $id = hon_current_user_id();
    if (!$id) {
        hon_error('Please log in first.', 401);
    }
    return $id;
}

function hon_public_user(array $user): array {
    $hots = (int) ($user['hots'] ?? 0);
    $votes = (int) ($user['votes'] ?? 0);
    return [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'gender' => $user['gender'],
        'age' => isset($user['age']) && $user['age'] !== null ? (int) $user['age'] : null,
        'filter_gender' => $user['filter_gender'] ?? 'both',
        'filter_ratings' => $user['filter_ratings'] ?? 'sfw',
        'see_men' => (int) ($user['see_men'] ?? 0),
        'see_women' => (int) ($user['see_women'] ?? 0),
        'see_couples' => (int) ($user['see_couples'] ?? 0),
        'needs_prefs' => hon_needs_see_prefs($user),
        'adult_confirmed' => (int) ($user['adult_confirmed'] ?? 0),
        'is_couple' => (($user['gender'] ?? '') === 'couple'),
        'hots' => $hots,
        'votes' => $votes,
        'csrf' => $_SESSION['csrf'] ?? '',
    ];
}

function hon_user_by_id(PDO $pdo, int $id): ?array {
    $sql = 'SELECT u.*,
        COALESCE((SELECT SUM(p.hots) FROM photos p WHERE p.user_id = u.id), 0) AS hots,
        COALESCE((SELECT SUM(p.votes) FROM photos p WHERE p.user_id = u.id), 0) AS votes
        FROM users u WHERE u.id = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function hon_login_user(int $userId): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

hon_security_headers();
hon_boot_session();
if (!hon_csrf_ok()) {
    hon_error('Invalid request origin.', 403);
}

require_once HON_ROOT . '/photos.php';
