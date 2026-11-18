<?php
declare(strict_types=1);

/**
 * Private signup + traffic counters for the morning newhotornot.com digest.
 * Returns counts only — never emails, names, IPs, or user agents.
 */

const HON_STATS_TZ = 'America/New_York';
const HON_DB_TZ = 'America/Boise';

const HON_SITE_HOSTS = [
    'newhotornot.com',
    'www.newhotornot.com',
];

const HON_SSL_LOG_CANDIDATES = [
    '/home1/vhuwyfmy/access-logs/newhotornot.vhu.wyf.mybluehost.me-ssl_log',
    '/usr/local/apache/domlogs/vhuwyfmy/newhotornot.vhu.wyf.mybluehost.me-ssl_log',
];

const HON_HTTP_LOG_CANDIDATES = [
    '/home1/vhuwyfmy/access-logs/newhotornot.vhu.wyf.mybluehost.me',
    '/usr/local/apache/domlogs/vhuwyfmy/newhotornot.vhu.wyf.mybluehost.me',
];

const HON_LOG_ARCHIVE_DIR = '/home1/vhuwyfmy/private/newhotornot/log-archive';
const HON_STATS_HISTORY = '/home1/vhuwyfmy/private/newhotornot/stats_history.json';
const HON_AWSTATS_SSL_DIR = '/home1/vhuwyfmy/tmp/awstats/ssl';
const HON_AWSTATS_DOMAIN = 'newhotornot.vhu.wyf.mybluehost.me';

const HON_SESSION_TIMEOUT = 3600;

const HON_BOT_NEEDLES = [
    'hondailystats',
    'googlebot', 'google-inspection', 'apis-google', 'adsbot-google',
    'bingbot', 'bingpreview', 'msnbot', 'adidxbot',
    'slurp', 'duckduckbot', 'baiduspider', 'yandex',
    'facebookexternalhit', 'facebot', 'meta-external', 'twitterbot',
    'linkedinbot', 'pinterest', 'slackbot', 'discordbot',
    'telegrambot', 'whatsapp', 'applebot', 'amazonbot',
    'gptbot', 'chatgpt-user', 'claudebot', 'anthropic', 'ccbot',
    'bytespider', 'petalbot', 'semrush', 'ahrefs', 'mj12bot',
    'dotbot', 'dataforseo', 'rogerbot', 'screaming frog',
    'ia_archiver', 'archive.org', 'wayback', 'internetarchive',
    'lighthouse', 'pagespeed', 'pingdom', 'uptimerobot',
    'gtmetrix', 'statuscake', 'headlesschrome', 'puppeteer',
    'playwright', 'phantomjs', 'scrapy', 'python-requests',
    'python-urllib', 'go-http-client', 'libwww', 'okhttp',
    'apache-httpclient', 'java/', 'curl/', 'wget/', 'php/',
    'axios/', 'node-fetch', 'httpclient', 'monitor',
    'crawler', 'spider', 'crawl', 'bot/', 'bot;', ' bot',
    'scanner', 'masscan', 'zgrab', 'nuclei', 'sqlmap',
    'embedly', 'iframely', 'skypeuripreview', 'vkshare',
    'redditbot', 'qwantify', 'seznam', 'sogou', 'exabot',
    'hootsuite', 'bitlybot', 'outbrain', 'preview',
];

