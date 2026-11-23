<?php
// The signed-in user's open alerts, and dismissing them.

function route_alerts(PDO $pdo, array $config, array $user, string $method): void
{
    $st = $pdo->prepare('SELECT * FROM alerts WHERE user_id = ? AND acknowledged_at IS NULL ORDER BY id DESC LIMIT 100');
    $st->execute([$user['id']]);
    json_out($st->fetchAll());
}

function route_alert_ack(PDO $pdo, array $config, array $user, string $method): void
{
    $id = json_body()['id'] ?? null;
    if ($id === 'all') {
        $pdo->prepare('UPDATE alerts SET acknowledged_at = ? WHERE user_id = ? AND acknowledged_at IS NULL')->execute([now(), $user['id']]);
    } else {
        $pdo->prepare('UPDATE alerts SET acknowledged_at = ? WHERE user_id = ? AND id = ?')->execute([now(), $user['id'], (int) $id]);
    }
    json_out(['ok' => true]);
}
