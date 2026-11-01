<?php

declare(strict_types=1);

// Router for `php -S` used by RedirectIsNotFollowedTest: every request is logged (path + X-Beacon-Auth +
// Authorization) to the file named by BEACON_REDIRECT_LOG; requests under /api/ are redirected to the same server
// under another host name (localhost instead of 127.0.0.1), any other path answers 202.
$log  = (string) getenv('BEACON_REDIRECT_LOG');
$port = (string) $_SERVER['SERVER_PORT'];
$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], \PHP_URL_PATH);

file_put_contents($log, json_encode([
    'host'          => $_SERVER['HTTP_HOST'] ?? '',
    'path'          => $path,
    'x_beacon_auth' => $_SERVER['HTTP_X_BEACON_AUTH'] ?? null,
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
]) . "\n", \FILE_APPEND);

if (str_starts_with($path, '/api/')) {
    header('Location: http://localhost:' . $port . '/collect' . $path, true, 307);

    return true;
}

http_response_code(202);

return true;