function hon_stats_collect(): array {
    $et = new DateTimeZone(HON_STATS_TZ);
    $now = new DateTimeImmutable('now', $et);
    $today = $now->format('Y-m-d');
    $yesterday = $now->modify('-1 day')->format('Y-m-d');

    $pdo = hon_db();
    $signups = hon_stats_signups($pdo, $now);

    hon_stats_snapshot_logs();
    $parsed = hon_stats_parse_all_logs();
    $awstats = hon_stats_awstats_ssl();
    $history = hon_stats_load_history();
    $history = hon_stats_merge_history($history, $parsed, $today);
    hon_stats_save_history($history);

    $trend = [];
    for ($i = 6; $i >= 0; $i--) {
        $day = $now->modify("-{$i} day")->format('Y-m-d');
        $trend[] = hon_stats_day_row($day, $parsed, $history, $awstats, $day === $today);
    }

    $yrow = hon_stats_day_row($yesterday, $parsed, $history, $awstats, false);

    return [
        'ok' => true,
        'generated_at' => $now->format('c'),
        'timezone' => HON_STATS_TZ,
        'today' => $today,
        'yesterday' => $yesterday,
        'signups' => [
            'total_accounts' => $signups['total_accounts'],
            'new_last_24h' => $signups['new_last_24h'],
            'new_yesterday' => $signups['new_yesterday'],
            'excluded_test_or_seed' => $signups['excluded_test_or_seed'],
        ],
        'photos' => [
            'total' => $signups['photos_total'],
            'uploaded_yesterday' => $signups['photos_yesterday'],
        ],
        'ratings' => [
            'cast_yesterday' => $signups['ratings_yesterday'],
        ],
        'traffic' => [
            'source' => 'bluehost_raw_access_logs',
            'hosts' => HON_SITE_HOSTS,
            'protocol' => 'https',
            'bots_filtered' => true,
            'session_timeout_minutes' => 60,
            'yesterday' => [
                'date' => $yesterday,
                'visits' => $yrow['visits'],
                'unique_visitors' => $yrow['unique_visitors'],
                'pageviews' => $yrow['pageviews'],
                'coverage' => $yrow['coverage'],
            ],
            'trend_7d' => $trend,
            'digest' => [
                'how' => 'The Morning Bluehost Daily Stats digest counts newhotornot.com as AWStats SSL “visits” (sessions, 60-minute timeout, status 200/304) from newhotornot.vhu.wyf.mybluehost.me-ssl_log. uniqueMonth is AWStats TotalUnique. HTTP AWStats is not included. Those AWStats totals include every Host in the vhost log (webmail, cpanel, mail, scanners), and day buckets follow the log timezone (-0700), not America/New_York.',
                'awstats_ssl' => $awstats,
            ],
            'http_excluded_from_primary' => true,
        ],
    ];
}

function hon_stats_account_where(): string {
    return "is_seed = 0
        AND name NOT LIKE 'testhon%'
        AND name NOT LIKE 'test\\_%'
        AND LOWER(name) NOT IN ('test', 'tester', 'testing', 'demo', 'dummy')";
}

function hon_stats_signups(PDO $pdo, DateTimeImmutable $nowEt): array {
    $et = $nowEt->getTimezone();
    $boise = new DateTimeZone(HON_DB_TZ);
    $today = $nowEt->format('Y-m-d');
    $yesterday = $nowEt->modify('-1 day')->format('Y-m-d');
    $since24 = $nowEt->sub(new DateInterval('PT24H'))->setTimezone($boise)->format('Y-m-d H:i:s');
    $ystart = (new DateTimeImmutable($yesterday . ' 00:00:00', $et))->setTimezone($boise)->format('Y-m-d H:i:s');
    $yend = (new DateTimeImmutable($today . ' 00:00:00', $et))->setTimezone($boise)->format('Y-m-d H:i:s');
    $where = hon_stats_account_where();

    $total = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE $where")->fetchColumn();
    $excluded = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() - $total;

    $q24 = $pdo->prepare("SELECT COUNT(*) FROM users WHERE $where AND created_at >= ?");
    $q24->execute([$since24]);

    $qY = $pdo->prepare("SELECT COUNT(*) FROM users WHERE $where AND created_at >= ? AND created_at < ?");
    $qY->execute([$ystart, $yend]);

    $photosTotal = (int) $pdo->query('SELECT COUNT(*) FROM photos')->fetchColumn();
    $pY = $pdo->prepare('SELECT COUNT(*) FROM photos WHERE created_at >= ? AND created_at < ?');
    $pY->execute([$ystart, $yend]);

    $rY = $pdo->prepare('SELECT COUNT(*) FROM ratings WHERE created_at >= ? AND created_at < ?');
    $rY->execute([$ystart, $yend]);

    return [
        'total_accounts' => $total,
        'new_last_24h' => (int) $q24->fetchColumn(),
        'new_yesterday' => (int) $qY->fetchColumn(),
        'excluded_test_or_seed' => $excluded,
        'photos_total' => $photosTotal,
        'photos_yesterday' => (int) $pY->fetchColumn(),
        'ratings_yesterday' => (int) $rY->fetchColumn(),
    ];
}

function hon_stats_first_readable(array $candidates): ?string {
    $seen = [];
    foreach ($candidates as $path) {
        if (!is_readable($path)) {
            continue;
        }
        $real = realpath($path) ?: $path;
        if (isset($seen[$real])) {
            continue;
        }
        $seen[$real] = true;
        return $path;
    }
    return null;
}

