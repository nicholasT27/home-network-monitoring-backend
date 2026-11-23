<?php
// Starts the server on your own computer for testing:
//
//   php -S 127.0.0.1:8081 router.php
//
// It only lets people reach /index.php, so nobody can download the other files in this folder
// (settings, database files, code).
if (rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) === '/index.php') {
    require __DIR__ . '/index.php';
    return true;
}

http_response_code(404);
header('Content-Type: application/json');
echo '{"error":"Not found. This server only provides the API at /index.php"}';
return true;
