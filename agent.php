<?php
// The home agent. It looks at your home network, finds which devices are connected, and sends the list to the
// dashboard's server.
// Run it on a computer at home that stays switched on:
//   php agent.php          keeps running: scans when you click Scan now, and again every 2 minutes
//   php agent.php --once   scans one time and then stops
// It reads two settings from the .env file next to this file: AGENT_API_URL and AGENT_KEY.
// Both are shown in the dashboard's Home agent panel.
// The part of the network to scan is the range you type in the dashboard.
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
    fwrite(STDERR, "Set AGENT_API_URL and AGENT_KEY first (open the .env file next to agent.php).\n");
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
        $err = curl_error($ch);   // stays empty when the request worked
    } else {   // This PHP has no curl add-on, so use PHP's basic web request instead (secure https links need the openssl add-on)
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
        // Code 0 means no answer came back at all (wrong address, no internet, or a security certificate problem), so explain why.
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
echo flush_arp_cache()
    ? "ARP check: ON (ping sweep first, then an ARP pass finds devices that ignore pings).\n"
    : "ARP check: OFF. Run the agent with sudo (Windows: an Administrator terminal) to also find devices that ignore pings.\n";
$last = 0;
while (true) {
    $poll = agent_call($config, 'agent_poll');
    if ($poll !== null && (($poll['scan_requested'] ?? false) || time() - $last >= $config['agent_interval_s'])) {
        agent_scan_and_send($config, $poll['subnet']);   // the range to scan comes from the dashboard
        $last = time();
    }
    sleep(5);
}
