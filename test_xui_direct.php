<?php

/**
 * IPTV Middleware - Live XUI.ONE Panel Direct Connection Tester
 * CLI: php test_xui_direct.php
 */

define('STD_RESET', "\033[0m");
define('STD_BOLD', "\033[1m");
define('STD_GREEN', "\033[32m");
define('STD_RED', "\033[31m");
define('STD_YELLOW', "\033[33m");
define('STD_CYAN', "\033[36m");

echo STD_BOLD . STD_CYAN . "=== PROBANDO CONEXIÓN EN VIVO A TU PANEL XUI.ONE ===" . STD_RESET . PHP_EOL . PHP_EOL;

// Load components
require_once __DIR__ . '/src/Autoloader.php';
\App\Autoloader::register();

// Load Environment
$envPath = __DIR__ . '/.env';
if (!file_exists($envPath)) {
    echo STD_RED . "[ERROR] Archivo .env no encontrado." . STD_RESET . PHP_EOL;
    exit(1);
}

$lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    $line = trim($line);
    if (empty($line) || str_starts_with($line, '#')) continue;
    if (strpos($line, '=') !== false) {
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (preg_match('/^"(.+)"$/', $value, $matches)) $value = $matches[1];
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }
}

try {
    $xuiService = new \App\Services\XuiService();
    
    $lineId = 5236; // Tu ID real de prueba
    echo "Consultando línea real ID: " . STD_YELLOW . $lineId . STD_RESET . " en tu panel..." . PHP_EOL;
    
    // Call XuiService
    $response = $xuiService->getLine($lineId);
    
    echo STD_GREEN . "[ÉXITO] El panel respondió correctamente." . STD_RESET . PHP_EOL . PHP_EOL;
    echo STD_BOLD . "Respuesta cruda decodificada del Panel:" . STD_RESET . PHP_EOL;
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL . PHP_EOL;

    // Simulate our Middleware standard translation output (resilient parser)
    echo STD_BOLD . "Traducción estandarizada enviada por tu Middleware al Chatbot:" . STD_RESET . PHP_EOL;
    $target = isset($response['data']) ? $response['data'] : $response;
    $exp = $target['exp_date'] ?? null;
    $expiryFormatted = is_numeric($exp) ? date('Y-m-d H:i:s', (int)$exp) : $exp;

    $middlewareResponse = [
        'success'         => true,
        'username'        => $target['username'] ?? 'N/A',
        'exp_date'        => $expiryFormatted ?? 'Nunca (Ilimitada)',
        'max_connections' => (int)($target['max_connections'] ?? 1),
        'enabled'         => (bool)($target['enabled'] ?? true)
    ];

    echo STD_GREEN . json_encode($middlewareResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . STD_RESET . PHP_EOL;

} catch (Exception $e) {
    echo STD_RED . "[FALLO DE CONEXIÓN] Ocurrió un error al contactar al panel." . STD_RESET . PHP_EOL;
    echo "Mensaje: " . STD_YELLOW . $e->getMessage() . STD_RESET . PHP_EOL;
}
