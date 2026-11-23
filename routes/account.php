<?php

// This function sends the user's summary for the dashboard.
function route_status(PDO $pdo, array $config, array $user, string $method): void
{
    $st = $pdo->prepare('SELECT * FROM scans WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$user['id']]);
    json_out([
        'email' => $user['email'],
        'last_scan' => $st->fetch() ?: null,
        'subnet' => user_subnet($user, $config),
        'mode' => $config['scan_mode'],
        'scan_target_s' => $config['scan_target_s'],
        'alert_email' => $user['alert_email'] ?? '',
        'agent_key' => $user['agent_key'],
        'agent_online' => time() - (int) $user['agent_seen'] < 60,
    ]);
}

// This function saves the user's network range and alert email.
function route_settings(PDO $pdo, array $config, array $user, string $method): void
{
    $b = json_body();
    if (array_key_exists('subnet', $b)) {
        try {
            $subnet = trim((string) $b['subnet']) === '' ? null : normalise_subnet((string) $b['subnet']);
        } catch (InvalidArgumentException $e) {
            fail('Enter the network range like 192.168.0.0/24 (prefix between /22 and /30)');
        }
        $pdo->prepare('UPDATE users SET subnet = ? WHERE id = ?')->execute([$subnet, $user['id']]);
    }
    if (array_key_exists('alert_email', $b)) {
        $email = trim((string) $b['alert_email']);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Invalid email address');
        $pdo->prepare('UPDATE users SET alert_email = ? WHERE id = ?')->execute([$email === '' ? null : $email, $user['id']]);
    }
    json_out(['ok' => true]);
}

// This function sends a test email, so the user can check that alert emails work.
function route_test_email(PDO $pdo, array $config, array $user, string $method): void
{
    if ($method !== 'POST') fail('POST required', 405);
    $to = (string) ($user['alert_email'] ?? '');
    if ($to === '') fail('Save an email address first');
    $sent = send_alert_email($config, $to, 'Network Monitor: test email', "This is a test email from your Home Network Monitor.\nIf you can read this, alert emails are working.");
    json_out(['sent' => $sent, 'to' => $to, 'via' => empty($config['smtp_host']) ? 'mail()' : 'smtp']);
}
