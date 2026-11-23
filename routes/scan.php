<?php

// This function starts a scan, or asks the home agent to scan.
function route_scan(PDO $pdo, array $config, array $user, string $method): void
{
    if ($method !== 'POST') fail('POST required', 405);
    if (user_subnet($user, $config) === '') fail('Enter your network range first (in the Network & alerts box), then click Scan now.');
    if ($config['scan_mode'] === 'agent') {
        $pdo->prepare('UPDATE users SET scan_requested = ? WHERE id = ?')->execute([time(), $user['id']]);
        json_out(['queued' => true]);
    }
    json_out(run_scan($pdo, $config, $user, 'manual'));
}
