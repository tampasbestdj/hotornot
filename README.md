# Hot or Not

A modern take on the classic Hot or Not site.

- Create an account and upload a real photo to enter
- Add up to 10 photos per person; each photo is rated on its own
- At signup, choose who you want to rate (Men, Women, and/or Couples; at least one required). That preference is saved on the account and enforced in the feed.
- Filter the pool further by maturity rating (SFW / Suggestive / Adult)
- Couple photos are rated as a unit and show a Couple badge
- See your score, a per-photo grid, and the leaderboard
- Seeded with 20 individual AI portraits plus 4 SFW couple portraits for cold start

## Run locally
The live site is a PHP app. For a quick UI preview you can still open `index.html`, but login, uploads, and voting need the API.

1. Copy `config.example.php` to a private `config.php` (outside the webroot in production).
2. Point `uploads_dir` at a writable folder.
3. Serve the repo (or the thin webroot PHP files) with PHP 8+ and MySQL or SQLite.
4. Seed portraits already on the server live in `images/`. The four couple seeds in this repo should be copied there too.

## Deploy
Static assets and the PHP endpoints go in the site webroot. `lib/` and `config.php` belong outside the webroot (`/home1/vhuwyfmy/private/newhotornot/` on Bluehost). Uploaded photos are stored outside the webroot and served through `photo.php`.

Migrations run automatically on the first request and turn any legacy single-photo user row into photo #1 (`is_primary=1`).
