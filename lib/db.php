<?php

// This function helps the application connect to the MySQL database.
function parse_db_settings(callable $env): ?array
{
    $first = function (array $names) use ($env): ?string {
        foreach ($names as $n) {
            if (($v = $env($n)) !== null) {
                return $v;
            }
        }
        return null;
    };
    if (($dsn = $env('DB_DSN')) !== null) {
        return ['dsn' => $dsn, 'user' => $env('DB_USER'), 'pass' => $env('DB_PASS')];
    }
    if (($url = $first(['DATABASE_URL', 'DB_URL', 'MYSQL_URL'])) !== null && ($u = parse_url($url)) && !empty($u['host'])) {
        return [
            'dsn' => sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $u['host'], $u['port'] ?? 3306, ltrim($u['path'] ?? '', '/')),
            'user' => isset($u['user']) ? rawurldecode($u['user']) : 'root',
            'pass' => isset($u['pass']) ? rawurldecode($u['pass']) : '',
        ];
    }
    if (($host = $first(['DB_HOST', 'MYSQL_HOST'])) !== null) {
        return [
            'dsn' => sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, (int) ($first(['DB_PORT', 'MYSQL_PORT']) ?? 3306),
                $first(['DB_NAME', 'DB_DATABASE', 'MYSQL_DATABASE']) ?? 'home_network_monitor'),
            'user' => $first(['DB_USER', 'DB_USERNAME', 'MYSQL_USER']) ?? 'root',
            'pass' => $first(['DB_PASS', 'DB_PASSWORD', 'MYSQL_PASSWORD']) ?? '',
        ];
    }
    return null;
}

// This function tidies up the raw SQL file, so the existing data is not deleted.
function schema_statements(string $sql): array
{
    $sql = preg_replace('/^--.*$/m', '', $sql);
    $out = [];
    foreach (explode(';', $sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '' || stripos($stmt, 'DROP TABLE') === 0) {
            continue;
        }
        $out[] = preg_replace('/^CREATE TABLE\s+(?!IF NOT EXISTS)/i', 'CREATE TABLE IF NOT EXISTS ', $stmt);
    }
    return $out;
}

// This function creates the database tables the first time, so nobody has to do it by hand.
function mysql_ensure_schema(PDO $pdo): void
{
    if ($pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn() !== false) {
        return;
    }
    foreach (schema_statements((string) file_get_contents(__DIR__ . '/../schema.shared-hosting.sql')) as $statement) {
        $pdo->exec($statement);
    }
}

// This function connects to MySQL (SQLite is only used for testing).
function db_connect(array $config): PDO
{
    $dsn = $config['db_dsn'];
    $isSqlite = str_starts_with($dsn, 'sqlite:');
    if ($isSqlite) {
        sqlite_prepare_file(substr($dsn, 7));
    }
    $pdo = new PDO($dsn, $config['db_user'] ?? null, $config['db_pass'] ?? null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    if ($isSqlite) {
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        if ((int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn() === 0) {
            $pdo->exec(file_get_contents(__DIR__ . '/../schema.sqlite.sql'));
        }
    } elseif (str_starts_with($dsn, 'mysql:')) {
        mysql_ensure_schema($pdo);
    }
    return $pdo;
}

// This function prepares the folder for the SQLite test file.
function sqlite_prepare_file(string $path): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
}

// This function gives back the current time in UTC (world time).
function now(): string
{
    return gmdate('Y-m-d H:i:s');
}
