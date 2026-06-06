<?php

namespace App\Services;

use Exception;

/**
 * XUI.ONE API Wrapper and Communications Client
 */
class XuiService
{
    private string $apiUrl;
    private string $apiKey;
    private string $username;
    private string $password;
    private string $resellerApiUrl;
    private int $defaultPackageId;
    private int $defaultConnections;

    /**
     * Per-call auth override. When non-null, request() uses these credentials and URL
     * instead of the admin .env defaults. Used by reseller-scoped operations.
     * Shape: ['url' => string, 'api_key' => string]
     */
    private ?array $authOverride = null;

    public function __construct()
    {
        $config = require dirname(__DIR__, 2) . '/config/xui.php';

        $this->apiUrl = $config['api_url'] ?? '';
        $this->apiKey = $config['api_key'] ?? '';
        $this->username = $config['username'] ?? '';
        $this->password = $config['password'] ?? '';
        $this->resellerApiUrl = $config['reseller_api_url'] ?? '';
        $this->defaultPackageId = $config['default_package_id'] ?? 1;
        $this->defaultConnections = $config['default_connections'] ?? 1;

        if (empty($this->apiUrl)) {
            LoggerService::logFile("XUI.ONE API URL is empty. Please check your .env file.", "warning");
        }
        if (empty($this->apiKey) && (empty($this->username) || empty($this->password))) {
            LoggerService::logFile("XUI.ONE authentication is missing. Define XUI_API_KEY or XUI_USERNAME/PASSWORD in .env.", "warning");
        }
    }

    /**
     * Helper to execute GET/POST cURL requests to XUI.ONE panel
     * 
     * @param string $action
     * @param array $params
     * @return array
     * @throws Exception
     */
    /**
     * Activate a per-call reseller auth context. Subsequent request() calls will use
     * the reseller's api_key and the reseller API URL until clearResellerAuth() runs.
     * Always wrap reseller-scoped calls with try/finally to guarantee the override is cleared.
     */
    public function useResellerAuth(string $resellerApiKey): void
    {
        if (empty($this->resellerApiUrl)) {
            throw new Exception("XUI_RESELLER_API_URL no está configurado en .env.");
        }
        if (empty($resellerApiKey)) {
            throw new Exception("api_key del reseller vacía.");
        }
        $this->authOverride = [
            'url'     => $this->resellerApiUrl,
            'api_key' => $resellerApiKey,
        ];
    }

    public function clearResellerAuth(): void
    {
        $this->authOverride = null;
    }

    /**
     * Force-use admin credentials for a single call, regardless of any active reseller override.
     * Useful for global lookups (packages, server stats) that resellers can't access via their API.
     */
    public function requestAsAdmin(string $action, array $params = []): array
    {
        $saved = $this->authOverride;
        $this->authOverride = null;
        try {
            return $this->request($action, $params);
        } finally {
            $this->authOverride = $saved;
        }
    }

