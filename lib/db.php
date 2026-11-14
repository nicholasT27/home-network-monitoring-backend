<?php
// Database access. SQLite (a file, created automatically) or MySQL (tables created automatically on first use).

// Works out the database connection from environment settings. Accepts the names used by different hosts:
//   DB_DSN, or a URL (DATABASE_URL / DB_URL / MYSQL_URL = mysql://user:pass@host:port/name),
//   or separate values (DB_HOST / MYSQL_HOST, DB_PORT, DB_NAME / DB_DATABASE / MYSQL_DATABASE,
//   DB_USER / DB_USERNAME / MYSQL_USER, DB_PASS / DB_PASSWORD / MYSQL_PASSWORD).
// $env is a function that returns a setting's value, or null. Returns ['dsn', 'user', 'pass'], or null if none is set.
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

// Turns the bundled MySQL schema into statements that are safe to run on a database that may already hold
// tables: comments and DROP TABLE lines are removed and CREATE TABLE becomes CREATE TABLE IF NOT EXISTS.
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

// First run on MySQL: create the tables, so no phpMyAdmin import is needed.
function mysql_ensure_schema(PDO $pdo): void
{
    if ($pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn() !== false) {
        return;
    }
    foreach (schema_statements((string) file_get_contents(__DIR__ . '/../schema.shared-hosting.sql')) as $statement) {
        $pdo->exec($statement);
    }
}

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
        // First run: create the tables from the bundled schema.
        if ((int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn() === 0) {
            $pdo->exec(file_get_contents(__DIR__ . '/../schema.sqlite.sql'));
        }
    } elseif (str_starts_with($dsn, 'mysql:')) {
        mysql_ensure_schema($pdo);
    }
    return $pdo;
}

// Makes sure the SQLite file's folder exists and is not served over the web.
function sqlite_prepare_file(string $path): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
}

function now(): string
{
    return gmdate('Y-m-d H:i:s');   // all stored times are UTC; the dashboard converts to local time
}

function get_setting(PDO $pdo, string $key, string $default = ''): string
{
    $st = $pdo->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string) $v;
}

function set_setting(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare('DELETE FROM settings WHERE k = ?')->execute([$key]);
    $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?)')->execute([$key, $value]);
}
