<?php
// This is the front door of the server. Every request from the dashboard or the home agent arrives here,
// for example index.php?action=devices.
// The code that answers each action is in the routes/ folder; shared helper code is in lib/.
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/monitor.php';
foreach (['auth', 'agent', 'account', 'devices', 'alerts', 'scan', 'health'] as $routeFile) {
    require_once __DIR__ . "/routes/$routeFile.php";
}

send_cors($config);
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// The actions that need a signed-in user. Each one has a function called route_<action> in the routes/ folder.
const USER_ACTIONS = ['status', 'devices', 'scan', 'alerts', 'alert_ack', 'device_update', 'device_delete', 'history', 'settings', 'test_email'];

try {
    // A setup check page. It only works when DEBUG=1 is set, and it works even if the database is broken.
    if ($action === 'health' && $config['debug']) {
        route_health($config);
    }

    $pdo = db_connect($config);

    // Anyone may create an account or sign in
    if ($method === 'POST' && in_array($action, ['register', 'login'], true)) {
        ("route_$action")($pdo, $config);
    }

    // The home agent proves who it is with its own personal key
    if (str_starts_with($action, 'agent_')) {
        route_agent($pdo, $config, $action, $method);
    }

    // Everything else needs the user to be signed in
    $uid = require_auth($config);
    $user = user_by_id($pdo, $uid) ?: fail('Not signed in', 401);
    if (!in_array($action, USER_ACTIONS, true)) {
        fail('Unknown action', 404);
    }
    ("route_$action")($pdo, $config, $user, $method);
} catch (PDOException $e) {
    error_log('network-monitor: ' . $e->getMessage());
    fail('Database error. Check that MySQL is running and the database tables were imported.', 500);
} catch (RuntimeException $e) {
    fail($e->getMessage(), 409);
} catch (Throwable $e) {
    error_log('network-monitor: ' . $e->getMessage());
    fail('Server error', 500);
}
