<?php
/**
 * Migration: create precios_paquetes table and seed initial prices.
 * Run from the project root: php database/migrate_precios.php
 */

// Load .env
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $v = trim($v);
        if (preg_match('/^"(.+)"$/', $v, $m)) $v = $m[1];
        elseif (preg_match("/^'(.+)'$/", $v, $m)) $v = $m[1];
        $_ENV[trim($k)] = $v;
        putenv(trim($k) . '=' . $v);
    }
}

require_once dirname(__DIR__) . '/src/Autoloader.php';
\App\Autoloader::register();

$db = \App\Database\Connection::getInstance();

// Create table
$db->exec("
    CREATE TABLE IF NOT EXISTS precios_paquetes (
        package_id   INT          NOT NULL PRIMARY KEY,
        package_name VARCHAR(255) NOT NULL DEFAULT '',
        precio       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        moneda       VARCHAR(10)  NOT NULL DEFAULT 'EUR',
        activo       TINYINT(1)   NOT NULL DEFAULT 1,
        updated_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
echo "Tabla precios_paquetes creada (o ya existía).\n";

// Seed with known packages and prices
$seeds = [
    [3,  '1 MES FULL - 1 Dispositivo sin XXX',   10.00, 'EUR'],
    [4,  '1 MES FULL - 1 Dispositivos con XXX',   10.00, 'EUR'],
    [5,  '3 MESES FULL - 1 Dispositivo sin XXX',  25.00, 'EUR'],
    [6,  '3 MESES FULL - 1 Dispositivo con XXX',  25.00, 'EUR'],
    [7,  '6 MESES FULL - 1 Dispositivo sin XXX',  35.00, 'EUR'],
    [8,  '6 MESES FULL - 1 Dispositivo con XXX',  35.00, 'EUR'],
    [9,  '12 MESES FULL - 1 Dispositivo sin XXX', 60.00, 'EUR'],
    [10, '12 MESES FULL - 1 Dispositivo con XXX', 60.00, 'EUR'],
    [11, '12 MESES ESPAÑA CON XXX',               60.00, 'EUR'],
    [12, '12 MESES ESPAÑA SIN XXX',               60.00, 'EUR'],
    [13, '1 MES ESPAÑA CON XXX',                  10.00, 'EUR'],
    [14, '1 MES ESPAÑA SIN XXX',                  10.00, 'EUR'],
    [15, '1 AÑO SIN VOD CON XXX',                 60.00, 'EUR'],
    [16, '1 AÑO SIN VOD SIN XXX',                 60.00, 'EUR'],
];

$stmt = $db->prepare("
    INSERT INTO precios_paquetes (package_id, package_name, precio, moneda)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE package_name = VALUES(package_name)
");

foreach ($seeds as $row) {
    $stmt->execute($row);
    echo "  · #{$row[0]} {$row[1]} — {$row[2]} {$row[3]}\n";
}

echo "\nMigración completada. Precios iniciales insertados (sin sobrescribir los existentes).\n";
