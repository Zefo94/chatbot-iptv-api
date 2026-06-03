<?php
/**
 * IPTV Middleware · Admin Dashboard
 * Sections: API Explorer + Precios de Paquetes
 * Protected by session login (DASHBOARD_PASSWORD in .env)
 */

// Load .env so DASHBOARD_PASSWORD is available
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

session_start();

$dashPass = $_ENV['DASHBOARD_PASSWORD'] ?? '';
$authRequired = $dashPass !== '';
$isAuth = !$authRequired || !empty($_SESSION['dash_ok']);

// Handle login POST
if (!$isAuth && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dp'])) {
    if ($_POST['dp'] === $dashPass) {
        $_SESSION['dash_ok'] = true;
        $isAuth = true;
    } else {
        $loginError = true;
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /dashboard');
    exit;
}

// Show login page if not authenticated
if (!$isAuth) {
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>IPTV Middleware · Acceso</title>
<style>
  :root{--bg:#0F172A;--surface:#1E293B;--border:#2c3c57;--text:#F8FAFC;--muted:#94A3B8;--accent:#22C55E;--danger:#F87171;--radius:12px}
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:var(--bg);color:var(--text);font-family:system-ui,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center}
  .card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:36px 32px;width:100%;max-width:360px}
  .brand{display:flex;align-items:center;gap:10px;margin-bottom:28px}
  .dot{width:10px;height:10px;border-radius:50%;background:var(--accent);box-shadow:0 0 12px var(--accent)}
  h1{font-size:18px;font-weight:600}
  small{color:var(--muted);font-size:13px;font-weight:400}
  label{display:block;font-size:13px;color:var(--muted);margin-bottom:6px}
  input{width:100%;background:var(--bg);color:var(--text);border:1px solid var(--border);border-radius:8px;padding:10px 12px;font-size:14px;outline:none;transition:border-color .15s}
  input:focus{border-color:var(--accent)}
  .err{background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.35);border-radius:8px;padding:9px 12px;font-size:13px;color:var(--danger);margin-bottom:16px}
  button{width:100%;margin-top:18px;background:var(--accent);color:#04240f;border:none;border-radius:8px;padding:11px;font-size:15px;font-weight:700;cursor:pointer;transition:filter .15s}
  button:hover{filter:brightness(1.08)}
</style>
</head>
<body>
<div class="card">
  <div class="brand"><span class="dot"></span><h1>IPTV Middleware <small>· Admin</small></h1></div>
  <?php if (!empty($loginError)): ?>
  <div class="err">Contraseña incorrecta.</div>
  <?php endif; ?>
  <form method="POST">
    <label for="dp">Contraseña</label>
    <input id="dp" name="dp" type="password" autofocus placeholder="••••••••">
    <button type="submit">Entrar</button>
  </form>
</div>
</body>
</html>
<?php
    exit;
}

