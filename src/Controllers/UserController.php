<?php

namespace App\Controllers;

use App\Database\Connection;
use App\Services\LoggerService;
use Exception;

/**
 * Chatbot User Search & Information Controller
 */
class UserController extends BaseController
{
    /**
     * Finds active client mapping by phone number
     * 
     * POST /api/buscar-usuario
     */
    public function buscar(): void
    {
        $input = $this->getRequestData();

        // 1. Validate phone parameter is passed
        $this->validate($input, [
            'telefono' => 'required|string'
        ]);

        $phone = trim($input['telefono']);
        $resellerXuiId = isset($input['revendedor_id']) ? (int)$input['revendedor_id'] : null;

        try {
            $db = Connection::getInstance();

            // Resolve optional reseller filter (input is xui_user_id, FK in clientes is local id)
            $resellerLocalId = null;
            if ($resellerXuiId !== null) {
                $reseller = \App\Controllers\ResellerController::findResellerByEitherId($resellerXuiId);
                if ($reseller) {
                    $resellerLocalId = (int)$reseller['id'];
                }
            }

            $sql = "
                SELECT c.`id`, c.`telefono`, c.`username`, c.`line_id`,
                       c.`estado`, c.`fecha_vencimiento`, c.`created_at`,
                       r.`xui_user_id` AS revendedor_xui_id
                FROM `clientes` c
                LEFT JOIN `revendedores` r ON r.`id` = c.`revendedor_id`
                WHERE c.`telefono` = :phone
            ";
            $params = [':phone' => $phone];
            if ($resellerLocalId !== null) {
                $sql .= " AND c.`revendedor_id` = :rid";
                $params[':rid'] = $resellerLocalId;
            }
            $sql .= " ORDER BY c.`created_at` DESC";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            if (empty($rows)) {
                LoggerService::logFile("Phone lookup failed: {$phone}. Client not registered.", "info");
                LoggerService::logAction("BUSCAR_USUARIO", $input, ['found' => false]);
                $this->error("No se encontró ningún usuario registrado con el número: {$phone}", 404);
            }

            $clientes = array_map(fn($c) => [
                'id'                => (int)$c['id'],
                'telefono'          => $c['telefono'],
                'username'          => $c['username'],
                'line_id'           => (int)$c['line_id'],
                'revendedor_id'     => $c['revendedor_xui_id'] !== null ? (int)$c['revendedor_xui_id'] : null,
                'estado'            => $c['estado'],
                'fecha_vencimiento' => $c['fecha_vencimiento'],
                'created_at'        => $c['created_at']
            ], $rows);

            LoggerService::logFile("Phone lookup: {$phone} → " . count($clientes) . " cuenta(s) encontrada(s)", "info");
            LoggerService::logAction("BUSCAR_USUARIO", $input, [
                'found'        => true,
                'total_lineas' => count($clientes)
            ]);

            $this->success("Cuentas encontradas.", [
                'total'    => count($clientes),
                'clientes' => $clientes,
                // Backward-compat: si solo hay 1, también lo expongo como 'cliente'
                'cliente'  => count($clientes) === 1 ? $clientes[0] : null,
            ]);

        } catch (Exception $e) {
            LoggerService::logFile("Error in buscar endpoint: " . $e->getMessage(), "error");
            $this->error("Error interno del servidor al consultar el usuario: " . $e->getMessage(), 500);
        }
    }

