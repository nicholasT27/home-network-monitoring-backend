<?php
// The signed-in user's devices and their connection history.

function route_devices(PDO $pdo, array $config, array $user, string $method): void
{
    $st = $pdo->prepare('SELECT * FROM devices WHERE user_id = ? ORDER BY online DESC, last_seen DESC');
    $st->execute([$user['id']]);
    json_out($st->fetchAll());
}

function route_device_update(PDO $pdo, array $config, array $user, string $method): void
{
    $uid = $user['id'];
    $b = json_body();
    $mac = strtoupper((string) ($b['mac'] ?? ''));
    if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) fail('Invalid MAC address');

    if (isset($b['status'])) {
        if (!in_array($b['status'], ['trusted', 'unknown'], true)) fail('Invalid status');
        // Trusting a device clears any suspicion; its current name and maker become the new normal.
        $pdo->prepare('UPDATE devices SET status = ?, suspicious = 0, suspicious_reason = NULL WHERE user_id = ? AND mac = ?')->execute([$b['status'], $uid, $mac]);
        if ($b['status'] === 'trusted') {
            $pdo->prepare('UPDATE alerts SET acknowledged_at = ? WHERE user_id = ? AND mac = ? AND acknowledged_at IS NULL')->execute([now(), $uid, $mac]);
        }
    }
    if (array_key_exists('label', $b)) {
        $label = trim((string) $b['label']);
        $pdo->prepare('UPDATE devices SET label = ? WHERE user_id = ? AND mac = ?')->execute([$label === '' ? null : mb_substr($label, 0, 100), $uid, $mac]);
    }
    if (!empty($b['clear_suspicious'])) {
        $pdo->prepare('UPDATE devices SET suspicious = 0, suspicious_reason = NULL WHERE user_id = ? AND mac = ?')->execute([$uid, $mac]);
        $pdo->prepare('UPDATE alerts SET acknowledged_at = ? WHERE user_id = ? AND mac = ? AND acknowledged_at IS NULL')->execute([now(), $uid, $mac]);
    }
    json_out(['ok' => true]);
}

function route_device_delete(PDO $pdo, array $config, array $user, string $method): void
{
    $mac = strtoupper((string) (json_body()['mac'] ?? ''));
    foreach (['devices', 'connection_history', 'alerts'] as $table) {
        $pdo->prepare("DELETE FROM $table WHERE user_id = ? AND mac = ?")->execute([$user['id'], $mac]);
    }
    json_out(['ok' => true]);
}

function route_history(PDO $pdo, array $config, array $user, string $method): void
{
    $mac = strtoupper((string) ($_GET['mac'] ?? ''));
    if ($mac !== '') {
        $st = $pdo->prepare('SELECT * FROM connection_history WHERE user_id = ? AND mac = ? ORDER BY id DESC LIMIT 100');
        $st->execute([$user['id'], $mac]);
    } else {
        $st = $pdo->prepare('SELECT * FROM connection_history WHERE user_id = ? ORDER BY id DESC LIMIT 100');
        $st->execute([$user['id']]);
    }
    json_out($st->fetchAll());
}
