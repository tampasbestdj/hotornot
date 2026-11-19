<?php
declare(strict_types=1);

const HON_CONTEST_TZ = 'America/New_York';
const HON_CONTEST_DB_TZ = 'America/Boise';
const HON_CONTEST_DEFAULT_MIN_VOTES = 50;
const HON_CONTEST_MIN_RATER_AGE = 'PT1H';
const HON_CONTEST_TOP = 15;
const HON_CONTEST_DEFAULT_TITLE = 'Hot or Not Championship';
const HON_CONTEST_DEFAULT_START_ET = '2026-11-17 00:00:00';
const HON_CONTEST_DEFAULT_END_ET = '2027-03-31 23:59:59';

function hon_contest_config(): array {
    static $cached;
    if (is_array($cached)) {
        return $cached;
    }
    $defaults = [
        'title' => HON_CONTEST_DEFAULT_TITLE,
        'start_et' => HON_CONTEST_DEFAULT_START_ET,
        'end_et' => HON_CONTEST_DEFAULT_END_ET,
        'min_votes' => HON_CONTEST_DEFAULT_MIN_VOTES,
    ];
    $candidates = [
        dirname(HON_ROOT) . '/contest_config.php',
        '/home1/vhuwyfmy/private/newhotornot/contest_config.php',
    ];
    $loaded = null;
    foreach ($candidates as $path) {
        if (is_readable($path)) {
            $loaded = require $path;
            break;
        }
    }
    if (!is_array($loaded)) {
        $cached = $defaults;
        return $cached;
    }
    $title = trim((string) ($loaded['title'] ?? $defaults['title']));
    $start = trim((string) ($loaded['start_et'] ?? $defaults['start_et']));
    $end = trim((string) ($loaded['end_et'] ?? $defaults['end_et']));
    $min = $loaded['min_votes'] ?? $defaults['min_votes'];
    $min = is_numeric($min) ? (int) $min : $defaults['min_votes'];
    if ($title === '') {
        $title = $defaults['title'];
    }
    if ($min < 1) {
        $min = $defaults['min_votes'];
    }
    $cached = [
        'title' => $title,
        'start_et' => $start !== '' ? $start : $defaults['start_et'],
        'end_et' => $end !== '' ? $end : $defaults['end_et'],
        'min_votes' => $min,
        'path' => $path ?? null,
    ];
    return $cached;
}

function hon_contest_parse_et(string $value, string $fallback): DateTimeImmutable {
    $et = new DateTimeZone(HON_CONTEST_TZ);
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $et);
    if ($dt instanceof DateTimeImmutable) {
        return $dt;
    }
    try {
        return new DateTimeImmutable($value, $et);
    } catch (Exception $e) {
        $fb = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $fallback, $et);
        if ($fb instanceof DateTimeImmutable) {
            return $fb;
        }
        return new DateTimeImmutable($fallback, $et);
    }
}

function hon_contest_categories(): array {
    return [
        'men' => [
            'label' => 'Men',
            'blurb' => 'Photos of men (not couple photos).',
        ],
        'women' => [
            'label' => 'Women',
            'blurb' => 'Photos of women (not couple photos).',
        ],
        'couples' => [
            'label' => 'Couples',
            'blurb' => 'Couple photos.',
        ],
        'cougars' => [
            'label' => 'Cougars 40+',
            'blurb' => 'Women 40 and older. Photos with no age listed do not qualify.',
        ],
        'silver_foxes' => [
            'label' => 'Silver Foxes 45+',
            'blurb' => 'Men 45 and older. Photos with no age listed do not qualify.',
        ],
    ];
}

function hon_contest_period(?DateTimeImmutable $nowEt = null): array {
    $et = new DateTimeZone(HON_CONTEST_TZ);
    $boise = new DateTimeZone(HON_CONTEST_DB_TZ);
    $cfg = hon_contest_config();
    $nowEt = $nowEt ? $nowEt->setTimezone($et) : new DateTimeImmutable('now', $et);
    $startEt = hon_contest_parse_et($cfg['start_et'], HON_CONTEST_DEFAULT_START_ET);
    $endInclusive = hon_contest_parse_et($cfg['end_et'], HON_CONTEST_DEFAULT_END_ET);
    if ($endInclusive < $startEt) {
        $endInclusive = hon_contest_parse_et(HON_CONTEST_DEFAULT_END_ET, HON_CONTEST_DEFAULT_END_ET);
    }
    $endExclusive = $endInclusive->modify('+1 second');
    return [
        'now_et' => $nowEt,
        'start_et' => $startEt,
        'end_et' => $endInclusive,
        'end_exclusive_et' => $endExclusive,
        'start_db' => $startEt->setTimezone($boise)->format('Y-m-d H:i:s'),
        'end_db' => $endExclusive->setTimezone($boise)->format('Y-m-d H:i:s'),
        'title' => $cfg['title'],
        'min_votes' => (int) $cfg['min_votes'],
        'end_iso' => $endExclusive->format(DateTimeInterface::ATOM),
        'config_path' => $cfg['path'] ?? null,
    ];
}

