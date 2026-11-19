<?php
declare(strict_types=1);

// Private contest settings. Keep this file outside the webroot, next to
// config.php, at /home1/vhuwyfmy/private/newhotornot/contest_config.php
// Start and end are America/New_York wall times. end_et is inclusive.

return [
    'title' => 'Hot or Not Championship',
    'start_et' => '2026-11-17 00:00:00',
    'end_et' => '2027-03-31 23:59:59',
    'min_votes' => 50,
];
