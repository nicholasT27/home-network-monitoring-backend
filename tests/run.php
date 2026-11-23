<?php
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require __DIR__ . '/../lib/monitor.php';
require __DIR__ . '/../lib/http.php';

$fail = 0;
// This function records whether one test passed or failed.
function check(string $name, bool $ok): void { global $fail; echo ($ok ? 'PASS' : 'FAIL') . "  $name\n"; if (!$ok) $fail++; }

$config = ['scan_mode' => 'live', 'alert_email' => '', 'alert_from' => 'x@y', 'smtp_host' => '', 'smtp_user' => '',
           'scan_target_s' => 120, 'auth_secret' => 's3cret', 'token_ttl_s' => 60];
$pdoOptions = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
if (getenv('TEST_DB_HOST') !== false) {
    $pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('TEST_DB_HOST'), getenv('TEST_DB_PORT') ?: '3306', getenv('TEST_DB_NAME')),
        getenv('TEST_DB_USER'), getenv('TEST_DB_PASS') ?: '', $pdoOptions);
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $pdo->exec("DROP TABLE `$t`");
    }
    mysql_ensure_schema($pdo);
    echo "(running against MySQL)\n";
} else {
    $pdo = new PDO('sqlite::memory:', null, null, $pdoOptions);
    $pdo->exec(file_get_contents(__DIR__ . '/../schema.sqlite.sql'));
}
$ms = fn() => (int) round(microtime(true) * 1000);
$dev = fn($mac, $ip, $host = null) => ['mac' => $mac, 'ip' => $ip, 'hostname' => $host];
$count = fn($sql) => (int) $pdo->query($sql)->fetchColumn();

$win = "Interface: 192.168.1.5 --- 0x4\n  192.168.1.1           3c-5a-b4-11-22-33     dynamic\n  192.168.1.255         ff-ff-ff-ff-ff-ff     static\n  224.0.0.22            01-00-5e-00-00-16     static\n  10.0.0.9              aa-bb-cc-dd-ee-ff     dynamic\n";
check('parse Windows arp -a', parse_arp($win, '192.168.1.0/24') === ['192.168.1.1' => '3C:5A:B4:11:22:33']);
$lin = "? (192.168.1.20) at 8c:77:12:44:55:66 [ether] on eth0\n? (192.168.1.21) at <incomplete> on eth0\n192.168.1.30 dev eth0 lladdr a4:83:e7:aa:bb:01 REACHABLE\n";
check('parse Linux arp / ip neigh', parse_arp($lin, '192.168.1.0/24') === ['192.168.1.20' => '8C:77:12:44:55:66', '192.168.1.30' => 'A4:83:E7:AA:BB:01']);
$mac = "? (192.168.1.7) at 0:1a:2b:3c:4d:5e on en0 ifscope [ethernet]\n";
check('parse macOS arp -an (short octets)', parse_arp($mac, '192.168.1.0/24') === ['192.168.1.7' => '00:1A:2B:3C:4D:5E']);

$pings = ['192.168.1.5' => true];
$arpNow = ['192.168.1.5' => 'AA:AA:AA:00:00:05', '192.168.1.9' => 'AA:AA:AA:00:00:09'];
check('without a cache flush only ping replies count as online', online_ips($pings, $arpNow, false) === ['192.168.1.5' => true]);
check('after a cache flush a device that only answered ARP counts as online', online_ips($pings, $arpNow, true) === ['192.168.1.5' => true, '192.168.1.9' => true]);
check('after a cache flush no ARP entry means offline', online_ips([], [], true) === []);
$t0 = microtime(true);
arp_probe([], 3, 1000);
arp_probe(['127.0.0.1'], 2, 1000);
check('ARP probe pass runs without error and ends quickly', microtime(true) - $t0 < 2);
check('cidr_hosts /24 has 254 hosts', count(cidr_hosts('192.168.1.0/24')) === 254);
check('private MAC detected', is_private_mac('DA:A1:19:00:00:01') && !is_private_mac('3C:5A:B4:11:22:33'));
check('subnet normalised to network address', normalise_subnet(' 192.168.0.124/24 ') === '192.168.0.0/24');
try { normalise_subnet('192.168.0.0/8'); $badSubnet = false; } catch (InvalidArgumentException $e) { $badSubnet = true; }
check('too-large or invalid subnet rejected', $badSubnet);

