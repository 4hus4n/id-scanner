<?php
// IdScanner learning server — shared code.
// The app sends, for each saved card photo, the measurements of every outline it considered
// (FEAT_COUNT numbers each) and how well each one matched the corners the user finally saved.
// Training learns weights so the right outline scores highest. No photos or card details are stored.

const FEAT_SCHEMA = 'f1';
const FEAT_COUNT = 15;
const MAX_CANDS = 16;
const MISS_IOU = 0.85;      // if no outline overlapped the saved corners this well, the record is a "miss"
const POS_MARGIN = 0.015;   // outlines within this of the best overlap all count as right

function cfg($key = null)
{
    static $c = null;
    if ($c === null) $c = require __DIR__ . '/config.php';
    return $key === null ? $c : ($c[$key] ?? null);
}

function data_dir()
{
    $d = __DIR__ . '/../data';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    if (!file_exists("$d/.htaccess")) {
        @file_put_contents("$d/.htaccess", "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
    if (!file_exists("$d/index.html")) @file_put_contents("$d/index.html", '');
    return $d;
}

function secret()
{
    $f = data_dir() . '/secret.txt';
    if (!file_exists($f)) @file_put_contents($f, bin2hex(random_bytes(32)));
    return trim((string)@file_get_contents($f));
}

function db()
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = cfg('db');
    if (($c['driver'] ?? 'sqlite') === 'mysql') {
        $m = $c['mysql'];
        $pdo = new PDO("mysql:host={$m['host']};dbname={$m['name']};charset=utf8mb4", $m['user'], $m['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("CREATE TABLE IF NOT EXISTS feedback (
            id INT AUTO_INCREMENT PRIMARY KEY, created_at INT NOT NULL, day CHAR(10) NOT NULL, client CHAR(16) NOT NULL,
            schema_v VARCHAR(8) NOT NULL, wver INT NOT NULL, pick INT NOT NULL, edited TINYINT NOT NULL, best_iou FLOAT NOT NULL,
            data MEDIUMTEXT NOT NULL, INDEX (day, client), INDEX (schema_v)) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE IF NOT EXISTS weights (
            version INT PRIMARY KEY, created_at INT NOT NULL, w TEXT NOT NULL, n_train INT, n_hold INT,
            acc_old FLOAT, acc_new FLOAT, reg_old INT, reg_new INT, active TINYINT NOT NULL DEFAULT 0, note VARCHAR(255)) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE IF NOT EXISTS meta (k VARCHAR(64) PRIMARY KEY, v MEDIUMTEXT) ENGINE=InnoDB");
    } else {
        data_dir();
        $pdo = new PDO('sqlite:' . $c['sqlite_path'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;');
        $pdo->exec("CREATE TABLE IF NOT EXISTS feedback (
            id INTEGER PRIMARY KEY AUTOINCREMENT, created_at INTEGER NOT NULL, day TEXT NOT NULL, client TEXT NOT NULL,
            schema_v TEXT NOT NULL, wver INTEGER NOT NULL, pick INTEGER NOT NULL, edited INTEGER NOT NULL, best_iou REAL NOT NULL,
            data TEXT NOT NULL)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS fb_day_client ON feedback(day, client)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS weights (
            version INTEGER PRIMARY KEY, created_at INTEGER NOT NULL, w TEXT NOT NULL, n_train INTEGER, n_hold INTEGER,
            acc_old REAL, acc_new REAL, reg_old INTEGER, reg_new INTEGER, active INTEGER NOT NULL DEFAULT 0, note TEXT)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT)");
    }
    return $pdo;
}

function meta_get($k, $def = null)
{
    $s = db()->prepare('SELECT v FROM meta WHERE k = ?');
    $s->execute([$k]);
    $v = $s->fetchColumn();
    return $v === false ? $def : $v;
}

function meta_set($k, $v)
{
    $pdo = db();
    $pdo->prepare('DELETE FROM meta WHERE k = ?')->execute([$k]);
    $pdo->prepare('INSERT INTO meta (k, v) VALUES (?, ?)')->execute([$k, (string)$v]);
}

function builtin_weights()
{
    $j = json_decode(file_get_contents(__DIR__ . '/builtin.json'), true);
    return ['version' => 0, 'schema' => FEAT_SCHEMA, 'feats' => $j['feats'], 'w' => array_map('floatval', $j['w'])];
}

function current_weights()
{
    $b = builtin_weights();
    $row = db()->query('SELECT version, w, created_at FROM weights WHERE active = 1 ORDER BY version DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!$row) return $b + ['updated' => null];
    $w = json_decode($row['w'], true);
    if (!is_array($w) || count($w) !== FEAT_COUNT) return $b + ['updated' => null];
    return ['version' => (int)$row['version'], 'schema' => FEAT_SCHEMA, 'feats' => $b['feats'], 'w' => array_map('floatval', $w), 'updated' => gmdate('c', (int)$row['created_at'])];
}

function client_ip()
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// anonymous per-day visitor id, only used for rate limiting and fair weighting
function client_hash()
{
    return substr(hash('sha256', client_ip() . '|' . gmdate('Y-m-d') . '|' . secret()), 0, 16);
}

function json_out($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
}

function cors()
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $ok = in_array(rtrim($origin, '/'), array_map(fn($o) => rtrim($o, '/'), cfg('allowed_origins') ?: []), true)
            || parse_url($origin, PHP_URL_HOST) === parse_url('//' . $host, PHP_URL_HOST);
        if ($ok) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Access-Control-Max-Age: 86400');
        }
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
}

// returns a cleaned record or null
function clean_record($r)
{
    if (!is_array($r) || ($r['schema'] ?? '') !== FEAT_SCHEMA) return null;
    $feats = $r['feats'] ?? null; $ious = $r['ious'] ?? null;
    if (!is_array($feats) || !is_array($ious)) return null;
    $n = count($feats);
    if ($n < 1 || $n > MAX_CANDS || count($ious) !== $n) return null;
    $F = [];
    foreach ($feats as $f) {
        if (!is_array($f) || count($f) !== FEAT_COUNT) return null;
        $row = [];
        foreach ($f as $v) {
            if (!is_numeric($v)) return null;
            $v = (float)$v;
            if (!is_finite($v) || $v < -0.01 || $v > 1.01) return null;
            $row[] = round($v, 3);
        }
        $F[] = $row;
    }
    $I = [];
    foreach ($ious as $v) {
        if (!is_numeric($v)) return null;
        $v = (float)$v;
        if (!is_finite($v) || $v < 0 || $v > 1) return null;
        $I[] = round($v, 3);
    }
    $pick = (int)($r['pick'] ?? -1);
    if ($pick < 0 || $pick >= $n) return null;
    return ['F' => $F, 'I' => $I, 'pick' => $pick, 'wver' => max(0, (int)($r['wver'] ?? 0)), 'edited' => !empty($r['edited']) ? 1 : 0, 'best' => max($I)];
}

// ---------------- training ----------------

function positives(array $I)
{
    $b = max($I);
    if ($b < MISS_IOU) return null;
    $P = [];
    foreach ($I as $v) $P[] = $v >= $b - POS_MARGIN ? 1 : 0;
    return $P;
}

function dot(array $w, array $f)
{
    $s = 0.0;
    for ($i = 0; $i < FEAT_COUNT; $i++) $s += $w[$i] * $f[$i];
    return $s;
}

function top_pick(array $w, array $F)
{
    $best = 0; $bs = -INF;
    foreach ($F as $k => $f) { $s = dot($w, $f); if ($s > $bs) { $bs = $s; $best = $k; } }
    return $best;
}

// share of items where the top-scoring outline is one of the right ones
function accuracy(array $w, array $items)
{
    if (!$items) return null;
    $ok = 0;
    foreach ($items as $it) $ok += $it['P'][top_pick($w, $it['F'])];
    return $ok / count($items);
}

// softmax ranking: raise the total probability of the right outlines, stay close to w0
function fit(array $w0, array $items, $lambda, $iters, $lr = 0.5)
{
    $w = $w0;
    $sw = 0.0;
    foreach ($items as $it) $sw += $it['wt'];
    if ($sw <= 0) return $w;
    for ($t = 0; $t < $iters; $t++) {
        $g = [];
        for ($i = 0; $i < FEAT_COUNT; $i++) $g[$i] = 2 * $lambda * ($w[$i] - $w0[$i]);
        foreach ($items as $it) {
            $F = $it['F']; $P = $it['P']; $n = count($F);
            $s = []; $mx = -INF;
            for ($k = 0; $k < $n; $k++) { $s[$k] = dot($w, $F[$k]); if ($s[$k] > $mx) $mx = $s[$k]; }
            $z = 0.0; $zp = 0.0;
            for ($k = 0; $k < $n; $k++) { $s[$k] = exp($s[$k] - $mx); $z += $s[$k]; if ($P[$k]) $zp += $s[$k]; }
            if ($zp <= 0) continue;
            $c = $it['wt'] / $sw;
            for ($k = 0; $k < $n; $k++) {
                $d = $s[$k] / $z - ($P[$k] ? $s[$k] / $zp : 0);
                if ($d == 0) continue;
                for ($i = 0; $i < FEAT_COUNT; $i++) $g[$i] += $c * $d * $F[$k][$i];
            }
        }
        for ($i = 0; $i < FEAT_COUNT; $i++) $w[$i] -= $lr * $g[$i];
    }
    return $w;
}

function regression_items()
{
    $j = json_decode(@file_get_contents(__DIR__ . '/regression.json'), true);
    $out = [];
    foreach (($j['items'] ?? []) as $r) {
        $P = positives($r['iou']);
        if ($P) $out[] = ['F' => $r['F'], 'P' => $P];
    }
    return $out;
}

function regression_score(array $w)
{
    $ok = 0;
    foreach (regression_items() as $it) $ok += $it['P'][top_pick($w, $it['F'])];
    return $ok;
}

// Learn new weights from saved feedback. Publishes them only if they do at least as well as the
// current weights on held-back records and on the built-in test photos.
function train($force = false)
{
    @set_time_limit(300);
    $lock = fopen(data_dir() . '/train.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return ['status' => 'busy', 'message' => 'Training is already running.'];
    try {
        $pdo = db();
        $cur = current_weights();
        $w0 = $cur['w'];
        $limit = (int)cfg('max_records_per_train');
        $rows = $pdo->query("SELECT id, day, client, data FROM feedback WHERE schema_v = '" . FEAT_SCHEMA . "' ORDER BY id DESC LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC);
        $items = []; $per = []; $miss = 0;
        foreach ($rows as $r) {
            $d = json_decode($r['data'], true);
            if (!$d) continue;
            $P = positives($d['I']);
            if (!$P) { $miss++; continue; }
            if (array_sum($P) === count($P)) continue;   // every outline was right: nothing to learn
            $key = $r['day'] . $r['client'];
            $per[$key] = ($per[$key] ?? 0) + 1;
            $items[] = ['id' => (int)$r['id'], 'key' => $key, 'F' => $d['F'], 'P' => $P];
        }
        $n = count($items);
        $report = ['status' => 'skipped', 'records' => count($rows), 'usable' => $n, 'misses' => $miss, 'at' => gmdate('c'), 'from_version' => $cur['version']];
        $min = (int)cfg('min_records_to_train');
        if ($n < $min) {
            $report['message'] = "Waiting for more data: $n of $min useful saves so far.";
            meta_set('last_train', time()); meta_set('last_report', json_encode($report));
            return $report;
        }
        // no single visitor-day may dominate
        $cap = max(20, 0.05 * $n);
        $train = []; $hold = [];
        foreach ($items as $it) {
            $it['wt'] = min(1.0, $cap / $per[$it['key']]);
            if ($it['id'] % 5 === 0) $hold[] = $it; else $train[] = $it;
        }
        $w = fit($w0, $train, (float)cfg('lambda'), (int)cfg('iterations'));
        $step = (float)cfg('max_step'); $moved = 0.0;
        for ($i = 0; $i < FEAT_COUNT; $i++) {
            $w[$i] = round($w0[$i] + max(-$step, min($step, $w[$i] - $w0[$i])), 4);
            $moved = max($moved, abs($w[$i] - $w0[$i]));
        }
        $evalSet = count($hold) >= 10 ? $hold : $train;
        $accOld = accuracy($w0, $evalSet); $accNew = accuracy($w, $evalSet);
        $regOld = regression_score($w0); $regNew = regression_score($w);
        $report += ['n_train' => count($train), 'n_hold' => count($hold), 'eval_on' => count($hold) >= 10 ? 'held-back saves' : 'training saves',
            'acc_old' => round($accOld, 4), 'acc_new' => round($accNew, 4), 'reg_old' => $regOld, 'reg_new' => $regNew, 'reg_total' => count(regression_items()), 'max_change' => round($moved, 4)];
        if ($moved < 0.005) {
            $report['message'] = 'Already up to date: the data does not call for a change.';
        } elseif ($accNew + 1e-9 < $accOld || $regNew < $regOld) {
            $report['status'] = 'rejected';
            $report['message'] = 'New weights did worse on the checks, so the current ones were kept.';
        } else {
            $ver = (int)$pdo->query('SELECT COALESCE(MAX(version), 0) FROM weights')->fetchColumn() + 1;
            $pdo->beginTransaction();
            $pdo->exec('UPDATE weights SET active = 0');
            $pdo->prepare('INSERT INTO weights (version, created_at, w, n_train, n_hold, acc_old, acc_new, reg_old, reg_new, active, note) VALUES (?,?,?,?,?,?,?,?,?,1,?)')
                ->execute([$ver, time(), json_encode($w), count($train), count($hold), $accOld, $accNew, $regOld, $regNew, $force ? 'manual' : 'auto']);
            $pdo->commit();
            $report['status'] = 'published';
            $report['version'] = $ver;
            $report['message'] = "Published version $ver.";
        }
        meta_set('last_train', time()); meta_set('last_report', json_encode($report));
        return $report;
    } finally {
        flock($lock, LOCK_UN); fclose($lock);
    }
}

function training_due()
{
    $last = (int)meta_get('last_train', 0);
    return time() - $last >= 3600 * (float)cfg('train_every_hours');
}

function set_active_version($v)
{
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->exec('UPDATE weights SET active = 0');
    if ($v > 0) $pdo->prepare('UPDATE weights SET active = 1 WHERE version = ?')->execute([(int)$v]);
    $pdo->commit();
}
