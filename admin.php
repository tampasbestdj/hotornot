<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once HON_ROOT . '/admin.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    hon_admin_deny();
}

hon_require_admin();
$showSeeds = isset($_GET['seeds']) && (string) $_GET['seeds'] === '1';
$data = hon_admin_dashboard(hon_db(), $showSeeds);

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$h = 'hon_admin_h';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>Admin — Hot or Not</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    body.admin-body { align-items: flex-start; }
    .admin-wrap { width: 100%; max-width: 720px; }
    .admin-top { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
    .admin-top h1 { font-size: 1.45rem; }
    .admin-top a { color: #ff6b6b; font-weight: 700; text-decoration: none; }
    .admin-note { opacity: 0.75; font-size: 0.85rem; margin-bottom: 16px; }
    .admin-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin-bottom: 18px; }
    .admin-stat { background: rgba(255,255,255,0.07); border-radius: 14px; padding: 12px 14px; }
    .admin-stat .num { font-size: 1.6rem; font-weight: 800; color: #ffd700; line-height: 1.1; }
    .admin-stat .lbl { font-size: 0.78rem; opacity: 0.75; margin-top: 4px; }
    .admin-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin: 8px 0 14px; flex-wrap: wrap; }
    .admin-toolbar h2 { font-size: 1.05rem; }
    .admin-toggle { color: #fff; background: rgba(255,255,255,0.12); padding: 8px 12px; border-radius: 999px; text-decoration: none; font-size: 0.85rem; font-weight: 600; }
    .admin-account { background: rgba(255,255,255,0.07); border-radius: 16px; padding: 14px; margin-bottom: 12px; }
    .admin-account h3 { font-size: 1.05rem; word-break: break-word; }
    .admin-meta { opacity: 0.8; font-size: 0.85rem; margin-top: 4px; }
    .admin-badges { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
    .admin-badge { font-size: 0.7rem; font-weight: 700; letter-spacing: 0.02em; text-transform: uppercase; padding: 3px 8px; border-radius: 999px; background: rgba(255,255,255,0.12); }
    .admin-badge.admin { background: #ff4757; color: #fff; }
    .admin-badge.seed, .admin-badge.test { background: rgba(255,215,0,0.2); color: #ffd700; }
    .admin-thumbs { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
    .admin-thumbs a { display: block; width: 92px; height: 122px; border-radius: 10px; overflow: hidden; background: #111; }
    .admin-thumbs img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .admin-empty { opacity: 0.65; font-size: 0.9rem; margin-top: 10px; }
  </style>
</head>
<body class="admin-body">
  <div class="admin-wrap">
    <div class="admin-top">
      <h1>Admin</h1>
      <a href="/">Back to site</a>
    </div>
    <p class="admin-note">Private signup and photo overview. Rating only — this is not a dating or messaging inbox. Times are Eastern Time.</p>

    <div class="admin-stats">
      <div class="admin-stat">
        <div class="num"><?php echo (int) $data['real_signups']; ?></div>
        <div class="lbl">Real sign-ups</div>
      </div>
      <div class="admin-stat">
        <div class="num"><?php echo (int) $data['photos_real']; ?></div>
        <div class="lbl">Photos from real accounts (<?php echo (int) $data['photos_all']; ?> total)</div>
      </div>
      <div class="admin-stat">
        <div class="num"><?php echo (int) $data['ratings_all']; ?></div>
        <div class="lbl">Ratings total (<?php echo (int) $data['ratings_real']; ?> on real photos)</div>
      </div>
    </div>

    <div class="admin-toolbar">
      <h2><?php echo $showSeeds ? 'All accounts' : 'Real accounts'; ?></h2>
      <?php if ($showSeeds): ?>
        <a class="admin-toggle" href="admin.php">Hide seed accounts</a>
      <?php else: ?>
        <a class="admin-toggle" href="admin.php?seeds=1">Show seed accounts (<?php echo (int) $data['seed_or_test']; ?>)</a>
      <?php endif; ?>
    </div>

    <?php if (!$data['accounts']): ?>
      <p class="admin-empty">No accounts to show.</p>
    <?php endif; ?>

    <?php foreach ($data['accounts'] as $account): ?>
      <article class="admin-account">
        <h3><?php echo $h($account['name']); ?></h3>
        <div class="admin-badges">
          <?php if ($account['is_admin']): ?><span class="admin-badge admin">Admin</span><?php endif; ?>
          <?php if ($account['kind'] === 'seed'): ?><span class="admin-badge seed">Seed</span><?php endif; ?>
          <?php if ($account['kind'] === 'test'): ?><span class="admin-badge test">Test</span><?php endif; ?>
        </div>
        <p class="admin-meta">Joined <?php echo $h($account['created_at_et']); ?></p>
        <p class="admin-meta">
          <?php echo $h($account['gender_label']); ?>
          · age <?php echo $account['age'] === null ? 'not set' : (int) $account['age']; ?>
        </p>
        <p class="admin-meta">Rates: <?php echo $account['prefs'] ? $h(implode(', ', $account['prefs'])) : 'none set'; ?></p>
        <p class="admin-meta">
          Score <?php echo $account['votes'] > 0 ? ((int) $account['score'] . '%') : '—'; ?>
          · <?php echo (int) $account['votes']; ?> rating<?php echo (int) $account['votes'] === 1 ? '' : 's'; ?> received
          · <?php echo (int) $account['ratings_cast']; ?> given
        </p>
        <?php if ($account['photos']): ?>
          <div class="admin-thumbs">
            <?php foreach ($account['photos'] as $photo): ?>
              <a href="<?php echo $h($photo['url']); ?>" target="_blank" rel="noopener">
                <img src="<?php echo $h($photo['url']); ?>" alt="<?php echo $h($account['name'] . ' photo'); ?>">
              </a>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="admin-empty">No photos.</p>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
</body>
</html>
