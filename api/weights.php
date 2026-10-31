<?php
// GET: the outline-picker weights every app copy should use right now.
require __DIR__ . '/inc/lib.php';
cors();
try {
    $w = current_weights();
    header('Cache-Control: no-cache, max-age=0');
    json_out(['schema' => $w['schema'], 'version' => $w['version'], 'feats' => $w['feats'], 'w' => $w['w'], 'updated' => $w['updated']]);
} catch (Throwable $e) {
    $b = builtin_weights();
    json_out(['schema' => $b['schema'], 'version' => 0, 'feats' => $b['feats'], 'w' => $b['w'], 'updated' => null, 'note' => 'server storage unavailable']);
}
