<?php
// POST {v:1, records:[{schema, wver, pick, edited, feats:[[15 numbers]...], ious:[...]}]}
// Stores outline measurements from a confirmed save. No photos, corners or card details are accepted.
require __DIR__ . '/inc/lib.php';
cors();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { json_out(['ok' => false, 'error' => 'Use POST'], 405); exit; }
$raw = file_get_contents('php://input', false, null, 0, 65537);
if ($raw === false || strlen($raw) > 65536) { json_out(['ok' => false, 'error' => 'Too large'], 413); exit; }
$body = json_decode($raw, true);
$records = is_array($body) ? ($body['records'] ?? null) : null;
if (!is_array($records) || !$records || count($records) > 20) { json_out(['ok' => false, 'error' => 'Bad request'], 400); exit; }

try {
    $pdo = db();
    $day = gmdate('Y-m-d'); $client = client_hash();
    $s = $pdo->prepare('SELECT COUNT(*) FROM feedback WHERE day = ? AND client = ?');
    $s->execute([$day, $client]);
    $room = (int)cfg('max_per_client_per_day') - (int)$s->fetchColumn();
    if ($room <= 0) { json_out(['ok' => false, 'error' => 'Daily limit reached'], 429); exit; }
    $ins = $pdo->prepare('INSERT INTO feedback (created_at, day, client, schema_v, wver, pick, edited, best_iou, data) VALUES (?,?,?,?,?,?,?,?,?)');
    $stored = 0;
    foreach ($records as $r) {
        if ($stored >= $room) break;
        $c = clean_record($r);
        if (!$c) continue;
        $ins->execute([time(), $day, $client, FEAT_SCHEMA, $c['wver'], $c['pick'], $c['edited'], $c['best'], json_encode(['F' => $c['F'], 'I' => $c['I']])]);
        $stored++;
    }
    $due = training_due();
    json_out(['ok' => true, 'stored' => $stored]);
    // nightly training without cron: run after the reply has been sent
    if ($due) {
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        else { @ob_end_flush(); @flush(); }
        ignore_user_abort(true);
        try { train(false); } catch (Throwable $e) { error_log('IdScanner train: ' . $e->getMessage()); }
    }
} catch (Throwable $e) {
    error_log('IdScanner feedback: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Storage unavailable'], 500);
}
