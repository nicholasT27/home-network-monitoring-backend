<?php
// Where the settings come from. Later ones win over earlier ones:
//   1. the default values written below
//   2. the .env file next to this file (when you run it on your own computer)
//   3. settings typed into a web host's control panel
// The names of all settings are listed in .env.example.

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
    // Different web hosts hand over their settings in different ways, so look in each place.
    foreach ([getenv($name), $_ENV[$name] ?? false, $_SERVER[$name] ?? false] as $v) {
        if (is_string($v) && $v !== '') {
            return $v;
        }
    }
    return ($file[$name] ?? '') !== '' ? $file[$name] : null;
};

$config = [
    // By default the data is saved in a single file (SQLite) that is created automatically; no database program is needed.
    // To use MySQL instead, set DB_HOST (with DB_NAME, DB_USER, DB_PASS) or DATABASE_URL; the tables are created automatically.
    'db_dsn'       => 'sqlite:' . __DIR__ . '/data/monitor.sqlite',
    'db_user'      => null,
    'db_pass'      => null,

    // The network range to scan if the user has not chosen one (at most 1024 addresses). Each user sets their own in the dashboard.
    'subnet'       => '192.168.1.0/24',
    // 'live'  = this server scans the network it is connected to (use when the server runs at home)
    // 'agent' = each user's home agent (agent.php) scans and sends the results here (use when the server is online)
    // 'demo'  = pretend devices, for trying the system without a real network
    'scan_mode'    => 'live',
    'agent_api_url' => '',           // used by agent.php: the server's address, e.g. https://api.example.com/index.php
    'agent_key'    => '',            // used by agent.php: the personal key shown in the dashboard
    'agent_interval_s' => 120,       // used by agent.php: scan at least this often (in seconds) (in seconds)
    'scan_target_s' => 120,

    // People create their own accounts in the dashboard. Set ALLOW_REGISTRATION=0 to stop new sign-ups.
    'allow_registration' => true,
    // DEBUG=1 turns on a setup check page (/index.php?action=health). It shows the names of settings, never their values. Keep it off normally.
    'debug'        => false,
    'auth_secret'  => 'change-this-long-random-string',
    'token_ttl_s'  => 43200,

    'allowed_origins' => ['http://localhost:5173', 'http://127.0.0.1:5173',
                          'http://localhost:4173', 'http://127.0.0.1:4173'],

    // Alert emails. Each user types the address that receives them in the dashboard; the emails are sent from one email account (for example Gmail).
    'alert_from'   => '',             // the "from" address; if empty, smtp_user is used
    'smtp_host'    => '',            // e.g. smtp.gmail.com; if empty, PHP's built-in mail() is used
    'smtp_port'    => 465,
    'smtp_secure'  => 'ssl',         // 'ssl' (secure) or 'none'
    'smtp_user'    => '',
    'smtp_pass'    => '',            // Gmail: a 16-character app password (not your normal password) (not your normal password)
];

$map = [
    'subnet' => 'SUBNET', 'scan_mode' => 'SCAN_MODE',
    'auth_secret' => 'AUTH_SECRET', 'alert_from' => 'ALERT_FROM',
    'smtp_host' => 'SMTP_HOST', 'smtp_port' => 'SMTP_PORT', 'smtp_secure' => 'SMTP_SECURE',
    'smtp_user' => 'SMTP_USER', 'smtp_pass' => 'SMTP_PASS',
    'agent_api_url' => 'AGENT_API_URL', 'agent_key' => 'AGENT_KEY', 'agent_interval_s' => 'AGENT_INTERVAL_S',
];
foreach ($map as $key => $name) {
    if (($v = $env($name)) !== null) {
        $config[$key] = $v;
    }
}
// Database connection: it can be given as one line, one web-style address, or separate values, under any of the names hosts use (see parse_db_settings in lib/db.php).
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

return $config;