$envOf = fn(array $vars) => fn(string $n) => $vars[$n] ?? null;
check('no database settings means SQLite (null)', parse_db_settings($envOf([])) === null);
$d = parse_db_settings($envOf(['DB_HOST' => 'db.example.com', 'DB_NAME' => 'n', 'DB_USER' => 'u', 'DB_PASS' => 'p']));
check('DB_HOST / DB_NAME / DB_USER / DB_PASS', $d['dsn'] === 'mysql:host=db.example.com;port=3306;dbname=n;charset=utf8mb4' && $d['user'] === 'u' && $d['pass'] === 'p');
$d = parse_db_settings($envOf(['DB_HOST' => 'h', 'DB_PORT' => '3307', 'DB_DATABASE' => 'n2', 'DB_USERNAME' => 'u2', 'DB_PASSWORD' => 'p2']));
check('Laravel-style DB_DATABASE / DB_USERNAME / DB_PASSWORD / DB_PORT', $d['dsn'] === 'mysql:host=h;port=3307;dbname=n2;charset=utf8mb4' && $d['user'] === 'u2' && $d['pass'] === 'p2');
$d = parse_db_settings($envOf(['MYSQL_HOST' => 'mh', 'MYSQL_DATABASE' => 'mn', 'MYSQL_USER' => 'mu', 'MYSQL_PASSWORD' => 'mp']));
check('MYSQL_* names', $d['dsn'] === 'mysql:host=mh;port=3306;dbname=mn;charset=utf8mb4' && $d['user'] === 'mu' && $d['pass'] === 'mp');
$d = parse_db_settings($envOf(['DATABASE_URL' => 'mysql://us%40er:p%3Aw@host.example.com:3310/app_db']));
check('DATABASE_URL (with an encoded user and password)', $d['dsn'] === 'mysql:host=host.example.com;port=3310;dbname=app_db;charset=utf8mb4' && $d['user'] === 'us@er' && $d['pass'] === 'p:w');
check('DB_DSN wins over the other names', parse_db_settings($envOf(['DB_DSN' => 'sqlite::memory:', 'DB_HOST' => 'x']))['dsn'] === 'sqlite::memory:');
$st = schema_statements(file_get_contents(__DIR__ . '/../schema.shared-hosting.sql'));
check('schema: six idempotent CREATE TABLE statements, no DROP', count($st) === 6
    && count(array_filter($st, fn($x) => str_starts_with($x, 'CREATE TABLE IF NOT EXISTS '))) === 6
    && count(array_filter($st, fn($x) => stripos($x, 'DROP') !== false)) === 0);

$alice = create_user($pdo, 'Alice@Example.com', 'password-alice');
$bob = create_user($pdo, 'bob@example.com', 'password-bob');
check('accounts get distinct agent keys', preg_match('/^[0-9a-f]{32}$/', $alice['agent_key']) && $alice['agent_key'] !== $bob['agent_key']);
check('email is normalised and login works', login_user($pdo, 'alice@example.com', 'password-alice')['id'] === $alice['id']);
check('wrong password rejected', login_user($pdo, 'alice@example.com', 'nope-nope') === null);
check('agent key finds its owner only', user_by_agent_key($pdo, $bob['agent_key'])['id'] === $bob['id'] && user_by_agent_key($pdo, 'zz') === null);
foreach ([['alice@example.com', 'another-pass', 'duplicate email'], ['not-an-email', 'password1', 'bad email'], ['c@example.com', 'short', 'short password']] as [$e, $p, $why]) {
    try { create_user($pdo, $e, $p); $rejected = false; } catch (InvalidArgumentException $ex) { $rejected = true; }
    check("sign-up rejected: $why", $rejected);
}
$t = make_token($config, (int) $alice['id']);
check('token returns its user id', token_user($config, $t) === (int) $alice['id']);
check('tampered token rejected', token_user($config, $t . 'x') === null && token_user(array_merge($config, ['auth_secret' => 'other']), $t) === null);
check('expired token rejected', token_user(array_merge($config, ['token_ttl_s' => -5]), make_token(array_merge($config, ['token_ttl_s' => -5]), 1)) === null);

