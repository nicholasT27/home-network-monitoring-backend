<?php

// This function creates a new account and signs the user in.
function route_register(PDO $pdo, array $config): void
{
    if (!$config['allow_registration']) fail('Sign-ups are closed', 403);
    $b = json_body();
    try {
        $user = create_user($pdo, (string) ($b['email'] ?? ''), (string) ($b['password'] ?? ''));
    } catch (InvalidArgumentException $e) {
        fail($e->getMessage());
    }
    json_out(['token' => make_token($config, (int) $user['id'])]);
}

// This function signs the user in.
function route_login(PDO $pdo, array $config): void
{
    $b = json_body();
    $user = login_user($pdo, (string) ($b['email'] ?? ''), (string) ($b['password'] ?? ''));
    if (!$user) {
        sleep(1);
        fail('Incorrect email or password', 401);
    }
    json_out(['token' => make_token($config, (int) $user['id'])]);
}