function hon_stats_log_first_line(string $path): ?string {
    $fh = @fopen($path, 'r');
    if (!$fh) {
        return null;
    }
    $line = fgets($fh);
    fclose($fh);
    return $line === false ? null : rtrim($line, "\r\n");
}

function hon_stats_snapshot_logs(): void {
    $dir = HON_LOG_ARCHIVE_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return;
    }
    $pairs = [
        'ssl_log.live' => hon_stats_first_readable(HON_SSL_LOG_CANDIDATES),
        'http_log.live' => hon_stats_first_readable(HON_HTTP_LOG_CANDIDATES),
    ];
    foreach ($pairs as $name => $src) {
        if ($src === null) {
            continue;
        }
        $dest = $dir . '/' . $name;
        if (is_readable($dest)) {
            $oldFirst = hon_stats_log_first_line($dest);
            $newFirst = hon_stats_log_first_line($src);
            if ($oldFirst && $newFirst && $oldFirst !== $newFirst) {
                $stamp = gmdate('YmdHis');
                @rename($dest, $dir . '/' . $name . '.' . $stamp);
            }
        }
        @copy($src, $dest);
        @chmod($dest, 0600);
    }
}

function hon_stats_archive_files(): array {
    $files = [];
    $seen = [];
    $add = function (string $path) use (&$files, &$seen): void {
        if (!is_readable($path) || is_dir($path)) {
            return;
        }
        $real = realpath($path) ?: $path;
        if (isset($seen[$real])) {
            return;
        }
        $seen[$real] = true;
        $files[] = $path;
    };
    $ssl = hon_stats_first_readable(HON_SSL_LOG_CANDIDATES);
    $http = hon_stats_first_readable(HON_HTTP_LOG_CANDIDATES);
    if ($ssl) {
        $add($ssl);
    }
    $dir = HON_LOG_ARCHIVE_DIR;
    if (is_dir($dir) && is_readable($dir)) {
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (!preg_match('/ssl_log|ssl-/i', $name)) {
                continue;
            }
            $add($dir . '/' . $name);
        }
    }
    return $files;
}

function hon_stats_is_bot(string $ua): bool {
    $ua = strtolower(trim($ua));
    if ($ua === '' || $ua === '-') {
        return true;
    }
    foreach (HON_BOT_NEEDLES as $needle) {
        if (str_contains($ua, $needle)) {
            return true;
        }
    }
    return false;
}

function hon_stats_is_site_host(string $host): bool {
    $host = strtolower($host);
    if (str_contains($host, ':')) {
        $host = explode(':', $host, 2)[0];
    }
    return in_array($host, HON_SITE_HOSTS, true);
}

function hon_stats_is_page(string $path, string $method, int $status): bool {
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        return false;
    }
    if (!in_array($status, [200, 304], true)) {
        return false;
    }
    $pathOnly = strtolower(explode('?', $path, 2)[0]);
    if ($pathOnly === '') {
        $pathOnly = '/';
    }
    if (str_starts_with($pathOnly, '/api/')) {
        return false;
    }
    if (str_starts_with($pathOnly, '/.well-known/')) {
        return false;
    }
    $base = basename($pathOnly);
    if (in_array($base, ['photo.php', 'robots.txt', 'favicon.ico'], true)) {
        return false;
    }
    if (preg_match('/\.(css|js|mjs|map|jpg|jpeg|png|gif|webp|ico|svg|woff2?|ttf|eot|otf|pdf|zip|json|xml|txt|mp4|webm|mp3)$/i', $pathOnly)) {
        return false;
    }
    return true;
}

