<?php
declare(strict_types=1);

function hon_is_sqlite(PDO $pdo): bool {
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
}

function hon_table_exists(PDO $pdo, string $table): bool {
    if (hon_is_sqlite($pdo)) {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = ?");
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function hon_column_exists(PDO $pdo, string $table, string $column): bool {
    if (hon_is_sqlite($pdo)) {
        $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
        foreach ($stmt as $row) {
            if (($row['name'] ?? '') === $column) {
                return true;
            }
        }
        return false;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}

function hon_migrate(PDO $pdo): void {
    $sqlite = hon_is_sqlite($pdo);
    $id = $sqlite
        ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
        : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
    $ts = $sqlite ? 'TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP' : 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP';
    $bool = $sqlite ? 'INTEGER NOT NULL DEFAULT 0' : 'TINYINT(1) NOT NULL DEFAULT 0';

    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(64) PRIMARY KEY,
        applied_at $ts
    )");

    $done = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $mark = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)');

    if (!in_array('001_init_users', $done, true)) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id $id,
            name VARCHAR(80) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            gender VARCHAR(16) NOT NULL DEFAULT '?',
            age TINYINT NULL,
            filter_gender VARCHAR(16) NOT NULL DEFAULT 'both',
            filter_ratings VARCHAR(64) NOT NULL DEFAULT 'sfw',
            adult_confirmed $bool,
            is_seed $bool,
            created_at $ts
        )");
        $mark->execute(['001_init_users']);
        $done[] = '001_init_users';
    }

    if (!in_array('002_photos_and_ratings', $done, true)) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS photos (
            id $id,
            user_id INT NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            tr_rating VARCHAR(16) NOT NULL DEFAULT 'sfw',
            is_couple $bool,
            hots INT NOT NULL DEFAULT 0,
            votes INT NOT NULL DEFAULT 0,
            is_primary $bool,
            created_at $ts
        )");
        hon_create_index($pdo, 'idx_photos_user', 'photos', 'user_id');
        hon_create_index($pdo, 'idx_photos_rating', 'photos', 'tr_rating');
        $pdo->exec("CREATE TABLE IF NOT EXISTS ratings (
            id $id,
            rater_id INT NOT NULL,
            photo_id INT NOT NULL,
            is_hot $bool,
            created_at $ts,
            UNIQUE (rater_id, photo_id)
        )");
        hon_create_index($pdo, 'idx_ratings_photo', 'ratings', 'photo_id');
        $mark->execute(['002_photos_and_ratings']);
        $done[] = '002_photos_and_ratings';
    }

    if (!in_array('003_legacy_single_photo', $done, true)) {
        hon_migrate_legacy_single_photo($pdo);
        $mark->execute(['003_legacy_single_photo']);
        $done[] = '003_legacy_single_photo';
    }

    if (!in_array('004_seed_users', $done, true)) {
        require_once HON_ROOT . '/seeds.php';
        hon_seed_users($pdo);
        $mark->execute(['004_seed_users']);
    }
}


function hon_create_index(PDO $pdo, string $name, string $table, string $column): void {
    try {
        $quoted = hon_is_sqlite($pdo) ? "CREATE INDEX IF NOT EXISTS $name ON $table ($column)" : "CREATE INDEX $name ON $table ($column)";
        $pdo->exec($quoted);
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'Duplicate') === false && stripos($msg, 'already exists') === false) {
            throw $e;
        }
    }
}

function hon_migrate_legacy_single_photo(PDO $pdo): void {
    if (!hon_table_exists($pdo, 'users') || !hon_table_exists($pdo, 'photos')) {
        return;
    }
    // If an older users table stored a single photo (and optional counters) on the user
    // row, turn that photo into photo #1 with is_primary=1.
    if (!hon_column_exists($pdo, 'users', 'photo') && !hon_column_exists($pdo, 'users', 'file_path')) {
        return;
    }
    $photoCol = hon_column_exists($pdo, 'users', 'photo') ? 'photo' : 'file_path';
    $hasRating = hon_column_exists($pdo, 'users', 'rating') || hon_column_exists($pdo, 'users', 'tr_rating');
    $ratingCol = hon_column_exists($pdo, 'users', 'tr_rating') ? 'tr_rating' : (hon_column_exists($pdo, 'users', 'rating') ? 'rating' : null);
    $hasCouple = hon_column_exists($pdo, 'users', 'is_couple');
    $hasHots = hon_column_exists($pdo, 'users', 'hots');
    $hasVotes = hon_column_exists($pdo, 'users', 'votes');

    $stmt = $pdo->query("SELECT * FROM users");
    $insert = $pdo->prepare('INSERT INTO photos (user_id, file_path, tr_rating, is_couple, hots, votes, is_primary, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?)');
    $exists = $pdo->prepare('SELECT 1 FROM photos WHERE user_id = ? LIMIT 1');
    foreach ($stmt as $user) {
        $exists->execute([$user['id']]);
        if ($exists->fetchColumn()) {
            continue;
        }
        $path = trim((string) ($user[$photoCol] ?? ''));
        if ($path === '') {
            continue;
        }
        $stored = hon_import_existing_path($path);
        $rating = $ratingCol ? strtolower((string) ($user[$ratingCol] ?? 'sfw')) : 'sfw';
        if (!in_array($rating, ['sfw', 'suggestive', 'adult'], true)) {
            $rating = 'sfw';
        }
        $isCouple = $hasCouple ? (int) (bool) $user['is_couple'] : (($user['gender'] ?? '') === 'couple' ? 1 : 0);
        $hots = $hasHots ? (int) $user['hots'] : 0;
        $votes = $hasVotes ? (int) $user['votes'] : 0;
        $insert->execute([
            $user['id'],
            $stored,
            $rating,
            $isCouple,
            $hots,
            $votes,
            $user['created_at'] ?? date('Y-m-d H:i:s'),
        ]);
    }
}

function hon_import_existing_path(string $path): string {
    $path = str_replace('\\', '/', $path);
    $base = basename($path);
    $candidates = [];
    if (str_starts_with($path, 'images/')) {
        $candidates[] = rtrim(hon_seeds_dir(), '/') . '/' . $base;
    }
    $candidates[] = $path;
    $candidates[] = rtrim(hon_seeds_dir(), '/') . '/' . $base;
    $targetDir = hon_uploads_dir();
    $destName = bin2hex(random_bytes(16)) . '.jpg';
    $dest = $targetDir . '/' . $destName;
    foreach ($candidates as $src) {
        if (is_readable($src) && is_file($src)) {
            if (!copy($src, $dest)) {
                throw new RuntimeException('Could not copy existing photo.');
            }
            return $destName;
        }
    }
    // Leave a pointer to the public seed path if the file is not moved yet.
    return str_starts_with($path, 'images/') ? $path : ('images/' . $base);
}
