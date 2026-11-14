<?php
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
// API tests over HTTP:  php backend/tests/api.php
// Starts PHP's built-in server on a temporary SQLite database and calls every endpoint.
$port = 20000 + random_int(0, 9000);
$db = sys_get_temp_dir() . "/netmon_api_test_$port.sqlite";
@unlink($db);
$root = dirname(__DIR__);
// Default: a temporary SQLite database. With TEST_DB_HOST set, the tests run against that MySQL database instead
// (its tables are dropped first, so use an empty test database; the backend then creates the tables itself).
$mysql = getenv('TEST_DB_HOST') !== false;
$dbEnv = ['DB_DSN' => "sqlite:$db"];
if ($mysql) {
    $dbEnv = ['DB_HOST' => getenv('TEST_DB_HOST'), 'DB_PORT' => getenv('TEST_DB_PORT') ?: '3306', 'DB_NAME' => getenv('TEST_DB_NAME'),
              'DB_USER' => getenv('TEST_DB_USER'), 'DB_PASS' => getenv('TEST_DB_PASS') ?: ''];
    $pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s', $dbEnv['DB_HOST'], $dbEnv['DB_PORT'], $dbEnv['DB_NAME']), $dbEnv['DB_USER'], $dbEnv['DB_PASS']);
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $pdo->exec("DROP TABLE `$t`");
    }
    echo "(running against MySQL at {$dbEnv['DB_HOST']}:{$dbEnv['DB_PORT']})\n";
}
$srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root,
    $dbEnv + ['SCAN_MODE' => 'demo', 'AUTH_SECRET' => 'test-secret', 'PATH' => getenv('PATH')]);
register_shutdown_function(function () use ($srv, $db) { proc_terminate($srv); @unlink($db); @unlink("$db-wal"); @unlink("$db-shm"); });
usleep(700000);

$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? 'PASS' : 'FAIL') . "  $name\n"; if (!$ok) $fail++; }

function call(string $action, string $method = 'GET', ?array $body = null, array $headers = [], array $query = []): array
{
    global $port;
    $q = http_build_query(['action' => $action] + $query);
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'ignore_errors' => true, 'timeout' => 15,
        'header' => implode("\r\n", array_merge(['Content-Type: application/json'], $headers)),
        'content' => $body !== null ? json_encode($body) : '',
    ]]);
    $out = @file_get_contents("http://127.0.0.1:$port/index.php?$q", false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int) $m[1];
    return [$code, json_decode((string) $out, true), $http_response_header ?? []];
}
function auth(string $token): array { return ["X-Auth-Token: $token"]; }

// --- the setup diagnostic is off unless DEBUG=1
[$c] = call('health');
check('health diagnostic is off by default (needs sign-in like any other action)', $c === 401);

// --- accounts
[$c] = call('status');
check('status without sign-in is refused (401)', $c === 401);
[$c, $r] = call('register', 'POST', ['email' => 'alice@example.com', 'password' => 'password-alice']);
$alice = $r['token'] ?? '';
check('register returns a token', $c === 200 && $alice !== '');
[$c] = call('register', 'POST', ['email' => 'alice@example.com', 'password' => 'another-pass']);
check('duplicate email rejected (400)', $c === 400);
[$c] = call('register', 'POST', ['email' => 'x@example.com', 'password' => 'short']);
check('short password rejected (400)', $c === 400);
[$c, $r] = call('login', 'POST', ['email' => 'alice@example.com', 'password' => 'password-alice']);
check('login works', $c === 200 && !empty($r['token']));
[$c] = call('login', 'POST', ['email' => 'alice@example.com', 'password' => 'wrong-password']);
check('wrong password rejected (401)', $c === 401);
[$c] = call('login', 'GET');
check('login by GET is not accepted', $c === 401);

// --- status and settings
[$c, $r] = call('status', 'GET', null, auth($alice));
check('status has the expected fields', $c === 200 && $r['email'] === 'alice@example.com' && isset($r['agent_key'], $r['subnet'], $r['mode']) && $r['mode'] === 'demo' && $r['last_scan'] === null);
$agentKey = $r['agent_key'];
[$c, $r] = call('nope', 'GET', null, auth($alice));
check('unknown action is 404', $c === 404);
[$c] = call('settings', 'POST', ['subnet' => '192.168.0.124/24'], auth($alice));
[, $r] = call('status', 'GET', null, auth($alice));
check('network range saved as a network address', $c === 200 && $r['subnet'] === '192.168.0.0/24');
[$c] = call('settings', 'POST', ['subnet' => '10.0.0.0/8'], auth($alice));
check('too-large range rejected (400)', $c === 400);
[$c] = call('settings', 'POST', ['alert_email' => 'not-an-email'], auth($alice));
check('invalid alert email rejected (400)', $c === 400);
[$c] = call('settings', 'POST', ['alert_email' => 'alice@example.com'], auth($alice));
[, $r] = call('status', 'GET', null, auth($alice));
check('alert email saved', $c === 200 && $r['alert_email'] === 'alice@example.com');
[$c, $r] = call('test_email', 'POST', null, auth($alice));
check('test email answers with a sent flag', $c === 200 && array_key_exists('sent', $r));
[$c] = call('test_email', 'GET', null, auth($alice));
check('test email by GET is refused (405)', $c === 405);

