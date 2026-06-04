<?php
/**
 * Reseller Panel — login propio, ver créditos, gestionar precios de paquetes.
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

        $_SESSION['rev_id']       = (int)$rev['id'];
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
    $stmt  = $db->prepare("SELECT * FROM `revendedores` WHERE `id` = :id AND `active` = 1 LIMIT 1");
    $stmt->execute([':id' => $revId]);
    $rev   = $stmt->fetch();
    if (!$rev) { session_destroy(); $err('Revendedor no encontrado.', 401); }

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
        $db->prepare("UPDATE `revendedores` SET `creditos_cache` = :c WHERE `id` = :id")
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
        $stmt->execute([':rid' => $revId]);
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
        ")->execute([':rid' => $revId, ':pid' => $packageId, ':precio' => $precio, ':moneda' => $moneda, ':activo' => $activo ? 1 : 0]);

        $ok('Precio guardado.');
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
<style>
  :root{--bg:#0F172A;--surface:#1E293B;--surface-2:#334155;--border:#2c3c57;--text:#F8FAFC;--muted:#94A3B8;--accent:#22C55E;--accent-ink:#04240f;--info:#38BDF8;--danger:#F87171;--warn:#FBBF24;--radius:10px;--mono:'Fira Code',ui-monospace,monospace;--sans:'Fira Sans',system-ui,sans-serif}
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

  /* Panel */
  header{display:flex;align-items:center;gap:16px;flex-wrap:wrap;padding:12px 24px;background:var(--surface);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:30}
  .header-brand{display:flex;align-items:center;gap:10px;font-weight:600}
  .header-brand small{color:var(--muted);font-weight:400}
  .header-right{display:flex;align-items:center;gap:16px;margin-left:auto}
  .credits-pill{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);border-radius:20px;padding:4px 14px;font-size:13px;font-family:var(--mono);color:var(--accent);font-weight:600}
  .credits-pill.low{background:rgba(248,113,113,.12);border-color:rgba(248,113,113,.3);color:var(--danger)}
  .user-pill{font-size:13px;color:var(--muted)}
  .logout-btn{background:none;border:1px solid var(--border);color:var(--muted);border-radius:8px;padding:6px 12px;font-size:13px;transition:color .15s,border-color .15s}
  .logout-btn:hover{color:var(--danger);border-color:var(--danger)}

  main{max-width:960px;margin:0 auto;padding:28px 24px}
  .page-title{font-size:22px;font-weight:600;margin-bottom:4px}
  .page-sub{color:var(--muted);font-size:13.5px;margin-bottom:24px}

  /* Prices table */
  .card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
  .card-head{display:flex;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid var(--border)}
  .card-head h2{font-size:13px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);font-weight:600;margin:0}
  .reload-btn{background:var(--surface-2);color:var(--text);border:none;border-radius:8px;padding:7px 12px;font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px;transition:background .15s}
  .reload-btn:hover{background:#3f5170}
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

  ::-webkit-scrollbar{width:8px;height:8px}
  ::-webkit-scrollbar-thumb{background:var(--surface-2);border-radius:6px}
  @media(max-width:640px){
    .pkg-table th:nth-child(2),.pkg-table td:nth-child(2){display:none}
    main{padding:16px}
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

<script>
(function(){
  const MONEDAS = ['EUR','USD','GBP','MXN','COP','ARS','CLP','PEN','BRL'];

  function esc(s){ return String(s).replace(/[&<>"]/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

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
              <input type="number" class="price-input" value="${precio.toFixed(2)}" min="0" step="0.01">
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
      // Update own-badge
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

  document.getElementById('logoutBtn').addEventListener('click', async () => {
    await api('logout');
    location.reload();
  });
  document.getElementById('reloadBtn').addEventListener('click', () => { loadInfo(); loadPaquetes(); });

  loadInfo();
  loadPaquetes();
})();
</script>

<?php endif; ?>
</body>
</html>
