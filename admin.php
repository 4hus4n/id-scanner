<?php
// IdScanner — learning dashboard. First visit asks you to create an admin password.
require __DIR__ . '/api/inc/lib.php';

session_name('idscanner_admin');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

$authFile = data_dir() . '/admin.json';
$auth = file_exists($authFile) ? json_decode(file_get_contents($authFile), true) : null;
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$error = null;

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function back($msg = null) { if ($msg) $_SESSION['flash'] = $msg; header('Location: admin.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) back('Your session expired. Try again.');
    $act = $_POST['act'] ?? '';
    if ($act === 'setup' && !$auth) {
        $p = $_POST['pass'] ?? '';
        if (strlen($p) < 8) $error = 'Use at least 8 characters.';
        elseif ($p !== ($_POST['pass2'] ?? '')) $error = 'The two passwords do not match.';
        else {
            file_put_contents($authFile, json_encode(['hash' => password_hash($p, PASSWORD_DEFAULT)]));
            @chmod($authFile, 0600);
            session_regenerate_id(true); $_SESSION['admin'] = true; back('Password saved. You are signed in.');
        }
    } elseif ($act === 'login' && $auth) {
        usleep(300000);
        if (password_verify($_POST['pass'] ?? '', $auth['hash'])) { session_regenerate_id(true); $_SESSION['admin'] = true; back(); }
        $error = 'Wrong password.';
    } elseif (!empty($_SESSION['admin'])) {
        if ($act === 'logout') { $_SESSION = []; session_destroy(); header('Location: admin.php'); exit; }
        if ($act === 'train') { $r = train(true); back($r['message'] ?? 'Training finished.'); }
        if ($act === 'activate') { set_active_version((int)($_POST['version'] ?? 0)); back('Now using ' . ((int)$_POST['version'] ? 'version ' . (int)$_POST['version'] : 'the built-in weights') . '.'); }
    }
}

$signedIn = !empty($_SESSION['admin']);
if ($signedIn) {
    $pdo = db();
    $tot = $pdo->query('SELECT COUNT(*) n, COALESCE(SUM(edited),0) e, COALESCE(SUM(CASE WHEN best_iou < 0.85 THEN 1 ELSE 0 END),0) m FROM feedback')->fetch(PDO::FETCH_ASSOC);
    $since = gmdate('Y-m-d', time() - 6 * 86400);
    $wk = $pdo->prepare('SELECT COUNT(*) n, COALESCE(SUM(edited),0) e FROM feedback WHERE day >= ?'); $wk->execute([$since]); $wk = $wk->fetch(PDO::FETCH_ASSOC);
    $from30 = gmdate('Y-m-d', time() - 29 * 86400);
    $d = $pdo->prepare('SELECT day, COUNT(*) n, SUM(edited) e FROM feedback WHERE day >= ? GROUP BY day'); $d->execute([$from30]);
    $daily = []; foreach ($d->fetchAll(PDO::FETCH_ASSOC) as $r) $daily[$r['day']] = $r;
    $byVer = $pdo->query('SELECT wver, COUNT(*) n, SUM(edited) e FROM feedback GROUP BY wver ORDER BY wver')->fetchAll(PDO::FETCH_ASSOC);
    $versions = $pdo->query('SELECT * FROM weights ORDER BY version DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
    $cur = current_weights();
    $report = json_decode((string)meta_get('last_report', ''), true);
    $lastTrain = (int)meta_get('last_train', 0);
}
$pct = fn($a, $b) => $b ? round(100 * $a / $b) . '%' : '–';
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>IdScanner learning</title><meta name="robots" content="noindex">
<link rel="icon" href="icons/icon.svg">
<style>
:root{--bg:#eaefee;--surface:#fff;--sunk:#dfe6e4;--ink:#13201e;--muted:#5a6b67;--line:#cdd7d4;--accent:#0d6b58;--accent-ink:#fff;--soft:#d3ebe4;--warn:#9a5208;--warn-soft:#fbe9d4;--bar2:#e2a35a}
@media (prefers-color-scheme:dark){:root{--bg:#0e1513;--surface:#16201e;--sunk:#1f2b28;--ink:#e4ece9;--muted:#93a59f;--line:#2b3a36;--accent:#41c3a2;--accent-ink:#06231c;--soft:#173a32;--warn:#f0a75a;--warn-soft:#3a2a17;--bar2:#c98a45;color-scheme:dark}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.45 system-ui,-apple-system,"Segoe UI",sans-serif}
.wrap{max-width:980px;margin:0 auto;padding:20px 16px 48px}
header{display:flex;align-items:center;gap:12px;margin-bottom:18px}header img{width:36px;height:36px;border-radius:9px}
h1{font-size:1.3rem;margin:0}h2{font-size:1.02rem;margin:0 0 12px}.sub{color:var(--muted);font-size:.85rem}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:14px}
.card{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:16px;min-width:0}
.big{font-size:1.9rem;font-weight:700;font-variant-numeric:tabular-nums;line-height:1.1}
.lbl{font-size:.74rem;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);font-weight:600;margin-bottom:6px}
.btn{border:1px solid var(--line);background:var(--surface);color:var(--ink);border-radius:9px;padding:8px 14px;font:inherit;font-weight:600;cursor:pointer;min-height:40px}
.btn.primary{background:var(--accent);border-color:var(--accent);color:var(--accent-ink)}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.flash{background:var(--soft);color:var(--ink);border-radius:10px;padding:10px 14px;margin-bottom:14px}
.err{background:var(--warn-soft);color:var(--warn);border-radius:10px;padding:10px 14px;margin:10px 0}
table{width:100%;border-collapse:collapse;font-size:.88rem;font-variant-numeric:tabular-nums}th,td{text-align:left;padding:8px 6px;border-bottom:1px solid var(--line)}th{color:var(--muted);font-weight:600;font-size:.76rem;text-transform:uppercase;letter-spacing:.05em}
.scroll{overflow-x:auto}
.pill{display:inline-block;font-size:.72rem;font-weight:700;padding:3px 8px;border-radius:99px;background:var(--soft);color:var(--accent)}
.pill.off{background:var(--sunk);color:var(--muted)}
code{background:var(--sunk);padding:2px 6px;border-radius:6px;font-size:.84rem;word-break:break-all}
input[type=password]{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:9px;background:var(--bg);color:var(--ink);font:inherit;margin:6px 0 12px}
.login{max-width:380px;margin:60px auto}
svg text{fill:var(--muted);font-size:10px}
.legend{display:flex;gap:14px;font-size:.8rem;color:var(--muted);margin-top:6px}.legend i{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:5px;vertical-align:-1px}
</style></head><body><div class="wrap">
<header><img src="icons/icon.svg" alt=""><div><h1>IdScanner learning</h1><div class="sub">How auto-detect is improving from everyone's corner fixes</div></div>
<?php if ($signedIn): ?><form method="post" style="margin-left:auto"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><button class="btn" name="act" value="logout">Sign out</button></form><?php endif; ?></header>

<?php if (!$auth): ?>
  <div class="card login"><h2>Create the admin password</h2><p class="sub">This page shows learning stats and lets you train or roll back. Only you should know this password.</p>
  <?php if ($error): ?><div class="err"><?= h($error) ?></div><?php endif; ?>
  <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="act" value="setup">
  <label>New password<input type="password" name="pass" minlength="8" required autocomplete="new-password"></label>
  <label>Repeat password<input type="password" name="pass2" minlength="8" required autocomplete="new-password"></label>
  <button class="btn primary">Save password</button></form></div>
<?php elseif (!$signedIn): ?>
  <div class="card login"><h2>Sign in</h2>
  <?php if ($error): ?><div class="err"><?= h($error) ?></div><?php endif; ?>
  <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="act" value="login">
  <label>Password<input type="password" name="pass" required autofocus autocomplete="current-password"></label>
  <button class="btn primary">Sign in</button></form></div>
<?php else: ?>
  <?php if ($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
  <div class="grid">
    <div class="card"><div class="lbl">Saves shared</div><div class="big"><?= (int)$tot['n'] ?></div><div class="sub"><?= (int)$wk['n'] ?> in the last 7 days</div></div>
    <div class="card"><div class="lbl">Right first time</div><div class="big"><?= $pct($tot['n'] - $tot['e'], $tot['n']) ?></div><div class="sub">Last 7 days: <?= $pct($wk['n'] - $wk['e'], $wk['n']) ?></div></div>
    <div class="card"><div class="lbl">Corners fixed by hand</div><div class="big"><?= (int)$tot['e'] ?></div><div class="sub">These teach the picker</div></div>
    <div class="card"><div class="lbl">Missed outright</div><div class="big"><?= (int)$tot['m'] ?></div><div class="sub">No outline close to the real card</div></div>
  </div>

  <div class="card" style="margin-bottom:14px"><h2>Last 30 days</h2>
  <?php $W = 900; $H = 140; $max = 1; for ($i = 29; $i >= 0; $i--) { $dd = gmdate('Y-m-d', time() - $i * 86400); $max = max($max, (int)($daily[$dd]['n'] ?? 0)); } $bw = $W / 30; ?>
  <div class="scroll"><svg viewBox="0 0 <?= $W ?> <?= $H + 18 ?>" width="100%" style="min-width:520px" role="img" aria-label="Daily saves, split into right first time and fixed by hand">
  <?php for ($i = 29, $x = 0; $i >= 0; $i--, $x++) { $dd = gmdate('Y-m-d', time() - $i * 86400); $n = (int)($daily[$dd]['n'] ?? 0); $e = (int)($daily[$dd]['e'] ?? 0);
    $hOk = ($n - $e) / $max * $H; $hE = $e / $max * $H; $X = $x * $bw + 2;
    if ($n) { ?><rect x="<?= $X ?>" y="<?= $H - $hOk - $hE ?>" width="<?= $bw - 4 ?>" height="<?= $hE ?>" fill="var(--bar2)" rx="2"/><rect x="<?= $X ?>" y="<?= $H - $hOk ?>" width="<?= $bw - 4 ?>" height="<?= $hOk ?>" fill="var(--accent)" rx="2"><title><?= h($dd) ?>: <?= $n ?> saves, <?= $e ?> fixed</title></rect><?php }
    if ($x % 5 === 0) { ?><text x="<?= $X ?>" y="<?= $H + 14 ?>"><?= h(substr($dd, 5)) ?></text><?php } } ?>
  <text x="2" y="10">max <?= $max ?> a day</text>
  </svg></div>
  <div class="legend"><span><i style="background:var(--accent)"></i>Right first time</span><span><i style="background:var(--bar2)"></i>Fixed by hand</span></div></div>

  <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr))">
    <div class="card"><h2>Accuracy by picker version</h2><div class="scroll"><table><tr><th>Version</th><th>Saves</th><th>Right first time</th></tr>
      <?php if (!$byVer): ?><tr><td colspan="3" class="sub">No saves yet.</td></tr><?php endif; ?>
      <?php foreach ($byVer as $r): ?><tr><td><?= $r['wver'] ? 'v' . (int)$r['wver'] : 'Built-in' ?></td><td><?= (int)$r['n'] ?></td><td><?= $pct($r['n'] - $r['e'], $r['n']) ?></td></tr><?php endforeach; ?>
    </table></div><p class="sub">Each save records which version picked the outline, so you can see whether newer versions need fewer fixes.</p></div>

    <div class="card"><h2>Training</h2>
      <p>In use: <span class="pill"><?= $cur['version'] ? 'v' . $cur['version'] : 'Built-in' ?></span></p>
      <p class="sub">Last run: <?= $lastTrain ? h(gmdate('Y-m-d H:i', $lastTrain)) . ' UTC' : 'never' ?><?php if ($report): ?><br><?= h($report['message'] ?? '') ?><?php if (isset($report['acc_new'])): ?><br>Check: <?= round($report['acc_old'] * 100) ?>% → <?= round($report['acc_new'] * 100) ?>% on <?= h($report['eval_on']) ?>, test photos <?= (int)$report['reg_old'] ?> → <?= (int)$report['reg_new'] ?> of <?= (int)$report['reg_total'] ?><?php endif; ?><?php endif; ?></p>
      <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><button class="btn primary" name="act" value="train">Train now</button></form>
      <p class="sub">Training also runs by itself once every <?= (float)cfg('train_every_hours') ?> hours when new saves arrive. New weights go live only if they do at least as well on held-back saves and on the built-in test photos. For a fixed schedule, add this in cPanel → Cron Jobs (daily):<br><code>php <?= h(realpath(__DIR__ . '/api/train.php')) ?></code></p>
    </div>
  </div>

  <div class="card"><h2>Versions</h2><div class="scroll"><table><tr><th>Version</th><th>Made</th><th>Trained on</th><th>Check before → after</th><th>Test photos</th><th></th></tr>
    <?php foreach ($versions as $v): ?><tr><td>v<?= (int)$v['version'] ?> <?php if ($v['active']): ?><span class="pill">in use</span><?php endif; ?></td><td><?= h(gmdate('Y-m-d H:i', (int)$v['created_at'])) ?></td><td><?= (int)$v['n_train'] ?> saves</td><td><?= round($v['acc_old'] * 100) ?>% → <?= round($v['acc_new'] * 100) ?>%</td><td><?= (int)$v['reg_old'] ?> → <?= (int)$v['reg_new'] ?></td>
      <td><?php if (!$v['active']): ?><form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="version" value="<?= (int)$v['version'] ?>"><button class="btn" name="act" value="activate">Use this</button></form><?php endif; ?></td></tr><?php endforeach; ?>
    <tr><td>Built-in <?php if (!$cur['version']): ?><span class="pill">in use</span><?php endif; ?></td><td colspan="4" class="sub">Shipped with the app</td><td><?php if ($cur['version']): ?><form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="version" value="0"><button class="btn" name="act" value="activate">Use this</button></form><?php endif; ?></td></tr>
  </table></div></div>
  <p class="sub" style="margin-top:16px">Stored per save: outline measurements and overlap scores only. No photos, corner positions, card text or IP addresses (visitors are counted with a daily one-way hash).</p>
<?php endif; ?>
</div></body></html>