$uid = (int) $alice['id'];
$bid = (int) $bob['id'];

$r = process_scan($pdo, $config, $uid, [$dev('3C:5A:B4:11:22:33', '192.168.1.1', 'router.lan'), $dev('A4:83:E7:AA:BB:01', '192.168.1.12', 'alex-macbook')], 'manual', $ms());
check('first scan is a baseline: unknown devices, one summary alert', $r['new_unknown'] === 2 && $count("SELECT COUNT(*) FROM devices WHERE user_id=$uid AND status='unknown'") === 2
    && $count("SELECT COUNT(*) FROM alerts WHERE type='baseline'") === 1 && $count('SELECT COUNT(*) FROM alerts') === 1);
$pdo->exec("UPDATE devices SET status='trusted' WHERE user_id=$uid");

$r = process_scan($pdo, $config, $uid, [$dev('3C:5A:B4:11:22:33', '192.168.1.1', 'router.lan'), $dev('A4:83:E7:AA:BB:01', '192.168.1.99', 'alex-macbook')], 'manual', $ms());
check('rescan does not re-alert', $r['new_unknown'] === 0 && $count('SELECT COUNT(*) FROM alerts') === 1);
check('IP change recorded, not alerted', $count("SELECT COUNT(*) FROM connection_history WHERE event='ip_changed'") === 1
    && $pdo->query("SELECT ip FROM devices WHERE mac='A4:83:E7:AA:BB:01'")->fetchColumn() === '192.168.1.99');

process_scan($pdo, $config, $uid, [$dev('3C:5A:B4:11:22:33', '192.168.1.1', 'router.lan')], 'manual', $ms());
check('missing device marked offline', $count("SELECT online FROM devices WHERE mac='A4:83:E7:AA:BB:01'") === 0);
process_scan($pdo, $config, $uid, [$dev('3C:5A:B4:11:22:33', '192.168.1.1', 'router.lan'), $dev('A4:83:E7:AA:BB:01', '192.168.1.99', 'alex-macbook')], 'manual', $ms());
check('rejoin logged', $count("SELECT COUNT(*) FROM connection_history WHERE event='joined'") === 1);

$pdo->exec("INSERT INTO devices (user_id, mac, ip, hostname, vendor, status, online, first_seen, last_seen) VALUES ($uid,'DA:A1:19:00:00:00','192.168.1.40','alex-iphone','Private (randomised) MAC','trusted',0,'2026-01-01 00:00:00','2026-01-01 00:00:00')");
$alertsBefore = $count('SELECT COUNT(*) FROM alerts');
$base = [$dev('3C:5A:B4:11:22:33', '192.168.1.1', 'router.lan'), $dev('A4:83:E7:AA:BB:01', '192.168.1.99', 'alex-macbook')];
process_scan($pdo, $config, $uid, array_merge($base, [$dev('DA:A1:19:00:00:01', '192.168.1.41', 'Alex-iPhone')]), 'manual', $ms());
check('rotated MAC adopted without alert', $count('SELECT COUNT(*) FROM alerts') === $alertsBefore
    && $count("SELECT COUNT(*) FROM devices WHERE mac='DA:A1:19:00:00:01' AND status='trusted'") === 1
    && $count("SELECT COUNT(*) FROM devices WHERE mac='DA:A1:19:00:00:00'") === 0
    && $count("SELECT COUNT(*) FROM connection_history WHERE event='mac_rotated'") === 1);

process_scan($pdo, $config, $uid, array_merge($base, [$dev('DA:A1:19:00:00:01', '192.168.1.41', 'alex-iphone'), $dev('5E:CF:7F:0B:AD:01', '192.168.1.77')]), 'manual', $ms());
check('new device after baseline raises unknown_device alert', $count("SELECT COUNT(*) FROM alerts WHERE type='unknown_device'") === 1);

process_scan($pdo, $config, $uid, array_merge($base, [$dev('DA:A1:19:00:00:01', '192.168.1.41', 'alex-iphone'), $dev('DA:A1:19:00:00:77', '192.168.1.60', 'alex-iphone')]), 'manual', $ms());
check('hostname clone while original online is unknown', $count("SELECT COUNT(*) FROM devices WHERE mac='DA:A1:19:00:00:77' AND status='unknown'") === 1);