// Authenticated — load catalog for API Explorer
$catalog = require dirname(__DIR__) . '/routes/catalog.php';
$catalogJson = json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>IPTV Middleware · Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fira+Code:wght@400;500;600&family=Fira+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{
    --bg:#0F172A; --surface:#1E293B; --surface-2:#334155; --border:#2c3c57;
    --text:#F8FAFC; --muted:#94A3B8; --accent:#22C55E; --accent-ink:#04240f;
    --info:#38BDF8; --danger:#F87171; --warn:#FBBF24;
    --radius:10px; --mono:'Fira Code',ui-monospace,monospace; --sans:'Fira Sans',system-ui,sans-serif;
  }
  *{box-sizing:border-box}
  html,body{margin:0;height:100%}
  body{background:var(--bg);color:var(--text);font-family:var(--sans);font-size:15px;line-height:1.55;}
  a{color:var(--info)}
  button{font-family:inherit;cursor:pointer}
  code,pre,.mono{font-family:var(--mono)}

  /* ---------- Top bar ---------- */
  header{
    display:flex;align-items:center;gap:16px;flex-wrap:wrap;
    padding:12px 20px;background:var(--surface);border-bottom:1px solid var(--border);
    position:sticky;top:0;z-index:30;
  }
  .brand{display:flex;align-items:center;gap:10px;font-weight:600;letter-spacing:.2px}
  .brand .dot{width:9px;height:9px;border-radius:50%;background:var(--accent);box-shadow:0 0 10px var(--accent)}
  .brand small{color:var(--muted);font-weight:400}
  .cfg{display:flex;align-items:center;gap:8px;margin-left:auto;flex-wrap:wrap}
  .field-inline{display:flex;flex-direction:column;gap:2px}
  .field-inline label{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.6px}
  input,textarea,select{
    background:var(--bg);color:var(--text);border:1px solid var(--border);border-radius:8px;
    padding:8px 10px;font-size:14px;font-family:var(--mono);outline:none;transition:border-color .15s,box-shadow .15s;
  }
  input:focus,textarea:focus,select:focus{border-color:var(--info);box-shadow:0 0 0 3px rgba(56,189,248,.18)}
  .cfg input{min-width:230px}
  .key-wrap{position:relative;display:flex;align-items:center}
  .key-wrap input{padding-right:34px}
  .key-wrap button{position:absolute;right:4px;background:none;border:none;color:var(--muted);padding:4px;display:flex}
  .saved{font-size:12px;color:var(--accent);opacity:0;transition:opacity .2s}
  .saved.show{opacity:1}
  .logout-btn{background:none;border:1px solid var(--border);color:var(--muted);border-radius:8px;padding:6px 12px;font-size:13px;transition:color .15s,border-color .15s}
  .logout-btn:hover{color:var(--danger);border-color:var(--danger)}

  /* ---------- Layout ---------- */
  .layout{display:grid;grid-template-columns:280px 1fr;min-height:calc(100vh - 58px)}
  nav{border-right:1px solid var(--border);background:#16213a;overflow-y:auto;padding:14px 10px}
  .nav-group{margin-bottom:10px}
  .nav-group h3{font-size:11px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin:14px 8px 6px}
  .nav-item{
    display:flex;align-items:center;gap:8px;width:100%;text-align:left;background:none;border:none;color:var(--text);
    padding:8px 8px;border-radius:8px;font-size:13.5px;transition:background .15s;
  }
  .nav-item:hover{background:var(--surface-2)}
  .nav-item.active{background:var(--surface-2);box-shadow:inset 3px 0 0 var(--accent)}
  .badge{font-family:var(--mono);font-size:10px;font-weight:600;padding:2px 6px;border-radius:5px;letter-spacing:.5px}
  .badge.POST{background:rgba(56,189,248,.16);color:var(--info)}
  .badge.GET{background:rgba(34,197,94,.16);color:var(--accent)}
  .badge.MGT{background:rgba(251,191,36,.16);color:var(--warn)}
  .nav-item .ttl{flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .nav-item .warn-dot{width:7px;height:7px;border-radius:50%;background:var(--danger)}

  main{padding:24px 28px;max-width:980px;overflow-x:hidden}
  .ep-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
  .ep-head h1{font-size:22px;margin:0}
  .path{font-family:var(--mono);font-size:14px;color:var(--info);background:var(--surface);border:1px solid var(--border);padding:4px 10px;border-radius:8px}
  .desc{color:#cdd6e4;margin:12px 0 0}
  .callout{display:flex;gap:10px;align-items:flex-start;border-radius:8px;padding:10px 12px;margin-top:14px;font-size:13.5px}
  .callout.note{background:rgba(56,189,248,.08);border:1px solid rgba(56,189,248,.3)}
  .callout.danger{background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.35)}
  .callout svg{flex:0 0 auto;margin-top:2px}
  .meta{display:flex;gap:18px;flex-wrap:wrap;margin-top:14px;font-size:12.5px;color:var(--muted)}
  .meta b{color:var(--text);font-weight:500}

  .card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:18px;margin-top:20px}
  .card h2{font-size:13px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin:0 0 14px;font-weight:600}
  .form-grid{display:grid;gap:14px}
  .form-row label{display:block;font-size:13px;margin-bottom:5px}
  .form-row label .req{color:var(--danger);margin-left:3px}
  .form-row label .opt{color:var(--muted);font-weight:400;margin-left:6px;font-size:11px}
  .form-row input,.form-row textarea{width:100%}
  .form-row .help{font-size:12px;color:var(--muted);margin-top:4px}
  textarea.raw{min-height:160px;resize:vertical;line-height:1.45}

  .actions{display:flex;gap:10px;align-items:center;margin-top:18px;flex-wrap:wrap}
  .btn{display:inline-flex;align-items:center;gap:8px;border:none;border-radius:8px;padding:10px 16px;font-size:14px;font-weight:600;transition:filter .15s,background .15s}
  .btn:disabled{opacity:.6;cursor:not-allowed}
  .btn-primary{background:var(--accent);color:var(--accent-ink)}
  .btn-primary:hover:not(:disabled){filter:brightness(1.08)}
  .btn-ghost{background:var(--surface-2);color:var(--text)}
  .btn-ghost:hover{background:#3f5170}
  .btn-sm{padding:6px 12px;font-size:13px}
  .spinner{width:15px;height:15px;border:2px solid rgba(0,0,0,.25);border-top-color:var(--accent-ink);border-radius:50%;animation:spin .6s linear infinite}
  @keyframes spin{to{transform:rotate(360deg)}}

  .curl-box{position:relative}
  pre{background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:14px;overflow:auto;font-size:13px;margin:0;white-space:pre;color:#d7e0ee}
  .copy-btn{position:absolute;top:8px;right:8px;background:var(--surface-2);border:1px solid var(--border);color:var(--muted);border-radius:6px;padding:5px 8px;display:flex;align-items:center;gap:5px;font-size:12px}
  .copy-btn:hover{color:var(--text)}

  .resp-head{display:flex;align-items:center;gap:12px;margin-bottom:12px;flex-wrap:wrap}
  .status{font-family:var(--mono);font-weight:600;padding:3px 10px;border-radius:6px;font-size:13px}
  .status.s2{background:rgba(34,197,94,.16);color:var(--accent)}
  .status.s4{background:rgba(251,191,36,.16);color:var(--warn)}
  .status.s5,.status.err{background:rgba(248,113,113,.16);color:var(--danger)}
  .resp-meta{color:var(--muted);font-size:12.5px}
  .empty{color:var(--muted);font-size:13.5px}

  /* ---------- Precios section ---------- */
  .precios-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:4px}
  .precios-header h1{font-size:22px;margin:0}
  .precios-header p{color:var(--muted);font-size:13.5px;margin:0}
  .precios-table{width:100%;border-collapse:collapse;margin-top:4px}
  .precios-table th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);padding:8px 12px;border-bottom:1px solid var(--border)}
  .precios-table td{padding:10px 12px;border-bottom:1px solid #1a2a40;vertical-align:middle}
  .precios-table tr:last-child td{border-bottom:none}
  .precios-table tr:hover td{background:rgba(255,255,255,.025)}
  .precio-input{width:90px;text-align:right;padding:6px 8px;font-size:14px}
  .moneda-select{background:var(--bg);color:var(--text);border:1px solid var(--border);border-radius:6px;padding:5px 8px;font-size:13px;font-family:var(--mono);margin-left:4px;cursor:pointer}
  .moneda-select:focus{border-color:var(--info);outline:none}
  .save-row-btn{background:var(--accent);color:var(--accent-ink);border:none;border-radius:6px;padding:6px 14px;font-size:13px;font-weight:600;cursor:pointer;transition:filter .15s,opacity .15s;white-space:nowrap}
  .save-row-btn:hover{filter:brightness(1.08)}
  .save-row-btn:disabled{opacity:.5;cursor:not-allowed}
  .row-msg{font-size:12px;margin-left:8px;opacity:0;transition:opacity .3s}
  .row-msg.ok{color:var(--accent);opacity:1}
  .row-msg.err{color:var(--danger);opacity:1}
  .pkg-name{font-size:14px}
  .pkg-id{font-family:var(--mono);font-size:11px;color:var(--muted);margin-left:6px}
  .activo-toggle{display:flex;align-items:center;gap:6px;font-size:13px;color:var(--muted);cursor:pointer}
  .activo-toggle input{width:16px;height:16px;cursor:pointer;accent-color:var(--accent)}
  .precios-reload{background:var(--surface-2);color:var(--text);border:none;border-radius:8px;padding:8px 14px;font-size:13px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:6px;transition:background .15s}
  .precios-reload:hover{background:#3f5170}
  .precios-loading{color:var(--muted);font-size:14px;padding:24px 0;text-align:center}
  .precios-error{color:var(--danger);font-size:14px;padding:16px 0}

  ::-webkit-scrollbar{width:10px;height:10px}
  ::-webkit-scrollbar-thumb{background:var(--surface-2);border-radius:6px}

  @media (max-width:760px){
    .layout{grid-template-columns:1fr}
    nav{border-right:none;border-bottom:1px solid var(--border);max-height:230px}
    .cfg{width:100%}.cfg input{flex:1;min-width:0}
    .precios-table th:nth-child(2),.precios-table td:nth-child(2){display:none}
  }
  @media (prefers-reduced-motion:reduce){*{transition:none!important;animation:none!important}}
</style>
</head>
<body>
<header>
  <div class="brand"><span class="dot"></span> Admin <small>· IPTV Middleware</small></div>
  <div class="cfg">
    <div class="field-inline">
      <label for="baseUrl">Base URL</label>
      <input id="baseUrl" spellcheck="false" placeholder="https://solucionesdigitales.icu">
    </div>
    <div class="field-inline">
      <label for="apiKey">X-API-Key</label>
      <div class="key-wrap">
        <input id="apiKey" type="password" spellcheck="false" placeholder="CHATBOT_API_KEY del .env">
        <button id="toggleKey" type="button" aria-label="Mostrar u ocultar la API key">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
        </button>
      </div>
    </div>
    <span class="saved" id="savedMsg">guardado ✓</span>
    <a href="/dashboard?logout=1"><button class="logout-btn" type="button">Salir</button></a>
  </div>
</header>

<div class="layout">
  <nav id="nav" aria-label="Navegación"></nav>
  <main id="main"></main>
</div>

<script id="catalog" type="application/json"><?= $catalogJson ?></script>
<script>
(function(){
  "use strict";
  const CATALOG = JSON.parse(document.getElementById('catalog').textContent);
  const LS = {base:'iptv_api_base', key:'iptv_api_key'};
  const $ = (s,r=document)=>r.querySelector(s);

  const ICON = {
    send:'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>',
    copy:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',
    fill:'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>',
    info:'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#38BDF8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
    warn:'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#F87171" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    tag:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>',
    refresh:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>'
  };

  const cfg = {
    base: localStorage.getItem(LS.base) || window.location.origin,
    key:  localStorage.getItem(LS.key) || ''
  };
  let currentId = null;

  // ---- Config inputs ----
  const baseInput = $('#baseUrl'), keyInput = $('#apiKey');
  baseInput.value = cfg.base; keyInput.value = cfg.key;
  function flashSaved(){ const m=$('#savedMsg'); m.classList.add('show'); clearTimeout(flashSaved._t); flashSaved._t=setTimeout(()=>m.classList.remove('show'),1200); }
  baseInput.addEventListener('input', ()=>{ cfg.base=baseInput.value.trim().replace(/\/$/,''); localStorage.setItem(LS.base,cfg.base); flashSaved(); if(currentId&&currentId!=='__precios__') renderCurl(); });
  keyInput.addEventListener('input', ()=>{ cfg.key=keyInput.value; localStorage.setItem(LS.key,cfg.key); flashSaved(); if(currentId&&currentId!=='__precios__') renderCurl(); });
  $('#toggleKey').addEventListener('click', ()=>{ keyInput.type = keyInput.type==='password'?'text':'password'; });

  // ---- Sidebar ----
  function buildNav(){
    const nav = $('#nav');
    const groups = [];
    CATALOG.forEach(ep=>{ let g=groups.find(x=>x.name===ep.group); if(!g){g={name:ep.group,items:[]};groups.push(g);} g.items.push(ep); });

    let html = `
      <div class="nav-group">
        <h3>Gestión</h3>
        <button class="nav-item" data-id="__precios__">
          <span class="badge MGT">MGT</span>
          <span class="ttl">Precios de Paquetes</span>
        </button>
      </div>`;

    html += groups.map(g=>`
      <div class="nav-group">
        <h3>${esc(g.name)}</h3>
        ${g.items.map(ep=>`
          <button class="nav-item" data-id="${esc(ep.id)}">
            <span class="badge ${ep.method}">${ep.method}</span>
            <span class="ttl">${esc(ep.title)}</span>
            ${ep.danger?'<span class="warn-dot" title="Acción real / peligrosa"></span>':''}
          </button>`).join('')}
      </div>`).join('');

    nav.innerHTML = html;
    nav.querySelectorAll('.nav-item').forEach(b=> b.addEventListener('click', ()=>select(b.dataset.id)));
  }

  // ---- Precios section ----
  async function renderPrecios(){
    currentId = '__precios__';
    document.querySelectorAll('.nav-item').forEach(b=>b.classList.toggle('active', b.dataset.id==='__precios__'));
    const main = $('#main');
    main.innerHTML = `
      <div class="precios-header">
        <div>
          <h1>Precios de Paquetes</h1>
          <p style="margin-top:4px">Edita el precio de cada plan. Los cambios se aplican inmediatamente al chatbot.</p>
        </div>
        <button class="precios-reload" id="reloadPrecios">${ICON.refresh} Recargar</button>
      </div>
      <div class="card" style="padding:0;overflow:hidden;margin-top:20px">
        <div id="preciosBody"><div class="precios-loading">Cargando precios…</div></div>
      </div>`;
    $('#reloadPrecios').addEventListener('click', loadPrecios);
    await loadPrecios();
  }

  async function loadPrecios(){
    const body = $('#preciosBody');
    if(!body) return;
    body.innerHTML = '<div class="precios-loading">Cargando…</div>';
    try {
      const [pkgRes, precRes] = await Promise.all([
        apiFetch('/api/listar-paquetes', {}),
        apiFetch('/api/listar-precios-paquetes', {})
      ]);
      const paquetes = pkgRes?.data?.paquetes || [];
      const precios  = {};
      (precRes?.data?.precios || []).forEach(r => { precios[r.package_id] = r; });

      if (!paquetes.length) { body.innerHTML='<div class="precios-error" style="padding:20px">No se pudieron cargar los paquetes de XUI ONE. Verifica la X-API-Key y el Base URL.</div>'; return; }

      const rows = paquetes.map(pkg => {
        const db = precios[pkg.id] || {};
        const precio = db.precio ?? pkg.precio ?? '0.00';
        const moneda = db.moneda ?? pkg.moneda ?? 'EUR';
        const activo = db.activo !== undefined ? db.activo : true;
        return `<tr data-pkg-id="${pkg.id}">
          <td><span class="pkg-name">${esc(pkg.nombre)}</span><span class="pkg-id">#${pkg.id}</span></td>
          <td><span style="color:var(--muted);font-size:12px">${esc(pkg.duracion_humana||'')}</span></td>
          <td>
            <label class="activo-toggle">
              <input type="checkbox" class="activo-chk" ${activo ? 'checked' : ''}>
              Activo
            </label>
          </td>
          <td>
            <input type="number" class="precio-input" value="${esc(String(precio))}" min="0" step="0.01">
            <select class="moneda-select">
              ${['EUR','USD','GBP','MXN','COP','ARS','CLP','PEN','BRL'].map(c =>
                `<option value="${c}" ${moneda===c?'selected':''}>${c}</option>`
              ).join('')}
            </select>
          </td>
          <td style="white-space:nowrap">
            <button class="save-row-btn">Guardar</button>
            <span class="row-msg"></span>
          </td>
        </tr>`;
      }).join('');

      body.innerHTML = `
        <table class="precios-table">
          <thead><tr>
            <th>Paquete</th><th>Duración</th><th>Estado</th><th>Precio</th><th></th>
          </tr></thead>
          <tbody>${rows}</tbody>
        </table>`;

      body.querySelectorAll('tr[data-pkg-id]').forEach(tr => {
        tr.querySelector('.save-row-btn').addEventListener('click', () => saveRow(tr, paquetes));
      });
    } catch(e) {
      body.innerHTML = `<div class="precios-error" style="padding:20px">Error: ${esc(e.message)}. Verifica la X-API-Key y el Base URL arriba.</div>`;
    }
  }

  async function saveRow(tr, paquetes) {
    const pkgId  = parseInt(tr.dataset.pkgId, 10);
    const precio = parseFloat(tr.querySelector('.precio-input').value);
    const moneda = tr.querySelector('.moneda-select').value;
    const activo = tr.querySelector('.activo-chk').checked;
    const btn    = tr.querySelector('.save-row-btn');
    const msg    = tr.querySelector('.row-msg');
    const pkg    = paquetes.find(p => p.id === pkgId) || {};

    if (isNaN(precio) || precio < 0) { showRowMsg(msg, 'Precio inválido', false); return; }

    btn.disabled = true;
    btn.textContent = '…';
    try {
      await apiFetch('/api/actualizar-precio-paquete', {
        package_id:   pkgId,
        package_name: pkg.nombre || '',
        precio:       precio,
        moneda:       moneda,
        activo:       activo,
      });
      showRowMsg(msg, '✓ Guardado', true);
    } catch(e) {
      showRowMsg(msg, '✗ Error', false);
    } finally {
      btn.disabled = false;
      btn.textContent = 'Guardar';
    }
  }

  function showRowMsg(el, text, ok) {
    el.textContent = text;
    el.className = 'row-msg ' + (ok ? 'ok' : 'err');
    clearTimeout(el._t);
    el._t = setTimeout(() => { el.className = 'row-msg'; el.textContent = ''; }, 2500);
  }

  async function apiFetch(path, body) {
    const res = await fetch(cfg.base + path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-API-Key': cfg.key },
      body: JSON.stringify(body),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.message || res.statusText);
    return data;
  }

  // ---- API Explorer ----
  function fieldInputHtml(f){
    const id = 'f_'+f.name;
    const reqMark = f.required ? '<span class="req">*</span>' : '<span class="opt">(opcional)</span>';
    const ph = f.example!==undefined && f.example!=='' ? `placeholder="ej: ${escAttr(String(f.example))}"` : '';
    const val = f.default!==undefined ? `value="${escAttr(String(f.default))}"` : '';
    const typeAttr = (f.type==='integer'||f.type==='number') ? 'inputmode="decimal"' : '';
    return `<div class="form-row">
      <label for="${id}">${esc(f.label||f.name)} <span class="opt">${esc(f.name)} · ${esc(f.in)}</span> ${reqMark}</label>
      <input id="${id}" data-name="${esc(f.name)}" data-type="${esc(f.type)}" data-in="${esc(f.in)}" ${typeAttr} ${ph} ${val} spellcheck="false">
      ${f.help?`<div class="help">${esc(f.help)}</div>`:''}
    </div>`;
  }

  function select(id){
    if(id==='__precios__'){ renderPrecios(); return; }
    currentId = id;
    document.querySelectorAll('.nav-item').forEach(b=> b.classList.toggle('active', b.dataset.id===id));
    const ep = CATALOG.find(e=>e.id===id);
    const fields = ep.fields||[];

    const callouts = [];
    if(ep.danger) callouts.push(`<div class="callout danger">${ICON.warn}<div>${esc(ep.note||'Acción que modifica datos reales.')}</div></div>`);
    else if(ep.note) callouts.push(`<div class="callout note">${ICON.info}<div>${esc(ep.note)}</div></div>`);

    const meta = [];
    meta.push(`<span><b>Auth:</b> ${ep.auth?'X-API-Key requerida':'pública (sin key)'}</span>`);
    if(ep.xui) meta.push(`<span><b>Acción XUI:</b> ${esc(ep.xui)}</span>`);

    const formInner = ep.rawBody
      ? `${fields.map(fieldInputHtml).join('')}
         <div class="form-row">
           <label for="rawBody">Cuerpo JSON crudo</label>
           <textarea id="rawBody" class="raw mono" spellcheck="false">${esc(ep.rawExample||'{}')}</textarea>
         </div>`
      : (fields.length ? fields.map(fieldInputHtml).join('') : '<div class="empty">Este endpoint no requiere parámetros.</div>');

    $('#main').innerHTML = `
      <div class="ep-head">
        <span class="badge ${ep.method}">${ep.method}</span>
        <h1>${esc(ep.title)}</h1>
        <span class="path">${esc(ep.path)}</span>
      </div>
      <p class="desc">${esc(ep.description)}</p>
      ${callouts.join('')}
      <div class="meta">${meta.join('')}</div>

      <div class="card">
        <h2>Parámetros</h2>
        <div class="form-grid" id="formGrid">${formInner}</div>
        <div class="actions">
          <button class="btn btn-primary" id="sendBtn">${ICON.send} Enviar petición</button>
          <button class="btn btn-ghost" id="fillBtn">${ICON.fill} Rellenar con ejemplo</button>
        </div>
      </div>

      <div class="card curl-box">
        <h2>Comando cURL</h2>
        <button class="copy-btn" id="copyCurl">${ICON.copy} copiar</button>
        <pre id="curlOut"></pre>
      </div>

      <div class="card">
        <h2>Respuesta</h2>
        <div id="respArea"><div class="empty">Aún no has enviado ninguna petición.</div></div>
      </div>`;

    $('#formGrid').addEventListener('input', renderCurl);
    $('#sendBtn').addEventListener('click', send);
    $('#fillBtn').addEventListener('click', ()=>{ fillExample(ep); renderCurl(); });
    $('#copyCurl').addEventListener('click', copyCurl);
    renderCurl();
  }

  function fillExample(ep){
    (ep.fields||[]).forEach(f=>{
      if(f.example===undefined || f.example==='') return;
      const el = document.querySelector(`[data-name="${cssEsc(f.name)}"]`);
      if(el) el.value = f.example;
    });
    if(ep.rawBody){ const t=$('#rawBody'); if(t) t.value = ep.rawExample||'{}'; }
  }

  function collect(){
    const ep = CATALOG.find(e=>e.id===currentId);
    const body = {}, query = {};
    document.querySelectorAll('#formGrid [data-name]').forEach(el=>{
      const v = el.value.trim(); if(v==='') return;
      const name=el.dataset.name, type=el.dataset.type, where=el.dataset.in;
      let val = v;
      if(type==='integer') val = parseInt(v,10);
      else if(type==='number') val = Number(v);
      (where==='query'?query:body)[name]=val;
    });
    let raw = null;
    if(ep.rawBody){ const t=$('#rawBody'); raw = t?t.value:''; }
    return {ep, body, query, raw};
  }

  function buildUrl(ep, query){
    const qs = Object.keys(query).map(k=>encodeURIComponent(k)+'='+encodeURIComponent(query[k])).join('&');
    return cfg.base + ep.path + (qs?('?'+qs):'');
  }
  function bodyString(ep, body, raw){
    if(ep.rawBody) return raw||'';
    if(ep.method==='GET') return null;
    return JSON.stringify(body);
  }

  function renderCurl(){
    const {ep, body, query, raw} = collect();
    const url = buildUrl(ep, query);
    const lines = [`curl -X ${ep.method} '${url}'`];
    if(ep.method!=='GET') lines.push(`  -H 'Content-Type: application/json'`);
    if(ep.auth) lines.push(`  -H 'X-API-Key: ${cfg.key||'<TU_API_KEY>'}'`);
    const bs = bodyString(ep, body, raw);
    if(bs!==null && bs!=='') lines.push(`  -d '${bs.replace(/'/g,`'\\''`)}'`);
    $('#curlOut').textContent = lines.join(" \\\n");
  }

  async function send(){
    const {ep, body, query, raw} = collect();
    const url = buildUrl(ep, query);
    const headers = {};
    if(ep.method!=='GET') headers['Content-Type']='application/json';
    if(ep.auth) headers['X-API-Key']=cfg.key;
    const bs = bodyString(ep, body, raw);

    const btn = $('#sendBtn'); const old = btn.innerHTML;
    btn.disabled=true; btn.innerHTML='<span class="spinner"></span> Enviando…';
    const t0 = performance.now();
    try{
      const opts = {method:ep.method, headers};
      if(bs!==null) opts.body = bs;
      const res = await fetch(url, opts);
      const ms = Math.round(performance.now()-t0);
      const text = await res.text();
      renderResponse(res.status, ms, text);
    }catch(err){
      renderResponse(null, Math.round(performance.now()-t0), 'Error de red: '+err.message);
    }finally{
      btn.disabled=false; btn.innerHTML=old;
    }
  }

  function renderResponse(status, ms, text){
    let cls='err', label='SIN RESPUESTA';
    if(status!==null){ cls = 's'+String(status)[0]; label = status; }
    let pretty=text;
    try{ pretty = JSON.stringify(JSON.parse(text), null, 2); }catch(_){}
    $('#respArea').innerHTML = `
      <div class="resp-head">
        <span class="status ${cls}">${esc(String(label))}</span>
        <span class="resp-meta">${ms} ms</span>
      </div>
      <div class="curl-box">
        <button class="copy-btn" id="copyResp">${ICON.copy} copiar</button>
        <pre>${esc(pretty)}</pre>
      </div>`;
    const c=$('#copyResp'); if(c) c.addEventListener('click',()=>copyText(pretty,c));
  }

  function copyCurl(){ copyText($('#curlOut').textContent, $('#copyCurl')); }
  function copyText(txt, btn){
    navigator.clipboard.writeText(txt).then(()=>{
      const old=btn.innerHTML; btn.textContent='copiado ✓';
      setTimeout(()=>btn.innerHTML=old,1200);
    });
  }

  function esc(s){ return String(s).replace(/[&<>"]/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
  function escAttr(s){ return esc(s).replace(/'/g,'&#39;'); }
  function cssEsc(s){ return String(s).replace(/[^a-zA-Z0-9_-]/g,'\\$&'); }

  buildNav();
  // Open Precios by default
  select('__precios__');
})();
</script>
</body>
</html>
