<?php
// Scans every account on a schedule, without the dashboard (SCAN_MODE=live or demo on this server).
// Set it to run from cron or Windows Task Scheduler, for example every 2 minutes:
//   php /path/to/this/folder/cli_scan.php
// (In SCAN_MODE=agent each home agent schedules its own scans, so run agent.php instead.)
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/lib/monitor.php';

$pdo = db_connect($config);
$failed = false;
foreach ($pdo->query('SELECT * FROM users')->fetchAll() as $user) {
    try {
        $r = run_scan($pdo, $config, $user, 'scheduled');
        echo sprintf("[%s] %s: %d devices, %d new unknown, %.1fs\n", $r['started_at'], $user['email'], $r['devices_found'], $r['new_unknown'], $r['duration_ms'] / 1000);
    } catch (Throwable $e) {
        fwrite(STDERR, "Scan failed for {$user['email']}: " . $e->getMessage() . "\n");
        $failed = true;
    }
}
exit($failed ? 1 : 0);
