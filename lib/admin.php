<?php
declare(strict_types=1);

const HON_ADMIN_DB_TZ = 'America/Boise';
const HON_ADMIN_DISPLAY_TZ = 'America/New_York';

function hon_admin_h(mixed $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Same exclusion rule as hon_stats_account_where() / stats.py. */
function hon_admin_real_account_where(string $alias = ''): string {
    $p = $alias === '' ? '' : $alias . '.';
    return "{$p}is_seed = 0
        AND {$p}name NOT LIKE 'testhon%'
        AND {$p}name NOT LIKE 'test\\_%'
        AND LOWER({$p}name) NOT IN ('test', 'tester', 'testing', 'demo', 'dummy')";
}

function hon_admin_format_et(?string $dbTs): string {
    if ($dbTs === null || trim($dbTs) === '') {
        return 'Unknown';
    }
    $boise = new DateTimeZone(HON_ADMIN_DB_TZ);
    $et = new DateTimeZone(HON_ADMIN_DISPLAY_TZ);
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dbTs, $boise);
    if (!$dt) {
        try {
            $dt = new DateTimeImmutable($dbTs, $boise);
        } catch (Exception $e) {
            return $dbTs;
        }
    }
    return $dt->setTimezone($et)->format('M j, Y, g:i:s A') . ' ET';
}

function hon_admin_gender_label(?string $gender): string {
    return match ($gender) {
        'm' => 'Man',
        'f' => 'Woman',
        'couple' => 'Couple',
        default => $gender ? (string) $gender : '—',
    };
}

function hon_admin_pref_labels(array $user): array {
    $labels = [];
    if ((int) ($user['see_men'] ?? 0) === 1) {
        $labels[] = 'Men';
    }
    if ((int) ($user['see_women'] ?? 0) === 1) {
        $labels[] = 'Women';
    }
    if ((int) ($user['see_couples'] ?? 0) === 1) {
        $labels[] = 'Couples';
    }
    if ((int) ($user['see_cougars'] ?? 0) === 1) {
        $labels[] = 'Cougars 40+';
    }
    if ((int) ($user['see_sugardaddy'] ?? 0) === 1) {
        $labels[] = 'Sugar Daddy 45+';
    }
    return $labels;
}

function hon_admin_account_kind(array $user): string {
    $name = strtolower((string) ($user['name'] ?? ''));
    if ((int) ($user['is_seed'] ?? 0) === 1) {
        return 'seed';
    }
    if (str_starts_with($name, 'testhon')
        || str_starts_with($name, 'test_')
        || in_array($name, ['test', 'tester', 'testing', 'demo', 'dummy'], true)
    ) {
        return 'test';
    }
    return 'real';
}

function hon_admin_dashboard(PDO $pdo, bool $includeSeeds): array {
    $realWhere = hon_admin_real_account_where();
    $realWhereU = hon_admin_real_account_where('u');

    $realSignups = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE $realWhere")->fetchColumn();
    $seedOrTest = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() - $realSignups;
    $photosAll = (int) $pdo->query('SELECT COUNT(*) FROM photos')->fetchColumn();
    $photosReal = (int) $pdo->query(
        "SELECT COUNT(*) FROM photos p INNER JOIN users u ON u.id = p.user_id WHERE $realWhereU"
    )->fetchColumn();
    $ratingsAll = (int) $pdo->query('SELECT COUNT(*) FROM ratings')->fetchColumn();
    $ratingsReal = (int) $pdo->query(
        "SELECT COUNT(*) FROM ratings r
         INNER JOIN photos p ON p.id = r.photo_id
         INNER JOIN users u ON u.id = p.user_id
         WHERE $realWhereU"
    )->fetchColumn();

    $listWhere = $includeSeeds ? '1=1' : $realWhere;
    $userSql = "SELECT u.id, u.name, u.gender, u.age, u.created_at, u.is_seed, u.is_admin,
            u.see_men, u.see_women, u.see_couples, u.see_cougars, u.see_sugardaddy,
            COALESCE((SELECT SUM(p.hots) FROM photos p WHERE p.user_id = u.id), 0) AS hots,
            COALESCE((SELECT SUM(p.votes) FROM photos p WHERE p.user_id = u.id), 0) AS votes,
            (SELECT COUNT(*) FROM photos p WHERE p.user_id = u.id) AS photo_count,
            (SELECT COUNT(*) FROM ratings r WHERE r.rater_id = u.id) AS ratings_cast
        FROM users u
        WHERE $listWhere
        ORDER BY u.is_seed ASC, u.created_at DESC, u.id DESC";
    $users = $pdo->query($userSql)->fetchAll() ?: [];

    $ids = array_map(static fn ($row) => (int) $row['id'], $users);
    $photosByUser = [];
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $photoStmt = $pdo->prepare(
            "SELECT id, user_id, is_primary FROM photos
             WHERE user_id IN ($placeholders)
             ORDER BY is_primary DESC, created_at ASC, id ASC"
        );
        $photoStmt->execute($ids);
        foreach ($photoStmt as $photo) {
            $uid = (int) $photo['user_id'];
            $photosByUser[$uid][] = [
                'id' => (int) $photo['id'],
                'url' => hon_photo_url((int) $photo['id']),
                'is_primary' => (int) $photo['is_primary'] === 1,
            ];
        }
    }

    $accounts = [];
    foreach ($users as $row) {
        $hots = (int) $row['hots'];
        $votes = (int) $row['votes'];
        $kind = hon_admin_account_kind($row);
        $accounts[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'gender' => (string) ($row['gender'] ?? ''),
            'gender_label' => hon_admin_gender_label($row['gender'] ?? null),
            'age' => isset($row['age']) && $row['age'] !== null ? (int) $row['age'] : null,
            'created_at' => (string) $row['created_at'],
            'created_at_et' => hon_admin_format_et((string) $row['created_at']),
            'is_admin' => (int) ($row['is_admin'] ?? 0) === 1,
            'kind' => $kind,
            'prefs' => hon_admin_pref_labels($row),
            'hots' => $hots,
            'votes' => $votes,
            'score' => hon_hot_percent($hots, $votes),
            'photo_count' => (int) $row['photo_count'],
            'ratings_cast' => (int) $row['ratings_cast'],
            'photos' => $photosByUser[(int) $row['id']] ?? [],
        ];
    }

    return [
        'real_signups' => $realSignups,
        'seed_or_test' => $seedOrTest,
        'photos_real' => $photosReal,
        'photos_all' => $photosAll,
        'ratings_real' => $ratingsReal,
        'ratings_all' => $ratingsAll,
        'include_seeds' => $includeSeeds,
        'accounts' => $accounts,
    ];
}
