<?php

//
//   php -S 127.0.0.1:8081 router.php
//
// It exposes ONLY the JSON API at /index.php: nothing else in this folder (config, SQL files, source) can be
// downloaded. If a built dashboard exists next to this project (../frontend/dist, as in the all-in-one
// repository) it is served too; on its own, this repository serves the API only.
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if ($path === '/index.php') {
    require __DIR__ . '/index.php';
    return true;
}

$dist = realpath(__DIR__ . '/../frontend/dist');
if ($dist !== false) {
    $file = realpath($dist . $path);
    if ($path !== '/' && $file !== false && str_starts_with($file, $dist . DIRECTORY_SEPARATOR) && is_file($file)) {
        return false;   // let the built-in server send the static file
    }
    header('Content-Type: text/html; charset=utf-8');
    readfile($dist . '/index.html');
    return true;
}

http_response_code(404);
header('Content-Type: application/json');
echo '{"error":"Not found. This server only provides the API at /index.php"}';
return true;
