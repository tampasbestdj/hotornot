<?php
declare(strict_types=1);

const HON_MAX_PHOTOS = 10;
const HON_RATINGS = ['sfw', 'suggestive', 'adult'];
const HON_GENDERS = ['m', 'f', 'couple', '?'];
const HON_GENDER_FILTERS = ['m', 'f', 'couple', 'both'];

function hon_normalize_rating(?string $rating): string {
    $rating = strtolower(trim((string) $rating));
    return in_array($rating, HON_RATINGS, true) ? $rating : 'sfw';
}

function hon_normalize_gender(?string $gender): string {
    $gender = strtolower(trim((string) $gender));
    if ($gender === 'man' || $gender === 'male') {
        return 'm';
    }
    if ($gender === 'woman' || $gender === 'female') {
        return 'f';
    }
    return in_array($gender, HON_GENDERS, true) ? $gender : '?';
}

function hon_require_declared_gender(?string $gender): string {
    $normalized = hon_normalize_gender($gender);
    if (!in_array($normalized, ['m', 'f', 'couple'], true)) {
        hon_error('Please say whether this photo is of a man, a woman, or a couple.');
    }
    return $normalized;
}

function hon_flag_on(mixed $value): bool {
    return $value === true || $value === 1 || in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
}

function hon_parse_see_flags(array $input, ?array $fallback = null): array {
    $hasSeeList = isset($input['see']) && is_array($input['see']);
    $hasKeys = $hasSeeList
        || array_key_exists('see_men', $input)
        || array_key_exists('see_women', $input)
        || array_key_exists('see_couples', $input);
    if (!$hasKeys) {
        if ($fallback !== null) {
            return $fallback;
        }
        return ['see_men' => 0, 'see_women' => 0, 'see_couples' => 0];
    }
    if ($hasSeeList) {
        $see = array_map(static fn($v) => strtolower(trim((string) $v)), $input['see']);
        return [
            'see_men' => in_array('m', $see, true) || in_array('men', $see, true) ? 1 : 0,
            'see_women' => in_array('f', $see, true) || in_array('women', $see, true) ? 1 : 0,
            'see_couples' => in_array('couple', $see, true) || in_array('couples', $see, true) ? 1 : 0,
        ];
    }
    return [
        'see_men' => hon_flag_on($input['see_men'] ?? 0) ? 1 : 0,
        'see_women' => hon_flag_on($input['see_women'] ?? 0) ? 1 : 0,
        'see_couples' => hon_flag_on($input['see_couples'] ?? 0) ? 1 : 0,
    ];
}

function hon_see_selected(array $flags): bool {
    return ((int) ($flags['see_men'] ?? 0) === 1)
        || ((int) ($flags['see_women'] ?? 0) === 1)
        || ((int) ($flags['see_couples'] ?? 0) === 1);
}

function hon_needs_see_prefs(array $user): bool {
    return !hon_see_selected($user);
}

function hon_derived_filter_gender(array $flags): string {
    $men = (int) ($flags['see_men'] ?? 0) === 1;
    $women = (int) ($flags['see_women'] ?? 0) === 1;
    $couples = (int) ($flags['see_couples'] ?? 0) === 1;
    $count = (int) $men + (int) $women + (int) $couples;
    if ($count === 1 && $men) return 'm';
    if ($count === 1 && $women) return 'f';
    if ($count === 1 && $couples) return 'couple';
    if ($count >= 2) return 'both';
    return '';
}

function hon_photo_is_couple_entry(array $photo): bool {
    return (int) ($photo['is_couple'] ?? 0) === 1 || ($photo['gender'] ?? '') === 'couple';
}

function hon_photo_allowed_for_user(array $photo, array $user): bool {
    if (hon_needs_see_prefs($user)) {
        return false;
    }
    if (hon_photo_is_couple_entry($photo)) {
        return (int) ($user['see_couples'] ?? 0) === 1;
    }
    $gender = $photo['gender'] ?? '';
    if ($gender === 'm') {
        return (int) ($user['see_men'] ?? 0) === 1;
    }
    if ($gender === 'f') {
        return (int) ($user['see_women'] ?? 0) === 1;
    }
    return false;
}

