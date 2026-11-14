<?php
// Settings come from, lowest to highest priority:
//   1. the defaults below
//   2. backend/.env (local development)        3. real environment variables (cloud host settings)
//   4. backend/config.local.php (optional, local only)
// See backend/.env.example for the variable names.

function load_env_file(string $path): array
{
    $vars = [];
    if (!is_file($path)) {
        return $vars;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $vars[trim($k)] = trim(trim($v), "\"'");
    }
    return $vars;
}

$file = load_env_file(__DIR__ . '/.env');
require_once __DIR__ . '/lib/db.php';
$env = function (string $name) use ($file): ?string {
    // Hosts expose environment variables in different ways: getenv(), $_ENV or $_SERVER.
    foreach ([getenv($name), $_ENV[$name] ?? false, $_SERVER[$name] ?? false] as $v) {
        if (is_string($v) && $v !== '') {
            return $v;
        }
    }
    return ($file[$name] ?? '') !== '' ? $file[$name] : null;
};

$config = [
    // Default: a SQLite file, created automatically on first use (no database server needed).
    // For MySQL set DB_HOST (and DB_NAME, DB_USER, DB_PASS), or DATABASE_URL, or DB_DSN; the tables are created automatically.
    'db_dsn'       => 'sqlite:' . __DIR__ . '/data/monitor.sqlite',
    'db_user'      => null,
    'db_pass'      => null,

    // Network range to scan (CIDR, at most 1024 hosts). A cloud server can only scan its own network,
    // so use scan_mode "demo" when deployed away from the home network.
    'subnet'       => '192.168.1.0/24',   // default range; each user can set their own in the dashboard
    // 'live'  = this server scans its own network (run the backend at home)
    // 'agent' = each user's home agent (agent.php) scans and sends results here (use this when deployed to the cloud)
    // 'demo'  = simulated devices
    'scan_mode'    => 'live',
    'agent_api_url' => '',           // used by agent.php: e.g. https://api.example.com/index.php
    'agent_key'    => '',            // used by agent.php: the personal key shown in the dashboard
    'agent_interval_s' => 120,       // used by agent.php: scan at least this often
    'scan_target_s' => 120,

    // People create their own accounts in the dashboard. Set ALLOW_REGISTRATION=0 to close sign-ups.
    'allow_registration' => true,
    // DEBUG=1 switches on the /index.php?action=health diagnostic (names of settings, never their values). Keep it off.
    'debug'        => false,
    'auth_secret'  => 'change-this-long-random-string',
    'token_ttl_s'  => 43200,

    'allowed_origins' => ['http://localhost:5173', 'http://127.0.0.1:5173',
                          'http://localhost:4173', 'http://127.0.0.1:4173'],

    // Email alerts. The recipient is entered in the dashboard; the sender is an SMTP account (e.g. Gmail).
    'alert_email'  => '',
    'alert_from'   => '',
    'smtp_host'    => '',            // e.g. smtp.gmail.com; empty = fall back to PHP mail()
    'smtp_port'    => 465,
    'smtp_secure'  => 'ssl',         // 'ssl' or 'none'
    'smtp_user'    => '',
    'smtp_pass'    => '',            // Gmail: a 16-character app password
];

$map = [
    'subnet' => 'SUBNET', 'scan_mode' => 'SCAN_MODE',
    'auth_secret' => 'AUTH_SECRET', 'alert_email' => 'ALERT_EMAIL', 'alert_from' => 'ALERT_FROM',
    'smtp_host' => 'SMTP_HOST', 'smtp_port' => 'SMTP_PORT', 'smtp_secure' => 'SMTP_SECURE',
    'smtp_user' => 'SMTP_USER', 'smtp_pass' => 'SMTP_PASS',
    'agent_api_url' => 'AGENT_API_URL', 'agent_key' => 'AGENT_KEY', 'agent_interval_s' => 'AGENT_INTERVAL_S',
];
foreach ($map as $key => $name) {
    if (($v = $env($name)) !== null) {
        $config[$key] = $v;
    }
}
// Database: a DSN, a URL, or separate values under any of the names hosts use (see parse_db_settings in lib/db.php).
if (($db = parse_db_settings($env)) !== null) {
    $config['db_dsn'] = $db['dsn'];
    $config['db_user'] = $db['user'];
    $config['db_pass'] = $db['pass'];
}
if (($origins = $env('ALLOWED_ORIGINS')) !== null) {
    $config['allowed_origins'] = array_merge($config['allowed_origins'], array_map('trim', explode(',', $origins)));
}
$config['smtp_port'] = (int) $config['smtp_port'];
$config['debug'] = filter_var($env('DEBUG') ?? '0', FILTER_VALIDATE_BOOLEAN);
if (($reg = $env('ALLOW_REGISTRATION')) !== null) {
    $config['allow_registration'] = filter_var($reg, FILTER_VALIDATE_BOOLEAN);
}
$config['agent_interval_s'] = max(30, (int) $config['agent_interval_s']);

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_merge($config, require $local);
}
return $config;