process_scan($pdo, $config, $uid, [$dev('3C:5A:B4:11:22:33', '192.168.1.1', 'evil-laptop'), $dev('A4:83:E7:AA:BB:01', '192.168.1.99', 'alex-macbook')], 'manual', $ms());
check('trusted device with changed hostname flagged suspicious', $count("SELECT suspicious FROM devices WHERE mac='3C:5A:B4:11:22:33'") === 1
    && $count("SELECT COUNT(*) FROM alerts WHERE type='suspicious_device'") === 1);
process_scan($pdo, $config, $uid, [$dev('3C:5A:B4:11:22:33', '192.168.1.1', 'evil-laptop')], 'manual', $ms());
check('suspicious alert not repeated', $count("SELECT COUNT(*) FROM alerts WHERE type='suspicious_device'") === 1);

$pdo->exec("UPDATE devices SET online = 1 WHERE mac='A4:83:E7:AA:BB:01'");
process_scan($pdo, $config, $uid, [['mac' => 'A4:83:E7:AA:BB:01', 'ip' => '192.168.1.99', 'hostname' => 'alex-macbook', 'alive' => false]], 'manual', $ms());
check('device with only a stale ARP entry goes offline', $count("SELECT online FROM devices WHERE mac='A4:83:E7:AA:BB:01'") === 0);
$before = $count('SELECT COUNT(*) FROM alerts');
process_scan($pdo, $config, $uid, [['mac' => '6A:11:22:33:44:55', 'ip' => '192.168.1.90', 'hostname' => null, 'alive' => false]], 'manual', $ms());
check('ARP-only unknown device is still detected (alert) but not shown online',
    $count("SELECT online FROM devices WHERE mac='6A:11:22:33:44:55'") === 0 && $count('SELECT COUNT(*) FROM alerts') === $before + 1);

check("Bob sees none of Alice's data", $count("SELECT COUNT(*) FROM devices WHERE user_id=$bid") === 0
    && $count("SELECT COUNT(*) FROM alerts WHERE user_id=$bid") === 0 && $count("SELECT COUNT(*) FROM scans WHERE user_id=$bid") === 0);
process_scan($pdo, $config, $bid, [$dev('3C:5A:B4:11:22:33', '192.168.0.1', 'router.lan')], 'manual', $ms());
check("Bob's first device is unknown while Alice's identical MAC stays trusted",
    $pdo->query("SELECT status FROM devices WHERE user_id=$bid AND mac='3C:5A:B4:11:22:33'")->fetchColumn() === 'unknown'
    && $pdo->query("SELECT status FROM devices WHERE user_id=$uid AND mac='3C:5A:B4:11:22:33'")->fetchColumn() === 'trusted');

$demoUser = array_merge(user_by_id($pdo, $uid), ['subnet' => '192.168.1.0/24']);
$live = array_merge($config, ['scan_mode' => 'live']);
check('no network range typed: nothing to scan in live mode', user_subnet(['subnet' => null]) === '');
check('network range typed: it is used', user_subnet(['subnet' => '10.0.0.0/24']) === '10.0.0.0/24');
try { run_scan($pdo, $live, array_merge($demoUser, ['subnet' => null])); $noRange = false; } catch (RuntimeException $e) { $noRange = true; }
check('scan refuses to run before a network range is entered', $noRange);
$demo = array_merge($config, ['scan_mode' => 'demo']);
check('scan runs, then lock is released', is_array(run_scan($pdo, $demo, $demoUser)) && $count("SELECT COUNT(*) FROM settings WHERE k LIKE 'scan_lock%'") === 0);
check('second scan runs after the first finished', is_array(run_scan($pdo, $demo, $demoUser)));
$pdo->exec("INSERT INTO settings (k, v) VALUES ('scan_lock_$uid', '" . time() . "')");
try { run_scan($pdo, $demo, $demoUser); $blocked = false; } catch (RuntimeException $e) { $blocked = true; }
check('concurrent scan for the same user is rejected', $blocked);
check("another user's scan is not blocked by Alice's lock", is_array(run_scan($pdo, $demo, array_merge(user_by_id($pdo, $bid), ['subnet' => '192.168.1.0/24']))));
$pdo->exec("UPDATE settings SET v = '" . (time() - 1000) . "' WHERE k='scan_lock_$uid'");
check('stale lock is ignored', is_array(run_scan($pdo, $demo, $demoUser)));

