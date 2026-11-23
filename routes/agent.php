<?php

// This function answers the home agent: tells it when to scan and receives the scan result.
function route_agent(PDO $pdo, array $config, string $action, string $method): void
{
    $agent = user_by_agent_key($pdo, (string) ($_SERVER['HTTP_X_AGENT_KEY'] ?? ''));
    if (!$agent) fail('Invalid agent key', 401);
    $pdo->prepare('UPDATE users SET agent_seen = ? WHERE id = ?')->execute([time(), $agent['id']]);

    if ($action === 'agent_poll') {
        $requested = (int) $agent['scan_requested'];
        json_out(['scan_requested' => $requested > 0 && time() - $requested < 300, 'subnet' => user_subnet($agent, $config)]);
    }
    if ($action === 'agent_ingest' && $method === 'POST') {
        json_out(ingest_agent_scan($pdo, $config, $agent, json_body()));
    }
    fail('Unknown agent action', 404);
}
