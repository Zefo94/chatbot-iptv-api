<?php

/**
 * IPTV Middleware - Diagnostic & Test Suite
 * Run via CLI: php test_system.php
 */

define('STD_RESET', "\033[0m");
define('STD_BOLD', "\033[1m");
define('STD_GREEN', "\033[32m");
define('STD_RED', "\033[31m");
define('STD_YELLOW', "\033[33m");
define('STD_CYAN', "\033[36m");

echo STD_BOLD . STD_CYAN . "=== DIAGNÓSTICO Y PRUEBAS DEL SISTEMA MIDDLEWARE IPTV ===" . STD_RESET . PHP_EOL . PHP_EOL;

// 1. Load Autoloader & Env
echo STD_BOLD . "1. Cargando Componentes y Variables de Entorno..." . STD_RESET . PHP_EOL;
require_once __DIR__ . '/src/Autoloader.php';
\App\Autoloader::register();

$envPath = __DIR__ . '/.env';
if (!file_exists($envPath)) {
    echo STD_RED . "[ERROR] Archivo .env no encontrado. Por favor crea el archivo copiado de .env.example." . STD_RESET . PHP_EOL;
    exit(1);
}

// Simple Env Loader
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
echo STD_GREEN . "[OK] Entorno cargado exitosamente." . STD_RESET . PHP_EOL . PHP_EOL;

// 2. Test URL Formatting & Separation Logic
echo STD_BOLD . "2. Verificando Lógica de URLs para XUI.ONE Panel..." . STD_RESET . PHP_EOL;
$apiUrl = $_ENV['XUI_API_URL'] ?? 'No Configurado';
$apiKey = $_ENV['XUI_API_KEY'] ?? '';
echo "URL base en .env: " . STD_YELLOW . $apiUrl . STD_RESET . PHP_EOL;
if (!empty($apiKey)) {
    echo "API Key configurada en .env: " . STD_GREEN . substr($apiKey, 0, 8) . "************************" . STD_RESET . PHP_EOL;
}

// Emulate separator builder mapping matching the new XuiService logic
$testParams = ['action' => 'get_line', 'id' => 5236];
if (!empty($apiKey)) {
    $testParams = array_merge(['api_key' => $apiKey], $testParams);
}
$queryString = http_build_query($testParams);
$separator = '';
if (!str_ends_with($apiUrl, '?')) {
    $separator = (strpos($apiUrl, '?') !== false) ? '&' : '?';
}
$fullUrl = $apiUrl . $separator . $queryString;
echo "URL Final de consulta XUI.ONE generada por el sistema:" . PHP_EOL;
echo STD_GREEN . "👉 " . $fullUrl . STD_RESET . PHP_EOL . PHP_EOL;

// 3. Test Database Connection
echo STD_BOLD . "3. Verificando Conexión a Base de Datos (PDO)..." . STD_RESET . PHP_EOL;
try {
    $db = \App\Database\Connection::getInstance();
    echo STD_GREEN . "[CONECTADO] Conexión establecida correctamente con MariaDB/MySQL." . STD_RESET . PHP_EOL;
    
    // Check if tables exist
    $tables = ['clientes', 'ordenes', 'pagos', 'logs'];
    foreach ($tables as $table) {
        $stmt = $db->query("SHOW TABLES LIKE '{$table}'");
        if ($stmt->fetch()) {
            echo "  └─ Tabla " . STD_GREEN . "[{$table}]" . STD_RESET . " existe y está estructurada." . PHP_EOL;
        } else {
            echo "  └─ Tabla " . STD_RED . "[{$table}]" . STD_RESET . " " . STD_YELLOW . "¡FALTA! Por favor ejecuta el script schema.sql." . STD_RESET . PHP_EOL;
        }
    }
} catch (Exception $e) {
    echo STD_RED . "[FALLO DE CONEXIÓN] No se pudo conectar a la Base de Datos." . STD_RESET . PHP_EOL;
    echo "Detalle del error: " . STD_YELLOW . $e->getMessage() . STD_RESET . PHP_EOL;
    echo "💡 Consejo: Edita el archivo .env con las credenciales de tu base de datos local y asegúrate de que el servidor MySQL esté corriendo." . PHP_EOL;
}
echo PHP_EOL;

// 4. Instructions for dynamic routing testing
echo STD_BOLD . "=== ¿CÓMO PROBAR LOS ENDPOINTS API REST LOCALMENTE? ===" . STD_RESET . PHP_EOL . PHP_EOL;
echo "Puedes iniciar un servidor web de desarrollo incorporado de PHP desde la terminal para pruebas locales rápidas:" . PHP_EOL;
echo STD_YELLOW . "  php -S 127.0.0.1:8000 -t public" . STD_RESET . PHP_EOL . PHP_EOL;

echo "Una vez que el servidor esté activo, ejecuta cualquiera de las siguientes pruebas cURL en tu terminal para probar los controladores:" . PHP_EOL . PHP_EOL;

echo STD_BOLD . "A. Prueba de Búsqueda de Usuario (Debe devolver 404 si la base de datos está vacía, o 200 si insertas datos):" . STD_RESET . PHP_EOL;
echo "  curl -X POST http://127.0.0.1:8000/api/buscar-usuario \\" . PHP_EOL;
echo "    -H \"X-API-Key: " . ($_ENV['CHATBOT_API_KEY'] ?? 'tu_token_secreto') . "\" \\" . PHP_EOL;
echo "    -H \"Content-Type: application/json\" \\" . PHP_EOL;
echo "    -d '{\"telefono\": \"+573001234567\"}'" . PHP_EOL . PHP_EOL;

echo STD_BOLD . "B. Prueba de Generación de Orden de Pago (Requiere que la base de datos tenga las tablas cargadas):" . STD_RESET . PHP_EOL;
echo "  curl -X POST http://127.0.0.1:8000/api/crear-orden \\" . PHP_EOL;
echo "    -H \"X-API-Key: " . ($_ENV['CHATBOT_API_KEY'] ?? 'tu_token_secreto') . "\" \\" . PHP_EOL;
echo "    -H \"Content-Type: application/json\" \\" . PHP_EOL;
echo "    -d '{\"line_id\": 44342, \"dias\": 30, \"monto\": 15000.00}'" . PHP_EOL . PHP_EOL;

echo STD_BOLD . "C. Simulación de Webhook de Cobro Aprobado por Pasarela (Bypasa la API Key y valida firmas):" . STD_RESET . PHP_EOL;
echo "  curl -X POST http://127.0.0.1:8000/api/webhook-pago?gateway=wompi \\" . PHP_EOL;
echo "    -H \"Content-Type: application/json\" \\" . PHP_EOL;
echo "    -d '{\"event\": \"transaction.updated\", \"data\": {\"transaction\": {\"id\": \"tx-123\", \"status\": \"APPROVED\", \"amount_in_cents\": 1500000, \"currency\": \"COP\", \"reference\": \"ORD-MOCK\"}}}'" . PHP_EOL . PHP_EOL;
