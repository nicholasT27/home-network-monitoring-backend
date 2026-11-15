<?php
// Home agent: scans the local network and sends the results to the (cloud) API.
// Run it on an always-on computer inside the home network:
//   php agent.php          keep running: scans when the dashboard asks, and every AGENT_INTERVAL_S seconds
//   php agent.php --once   scan once and exit
// Settings (env vars or backend/.env): AGENT_API_URL, AGENT_KEY (both shown in your dashboard), AGENT_INTERVAL_S.
// The network range to scan is the one you enter in the dashboard.
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/lib/scanner.php';

if ($config['agent_api_url'] !== '' && !preg_match('#^https?://[^/\s]+#i', $config['agent_api_url'])) {
    fwrite(STDERR, "AGENT_API_URL must start with https:// (check the .env file next to agent.php).\n");
    exit(1);
}
if ($config['agent_api_url'] === '' || $config['agent_key'] === '') {
    fwrite(STDERR, "Set AGENT_API_URL and AGENT_KEY first (see backend/.env.example).\n");
    exit(1);
}

function agent_call(array $config, string $action, ?array $body = null): ?array
{
    $url = $config['agent_api_url'] . (str_contains($config['agent_api_url'], '?') ? '&' : '?') . 'action=' . $action;
    $headers = ['Content-Type: application/json', 'X-Agent-Key: ' . $config['agent_key']];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $headers]);
        if ($body !== null) {
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body)]);
        }
        $out = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);   // empty when the request itself worked
    } else {   // PHP without the curl extension: use plain streams (https needs the openssl extension)
        $ctx = stream_context_create(['http' => [
            'method' => $body !== null ? 'POST' : 'GET', 'header' => implode("\r\n", $headers),
            'content' => $body !== null ? json_encode($body) : '', 'timeout' => 30, 'ignore_errors' => true,
        ]]);
        $out = @file_get_contents($url, false, $ctx);
        $err = $out === false ? (error_get_last()['message'] ?? 'request failed') : '';
        $code = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int) $m[1];
        }
    }
    $data = json_decode((string) $out, true);
    if ($code !== 200 || !is_array($data)) {
        // Code 0 means no reply at all (wrong address, no internet, certificate problem), so say why.
        $why = $code === 0 ? "could not connect to {$config['agent_api_url']}" . ($err !== '' ? " ($err)" : '') : substr((string) $out, 0, 200);
        fwrite(STDERR, "[" . date('H:i:s') . "] API error $action ($code): $why\n");
        return null;
    }
    return $data;
}

function agent_scan_and_send(array $config, string $subnet): void
{
    $start = microtime(true);
    $found = scan_network(['subnet' => $subnet, 'scan_mode' => 'live'], time() + 25);
    $result = agent_call($config, 'agent_ingest', [
        'devices' => $found,
        'duration_ms' => (int) round((microtime(true) - $start) * 1000),
    ]);
    $alive = count(array_filter($found, fn($d) => $d['alive']));
    echo '[' . date('H:i:s') . '] scanned ' . count($found) . " devices ($alive answering), "
        . ($result ? "sent: {$result['new_unknown']} new unknown" : 'NOT sent') . "\n";
}

if (in_array('--once', $argv, true)) {
    $poll = agent_call($config, 'agent_poll');
    if ($poll === null) {
        exit(1);
    }
    agent_scan_and_send($config, $poll['subnet']);
    exit(0);
}

echo "Home agent running, API {$config['agent_api_url']}. Ctrl+C to stop.\n";
$last = 0;
while (true) {
    $poll = agent_call($config, 'agent_poll');
    if ($poll !== null && (($poll['scan_requested'] ?? false) || time() - $last >= $config['agent_interval_s'])) {
        agent_scan_and_send($config, $poll['subnet']);   // range comes from the dashboard setting
        $last = time();
    }
    sleep(5);
}
