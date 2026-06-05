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

    // 5. Run pending database migrations automatically
    (new \App\Database\MigrationRunner())->run();

    // 6. Initialize Logger and read debug configs
    $debugMode = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($debugMode) {
        ini_set('display_errors', 1);
        ini_set('display_startup_errors', 1);
        error_reporting(E_ALL);
    } else {
        ini_set('display_errors', 0);
        error_reporting(0);
    }

    // 7. Router Dispatching
    $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $requestMethod = $_SERVER['REQUEST_METHOD'];

    // Admin dashboard (served directly, has its own session auth)
    if (in_array($requestUri, ['/dashboard', '/dashboard/']) && in_array($requestMethod, ['GET', 'POST'])) {
        require __DIR__ . '/dashboard.php';
        exit;
    }

    // Reseller panel + its own API subroutes
    if (str_starts_with($requestUri, '/reseller')) {
        require __DIR__ . '/reseller.php';
        exit;
    }

    // PayPal redirect landing pages (GET, no auth required)
    if ($requestMethod === 'GET' && $requestUri === '/pago-exitoso') {
        header('Content-Type: text/html; charset=utf-8');
        echo <<<'HTML'
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>¡Pago completado!</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#0f4c2a 0%,#1a7a42 50%,#25a55a 100%);padding:1rem}
  .card{background:#fff;border-radius:24px;padding:2.5rem 2rem;max-width:400px;width:100%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.25)}
  .icon-wrap{width:80px;height:80px;background:linear-gradient(135deg,#22c55e,#16a34a);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.5rem;box-shadow:0 8px 24px rgba(34,197,94,.4)}
  .icon-wrap svg{width:40px;height:40px;stroke:#fff;fill:none;stroke-width:3;stroke-linecap:round;stroke-linejoin:round}
  .checkmark{stroke-dasharray:60;stroke-dashoffset:60;animation:draw .5s .2s ease forwards}
  @keyframes draw{to{stroke-dashoffset:0}}
  h1{font-size:1.6rem;font-weight:700;color:#111827;margin-bottom:.5rem}
  .subtitle{color:#6b7280;font-size:.95rem;margin-bottom:2rem;line-height:1.5}
  .steps{background:#f0fdf4;border-radius:16px;padding:1.25rem;margin-bottom:1.75rem;text-align:left}
  .step{display:flex;align-items:flex-start;gap:.75rem;margin-bottom:.75rem}
  .step:last-child{margin-bottom:0}
  .step-num{width:24px;height:24px;border-radius:50%;background:#16a34a;color:#fff;font-size:.75rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px}
  .step-text{font-size:.875rem;color:#374151;line-height:1.4}
  .step-text strong{color:#111827}
  .btn{display:flex;align-items:center;justify-content:center;gap:.6rem;background:#25d366;color:#fff;text-decoration:none;padding:.875rem 1.5rem;border-radius:14px;font-size:1rem;font-weight:600;box-shadow:0 4px 16px rgba(37,211,102,.4);transition:transform .15s,box-shadow .15s}
  .btn:active{transform:scale(.97)}
  .btn svg{width:22px;height:22px;fill:#fff;flex-shrink:0}
  .footer{margin-top:1.5rem;font-size:.8rem;color:#9ca3af}
</style>
</head>
<body>
<div class="card">
  <div class="icon-wrap">
    <svg viewBox="0 0 24 24"><polyline class="checkmark" points="4,12 9,17 20,7"/></svg>
  </div>
  <h1>¡Pago completado!</h1>
  <p class="subtitle">Tu pago fue procesado correctamente por PayPal.</p>
  <div class="steps">
    <div class="step">
      <div class="step-num">1</div>
      <div class="step-text">Regresa a <strong>WhatsApp</strong></div>
    </div>
    <div class="step">
      <div class="step-num">2</div>
      <div class="step-text">Escríbeme <strong>cualquier mensaje</strong></div>
    </div>
    <div class="step">
      <div class="step-num">3</div>
      <div class="step-text">Verificaré tu pago y <strong>activaré tu servicio</strong> automáticamente ✅</div>
    </div>
  </div>
  <a href="whatsapp://" class="btn">
    <svg viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.138.561 4.141 1.535 5.873L.057 23.5l5.734-1.502A11.934 11.934 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.818a9.808 9.808 0 01-4.988-1.362l-.358-.213-3.405.893.908-3.317-.233-.371A9.818 9.818 0 012.182 12C2.182 6.57 6.57 2.182 12 2.182S21.818 6.57 21.818 12 17.43 21.818 12 21.818z"/></svg>
    Volver a WhatsApp
  </a>
  <p class="footer">¿Problemas? Escríbenos directamente en WhatsApp</p>
</div>
</body>
</html>
HTML;
        exit;
    }
    if ($requestMethod === 'GET' && $requestUri === '/pago-cancelado') {
        header('Content-Type: text/html; charset=utf-8');
        echo <<<'HTML'
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pago cancelado</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#4a0f0f 0%,#7a1a1a 50%,#a52525 100%);padding:1rem}
  .card{background:#fff;border-radius:24px;padding:2.5rem 2rem;max-width:400px;width:100%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.25)}
  .icon-wrap{width:80px;height:80px;background:linear-gradient(135deg,#f87171,#dc2626);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.5rem;box-shadow:0 8px 24px rgba(220,38,38,.4)}
  .icon-wrap svg{width:36px;height:36px;stroke:#fff;fill:none;stroke-width:3;stroke-linecap:round}
  h1{font-size:1.6rem;font-weight:700;color:#111827;margin-bottom:.5rem}
  .subtitle{color:#6b7280;font-size:.95rem;margin-bottom:2rem;line-height:1.5}
  .info-box{background:#fef2f2;border-radius:16px;padding:1.25rem;margin-bottom:1.75rem;text-align:left}
  .info-item{display:flex;align-items:flex-start;gap:.75rem;margin-bottom:.625rem}
  .info-item:last-child{margin-bottom:0}
  .dot{width:8px;height:8px;border-radius:50%;background:#dc2626;flex-shrink:0;margin-top:5px}
  .info-text{font-size:.875rem;color:#374151;line-height:1.4}
  .btn{display:flex;align-items:center;justify-content:center;gap:.6rem;background:#25d366;color:#fff;text-decoration:none;padding:.875rem 1.5rem;border-radius:14px;font-size:1rem;font-weight:600;box-shadow:0 4px 16px rgba(37,211,102,.4)}
  .btn svg{width:22px;height:22px;fill:#fff;flex-shrink:0}
  .footer{margin-top:1.5rem;font-size:.8rem;color:#9ca3af}
</style>
</head>
<body>
<div class="card">
  <div class="icon-wrap">
    <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
  </div>
  <h1>Pago cancelado</h1>
  <p class="subtitle">No se realizó ningún cargo. Tu dinero está seguro.</p>
  <div class="info-box">
    <div class="info-item">
      <div class="dot"></div>
      <div class="info-text">Puedes intentarlo de nuevo cuando quieras</div>
    </div>
    <div class="info-item">
      <div class="dot"></div>
      <div class="info-text">Vuelve a WhatsApp y elige tu plan</div>
    </div>
    <div class="info-item">
      <div class="dot"></div>
      <div class="info-text">Si tuviste algún problema, escríbenos y te ayudamos</div>
    </div>
  </div>
  <a href="whatsapp://" class="btn">
    <svg viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.138.561 4.141 1.535 5.873L.057 23.5l5.734-1.502A11.934 11.934 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.818a9.808 9.808 0 01-4.988-1.362l-.358-.213-3.405.893.908-3.317-.233-.371A9.818 9.818 0 012.182 12C2.182 6.57 6.57 2.182 12 2.182S21.818 6.57 21.818 12 17.43 21.818 12 21.818z"/></svg>
    Volver a WhatsApp
  </a>
  <p class="footer">No se realizó ningún cobro</p>
</div>
</body>
</html>
HTML;
        exit;
    }

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

    // 8. Dynamic API-Key security validation
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

    // 9. Invoke Route Target Controller
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
