<?php

// This function talks to the home agent: tells it when to scan and receives its result.
function route_agent(PDO $pdo, array $config, string $action, string $method): void
{
    $agent = user_by_agent_key($pdo, (string) ($_SERVER['HTTP_X_AGENT_KEY'] ?? ''));
    if (!$agent) fail('Invalid agent key', 401);
    $pdo->prepare('UPDATE users SET agent_seen = ? WHERE id = ?')->execute([time(), $agent['id']]);

    if ($action === 'agent_poll') {
        $requested = (int) $agent['scan_requested'];
        $subnet = user_subnet($agent);
        json_out(['scan_requested' => $subnet !== '' && $requested > 0 && time() - $requested < 300, 'subnet' => $subnet]);
    }
    if ($action === 'agent_ingest' && $method === 'POST') {
        json_out(ingest_agent_scan($pdo, $config, $agent, json_body()));
    }
    fail('Unknown agent action', 404);
}