function hon_normalize_ratings_list(string $raw, bool $adultConfirmed): array {
    $items = array_values(array_unique(array_filter(array_map('trim', explode(',', strtolower($raw))))));
    $items = array_values(array_intersect($items, HON_RATINGS));
    if (!$adultConfirmed) {
        $items = array_values(array_diff($items, ['adult']));
    }
    return $items ?: ['sfw'];
}

function hon_photo_url(int $id): string {
    return 'photo.php?id=' . $id;
}

function hon_hot_percent(int $hots, int $votes): int {
    if ($votes <= 0) {
        return 0;
    }
    return (int) round(($hots / $votes) * 100);
}

function hon_store_upload(array $file): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        hon_error('Please choose a photo.');
    }
    if (($file['size'] ?? 0) > 8 * 1024 * 1024) {
        hon_error('That photo is too large.');
    }
    $tmp = $file['tmp_name'] ?? '';
    $info = @getimagesize($tmp);
    if ($info === false) {
        hon_error('That file is not a valid image.');
    }
    $mime = $info['mime'] ?? '';
    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($tmp),
        'image/png' => @imagecreatefrompng($tmp),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
        'image/gif' => @imagecreatefromgif($tmp),
        default => false,
    };
    if (!$src) {
        hon_error('Could not process that photo. Try a JPEG or PNG.');
    }
    $width = imagesx($src);
    $height = imagesy($src);
    $max = 800;
    if ($width > $max) {
        $newW = $max;
        $newH = max(1, (int) round($height * ($max / $width)));
        $dst = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);
        imagedestroy($src);
        $src = $dst;
    }
    $name = bin2hex(random_bytes(16)) . '.jpg';
    $dest = hon_uploads_dir() . '/' . $name;
    imagejpeg($src, $dest, 85);
    imagedestroy($src);
    chmod($dest, 0600);
    return $name;
}

function hon_resolve_photo_file(string $filePath): ?string {
    $filePath = str_replace('\\', '/', $filePath);
    if (str_contains($filePath, '..')) {
        return null;
    }
    $base = basename($filePath);
    $upload = hon_uploads_dir() . '/' . $base;
    if (is_file($upload) && is_readable($upload)) {
        return $upload;
    }
    if (str_starts_with($filePath, 'images/')) {
        $seed = rtrim(hon_seeds_dir(), '/') . '/' . $base;
        if (is_file($seed) && is_readable($seed)) {
            return $seed;
        }
    }
    return null;
}

function hon_user_photo_count(PDO $pdo, int $userId): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM photos WHERE user_id = ?');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