    /**
     * Returns the account at position N for a given phone.
     * Used by the chatbot after the user picks a number from the account list.
     *
     * POST /api/seleccionar-cuenta
     * Body: { "telefono": "+57...", "indice": 2 }
     */
    public function seleccionarCuenta(): void
    {
        $input = $this->getRequestData();

        $this->validate($input, [
            'telefono' => 'required|string',
            'indice'   => 'required|integer',
        ]);

        $phone  = trim($input['telefono']);
        $indice = (int)$input['indice'];
        $resellerXuiId = isset($input['revendedor_id']) ? (int)$input['revendedor_id'] : null;

        if ($indice < 1) {
            $this->error("El índice debe ser 1 o mayor.", 400);
        }

        try {
            $db = Connection::getInstance();

            // Resolve optional reseller filter
            $resellerLocalId = null;
            if ($resellerXuiId !== null) {
                $reseller = \App\Controllers\ResellerController::findResellerByEitherId($resellerXuiId);
                if ($reseller) {
                    $resellerLocalId = (int)$reseller['id'];
                }
            }

            $sql = "
                SELECT c.`id`, c.`telefono`, c.`username`, c.`line_id`,
                       c.`estado`, c.`fecha_vencimiento`, c.`created_at`,
                       r.`xui_user_id` AS revendedor_xui_id
                FROM `clientes` c
                LEFT JOIN `revendedores` r ON r.`id` = c.`revendedor_id`
                WHERE c.`telefono` = :phone
            ";
            $params = [':phone' => $phone];
            if ($resellerLocalId !== null) {
                $sql .= " AND c.`revendedor_id` = :rid";
                $params[':rid'] = $resellerLocalId;
            }
            $sql .= " ORDER BY c.`created_at` ASC LIMIT 1 OFFSET :offset";

            $stmt = $db->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v, \PDO::PARAM_STR);
            }
            $stmt->bindValue(':offset', $indice - 1, \PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch();

            if (!$row) {
                $this->error("Número {$indice} no es válido. Por favor elige un número de la lista.", 404);
            }

            $cliente = [
                'indice'            => $indice,
                'username'          => $row['username'],
                'line_id'           => (int)$row['line_id'],
                'revendedor_id'     => $row['revendedor_xui_id'] !== null ? (int)$row['revendedor_xui_id'] : null,
                'estado'            => $row['estado'],
                'fecha_vencimiento' => $row['fecha_vencimiento'],
            ];

            LoggerService::logFile("Cuenta seleccionada: {$phone} → índice {$indice} = {$row['username']}", "info");
            $this->success("Cuenta seleccionada.", ['cliente' => $cliente]);

        } catch (Exception $e) {
            LoggerService::logFile("Error in seleccionarCuenta: " . $e->getMessage(), "error");
            $this->error("Error al seleccionar la cuenta: " . $e->getMessage(), 500);
        }
    }

    /**
     * Chatbot-friendly listing of all IPTV accounts for a given phone.
     * Returns a pre-formatted message ready to display + numbered indexes so the
     * chatbot can easily map "user typed N" to the corresponding username/line_id.
     *
     * POST /api/listar-mis-lineas
     * Body: {
     *   "telefono": "+57...",
     *   "revendedor_id": 17     (optional, filters to one reseller's clients)
     * }
     */
    public function listarMisLineas(): void
    {
        $input = $this->getRequestData();

        $this->validate($input, [
            'telefono' => 'required|string'
        ]);

        $phone = trim($input['telefono']);
        $resellerXuiId = isset($input['revendedor_id']) ? (int)$input['revendedor_id'] : null;

        try {
            $db = Connection::getInstance();

            // Resolve reseller filter (input is xui_user_id, but the FK in clientes is local id)
            $resellerLocalId = null;
            if ($resellerXuiId !== null) {
                $reseller = \App\Controllers\ResellerController::findResellerByEitherId($resellerXuiId);
                if ($reseller) {
                    $resellerLocalId = (int)$reseller['id'];
                }
            }

            // Build query — JOIN to surface reseller's xui_user_id consistently
            $sql = "
                SELECT c.`id`, c.`telefono`, c.`username`, c.`line_id`,
                       c.`estado`, c.`fecha_vencimiento`, c.`created_at`,
                       r.`xui_user_id` AS revendedor_xui_id
                FROM `clientes` c
                LEFT JOIN `revendedores` r ON r.`id` = c.`revendedor_id`
                WHERE c.`telefono` = :phone
            ";
            $params = [':phone' => $phone];
            if ($resellerLocalId !== null) {
                $sql .= " AND c.`revendedor_id` = :rid";
                $params[':rid'] = $resellerLocalId;
            }
            $sql .= " ORDER BY c.`created_at` ASC";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            $total = count($rows);

            if ($total === 0) {
                LoggerService::logAction("LISTAR_MIS_LINEAS", $input, ['total' => 0]);
                $this->success("No tienes cuentas registradas.", [
                    'total'    => 0,
                    'clientes' => [],
                    'message'  => "No encontré cuentas IPTV asociadas a tu número. ¿Quieres crear una?",
                ]);
            }

            // Build numbered emoji indicators (max 10 lines)
            $numEmojis = ['1️⃣', '2️⃣', '3️⃣', '4️⃣', '5️⃣', '6️⃣', '7️⃣', '8️⃣', '9️⃣', '🔟'];

            // Pre-format a ready-to-display message + per-index lookups for the bot
            $message = "📺 *Tus cuentas IPTV:*\n\n";
            $clientes = [];
            $indices = [];   // { "1": {username, line_id, ...}, "2": {...} }

            foreach ($rows as $i => $row) {
                $idx = $i + 1;
                $emoji = $numEmojis[$i] ?? "[{$idx}]";
                $estado = $row['estado'] === 'active' ? 'Activa 🟢' : ($row['estado'] === 'suspended' ? 'Suspendida 🔴' : $row['estado']);

                $message .= "{$emoji} *{$row['username']}*\n";
                $message .= "   📅 Vence: {$row['fecha_vencimiento']}\n";
                $message .= "   ⚡ {$estado}\n\n";

                $client = [
                    'indice'            => $idx,
                    'id'                => (int)$row['id'],
                    'telefono'          => $row['telefono'],
                    'username'          => $row['username'],
                    'line_id'           => (int)$row['line_id'],
                    'revendedor_id'     => $row['revendedor_xui_id'] !== null ? (int)$row['revendedor_xui_id'] : null,
                    'estado'            => $row['estado'],
                    'fecha_vencimiento' => $row['fecha_vencimiento'],
                ];
                $clientes[] = $client;
                $indices[(string)$idx] = $client;
            }

            if ($total > 1) {
                $message .= "Responde con el *número* (1 a {$total}) para gestionar esa cuenta.";
            } else {
                $message .= "Continúa para gestionar tu cuenta.";
            }

            LoggerService::logAction("LISTAR_MIS_LINEAS", $input, ['total' => $total]);

            $this->success("Listado de cuentas.", [
                'total'    => $total,
                'clientes' => $clientes,
                'indices'  => $indices,
                'message'  => $message,
            ]);

        } catch (Exception $e) {
            LoggerService::logFile("Error in listar-mis-lineas: " . $e->getMessage(), "error");
            $this->error("Error al listar tus cuentas: " . $e->getMessage(), 500);
        }
    }
}
