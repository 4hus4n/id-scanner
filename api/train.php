<?php
// Run training from the command line (cPanel > Cron Jobs):
//   php /home/USER/public_html/idscanner/api/train.php
// Add --force to train even if the last run was recent.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Run from the command line or use the admin page.\n"); }
require __DIR__ . '/inc/lib.php';
$force = in_array('--force', $argv ?? [], true);
if (!$force && !training_due()) { echo "Not due yet. Use --force to run anyway.\n"; exit(0); }
$r = train($force);
echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
