<?php
// IdScanner learning server — settings.
// Most people never need to change anything here.

return [
    // Database. 'sqlite' needs no setup: a file is created in api/data/.
    // To use MySQL instead, set 'driver' => 'mysql' and fill in the mysql block
    // (create the database and user in cPanel > MySQL Databases first).
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => __DIR__ . '/../data/learn.sqlite',
        'mysql' => [
            'host' => 'localhost',
            'name' => '',
            'user' => '',
            'pass' => '',
        ],
    ],

    // Other websites that may send feedback / read weights (the app's own site is always allowed).
    // Add your GitHub Pages address here if that copy should share the same learning.
    'allowed_origins' => [
        'https://4hus4n.github.io',
    ],

    // Spam guard: most records one visitor can send per day.
    'max_per_client_per_day' => 200,

    // Training
    'min_records_to_train' => 50,   // nothing is learned until this many confirmed saves
    'train_every_hours' => 24,      // automatic training interval (also run by cron if set up)
    'max_records_per_train' => 4000, // newest N records are used
    'max_step' => 0.5,              // biggest change any single weight may make per update
    'lambda' => 0.05,               // pull toward the current weights (higher = slower, steadier learning)
    'iterations' => 400,
];