    public function request(string $action, array $params = []): array
    {
        // Pick auth context: reseller override (if set) or admin defaults.
        $effectiveUrl = $this->authOverride['url'] ?? $this->apiUrl;
        $effectiveApiKey = $this->authOverride['api_key'] ?? $this->apiKey;

        if (empty($effectiveUrl)) {
            throw new Exception("XUI.ONE API URL is not configured. Check your .env file.");
        }

        // Build authenticated query string parameters
        $queryParams = [];
        if (!empty($effectiveApiKey)) {
            $queryParams['api_key'] = $effectiveApiKey;
        } else {
            $queryParams['username'] = $this->username;
            $queryParams['password'] = $this->password;
        }
        $queryParams['action'] = $action;

        // Merge action-specific parameters
        $allParams = array_merge($queryParams, $params);
        $queryString = http_build_query($allParams);

        // Dynamically choose separator to prevent double '??' when URL already contains or ends with a '?'
        $separator = '';
        if (!str_ends_with($effectiveUrl, '?')) {
            $separator = (strpos($effectiveUrl, '?') !== false) ? '&' : '?';
        }

        $url = $effectiveUrl . $separator . $queryString;

        LoggerService::logFile("Sending outbound XUI.ONE request action: '{$action}' to URL: " . $url, "debug");

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false, // Bypass SSL warnings for custom IP panels
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'User-Agent: IPTV-Middleware-Chatbot-API/1.0'
            ]
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            $errMessage = "cURL request failed for action '{$action}': " . $curlError;
            LoggerService::logFile($errMessage, "error");
            throw new Exception($errMessage);
        }

        if ($httpCode >= 400) {
            $errMessage = "XUI.ONE API responded with HTTP status code: {$httpCode} for action '{$action}'";
            LoggerService::logFile($errMessage . ". Response: " . substr($responseBody, 0, 500), "error");
            throw new Exception($errMessage);
        }

        $decoded = json_decode($responseBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Some panels return text or empty if successful. 
            // In XUI.ONE, standard actions return a success/error json.
            $errMessage = "Failed parsing XUI.ONE json response for action '{$action}': " . json_last_error_msg();
            LoggerService::logFile($errMessage . ". Raw Body: " . substr($responseBody, 0, 500), "warning");
            
            // Return raw response representation if valid HTML or status indicators
            return [
                'success' => stripos($responseBody, 'success') !== false,
                'raw_body' => $responseBody
            ];
        }

        // XUI.ONE responds HTTP 200 even on logical errors. Any STATUS_* other than STATUS_SUCCESS
        // is a failure (STATUS_FAILURE, STATUS_INVALID_DATE, STATUS_INVALID_USERNAME, etc.).
        if (is_array($decoded) && isset($decoded['status']) && $decoded['status'] !== 'STATUS_SUCCESS') {
            $apiError = $decoded['error'] ?? $decoded['status'];
            LoggerService::logFile("XUI.ONE action '{$action}' rechazada: {$apiError}", "error");
            throw new Exception("XUI.ONE rechazó la acción '{$action}': {$apiError}");
        }

        LoggerService::logFile("Received response from XUI.ONE. HTTP Code: {$httpCode}", "debug");
        return $decoded;
    }

    /**
     * Consult details of a specific IPTV Line
     * Action: get_line
     */
    public function getLine(int $lineId): array
    {
        return $this->request('get_line', ['id' => $lineId]);
    }

    /**
     * Resolve an IPTV line by username when the line_id is unknown.
     * Tries multiple XUI.ONE action conventions because different panel versions expose different names.
     * Returns the line payload (with id/line_id + exp_date + max_connections, etc.) or null if not found.
     */
    /**
     * @param bool $listOnly  When true, skips the direct single-user lookup (Strategy A) and goes
     *                        straight to the list-based search (Strategy B). Use this whenever the
     *                        XuiService is in reseller-auth mode: XUI One's single-user actions
     *                        (get_user, get_line) do NOT scope by reseller and will return any account
     *                        regardless of ownership, while the list actions (get_lines) ARE scoped.
     */
    public function findLineByUsername(string $username, bool $listOnly = false): ?array
    {
        $username = trim($username);
        if ($username === '') {
            return null;
        }

        // Strategy A: direct lookup by username (cheapest if supported).
        // CRITICAL: verify the returned username matches — some panel actions ignore the parameter
        // and return the API user (e.g. admin), which would otherwise produce a false positive.
        // SKIP when $listOnly = true (reseller context): these actions are not scoped by reseller in XUI.
        if (!$listOnly) {
            foreach (['get_line', 'get_user', 'user_info', 'get_info'] as $action) {
                try {
                    $response = $this->request($action, ['username' => $username]);
                    $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : $response;
                    if (!is_array($data)) {
                        continue;
                    }
                    $returnedUsername = isset($data['username']) ? (string)$data['username'] : '';
                    $hasId = !empty($data['id']) || !empty($data['line_id']);
                    if ($hasId && strcasecmp($returnedUsername, $username) === 0) {
                        LoggerService::logFile("findLineByUsername: matched '{$username}' via direct action '{$action}'", "info");
                        return $data;
                    }
                    if ($hasId && $returnedUsername !== '') {
                        LoggerService::logFile("findLineByUsername: direct action '{$action}' ignored username param (returned '{$returnedUsername}' instead of '{$username}')", "debug");
                    }
                } catch (Exception $e) {
                    LoggerService::logFile("findLineByUsername: direct action '{$action}' failed: " . $e->getMessage(), "debug");
                }
            }
        }

        // Strategy B: list all lines and filter locally.
        // Pass a large 'limit' since XUI.ONE paginates list endpoints at 50 by default — without it
        // we'd only see the first 50 lines and miss every account beyond that.
        // get_lines is the panel's authoritative source for IPTV lines; the others are kept as fallbacks
        // for compatibility with panel variants that use different action names.
        foreach (['get_lines', 'list_lines', 'lines', 'get_users', 'list_users', 'users'] as $action) {
            try {
                $response = $this->request($action, ['limit' => 50000]);
                $items = isset($response['data']) && is_array($response['data']) ? $response['data'] : $response;
                if (!is_array($items)) {
                    continue;
                }
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    if (isset($item['username']) && strcasecmp((string)$item['username'], $username) === 0) {
                        LoggerService::logFile("findLineByUsername: matched '{$username}' via list action '{$action}' (scanned " . count($items) . " items)", "info");
                        return $item;
                    }
                }
            } catch (Exception $e) {
                LoggerService::logFile("findLineByUsername: list action '{$action}' failed: " . $e->getMessage(), "debug");
            }
        }

        return null;
    }

    /**
     * Create an IPTV line for a user
     * Action: user (or create_line)
     */
    public function createLine(string $username, string $password, ?int $packageId = null, ?int $maxConnections = null): array
    {
        $pkgId = $packageId ?? $this->defaultPackageId;
        $maxConn = $maxConnections ?? $this->defaultConnections;
        // The reseller API expects 'package' (admin uses 'package_id'). Sending both is safe
        // since each endpoint ignores the parameter it doesn't recognise.
        $params = [
            'username'        => $username,
            'password'        => $password,
            'package_id'      => $pkgId,
            'package'         => $pkgId,
            'max_connections' => $maxConn,
        ];
        return $this->request('create_line', $params);
    }

    /**
     * Edit line parameters (e.g. expiration date, password, max connections).
     * IMPORTANT: this panel's edit_line regenerates username and password to random strings
     * when those fields aren't passed in the payload. We always look up the current line and
     * preserve them unless the caller is explicitly overriding them.
     * Action: edit_line (or user action_type=edit)
     */
    public function editLine(int $lineId, array $data): array
    {
        // Fields this panel resets to defaults when omitted from edit_line. We always pull the
        // current values and only let $data override them so partial edits (e.g. only password)
        // don't wipe unrelated state.
        $preserveFields = ['username', 'password', 'exp_date', 'max_connections', 'package_id',
                           'bouquet', 'vod_bouquet', 'series_bouquet', 'member_group_id',
                           'allowed_outputs', 'is_mag', 'is_e2', 'is_stalker', 'is_isplock',
                           'allowed_ips', 'allowed_ua', 'forced_country', 'is_restreamer'];
        $missingAny = false;
        foreach ($preserveFields as $f) {
            if (!isset($data[$f])) {
                $missingAny = true;
                break;
            }
        }
        if ($missingAny) {
            try {
                $current = $this->getLine($lineId);
                $currentData = isset($current['data']) && is_array($current['data']) ? $current['data'] : $current;
                if (is_array($currentData)) {
                    foreach ($preserveFields as $f) {
                        if (!isset($data[$f]) && isset($currentData[$f]) && $currentData[$f] !== null) {
                            $value = $currentData[$f];
                            // exp_date arrives as unix timestamp string but edit_line requires Y-m-d H:i:s
                            if ($f === 'exp_date' && is_numeric($value)) {
                                $value = date('Y-m-d H:i:s', (int)$value);
                            }
                            // Never preserve empty arrays — sending [] writes literal [] into the field
                            if (in_array($f, ['bouquet', 'vod_bouquet', 'series_bouquet',
                                              'allowed_ips', 'allowed_ua'], true)) {
                                if ($value === '[]' || $value === [] || $value === '' || $value === '0') continue;
                            }
                            $data[$f] = $value;
                        }
                    }
                }
            } catch (Exception $e) {
                LoggerService::logFile("editLine: failed to fetch current line {$lineId} for field preservation: " . $e->getMessage(), "warning");
            }
        }
        return $this->request('edit_line', array_merge(['id' => $lineId], $data));
    }

    /**
     * Same as editLine but forces admin credentials regardless of any active reseller override.
     * The reseller API silently ignores exp_date in edit_line; use this for any renewal that
     * must actually update the expiration date.
     */
    public function editLineAsAdmin(int $lineId, array $data): array
    {
        $saved = $this->authOverride;
        $this->authOverride = null;
        try {
            return $this->editLine($lineId, $data);
        } finally {
            $this->authOverride = $saved;
        }
    }

    /**
     * Update ONLY the expiration date of a line via admin API, with the absolute minimum payload.
     * Only sends id + exp_date + username + password — nothing else — so the panel does not
     * reset any other field (bouquets, is_stalker, is_isplock, allowed_outputs, etc.).
     * Use this when you need to correct exp_date after a reseller API call without touching anything else.
     */
    /**
     * Cross-package step 1: set new package_id + exp_date = baseExpDate via admin API.
     * Bouquets will be reset by the panel (expected — step 2 reseller API will restore them).
     * Minimal payload (id + package_id + exp_date + username + password) to avoid touching
     * other line settings.
     * After this call the line has the new package_id, so the following renewLineAsReseller
     * is treated as a same-package renewal and stacks exp_date from baseExpDate correctly.
     */
    public function setPackageAndBaseExpAsAdmin(int $lineId, int $packageId, string $baseExpDate): array
    {
        $current = $this->getLine($lineId);
        $data    = isset($current['data']) && is_array($current['data']) ? $current['data'] : $current;

        $saved = $this->authOverride;
        $this->authOverride = null;
        try {
            return $this->request('edit_line', [
                'id'         => $lineId,
                'package_id' => $packageId,
                'exp_date'   => $baseExpDate,
                'username'   => (string)($data['username'] ?? ''),
                'password'   => (string)($data['password'] ?? ''),
            ]);
        } finally {
            $this->authOverride = $saved;
        }
    }

    /**
     * Renew a line using the reseller's own API key.
     *
     * The reseller API edit_line:
     *  - Correctly assigns the package's bouquets (admin API ignores bouquet params entirely)
     *  - Extends exp_date by the package duration from the current exp_date automatically
     *  - Does NOT expose exp_date as a settable field (it's calculated by the panel)
     *
     * Use this instead of editLineAsAdmin for all payment-triggered renewals.
     */
    public function renewLineAsReseller(int $lineId, int $packageId, string $resellerApiKey): array
    {
        $current     = $this->getLine($lineId);
        $currentData = isset($current['data']) && is_array($current['data']) ? $current['data'] : $current;

        $params = [
            'id'              => $lineId,
            'package'         => $packageId,
            'package_id'      => $packageId,
            'username'        => (string)($currentData['username']        ?? ''),
            'password'        => (string)($currentData['password']        ?? ''),
            'max_connections' => (int)($currentData['max_connections']    ?? 1),
        ];

        // Fetch package bouquets and output_formats from XUI and include them explicitly.
        // XUI does NOT apply package bouquets automatically when package_id changes via edit_line.
        // IMPORTANT: send bouquet as raw JSON string (e.g. "[6,8,9,...]"), NOT as a PHP array —
        // http_build_query encodes PHP arrays as bouquet[0]=6&bouquet[1]=8 which XUI ignores.
        try {
            $pkgResp = $this->requestAsAdmin('get_package', ['id' => $packageId]);
            $pkgData = isset($pkgResp['data']) && is_array($pkgResp['data']) ? $pkgResp['data'] : $pkgResp;

            // 'bouquets' (plural) in get_package → 'bouquet' (singular) in edit_line; keep as JSON string.
            $bouquetsRaw = $pkgData['bouquets'] ?? null;
            if ($bouquetsRaw !== null && $bouquetsRaw !== '' && $bouquetsRaw !== '[]') {
                $params['bouquet'] = $bouquetsRaw; // JSON string → http_build_query sends bouquet=[6,8,...]
                LoggerService::logFile("renewLineAsReseller: applying pkg bouquets for line {$lineId}: {$bouquetsRaw}", "info");
            }

            // vod/series bouquets if the panel exposes them (keep as JSON strings)
            foreach (['vod_bouquets' => 'vod_bouquet', 'series_bouquets' => 'series_bouquet'] as $pkgField => $lineField) {
                $v = $pkgData[$pkgField] ?? null;
                if ($v !== null && $v !== '' && $v !== '[]') {
                    $params[$lineField] = $v;
                }
            }
        } catch (Exception $e) {
            LoggerService::logFile("renewLineAsReseller: could not fetch package bouquets for pkg {$packageId}: " . $e->getMessage(), "warning");
        }

        // Preserve allowed_outputs from the current line (format as returned by getLine — JSON string).
        // Do NOT send is_stalker, is_isplock, is_mag, is_e2, is_restreamer: those are package-defined
        // and sending 0 would override the package's own defaults.
        $v = $currentData['allowed_outputs'] ?? null;
        if ($v !== null && $v !== '' && $v !== [] && $v !== '[]') {
            $params['allowed_outputs'] = $v;
        }

        $this->useResellerAuth($resellerApiKey);
        try {
            return $this->request('edit_line', $params);
        } finally {
            $this->clearResellerAuth();
        }
    }

    /**
     * Delete an IPTV Line
     */
    public function deleteLine(int $lineId): array
    {
        return $this->request('delete_line', ['id' => $lineId]);
    }

    /**
     * Enable an IPTV Line
     */
    public function enableLine(int $lineId): array
    {
        return $this->request('enable_line', ['id' => $lineId]);
    }

    /**
     * Disable/Suspend an IPTV Line
     */
    public function disableLine(int $lineId): array
    {
        return $this->request('disable_line', ['id' => $lineId]);
    }

    /**
     * Change password for an IPTV Line
     */
    public function changePassword(int $lineId, string $newPassword): array
    {
        return $this->editLine($lineId, ['password' => $newPassword]);
    }

    /**
     * Change connections count for an IPTV Line
     */
    public function changeConnections(int $lineId, int $connections): array
    {
        return $this->editLine($lineId, ['max_connections' => $connections]);
    }
}
