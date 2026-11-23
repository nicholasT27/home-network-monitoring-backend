<?php
// Open routes: create an account and sign in. Both give back a signed login token.

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

function route_login(PDO $pdo, array $config): void
{
    $b = json_body();
    $user = login_user($pdo, (string) ($b['email'] ?? ''), (string) ($b['password'] ?? ''));
    if (!$user) {
        sleep(1);   // a one-second pause makes guessing passwords slow
        fail('Incorrect email or password', 401);
    }
    json_out(['token' => make_token($config, (int) $user['id'])]);
}
