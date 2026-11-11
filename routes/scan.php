<?php
// "Scan now": runs the scan on this server, or asks the user's home agent to scan (SCAN_MODE=agent).

function route_scan(PDO $pdo, array $config, array $user, string $method): void
{
    if ($method !== 'POST') fail('POST required', 405);
    if ($config['scan_mode'] === 'agent') {   // the user's home agent picks this up within seconds
        $pdo->prepare('UPDATE users SET scan_requested = ? WHERE id = ?')->execute([time(), $user['id']]);
        json_out(['queued' => true]);
    }
    json_out(run_scan($pdo, $config, $user, 'manual'));
}