/** @deprecated Use hon_contest_period() */
function hon_contest_month(?DateTimeImmutable $nowEt = null): array {
    return hon_contest_period($nowEt);
}

function hon_contest_photo_categories(array $photo): array {
    $keys = [];
    $isCouple = hon_photo_is_couple_entry($photo);
    $gender = $photo['gender'] ?? '';
    if ($isCouple || $gender === 'couple') {
        $keys[] = 'couples';
        return $keys;
    }
    if ($gender === 'm') {
        $keys[] = 'men';
    }
    if ($gender === 'f') {
        $keys[] = 'women';
    }
    if (hon_photo_is_cougar($photo)) {
        $keys[] = 'cougars';
    }
    if (hon_photo_is_sugardaddy($photo)) {
        $keys[] = 'silver_foxes';
    }
    return $keys;
}

function hon_contest_qualifying_sql(PDO $pdo): string {
    $realRater = hon_admin_real_account_where('ru');
    $hourClause = function_exists('hon_is_sqlite') && hon_is_sqlite($pdo)
        ? "datetime(ru.created_at) <= datetime(r.created_at, '-1 hour')"
        : 'ru.created_at <= DATE_SUB(r.created_at, INTERVAL 1 HOUR)';
    return "SELECT r.id, r.photo_id, r.is_hot, r.created_at
        FROM ratings r
        INNER JOIN users ru ON ru.id = r.rater_id
        WHERE r.created_at >= ? AND r.created_at < ?
          AND $realRater
          AND $hourClause
          AND (
            r.ip_hash IS NULL
            OR r.id = (
                SELECT MIN(r2.id) FROM ratings r2
                WHERE r2.photo_id = r.photo_id
                  AND r2.ip_hash = r.ip_hash
                  AND r2.created_at >= ? AND r2.created_at < ?
            )
          )";
}

function hon_contest_standings(PDO $pdo, ?DateTimeImmutable $nowEt = null): array {
    require_once HON_ROOT . '/admin.php';
    $period = hon_contest_period($nowEt);
    $realOwner = hon_admin_real_account_where('u');
    $sql = 'SELECT p.id, p.user_id, p.is_couple, p.age, p.created_at,
                   u.name, u.gender,
                   SUM(q.is_hot) AS hots, COUNT(*) AS votes
            FROM (' . hon_contest_qualifying_sql($pdo) . ') q
            INNER JOIN photos p ON p.id = q.photo_id
            INNER JOIN users u ON u.id = p.user_id
            WHERE ' . $realOwner . '
            GROUP BY p.id, p.user_id, p.is_couple, p.age, p.created_at, u.name, u.gender';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$period['start_db'], $period['end_db'], $period['start_db'], $period['end_db']]);
    $rows = $stmt->fetchAll() ?: [];

    $byCat = [];
    foreach (array_keys(hon_contest_categories()) as $key) {
        $byCat[$key] = [];
    }
    foreach ($rows as $row) {
        $hots = (int) $row['hots'];
        $votes = (int) $row['votes'];
        $entry = [
            'photo_id' => (int) $row['id'],
            'url' => hon_photo_url((int) $row['id']),
            'name' => (string) $row['name'],
            'gender' => (string) ($row['gender'] ?? ''),
            'age' => isset($row['age']) && $row['age'] !== null ? (int) $row['age'] : null,
            'is_couple' => (int) ($row['is_couple'] ?? 0) === 1 || ($row['gender'] ?? '') === 'couple',
            'hots' => $hots,
            'votes' => $votes,
            'percent' => hon_hot_percent($hots, $votes),
            'eligible' => $votes >= $period['min_votes'],
            'created_at' => (string) $row['created_at'],
        ];
        foreach (hon_contest_photo_categories($row + ['age' => $row['age']]) as $key) {
            $byCat[$key][] = $entry;
        }
    }

    $categories = [];
    foreach (hon_contest_categories() as $key => $meta) {
        $list = $byCat[$key];
        usort($list, static function ($a, $b) {
            if ($a['eligible'] !== $b['eligible']) {
                return $a['eligible'] ? -1 : 1;
            }
            if ($a['percent'] !== $b['percent']) {
                return $b['percent'] <=> $a['percent'];
            }
            if ($a['votes'] !== $b['votes']) {
                return $b['votes'] <=> $a['votes'];
            }
            $cmp = strcmp($a['created_at'], $b['created_at']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return $a['photo_id'] <=> $b['photo_id'];
        });
        $leader = null;
        foreach ($list as $item) {
            if ($item['eligible']) {
                $leader = $item;
                break;
            }
        }
        $categories[$key] = [
            'key' => $key,
            'label' => $meta['label'],
            'blurb' => $meta['blurb'],
            'leader' => $leader,
            'entries' => array_slice($list, 0, HON_CONTEST_TOP),
            'total' => count($list),
        ];
    }

    return [
        'title' => $period['title'],
        'month' => $period['title'],
        'start_et' => $period['start_et']->format('M j, Y, g:i A') . ' ET',
        'end_et' => $period['end_et']->format('M j, Y, g:i A') . ' ET',
        'end_iso' => $period['end_iso'],
        'min_votes' => $period['min_votes'],
        'categories' => $categories,
    ];
}
