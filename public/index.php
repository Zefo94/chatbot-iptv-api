<?php

/**
 * IPTV XUI.ONE Management Middleware - Single Entry Point
 * Pure PHP 8.2 Application Entry
 */

// 1. Configure CORS & response headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');

// Handle pre-flight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 2. Global Exception Boundary
try {
    // 3. Register Custom Autoloader
    require_once dirname(__DIR__) . '/src/Autoloader.php';
    \App\Autoloader::register();

    // 4. Load Environment (.env) Variables dynamically
    loadEnvironmentVariables(dirname(__DIR__) . '/.env');

    // 5. Initialize Logger and read debug configs
    $debugMode = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($debugMode) {
        ini_set('display_errors', 1);
        ini_set('display_startup_errors', 1);
        error_reporting(E_ALL);
    } else {
        ini_set('display_errors', 0);
        error_reporting(0);
    }

    // 6. Router Dispatching
    $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $requestMethod = $_SERVER['REQUEST_METHOD'];

    $routes = require dirname(__DIR__) . '/routes/api.php';

    // Verify Route exists
    if (!isset($routes[$requestMethod][$requestUri])) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => "Endpoint no encontrado: {$requestMethod} {$requestUri}"
        ]);
        exit;
    }

    // 7. Dynamic API-Key security validation
    // Chatbot actions require security token. Webhooks must bypass since payment servers query directly.
    $isWebhook = ($requestUri === '/api/webhook-pago');
    if (!$isWebhook) {
        $configuredApiKey = $_ENV['CHATBOT_API_KEY'] ?? '';
        
        // Read header in case sensitive headers are stripped or lowercased
        $clientApiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_SERVER['x-api-key'] ?? '';
        
        if (empty($clientApiKey) && function_exists('getallheaders')) {
            $headers = getallheaders();
            $clientApiKey = $headers['X-API-Key'] ?? $headers['x-api-key'] ?? '';
        }

        if (empty($configuredApiKey) || $clientApiKey !== $configuredApiKey) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => "No autorizado. Token X-API-Key ausente o inválido."
            ]);
            exit;
        }
    }

    // 8. Invoke Route Target Controller
    list($controllerClass, $actionMethod) = $routes[$requestMethod][$requestUri];

    if (!class_exists($controllerClass)) {
        throw new Exception("Controller class '{$controllerClass}' not found.");
    }

    $controllerInstance = new $controllerClass();

    if (!method_exists($controllerInstance, $actionMethod)) {
        throw new Exception("Action method '{$actionMethod}' not found in {$controllerClass}.");
    }

    // Call target endpoint
    $controllerInstance->$actionMethod();

} catch (Throwable $e) {
    // Audit critical errors
    \App\Services\LoggerService::logFile("CRITICAL SYSTEM PANIC: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine(), 'error');

    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => "Fallo interno del servidor.",
        'details' => ($_ENV['APP_ENV'] ?? 'production') === 'local' ? $e->getMessage() : 'Ocurrió un error inesperado, por favor consulte con administración.'
    ]);
    exit;
}

/**
 * Pure PHP Environment Variables File Parser
 * 
 * @param string $filePath
 */
function loadEnvironmentVariables(string $filePath): void
{
    if (!file_exists($filePath)) {
        return;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        
        // Skip comment lines
        if (empty($line) || str_starts_with($line, '#')) {
            continue;
        }

        // Support key = value assignments
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Strip quotes if they wrap values
            if (preg_match('/^"(.+)"$/', $value, $matches)) {
                $value = $matches[1];
            } elseif (preg_match('/^\'(.+)\'$/', $value, $matches)) {
                $value = $matches[1];
            }

            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }
}
