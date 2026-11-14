<?php
// Diagnostic for setting up a new host. Off unless DEBUG=1 is set; turn it off again afterwards.
// It reports which database-related settings exist (names only, never values) and whether the database connects.

function route_health(array $config): void
{
    $names = [];
    foreach (array_merge(array_keys($_ENV), array_keys($_SERVER), array_keys(getenv() ?: [])) as $name) {
        if (is_string($name) && preg_match('/^(DB_|DATABASE|MYSQL|SMTP_|SCAN_MODE|AUTH_SECRET|ALLOWED_ORIGINS|ALLOW_REGISTRATION|DEBUG)/i', $name)) {
            $names[$name] = true;
        }
    }
    ksort($names);

    $db = ['driver' => str_starts_with($config['db_dsn'], 'mysql:') ? 'mysql' : 'sqlite', 'connects' => false];
    try {
        db_connect($config);
        $db['connects'] = true;
    } catch (Throwable $e) {
        $db['error'] = get_class($e) . ': ' . $e->getMessage();
    }

    json_out([
        'php' => PHP_VERSION,
        'extensions' => ['pdo_mysql' => extension_loaded('pdo_mysql'), 'pdo_sqlite' => extension_loaded('pdo_sqlite'),
                         'openssl' => extension_loaded('openssl'), 'curl' => extension_loaded('curl')],
        'scan_mode' => $config['scan_mode'],
        'database' => $db,
        'settings_present' => array_keys($names),
        'allowed_origins' => $config['allowed_origins'],
        'smtp_configured' => $config['smtp_host'] !== '',
    ]);
}
