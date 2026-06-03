<?php

namespace App\Controllers;

use App\Database\Connection;
use App\Services\LoggerService;
use Exception;

class PreciosController extends BaseController
{
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
                    package_name = IF(:name2 <> '', :name2, package_name),
                    precio       = :precio2,
                    moneda       = :moneda2,
                    activo       = :activo2
            ")->execute([
                ':id'      => $packageId,
                ':name'    => $packageName,
                ':precio'  => $precio,
                ':moneda'  => $moneda,
                ':activo'  => $activo,
                ':name2'   => $packageName,
                ':precio2' => $precio,
                ':moneda2' => $moneda,
                ':activo2' => $activo,
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
