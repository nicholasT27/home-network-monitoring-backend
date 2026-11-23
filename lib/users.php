<?php
// Accounts. Each household has one account, and everything saved (devices, alerts, history) is labelled with that account's id.

require_once __DIR__ . '/db.php';

function normalise_email(string $email): string
{
    return strtolower(trim($email));
}

function create_user(PDO $pdo, string $email, string $password): array
{
    $email = normalise_email($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        throw new InvalidArgumentException('Enter a valid email address');
    }
    if (strlen($password) < 8) {
        throw new InvalidArgumentException('Password must be at least 8 characters');
    }
    if (user_by_email($pdo, $email)) {
        throw new InvalidArgumentException('An account with this email already exists');
    }
    $pdo->prepare('INSERT INTO users (email, password_hash, agent_key, created_at) VALUES (?,?,?,?)')
        ->execute([$email, password_hash($password, PASSWORD_DEFAULT), bin2hex(random_bytes(16)), now()]);
    return user_by_id($pdo, (int) $pdo->lastInsertId());
}

function user_by_email(PDO $pdo, string $email): ?array
{
    $st = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([normalise_email($email)]);
    return $st->fetch() ?: null;
}

function user_by_id(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function user_by_agent_key(PDO $pdo, string $key): ?array
{
    if (!preg_match('/^[0-9a-f]{32}$/', $key)) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM users WHERE agent_key = ?');
    $st->execute([$key]);
    return $st->fetch() ?: null;
}

function login_user(PDO $pdo, string $email, string $password): ?array
{
    $user = user_by_email($pdo, $email);
    return ($user && password_verify($password, $user['password_hash'])) ? $user : null;
}

// The network range to scan for this user: the one they chose, or the server's default.
function user_subnet(array $user, array $config): string
{
    return $user['subnet'] ?: $config['subnet'];
}

// Checks a network range typed by the user (like 192.168.0.0/24) and tidies it, for example 192.168.0.124/24 becomes 192.168.0.0/24.
function normalise_subnet(string $cidr): string
{
    $cidr = trim($cidr);
    cidr_hosts($cidr);   // stops with an error if the range is not valid
    [$ip, $prefix] = explode('/', $cidr);
    $mask = (~0 << (32 - (int) $prefix)) & 0xFFFFFFFF;
    return long2ip(ip2long($ip) & $mask) . '/' . (int) $prefix;
}