function hon_stats_parse_line(string $line): ?array {
    $line = rtrim($line, "\r\n");
    if ($line === '') {
        return null;
    }
    $qpos = strrpos($line, '" ');
    if ($qpos === false) {
        return null;
    }
    $after = substr($line, $qpos + 2);
    $hostTok = explode(' ', trim($after), 2)[0] ?? '';
    $main = substr($line, 0, $qpos + 1);
    if (!preg_match('/^(\S+) \S+ \S+ \[([^\]]+)\] "([^"]*)" (\d{3}) (\S+) "([^"]*)" "(.*)"$/', $main, $m)) {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('d/M/Y:H:i:s O', $m[2]);
    if (!$dt) {
        return null;
    }
    $req = $m[3];
    $parts = preg_split('/\s+/', $req) ?: [];
    $method = strtoupper($parts[0] ?? '');
    $path = $parts[1] ?? '/';
    return [
        'ip' => $m[1],
        'dt' => $dt,
        'method' => $method,
        'path' => $path,
        'status' => (int) $m[4],
        'ua' => $m[7],
        'host' => $hostTok,
    ];
}

function hon_stats_parse_all_logs(): array {
    $seen = [];
    $hits = [];
    foreach (hon_stats_archive_files() as $path) {
        $open = str_ends_with(strtolower($path), '.gz') ? 'gzopen' : 'fopen';
        $read = $open === 'gzopen' ? 'gzgets' : 'fgets';
        $close = $open === 'gzopen' ? 'gzclose' : 'fclose';
        $fh = @$open($path, 'r');
        if (!$fh) {
            continue;
        }
        while (($line = $read($fh)) !== false) {
            $key = md5($line);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $row = hon_stats_parse_line($line);
            if ($row === null) {
                continue;
            }
            if (!hon_stats_is_site_host($row['host'])) {
                continue;
            }
            if (hon_stats_is_bot($row['ua'])) {
                continue;
            }
            $hits[] = $row;
        }
        $close($fh);
    }
    usort($hits, static fn ($a, $b) => $a['dt'] <=> $b['dt']);
    return hon_stats_bucket_hits($hits);
}

function hon_stats_bucket_hits(array $hits): array {
    $et = new DateTimeZone(HON_STATS_TZ);
    $byDay = [];
    $sessions = [];
    foreach ($hits as $hit) {
        $local = $hit['dt']->setTimezone($et);
        $day = $local->format('Y-m-d');
        $ts = $hit['dt']->getTimestamp();
        $isPage = hon_stats_is_page($hit['path'], $hit['method'], $hit['status']);
        if (!isset($byDay[$day])) {
            $byDay[$day] = [
                'date' => $day,
                'visits' => 0,
                'unique_visitors' => 0,
                'pageviews' => 0,
                'ips' => [],
                'first_ts' => $ts,
                'last_ts' => $ts,
                'source' => 'raw_logs',
            ];
        }
        $byDay[$day]['first_ts'] = min($byDay[$day]['first_ts'], $ts);
        $byDay[$day]['last_ts'] = max($byDay[$day]['last_ts'], $ts);
        if ($isPage) {
            $byDay[$day]['pageviews']++;
            $sid = $hit['ip'] . "\n" . $hit['ua'];
            $key = $day . "\n" . $sid;
            if (!isset($sessions[$key]) || ($ts - $sessions[$key]) > HON_SESSION_TIMEOUT) {
                $byDay[$day]['visits']++;
                $byDay[$day]['ips'][$hit['ip']] = true;
            }
            $sessions[$key] = $ts;
        }
    }
    foreach ($byDay as $day => &$row) {
        $row['unique_visitors'] = count($row['ips']);
        unset($row['ips']);
        $start = (new DateTimeImmutable($day . ' 00:00:00', $et))->getTimestamp();
        $end = (new DateTimeImmutable($day . ' 23:59:59', $et))->getTimestamp();
        $row['coverage'] = ($row['first_ts'] <= $start + 600 && $row['last_ts'] >= $end - 600)
            ? 'full_day'
            : 'partial_logs';
    }
    unset($row);
    return $byDay;
}

function hon_stats_awstats_ssl(): array {
    $out = [
        'days' => [],
        'total_visits' => null,
        'total_unique' => null,
        'last_update' => null,
        'file' => null,
    ];
    $dir = HON_AWSTATS_SSL_DIR;
    if (!is_dir($dir) || !is_readable($dir)) {
        return $out;
    }
    $files = [];
    foreach (scandir($dir) ?: [] as $name) {
        if (preg_match('/^awstats\d{6}\.' . preg_quote(HON_AWSTATS_DOMAIN, '/') . '\.txt$/', $name)) {
            $files[] = $dir . '/' . $name;
        }
    }
    if (!$files) {
        return $out;
    }
    rsort($files);
    $out['file'] = basename((string) $files[0]);
    foreach ($files as $path) {
        $txt = @file_get_contents($path);
        if ($txt === false) {
            continue;
        }
        if (preg_match('/^TotalVisits\s+(\d+)/m', $txt, $m)) {
            $out['total_visits'] = (int) $m[1];
        }
        if (preg_match('/^TotalUnique\s+(\d+)/m', $txt, $m)) {
            $out['total_unique'] = (int) $m[1];
        }
        if (preg_match('/^LastUpdate\s+(\d{14})/m', $txt, $m)) {
            $out['last_update'] = $m[1];
        }
        if (preg_match('/BEGIN_DAY \d+\n(.*?)END_DAY/s', $txt, $m)) {
            foreach (preg_split('/\n/', trim($m[1])) as $line) {
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) >= 5 && preg_match('/^\d{8}$/', $parts[0])) {
                    $d = substr($parts[0], 0, 4) . '-' . substr($parts[0], 4, 2) . '-' . substr($parts[0], 6, 2);
                    $out['days'][$d] = [
                        'date' => $d,
                        'pages' => (int) $parts[1],
                        'hits' => (int) $parts[2],
                        'visits' => (int) $parts[4],
                        'tz' => '-0700',
                    ];
                }
            }
        }
    }
    return $out;
}

