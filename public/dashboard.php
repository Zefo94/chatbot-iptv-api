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

  /* ---------- Revendedores section ---------- */
  .rev-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:4px}
  .rev-header h1{font-size:22px;margin:0}
  .rev-grid{display:grid;gap:16px;margin-top:20px}
  .rev-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:18px}
  .rev-card h2{font-size:13px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin:0 0 16px;font-weight:600}
  .rev-table{width:100%;border-collapse:collapse}
  .rev-table th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);padding:8px 12px;border-bottom:1px solid var(--border)}
  .rev-table td{padding:10px 12px;border-bottom:1px solid #1a2a40;vertical-align:middle;font-size:14px}
  .rev-table tr:last-child td{border-bottom:none}
  .rev-table tr:hover td{background:rgba(255,255,255,.025)}
  .credits-badge{font-family:var(--mono);background:rgba(34,197,94,.12);color:var(--accent);border:1px solid rgba(34,197,94,.25);border-radius:6px;padding:3px 10px;font-size:13px;font-weight:600}
  .credits-badge.low{background:rgba(248,113,113,.12);color:var(--danger);border-color:rgba(248,113,113,.25)}
  .rev-actions{display:flex;gap:6px;align-items:center}
  .btn-sm-danger{background:rgba(248,113,113,.15);color:var(--danger);border:1px solid rgba(248,113,113,.3);border-radius:6px;padding:5px 10px;font-size:12px;font-weight:600;cursor:pointer;transition:background .15s}
  .btn-sm-danger:hover{background:rgba(248,113,113,.28)}
  .btn-sm-info{background:rgba(56,189,248,.12);color:var(--info);border:1px solid rgba(56,189,248,.25);border-radius:6px;padding:5px 10px;font-size:12px;font-weight:600;cursor:pointer;transition:background .15s}
  .btn-sm-info:hover{background:rgba(56,189,248,.22)}
  .recharge-row{display:none;background:#0f1d31}
  .recharge-row td{padding:10px 14px}
  .recharge-inner{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
  .recharge-inner input{width:110px;padding:6px 10px;font-size:14px}
  .recharge-inner textarea{flex:1;min-width:180px;padding:6px 10px;font-size:13px;resize:none;height:38px;font-family:var(--sans)}
  .create-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  @media(max-width:640px){.create-form-grid{grid-template-columns:1fr}}


  ::-webkit-scrollbar{width:10px;height:10px}
  ::-webkit-scrollbar-thumb{background:var(--surface-2);border-radius:6px}

  @media (max-width:760px){
    .layout{grid-template-columns:1fr}
    nav{border-right:none;border-bottom:1px solid var(--border);max-height:230px}
    .cfg{width:100%}.cfg input{flex:1;min-width:0}
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
        <input id="apiKey" type="password" spellcheck="false" autocomplete="off" placeholder="CHATBOT_API_KEY del .env">
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
        <button class="nav-item" data-id="__revendedores__">
          <span class="badge MGT">MGT</span>
          <span class="ttl">Revendedores</span>
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

  // ---- Revendedores section ----
  async function renderRevendedores(){
    currentId = '__revendedores__';
    document.querySelectorAll('.nav-item').forEach(b=>b.classList.toggle('active', b.dataset.id==='__revendedores__'));
    const main = $('#main');
    main.innerHTML = `
      <div class="rev-header">
        <div>
          <h1>Revendedores</h1>
          <p style="margin-top:4px;color:var(--muted);font-size:13.5px">Administra los revendedores registrados y sus créditos en XUI.ONE.</p>
        </div>
        <button class="precios-reload" id="reloadRevs">${ICON.refresh} Actualizar</button>
      </div>
      <div class="rev-grid">
        <div class="rev-card">
          <h2>Revendedores registrados</h2>
          <div id="revTableBody"><div class="precios-loading">Cargando…</div></div>
        </div>
        <div class="rev-card">
          <h2>Registrar nuevo revendedor</h2>
          <div class="create-form-grid">
            <div class="form-row">
              <label>Nombre <span class="req">*</span></label>
              <input id="rev_nombre" placeholder="ej: Juan Pérez" spellcheck="false">
            </div>
            <div class="form-row">
              <label>Teléfono <span style="color:var(--muted);font-size:11px">(opcional)</span></label>
              <input id="rev_telefono" placeholder="ej: +34600000000" spellcheck="false">
            </div>
            <div class="form-row">
              <label>XUI User ID <span class="req">*</span></label>
              <input id="rev_user_id" type="number" placeholder="ej: 17">
              <div class="help">ID numérico del usuario en el panel XUI.ONE</div>
            </div>
            <div class="form-row">
              <label>XUI Username <span class="req">*</span></label>
              <input id="rev_username" placeholder="ej: brenderos94" spellcheck="false">
            </div>
            <div class="form-row" style="grid-column:1/-1">
              <label>XUI API Key <span class="req">*</span></label>
              <input id="rev_api_key" placeholder="ej: 52035387EB0A9C3E4CD8D6133B219493" spellcheck="false" style="font-family:var(--mono)">
              <div class="help">API Key del revendedor en XUI.ONE (no la del admin)</div>
            </div>
            <div class="form-row">
              <label>Contraseña panel <span class="req">*</span></label>
              <input id="rev_password" type="password" placeholder="mín. 6 caracteres">
              <div class="help">El revendedor usará esta contraseña para entrar en /reseller</div>
            </div>
          </div>
          <div class="actions">
            <button class="btn btn-primary" id="createRevBtn">${ICON.send} Registrar revendedor</button>
          </div>
          <div id="createRevMsg" style="margin-top:12px;font-size:13.5px"></div>
        </div>
      </div>`;
    $('#reloadRevs').addEventListener('click', loadRevendedores);
    $('#createRevBtn').addEventListener('click', createRevendedor);
    await loadRevendedores();
  }

  async function loadRevendedores(){
    const body = $('#revTableBody');
    if(!body) return;
    body.innerHTML = '<div class="precios-loading">Cargando…</div>';
    try {
      const res = await apiFetch('/api/listar-revendedores', {});
      const revs = res?.data?.revendedores || [];
      if(!revs.length){
        body.innerHTML = '<div style="padding:20px;color:var(--muted);font-size:14px">No hay revendedores registrados.</div>';
        return;
      }
      const rows = revs.map(r => {
        const low = r.creditos_cache < 10;
        return `<tr data-rev-id="${r.local_id}" data-xui-user-id="${r.xui_user_id}">
          <td><strong>${esc(r.nombre)}</strong><br><span style="font-size:12px;color:var(--muted)">${esc(r.xui_username)}</span></td>
          <td style="font-size:12px;color:var(--muted)">${r.telefono ? esc(r.telefono) : '—'}</td>
          <td><span class="credits-badge${low?' low':''}">${r.creditos_cache} créditos</span></td>
          <td>
            <span style="font-size:12px;${r.active?'color:var(--accent)':'color:var(--danger)'}">${r.active ? '● Activo' : '● Inactivo'}</span>
          </td>
          <td>
            <div class="rev-actions">
              <button class="btn-sm-info sync-btn" data-local-id="${r.local_id}">Sincronizar</button>
              <button class="btn-sm-info recharge-toggle" data-local-id="${r.local_id}">Recargar</button>
              <button class="btn-sm-info passwd-toggle" data-local-id="${r.local_id}">Contraseña</button>
              <button class="btn-sm-info edit-toggle" data-local-id="${r.local_id}" data-nombre="${escAttr(r.nombre)}" data-telefono="${escAttr(r.telefono||'')}" data-xui-user-id="${r.xui_user_id}" data-xui-username="${escAttr(r.xui_username)}">Editar</button>
              <button class="btn-sm-danger delete-btn" data-local-id="${r.local_id}" data-name="${esc(r.nombre)}">Eliminar</button>
            </div>
          </td>
        </tr>
        <tr class="recharge-row" id="passwd-${r.local_id}" style="display:none">
          <td colspan="5">
            <div class="recharge-inner">
              <span style="font-size:13px;color:var(--muted)">Nueva contraseña panel:</span>
              <input type="password" class="passwd-input" placeholder="mín. 6 caracteres" style="width:200px">
              <button class="btn btn-primary btn-sm do-passwd" data-local-id="${r.local_id}" style="white-space:nowrap">Guardar</button>
              <span class="passwd-msg" style="font-size:13px"></span>
            </div>
          </td>
        </tr>
        <tr class="recharge-row" id="recharge-${r.local_id}">
          <td colspan="5">
            <div class="recharge-inner">
              <span style="font-size:13px;color:var(--muted)">Créditos a recargar:</span>
              <input type="number" class="recharge-amount" placeholder="ej: 50" min="1" style="width:110px">
              <input type="text" class="recharge-nota" placeholder="Nota (ej: Pago USDT)" style="flex:1;min-width:160px">
              <button class="btn btn-primary btn-sm do-recharge" data-local-id="${r.local_id}" style="white-space:nowrap">${ICON.send} Confirmar</button>
              <span class="recharge-msg" style="font-size:13px"></span>
            </div>
          </td>
        </tr>
        <tr class="recharge-row" id="edit-${r.local_id}" style="display:none">
          <td colspan="5">
            <div class="recharge-inner" style="flex-wrap:wrap;gap:8px 12px">
              <input type="text" class="edit-nombre" placeholder="Nombre" style="width:160px">
              <input type="text" class="edit-telefono" placeholder="Teléfono" style="width:120px">
              <input type="number" class="edit-xui-id" placeholder="XUI User ID" style="width:110px">
              <input type="text" class="edit-username" placeholder="XUI Username" style="width:140px">
              <input type="text" class="edit-apikey" placeholder="XUI API Key (vacío = no cambiar)" style="width:260px">
              <button class="btn btn-primary btn-sm do-edit" data-local-id="${r.local_id}" style="white-space:nowrap">Guardar</button>
              <span class="edit-msg" style="font-size:13px"></span>
            </div>
          </td>
        </tr>`;
      }).join('');

      body.innerHTML = `
        <table class="rev-table">
          <thead><tr>
            <th>Revendedor</th><th>Teléfono</th><th>Créditos</th><th>Estado</th><th>Acciones</th>
          </tr></thead>
          <tbody>${rows}</tbody>
        </table>`;

      body.querySelectorAll('.sync-btn').forEach(btn =>
        btn.addEventListener('click', () => syncRevendedor(btn.dataset.localId, btn)));
      body.querySelectorAll('.recharge-toggle').forEach(btn =>
        btn.addEventListener('click', () => toggleRecharge(btn.dataset.localId)));
      body.querySelectorAll('.passwd-toggle').forEach(btn =>
        btn.addEventListener('click', () => togglePasswd(btn.dataset.localId)));
      body.querySelectorAll('.delete-btn').forEach(btn =>
        btn.addEventListener('click', () => deleteRevendedor(btn.dataset.localId, btn.dataset.name)));
      body.querySelectorAll('.do-recharge').forEach(btn =>
        btn.addEventListener('click', () => doRecharge(btn.dataset.localId)));
      body.querySelectorAll('.do-passwd').forEach(btn =>
        btn.addEventListener('click', () => doSetPassword(btn.dataset.localId)));
      body.querySelectorAll('.edit-toggle').forEach(btn =>
        btn.addEventListener('click', () => toggleEdit(btn)));
      body.querySelectorAll('.do-edit').forEach(btn =>
        btn.addEventListener('click', () => doEditRevendedor(btn.dataset.localId)));
    } catch(e) {
      body.innerHTML = `<div style="padding:20px;color:var(--danger);font-size:14px">Error: ${esc(e.message)}</div>`;
    }
  }

  function toggleRecharge(localId){
    const row = document.getElementById('recharge-'+localId);
    if(!row) return;
    row.style.display = row.style.display === 'table-row' ? 'none' : 'table-row';
  }

  function togglePasswd(localId){
    const row = document.getElementById('passwd-'+localId);
    if(!row) return;
    row.style.display = row.style.display === 'table-row' ? 'none' : 'table-row';
    if(row.style.display === 'table-row') row.querySelector('.passwd-input').focus();
  }

  function toggleEdit(btn){
    const localId = btn.dataset.localId;
    const row = document.getElementById('edit-'+localId);
    if(!row) return;
    const opening = row.style.display !== 'table-row';
    row.style.display = opening ? 'table-row' : 'none';
    if(opening){
      row.querySelector('.edit-nombre').value    = btn.dataset.nombre || '';
      row.querySelector('.edit-telefono').value  = btn.dataset.telefono || '';
      row.querySelector('.edit-xui-id').value    = btn.dataset.xuiUserId || '';
      row.querySelector('.edit-username').value  = btn.dataset.xuiUsername || '';
      row.querySelector('.edit-apikey').value    = '';
      row.querySelector('.edit-nombre').focus();
    }
  }

  async function doEditRevendedor(localId){
    const row = document.getElementById('edit-'+localId);
    const msg = row.querySelector('.edit-msg');
    const btn = row.querySelector('.do-edit');
    const nombre   = row.querySelector('.edit-nombre').value.trim();
    const telefono = row.querySelector('.edit-telefono').value.trim();
    const xuiId    = row.querySelector('.edit-xui-id').value.trim();
    const username = row.querySelector('.edit-username').value.trim();
    const apiKey   = row.querySelector('.edit-apikey').value.trim();
    if(!nombre){ msg.style.color='var(--danger)'; msg.textContent='El nombre es requerido'; return; }
    btn.disabled=true; btn.textContent='…'; msg.textContent='';
    const payload = { revendedor_id: parseInt(localId), nombre, telefono };
    if(xuiId)    payload.xui_user_id   = parseInt(xuiId);
    if(username) payload.xui_username  = username;
    if(apiKey)   payload.xui_api_key   = apiKey;
    try {
      await apiFetch('/api/editar-revendedor', payload);
      msg.style.color='var(--accent)'; msg.textContent='✓ Actualizado';
      setTimeout(()=>{ row.style.display='none'; msg.textContent=''; loadRevendedores(); }, 1500);
    } catch(e) {
      msg.style.color='var(--danger)'; msg.textContent='✗ '+e.message;
    } finally {
      btn.disabled=false; btn.textContent='Guardar';
    }
  }

  async function doSetPassword(localId){
    const row = document.getElementById('passwd-'+localId);
    const password = row.querySelector('.passwd-input').value;
    const msg = row.querySelector('.passwd-msg');
    const btn = row.querySelector('.do-passwd');
    if(!password || password.length < 6){ msg.style.color='var(--danger)'; msg.textContent='Mínimo 6 caracteres'; return; }
    btn.disabled=true; btn.textContent='…'; msg.textContent='';
    try {
      await apiFetch('/api/set-reseller-password', { revendedor_id: parseInt(localId), password });
      msg.style.color='var(--accent)'; msg.textContent='✓ Contraseña actualizada';
      row.querySelector('.passwd-input').value='';
      setTimeout(()=>{ row.style.display='none'; msg.textContent=''; }, 2000);
    } catch(e) {
      msg.style.color='var(--danger)'; msg.textContent='✗ '+e.message;
    } finally {
      btn.disabled=false; btn.textContent='Guardar';
    }
  }

  async function syncRevendedor(localId, btn){
    const old = btn.textContent;
    btn.disabled = true; btn.textContent = '…';
    try {
      const res = await apiFetch('/api/saldo-revendedor', { revendedor_id: parseInt(localId) });
      btn.textContent = '✓ '+(res?.data?.creditos ?? res?.data?.creditos_cache);
      setTimeout(()=>{ btn.textContent=old; btn.disabled=false; loadRevendedores(); }, 1500);
    } catch(e) {
      btn.textContent = '✗ Error';
      setTimeout(()=>{ btn.textContent=old; btn.disabled=false; }, 2000);
    }
  }

  async function doRecharge(localId){
    const row = document.getElementById('recharge-'+localId);
    const amount = parseInt(row.querySelector('.recharge-amount').value);
    const nota = row.querySelector('.recharge-nota').value.trim();
    const msg = row.querySelector('.recharge-msg');
    const btn = row.querySelector('.do-recharge');

    if(!amount || amount < 1){ msg.style.color='var(--danger)'; msg.textContent='Cantidad inválida'; return; }

    btn.disabled=true; btn.innerHTML='<span class="spinner"></span>';
    msg.textContent='';
    try {
      const res = await apiFetch('/api/recargar-creditos', {
        revendedor_id: parseInt(localId),
        creditos: amount,
        nota: nota || undefined,
      });
      msg.style.color='var(--accent)';
      msg.textContent = `✓ ${res?.data?.creditos_antes} → ${res?.data?.creditos_despues} créditos`;
      setTimeout(()=>{ row.style.display='none'; loadRevendedores(); }, 2000);
    } catch(e) {
      msg.style.color='var(--danger)';
      msg.textContent = '✗ '+e.message;
    } finally {
      btn.disabled=false; btn.innerHTML=ICON.send+' Confirmar';
    }
  }

  async function deleteRevendedor(localId, nombre){
    if(!confirm(`¿Eliminar al revendedor "${nombre}"? Esta acción no se puede deshacer.`)) return;
    try {
      await apiFetch('/api/eliminar-revendedor', { revendedor_id: parseInt(localId) });
      loadRevendedores();
    } catch(e) {
      alert('Error al eliminar: '+e.message);
    }
  }

  async function createRevendedor(){
    const nombre    = $('#rev_nombre').value.trim();
    const telefono  = $('#rev_telefono').value.trim();
    const userId    = parseInt($('#rev_user_id').value);
    const username  = $('#rev_username').value.trim();
    const apiKey    = $('#rev_api_key').value.trim();
    const password  = $('#rev_password').value;
    const msgEl     = $('#createRevMsg');

    if(!nombre || !userId || !username || !apiKey || !password){
      msgEl.style.color='var(--danger)'; msgEl.textContent='Completa los campos obligatorios incluyendo la contraseña.'; return;
    }
    if(password.length < 6){
      msgEl.style.color='var(--danger)'; msgEl.textContent='La contraseña debe tener al menos 6 caracteres.'; return;
    }

    const btn = $('#createRevBtn'); const old = btn.innerHTML;
    btn.disabled=true; btn.innerHTML='<span class="spinner"></span> Registrando…';
    msgEl.textContent='';
    try {
      const payload = { nombre, xui_user_id: userId, xui_username: username, xui_api_key: apiKey };
      if(telefono) payload.telefono = telefono;
      const res = await apiFetch('/api/crear-revendedor', payload);
      // Set password right after creation
      await apiFetch('/api/set-reseller-password', { revendedor_id: res?.data?.revendedor?.local_id, password });
      msgEl.style.color='var(--accent)';
      msgEl.textContent = `✓ Revendedor registrado. Créditos: ${res?.data?.revendedor?.creditos ?? '—'}. Puede entrar en /reseller`;
      $('#rev_nombre').value=''; $('#rev_telefono').value=''; $('#rev_user_id').value='';
      $('#rev_username').value=''; $('#rev_api_key').value=''; $('#rev_password').value='';
      setTimeout(loadRevendedores, 800);
    } catch(e) {
      msgEl.style.color='var(--danger)';
      msgEl.textContent = '✗ '+e.message;
    } finally {
      btn.disabled=false; btn.innerHTML=old;
    }
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
    if(id==='__revendedores__'){ renderRevendedores(); return; }
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
  select('__revendedores__');
})();
</script>
</body>
</html>
