<?php

namespace App\Controllers;

use App\Database\Connection;
use App\Services\XuiService;
use App\Services\LoggerService;
use Exception;

class PreciosController extends BaseController
{
    /**
     * Sync packages from XUI.ONE into local precios_paquetes table.
     * Inserts new packages with precio=0, preserves existing prices.
     *
     * POST /api/sincronizar-paquetes
     */
    public function sincronizar(): void
    {
        try {
            $xui  = new XuiService();
            $raw  = $xui->request('get_packages', []);
            $items = isset($raw['data']) && is_array($raw['data']) ? $raw['data'] : $raw;
            if (!is_array($items)) {
                $this->error("La respuesta de XUI.ONE no contiene paquetes.", 502);
                return;
            }

            $db   = Connection::getInstance();
            $stmt = $db->prepare("
                INSERT INTO precios_paquetes
                    (package_id, package_name, duracion, duracion_unidad, dias, creditos, precio, moneda, activo)
                VALUES
                    (:id, :name, :dur, :unit, :dias, :cred, :precio, :moneda, 1)
                ON DUPLICATE KEY UPDATE
                    package_name   = VALUES(package_name),
                    duracion       = VALUES(duracion),
                    duracion_unidad= VALUES(duracion_unidad),
                    dias           = VALUES(dias),
                    creditos       = VALUES(creditos)
            ");

            $synced = 0;
            foreach ($items as $p) {
                if (!is_array($p)) continue;
                $dur   = (int)($p['official_duration']    ?? 0);
                $unit  = (string)($p['official_duration_in'] ?? '');
                $dias  = $this->durationToDays($dur, $unit);
                $stmt->execute([
                    ':id'     => (int)($p['id'] ?? 0),
                    ':name'   => (string)($p['package_name'] ?? ''),
                    ':dur'    => $dur,
                    ':unit'   => $unit,
                    ':dias'   => $dias,
                    ':cred'   => (int)($p['official_credits'] ?? 0),
                    ':precio' => 0.00,
                    ':moneda' => 'EUR',
                ]);
                $synced++;
            }

            LoggerService::logAction("SINCRONIZAR_PAQUETES", [], ['total' => $synced]);
            $this->success("Paquetes sincronizados correctamente.", ['total' => $synced]);

        } catch (Exception $e) {
            LoggerService::logFile("Error in sincronizar-paquetes: " . $e->getMessage(), 'error');
            $this->error("Error al sincronizar paquetes: " . $e->getMessage(), 500);
        }
    }

    private function durationToDays(int $value, string $unit): int
    {
        return match (strtolower(trim($unit))) {
            'hours', 'hour'   => (int)ceil($value / 24),
            'days',  'day'    => $value,
            'weeks', 'week'   => $value * 7,
            'months','month'  => $value * 30,
            'years', 'year'   => $value * 365,
            default           => $value,
        };
    }

    /**
     * GET list of all package prices from local DB.
     * POST /api/listar-precios-paquetes
     */
    public function listar(): void
    {
        try {
            $db   = Connection::getInstance();
            $rows = $db->query("SELECT package_id, package_name, precio, moneda, activo FROM precios_paquetes ORDER BY package_id")
                        ->fetchAll();
            $this->success("Precios de paquetes.", ['precios' => $rows]);
        } catch (Exception $e) {
            LoggerService::logFile("Error in listarPrecios: " . $e->getMessage(), 'error');
            $this->error("Error al listar precios: " . $e->getMessage(), 500);
        }
    }

    /**
     * Save (upsert) price for a single package.
     * POST /api/actualizar-precio-paquete
     *
     * Body: { package_id, package_name?, precio, moneda?, activo? }
     */
    public function actualizar(): void
    {
        $input = $this->getRequestData();

        if (empty($input['package_id']) || !isset($input['precio'])) {
            $this->error("Se requieren 'package_id' y 'precio'.", 400);
        }

        $packageId   = (int)$input['package_id'];
        $precio      = (float)$input['precio'];
        $moneda      = isset($input['moneda'])       ? strtoupper(trim($input['moneda']))     : 'EUR';
        $packageName = isset($input['package_name']) ? trim($input['package_name'])            : '';
        $activo      = isset($input['activo'])        ? (int)(bool)$input['activo']            : 1;

        if ($precio < 0) {
            $this->error("El precio no puede ser negativo.", 400);
        }

        try {
            $db = Connection::getInstance();
            $db->prepare("
                INSERT INTO precios_paquetes (package_id, package_name, precio, moneda, activo)
                VALUES (:id, :name, :precio, :moneda, :activo)
                ON DUPLICATE KEY UPDATE
                    package_name = IF(VALUES(package_name) <> '', VALUES(package_name), package_name),
                    precio       = VALUES(precio),
                    moneda       = VALUES(moneda),
                    activo       = VALUES(activo)
            ")->execute([
                ':id'     => $packageId,
                ':name'   => $packageName,
                ':precio' => $precio,
                ':moneda' => $moneda,
                ':activo' => $activo,
            ]);

            LoggerService::logAction("ACTUALIZAR_PRECIO", $input, [
                'package_id' => $packageId,
                'precio'     => $precio,
                'moneda'     => $moneda,
            ]);

            $this->success("Precio actualizado.", [
                'package_id' => $packageId,
                'precio'     => $precio,
                'moneda'     => $moneda,
            ]);
        } catch (Exception $e) {
            LoggerService::logFile("Error in actualizarPrecio: " . $e->getMessage(), 'error');
            $this->error("Error al actualizar precio: " . $e->getMessage(), 500);
        }
    }
}
