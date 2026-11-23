<?php
// Local development server for the JSON API:
//
//   php -S 127.0.0.1:8081 router.php
//
// It exposes ONLY /index.php, so nothing else in this folder (settings, SQL files, source) can be downloaded.
if (rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) === '/index.php') {
    require __DIR__ . '/index.php';
    return true;
}

http_response_code(404);
header('Content-Type: application/json');
echo '{"error":"Not found. This server only provides the API at /index.php"}';
return true;
