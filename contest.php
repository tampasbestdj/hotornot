<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once HON_ROOT . '/contest.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Method not allowed';
    exit;
}

$loggedIn = hon_current_user_id() !== null;
$data = hon_contest_standings(hon_db());
$h = static function (mixed $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$sponsorMailto = 'mailto:tampasbestdj@gmail.com?subject=' . rawurlencode('Contest sponsorship on newhotornot.com');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo $h($data['month']); ?> Contest — Hot or Not</title>
  <meta name="description" content="Monthly photo rating contest on newhotornot.com. Live standings, official rules, and prizes announced soon.">
  <link rel="stylesheet" href="styles.css">
  <style>
    body.contest-body { align-items: flex-start; }
    .contest-wrap { width: 100%; max-width: 720px; }
    .contest-top { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; margin-bottom: 10px; flex-wrap: wrap; }
    .contest-top h1 { font-size: 1.45rem; }
    .contest-top a { color: #ff6b6b; font-weight: 700; text-decoration: none; }
    .contest-lede { opacity: 0.85; margin-bottom: 14px; }
    .contest-clock { background: rgba(255,255,255,0.07); border-radius: 16px; padding: 14px 16px; margin-bottom: 14px; }
    .contest-clock .count { font-size: 1.35rem; font-weight: 800; color: #ffd700; }
    .contest-clock .sub { font-size: 0.85rem; opacity: 0.75; margin-top: 4px; }
    .contest-prizes, .contest-sponsor { background: rgba(255,255,255,0.07); border-radius: 16px; padding: 14px 16px; margin-bottom: 14px; }
    .contest-sponsor a { color: #ffd700; font-weight: 700; }
    .contest-cat { background: rgba(255,255,255,0.07); border-radius: 16px; padding: 14px 16px; margin-bottom: 14px; }
    .contest-cat h2 { font-size: 1.15rem; margin-bottom: 4px; }
    .contest-cat .blurb { font-size: 0.85rem; opacity: 0.75; margin-bottom: 10px; }
    .contest-leader { margin-bottom: 10px; font-size: 0.95rem; }
    .contest-row { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-top: 1px solid rgba(255,255,255,0.08); }
    .contest-rank { width: 1.6rem; font-weight: 700; opacity: 0.7; }
    .contest-thumb { width: 48px; height: 64px; border-radius: 8px; overflow: hidden; background: #111; flex-shrink: 0; }
    .contest-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .contest-thumb.ph { display: flex; align-items: center; justify-content: center; font-size: 0.75rem; opacity: 0.6; }
    .contest-who { flex: 1; min-width: 0; }
    .contest-who .name { font-weight: 700; word-break: break-word; }
    .contest-who .meta { font-size: 0.8rem; opacity: 0.75; }
    .contest-pct { font-weight: 800; color: #ffd700; white-space: nowrap; }
    .need { color: #ffb347; font-size: 0.75rem; font-weight: 700; }
    .eligible { color: #9cffb0; font-size: 0.75rem; font-weight: 700; }
    .contest-rules { margin: 24px 0 40px; }
    .contest-rules h2 { font-size: 1.15rem; margin-bottom: 10px; }
    .contest-rules h3 { font-size: 0.95rem; margin: 14px 0 6px; }
    .contest-rules p, .contest-rules li { font-size: 0.9rem; opacity: 0.88; line-height: 1.45; }
    .contest-rules ul { padding-left: 1.2rem; }
    .contest-rules li { margin: 6px 0; }
  </style>
</head>
<body class="contest-body">
  <div class="contest-wrap">
    <div class="contest-top">
      <h1><?php echo $h($data['month']); ?> Contest</h1>
      <a href="/">Back to site</a>
    </div>
    <p class="contest-lede">A monthly photo rating contest on newhotornot.com. Rate photos, see the live standings, and watch the countdown to the end of the month. This is a rating contest only — not a dating, matching, or messaging service.</p>

    <div class="contest-clock">
      <div id="contest-countdown" class="count" data-end="<?php echo $h($data['end_iso']); ?>">Calculating…</div>
      <div class="sub">Until the month ends <?php echo $h($data['end_et']); ?>. Ratings received from <?php echo $h($data['start_et']); ?> through then count toward this month.</div>
    </div>

    <div class="contest-prizes">
      <strong>Prizes announced soon</strong>
      <p class="contest-lede" style="margin:6px 0 0">No prize amounts have been set for this month yet. Check this page for updates.</p>
    </div>

    <div class="contest-sponsor">
      <p>Presented by: <a href="<?php echo $h($sponsorMailto); ?>">your business here</a></p>
      <p class="contest-lede" style="margin:6px 0 0">Want to sponsor a month? Email tampasbestdj@gmail.com.</p>
    </div>

    <?php if (!$loggedIn): ?>
      <p class="contest-lede">Log in to see contest photo thumbnails. Scores and names below are public.</p>
    <?php endif; ?>

    <?php foreach ($data['categories'] as $cat): ?>
      <section class="contest-cat">
        <h2><?php echo $h($cat['label']); ?></h2>
        <p class="blurb"><?php echo $h($cat['blurb']); ?></p>
        <?php if ($cat['leader']): ?>
          <p class="contest-leader">Current leader: <strong><?php echo $h($cat['leader']['name']); ?></strong> at <?php echo (int) $cat['leader']['percent']; ?>% Hot, with <?php echo (int) $cat['leader']['votes']; ?> qualifying ratings this month.</p>
        <?php else: ?>
          <p class="contest-leader">No one has <?php echo (int) $data['min_votes']; ?> qualifying ratings in this category this month yet. The first photo to reach that mark with the highest % Hot leads.</p>
        <?php endif; ?>

        <?php if (!$cat['entries']): ?>
          <p class="blurb">No qualifying ratings on real accounts in this category so far this month.</p>
        <?php else: ?>
          <?php foreach ($cat['entries'] as $i => $entry): ?>
            <div class="contest-row">
              <div class="contest-rank"><?php echo (int) $i + 1; ?></div>
              <div class="contest-thumb<?php echo $loggedIn ? '' : ' ph'; ?>">
                <?php if ($loggedIn): ?>
                  <img src="<?php echo $h($entry['url']); ?>" alt="">
                <?php else: ?>
                  Photo
                <?php endif; ?>
              </div>
              <div class="contest-who">
                <div class="name"><?php echo $h($entry['name']); ?></div>
                <div class="meta"><?php echo (int) $entry['votes']; ?> rating<?php echo (int) $entry['votes'] === 1 ? '' : 's'; ?> this month
                  <?php if ($entry['eligible']): ?>
                    <span class="eligible">Eligible</span>
                  <?php else: ?>
                    <span class="need">Needs <?php echo (int) ($data['min_votes'] - $entry['votes']); ?> more</span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="contest-pct"><?php echo (int) $entry['percent']; ?>%</div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>

    <section class="contest-rules" id="rules">
      <h2>Official Rules</h2>
      <p>The newhotornot.com monthly contest is a skill- and vote-based photo rating contest. It is free to enter. No purchase is necessary.</p>

      <h3>Eligibility</h3>
      <p>Open only to legal residents of the United States who are 18 years of age or older. Void where prohibited.</p>

      <h3>How to enter</h3>
      <p>Create a free account on newhotornot.com, upload a photo of yourself (or of you as a couple), and receive ratings from other users during the contest month. Each photo is entered in every category it qualifies for that month. There is no entry fee.</p>

      <h3>Categories and judging</h3>
      <p>There are five categories: Men, Women, Couples, Cougars 40+, and Silver Foxes 45+. Cougars 40+ is for photos of women age 40 or older. Silver Foxes 45+ is for photos of men age 45 or older. Photos with no age listed do not qualify for those two themed categories. A photo may qualify in more than one category.</p>
      <p>In each category, the winner is the photo with the highest percentage of “Hot” ratings among qualifying ratings received during that calendar month, Eastern Time, with at least 50 qualifying ratings that month. If there is a tie, the photo with more qualifying ratings wins. If it is still tied, the earlier upload wins.</p>
      <p>Qualifying ratings exclude ratings from accounts that were less than one hour old at the time of the rating, ratings from seed or test accounts, and extra ratings of the same photo from the same internet connection (only the first such rating counts). The organizer may also disqualify ratings or accounts for vote manipulation.</p>

      <h3>Account limits</h3>
      <p>One account per person. Fake accounts, multiple accounts used by the same person, automated voting, and other vote manipulation are grounds for disqualification.</p>

      <h3>Winner verification</h3>
      <p>Potential winners must pass a selfie identity check showing that they are the person — or the couple — in the winning photo. A prize will not be issued until that check is complete.</p>

      <h3>Prizes and tax forms</h3>
      <p>Prizes announced soon. If a winner receives prizes valued at $600 or more in a calendar year, a completed IRS Form W-9 is required before the prize is issued.</p>

      <h3>Sponsor</h3>
      <p>The sponsor and organizer of this contest is newhotornot.com. The organizer may amend these rules for future months. Any amendment will be posted on this page and will not change the rules for a month that has already ended.</p>
    </section>
  </div>
  <script>
    (function () {
      const el = document.getElementById('contest-countdown');
      if (!el) return;
      const end = new Date(el.getAttribute('data-end'));
      function tick() {
        const ms = end.getTime() - Date.now();
        if (isNaN(end.getTime())) {
          el.textContent = 'Countdown unavailable';
          return;
        }
        if (ms <= 0) {
          el.textContent = 'This month has ended. A new contest month is starting.';
          return;
        }
        const total = Math.floor(ms / 1000);
        const d = Math.floor(total / 86400);
        const h = Math.floor((total % 86400) / 3600);
        const m = Math.floor((total % 3600) / 60);
        const s = total % 60;
        el.textContent = d + 'd ' + h + 'h ' + m + 'm ' + s + 's left';
      }
      tick();
      setInterval(tick, 1000);
    })();
  </script>
</body>
</html>
