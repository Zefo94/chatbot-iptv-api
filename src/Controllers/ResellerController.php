<?php

namespace App\Controllers;

use App\Database\Connection;
use App\Services\XuiService;
use App\Services\LoggerService;
use Exception;

/**
 * Reseller management: register, list, query balance, recharge credits.
 *
 * Resellers act as XUI.ONE users in the "Resellers" group. Each one has their own
 * api_key (issued by the admin from the XUI panel) which lets this middleware
 * authenticate as them so credits are charged to their balance when they sell lines.
 *
 * Only the admin (this middleware's X-API-Key) recharges balances — credits are
 * never auto-issued.
 */
class ResellerController extends BaseController
{
    private XuiService $xuiService;

    public function __construct()
    {
        $this->xuiService = new XuiService();
    }

    /**
     * Register a reseller in the local DB.
     *
     * POST /api/crear-revendedor
     * Body: {
     *   "nombre": "Juan",
     *   "telefono": "+57300...",   (optional)
     *   "xui_user_id": 17,         (the user_id of the reseller in XUI.ONE)
     *   "xui_username": "brenderos94",
     *   "xui_api_key": "52035387EB0A9C3E4CD8D6133B219493"
     * }
     */
    public function crear(): void
    {
        $input = $this->getRequestData();

        $this->validate($input, [
            'nombre'       => 'required|string',
            'xui_user_id'  => 'required|integer',
            'xui_username' => 'required|string',
            'xui_api_key'  => 'required|string',
        ]);

        $nombre = trim($input['nombre']);
        $telefono = isset($input['telefono']) ? trim((string)$input['telefono']) : '';
        $xuiUserId = (int)$input['xui_user_id'];
        $xuiUsername = trim($input['xui_username']);
        $xuiApiKey = trim($input['xui_api_key']);

        try {
            // Validate the api_key actually works by fetching user_info as the reseller.
            $creditos = $this->fetchResellerCredits($xuiApiKey);

            $db = Connection::getInstance();
            $stmt = $db->prepare("
                INSERT INTO `revendedores`
                    (`nombre`, `telefono`, `xui_user_id`, `xui_username`, `xui_api_key`, `creditos_cache`, `active`)
                VALUES
                    (:nombre, :telefono, :xui_user_id, :xui_username, :xui_api_key, :creditos, 1)
            ");
            $stmt->execute([
                ':nombre'       => $nombre,
                ':telefono'     => $telefono,
                ':xui_user_id'  => $xuiUserId,
                ':xui_username' => $xuiUsername,
                ':xui_api_key'  => $xuiApiKey,
                ':creditos'     => $creditos,
            ]);

            $newId = (int)$db->lastInsertId();
            LoggerService::logAction("CREAR_REVENDEDOR", ['xui_user_id' => $xuiUserId], ['id' => $newId, 'creditos' => $creditos]);

            $this->success("Revendedor registrado correctamente.", [
                'revendedor' => [
                    'revendedor_id' => $xuiUserId,    // ← este es el que el bot debe usar
                    'xui_user_id'   => $xuiUserId,
                    'local_id'      => $newId,        // ← solo informativo
                    'nombre'        => $nombre,
                    'telefono'      => $telefono,
                    'xui_username'  => $xuiUsername,
                    'creditos'      => $creditos,
                    'active'        => true,
                ]
            ], 201);
        } catch (Exception $e) {
            LoggerService::logFile("Error in crear-revendedor: " . $e->getMessage(), "error");
            $this->error("Error al registrar revendedor: " . $e->getMessage(), 500);
        }
    }

    /**
     * List all registered resellers with their cached balances.
     *
     * POST /api/listar-revendedores
     * Body: { "solo_activos": true }   (optional, default true)
     */
    public function listar(): void
    {
        $input = $this->getRequestData();
        $onlyActive = !isset($input['solo_activos']) || $input['solo_activos'];

        try {
            $db = Connection::getInstance();
            $sql = "SELECT `id`, `nombre`, `telefono`, `xui_user_id`, `xui_username`, `creditos_cache`, `active`, `created_at`, `updated_at`
                    FROM `revendedores`";
            if ($onlyActive) {
                $sql .= " WHERE `active` = 1";
            }
            $sql .= " ORDER BY `nombre` ASC";

            $rows = $db->query($sql)->fetchAll();
            $resellers = array_map(fn($r) => [
                'revendedor_id'  => (int)$r['xui_user_id'],   // ← este es el que el bot debe usar
                'xui_user_id'    => (int)$r['xui_user_id'],
                'local_id'       => (int)$r['id'],
                'nombre'         => $r['nombre'],
                'telefono'       => $r['telefono'],
                'xui_username'   => $r['xui_username'],
                'creditos_cache' => (int)$r['creditos_cache'],
                'active'         => (bool)$r['active'],
                'created_at'     => $r['created_at'],
                'updated_at'     => $r['updated_at'],
            ], $rows);

            $this->success("Lista de revendedores.", ['revendedores' => $resellers, 'total' => count($resellers)]);
        } catch (Exception $e) {
            LoggerService::logFile("Error in listar-revendedores: " . $e->getMessage(), "error");
            $this->error("Error al listar revendedores: " . $e->getMessage(), 500);
        }
    }

    /**
     * Get current credit balance of a reseller (live from XUI).
     *
     * POST /api/saldo-revendedor
     * Body: { "revendedor_id": 1 }
     */
    public function saldo(): void
    {
        $input = $this->getRequestData();
        $this->validate($input, ['revendedor_id' => 'required|integer']);

        try {
            $reseller = $this->loadResellerOrFail((int)$input['revendedor_id']);
            $creditos = $this->fetchResellerCredits($reseller['xui_api_key']);
            $this->updateCreditsCache((int)$reseller['id'], $creditos);

            $this->success("Saldo consultado.", [
                'revendedor_id' => (int)$reseller['xui_user_id'],   // ← lo que el chatbot envía/usa
                'xui_user_id'   => (int)$reseller['xui_user_id'],
                'local_id'      => (int)$reseller['id'],            // ← solo informativo
                'nombre'        => $reseller['nombre'],
                'xui_username'  => $reseller['xui_username'],
                'creditos'      => $creditos,
            ]);
        } catch (Exception $e) {
            LoggerService::logFile("Error in saldo-revendedor: " . $e->getMessage(), "error");
            $this->error("Error al consultar saldo: " . $e->getMessage(), 500);
        }
    }

    /**
     * Recharge credits for a reseller (admin operation). Adds N credits to the current balance.
     *
     * POST /api/recargar-creditos
     * Body: { "revendedor_id": 1, "creditos": 50, "nota": "Pago recibido por USDT 2026-05-29" }
     */
    public function recargar(): void
    {
        $input = $this->getRequestData();
        $this->validate($input, [
            'revendedor_id' => 'required|integer',
            'creditos'      => 'required|integer',
        ]);

        $creditosARecargar = (int)$input['creditos'];
        if ($creditosARecargar <= 0) {
            $this->error("La cantidad de créditos a recargar debe ser mayor a 0.", 400);
        }
        $nota = isset($input['nota']) ? trim((string)$input['nota']) : null;

        try {
            $reseller = $this->loadResellerOrFail((int)$input['revendedor_id']);

            // 1. Read current credits live (avoid stale cache)
            $creditosAntes = $this->fetchResellerCredits($reseller['xui_api_key']);
            $creditosNuevos = $creditosAntes + $creditosARecargar;

            // 2. Apply via admin edit_user, preserving username/password/email so XUI doesn't reset them
            $this->xuiService->request('edit_user', [
                'id'              => (int)$reseller['xui_user_id'],
                'username'        => $reseller['xui_username'],
                'email'           => $this->fetchResellerEmail($reseller['xui_api_key']),
                'credits'         => $creditosNuevos,
                'member_group_id' => 2, // Resellers group
            ]);

            // 3. Confirm by re-reading
            $creditosDespues = $this->fetchResellerCredits($reseller['xui_api_key']);
            $this->updateCreditsCache((int)$reseller['id'], $creditosDespues);

            // 4. Audit log
            $db = Connection::getInstance();
            $stmt = $db->prepare("
                INSERT INTO `recargas` (`revendedor_id`, `creditos_antes`, `creditos_recargados`, `creditos_despues`, `nota`)
                VALUES (:rid, :antes, :recargados, :despues, :nota)
            ");
            $stmt->execute([
                ':rid'        => (int)$reseller['id'],
                ':antes'      => $creditosAntes,
                ':recargados' => $creditosARecargar,
                ':despues'    => $creditosDespues,
                ':nota'       => $nota,
            ]);

            LoggerService::logFile("Recarga: revendedor {$reseller['xui_username']} +{$creditosARecargar} créditos ({$creditosAntes} → {$creditosDespues}).", "info");
            LoggerService::logAction("RECARGAR_CREDITOS", $input, [
                'creditos_antes'   => $creditosAntes,
                'creditos_despues' => $creditosDespues,
            ]);

            $this->success("Créditos recargados correctamente.", [
                'revendedor_id'    => (int)$reseller['xui_user_id'],
                'xui_user_id'      => (int)$reseller['xui_user_id'],
                'local_id'         => (int)$reseller['id'],
                'creditos_antes'   => $creditosAntes,
                'creditos_recargados' => $creditosARecargar,
                'creditos_despues' => $creditosDespues,
            ]);
        } catch (Exception $e) {
            LoggerService::logFile("Error in recargar-creditos: " . $e->getMessage(), "error");
            $this->error("Error al recargar créditos: " . $e->getMessage(), 500);
        }
    }

    /**
     * Audit trail of past recharges, newest first.
     *
     * POST /api/historial-recargas
     * Body: { "revendedor_id": 1, "limite": 50 }  (revendedor_id optional → all if absent)
     */
    public function historial(): void
    {
        $input = $this->getRequestData();
        $resellerId = isset($input['revendedor_id']) ? (int)$input['revendedor_id'] : null;
        $limit = isset($input['limite']) ? max(1, min(500, (int)$input['limite'])) : 50;

        try {
            $db = Connection::getInstance();
            if ($resellerId !== null) {
                // Accept either local id or xui_user_id (same convenience as other endpoints).
                $reseller = self::findResellerByEitherId($resellerId);
                if (!$reseller) {
                    $this->error("Revendedor con id {$resellerId} no encontrado.", 404);
                }
                $resellerId = (int)$reseller['id'];
                $stmt = $db->prepare("
                    SELECT r.*, rv.nombre, rv.xui_username
                    FROM `recargas` r
                    JOIN `revendedores` rv ON rv.id = r.revendedor_id
                    WHERE r.revendedor_id = :rid
                    ORDER BY r.created_at DESC
                    LIMIT {$limit}
                ");
                $stmt->execute([':rid' => $resellerId]);
            } else {
                $stmt = $db->prepare("
                    SELECT r.*, rv.nombre, rv.xui_username
                    FROM `recargas` r
                    JOIN `revendedores` rv ON rv.id = r.revendedor_id
                    ORDER BY r.created_at DESC
                    LIMIT {$limit}
                ");
                $stmt->execute();
            }
            $rows = $stmt->fetchAll();

            $items = array_map(fn($r) => [
                'id'                  => (int)$r['id'],
                'revendedor_id'       => (int)$r['revendedor_id'],
                'revendedor_nombre'   => $r['nombre'],
                'xui_username'        => $r['xui_username'],
                'creditos_antes'      => (int)$r['creditos_antes'],
                'creditos_recargados' => (int)$r['creditos_recargados'],
                'creditos_despues'    => (int)$r['creditos_despues'],
                'nota'                => $r['nota'],
                'created_at'          => $r['created_at'],
            ], $rows);

            $this->success("Historial de recargas.", ['recargas' => $items, 'total' => count($items)]);
        } catch (Exception $e) {
            LoggerService::logFile("Error in historial-recargas: " . $e->getMessage(), "error");
            $this->error("Error al consultar historial: " . $e->getMessage(), 500);
        }
    }

    /**
     * Soft-delete a reseller (sets active=0). Their existing lines stay attributed
     * to them in XUI, but no new operations can be routed through them.
     *
     * POST /api/eliminar-revendedor
     * Body: { "revendedor_id": 1 }
     */
    public function eliminar(): void
    {
        $input = $this->getRequestData();
        $this->validate($input, ['revendedor_id' => 'required|integer']);

        try {
            $reseller = $this->loadResellerOrFail((int)$input['revendedor_id']);
            $db = Connection::getInstance();
            $stmt = $db->prepare("UPDATE `revendedores` SET `active` = 0 WHERE `id` = :id");
            $stmt->execute([':id' => (int)$reseller['id']]);
            LoggerService::logAction("ELIMINAR_REVENDEDOR", $input, ['xui_username' => $reseller['xui_username']]);
            $this->success("Revendedor desactivado.", [
                'revendedor_id' => (int)$reseller['xui_user_id'],
                'xui_user_id'   => (int)$reseller['xui_user_id'],
                'local_id'      => (int)$reseller['id'],
            ]);
        } catch (Exception $e) {
            LoggerService::logFile("Error in eliminar-revendedor: " . $e->getMessage(), "error");
            $this->error("Error al desactivar revendedor: " . $e->getMessage(), 500);
        }
    }

    /**
     * Load a reseller row by id, trying both the internal local id (PK) and the xui_user_id.
     * This way the chatbot can pass either one without having to remember which is which.
     * Sends 404 if not found.
     */
    private function loadResellerOrFail(int $resellerId): array
    {
        $row = self::findResellerByEitherId($resellerId);
        if (!$row) {
            $this->error(
                "Revendedor con id {$resellerId} no encontrado. " .
                "Se buscó tanto en `id` (local) como en `xui_user_id`. " .
                "Usa /api/listar-revendedores para ver los IDs válidos.",
                404
            );
        }
        return $row;
    }

    /**
     * Resolve a reseller by the id sent in the request body.
     * The chatbot always sends the XUI panel user_id (what the admin sees in XUI), so we
     * match against `xui_user_id` first. We keep `id` (local PK) as a backward-compatible
     * fallback in case some integration still uses the internal id.
     */
    public static function findResellerByEitherId(int $resellerId): ?array
    {
        $db = Connection::getInstance();
        // 1) Primary: match by XUI panel user_id (what the user sees in the panel)
        $stmt = $db->prepare("SELECT * FROM `revendedores` WHERE `xui_user_id` = :id LIMIT 1");
        $stmt->execute([':id' => $resellerId]);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }
        // 2) Fallback: internal local id (legacy)
        $stmt = $db->prepare("SELECT * FROM `revendedores` WHERE `id` = :id LIMIT 1");
        $stmt->execute([':id' => $resellerId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Fetch the live credit balance of a reseller via their own api_key (user_info action). */
    private function fetchResellerCredits(string $resellerApiKey): int
    {
        $this->xuiService->useResellerAuth($resellerApiKey);
        try {
            $resp = $this->xuiService->request('user_info', []);
            $data = isset($resp['data']) && is_array($resp['data']) ? $resp['data'] : $resp;
            return (int)($data['credits'] ?? 0);
        } finally {
            $this->xuiService->clearResellerAuth();
        }
    }

    /** Reseller's email (needed when calling edit_user to avoid wiping it out). */
    private function fetchResellerEmail(string $resellerApiKey): string
    {
        $this->xuiService->useResellerAuth($resellerApiKey);
        try {
            $resp = $this->xuiService->request('user_info', []);
            $data = isset($resp['data']) && is_array($resp['data']) ? $resp['data'] : $resp;
            return (string)($data['email'] ?? '');
        } finally {
            $this->xuiService->clearResellerAuth();
        }
    }

    private function updateCreditsCache(int $resellerId, int $creditos): void
    {
        $db = Connection::getInstance();
        $stmt = $db->prepare("UPDATE `revendedores` SET `creditos_cache` = :c WHERE `id` = :id");
        $stmt->execute([':c' => $creditos, ':id' => $resellerId]);
    }

    /**
     * Public helper for other controllers (e.g. LineController) to scope a XuiService call
     * to a reseller. Returns the reseller row so the caller can save revendedor_id in clientes.
     * Throws if the reseller is inactive or not found.
     */
    public static function activateResellerAuth(XuiService $xuiService, int $resellerId): array
    {
        $row = self::findResellerByEitherId($resellerId);
        if (!$row) {
            throw new Exception(
                "Revendedor con id {$resellerId} no encontrado. " .
                "Verifica que esté registrado vía /api/crear-revendedor. " .
                "Acepto tanto el id local como el xui_user_id."
            );
        }
        if ((int)($row['active'] ?? 0) !== 1) {
            throw new Exception("Revendedor '{$row['xui_username']}' está desactivado (active=0).");
        }
        $xuiService->useResellerAuth($row['xui_api_key']);
        return $row;
    }
}
