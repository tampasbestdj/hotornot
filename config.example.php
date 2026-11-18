<?php
// Copy to /home1/vhuwyfmy/private/newhotornot/config.php on the server.
// Never commit the real file.
return [
    'dsn' => 'mysql:host=localhost;dbname=vhuwyfmy_hotornot;charset=utf8mb4',
    'user' => 'vhuwyfmy_hon',
    'pass' => 'CHANGE_ME',
    'uploads_dir' => '/home1/vhuwyfmy/private/newhotornot/uploads',
    'seeds_dir' => '/home1/vhuwyfmy/newhotornot.com/images',
    'allowed_origins' => [
        'https://newhotornot.com',
        'https://www.newhotornot.com',
    ],
    // Box-only morning stats. Leave empty to disable /api/stats.php.
    'stats_secret' => '',
];