function hon_stats_load_history(): array {
    $path = HON_STATS_HISTORY;
    if (!is_readable($path)) {
        return ['days' => []];
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data) || !isset($data['days']) || !is_array($data['days'])) {
        return ['days' => []];
    }
    return $data;
}

function hon_stats_merge_history(array $history, array $parsed, string $today): array {
    if (!isset($history['days']) || !is_array($history['days'])) {
        $history['days'] = [];
    }
    foreach ($parsed as $day => $row) {
        $existing = $history['days'][$day] ?? null;
        // Prefer raw logs when we have them. Do not shrink a completed day's
        // stored totals if today's partial snapshot would overwrite a fuller one
        // after log rotation — keep the max visits/pageviews.
        if (is_array($existing) && ($existing['source'] ?? '') === 'raw_logs' && $day !== $today) {
            $row['visits'] = max((int) $row['visits'], (int) ($existing['visits'] ?? 0));
            $row['unique_visitors'] = max((int) $row['unique_visitors'], (int) ($existing['unique_visitors'] ?? 0));
            $row['pageviews'] = max((int) $row['pageviews'], (int) ($existing['pageviews'] ?? 0));
            if (($existing['coverage'] ?? '') === 'full_day') {
                $row['coverage'] = 'full_day';
            }
        }
        $history['days'][$day] = [
            'date' => $day,
            'visits' => (int) $row['visits'],
            'unique_visitors' => (int) $row['unique_visitors'],
            'pageviews' => (int) $row['pageviews'],
            'coverage' => $row['coverage'] ?? 'partial_logs',
            'source' => 'raw_logs',
        ];
    }
    $history['updated_at'] = (new DateTimeImmutable('now', new DateTimeZone(HON_STATS_TZ)))->format('c');
    return $history;
}

function hon_stats_save_history(array $history): void {
    $path = HON_STATS_HISTORY;
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    @file_put_contents($path, json_encode($history, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    @chmod($path, 0600);
}

function hon_stats_day_row(string $day, array $parsed, array $history, array $awstats, bool $partialToday): array {
    if (isset($parsed[$day])) {
        $row = $parsed[$day];
        return [
            'date' => $day,
            'visits' => (int) $row['visits'],
            'unique_visitors' => (int) $row['unique_visitors'],
            'pageviews' => (int) $row['pageviews'],
            'coverage' => $partialToday ? 'today_partial' : ($row['coverage'] ?? 'partial_logs'),
            'source' => 'raw_logs',
        ];
    }
    $hist = $history['days'][$day] ?? null;
    if (is_array($hist) && isset($hist['visits'])) {
        return [
            'date' => $day,
            'visits' => (int) $hist['visits'],
            'unique_visitors' => (int) ($hist['unique_visitors'] ?? 0),
            'pageviews' => (int) ($hist['pageviews'] ?? 0),
            'coverage' => $hist['coverage'] ?? 'history',
            'source' => 'history',
        ];
    }
    $aw = $awstats['days'][$day] ?? null;
    if (is_array($aw)) {
        return [
            'date' => $day,
            'visits' => (int) $aw['visits'],
            'unique_visitors' => null,
            'pageviews' => (int) $aw['pages'],
            'coverage' => 'awstats_ssl_fallback',
            'source' => 'awstats_ssl',
            'note' => 'AWStats SSL day in -0700, includes all vhost Hosts; not filtered the same way as raw site traffic.',
        ];
    }
    return [
        'date' => $day,
        'visits' => 0,
        'unique_visitors' => 0,
        'pageviews' => 0,
        'coverage' => $partialToday ? 'today_partial' : 'no_data',
        'source' => 'none',
    ];
}