$carol = create_user($pdo, 'carol@example.com', 'password-carol');
$payload = ['duration_ms' => 4200, 'devices' => [
    ['mac' => 'aa-bb-cc-dd-ee-01', 'ip' => '192.168.1.50', 'hostname' => 'agent-laptop', 'alive' => true],
    ['mac' => 'not-a-mac', 'ip' => '192.168.1.51', 'alive' => true],
    ['mac' => 'AA:BB:CC:DD:EE:02', 'ip' => '999.1.1.1', 'alive' => true],
    ['mac' => 'AA:BB:CC:DD:EE:03', 'ip' => '192.168.1.52', 'hostname' => '', 'alive' => false],
]];
$pdo->prepare('UPDATE users SET scan_requested = ? WHERE id = ?')->execute([time(), $carol['id']]);
$r = ingest_agent_scan($pdo, $config, $carol, $payload);
check('agent ingest keeps only valid devices', $r['devices_found'] === 2 && $r['duration_ms'] >= 4200
    && $count("SELECT COUNT(*) FROM devices WHERE user_id={$carol['id']} AND mac='AA:BB:CC:DD:EE:01' AND online=1") === 1
    && $count("SELECT COUNT(*) FROM devices WHERE user_id={$carol['id']} AND mac='AA:BB:CC:DD:EE:03' AND online=0") === 1
    && $count("SELECT COUNT(*) FROM scans WHERE user_id={$carol['id']} AND source='agent'") === 1);
check('agent ingest clears the scan request and stays in its own account', (int) user_by_id($pdo, (int) $carol['id'])['scan_requested'] === 0
    && $count("SELECT COUNT(*) FROM devices WHERE mac='AA:BB:CC:DD:EE:01' AND user_id <> {$carol['id']}") === 0);

$port = 20000 + random_int(0, 9000); $outFile = sys_get_temp_dir() . '/netmon_smtp_test.txt'; @unlink($outFile);
$srvProc = proc_open([PHP_BINARY, __DIR__ . '/fake_smtp.php', (string) $port, $outFile], [], $srvPipes);
usleep(500000);
$smtpCfg = array_merge($config, ['smtp_host' => '127.0.0.1', 'smtp_port' => $port, 'smtp_secure' => 'none', 'smtp_user' => 'me@example.com', 'smtp_pass' => 'pw', 'alert_from' => '']);
$sent = send_alert_email($smtpCfg, 'teacher@example.com', 'Network alert: test', "Line one\n.dot line\nIP: 1.2.3.4");
proc_close($srvProc);
$log = (string) @file_get_contents($outFile);
check('SMTP alert delivered to the fake server', $sent && str_contains($log, 'RCPT TO:<teacher@example.com>')
    && str_contains($log, 'Subject: Network alert: test') && str_contains($log, 'IP: 1.2.3.4') && str_contains($log, '..dot line'));
check('invalid recipient is not sent', !send_alert_email($smtpCfg, 'not-an-email', 's', 'b'));

$run = proc_open([PHP_BINARY, '-r', 'echo json_encode(require "' . __DIR__ . '/../config.php");'],
    [1 => ['pipe', 'w']], $pp, null, ['SCAN_MODE' => 'agent', 'DB_HOST' => 'db.example.com', 'DB_NAME' => 'x',
    'SMTP_HOST' => 'smtp.gmail.com', 'SMTP_PORT' => '465', 'ALLOWED_ORIGINS' => 'https://app.example.com', 'ALLOW_REGISTRATION' => '0']);
$cfg = json_decode(stream_get_contents($pp[1]), true); proc_close($run);
check('config reads environment variables', ($cfg['scan_mode'] === 'agent' && $cfg['smtp_port'] === 465
    && str_contains($cfg['db_dsn'], 'host=db.example.com') && in_array('https://app.example.com', $cfg['allowed_origins'], true) && $cfg['allow_registration'] === false));

check('scans recorded', $count('SELECT COUNT(*) FROM scans') >= 9);
exit($fail ? 1 : 0);
