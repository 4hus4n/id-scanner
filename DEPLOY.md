# IdScanner — cPanel deployment

## What's in this folder
- `index.html`, `icons/`, `manifest.webmanifest` — the app
- `models/` — DocAligner AI corner model (4.8 MB)
- `vendor/ort/` — ONNX Runtime engine that runs the model in the browser (11 MB)
- `api/` — the learning server (PHP)
- `admin.php` — learning dashboard (stats, Train now, roll back)

## Install (about 5 minutes)
1. cPanel → **File Manager** → open `public_html` (or a subdomain's folder).
2. **Upload** `idscanner-cpanel.zip`, then right-click it → **Extract**. You get a folder `idscanner/`.
3. Open `https://YOUR-DOMAIN/idscanner/` — the app should load and the chip should say **AI detect ready**.
   Use **https**: phones only allow the live camera on secure pages (cPanel → SSL/TLS Status → run AutoSSL if needed).
4. Open `https://YOUR-DOMAIN/idscanner/admin.php` and create the admin password.
5. Optional, recommended: cPanel → **Cron Jobs** → Once Per Day →
   `php /home/YOUR-CPANEL-USER/public_html/idscanner/api/train.php`
   (The exact path is shown on the admin page. Training also runs by itself once a day when saves arrive, so this is a backup.)

## Requirements
- PHP 7.4 or newer with PDO SQLite (enabled on almost every cPanel host). Check in cPanel → Select PHP Version → Extensions → `pdo_sqlite`.
- No database setup: data is kept in `api/data/learn.sqlite`.
  To use MySQL instead, create a database + user in cPanel → MySQL Databases and fill in `api/inc/config.php` (`'driver' => 'mysql'`).
- `api/data/` must be writable by PHP (the default folder permission 755 works on cPanel).

## How the learning works
1. For each photo the app finds several possible outlines (from the AI model, edge-snapped, stretched to card shape) and measures 15 numbers for each: model confidence, edge strength, colour contrast, shape, size…
2. A weighted score picks the outline. The weights come from `api/weights.php`.
3. When someone **saves**, the app sends those measurements plus how well each outline matched the corners that were saved. If the user fixed the corners, that teaches the picker which outline was right.
4. Once a day the server learns new weights from the newest saves (needs at least 50 useful ones). New weights go live only if they:
   - pick the right outline at least as often on saves held back from training, and
   - still get the built-in test photos right (`api/inc/regression.json`, 15 real card photos).
   Each update can only move a weight by a limited amount, and every version is kept — roll back on the admin page with **Use this**.
5. Every app copy downloads the newest weights when it opens.

## Privacy
Sent per save: outline measurements and overlap scores only. Never the photo, corner positions, card text or name.
Visitors are counted with a daily one-way hash for the spam limit (200 saves per visitor per day); IP addresses are not stored.
Users can switch sharing off with **Help improve auto-detect** in the Output panel.

## Sharing learning with the GitHub Pages copy (optional)
The GitHub Pages copy has no PHP, so it uses the built-in weights. To make it learn with this server too,
add this line inside `<head>` of the GitHub copy's `index.html`:
`<meta name="idscanner-api" content="https://YOUR-DOMAIN/idscanner/api/">`
`https://4hus4n.github.io` is already allowed in `api/inc/config.php` (`allowed_origins`).

## Updating later
Upload the new files over the old ones but keep `api/data/` (it holds the learning data and admin password).
