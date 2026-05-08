<?php
declare(strict_types=1);

require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';

// ── CORS — handled globally by the front controller ───────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Routing ───────────────────────────────────────────────────────────────────
$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$path = '/' . ltrim(substr($uri, strlen($base)), '/');
$path = rtrim($path, '/') ?: '/';

// Route table
//   public      — no auth required
//   roles       — roles allowed for read methods (GET/HEAD)
//   write_roles — roles allowed for write methods (POST/PUT/DELETE/PATCH)
$routes = [
    '/api/login'     => ['file' => __DIR__ . '/api/auth.php',      'public'      => true],
    '/api/logout'    => ['file' => __DIR__ . '/api/auth.php',      'roles'       => ['admin', 'staff']],
    '/api/equipment' => ['file' => __DIR__ . '/api/equipment.php', 'roles'       => ['admin', 'staff'],
                                                                    'write_roles' => ['admin']],
    '/api/sites'     => ['file' => __DIR__ . '/api/sites.php',     'roles'       => ['admin']],
    '/api/checkout'  => ['file' => __DIR__ . '/api/checkout.php',  'roles'       => ['admin', 'staff'],
                                                                    'write_roles' => ['admin']],
    '/api/audit'     => ['file' => __DIR__ . '/api/audit.php',     'roles'       => ['admin', 'staff']],
    '/api/map'       => ['file' => __DIR__ . '/api/map.php',       'roles'       => ['admin', 'staff']],
];

$route = $routes[$path] ?? null;

if (!$route) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Not found.']);
    exit;
}

// ── Auth + role enforcement ───────────────────────────────────────────────────
if (!($route['public'] ?? false)) {
    $current_user            = require_auth();
    $GLOBALS['current_user'] = $current_user;

    $write_methods = ['POST', 'PUT', 'DELETE', 'PATCH'];
    $allowed_roles = (in_array($_SERVER['REQUEST_METHOD'], $write_methods, true) && isset($route['write_roles']))
        ? $route['write_roles']
        : $route['roles'];

    require_role($current_user, $allowed_roles);
}

// ── Dispatch ──────────────────────────────────────────────────────────────────
$_route_path = $path;   // lets auth.php distinguish /api/login vs /api/logout
require $route['file'];
exit;
