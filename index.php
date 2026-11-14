<?php
// JSON API entry point:  index.php?action=<name>
// Handlers live in routes/, shared code in lib/.
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

// Actions for a signed-in user. Each handler is route_<action>(PDO, config, user, method).
const USER_ACTIONS = ['status', 'devices', 'scan', 'alerts', 'alert_ack', 'device_update', 'device_delete', 'history', 'settings', 'test_email'];

try {
    // Setup diagnostic (only when DEBUG=1); it must work even when the database does not
    if ($action === 'health' && $config['debug']) {
        route_health($config);
    }

    $pdo = db_connect($config);

    // Public: create an account / sign in
    if ($method === 'POST' && in_array($action, ['register', 'login'], true)) {
        ("route_$action")($pdo, $config);
    }

    // Home agent: authenticated by its own key
    if (str_starts_with($action, 'agent_')) {
        route_agent($pdo, $config, $action, $method);
    }

    // Everything else needs a signed-in user
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
