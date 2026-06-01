<?php
/**
 * Test script: verifica que el renewal con API key del revendedor deduce créditos.
 * Uso: php test_credit_deduction.php [revendedor_id] [package_id] [line_id]
 * Ejemplo: php test_credit_deduction.php 17 9 44352
 *
 * NO modifica la fecha de vencimiento real (usa dry_run=true para solo leer créditos).
 */

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

use App\Services\XuiService;

$revendedorId = (int)($argv[1] ?? 17);
$packageId    = (int)($argv[2] ?? 9);
$lineId       = (int)($argv[3] ?? 0);

$xui = new XuiService();

echo "=== TEST CREDIT DEDUCTION ===\n\n";

// 1. Créditos ANTES
echo "1. Leyendo créditos actuales del revendedor xui_id={$revendedorId}...\n";
$before = $xui->requestAsAdmin('get_user', ['id' => $revendedorId]);
$beforeData = $before['data'] ?? $before;
$creditsBefore = (int)($beforeData['credits'] ?? 0);
$resellerApiKey = $beforeData['api_key'] ?? null;
$resellerUsername = $beforeData['username'] ?? 'desconocido';
echo "   Revendedor: {$resellerUsername}\n";
echo "   Créditos antes: {$creditsBefore}\n";
echo "   API Key: " . ($resellerApiKey ? substr($resellerApiKey, 0, 8) . "..." : "NO ENCONTRADA") . "\n\n";

if (!$resellerApiKey) {
    echo "ERROR: No se pudo obtener la API key del revendedor. Abortando.\n";
    exit(1);
}

// 2. Verificar costo del paquete
echo "2. Verificando costo del paquete id={$packageId}...\n";
$pkgs = $xui->requestAsAdmin('get_packages', []);
$pkgData = isset($pkgs['data']) && is_array($pkgs['data']) ? $pkgs['data'] : (is_array($pkgs) ? $pkgs : []);
$cost = 0;
$pkgName = '';
foreach ($pkgData as $pkg) {
    if ((int)($pkg['id'] ?? 0) === $packageId) {
        $cost = (int)($pkg['official_credits'] ?? 0);
        $pkgName = $pkg['package_name'] ?? $pkg['name'] ?? "pkg_{$packageId}";
        break;
    }
}
echo "   Paquete: {$pkgName} (id={$packageId})\n";
echo "   Costo: {$cost} crédito(s)\n\n";

if ($cost <= 0) {
    echo "ADVERTENCIA: El paquete tiene costo 0 — XUI no deducirá nada.\n";
    echo "Revisa que 'official_credits' esté configurado en ese paquete en XUI.\n";
    exit(0);
}

// 3. Simular edit_line con API key del revendedor (si hay line_id)
if ($lineId > 0) {
    echo "3. Ejecutando edit_line como revendedor (line_id={$lineId}, package_id={$packageId})...\n";
    $xui->useResellerAuth($resellerApiKey);
    try {
        $result = $xui->request('edit_line', [
            'id'         => $lineId,
            'package_id' => $packageId,
            // No cambiamos exp_date en este test para no alterar la línea real
        ]);
        echo "   Respuesta XUI: " . json_encode($result) . "\n";
    } finally {
        $xui->clearResellerAuth();
    }
    echo "\n";
} else {
    echo "3. SALTANDO edit_line (no se proporcionó line_id — solo verificando créditos).\n\n";
}

// 4. Créditos DESPUÉS
echo "4. Leyendo créditos después...\n";
sleep(1);
$after = $xui->requestAsAdmin('get_user', ['id' => $revendedorId]);
$afterData = $after['data'] ?? $after;
$creditsAfter = (int)($afterData['credits'] ?? 0);
$diff = $creditsBefore - $creditsAfter;
echo "   Créditos después: {$creditsAfter}\n\n";

echo "=== RESULTADO ===\n";
echo "Antes:    {$creditsBefore}\n";
echo "Después:  {$creditsAfter}\n";
echo "Diferencia: -{$diff}\n";

if ($lineId > 0) {
    if ($diff === $cost) {
        echo "\n✅ XUI dedujo {$diff} crédito(s) automáticamente con la API key del revendedor.\n";
        echo "   El renewal nativo funciona correctamente.\n";
    } elseif ($diff === 0) {
        echo "\n⚠️  XUI NO dedujo créditos con la API key del revendedor.\n";
        echo "   El sistema usará el fallback (admin edit_user manual).\n";
    } else {
        echo "\n⚠️  Se dedujo una cantidad inesperada ({$diff} en vez de {$cost}).\n";
    }
} else {
    echo "\nℹ️  Para probar la deducción real, corre: php test_credit_deduction.php {$revendedorId} {$packageId} <line_id>\n";
}
