<?php

namespace App\Controllers;

use App\Services\XuiService;
use App\Services\LoggerService;
use Exception;

/**
 * Read-only passthrough to the XUI.ONE Admin API.
 *
 * Exposes a single endpoint that forwards GET-style "action" commands to the
 * panel, restricted to a whitelist of NON-mutating actions. This lets the
 * dashboard explore the panel (packages, lines, users, stats, logs...) without
 * ever allowing create/edit/delete/start/stop/kill/mysql_query, etc.
 */
class PanelController extends BaseController
{
    private XuiService $xuiService;

    /**
     * Whitelist of safe, read-only XUI.ONE actions.
     * Anything that creates, edits, deletes, starts/stops, reloads, kills,
     * flushes or runs SQL is intentionally excluded.
     */
    private const READ_ONLY_ACTIONS = [
        // Listings & info
        'user_info', 'get_lines', 'get_mags', 'get_enigmas', 'get_users',
        'get_streams', 'get_channels', 'get_stations', 'get_movies',
        'get_series_list', 'get_episodes',
        // Single records
        'get_line', 'get_user', 'get_mag', 'get_enigma', 'get_stream',
        'get_channel', 'get_station', 'get_movie', 'get_series', 'get_episode',
        // Catalog
        'get_bouquets', 'get_bouquet', 'get_groups', 'get_group',
        'get_packages', 'get_package', 'get_categories', 'get_category',
        'get_access_codes', 'get_access_code', 'get_epgs', 'get_epg',
        'get_transcode_profiles', 'get_transcode_profile',
        'get_subresellers', 'get_subreseller', 'get_watch_folders', 'get_watch_folder',
        'get_rtmp_ips', 'get_rtmp_ip', 'get_hmacs', 'get_hmac',
        'get_blocked_isps', 'get_blocked_uas', 'get_blocked_ips',
        // Servers
        'get_servers', 'get_server',
        // Logs & events
        'activity_logs', 'live_connections', 'credit_logs', 'client_logs',
        'user_logs', 'stream_errors', 'watch_output', 'system_logs',
        'login_logs', 'restream_logs', 'mag_events',
        // Stats & settings (read)
        'get_settings', 'get_server_stats', 'get_fpm_status', 'get_rtmp_stats',
        'get_free_space', 'get_pids', 'get_certificate_info', 'get_directory',
    ];

    public function __construct()
    {
        $this->xuiService = new XuiService();
    }

    /**
     * Forward a read-only action to the XUI.ONE panel.
     *
     * POST /api/panel-consultar
     * Body: { "action": "get_packages", ...extraParams }
     * Any body key other than "action" is forwarded as a query parameter
     * (e.g. server_id for server-specific actions, or id for single records).
     */
    /**
     * Simplified, chatbot-friendly listing of sellable packages.
     * Excludes trials by default, returns just the fields a menu needs.
     *
     * POST /api/listar-paquetes
     * Body: { "incluir_trials": false }   (optional, default false)
     */
    public function listarPaquetes(): void
    {
        $input = $this->getRequestData();
        $includeTrials = !empty($input['incluir_trials']);

        try {
            $raw = $this->xuiService->request('get_packages', []);
            $items = isset($raw['data']) && is_array($raw['data']) ? $raw['data'] : $raw;
            if (!is_array($items)) {
                $items = [];
            }

            $config         = require dirname(__DIR__, 2) . '/config/payment.php';
            $pricePerCredit = (float)($config['paypal']['price_per_credit'] ?? 0.0);
            $currency       = strtoupper($config['paypal']['currency'] ?? 'EUR');

            $packages = [];
            foreach ($items as $p) {
                if (!is_array($p)) continue;
                $isTrial = ($p['is_trial'] ?? '0') === '1';
                if ($isTrial && !$includeTrials) continue;

                $duration = (int)($p['official_duration'] ?? 0);
                $unit     = (string)($p['official_duration_in'] ?? '');
                $days     = $this->durationToDays($duration, $unit);
                $credits  = (int)($p['official_credits'] ?? 0);
                $precio   = ($pricePerCredit > 0 && $credits > 0)
                    ? number_format($credits * $pricePerCredit, 2, '.', '')
                    : null;

                $packages[] = [
                    'id'              => (int)($p['id'] ?? 0),
                    'nombre'          => (string)($p['package_name'] ?? ''),
                    'duracion'        => $duration,
                    'duracion_unidad' => $unit,
                    'dias'            => $days,
                    'duracion_humana' => $this->humanDuration($duration, $unit),
                    'es_trial'        => $isTrial,
                    'creditos'        => $credits,
                    'precio'          => $precio,
                    'moneda'          => $currency,
                    'precio_label'    => $precio !== null ? "{$precio} {$currency}" : null,
                ];
            }

            LoggerService::logAction("LISTAR_PAQUETES", $input, ['total' => count($packages)]);
            $this->success("Paquetes disponibles.", ['paquetes' => $packages]);
        } catch (Exception $e) {
            LoggerService::logFile("Error in listar-paquetes: " . $e->getMessage(), "error");
            $this->error("Error al listar paquetes: " . $e->getMessage(), 500);
        }
    }

    /**
     * Convert XUI's (duration, unit) pair to a day count usable by edit_line / renew flows.
     */
    private function durationToDays(int $value, string $unit): int
    {
        $unit = strtolower(trim($unit));
        return match ($unit) {
            'hours', 'hour'   => (int)ceil($value / 24),
            'days', 'day'     => $value,
            'weeks', 'week'   => $value * 7,
            'months', 'month' => $value * 30,
            'years', 'year'   => $value * 365,
            default           => $value,
        };
    }

    private function humanDuration(int $value, string $unit): string
    {
        $unitEs = match (strtolower(trim($unit))) {
            'hours', 'hour'   => $value === 1 ? 'hora' : 'horas',
            'days', 'day'     => $value === 1 ? 'día' : 'días',
            'weeks', 'week'   => $value === 1 ? 'semana' : 'semanas',
            'months', 'month' => $value === 1 ? 'mes' : 'meses',
            'years', 'year'   => $value === 1 ? 'año' : 'años',
            default           => $unit,
        };
        return "{$value} {$unitEs}";
    }

    public function consultar(): void
    {
        $input = $this->getRequestData();

        $this->validate($input, [
            'action' => 'required|string'
        ]);

        $action = trim($input['action']);

        if (!in_array($action, self::READ_ONLY_ACTIONS, true)) {
            $this->error(
                "Acción no permitida. Este endpoint solo acepta acciones de lectura.",
                400,
                ['permitidas' => self::READ_ONLY_ACTIONS]
            );
        }

        // Everything except 'action' becomes a query parameter for the panel.
        $params = $input;
        unset($params['action']);

        try {
            $result = $this->xuiService->request($action, $params);

            LoggerService::logAction("PANEL_CONSULTA", $input, ['action' => $action, 'ok' => true]);

            $this->success("Consulta '{$action}' ejecutada correctamente.", [
                'action' => $action,
                'result' => $result
            ]);

        } catch (Exception $e) {
            LoggerService::logFile("Error en panel-consultar ({$action}): " . $e->getMessage(), "error");
            $this->error("Error al consultar el panel XUI.ONE: " . $e->getMessage(), 500);
        }
    }
}
