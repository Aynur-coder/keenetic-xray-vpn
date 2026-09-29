<?php
// Local preview of the web UI without a router or Docker:
//
//   php -S 127.0.0.1:8099 tests/ui/dev-server.php
//
// Serves static files from src/web. Requests to /api.php answer with
// tests/ui/fixtures/<action>.json (200), or {} when there is no fixture.
// Switches (create/delete the file while the server runs):
//   tests/ui/.offline  — api.php answers 503 (router unreachable); if the file
//                        contains "hang", it stalls 15 s instead (client timeout path)
//   tests/ui/.locked   — actions other than login/logout/get_onboarding_status/
//                        get_features answer 401 {error:auth_required};
//                        login with password "test" removes the lock; password
//                        "locked" answers 429 {too_many_attempts, retry_after: 125}
//   tests/ui/.scenario   — a name, e.g. "stopped": fixtures/<name>/<action>.json is
//                          served instead of fixtures/<action>.json when it exists

$root = realpath(__DIR__ . '/../../src/web');
$uiDir = __DIR__;
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

if ($path === '/api.php') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    if (file_exists("$uiDir/.offline")) {
        if (strpos((string)file_get_contents("$uiDir/.offline"), 'hang') !== false) sleep(15);
        http_response_code(503);
        echo json_encode(['error' => 'offline']);
        return true;
    }

    if ($action === 'login') {
        if (($_POST['password'] ?? '') === 'locked') {
            http_response_code(429);
            echo json_encode(['error' => 'too_many_attempts', 'retry_after' => 125]);
        } elseif (($_POST['password'] ?? '') === 'test') {
            @unlink("$uiDir/.locked");
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['error' => 'invalid_credentials', 'remaining' => 4]);
        }
        return true;
    }

    $public = ['logout', 'get_onboarding_status', 'get_features'];
    if (file_exists("$uiDir/.locked") && !in_array($action, $public, true)) {
        http_response_code(401);
        echo json_encode(['error' => 'auth_required']);
        return true;
    }

    $name = preg_replace('/[^a-z0-9_]/', '', $action) . '.json';
    $fixture = "$uiDir/fixtures/$name";
    $scenario = file_exists("$uiDir/.scenario")
        ? trim((string)file_get_contents("$uiDir/.scenario")) : '';
    $scenario = preg_replace('/[^a-z0-9_-]/', '', $scenario);
    if ($scenario !== '' && file_exists("$uiDir/fixtures/$scenario/$name")) {
        $fixture = "$uiDir/fixtures/$scenario/$name";
    }
    echo file_exists($fixture) ? file_get_contents($fixture) : '{}';
    return true;
}

$file = realpath($root . ($path === '/' ? '/index.php' : $path));
if ($file === false || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) {
    http_response_code(404);
    echo 'Not found';
    return true;
}

$types = [
    'php' => 'text/html; charset=utf-8', 'html' => 'text/html; charset=utf-8',
    'js' => 'text/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8',
    'json' => 'application/json', 'svg' => 'image/svg+xml', 'png' => 'image/png',
];
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
// Same as the router's lighttpd rule (91-shadowsocks.conf): always revalidate UI files.
header('Cache-Control: no-cache');
if ($ext === 'php') {
    include $file;
} else {
    readfile($file);
}
return true;
