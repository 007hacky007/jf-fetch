<?php

declare(strict_types=1);

use App\Infra\Config;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'App\\';
        if (str_starts_with($class, $prefix) === false) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $path = __DIR__ . '/../app/' . str_replace('\\', '/', $relative) . '.php';

        if (file_exists($path)) {
            require_once $path;
        }
    });
}

// Load configuration early to ensure environment is valid.
Config::boot(__DIR__ . '/../config');

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';

if ($path !== '/' && str_ends_with($path, '/') === true) {
    $path = rtrim($path, '/');
}

// Route API requests using spec-aligned endpoints.
if (str_starts_with($path, '/api/')) {
    $apiRoot = realpath(__DIR__ . '/api');
    if ($apiRoot === false) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'API directory missing']);
        exit;
    }

    $relative = trim(substr($path, 5), '/');
    $segments = $relative === '' ? [] : array_values(array_filter(explode('/', $relative), static fn ($segment) => $segment !== ''));

    if (dispatchApiRoute($apiRoot, $segments) === false) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Endpoint not found']);
    }

    exit;
}

// Serve UI assets from public/ui.
$uiRoot = realpath(__DIR__ . '/ui');

if ($path === '/' || $path === '/index.html') {
    require $uiRoot . '/index.html';
    exit;
}

$assetPath = realpath($uiRoot . $path);
if ($assetPath !== false && str_starts_with($assetPath, $uiRoot) && is_file($assetPath)) {
    $extension = pathinfo($assetPath, PATHINFO_EXTENSION);
    $mime = match ($extension) {
        'js' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'html' => 'text/html; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        default => 'application/octet-stream',
    };

    header('Content-Type: ' . $mime);
    readfile($assetPath);
    exit;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not Found';

/**
 * Attempts to resolve and execute an API script based on REST-flavoured routes.
 *
 * @param string $apiRoot Absolute path to the api directory.
 * @param array<int, string> $segments Request path segments after /api/.
 */
function dispatchApiRoute(string $apiRoot, array $segments): bool
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $primary = $segments[0] ?? '';

    // Direct file resolution first for backwards compatibility.
    if ($primary !== '') {
        $candidate = $apiRoot . '/' . implode('/', $segments);
        $resolved = realpath($candidate . '.php') ?: realpath($candidate);
        if (is_string($resolved) && str_starts_with($resolved, $apiRoot) && is_file($resolved)) {
            require $resolved;

            return true;
        }
    }

    switch ($primary) {
        case 'queue':
            if (count($segments) === 1 && $method === 'POST') {
                require $apiRoot . '/jobs/queue.php';

                return true;
            }

            break;

        case 'providers':
            if (count($segments) >= 2 && $segments[1] === 'kraska') {
                if (count($segments) === 3 && $segments[2] === 'menu' && $method === 'GET') {
                    require $apiRoot . '/providers/kraska/menu.php';

                    return true;
                }
            }

            if (count($segments) === 1) {
                if ($method === 'GET') {
                    require $apiRoot . '/providers/list.php';

                    return true;
                }

                if ($method === 'POST') {
                    require $apiRoot . '/providers/create.php';

                    return true;
                }
            }

            if (count($segments) >= 2) {
                $id = $segments[1];
                if (!ctype_digit($id)) {
                    break;
                }

                $_GET['id'] = $_GET['id'] ?? (int) $id;

                if (count($segments) === 2) {
                    if ($method === 'PATCH') {
                        require $apiRoot . '/providers/update.php';

                        return true;
                    }

                    if ($method === 'DELETE') {
                        require $apiRoot . '/providers/delete.php';

                        return true;
                    }
                }

                if (count($segments) === 3 && $segments[2] === 'test' && $method === 'POST') {
                    require $apiRoot . '/providers/test.php';

                    return true;
                }
            }

            break;

        case 'users':
            if (count($segments) === 1 && in_array($method, ['GET', 'POST'], true)) {
                require $apiRoot . '/users.php';

                return true;
            }

            if (count($segments) === 2 && in_array($method, ['PATCH', 'DELETE'], true)) {
                $id = $segments[1];
                if (!ctype_digit($id)) {
                    break;
                }

                $_GET['id'] = $_GET['id'] ?? (int) $id;
                require $apiRoot . '/users.php';

                return true;
            }

            break;

        case 'jobs':
            if (count($segments) === 1 && $method === 'GET') {
                require $apiRoot . '/jobs/list.php';

                return true;
            }

            if (count($segments) === 2 && $segments[1] === 'stream' && $method === 'GET') {
                require $apiRoot . '/jobs/stream.php';

                return true;
            }

            if (count($segments) === 2 && $segments[1] === 'reorder' && $method === 'POST') {
                require $apiRoot . '/jobs/reorder.php';

                return true;
            }

            if (count($segments) >= 3) {
                $id = $segments[1];
                $action = $segments[2];
                if (!ctype_digit($id)) {
                    break;
                }

                $_GET['id'] = $_GET['id'] ?? (int) $id;

                if ($action === 'priority' && $method === 'PATCH') {
                    require $apiRoot . '/jobs/priority.php';

                    return true;
                }

                if (in_array($action, ['cancel', 'pause', 'resume'], true) && $method === 'PATCH') {
                    require $apiRoot . '/jobs/' . $action . '.php';

                    return true;
                }
            }

            break;

        case 'system':
            if (count($segments) === 2 && $segments[1] === 'storage' && $method === 'GET') {
                require $apiRoot . '/system/storage.php';

                return true;
            }

            if (count($segments) === 2 && $segments[1] === 'health' && $method === 'GET') {
                require $apiRoot . '/system/health.php';

                return true;
            }

            break;

        case 'settings':
            if (count($segments) === 1 && in_array($method, ['GET', 'PUT'], true)) {
                require $apiRoot . '/settings.php';

                return true;
            }

            break;

        case 'auth':
            if (count($segments) === 2 && $segments[1] === 'login' && $method === 'POST') {
                require $apiRoot . '/auth/login.php';

                return true;
            }

            if (count($segments) === 2 && $segments[1] === 'logout' && $method === 'POST') {
                require $apiRoot . '/auth/logout.php';

                return true;
            }

            break;

        case 'audit':
            if ($method === 'GET') {
                $candidate = $apiRoot . '/audit/index.php';
                if (file_exists($candidate)) {
                    require $candidate;

                    return true;
                }
            }

            break;
    }

    return false;
}