// --- scanning, devices, alerts, history
[$c] = call('scan', 'GET', null, auth($alice));
check('scan by GET is refused (405)', $c === 405);
[$c, $r] = call('scan', 'POST', null, auth($alice));
check('first scan (demo) finds devices', $c === 200 && $r['devices_found'] === 6);
[, $devices] = call('devices', 'GET', null, auth($alice));
check('devices are listed', count($devices) === 6);
$mac = $devices[0]['mac'];
[$c] = call('device_update', 'POST', ['mac' => $mac, 'status' => 'trusted'], auth($alice));
[, $devices] = call('devices', 'GET', null, auth($alice));
$trusted = array_values(array_filter($devices, fn($d) => $d['mac'] === $mac))[0]['status'] ?? '';
check('trusting a device works', $c === 200 && $trusted === 'trusted');
[$c] = call('device_update', 'POST', ['mac' => 'bad', 'status' => 'trusted'], auth($alice));
check('invalid MAC rejected (400)', $c === 400);
[$c] = call('device_update', 'POST', ['mac' => $mac, 'label' => 'My router'], auth($alice));
[, $devices] = call('devices', 'GET', null, auth($alice));
check('renaming a device works', $c === 200 && array_values(array_filter($devices, fn($d) => $d['mac'] === $mac))[0]['label'] === 'My router');
[, $h] = call('history', 'GET', null, auth($alice), ['mac' => $mac]);
check('device history has its first_seen event', count($h) >= 1 && $h[0]['event'] === 'first_seen');
[, $alerts] = call('alerts', 'GET', null, auth($alice));
check('baseline alert present after first scan', count($alerts) === 1 && $alerts[0]['type'] === 'baseline');
call('scan', 'POST', null, auth($alice)); call('scan', 'POST', null, auth($alice));   // 3rd demo scan: an intruder appears
[, $alerts] = call('alerts', 'GET', null, auth($alice));
check('new unknown device raises an alert', count(array_filter($alerts, fn($a) => $a['type'] === 'unknown_device')) === 1);
[$c] = call('alert_ack', 'POST', ['id' => 'all'], auth($alice));
[, $alerts] = call('alerts', 'GET', null, auth($alice));
check('dismissing all alerts empties the list', $c === 200 && count($alerts) === 0);
[$c] = call('device_delete', 'POST', ['mac' => $mac], auth($alice));
[, $devices] = call('devices', 'GET', null, auth($alice));
check('removing a device works', $c === 200 && count(array_filter($devices, fn($d) => $d['mac'] === $mac)) === 0);

// --- a second account sees nothing of the first
[, $r] = call('register', 'POST', ['email' => 'bob@example.com', 'password' => 'password-bob']);
$bob = $r['token'];
[, $devices] = call('devices', 'GET', null, auth($bob));
[, $alerts] = call('alerts', 'GET', null, auth($bob));
check("second account sees none of the first account's devices or alerts", $devices === [] && $alerts === []);
[$c] = call('devices', 'GET', null, auth($alice . 'x'));
check('a tampered token is refused (401)', $c === 401);

// --- home agent
[$c] = call('agent_poll', 'GET', null, ['X-Agent-Key: 00000000000000000000000000000000']);
check('agent with a wrong key is refused (401)', $c === 401);
[$c, $r] = call('agent_poll', 'GET', null, ["X-Agent-Key: $agentKey"]);
check('agent poll returns the saved range', $c === 200 && $r['subnet'] === '192.168.0.0/24' && $r['scan_requested'] === false);
[$c, $r] = call('agent_ingest', 'POST', ['duration_ms' => 1200, 'devices' => [['mac' => 'AA:BB:CC:00:00:01', 'ip' => '192.168.0.50', 'hostname' => 'agent-device', 'alive' => true]]], ["X-Agent-Key: $agentKey"]);
[, $devices] = call('devices', 'GET', null, auth($alice));
check('agent upload is stored in the right account', $c === 200 && count(array_filter($devices, fn($d) => $d['mac'] === 'AA:BB:CC:00:00:01')) === 1);
[, $r] = call('status', 'GET', null, auth($alice));
check('status shows the agent online', $r['agent_online'] === true);

// --- CORS preflight
[$c, , $hdr] = call('devices', 'OPTIONS', null, ['Origin: http://localhost:5173']);
check('CORS preflight answers 204 with the allowed origin', $c === 204 && in_array('Access-Control-Allow-Origin: http://localhost:5173', $hdr, true));
[, , $hdr] = call('devices', 'OPTIONS', null, ['Origin: https://evil.example']);
check('CORS preflight from a stranger gets no allow-origin header', count(array_filter($hdr, fn($h) => stripos($h, 'access-control-allow-origin') === 0)) === 0);

exit($fail ? 1 : 0);
