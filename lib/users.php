<?php

require_once __DIR__ . '/db.php';

// This function tidies an email address (removes spaces and capital letters).
function normalise_email(string $email): string
{
    return strtolower(trim($email));
}

// This function creates a new account.
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

// This function finds a user from their email address.
function user_by_email(PDO $pdo, string $email): ?array
{
    $st = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([normalise_email($email)]);
    return $st->fetch() ?: null;
}

// This function finds a user from their id number.
function user_by_id(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

// This function finds a user from the personal key of their home agent.
function user_by_agent_key(PDO $pdo, string $key): ?array
{
    if (!preg_match('/^[0-9a-f]{32}$/', $key)) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM users WHERE agent_key = ?');
    $st->execute([$key]);
    return $st->fetch() ?: null;
}

// This function checks the email and password and gives back the user if they are correct.
function login_user(PDO $pdo, string $email, string $password): ?array
{
    $user = user_by_email($pdo, $email);
    return ($user && password_verify($password, $user['password_hash'])) ? $user : null;
}

// This function gives back the network range to scan for this user.
function user_subnet(array $user, array $config): string
{
    return $user['subnet'] ?: $config['subnet'];
}

// This function checks and tidies the network range typed by the user.
function normalise_subnet(string $cidr): string
{
    $cidr = trim($cidr);
    cidr_hosts($cidr);
    [$ip, $prefix] = explode('/', $cidr);
    $mask = (~0 << (32 - (int) $prefix)) & 0xFFFFFFFF;
    return long2ip(ip2long($ip) & $mask) . '/' . (int) $prefix;
}
