<?php
/**
 * Reseller Panel — login propio, ver créditos, gestionar precios de paquetes y ventas.
 */

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
    }
}

ini_set('session.gc_maxlifetime', 86400);
session_set_cookie_params(['lifetime' => 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
session_name('reseller_sess');
session_start();

require_once dirname(__DIR__) . '/src/Autoloader.php';
\App\Autoloader::register();

$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// ── API subroutes ────────────────────────────────────────────────────────────
if (str_starts_with($uri, '/reseller/api/')) {
    header('Content-Type: application/json; charset=utf-8');
    $action = substr($uri, strlen('/reseller/api/'));
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];

    $ok  = fn($msg, $data = []) => die(json_encode(['success' => true,  'message' => $msg, 'data' => $data]));
    $err = function($msg, $code = 400) { http_response_code($code); die(json_encode(['success' => false, 'message' => $msg, 'data' => []])); };

    // Login (sin sesión)
    if ($action === 'login') {
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        if (!$username || !$password) $err('Usuario y contraseña requeridos.');

        $db   = \App\Database\Connection::getInstance();
        $stmt = $db->prepare("SELECT * FROM `revendedores` WHERE `xui_username` = :u AND `active` = 1 LIMIT 1");
        $stmt->execute([':u' => $username]);
        $rev  = $stmt->fetch();

        if (!$rev || !$rev['panel_password'] || !password_verify($password, $rev['panel_password'])) {
            $err('Usuario o contraseña incorrectos.', 401);
        }

        $_SESSION['rev_id']       = (int)$rev['xui_user_id'];
        $_SESSION['rev_nombre']   = $rev['nombre'];
        $_SESSION['rev_username'] = $rev['xui_username'];
        $ok('Sesión iniciada.', ['nombre' => $rev['nombre']]);
    }

    // Logout
    if ($action === 'logout') {
        session_destroy();
        $ok('Sesión cerrada.');
    }

    // Todos los demás requieren sesión
    if (empty($_SESSION['rev_id'])) $err('No autenticado.', 401);

    $revId = (int)$_SESSION['rev_id'];
    $db    = \App\Database\Connection::getInstance();
    $stmt  = $db->prepare("SELECT * FROM `revendedores` WHERE `xui_user_id` = :id AND `active` = 1 LIMIT 1");
    $stmt->execute([':id' => $revId]);
    $rev   = $stmt->fetch();
    if (!$rev) { session_destroy(); $err('Revendedor no encontrado.', 401); }
    $localRevId = (int)$rev['id']; // local PK — used as FK in revendedor_precios, ordenes, etc.

    if ($action === 'info') {
        $xui = new \App\Services\XuiService();
        $xui->useResellerAuth($rev['xui_api_key']);
        try {
            $info    = $xui->request('user_info', []);
            $data    = isset($info['data']) && is_array($info['data']) ? $info['data'] : $info;
            $creditos = (int)($data['credits'] ?? 0);
        } finally {
            $xui->clearResellerAuth();
        }
        $db->prepare("UPDATE `revendedores` SET `creditos_cache` = :c WHERE `xui_user_id` = :id")
           ->execute([':c' => $creditos, ':id' => $revId]);
        $ok('OK', ['nombre' => $rev['nombre'], 'xui_username' => $rev['xui_username'], 'creditos' => $creditos]);
    }

    if ($action === 'paquetes') {
        $xui = new \App\Services\XuiService();
        try {
            $resp = $xui->requestAsAdmin('get_packages', []);
            $pkgs = $resp['data'] ?? $resp;
            if (!is_array($pkgs)) $pkgs = [];
        } catch (\Exception $e) {
            $err('Error al obtener paquetes: ' . $e->getMessage(), 500);
        }

        $misPrecios = [];
        $stmt = $db->prepare("SELECT * FROM `revendedor_precios` WHERE `revendedor_id` = :rid");
        $stmt->execute([':rid' => $localRevId]);
        foreach ($stmt->fetchAll() as $r) $misPrecios[(int)$r['package_id']] = $r;

        $result = array_map(function($pkg) use ($misPrecios) {
            $pid  = (int)$pkg['id'];
            $mine = $misPrecios[$pid] ?? null;
            $durVal  = (int)($pkg['official_duration'] ?? 0);
            $durUnit = strtolower((string)($pkg['official_duration_in'] ?? ''));
            return [
                'id'               => $pid,
                'nombre'           => $pkg['package_name'] ?? '',
                'duracion_humana'  => $durVal ? "{$durVal} {$durUnit}" : '—',
                'precio_propio'    => $mine ? (float)$mine['precio'] : null,
                'moneda_propia'    => $mine ? $mine['moneda'] : 'EUR',
                'activo'           => $mine ? (bool)$mine['activo'] : true,
                'tiene_precio_propio' => $mine !== null,
            ];
        }, $pkgs);

        $ok('OK', ['paquetes' => $result]);
    }

    if ($action === 'guardar-precio') {
        $packageId = (int)($input['package_id'] ?? 0);
        $precio    = (float)($input['precio'] ?? 0);
        $moneda    = trim($input['moneda'] ?? 'EUR');
        $activo    = isset($input['activo']) ? (bool)$input['activo'] : true;
        if (!$packageId) $err('package_id requerido.');

        $db->prepare("
            INSERT INTO `revendedor_precios` (`revendedor_id`, `package_id`, `precio`, `moneda`, `activo`)
            VALUES (:rid, :pid, :precio, :moneda, :activo)
            ON DUPLICATE KEY UPDATE
                `precio` = VALUES(`precio`), `moneda` = VALUES(`moneda`),
                `activo` = VALUES(`activo`), `updated_at` = CURRENT_TIMESTAMP
        ")->execute([':rid' => $localRevId, ':pid' => $packageId, ':precio' => $precio, ':moneda' => $moneda, ':activo' => $activo ? 1 : 0]);

        $ok('Precio guardado.');
    }

    if ($action === 'estadisticas') {
        $desde = trim($input['desde'] ?? date('Y-m-d', strtotime('-30 days')));
        $hasta = trim($input['hasta'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) $desde = date('Y-m-d', strtotime('-30 days'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) $hasta = date('Y-m-d');

        // Timezone offset from browser (JS getTimezoneOffset() = minutes to ADD to local to get UTC)
        // Clamped to [-840, 840] to reject garbage values
        $tzOffset = max(-840, min(840, (int)($input['tz_offset'] ?? 0)));

        // Convert local date boundaries to UTC datetime strings for querying
        // Local midnight in UTC = midnight + tzOffset minutes
        [$dy, $dm, $dd] = explode('-', $desde);
        [$hy, $hm, $hd] = explode('-', $hasta);
        $desdeUtc = gmdate('Y-m-d H:i:s', gmmktime(0,  0,  0,  (int)$dm, (int)$dd, (int)$dy) + $tzOffset * 60);
        $hastaUtc = gmdate('Y-m-d H:i:s', gmmktime(23, 59, 59, (int)$hm, (int)$hd, (int)$hy) + $tzOffset * 60);

        // For GROUP BY local date: shift stored UTC to local time before extracting date
        // local_time = UTC - tzOffset minutes  →  DATE_SUB(created_at, INTERVAL :tz MINUTE)
        $params = [':rid' => $localRevId, ':desde' => $desdeUtc, ':hasta' => $hastaUtc, ':tz' => $tzOffset];

        // Resumen general
        $stmt = $db->prepare("
            SELECT COUNT(*) as total_ordenes,
                   COALESCE(SUM(monto), 0) as total_monto,
                   COALESCE(AVG(monto), 0) as promedio_monto
            FROM `ordenes`
            WHERE `revendedor_id` = :rid
              AND `estado` = 'completed'
              AND `created_at` BETWEEN :desde AND :hasta
        ");
        $stmt->execute([':rid' => $localRevId, ':desde' => $desdeUtc, ':hasta' => $hastaUtc]);
        $resumen = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Ventas por día (agrupadas en hora local del navegador)
        $stmt = $db->prepare("
            SELECT DATE(DATE_SUB(`created_at`, INTERVAL :tz MINUTE)) as fecha,
                   COUNT(*) as ordenes, SUM(`monto`) as monto
            FROM `ordenes`
            WHERE `revendedor_id` = :rid
              AND `estado` = 'completed'
              AND `created_at` BETWEEN :desde AND :hasta
            GROUP BY DATE(DATE_SUB(`created_at`, INTERVAL :tz2 MINUTE))
            ORDER BY fecha ASC
        ");
        $stmt->execute([':rid' => $localRevId, ':desde' => $desdeUtc, ':hasta' => $hastaUtc,
                        ':tz' => $tzOffset, ':tz2' => $tzOffset]);
        $porDia = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Top paquetes
        $stmt = $db->prepare("
            SELECT `package_id`, COUNT(*) as ordenes, SUM(`monto`) as monto
            FROM `ordenes`
            WHERE `revendedor_id` = :rid
              AND `estado` = 'completed'
              AND `package_id` IS NOT NULL
              AND `created_at` BETWEEN :desde AND :hasta
            GROUP BY `package_id`
            ORDER BY ordenes DESC
            LIMIT 6
        ");
        $stmt->execute([':rid' => $localRevId, ':desde' => $desdeUtc, ':hasta' => $hastaUtc]);
        $topPkgs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Nombres de paquetes desde XUI
        $pkgNames = [];
        try {
            $xui  = new \App\Services\XuiService();
            $resp = $xui->requestAsAdmin('get_packages', []);
            $list = $resp['data'] ?? $resp;
            if (is_array($list)) {
                foreach ($list as $p) {
                    $pkgNames[(int)$p['id']] = $p['package_name'] ?? ('Paquete #' . $p['id']);
                }
            }
        } catch (\Exception $e) { /* fallback to IDs */ }

        foreach ($topPkgs as &$tp) {
            $tp['nombre'] = $pkgNames[(int)($tp['package_id'] ?? 0)] ?? ('Paquete #' . ($tp['package_id'] ?? '?'));
        }
        unset($tp);

        // Lista de transacciones
        $stmt = $db->prepare("
            SELECT o.`order_id`, o.`paypal_order_id`, o.`line_id`, o.`dias`,
                   o.`monto`, o.`estado`, o.`created_at`, o.`package_id`,
                   c.`username` as iptv_user
            FROM `ordenes` o
            LEFT JOIN `clientes` c ON c.`line_id` = o.`line_id`
            WHERE o.`revendedor_id` = :rid
              AND o.`created_at` BETWEEN :desde AND :hasta
            ORDER BY o.`created_at` DESC
            LIMIT 200
        ");
        $stmt->execute([':rid' => $localRevId, ':desde' => $desdeUtc, ':hasta' => $hastaUtc]);
        $txs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Añadir nombre del paquete a cada transacción
        foreach ($txs as &$tx) {
            $tx['pkg_nombre'] = $pkgNames[(int)($tx['package_id'] ?? 0)] ?? null;
        }
        unset($tx);

        $ok('OK', [
            'resumen'       => [
                'total_ordenes'  => (int)$resumen['total_ordenes'],
                'total_monto'    => round((float)$resumen['total_monto'], 2),
                'promedio_monto' => round((float)$resumen['promedio_monto'], 2),
            ],
            'por_dia'       => $porDia,
            'top_paquetes'  => $topPkgs,
            'transacciones' => $txs,
            'desde'         => $desde,
            'hasta'         => $hasta,
            'tz_offset'     => $tzOffset,
        ]);
    }

    $err('Acción no encontrada.', 404);
}

// ── HTML Panel ────────────────────────────────────────────────────────────────
$isAuth = !empty($_SESSION['rev_id']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Panel Revendedor · IPTV</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fira+Code:wght@400;500;600&family=Fira+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<style>
  :root{--bg:#0F172A;--surface:#1E293B;--surface-2:#334155;--border:#2c3c57;--text:#F8FAFC;--muted:#94A3B8;--accent:#22C55E;--accent-ink:#04240f;--info:#38BDF8;--danger:#F87171;--warn:#FBBF24;--purple:#A78BFA;--radius:10px;--mono:'Fira Code',ui-monospace,monospace;--sans:'Fira Sans',system-ui,sans-serif}
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:var(--bg);color:var(--text);font-family:var(--sans);font-size:15px;line-height:1.55;min-height:100vh}
  button{font-family:inherit;cursor:pointer}
  code,pre,.mono{font-family:var(--mono)}

  /* Login */
  .login-wrap{display:flex;align-items:center;justify-content:center;min-height:100vh}
  .login-card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:40px 36px;width:100%;max-width:380px}
  .brand{display:flex;align-items:center;gap:10px;margin-bottom:28px}
  .dot{width:10px;height:10px;border-radius:50%;background:var(--accent);box-shadow:0 0 12px var(--accent)}
  .brand h1{font-size:18px;font-weight:600}
  .brand small{color:var(--muted);font-weight:400}
  .form-row{margin-bottom:16px}
  .form-row label{display:block;font-size:13px;color:var(--muted);margin-bottom:6px}
  input,select{background:var(--bg);color:var(--text);border:1px solid var(--border);border-radius:8px;padding:10px 12px;font-size:14px;font-family:var(--mono);outline:none;transition:border-color .15s,box-shadow .15s;width:100%}
  input:focus,select:focus{border-color:var(--info);box-shadow:0 0 0 3px rgba(56,189,248,.18)}
  .err-msg{background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.35);border-radius:8px;padding:9px 12px;font-size:13px;color:var(--danger);margin-bottom:16px;display:none}
  .btn{display:inline-flex;align-items:center;gap:8px;border:none;border-radius:8px;padding:10px 16px;font-size:14px;font-weight:600;transition:filter .15s;cursor:pointer}
  .btn:disabled{opacity:.55;cursor:not-allowed}
  .btn-primary{background:var(--accent);color:var(--accent-ink);width:100%;justify-content:center}
  .btn-primary:hover:not(:disabled){filter:brightness(1.08)}
  .spinner{width:15px;height:15px;border:2px solid rgba(0,0,0,.25);border-top-color:var(--accent-ink);border-radius:50%;animation:spin .6s linear infinite}
  @keyframes spin{to{transform:rotate(360deg)}}

  /* Header */
  header{display:flex;align-items:center;gap:16px;flex-wrap:wrap;padding:12px 24px;background:var(--surface);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:30}
  .header-brand{display:flex;align-items:center;gap:10px;font-weight:600}
  .header-brand small{color:var(--muted);font-weight:400}
  .header-right{display:flex;align-items:center;gap:16px;margin-left:auto}
  .credits-pill{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);border-radius:20px;padding:4px 14px;font-size:13px;font-family:var(--mono);color:var(--accent);font-weight:600}
  .credits-pill.low{background:rgba(248,113,113,.12);border-color:rgba(248,113,113,.3);color:var(--danger)}
  .user-pill{font-size:13px;color:var(--muted)}
  .logout-btn{background:none;border:1px solid var(--border);color:var(--muted);border-radius:8px;padding:6px 12px;font-size:13px;transition:color .15s,border-color .15s}
  .logout-btn:hover{color:var(--danger);border-color:var(--danger)}

  /* Tabs */
  .tab-nav{display:flex;gap:4px;padding:20px 24px 0;border-bottom:1px solid var(--border);background:var(--surface);position:sticky;top:57px;z-index:20}
  .tab-btn{background:none;border:none;border-bottom:2px solid transparent;padding:10px 18px;font-size:14px;font-weight:500;color:var(--muted);transition:color .15s,border-color .15s;margin-bottom:-1px}
  .tab-btn:hover{color:var(--text)}
  .tab-btn.active{color:var(--accent);border-bottom-color:var(--accent)}
  .tab-content{display:none}
  .tab-content.active{display:block}

  main{max-width:1060px;margin:0 auto;padding:28px 24px}
  .page-title{font-size:22px;font-weight:600;margin-bottom:4px}
  .page-sub{color:var(--muted);font-size:13.5px;margin-bottom:24px}

  /* Cards */
  .card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
  .card-head{display:flex;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid var(--border)}
  .card-head h2{font-size:13px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);font-weight:600;margin:0}
  .reload-btn{background:var(--surface-2);color:var(--text);border:none;border-radius:8px;padding:7px 12px;font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px;transition:background .15s}
  .reload-btn:hover{background:#3f5170}

  /* Packages table */
  .pkg-table{width:100%;border-collapse:collapse}
  .pkg-table th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);padding:10px 16px;border-bottom:1px solid var(--border)}
  .pkg-table td{padding:10px 16px;border-bottom:1px solid #1a2a40;vertical-align:middle;font-size:14px}
  .pkg-table tr:last-child td{border-bottom:none}
  .pkg-table tr:hover td{background:rgba(255,255,255,.02)}
  .pkg-name{font-weight:500}
  .pkg-id{font-family:var(--mono);font-size:11px;color:var(--muted);margin-left:6px}
  .dur{font-size:12px;color:var(--muted)}
  .price-wrap{display:flex;align-items:center;gap:6px;flex-wrap:nowrap}
  .price-input{width:110px;text-align:right;padding:6px 8px;font-size:14px}
  .moneda-sel{background:var(--bg);color:var(--text);border:1px solid var(--border);border-radius:6px;padding:6px 8px;font-size:13px;font-family:var(--mono);cursor:pointer;min-width:72px}
  .activo-wrap{display:flex;align-items:center;gap:6px;font-size:13px;color:var(--muted);cursor:pointer;white-space:nowrap}
  .activo-wrap input{width:16px;height:16px;cursor:pointer;accent-color:var(--accent)}
  .save-btn{background:var(--accent);color:var(--accent-ink);border:none;border-radius:6px;padding:6px 14px;font-size:13px;font-weight:600;cursor:pointer;transition:filter .15s,opacity .15s;white-space:nowrap}
  .save-btn:hover{filter:brightness(1.08)}
  .save-btn:disabled{opacity:.5;cursor:not-allowed}
  .row-msg{font-size:12px;margin-left:8px;opacity:0;transition:opacity .3s;white-space:nowrap}
  .row-msg.ok{color:var(--accent);opacity:1}
  .row-msg.err{color:var(--danger);opacity:1}
  .own-badge{font-size:10px;background:rgba(56,189,248,.15);color:var(--info);border:1px solid rgba(56,189,248,.3);border-radius:4px;padding:1px 6px;margin-left:6px;font-family:var(--mono)}
  .loading{text-align:center;padding:32px;color:var(--muted);font-size:14px}
  .error-state{padding:20px;color:var(--danger);font-size:14px}

  /* ── Dashboard Ventas ── */
  .filter-bar{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;margin-bottom:24px}
  .filter-field{display:flex;flex-direction:column;gap:5px}
  .filter-field label{font-size:12px;color:var(--muted);font-weight:500}
  .filter-field input[type=date]{width:auto;min-width:140px;padding:8px 10px;font-size:13px}
  .quick-btns{display:flex;gap:6px;flex-wrap:wrap;align-self:flex-end}
  .quick-btn{background:var(--surface-2);border:1px solid var(--border);color:var(--muted);border-radius:6px;padding:7px 12px;font-size:12px;font-weight:600;transition:background .15s,color .15s,border-color .15s}
  .quick-btn:hover,.quick-btn.active-q{background:rgba(34,197,94,.15);border-color:rgba(34,197,94,.4);color:var(--accent)}
  .apply-btn{background:var(--accent);color:var(--accent-ink);border:none;border-radius:8px;padding:8px 18px;font-size:13px;font-weight:700;transition:filter .15s;align-self:flex-end}
  .apply-btn:hover{filter:brightness(1.08)}
  .apply-btn:disabled{opacity:.55;cursor:not-allowed}

  /* Stat cards */
  .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px}
  .stat-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:20px 20px 18px}
  .stat-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;margin-bottom:14px}
  .stat-icon.green{background:rgba(34,197,94,.15)}
  .stat-icon.blue{background:rgba(56,189,248,.15)}
  .stat-icon.purple{background:rgba(167,139,250,.15)}
  .stat-icon.yellow{background:rgba(251,191,36,.15)}
  .stat-label{font-size:12px;color:var(--muted);font-weight:500;margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px}
  .stat-value{font-size:26px;font-weight:700;font-family:var(--mono);line-height:1.1}
  .stat-sub{font-size:12px;color:var(--muted);margin-top:4px}

  /* Charts */
  .charts-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px}
  .chart-wrap{padding:16px 12px 12px;position:relative;height:240px}
  .chart-wrap canvas{max-height:100%}

  /* Transactions table */
  .tx-table{width:100%;border-collapse:collapse}
  .tx-table th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);padding:10px 14px;border-bottom:1px solid var(--border);white-space:nowrap}
  .tx-table td{padding:9px 14px;border-bottom:1px solid #1a2a40;font-size:13px;vertical-align:middle}
  .tx-table tr:last-child td{border-bottom:none}
  .tx-table tr:hover td{background:rgba(255,255,255,.02)}
  .tx-id{font-family:var(--mono);font-size:11px;color:var(--info)}
  .tx-paypal{font-family:var(--mono);font-size:10px;color:var(--muted)}
  .tx-amount{font-family:var(--mono);font-weight:600;color:var(--text)}
  .badge{display:inline-flex;align-items:center;border-radius:20px;padding:2px 9px;font-size:11px;font-weight:600;white-space:nowrap}
  .badge-ok{background:rgba(34,197,94,.15);color:var(--accent);border:1px solid rgba(34,197,94,.3)}
  .badge-pend{background:rgba(251,191,36,.12);color:var(--warn);border:1px solid rgba(251,191,36,.3)}
  .badge-fail{background:rgba(248,113,113,.12);color:var(--danger);border:1px solid rgba(248,113,113,.3)}
  .badge-exp{background:rgba(148,163,184,.1);color:var(--muted);border:1px solid var(--border)}
  .tx-date{font-size:12px;color:var(--muted);white-space:nowrap}
  .tx-empty{text-align:center;padding:36px;color:var(--muted);font-size:14px}

  /* Scrollbar */
  ::-webkit-scrollbar{width:8px;height:8px}
  ::-webkit-scrollbar-thumb{background:var(--surface-2);border-radius:6px}

  /* Responsive */
  @media(max-width:900px){
    .stats-grid{grid-template-columns:repeat(2,1fr)}
    .charts-row{grid-template-columns:1fr}
  }
  @media(max-width:600px){
    header{padding:10px 14px;gap:8px}
    .header-brand{font-size:14px}
    .header-right{gap:8px;flex-wrap:wrap;width:100%}
    .user-pill{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .credits-pill{font-size:12px;padding:4px 10px}
    .logout-btn{padding:8px 12px;font-size:13px;min-height:36px}
    .tab-nav{padding:16px 12px 0;top:52px}
    .tab-btn{padding:8px 14px;font-size:13px}
    main{padding:12px}
    .page-title{font-size:18px}
    .login-card{padding:28px 18px;margin:16px}
    .stats-grid{grid-template-columns:repeat(2,1fr);gap:10px}
    .stat-card{padding:14px}
    .stat-value{font-size:20px}
    .filter-bar{gap:8px}
    .pkg-table thead{display:none}
    .pkg-table tbody{display:flex;flex-direction:column;gap:10px;padding:12px}
    .pkg-table tr{display:flex;flex-direction:column;gap:10px;background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:14px}
    .pkg-table tr:hover td{background:transparent}
    .pkg-table td{display:block;padding:0;border:none;font-size:14px}
    .pkg-table td:first-child{display:flex;align-items:center;flex-wrap:wrap;gap:6px}
    .pkg-table td:nth-child(2)::before{content:'Duración: ';color:var(--muted);font-size:12px}
    .dur{font-size:13px}
    .activo-wrap{font-size:14px}
    .activo-wrap input{width:18px;height:18px}
    .price-wrap{flex-wrap:nowrap;width:100%}
    .price-input{flex:1;min-width:0;width:auto!important;font-size:16px}
    .moneda-sel{flex-shrink:0;width:76px!important;font-size:13px}
    .pkg-table td:last-child{display:flex;flex-direction:column;gap:6px}
    .save-btn{width:100%;padding:12px;font-size:15px;border-radius:8px;min-height:44px}
    .row-msg{margin-left:0;text-align:center}
    /* TX table on mobile */
    .tx-overflow{overflow-x:auto;-webkit-overflow-scrolling:touch}
  }
  @media(prefers-reduced-motion:reduce){*{transition:none!important;animation:none!important}}
</style>
</head>
<body>
<?php if (!$isAuth): ?>

<div class="login-wrap">
  <div class="login-card">
    <div class="brand"><span class="dot"></span><h1>Panel Revendedor <small>· IPTV</small></h1></div>
    <div class="err-msg" id="loginErr"></div>
    <div class="form-row">
      <label for="uname">Usuario XUI</label>
      <input id="uname" type="text" autocomplete="username" spellcheck="false" placeholder="tu_usuario">
    </div>
    <div class="form-row">
      <label for="upass">Contraseña</label>
      <input id="upass" type="password" autocomplete="current-password" placeholder="••••••••">
    </div>
    <button class="btn btn-primary" id="loginBtn">Entrar</button>
  </div>
</div>

<script>
(function(){
  const uname = document.getElementById('uname');
  const upass = document.getElementById('upass');
  const btn   = document.getElementById('loginBtn');
  const errEl = document.getElementById('loginErr');

  async function doLogin(){
    const username = uname.value.trim();
    const password = upass.value;
    if(!username || !password){ showErr('Completa todos los campos.'); return; }
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Entrando…';
    errEl.style.display = 'none';
    try {
      const res = await fetch('/reseller/api/login', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({username, password})
      });
      const data = await res.json();
      if(data.success){ location.reload(); }
      else { showErr(data.message || 'Error al iniciar sesión.'); }
    } catch(e) {
      showErr('Error de red: ' + e.message);
    } finally {
      btn.disabled = false;
      btn.textContent = 'Entrar';
    }
  }

  function showErr(msg){ errEl.textContent = msg; errEl.style.display = 'block'; }
  btn.addEventListener('click', doLogin);
  [uname, upass].forEach(el => el.addEventListener('keydown', e => { if(e.key==='Enter') doLogin(); }));
})();
</script>

<?php else: ?>

<header>
  <div class="header-brand"><span class="dot"></span> Panel Revendedor <small>· IPTV</small></div>
  <div class="header-right">
    <span class="credits-pill" id="creditsPill">— créditos</span>
    <span class="user-pill" id="userPill"><?= htmlspecialchars($_SESSION['rev_username'] ?? '') ?></span>
    <button class="logout-btn" id="logoutBtn">Salir</button>
  </div>
</header>

<!-- Tabs -->
<nav class="tab-nav">
  <button class="tab-btn active" data-tab="paquetes">Mis paquetes</button>
  <button class="tab-btn" data-tab="ventas">Ventas</button>
</nav>

<!-- Tab: Paquetes -->
<div id="tab-paquetes" class="tab-content active">
  <main>
    <div class="page-title">Mis paquetes</div>
    <div class="page-sub">Establece tu precio de venta para cada paquete.</div>

    <div class="card">
      <div class="card-head">
        <h2>Precios de paquetes</h2>
        <button class="reload-btn" id="reloadBtn">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
          Actualizar
        </button>
      </div>
      <div id="tableWrap" style="overflow-x:auto"><div class="loading">Cargando paquetes…</div></div>
    </div>
  </main>
</div>

<!-- Tab: Ventas -->
<div id="tab-ventas" class="tab-content">
  <main>
    <div class="page-title">Ventas</div>
    <div class="page-sub">Analiza tus ingresos, transacciones y paquetes más vendidos.</div>

    <!-- Filtros de fecha -->
    <div class="filter-bar">
      <div class="filter-field">
        <label>Desde</label>
        <input type="date" id="fechaDesde">
      </div>
      <div class="filter-field">
        <label>Hasta</label>
        <input type="date" id="fechaHasta">
      </div>
      <div class="quick-btns">
        <button class="quick-btn" data-days="7">7 días</button>
        <button class="quick-btn" data-days="30">30 días</button>
        <button class="quick-btn" data-days="90">90 días</button>
        <button class="quick-btn" data-month="1">Este mes</button>
      </div>
      <button class="apply-btn" id="aplicarBtn">Aplicar</button>
    </div>

    <!-- Stat cards -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon green">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#22C55E" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
        </div>
        <div class="stat-label">Ventas completadas</div>
        <div class="stat-value" id="statVentas">—</div>
        <div class="stat-sub" id="statVentasSub"></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon blue">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#38BDF8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        </div>
        <div class="stat-label">Ingresos totales</div>
        <div class="stat-value" id="statMonto">—</div>
        <div class="stat-sub" id="statMontoSub"></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon purple">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#A78BFA" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
        </div>
        <div class="stat-label">Promedio por venta</div>
        <div class="stat-value" id="statPromedio">—</div>
        <div class="stat-sub" id="statPromedioSub"></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon yellow">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#FBBF24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
        </div>
        <div class="stat-label">Paquete más vendido</div>
        <div class="stat-value stat-value-sm" id="statTopPkg">—</div>
        <div class="stat-sub" id="statTopPkgSub"></div>
      </div>
    </div>

    <!-- Gráficas -->
    <div class="charts-row">
      <div class="card">
        <div class="card-head"><h2>Ventas por día</h2></div>
        <div class="chart-wrap"><canvas id="chartDiario"></canvas></div>
      </div>
      <div class="card">
        <div class="card-head"><h2>Top paquetes</h2></div>
        <div class="chart-wrap"><canvas id="chartPkgs"></canvas></div>
      </div>
    </div>

    <!-- Transacciones -->
    <div class="card">
      <div class="card-head">
        <h2>Transacciones</h2>
        <span id="txCount" style="font-size:12px;color:var(--muted)"></span>
      </div>
      <div class="tx-overflow" id="txWrap">
        <div class="loading">Cargando datos…</div>
      </div>
    </div>
  </main>
</div>

<style>
  .stat-value-sm{font-size:16px;word-break:break-word;line-height:1.3;margin-top:2px}
</style>

<script>
(function(){
  const MONEDAS = ['EUR','USD','GBP','MXN','COP','ARS','CLP','PEN','BRL'];
  let chartDiario = null, chartPkgs = null;

  function esc(s){ return String(s||'').replace(/[&<>"]/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

  async function api(action, body = {}){
    const res = await fetch('/reseller/api/' + action, {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify(body)
    });
    const data = await res.json();
    if(!data.success) throw new Error(data.message || 'Error desconocido');
    return data;
  }

  // ─── Info & credits ────────────────────────────────────────────────────────
  async function loadInfo(){
    try {
      const res = await api('info');
      const c = res.data.creditos;
      const pill = document.getElementById('creditsPill');
      pill.textContent = c + ' créditos';
      pill.className = 'credits-pill' + (c < 10 ? ' low' : '');
      document.getElementById('userPill').textContent = res.data.nombre + ' · ' + res.data.xui_username;
    } catch(e) { /* non-fatal */ }
  }

  // ─── Paquetes ──────────────────────────────────────────────────────────────
  async function loadPaquetes(){
    const wrap = document.getElementById('tableWrap');
    wrap.innerHTML = '<div class="loading">Cargando…</div>';
    try {
      const res = await api('paquetes');
      const pkgs = res.data.paquetes || [];
      if(!pkgs.length){ wrap.innerHTML='<div class="loading">No hay paquetes disponibles.</div>'; return; }

      const rows = pkgs.map(pkg => {
        const precio = pkg.precio_propio ?? 0.00;
        const moneda = pkg.moneda_propia || 'EUR';
        const ownBadge = pkg.tiene_precio_propio ? '<span class="own-badge">propio</span>' : '';
        const monedaOpts = MONEDAS.map(m=>`<option value="${m}"${moneda===m?' selected':''}>${m}</option>`).join('');
        return `<tr data-pkg-id="${pkg.id}">
          <td><span class="pkg-name">${esc(pkg.nombre)}</span>${ownBadge}<span class="pkg-id">#${pkg.id}</span></td>
          <td><span class="dur">${esc(pkg.duracion_humana)}</span></td>
          <td>
            <label class="activo-wrap">
              <input type="checkbox" class="activo-chk" ${pkg.activo?'checked':''}> Activo
            </label>
          </td>
          <td>
            <div class="price-wrap">
              <input type="text" inputmode="decimal" class="price-input" value="${precio.toFixed(2)}" min="0" step="0.01">
              <select class="moneda-sel">${monedaOpts}</select>
            </div>
          </td>
          <td style="white-space:nowrap">
            <button class="save-btn">Guardar</button>
            <span class="row-msg"></span>
          </td>
        </tr>`;
      }).join('');

      wrap.innerHTML = `<table class="pkg-table">
        <thead><tr>
          <th>Paquete</th><th>Duración</th><th>Estado</th><th>Mi precio</th><th></th>
        </tr></thead>
        <tbody>${rows}</tbody>
      </table>`;

      wrap.querySelectorAll('tr[data-pkg-id]').forEach(tr => {
        tr.querySelector('.save-btn').addEventListener('click', () => saveRow(tr));
      });
    } catch(e) {
      wrap.innerHTML = `<div class="error-state">Error: ${esc(e.message)}</div>`;
    }
  }

  async function saveRow(tr){
    const pkgId  = parseInt(tr.dataset.pkgId, 10);
    const precio = parseFloat(tr.querySelector('.price-input').value);
    const moneda = tr.querySelector('.moneda-sel').value;
    const activo = tr.querySelector('.activo-chk').checked;
    const btn    = tr.querySelector('.save-btn');
    const msg    = tr.querySelector('.row-msg');

    if(isNaN(precio) || precio < 0){ showMsg(msg, '✗ Precio inválido', false); return; }

    btn.disabled = true; btn.textContent = '…';
    try {
      await api('guardar-precio', {package_id: pkgId, precio, moneda, activo});
      showMsg(msg, '✓ Guardado', true);
      let badge = tr.querySelector('.own-badge');
      if(!badge){
        badge = document.createElement('span');
        badge.className = 'own-badge';
        badge.textContent = 'propio';
        tr.querySelector('.pkg-name').after(badge);
      }
    } catch(e) {
      showMsg(msg, '✗ Error', false);
    } finally {
      btn.disabled = false; btn.textContent = 'Guardar';
    }
  }

  function showMsg(el, text, ok){
    el.textContent = text;
    el.className = 'row-msg ' + (ok ? 'ok' : 'err');
    clearTimeout(el._t);
    el._t = setTimeout(() => { el.className = 'row-msg'; el.textContent = ''; }, 2500);
  }

  // ─── Dashboard Ventas ─────────────────────────────────────────────────────
  // Use local calendar date (not UTC) so users in UTC-6 see their own day, not server day
  function localDateStr(d){
    d = d || new Date();
    return d.getFullYear() + '-' +
           String(d.getMonth()+1).padStart(2,'0') + '-' +
           String(d.getDate()).padStart(2,'0');
  }
  function todayStr(){ return localDateStr(); }
  function dateOffset(days){
    const d = new Date();
    d.setDate(d.getDate() - days);
    return localDateStr(d);
  }
  function firstOfMonth(){
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-01';
  }

  function setDateRange(desde, hasta){
    document.getElementById('fechaDesde').value = desde;
    document.getElementById('fechaHasta').value = hasta;
  }

  function initDates(){
    setDateRange(dateOffset(30), todayStr());
  }

  function fmtMoney(n){
    return new Intl.NumberFormat('es', {minimumFractionDigits:2, maximumFractionDigits:2}).format(n);
  }
  function fmtDate(s){
    if(!s) return '—';
    const d = new Date(s);
    if(isNaN(d)) return s;
    return d.toLocaleDateString('es', {day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});
  }
  function fmtDateShort(s){
    if(!s) return '';
    const d = new Date(s + 'T00:00:00');
    return d.toLocaleDateString('es', {day:'2-digit',month:'short'});
  }

  function badgeHtml(estado){
    const map = {
      completed: ['badge-ok','Completado'],
      pending:   ['badge-pend','Pendiente'],
      failed:    ['badge-fail','Fallido'],
      expired:   ['badge-exp','Expirado'],
    };
    const [cls, lbl] = map[estado] || ['badge-exp', estado];
    return `<span class="badge ${cls}">${lbl}</span>`;
  }

  async function loadEstadisticas(){
    const desde = document.getElementById('fechaDesde').value;
    const hasta = document.getElementById('fechaHasta').value;
    if(!desde || !hasta){ alert('Selecciona un rango de fechas.'); return; }

    const btn = document.getElementById('aplicarBtn');
    btn.disabled = true; btn.textContent = 'Cargando…';

    document.getElementById('txWrap').innerHTML = '<div class="loading">Cargando datos…</div>';
    ['statVentas','statMonto','statPromedio','statTopPkg'].forEach(id => {
      document.getElementById(id).textContent = '…';
    });

    try {
      const tzOffset = new Date().getTimezoneOffset(); // minutes behind UTC (positive=west)
      const res = await api('estadisticas', { desde, hasta, tz_offset: tzOffset });
      const { resumen, por_dia, top_paquetes, transacciones } = res.data;

      // Stat cards
      document.getElementById('statVentas').textContent = resumen.total_ordenes;
      document.getElementById('statVentasSub').textContent = desde + ' → ' + hasta;
      document.getElementById('statMonto').textContent = '€' + fmtMoney(resumen.total_monto);
      document.getElementById('statMontoSub').textContent = resumen.total_ordenes > 0
        ? resumen.total_ordenes + ' transacciones'
        : 'Sin transacciones';
      document.getElementById('statPromedio').textContent = resumen.total_ordenes > 0
        ? '€' + fmtMoney(resumen.promedio_monto)
        : '—';
      if(top_paquetes.length > 0){
        const top = top_paquetes[0];
        document.getElementById('statTopPkg').textContent = top.nombre;
        document.getElementById('statTopPkgSub').textContent = top.ordenes + ' ventas';
      } else {
        document.getElementById('statTopPkg').textContent = '—';
        document.getElementById('statTopPkgSub').textContent = '';
      }

      // Chart diario
      renderChartDiario(por_dia);

      // Chart paquetes
      renderChartPkgs(top_paquetes);

      // Transacciones
      renderTxTable(transacciones);

    } catch(e) {
      document.getElementById('txWrap').innerHTML = `<div class="error-state">Error: ${esc(e.message)}</div>`;
    } finally {
      btn.disabled = false; btn.textContent = 'Aplicar';
    }
  }

  function renderChartDiario(porDia){
    const ctx = document.getElementById('chartDiario').getContext('2d');
    if(chartDiario){ chartDiario.destroy(); chartDiario = null; }

    const labels  = porDia.map(d => fmtDateShort(d.fecha));
    const ventas  = porDia.map(d => parseInt(d.ordenes));
    const montos  = porDia.map(d => parseFloat(d.monto));

    chartDiario = new Chart(ctx, {
      type: 'bar',
      data: {
        labels,
        datasets: [
          {
            label: 'Ventas',
            data: ventas,
            backgroundColor: 'rgba(34,197,94,0.25)',
            borderColor: 'rgba(34,197,94,0.9)',
            borderWidth: 2,
            borderRadius: 5,
            yAxisID: 'yVentas',
          },
          {
            label: 'Ingresos (€)',
            data: montos,
            type: 'line',
            borderColor: 'rgba(56,189,248,0.9)',
            backgroundColor: 'rgba(56,189,248,0.08)',
            borderWidth: 2,
            pointRadius: 3,
            pointHoverRadius: 5,
            fill: true,
            tension: 0.35,
            yAxisID: 'yMonto',
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { labels: { color: '#94A3B8', font: { size: 11 }, boxWidth: 14, padding: 12 } },
          tooltip: {
            backgroundColor: '#1E293B',
            borderColor: '#2c3c57',
            borderWidth: 1,
            titleColor: '#F8FAFC',
            bodyColor: '#94A3B8',
            callbacks: {
              label: ctx => ctx.datasetIndex === 1
                ? ` €${fmtMoney(ctx.raw)}`
                : ` ${ctx.raw} ventas`
            }
          }
        },
        scales: {
          x: { ticks: { color: '#94A3B8', font: { size: 10 }, maxRotation: 45 }, grid: { color: '#1a2a40' } },
          yVentas: {
            position: 'left',
            ticks: { color: '#22C55E', font: { size: 10 }, stepSize: 1 },
            grid: { color: '#1a2a40' },
            title: { display: false }
          },
          yMonto: {
            position: 'right',
            ticks: { color: '#38BDF8', font: { size: 10 }, callback: v => '€'+v },
            grid: { drawOnChartArea: false },
          }
        }
      }
    });
  }

  function renderChartPkgs(topPkgs){
    const ctx = document.getElementById('chartPkgs').getContext('2d');
    if(chartPkgs){ chartPkgs.destroy(); chartPkgs = null; }

    if(!topPkgs.length){
      ctx.clearRect(0, 0, ctx.canvas.width, ctx.canvas.height);
      ctx.fillStyle = '#94A3B8';
      ctx.font = '13px Fira Sans, sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText('Sin datos en este período', ctx.canvas.width/2, ctx.canvas.height/2);
      return;
    }

    const COLORS = ['#22C55E','#38BDF8','#A78BFA','#FBBF24','#F87171','#34D399'];
    const labels = topPkgs.map(p => p.nombre);
    const data   = topPkgs.map(p => parseInt(p.ordenes));
    const bgs    = topPkgs.map((_, i) => COLORS[i % COLORS.length] + '33');
    const borders= topPkgs.map((_, i) => COLORS[i % COLORS.length]);

    chartPkgs = new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels,
        datasets: [{
          data,
          backgroundColor: bgs,
          borderColor: borders,
          borderWidth: 2,
          hoverOffset: 8,
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            position: 'bottom',
            labels: { color: '#94A3B8', font: { size: 11 }, padding: 10, boxWidth: 12 }
          },
          tooltip: {
            backgroundColor: '#1E293B',
            borderColor: '#2c3c57',
            borderWidth: 1,
            titleColor: '#F8FAFC',
            bodyColor: '#94A3B8',
            callbacks: {
              label: ctx => ` ${ctx.raw} ventas · €${fmtMoney(topPkgs[ctx.dataIndex].monto)}`
            }
          }
        },
        cutout: '62%',
      }
    });
  }

  function renderTxTable(txs){
    const wrap = document.getElementById('txWrap');
    const count = document.getElementById('txCount');
    count.textContent = txs.length + ' registros';

    if(!txs.length){
      wrap.innerHTML = '<div class="tx-empty">No hay transacciones en este período.</div>';
      return;
    }

    const rows = txs.map(tx => {
      const pkgLabel = tx.pkg_nombre
        ? `<span style="font-size:12px">${esc(tx.pkg_nombre)}</span>`
        : (tx.package_id ? `<span class="tx-paypal">#${tx.package_id}</span>` : '—');
      const ppLink = tx.paypal_order_id
        ? `<span class="tx-paypal">${esc(tx.paypal_order_id)}</span>`
        : '<span style="color:var(--muted);font-size:11px">—</span>';
      const user = tx.iptv_user
        ? `<span class="mono" style="font-size:12px">${esc(tx.iptv_user)}</span>`
        : `<span class="tx-paypal">línea #${tx.line_id}</span>`;
      return `<tr>
        <td><span class="tx-id">${esc(tx.order_id)}</span></td>
        <td>${ppLink}</td>
        <td>${user}</td>
        <td>${pkgLabel}</td>
        <td><span class="tx-amount">€${fmtMoney(tx.monto)}</span></td>
        <td>${badgeHtml(tx.estado)}</td>
        <td><span class="tx-date">${fmtDate(tx.created_at)}</span></td>
      </tr>`;
    }).join('');

    wrap.innerHTML = `<table class="tx-table">
      <thead><tr>
        <th>ID Orden</th>
        <th>ID PayPal</th>
        <th>Usuario IPTV</th>
        <th>Paquete</th>
        <th>Monto</th>
        <th>Estado</th>
        <th>Fecha</th>
      </tr></thead>
      <tbody>${rows}</tbody>
    </table>`;
  }

  // ─── Tabs ─────────────────────────────────────────────────────────────────
  let ventasCargadas = false;

  document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
      document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
      btn.classList.add('active');
      document.getElementById('tab-' + btn.dataset.tab).classList.add('active');

      if(btn.dataset.tab === 'ventas' && !ventasCargadas){
        ventasCargadas = true;
        loadEstadisticas();
      }
    });
  });

  // ─── Controles de fecha ───────────────────────────────────────────────────
  document.querySelectorAll('.quick-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.quick-btn').forEach(b => b.classList.remove('active-q'));
      btn.classList.add('active-q');
      if(btn.dataset.days){
        setDateRange(dateOffset(parseInt(btn.dataset.days)), todayStr());
      } else if(btn.dataset.month){
        setDateRange(firstOfMonth(), todayStr());
      }
      // Auto-aplicar al hacer click en rango rápido
      ventasCargadas = true;
      loadEstadisticas();
    });
  });

  document.getElementById('aplicarBtn').addEventListener('click', () => {
    ventasCargadas = true;
    loadEstadisticas();
  });

  // ─── Otros eventos ────────────────────────────────────────────────────────
  document.getElementById('logoutBtn').addEventListener('click', async () => {
    await api('logout');
    location.reload();
  });
  document.getElementById('reloadBtn').addEventListener('click', () => { loadInfo(); loadPaquetes(); });

  // ─── Init ─────────────────────────────────────────────────────────────────
  // Inicializar fechas con rango de 30 días por defecto
  (function initDates(){
    document.getElementById('fechaDesde').value = dateOffset(30);
    document.getElementById('fechaHasta').value = todayStr();
    // Marcar "30 días" como activo por defecto
    document.querySelector('[data-days="30"]')?.classList.add('active-q');
  })();

  loadInfo();
  loadPaquetes();
})();
</script>

<?php endif; ?>
</body>
</html>