function hon_add_photo(PDO $pdo, int $userId, array $file, string $rating, bool $isCouple): array {
    if (hon_user_photo_count($pdo, $userId) >= HON_MAX_PHOTOS) {
        hon_error('You already have 10 photos. Delete one to add another.');
    }
    $path = hon_store_upload($file);
    $primary = hon_user_photo_count($pdo, $userId) === 0 ? 1 : 0;
    $stmt = $pdo->prepare('INSERT INTO photos (user_id, file_path, tr_rating, is_couple, is_primary) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$userId, $path, hon_normalize_rating($rating), $isCouple ? 1 : 0, $primary]);
    $id = (int) $pdo->lastInsertId();
    return hon_get_photo($pdo, $id);
}

function hon_get_photo(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare('SELECT p.*, u.name, u.gender, u.age
        FROM photos p JOIN users u ON u.id = p.user_id WHERE p.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function hon_photo_payload(array $row): array {
    return [
        'id' => (int) $row['id'],
        'user_id' => (int) $row['user_id'],
        'name' => $row['name'],
        'gender' => $row['gender'],
        'age' => isset($row['age']) && $row['age'] !== null ? (int) $row['age'] : null,
        'tr_rating' => $row['tr_rating'],
        'is_couple' => (int) $row['is_couple'] === 1 || $row['gender'] === 'couple',
        'is_primary' => (int) $row['is_primary'] === 1,
        'hots' => (int) $row['hots'],
        'votes' => (int) $row['votes'],
        'hot_percent' => hon_hot_percent((int) $row['hots'], (int) $row['votes']),
        'url' => hon_photo_url((int) $row['id']),
    ];
}

function hon_get_pool(PDO $pdo, array $user): ?array {
    if (hon_needs_see_prefs($user)) {
        return null;
    }
    $ratings = hon_normalize_ratings_list((string) ($user['filter_ratings'] ?? 'sfw'), (int) ($user['adult_confirmed'] ?? 0) === 1);
    $placeholders = implode(',', array_fill(0, count($ratings), '?'));

    $sql = "SELECT p.*, u.name, u.gender, u.age
        FROM photos p
        JOIN users u ON u.id = p.user_id
        WHERE p.user_id != ?
          AND p.tr_rating IN ($placeholders)
          AND p.id NOT IN (SELECT r.photo_id FROM ratings r WHERE r.rater_id = ?)";
    $params = array_merge([(int) $user['id']], $ratings, [(int) $user['id']]);

    $clauses = [];
    if ((int) ($user['see_men'] ?? 0) === 1) {
        $clauses[] = "(p.is_couple = 0 AND u.gender = 'm')";
    }
    if ((int) ($user['see_women'] ?? 0) === 1) {
        $clauses[] = "(p.is_couple = 0 AND u.gender = 'f')";
    }
    if ((int) ($user['see_couples'] ?? 0) === 1) {
        $clauses[] = "(p.is_couple = 1 OR u.gender = 'couple')";
    }
    if (!$clauses) {
        return null;
    }
    $sql .= ' AND (' . implode(' OR ', $clauses) . ')';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return null;
    }

    $lastUserId = $_SESSION['last_pool_user_id'] ?? null;
    $choices = $rows;
    if ($lastUserId) {
        $others = array_values(array_filter($rows, fn($row) => (int) $row['user_id'] !== (int) $lastUserId));
        if ($others) {
            $choices = $others;
        }
    }
    $pick = $choices[random_int(0, count($choices) - 1)];
    $_SESSION['last_pool_user_id'] = (int) $pick['user_id'];
    return hon_photo_payload($pick);
}

function hon_rate_photo(PDO $pdo, int $raterId, int $photoId, bool $isHot): array {
    $photo = hon_get_photo($pdo, $photoId);
    if (!$photo) {
        hon_error('Photo not found.', 404);
    }
    if ((int) $photo['user_id'] === $raterId) {
        hon_error('You cannot rate your own photo.');
    }
    $rater = hon_user_by_id($pdo, $raterId);
    if (!$rater || hon_needs_see_prefs($rater)) {
        hon_error('Please choose who you want to rate first.');
    }
    if (!hon_photo_allowed_for_user($photo, $rater)) {
        hon_error('That photo is outside your rating preferences.');
    }
    try {
        $pdo->beginTransaction();
        $insert = $pdo->prepare('INSERT INTO ratings (rater_id, photo_id, is_hot) VALUES (?, ?, ?)');
        $insert->execute([$raterId, $photoId, $isHot ? 1 : 0]);
        if ($isHot) {
            $pdo->prepare('UPDATE photos SET votes = votes + 1, hots = hots + 1 WHERE id = ?')->execute([$photoId]);
        } else {
            $pdo->prepare('UPDATE photos SET votes = votes + 1 WHERE id = ?')->execute([$photoId]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (str_contains($e->getMessage(), 'UNIQUE') || (int) $e->getCode() === 23000) {
            hon_error('You already rated that photo.');
        }
        throw $e;
    }
    return hon_photo_payload(hon_get_photo($pdo, $photoId));
}

function hon_my_photos(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare('SELECT p.*, u.name, u.gender, u.age FROM photos p JOIN users u ON u.id = p.user_id WHERE p.user_id = ?');
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();
    $payloads = array_map('hon_photo_payload', $rows);
    usort($payloads, function ($a, $b) {
        if ($a['hot_percent'] === $b['hot_percent']) {
            if ($a['votes'] === $b['votes']) {
                return $b['id'] <=> $a['id'];
            }
            return $b['votes'] <=> $a['votes'];
        }
        return $b['hot_percent'] <=> $a['hot_percent'];
    });
    $bestId = null;
    $bestScore = -1;
    $bestVotes = -1;
    foreach ($payloads as $photo) {
        if ($photo['votes'] >= 10 && (
            $photo['hot_percent'] > $bestScore ||
            ($photo['hot_percent'] === $bestScore && $photo['votes'] > $bestVotes)
        )) {
            $bestScore = $photo['hot_percent'];
            $bestVotes = $photo['votes'];
            $bestId = $photo['id'];
        }
    }
    foreach ($payloads as &$photo) {
        $photo['is_best'] = $bestId !== null && $photo['id'] === $bestId;
    }
    unset($photo);
    $hots = array_sum(array_column($payloads, 'hots'));
    $votes = array_sum(array_column($payloads, 'votes'));
    return [
        'photos' => $payloads,
        'hots' => $hots,
        'votes' => $votes,
        'score_percent' => $votes ? hon_hot_percent($hots, $votes) : null,
    ];
}

function hon_delete_photo(PDO $pdo, int $userId, int $photoId): void {
    $stmt = $pdo->prepare('SELECT * FROM photos WHERE id = ? AND user_id = ?');
    $stmt->execute([$photoId, $userId]);
    $photo = $stmt->fetch();
    if (!$photo) {
        hon_error('Photo not found.', 404);
    }
    if (hon_user_photo_count($pdo, $userId) <= 1) {
        hon_error('Keep at least one photo on your account.');
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM ratings WHERE photo_id = ?')->execute([$photoId]);
        $pdo->prepare('DELETE FROM photos WHERE id = ? AND user_id = ?')->execute([$photoId, $userId]);
        if ((int) $photo['is_primary'] === 1) {
            $next = $pdo->prepare('SELECT id FROM photos WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 1');
            $next->execute([$userId]);
            $nextId = $next->fetchColumn();
            if ($nextId) {
                $pdo->prepare('UPDATE photos SET is_primary = 1 WHERE id = ?')->execute([$nextId]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $full = hon_resolve_photo_file($photo['file_path']);
    $upload = hon_uploads_dir() . '/' . basename($photo['file_path']);
    if ($full && $full === $upload && is_file($upload)) {
        @unlink($upload);
    }
}

function hon_set_primary(PDO $pdo, int $userId, int $photoId): void {
    $stmt = $pdo->prepare('SELECT id FROM photos WHERE id = ? AND user_id = ?');
    $stmt->execute([$photoId, $userId]);
    if (!$stmt->fetchColumn()) {
        hon_error('Photo not found.', 404);
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE photos SET is_primary = 0 WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('UPDATE photos SET is_primary = 1 WHERE id = ? AND user_id = ?')->execute([$photoId, $userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function hon_leaderboard(PDO $pdo): array {
    $sql = "SELECT u.id, u.name, u.gender,
        COALESCE(SUM(p.hots), 0) AS hots,
        COALESCE(SUM(p.votes), 0) AS votes
        FROM users u
        JOIN photos p ON p.user_id = u.id
        GROUP BY u.id, u.name, u.gender
        HAVING votes > 0
        ORDER BY (SUM(p.hots) * 1.0 / SUM(p.votes)) DESC, votes DESC
        LIMIT 10";
    $rows = $pdo->query($sql)->fetchAll();
    return array_map(function ($row) {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'gender' => $row['gender'],
            'is_couple' => $row['gender'] === 'couple',
            'hots' => (int) $row['hots'],
            'votes' => (int) $row['votes'],
            'hot_percent' => hon_hot_percent((int) $row['hots'], (int) $row['votes']),
        ];
    }, $rows);
}

function hon_delete_account(PDO $pdo, int $userId, string $password): void {
    $stmt = $pdo->prepare('SELECT password_hash, is_seed FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user || (int) $user['is_seed'] === 1 || !password_verify($password, $user['password_hash'])) {
        hon_error('Could not delete that account.');
    }
    $photos = $pdo->prepare('SELECT id, file_path FROM photos WHERE user_id = ?');
    $photos->execute([$userId]);
    $rows = $photos->fetchAll() ?: [];
    $paths = array_column($rows, 'file_path');
    $photoIds = array_map('intval', array_column($rows, 'id'));
    $pdo->beginTransaction();
    try {
        if ($photoIds) {
            $in = implode(',', array_fill(0, count($photoIds), '?'));
            $pdo->prepare("DELETE FROM ratings WHERE rater_id = ? OR photo_id IN ($in)")->execute(array_merge([$userId], $photoIds));
        } else {
            $pdo->prepare('DELETE FROM ratings WHERE rater_id = ?')->execute([$userId]);
        }
        $pdo->prepare('DELETE FROM photos WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    foreach ($paths as $path) {
        $full = hon_uploads_dir() . '/' . basename((string) $path);
        if (is_file($full)) {
            @unlink($full);
        }
    }
}
