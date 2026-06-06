<?php

namespace App\Controllers;

use App\Database\Connection;
use App\Services\XuiService;
use App\Services\LoggerService;
use Exception;
use PDO;

/**
 * XUI.ONE IPTV Line Management Controller
 */
class LineController extends BaseController
{
    private XuiService $xuiService;

    public function __construct()
    {
        $this->xuiService = new XuiService();
    }

    /**
     * Consult direct line status on XUI.ONE panel
     * 
     * POST /api/consultar-linea
     */
    public function consultar(): void
    {
        $input = $this->getRequestData();

        try {
            $this->maybeUseReseller($input);
            ['line_id' => $lineId, 'xui_data' => $xuiData] = $this->resolveLineId($input);

            if ($xuiData === null) {
                $xuiData = $this->xuiService->getLine($lineId);
            }

            if (empty($xuiData)) {
                $this->error("La línea con ID {$lineId} no existe en XUI.ONE.", 404);
            }

            // Map and format dates appropriately for output (support both direct and nested data payloads)
            $target = isset($xuiData['data']) ? $xuiData['data'] : $xuiData;
            $exp = $target['exp_date'] ?? null;
            // XUI.ONE uses 2147483647 (int32 max) as the sentinel for "never expires"
            if (is_numeric($exp) && (int)$exp >= 2147483647) {
                $expiryFormatted = 'Nunca (Ilimitada)';
            } else {
                $expiryFormatted = is_numeric($exp) ? date('Y-m-d H:i:s', (int)$exp) : $exp;
                if (empty($expiryFormatted)) {
                    $expiryFormatted = 'Nunca (Ilimitada)';
                }
            }

            $statusText = (bool)($target['enabled'] ?? true) ? 'Activo 🟢' : 'Suspendido 🔴';

            // Create a gorgeous pre-formatted message block so the chatbot can print it in 1 click!
            $messageBody = "👤 Usuario: " . ($target['username'] ?? 'N/A') . "\n"
                         . "📅 Vencimiento: " . $expiryFormatted . "\n"
                         . "🔌 Conexiones: " . (int)($target['max_connections'] ?? 1) . "\n"
                         . "⚡ Estado: " . $statusText;

            $response = [
                'line_id'         => $lineId,
                'username'        => $target['username'] ?? 'N/A',
                'exp_date'        => $expiryFormatted,
                'max_connections' => (int)($target['max_connections'] ?? 1),
                'enabled'         => (bool)($target['enabled'] ?? true),
                'message'         => $messageBody
            ];

            LoggerService::logAction("CONSULTAR_LINEA", $input, $response);

            // If the chatbot requests plain text format for direct unparsed display
            if (isset($_GET['format']) && $_GET['format'] === 'text') {
                header('Content-Type: text/plain; charset=utf-8');
                echo $messageBody;
                exit;
            }

            // Sync fresh XUI data back to local cache so buscar-usuario / listar-mis-lineas stay accurate
            try {
                $db = Connection::getInstance();
                $db->prepare("
                    UPDATE `clientes`
                    SET `estado` = :estado, `fecha_vencimiento` = :exp
                    WHERE `line_id` = :lid
                ")->execute([
                    ':estado' => $response['enabled'] ? 'active' : 'suspended',
                    ':exp'    => $response['exp_date'],
                    ':lid'    => $lineId,
                ]);
            } catch (\Exception $cacheEx) {
                LoggerService::logFile("consultar-linea: cache sync failed: " . $cacheEx->getMessage(), "warning");
            }

            // Clean custom response matching requested chatbot specifications
            $this->json(array_merge(['success' => true], $response));

        } catch (Exception $e) {
            LoggerService::logFile("Error in consultar-linea: " . $e->getMessage(), "error");
            $this->error("Error al consultar la línea en XUI.ONE: " . $e->getMessage(), 500);
        } finally {
            $this->xuiService->clearResellerAuth();
        }
    }

    /**
     * Provision a new IPTV Line and record database association
     * 
     * POST /api/crear-linea
     */
    public function crear(): void
    {
        $input = $this->getRequestData();

        $this->validate($input, [
            'telefono' => 'required|string',
            'username' => 'required|string',
            'password' => 'required|string'
        ]);

        $phone = trim($input['telefono']);
        $username = trim($input['username']);
        $password = trim($input['password']);
        $packageId = isset($input['package_id']) ? (int)$input['package_id'] : null;
        $connections = isset($input['max_connections']) ? (int)$input['max_connections'] : null;

        try {
            $reseller = $this->maybeUseReseller($input);
            $resellerId = $reseller ? (int)$reseller['id'] : null;
            $resellerXuiId = $reseller ? (int)$reseller['xui_user_id'] : null;

            $db = Connection::getInstance();

            // 1. Only username must be unique (a single phone may own multiple IPTV accounts —
            //    family setups, multi-device, reseller buying for relatives, etc).
            $stmt = $db->prepare("SELECT id FROM `clientes` WHERE `username` = :user LIMIT 1");
            $stmt->execute([':user' => $username]);
            if ($stmt->fetch()) {
                $this->error("El nombre de usuario '{$username}' ya está registrado. Elige otro.", 400);
            }

            // 2. Call XUI.ONE to create the line (auth mode chosen above: admin or reseller)
            $xuiResult = $this->xuiService->createLine($username, $password, $packageId, $connections);

            // In XUI.ONE, creation response contains the newly generated line details
            // Support formats where line_id is returned inside 'id' or standard fields
            $target = isset($xuiResult['data']) ? $xuiResult['data'] : $xuiResult;
            $lineId = (int)($target['id'] ?? $target['line_id'] ?? 0);
            
            if ($lineId === 0) {
                // If it fails to return an ID, parse xui success signals
                if (isset($xuiResult['success']) && !$xuiResult['success']) {
                    throw new Exception($xuiResult['message'] ?? "El panel XUI.ONE denegó la creación.");
                }
                // Also parse standard status if present
                if (isset($xuiResult['status']) && $xuiResult['status'] === 'STATUS_FAILURE') {
                    throw new Exception($xuiResult['message'] ?? "Fallo reportado por el panel XUI.ONE.");
                }
                throw new Exception("El panel XUI.ONE no devolvió un ID de línea válido. Payload: " . json_encode($xuiResult));
            }

            // 3. Obtain initial expiration details from XUI
            $exp = $target['exp_date'] ?? (time() + (30 * 86400)); // Default 30 days if not returned
            $expiryDate = is_numeric($exp) ? date('Y-m-d H:i:s', (int)$exp) : $exp;

            // 4. Save client reference locally
            $insertStmt = $db->prepare("
                INSERT INTO `clientes` (`telefono`, `username`, `line_id`, `revendedor_id`, `estado`, `fecha_vencimiento`, `created_at`)
                VALUES (:phone, :username, :line_id, :revendedor_id, 'active', :expiry, NOW())
            ");

            $insertStmt->execute([
                ':phone'         => $phone,
                ':username'      => $username,
                ':line_id'       => $lineId,
                ':revendedor_id' => $resellerId,
                ':expiry'        => $expiryDate
            ]);

            $responseData = [
                'telefono'          => $phone,
                'username'          => $username,
                'line_id'           => $lineId,
                'revendedor_id'     => $resellerXuiId,   // ← devolver el xui_user_id (lo que el bot usa)
                'fecha_vencimiento' => $expiryDate,
                'estado'            => 'active'
            ];

            LoggerService::logFile("IPTV line {$lineId} successfully created for client: {$phone}", "info");
            LoggerService::logAction("CREAR_LINEA", $input, $responseData);

            $this->success("Línea IPTV creada y vinculada correctamente.", $responseData, 201);

        } catch (Exception $e) {
            LoggerService::logFile("Error in crear-linea: " . $e->getMessage(), "error");
            $this->error("Error al crear la línea IPTV: " . $e->getMessage(), 500);
        } finally {
            $this->xuiService->clearResellerAuth();
        }
    }

    /**
     * Direct Line Expiry Date renewal operation
     * 
     * POST /api/renovar-linea
     */
    public function renovar(): void
    {
        $input = $this->getRequestData();

        // Either 'dias' (explicit) or 'package_id' (derives dias from XUI package duration) is required.
        $hasDays = isset($input['dias']) && $input['dias'] !== '';
        $hasPackage = isset($input['package_id']) && $input['package_id'] !== '';
        if (!$hasDays && !$hasPackage) {
            $this->error("Debes proporcionar 'dias' o 'package_id' para renovar.", 422);
        }

        try {
            $reseller = $this->maybeUseReseller($input);
            ['line_id' => $lineId, 'xui_data' => $lineDetails] = $this->resolveLineId($input);
            if ($lineDetails === null) {
                $lineDetails = $this->xuiService->getLine($lineId);
            }
            if (empty($lineDetails) || $lineId <= 0) {
                $this->error("La línea con ID {$lineId} no existe en XUI.ONE.", 404);
            }

            // Resolve days from package_id if provided (and no explicit 'dias' override)
            $packageId = $hasPackage ? (int)$input['package_id'] : null;
            if ($packageId !== null && !$hasDays) {
                $days = $this->daysFromPackage($packageId);
                if ($days <= 0) {
                    $this->error("No se pudo determinar la duración del package_id={$packageId}.", 400);
                }
            } else {
                $days = (int)$input['dias'];
            }

            $target = isset($lineDetails['data']) ? $lineDetails['data'] : $lineDetails;
            $currentExp = $target['exp_date'] ?? null;
            $currentTimestamp = time();

            if (is_numeric($currentExp)) {
                $currentExpTimestamp = (int)$currentExp;
            } elseif (is_string($currentExp) && !empty($currentExp)) {
                $currentExpTimestamp = strtotime($currentExp);
            } else {
                $currentExpTimestamp = $currentTimestamp;
            }

            // 2. Extend expiration timestamp (calendar-accurate for month/year packages)
            $baseTimestamp = ($currentExpTimestamp > $currentTimestamp) ? $currentExpTimestamp : $currentTimestamp;
            $newExpirationFormatted = $this->computeExpiry($baseTimestamp, $days, $packageId);

            // 3. Verify reseller has enough credits BEFORE touching XUI (avoid partial state).
            $cost = 0;
            if ($reseller !== null && $packageId !== null) {
                $cost = $this->packageCredits($packageId);
                if ($cost > 0) {
                    $this->xuiService->useResellerAuth($reseller['xui_api_key']);
                    try {
                        $info     = $this->xuiService->request('user_info', []);
                        $infoData = isset($info['data']) && is_array($info['data']) ? $info['data'] : $info;
                        $balance  = (int)($infoData['credits'] ?? 0);
                    } finally {
                        $this->xuiService->clearResellerAuth();
                    }
                    if ($balance < $cost) {
                        $this->error(
                            "Saldo insuficiente: se necesitan {$cost} crédito(s) para renovar pero el revendedor solo tiene {$balance}. Contacta con tu agente.",
                            402
                        );
                    }
                }
            }

            // 4. Update expiry via admin API — the reseller API silently ignores exp_date
            //    in edit_line and returns STATUS_SUCCESS without actually changing the date.
            $editPayload = ['exp_date' => $newExpirationFormatted];
            if ($packageId !== null) {
                $editPayload['package_id'] = $packageId;
            }
            $this->xuiService->editLineAsAdmin($lineId, $editPayload);

            // 5. Ensure line is activated
            $this->xuiService->requestAsAdmin('enable_line', ['id' => $lineId]);

            // 6. Update local database
            $db = Connection::getInstance();
            $stmt = $db->prepare("
                UPDATE `clientes`
                SET `estado` = 'active', `fecha_vencimiento` = :expiry
                WHERE `line_id` = :line_id
            ");
            $stmt->execute([
                ':expiry'  => $newExpirationFormatted,
                ':line_id' => $lineId
            ]);

            $responseData = [
                'line_id'           => $lineId,
                'dias_adicionados'  => $days,
                'nueva_expiracion'  => $newExpirationFormatted,
                'estado'            => 'active'
            ];
            if ($packageId !== null) {
                $responseData['package_id'] = $packageId;
            }

            // 7. Deduct credits after successful renewal.
            if ($reseller !== null && $cost > 0) {
                $balanceInfo = $this->chargeResellerCredits($reseller, $cost);
                $responseData['revendedor_id']       = (int)$reseller['xui_user_id'];
                $responseData['creditos_cobrados']   = $cost;
                $responseData['creditos_restantes']  = $balanceInfo['after'];
            }

            LoggerService::logFile("IPTV line {$lineId} directly renewed for {$days} days.", "info");
            LoggerService::logAction("RENOVAR_LINEA", $input, $responseData);

            $this->success("Línea renovada con éxito.", $responseData);

        } catch (Exception $e) {
            LoggerService::logFile("Error in renovar-linea: " . $e->getMessage(), "error");
            $this->error("Error al renovar la línea IPTV: " . $e->getMessage(), 500);
        } finally {
            $this->xuiService->clearResellerAuth();
        }
    }

    /**
     * Disable streams for an IPTV user
     * 
     * POST /api/suspender-linea
     */
    public function suspender(): void
    {
        $input = $this->getRequestData();

        try {
            $this->maybeUseReseller($input);
            $lineId = $this->resolveLineId($input)['line_id'];

            // 1. Call XUI API
            $this->xuiService->disableLine($lineId);

            // 2. Update local database status
            $db = Connection::getInstance();
            $stmt = $db->prepare("UPDATE `clientes` SET `estado` = 'suspended' WHERE `line_id` = :line_id");
            $stmt->execute([':line_id' => $lineId]);

            LoggerService::logFile("IPTV line {$lineId} suspended successfully.", "info");
            LoggerService::logAction("SUSPENDER_LINEA", $input, ['line_id' => $lineId, 'estado' => 'suspended']);

            $this->success("Línea suspendida correctamente.", [
                'line_id' => $lineId,
                'estado'  => 'suspended'
            ]);

        } catch (Exception $e) {
            LoggerService::logFile("Error in suspender-linea: " . $e->getMessage(), "error");
            $this->error("Error al suspender la línea: " . $e->getMessage(), 500);
        } finally {
            $this->xuiService->clearResellerAuth();
        }
    }

    /**
     * Re-enable stream access for an IPTV user
     * 
     * POST /api/activar-linea
     */
    public function activar(): void
    {
        $input = $this->getRequestData();

        try {
            $this->maybeUseReseller($input);
            $lineId = $this->resolveLineId($input)['line_id'];

            // 1. Call XUI API
            $this->xuiService->enableLine($lineId);

            // 2. Update local database status
            $db = Connection::getInstance();
            $stmt = $db->prepare("UPDATE `clientes` SET `estado` = 'active' WHERE `line_id` = :line_id");
            $stmt->execute([':line_id' => $lineId]);

            LoggerService::logFile("IPTV line {$lineId} activated successfully.", "info");
            LoggerService::logAction("ACTIVAR_LINEA", $input, ['line_id' => $lineId, 'estado' => 'active']);

            $this->success("Línea activada correctamente.", [
                'line_id' => $lineId,
                'estado'  => 'active'
            ]);

        } catch (Exception $e) {
            LoggerService::logFile("Error in activar-linea: " . $e->getMessage(), "error");
            $this->error("Error al activar la línea: " . $e->getMessage(), 500);
        } finally {
            $this->xuiService->clearResellerAuth();
        }
    }

    /**
     * Change client account password
     * 
     * POST /api/cambiar-password
     */
    public function cambiarPassword(): void
    {
        $input = $this->getRequestData();

        $this->validate($input, [
            'password' => 'required|string'
        ]);

        $newPass = trim($input['password']);

        try {
            $this->maybeUseReseller($input);
            $lineId = $this->resolveLineId($input)['line_id'];

            // Call XUI API
            $this->xuiService->changePassword($lineId, $newPass);

            LoggerService::logFile("Password changed for IPTV line {$lineId}.", "info");
            LoggerService::logAction("CAMBIAR_PASSWORD", ['line_id' => $lineId], ['success' => true]);

            $this->success("Contraseña actualizada con éxito.", [
                'line_id' => $lineId
            ]);

        } catch (Exception $e) {
            LoggerService::logFile("Error in cambiar-password: " . $e->getMessage(), "error");
            $this->error("Error al cambiar la contraseña: " . $e->getMessage(), 500);
        } finally {
            $this->xuiService->clearResellerAuth();
        }
    }

    /**
     * Change concurrent connections count
     * 
     * POST /api/cambiar-conexiones
     */
    public function cambiarConexiones(): void
    {
        $input = $this->getRequestData();

        $this->validate($input, [
            'conexiones' => 'required|integer'
        ]);

        $connections = (int)$input['conexiones'];

        try {
            $this->maybeUseReseller($input);
            $lineId = $this->resolveLineId($input)['line_id'];

            // Call XUI API
            $this->xuiService->changeConnections($lineId, $connections);

            LoggerService::logFile("Connections limit changed to {$connections} for IPTV line {$lineId}.", "info");
            LoggerService::logAction("CAMBIAR_CONEXIONES", $input, ['success' => true]);

            $this->success("Límite de conexiones actualizado con éxito.", [
                'line_id'    => $lineId,
                'conexiones' => $connections
            ]);

        } catch (Exception $e) {
            LoggerService::logFile("Error in cambiar-conexiones: " . $e->getMessage(), "error");
            $this->error("Error al cambiar el límite de conexiones: " . $e->getMessage(), 500);
        } finally {
            $this->xuiService->clearResellerAuth();
        }
    }

    /**
     * Look up an XUI package by id and convert its (duration, unit) pair to days.
     * Returns 0 if the package can't be found or its duration is undefined.
     */
    /**
     * Lookup how many credits a given package costs (XUI's official_credits field).
     * Uses admin auth because resellers can't query packages by id.
     */
    private function packageCredits(int $packageId): int
    {
        try {
            $resp = $this->xuiService->requestAsAdmin('get_package', ['id' => $packageId]);
            $pkg = isset($resp['data']) && is_array($resp['data']) ? $resp['data'] : $resp;
            return (int)($pkg['official_credits'] ?? 0);
        } catch (Exception $e) {
            LoggerService::logFile("packageCredits failed for package_id={$packageId}: " . $e->getMessage(), "warning");
            return 0;
        }
    }

    /**
     * Manually deduct credits from a reseller's balance — used on renew flows since
     * XUI's edit_line doesn't auto-charge. Reads balance live, subtracts, writes back
     * via admin edit_user, and refreshes the local creditos_cache. Returns
     * ['before' => int, 'after' => int].
     */
    private function chargeResellerCredits(array $reseller, int $cost): array
    {
        // 1. Read current balance via reseller auth (user_info).
        $this->xuiService->useResellerAuth($reseller['xui_api_key']);
        try {
            $info = $this->xuiService->request('user_info', []);
            $infoData = isset($info['data']) && is_array($info['data']) ? $info['data'] : $info;
            $current = (int)($infoData['credits'] ?? 0);
            $email = (string)($infoData['email'] ?? '');
        } finally {
            $this->xuiService->clearResellerAuth();
        }

        $newBalance = max(0, $current - $cost);

        // 2. Apply via admin edit_user, preserving username/email so XUI doesn't rewrite them.
        $this->xuiService->requestAsAdmin('edit_user', [
            'id'              => (int)$reseller['xui_user_id'],
            'username'        => $reseller['xui_username'],
            'email'           => $email,
            'credits'         => $newBalance,
            'member_group_id' => 2,
        ]);

        // 3. Update local cache.
        try {
            $db = Connection::getInstance();
            $stmt = $db->prepare("UPDATE `revendedores` SET `creditos_cache` = :c WHERE `id` = :id");
            $stmt->execute([':c' => $newBalance, ':id' => (int)$reseller['id']]);
        } catch (Exception $e) {
            LoggerService::logFile("chargeResellerCredits: failed to update cache: " . $e->getMessage(), "warning");
        }

        return ['before' => $current, 'after' => $newBalance];
    }

    /**
     * Calendar-accurate expiry calculation. For month/year packages, uses DateTime::modify()
     * so "6 months" = same day 6 months later, not 180 fixed days.
     */
    private function computeExpiry(int $baseTs, int $fallbackDays, ?int $packageId): string
    {
        if ($packageId) {
            try {
                $db   = Connection::getInstance();
                $stmt = $db->prepare("SELECT duracion, duracion_unidad FROM precios_paquetes WHERE package_id = :pid LIMIT 1");
                $stmt->execute([':pid' => $packageId]);
                $row  = $stmt->fetch();
                if ($row) {
                    $val  = (int)($row['duracion'] ?? 0);
                    $unit = strtolower(trim((string)($row['duracion_unidad'] ?? '')));
                    if ($val > 0 && in_array($unit, ['month', 'months', 'year', 'years'])) {
                        $modifier = in_array($unit, ['year', 'years']) ? "+{$val} years" : "+{$val} months";
                        $dt = new \DateTime();
                        $dt->setTimestamp($baseTs);
                        $dt->modify($modifier);
                        return $dt->format('Y-m-d H:i:s');
                    }
                }
            } catch (Exception $e) {
                LoggerService::logFile("computeExpiry: package lookup failed, using days fallback: " . $e->getMessage(), "warning");
            }
        }
        return date('Y-m-d H:i:s', $baseTs + $fallbackDays * 86400);
    }

    private function daysFromPackage(int $packageId): int
    {
        try {
            // Packages are global — always look them up with admin auth so this works
            // whether the surrounding request is in admin or reseller context.
            $resp = $this->xuiService->requestAsAdmin('get_package', ['id' => $packageId]);
            $pkg = isset($resp['data']) && is_array($resp['data']) ? $resp['data'] : $resp;
            $value = (int)($pkg['official_duration'] ?? 0);
            $unit = strtolower((string)($pkg['official_duration_in'] ?? ''));
            if ($value <= 0) return 0;
            return match ($unit) {
                'hours', 'hour'   => (int)ceil($value / 24),
                'days', 'day'     => $value,
                'weeks', 'week'   => $value * 7,
                'months', 'month' => $value * 30,
                'years', 'year'   => $value * 365,
                default           => $value,
            };
        } catch (Exception $e) {
            LoggerService::logFile("daysFromPackage failed for package_id={$packageId}: " . $e->getMessage(), "warning");
            return 0;
        }
    }

    /**
     * Delete an IPTV line from XUI.ONE and remove it from the local cache.
     *
     * POST /api/eliminar-linea
     * Body: { "username": "X" }  or  { "line_id": 123 }
     */
    public function eliminar(): void
    {
        $input = $this->getRequestData();

        try {
            $this->maybeUseReseller($input);
            $lineId = $this->resolveLineId($input)['line_id'];
            if ($lineId <= 0) {
                $this->error("No se pudo determinar el line_id.", 400);
            }

            $this->xuiService->deleteLine($lineId);

            $db = Connection::getInstance();
            $stmt = $db->prepare("DELETE FROM `clientes` WHERE `line_id` = :line_id");
            $stmt->execute([':line_id' => $lineId]);
            $deletedRows = $stmt->rowCount();

            LoggerService::logFile("IPTV line {$lineId} deleted from XUI and BD ({$deletedRows} local rows).", "info");
            LoggerService::logAction("ELIMINAR_LINEA", $input, ['line_id' => $lineId, 'filas_borradas_local' => $deletedRows]);

            $this->success("Línea eliminada correctamente.", [
                'line_id'              => $lineId,
                'filas_borradas_local' => $deletedRows
            ]);
        } catch (Exception $e) {
            LoggerService::logFile("Error in eliminar-linea: " . $e->getMessage(), "error");
            $this->error("Error al eliminar la línea: " . $e->getMessage(), 500);
        } finally {
            $this->xuiService->clearResellerAuth();
        }
    }

    /**
     * If the request payload includes a revendedor_id, switches the XuiService into
     * reseller auth mode so all subsequent XUI calls in this request hit the reseller
     * API with the reseller's api_key. Returns the reseller row (or null if not present).
     * Each caller MUST clear the auth in a finally block.
     */
    private function maybeUseReseller(array $input, bool $strict = false): ?array
    {
        if (empty($input['revendedor_id'])) {
            return null;
        }
        try {
            return ResellerController::activateResellerAuth($this->xuiService, (int)$input['revendedor_id']);
        } catch (Exception $e) {
            if ($strict) {
                $this->error($e->getMessage(), 400);
            }
            // revendedor_id no existe en BD → usar auth admin como fallback
            LoggerService::logFile("maybeUseReseller: revendedor_id=" . $input['revendedor_id'] . " no encontrado, usando auth admin. " . $e->getMessage(), "warning");
            return null;
        }
    }

    /**
     * Resolve an input payload to a line_id. Accepts either an explicit `line_id` (cheapest path)
     * or a `username`, which is looked up first in the local clientes cache and then via XUI scan.
     * Returns ['line_id' => int, 'xui_data' => ?array] — xui_data is populated when we fetched
     * the record from XUI as part of the resolution, so callers can skip a redundant get_line().
     * Sends a 4xx response and terminates if neither field is provided or the username is unknown.
     */
    private function resolveLineId(array $input): array
    {
        $db = Connection::getInstance();

        // Resolve by explicit line_id — still enforce ownership below
        if (!empty($input['line_id'])) {
            $lineId = (int)$input['line_id'];
            if (!empty($input['revendedor_id'])) {
                $reseller = ResellerController::findResellerByEitherId((int)$input['revendedor_id']);
                if ($reseller) {
                    $chk = $db->prepare("SELECT `revendedor_id` FROM `clientes` WHERE `line_id` = :lid LIMIT 1");
                    $chk->execute([':lid' => $lineId]);
                    $row = $chk->fetch();
                    if ($row && $row['revendedor_id'] !== null && (int)$row['revendedor_id'] !== (int)$reseller['id']) {
                        $this->error("No tienes permiso para acceder a esta línea.", 403);
                    }
                }
            }
            return ['line_id' => $lineId, 'xui_data' => null];
        }

        if (empty($input['username'])) {
            $this->error("Debes proporcionar 'line_id' o 'username'.", 400);
        }

        $username = trim((string)$input['username']);
        $providedPhone = isset($input['telefono']) ? trim((string)$input['telefono']) : '';

        $stmt = $db->prepare("SELECT `line_id`, `telefono`, `revendedor_id` FROM `clientes` WHERE `username` = :user LIMIT 1");
        $stmt->execute([':user' => $username]);
        $client = $stmt->fetch();

        if ($client) {
            // Verify the requesting reseller owns this client
            if (!empty($input['revendedor_id'])) {
                $reseller = ResellerController::findResellerByEitherId((int)$input['revendedor_id']);
                if ($reseller) {
                    if ($client['revendedor_id'] !== null && (int)$client['revendedor_id'] !== (int)$reseller['id']) {
                        // BD dice que pertenece a otro revendedor → bloquear
                        $this->error("No tienes permiso para acceder a esta línea.", 403);
                    }
                    if ($client['revendedor_id'] === null) {
                        // BD no tiene revendedor asignado — verificar en XUI con credenciales del revendedor
                        $this->xuiService->useResellerAuth($reseller['xui_api_key']);
                        $xuiCheck = null;
                        try {
                            $xuiCheck = $this->xuiService->findLineByUsername($username ?? '');
                        } finally {
                            $this->xuiService->clearResellerAuth();
                        }
                        if (!$xuiCheck) {
                            $this->error("No tienes permiso para acceder a esta línea.", 403);
                        }
                        // Corregir el revendedor_id en BD para consultas futuras
                        $db->prepare("UPDATE `clientes` SET `revendedor_id` = :rid WHERE `username` = :user AND `revendedor_id` IS NULL")
                           ->execute([':rid' => (int)$reseller['id'], ':user' => $username ?? '']);
                    }
                }
            }
            $this->upgradePlaceholderPhone($db, $username, $client['telefono'] ?? '', $providedPhone);
            // Patch missing revendedor_id if we now know which reseller owns this client
            if ($client['revendedor_id'] === null && !empty($input['revendedor_id'])) {
                $reseller = ResellerController::findResellerByEitherId((int)$input['revendedor_id']);
                if ($reseller) {
                    $patch = $db->prepare("UPDATE `clientes` SET `revendedor_id` = :rid WHERE `username` = :user AND `revendedor_id` IS NULL");
                    $patch->execute([':rid' => (int)$reseller['id'], ':user' => $username]);
                }
            }
            return ['line_id' => (int)$client['line_id'], 'xui_data' => null];
        }

        $remote = $this->xuiService->findLineByUsername($username);
        if (empty($remote)) {
            $this->error("El nombre de usuario IPTV '{$username}' no se encontró ni en BD local ni en XUI.ONE.", 404);
        }

        $lineId = (int)($remote['id'] ?? $remote['line_id'] ?? 0);
        if ($lineId > 0) {
            try {
                $exp = $remote['exp_date'] ?? null;
                if (is_numeric($exp) && (int)$exp >= 2147483647) {
                    $expFormatted = '2099-12-31 23:59:59';
                } else {
                    $expFormatted = is_numeric($exp) ? date('Y-m-d H:i:s', (int)$exp) : ($exp ?: '2099-12-31 23:59:59');
                }
                $phoneToStore = $providedPhone ?: ('xui-sync-' . $username);

                // Resolve reseller local id from xui_user_id if provided
                $resellerLocalId = null;
                if (!empty($input['revendedor_id'])) {
                    $reseller = ResellerController::findResellerByEitherId((int)$input['revendedor_id']);
                    if ($reseller) {
                        $resellerLocalId = (int)$reseller['id'];
                    }
                }

                $cache = $db->prepare("INSERT IGNORE INTO `clientes` (`telefono`, `username`, `line_id`, `revendedor_id`, `estado`, `fecha_vencimiento`) VALUES (:phone, :user, :line_id, :rid, 'active', :exp)");
                $cache->execute([
                    ':phone'   => $phoneToStore,
                    ':user'    => $username,
                    ':line_id' => $lineId,
                    ':rid'     => $resellerLocalId,
                    ':exp'     => $expFormatted
                ]);

                // Also patch existing rows that were cached without a reseller (INSERT IGNORE skips them)
                if ($resellerLocalId !== null) {
                    $patch = $db->prepare("UPDATE `clientes` SET `revendedor_id` = :rid WHERE `username` = :user AND `revendedor_id` IS NULL");
                    $patch->execute([':rid' => $resellerLocalId, ':user' => $username]);
                }
            } catch (Exception $cacheEx) {
                LoggerService::logFile("Cache to clientes failed for '{$username}': " . $cacheEx->getMessage(), "warning");
            }
        }

        return ['line_id' => $lineId, 'xui_data' => $remote];
    }

    /**
     * Replace a placeholder telefono ('xui-sync-*') with the real one when the chatbot provides it.
     * No-op if the row already has a real phone or the new phone is empty/identical.
     */
    private function upgradePlaceholderPhone(\PDO $db, string $username, string $currentPhone, string $newPhone): void
    {
        if ($newPhone === '' || $newPhone === $currentPhone) {
            return;
        }
        if (!str_starts_with($currentPhone, 'xui-sync-')) {
            return;
        }
        try {
            $upd = $db->prepare("UPDATE `clientes` SET `telefono` = :phone WHERE `username` = :user");
            $upd->execute([':phone' => $newPhone, ':user' => $username]);
            LoggerService::logFile("Phone upgraded for '{$username}': '{$currentPhone}' -> '{$newPhone}'", "info");
        } catch (Exception $e) {
            LoggerService::logFile("Phone upgrade failed for '{$username}': " . $e->getMessage(), "warning");
        }
    }

    /**
     * Verifica un username en XUI y lo vincula automáticamente al número de WhatsApp.
     *
     * Cuando se provee revendedor_id, XUI ONE es la fuente de verdad para la propiedad:
     * se consulta XUI con las credenciales del revendedor — si no lo encuentra, no le pertenece.
     *
     * POST /api/vincular-cuenta
     * Body: { "telefono": "+57...", "username": "mi_usuario_iptv", "revendedor_id": 17 }
     */
    public function vincularCuenta(): void
    {
        $input = $this->getRequestData();

        $this->validate($input, [
            'telefono' => 'required|string',
            'username' => 'required|string',
        ]);

        $phone    = trim($input['telefono']);
        $username = trim($input['username']);

        try {
            // Modo estricto: si viene revendedor_id pero no existe en BD → error 400.
            // NUNCA hacer fallback a admin cuando se provee revendedor_id; eso bypasearía
            // todos los chequeos de propiedad.
            $reseller   = $this->maybeUseReseller($input, !empty($input['revendedor_id']));
            $resellerId = $reseller ? (int)$reseller['id'] : null;

            $db = Connection::getInstance();

            // Bloquear siempre si el username es un revendedor registrado
            $resellerCheck = $db->prepare("SELECT id FROM `revendedores` WHERE LOWER(`xui_username`) = LOWER(:user) LIMIT 1");
            $resellerCheck->execute([':user' => $username]);
            if ($resellerCheck->fetch()) {
                $this->error("El usuario '{$username}' es un revendedor y no puede ser vinculado como línea de cliente.", 403);
            }

            // ── Contexto REVENDEDOR: XUI admin + member_id es la fuente de verdad ─
            // La resselerapi devuelve get_lines vacío en esta instancia de XUI One
            // (las líneas fueron creadas vía admin, no vía reseller API).
            // XUI almacena el reseller propietario en el campo member_id de cada línea.
            // Usamos credenciales admin + verificamos member_id == xui_user_id del reseller.
            if ($resellerId !== null) {
                $this->xuiService->clearResellerAuth(); // buscar con credenciales admin
                $xuiData = $this->xuiService->findLineByUsername($username);

                if (empty($xuiData)) {
                    $this->error("La cuenta '{$username}' no existe en el sistema IPTV.", 404);
                }

                // Verificar propiedad: member_id en XUI = xui_user_id del revendedor dueño
                $lineMemberId = isset($xuiData['member_id']) ? (string)$xuiData['member_id'] : null;
                if ($lineMemberId !== (string)$reseller['xui_user_id']) {
                    LoggerService::logFile("vincularCuenta IDOR bloqueado: '{$username}' member_id={$lineMemberId} esperado={$reseller['xui_user_id']}", "warning");
                    $this->error("La cuenta '{$username}' no pertenece a tu revendedor.", 403);
                }

                $target = isset($xuiData['data']) ? $xuiData['data'] : $xuiData;
                $lineId = (int)($target['id'] ?? $target['line_id'] ?? 0);
                if ($lineId === 0) {
                    $this->error("No se pudo obtener el ID de línea para '{$username}'.", 500);
                }

                $exp = $target['exp_date'] ?? null;
                if (is_numeric($exp) && (int)$exp >= 2147483647) {
                    $expiryFormatted = 'Nunca (Ilimitada)';
                    $expiryDb        = '2099-12-31 23:59:59';
                } else {
                    $expiryDb = $expiryFormatted = is_numeric($exp)
                        ? date('Y-m-d H:i:s', (int)$exp)
                        : ($exp ?? date('Y-m-d H:i:s', strtotime('+30 days')));
                }
                $enabled = (bool)($target['enabled'] ?? true);
                $estado  = $enabled ? 'active' : 'suspended';

                // Upsert en BD: actualizar si existe (corrigiendo revendedor_id si estaba mal),
                // insertar si no. Así el caché queda siempre consistente con XUI.
                $stmtEx = $db->prepare("SELECT id, telefono FROM `clientes` WHERE `username` = :user LIMIT 1");
                $stmtEx->execute([':user' => $username]);
                $existing = $stmtEx->fetch(\PDO::FETCH_ASSOC);

                if ($existing) {
                    $db->prepare("
                        UPDATE `clientes`
                        SET `telefono` = :phone, `revendedor_id` = :rid,
                            `estado` = :estado, `fecha_vencimiento` = :exp
                        WHERE `username` = :user
                    ")->execute([':phone' => $phone, ':rid' => $resellerId,
                                 ':estado' => $estado, ':exp' => $expiryDb, ':user' => $username]);
                    $yaExistia = true;
                } else {
                    $db->prepare("
                        INSERT INTO `clientes`
                            (`telefono`, `username`, `line_id`, `revendedor_id`, `estado`, `fecha_vencimiento`, `created_at`)
                        VALUES (:phone, :username, :line_id, :rid, :estado, :expiry, NOW())
                    ")->execute([':phone' => $phone, ':username' => $username, ':line_id' => $lineId,
                                 ':rid' => $resellerId, ':estado' => $estado, ':expiry' => $expiryDb]);
                    $yaExistia = false;
                }

                LoggerService::logAction("VINCULAR_CUENTA", $input, [
                    'username' => $username, 'line_id' => $lineId, 'ya_existia' => $yaExistia,
                ]);

                $prefix = $yaExistia ? "📺" : "✅ Cuenta vinculada exitosamente.\n\n📺";
                $this->json([
                    'success'           => true,
                    'vinculado'         => true,
                    'ya_existia'        => $yaExistia,
                    'username'          => $username,
                    'line_id'           => $lineId,
                    'telefono'          => $phone,
                    'estado'            => $estado,
                    'fecha_vencimiento' => $expiryFormatted,
                    'message'           => "{$prefix} *{$username}*\n📅 Vence: {$expiryFormatted}\n⚡ " . ($enabled ? 'Activo 🟢' : 'Suspendido 🔴'),
                ]);
                return;
            }

            // ── Contexto ADMIN (sin revendedor_id): lógica original ───────────────
            $stmt = $db->prepare("SELECT * FROM `clientes` WHERE `username` = :user LIMIT 1");
            $stmt->execute([':user' => $username]);
            $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($existing) {
                if ($existing['telefono'] !== $phone) {
                    $db->prepare("UPDATE `clientes` SET `telefono` = :phone WHERE `username` = :user")
                       ->execute([':phone' => $phone, ':user' => $username]);
                    $existing['telefono'] = $phone;
                }
                $exp     = $existing['fecha_vencimiento'];
                $enabled = ($existing['estado'] === 'active');
                $this->json([
                    'success'           => true,
                    'vinculado'         => true,
                    'ya_existia'        => true,
                    'username'          => $existing['username'],
                    'line_id'           => (int)$existing['line_id'],
                    'telefono'          => $existing['telefono'],
                    'estado'            => $existing['estado'],
                    'fecha_vencimiento' => $exp,
                    'message'           => "📺 *{$username}*\n📅 Vence: {$exp}\n⚡ " . ($enabled ? 'Activo 🟢' : 'Suspendido 🔴'),
                ]);
                return;
            }

            $xuiData = $this->xuiService->findLineByUsername($username);
            if (empty($xuiData)) {
                $this->error("No encontré el usuario '{$username}' en el sistema IPTV. Verifica que sea correcto.", 404);
            }

            $target = isset($xuiData['data']) ? $xuiData['data'] : $xuiData;
            $lineId = (int)($target['id'] ?? $target['line_id'] ?? 0);
            if ($lineId === 0) {
                $this->error("No se pudo obtener el ID de línea para '{$username}'.", 500);
            }

            $exp = $target['exp_date'] ?? null;
            if (is_numeric($exp) && (int)$exp >= 2147483647) {
                $expiryFormatted = 'Nunca (Ilimitada)';
                $expiryDb        = '2099-12-31 23:59:59';
            } else {
                $expiryDb = $expiryFormatted = is_numeric($exp)
                    ? date('Y-m-d H:i:s', (int)$exp)
                    : ($exp ?? date('Y-m-d H:i:s', strtotime('+30 days')));
            }
            $enabled = (bool)($target['enabled'] ?? true);
            $estado  = $enabled ? 'active' : 'suspended';

            $db->prepare("
                INSERT INTO `clientes`
                    (`telefono`, `username`, `line_id`, `revendedor_id`, `estado`, `fecha_vencimiento`, `created_at`)
                VALUES (:phone, :username, :line_id, :revendedor_id, :estado, :expiry, NOW())
            ")->execute([
                ':phone'         => $phone,
                ':username'      => $username,
                ':line_id'       => $lineId,
                ':revendedor_id' => null,
                ':estado'        => $estado,
                ':expiry'        => $expiryDb,
            ]);

            LoggerService::logAction("VINCULAR_CUENTA", $input, ['username' => $username, 'line_id' => $lineId]);

            $this->json([
                'success'           => true,
                'vinculado'         => true,
                'ya_existia'        => false,
                'username'          => $username,
                'line_id'           => $lineId,
                'telefono'          => $phone,
                'estado'            => $estado,
                'fecha_vencimiento' => $expiryFormatted,
                'message'           => "✅ Cuenta vinculada exitosamente.\n\n📺 *{$username}*\n📅 Vence: {$expiryFormatted}\n⚡ " . ($enabled ? 'Activo 🟢' : 'Suspendido 🔴'),
            ]);

        } catch (Exception $e) {
            LoggerService::logFile("Error in vincular-cuenta: " . $e->getMessage(), "error");
            $this->error("Error al vincular la cuenta: " . $e->getMessage(), 500);
        } finally {
            $this->xuiService->clearResellerAuth();
        }
    }

}
