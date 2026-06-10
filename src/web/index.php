<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Xray VPN — Keenetic</title>
<style>
:root {
  --bg: #0f1117;
  --card: #1a1d27;
  --card2: #22263a;
  --border: #2a2e3f;
  --accent: #6366f1;
  --accent2: #818cf8;
  --green: #10b981;
  --red: #ef4444;
  --orange: #f59e0b;
  --cyan: #06b6d4;
  --text: #e2e8f0;
  --text2: #64748b;
  --shadow: 0 4px 24px rgba(0,0,0,.4);
  --radius: 12px;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,'SF Pro Display','Inter','Segoe UI',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.app{max-width:1120px;margin:0 auto;padding:20px}

/* Header */
.header{display:flex;align-items:center;justify-content:space-between;padding:20px 24px;margin-bottom:24px;background:linear-gradient(135deg,#4f46e5 0%,#7c3aed 100%);border-radius:16px;box-shadow:var(--shadow)}
.header h1{font-size:20px;font-weight:700;color:#fff;display:flex;align-items:center;gap:10px}
.header-controls{display:flex;gap:8px}

/* Status bar */
.status-bar{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:24px}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:16px;transition:transform .15s}
.stat-card:hover{transform:translateY(-2px)}
.stat-label{font-size:11px;text-transform:uppercase;letter-spacing:1px;color:var(--text2);margin-bottom:6px;display:flex;align-items:center;gap:6px}
.stat-label svg{width:14px;height:14px;opacity:.6}
.stat-value{font-size:18px;font-weight:700;word-break:break-all}
.green{color:var(--green)}.red{color:var(--red)}.orange{color:var(--orange)}.cyan{color:var(--cyan)}

/* Tabs */
.tabs{display:flex;gap:2px;margin-bottom:20px;background:var(--card);border-radius:var(--radius);padding:4px;overflow-x:auto;border:1px solid var(--border)}
.tab{padding:10px 16px;cursor:pointer;border-radius:8px;font-size:13px;font-weight:500;color:var(--text2);transition:all .2s;white-space:nowrap;border:none;background:none;display:flex;align-items:center;gap:6px}
.tab:hover{color:var(--text);background:var(--card2)}
.tab.active{background:var(--accent);color:#fff}
.tab svg{width:16px;height:16px}

/* Panels */
.panel{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:24px;display:none;box-shadow:var(--shadow)}
.panel.active{display:block}
.panel-title{font-size:15px;font-weight:600;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.panel-title svg{width:20px;height:20px;color:var(--accent2)}
.panel-desc{color:var(--text2);font-size:12px;margin-bottom:14px}

/* Inputs */
.input-group{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}
input[type="text"],input[type="url"],textarea,select{background:var(--bg);border:1px solid var(--border);color:var(--text);padding:10px 14px;border-radius:8px;font-size:13px;outline:none;transition:border-color .2s;font-family:inherit}
input:focus,textarea:focus,select:focus{border-color:var(--accent)}
input.flex1,textarea.flex1{flex:1;min-width:200px}
textarea{resize:vertical;min-height:80px;width:100%}

/* Buttons */
.btn{padding:10px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;transition:all .15s;display:inline-flex;align-items:center;gap:6px;white-space:nowrap}
.btn:active{transform:scale(.96)}
.btn-primary{background:var(--accent);color:#fff}.btn-primary:hover{background:var(--accent2)}
.btn-success{background:var(--green);color:#fff}.btn-success:hover{opacity:.85}
.btn-danger{background:var(--red);color:#fff}.btn-danger:hover{opacity:.85}
.btn-warn{background:var(--orange);color:#111}.btn-warn:hover{opacity:.85}
.btn-ghost{background:transparent;border:1px solid var(--border);color:var(--text2)}.btn-ghost:hover{border-color:var(--accent);color:var(--text)}
.btn-sm{padding:6px 12px;font-size:12px}
.btn-icon{padding:6px 8px;font-size:14px;line-height:1}

/* List */
.list{display:flex;flex-direction:column;gap:6px;max-height:440px;overflow-y:auto;padding-right:4px}
.list::-webkit-scrollbar{width:4px}.list::-webkit-scrollbar-thumb{background:var(--border);border-radius:2px}
.list-item{display:flex;align-items:center;gap:10px;background:var(--card2);border:1px solid var(--border);border-radius:10px;padding:10px 14px;transition:background .15s}
.list-item:hover{background:#2a2e45}
.list-item .info{flex:1;overflow:hidden}
.list-item .name{font-weight:600;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.list-item .meta{font-size:11px;color:var(--text2);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.list-item .actions{display:flex;gap:4px;flex-shrink:0}

/* Badge */
.badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase;margin-left:6px}
.badge-vless{background:rgba(99,102,241,.2);color:var(--accent2)}
.badge-ss,.badge-shadowsocks{background:rgba(16,185,129,.2);color:var(--green)}
.badge-active{background:rgba(16,185,129,.3);color:var(--green)}
.badge-off{background:rgba(239,68,68,.15);color:var(--red)}

/* Radio list (servers) */
.radio-list{display:flex;flex-direction:column;gap:4px;margin-bottom:12px;max-height:380px;overflow-y:auto}
.radio-item{display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--card2);border:1px solid var(--border);border-radius:10px;cursor:pointer;transition:all .15s}
.radio-item:hover{border-color:var(--accent)}
.radio-item.selected{border-color:var(--green);background:rgba(16,185,129,.06)}
.radio-dot{width:16px;height:16px;border-radius:50%;border:2px solid var(--border);flex-shrink:0;position:relative}
.radio-item.selected .radio-dot{border-color:var(--green)}
.radio-item.selected .radio-dot::after{content:'';position:absolute;top:3px;left:3px;width:6px;height:6px;border-radius:50%;background:var(--green)}

/* Toggle */
.toggle{width:40px;height:22px;background:var(--border);border-radius:11px;position:relative;cursor:pointer;transition:background .2s;flex-shrink:0}
.toggle.on{background:var(--green)}
.toggle::after{content:'';position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;transition:transform .2s}
.toggle.on::after{transform:translateX(18px)}

/* Log view */
.log-view{background:#0a0c10;border:1px solid var(--border);border-radius:8px;padding:12px;font-family:'SF Mono','Fira Code','JetBrains Mono',monospace;font-size:11px;line-height:1.6;max-height:440px;overflow:auto;color:#94a3b8;white-space:pre-wrap;word-break:break-all}

/* Toast */
.toast{position:fixed;bottom:24px;right:24px;background:var(--green);color:#fff;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;transform:translateY(80px);opacity:0;transition:all .3s;z-index:999;box-shadow:0 8px 32px rgba(0,0,0,.5)}
.toast.show{transform:translateY(0);opacity:1}
.toast.error{background:var(--red)}

/* WG config modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:100;align-items:center;justify-content:center}
.modal-overlay.show{display:flex}
.modal{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:24px;max-width:520px;width:90%;max-height:80vh;overflow-y:auto}
.modal-title{font-size:16px;font-weight:700;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between}
.modal pre{background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:12px;font-family:monospace;font-size:11px;white-space:pre-wrap;word-break:break-all;color:var(--cyan);margin-bottom:12px}
.modal .qr-box{text-align:center;margin:12px 0;font-family:monospace;font-size:4px;line-height:4.5px;letter-spacing:1px;white-space:pre;color:#fff;background:#000;padding:12px;border-radius:8px;overflow-x:auto}

/* Spinner */
.spinner{width:16px;height:16px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .6s linear infinite;display:inline-block}
@keyframes spin{to{transform:rotate(360deg)}}

/* Section divider */
.section-divider{height:1px;background:var(--border);margin:16px 0}

/* Responsive */
@media(max-width:640px){
  .app{padding:10px}
  .header{flex-direction:column;gap:12px;text-align:center}
  .status-bar{grid-template-columns:1fr 1fr}
  .tabs{flex-wrap:nowrap;overflow-x:auto}
  .input-group{flex-direction:column}
  input.flex1{min-width:auto}
}
</style>
</head>
<body>
<div class="app">

<div class="header">
  <h1>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
    Xray VPN Manager
  </h1>
  <div class="header-controls">
    <button class="btn btn-success" onclick="doAction('restart')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
      Перезапуск
    </button>
    <button class="btn btn-danger" id="btnToggle" onclick="toggleService()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>
      <span id="btnToggleText">Стоп</span>
    </button>
  </div>
</div>

<div class="status-bar" id="statusBar">
  <div class="stat-card">
    <div class="stat-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg> Статус</div>
    <div class="stat-value" id="stStatus">—</div>
  </div>
  <div class="stat-card">
    <div class="stat-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> VPN IP</div>
    <div class="stat-value" id="stVpnIp">—</div>
  </div>
  <div class="stat-card">
    <div class="stat-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9"/></svg> Реальный IP</div>
    <div class="stat-value" id="stRealIp">—</div>
  </div>
  <div class="stat-card">
    <div class="stat-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg> Память</div>
    <div class="stat-value" id="stMem">—</div>
  </div>
  <div class="stat-card">
    <div class="stat-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> WireGuard</div>
    <div class="stat-value" id="stWg">—</div>
  </div>
</div>

<div class="tabs">
  <button class="tab active" data-tab="servers"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg> Серверы</button>
  <button class="tab" data-tab="subscriptions"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 11a9 9 0 019 9"/><path d="M4 4a16 16 0 0116 16"/><circle cx="5" cy="19" r="1"/></svg> Подписки</button>
  <button class="tab" data-tab="keys"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 11-7.778 7.778 5.5 5.5 0 017.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg> Ключи</button>
  <button class="tab" data-tab="domains"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg> Домены</button>
  <button class="tab" data-tab="ips"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg> IP-адреса</button>
  <button class="tab" data-tab="github"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg> Списки v2fly</button>
  <button class="tab" data-tab="devices"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg> Устройства</button>
  <button class="tab" data-tab="wireguard"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg> WireGuard</button>
  <button class="tab" data-tab="logs"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg> Логи</button>
</div>

<!-- SERVERS -->
<div class="panel active" id="panel-servers">
  <div class="panel-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg> Выбор сервера</div>
  <p class="panel-desc">Выберите активный сервер для VPN-подключения</p>
  <div id="serverList" class="radio-list"></div>
  <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
    <button class="btn btn-primary" onclick="applyServer()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="20 6 9 17 4 12"/></svg> Применить</button>
    <button class="btn btn-warn" onclick="testConnection()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg> Тест</button>
  </div>
  <div id="testResult" style="margin-top:10px;font-size:13px;color:var(--text2)"></div>
</div>

<!-- SUBSCRIPTIONS -->
<div class="panel" id="panel-subscriptions">
  <div class="panel-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 11a9 9 0 019 9"/><path d="M4 4a16 16 0 0116 16"/><circle cx="5" cy="19" r="1"/></svg> Подписки</div>
  <div class="input-group">
    <input type="text" class="flex1" id="subName" placeholder="Название">
    <input type="url" class="flex1" id="subUrl" placeholder="URL подписки">
    <button class="btn btn-primary" onclick="addSub()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Добавить</button>
  </div>
  <button class="btn btn-success btn-sm" style="margin-bottom:12px" onclick="updateSubs()">
    <span class="spinner" style="display:none" id="subSpinner"></span>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg> Обновить подписки
  </button>
  <div id="subList" class="list"></div>
</div>

<!-- KEYS -->
<div class="panel" id="panel-keys">
  <div class="panel-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 11-7.778 7.778 5.5 5.5 0 017.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg> Ручные ключи</div>
  <div class="input-group">
    <input type="text" class="flex1" id="keyName" placeholder="Название">
    <input type="text" class="flex1" id="keyLink" placeholder="vless://... или ss://...">
    <button class="btn btn-primary" onclick="addKey()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Добавить</button>
  </div>
  <div id="keyList" class="list"></div>
</div>

<!-- DOMAINS -->
<div class="panel" id="panel-domains">
  <div class="panel-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg> Домены через VPN</div>
  <div class="input-group">
    <input type="text" class="flex1" id="domainInput" placeholder="Один домен (example.com)">
    <button class="btn btn-primary" onclick="addDomains($('#domainInput').value)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Добавить</button>
  </div>
  <div style="margin-bottom:12px">
    <textarea class="flex1" id="domainBatch" placeholder="Пакетное добавление: по одному на строку или через запятую&#10;example.com&#10;test.ru, site.org"></textarea>
    <button class="btn btn-success btn-sm" style="margin-top:6px" onclick="addDomains($('#domainBatch').value);$('#domainBatch').value=''">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 2v4H8V2"/></svg> Добавить пачкой
    </button>
  </div>
  <div style="margin-bottom:8px;font-size:12px;color:var(--text2)">Всего доменов: <strong id="domainCount">0</strong></div>
  <input type="text" id="domainSearch" placeholder="Поиск..." style="width:100%;margin-bottom:8px" oninput="filterDomains()">
  <div id="domainList" class="list"></div>
</div>

<!-- IPs -->
<div class="panel" id="panel-ips">
  <div class="panel-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg> IP-адреса через VPN</div>
  <div class="input-group">
    <input type="text" class="flex1" id="ipInput" placeholder="IP или CIDR (1.2.3.4 или 10.0.0.0/24)">
    <button class="btn btn-primary" onclick="addIps($('#ipInput').value)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Добавить</button>
  </div>
  <div style="margin-bottom:12px">
    <textarea class="flex1" id="ipBatch" placeholder="Пакетное добавление: по одному на строку&#10;1.2.3.4&#10;10.0.0.0/24"></textarea>
    <button class="btn btn-success btn-sm" style="margin-top:6px" onclick="addIps($('#ipBatch').value);$('#ipBatch').value=''">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 2v4H8V2"/></svg> Добавить пачкой
    </button>
  </div>
  <div style="margin-bottom:8px;font-size:12px;color:var(--text2)">Всего IP: <strong id="ipCount">0</strong></div>
  <div id="ipList" class="list"></div>
</div>

<!-- GITHUB / V2FLY -->
<div class="panel" id="panel-github">
  <div class="panel-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg> Списки доменов v2fly</div>
  <p class="panel-desc">Поиск по каталогу <a href="https://github.com/v2fly/domain-list-community/tree/master/data" target="_blank" style="color:var(--accent)">v2fly/domain-list-community</a>. Введите название сервиса — все его домены добавятся в обход блокировок.</p>
  <div class="input-group">
    <input type="text" class="flex1" id="v2flySearch" placeholder="Введите: google, openai, twitter, discord..." oninput="v2flyDoSearch()">
  </div>
  <div id="v2flyResults" style="margin-bottom:16px"></div>
  <div class="section-divider"></div>
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
    <div class="panel-title" style="margin-bottom:0;font-size:13px">Подключённые списки</div>
    <button class="btn btn-success btn-sm" onclick="v2flyRefreshAll()">
      <span class="spinner" style="display:none" id="v2flySpinner"></span>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg> Обновить все
    </button>
  </div>
  <div id="ghList" class="list"></div>
</div>

<!-- DEVICES -->
<div class="panel" id="panel-devices">
  <div class="panel-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg> Полный VPN для устройств</div>
  <p class="panel-desc">Весь трафик устройства пойдет через VPN (по MAC-адресу). Имена устройств загружаются из Keenetic.</p>
  <div class="input-group">
    <input type="text" class="flex1" id="macInput" placeholder="MAC-адрес (AA:BB:CC:DD:EE:FF)">
    <button class="btn btn-primary" onclick="addDevice()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Добавить</button>
  </div>
  <div style="margin-bottom:12px">
    <div class="panel-title" style="font-size:13px">Устройства с полным VPN:</div>
    <div id="fullvpnList" class="list" style="margin-bottom:12px"></div>
  </div>
  <div class="section-divider"></div>
  <div>
    <div class="panel-title" style="font-size:13px">Устройства в сети:</div>
    <div id="lanList" class="list"></div>
  </div>
</div>

<!-- WIREGUARD -->
<div class="panel" id="panel-wireguard">
  <div class="panel-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg> WireGuard VPN</div>
  <p class="panel-desc">Управление WireGuard клиентами. Нажмите на клиента для просмотра конфигурации и QR-кода.</p>
  <div class="input-group">
    <input type="text" class="flex1" id="wgName" placeholder="Имя клиента (iPhone_User, Mac_User)">
    <button class="btn btn-primary" onclick="wgAddPeer()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Добавить</button>
    <button class="btn btn-ghost" onclick="wgRestart()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg> Перезапустить WG</button>
  </div>
  <div id="wgPeerList" class="list"></div>
</div>

<!-- LOGS -->
<div class="panel" id="panel-logs">
  <div class="panel-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg> Логи Xray</div>
  <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">
    <button class="btn btn-primary btn-sm" onclick="loadLogs('error')">Error</button>
    <button class="btn btn-primary btn-sm" onclick="loadLogs('access')">Access</button>
    <button class="btn btn-danger btn-sm" onclick="clearLogs()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg> Очистить
    </button>
    <select id="logLines" style="width:100px" onchange="loadLogs()">
      <option value="30">30 строк</option>
      <option value="50" selected>50 строк</option>
      <option value="100">100 строк</option>
      <option value="200">200 строк</option>
    </select>
  </div>
  <div class="log-view" id="logView">Нажмите кнопку для загрузки логов</div>
</div>

</div>

<!-- WireGuard Config Modal -->
<div class="modal-overlay" id="wgModal">
  <div class="modal">
    <div class="modal-title"><span id="wgModalTitle">Конфигурация</span><button class="btn btn-ghost btn-sm" onclick="closeModal()">&#10005;</button></div>
    <pre id="wgModalConfig"></pre>
    <div class="qr-box" id="wgModalQr" style="display:none"></div>
    <div style="display:flex;gap:8px;margin-top:12px">
      <button class="btn btn-primary btn-sm" onclick="copyConfig()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg> Копировать
      </button>
      <button class="btn btn-ghost btn-sm" onclick="showQr()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg> QR-код
      </button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
const $=s=>document.querySelector(s);
const $$=s=>document.querySelectorAll(s);
let currentLogType='error';
let allDomains=[];
let selectedServer='';
let currentWgName='';

async function api(action,data={}){
  const isGet=typeof data==='string';
  const url=isGet?`api.php?action=${action}&${data}`:`api.php?action=${action}`;
  const opts=isGet?{}:{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(data).toString()};
  try{const r=await fetch(url,opts);return await r.json();}
  catch(e){toast('Ошибка сети: '+e.message,true);return{error:e.message};}
}

function toast(msg,isErr=false){
  const t=$('#toast');t.textContent=msg;
  t.className='toast show'+(isErr?' error':'');
  setTimeout(()=>t.className='toast',3000);
}

function doAction(act){
  toast('Выполняется...');
  api(act).then(r=>{
    if(r.error)toast(r.error,true);else toast(act==='restart'?'Перезапущено':'Готово');
    setTimeout(loadStatus,2000);
  });
}

function toggleService(){
  const st=$('#stStatus').textContent;
  doAction(st.includes('Работает')?'stop':'start');
}

// Status
async function loadStatus(){
  const s=await api('status','');
  if(s.error)return;
  const stEl=$('#stStatus');
  if(s.running){
    stEl.textContent='Работает';stEl.className='stat-value green';
    $('#btnToggleText').textContent='Стоп';$('#btnToggle').className='btn btn-danger';
  }else{
    stEl.textContent='Остановлен';stEl.className='stat-value red';
    $('#btnToggleText').textContent='Старт';$('#btnToggle').className='btn btn-success';
  }
  $('#stVpnIp').textContent=s.external_ip||'—';
  $('#stVpnIp').className='stat-value '+(s.external_ip?'green':'red');
  $('#stRealIp').textContent=s.real_ip||'—';
  $('#stMem').textContent=s.mem_used&&s.mem_total?`${s.mem_used}/${s.mem_total} MB`:'—';
  $('#stWg').textContent=s.wg_up?'Активен':'Выкл';
  $('#stWg').className='stat-value '+(s.wg_up?'green':'red');
}

// Tabs
$$('.tab').forEach(tab=>{
  tab.addEventListener('click',()=>{
    $$('.tab').forEach(t=>t.classList.remove('active'));
    $$('.panel').forEach(p=>p.classList.remove('active'));
    tab.classList.add('active');
    $(`#panel-${tab.dataset.tab}`).classList.add('active');
    loadPanel(tab.dataset.tab);
  });
});

function loadPanel(name){
  switch(name){
    case'servers':loadServers();break;case'subscriptions':loadSubs();break;
    case'keys':loadKeys();break;case'domains':loadDomains();break;
    case'ips':loadIps();break;case'github':loadGhLists();break;
    case'devices':loadDevices();break;case'wireguard':loadWgPeers();break;
    case'logs':loadLogs();break;
  }
}

// Parse VLESS/SS link params
function parseLinkParams(link){
  if(!link)return {};
  const m=link.match(/\?([^#]*)/);
  if(!m)return {};
  const params={};
  m[1].split('&').forEach(p=>{const[k,v]=p.split('=');if(k)params[k]=decodeURIComponent(v||'')});
  return params;
}

// Servers
async function loadServers(){
  const[keys,servers,status]=await Promise.all([api('keys',''),api('subscription_servers',''),api('status','')]);
  const list=$('#serverList');list.innerHTML='';
  const activeId=status.active_outbound||'';
  if(activeId&&!selectedServer)selectedServer=activeId;
  const all=[];
  if(Array.isArray(keys))keys.forEach(k=>{if(k.link)all.push({id:k.id,name:k.name||'Key',type:k.type,link:k.link,enabled:k.enabled,src:'key'})});
  if(Array.isArray(servers))servers.forEach(s=>{all.push({id:s.id,name:s.name||'Server',type:s.link?.startsWith('vless://')?'vless':'ss',link:s.link,enabled:s.enabled,src:'sub'})});
  if(!all.length){list.innerHTML='<div style="text-align:center;color:var(--text2);padding:24px">Нет серверов. Добавьте подписку или ключ.</div>';return}
  all.forEach(item=>{
    const el=document.createElement('div');
    const isActive=activeId===item.id;
    el.className='radio-item'+(selectedServer===item.id?' selected':'');
    const host=item.link?.match(/@([^:]+)/)?.[1]||'';
    const params=parseLinkParams(item.link);
    const sni=params.sni||params.serverName||'';
    const fp=params.fp||'';
    const security=params.security||'';
    const metaParts=[host];
    if(sni)metaParts.push('SNI: '+sni);
    if(fp)metaParts.push('FP: '+fp);
    if(security)metaParts.push(security);
    el.innerHTML=`<div class="radio-dot"></div><div class="info"><div class="name">${isActive?'<svg viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="3" width="14" height="14" style="vertical-align:-2px;margin-right:4px"><polyline points="20 6 9 17 4 12"/></svg>':''}${esc(item.name)}<span class="badge badge-${item.type}">${item.type}</span>${item.enabled?'':'<span class="badge badge-off">OFF</span>'}${isActive?'<span class="badge badge-active">active</span>':''}</div><div class="meta">${esc(metaParts.join(' • '))}</div></div>`;
    el.onclick=()=>{selectedServer=item.id;$$('#serverList .radio-item').forEach(e=>e.classList.remove('selected'));el.classList.add('selected')};
    list.appendChild(el);
  });
}

async function applyServer(){
  if(!selectedServer){toast('Выберите сервер',true);return}
  await api('select_server',{id:selectedServer});
  toast('Применяется...');await api('restart');
  toast('Сервер изменён');setTimeout(loadStatus,3000);
}

async function testConnection(){
  $('#testResult').innerHTML='<span class="spinner"></span> Проверка...';
  const r=await api('test_connection','');
  let html='';
  if(!r.running){html='<span class="red">Xray не запущен</span>'}
  else if(r.selective && r.vpn_route_ok){
    html=`<span class="green">VPN работает (selective mode)</span><br><span style="font-size:12px;color:var(--text2)">Реальный IP: ${esc(r.real_ip)} | Прокси: ${esc(r.proxy_ip)} (совпадает — режим «только выбранные»)<br>Роутинг через VPN: ${esc(r.test_domain)} — OK</span>`
  }else if(r.proxy_ip && r.proxy_ip !== r.real_ip){
    html=`<span class="green">VPN работает! IP: <strong>${esc(r.proxy_ip)}</strong></span><br><span style="font-size:12px;color:var(--text2)">Реальный IP: ${esc(r.real_ip)}</span>`
  }else if(r.vpn_route_ok){
    html=`<span class="green">VPN маршрутизация работает</span><br><span style="font-size:12px;color:var(--text2)">${esc(r.test_domain)} — доступен через VPN</span>`
  }else{
    html='<span class="red">VPN не работает</span>'
  }
  $('#testResult').innerHTML=html;
}

// Subscriptions
async function loadSubs(){
  const subs=await api('subscriptions','');
  const list=$('#subList');list.innerHTML='';
  if(!Array.isArray(subs)||!subs.length){list.innerHTML='<div style="text-align:center;color:var(--text2);padding:16px">Нет подписок</div>';return}
  subs.forEach(s=>{
    const el=document.createElement('div');el.className='list-item';
    el.innerHTML=`<div class="info"><div class="name">${esc(s.name)}</div><div class="meta">${esc(s.url)} ${s.updated?'• '+esc(s.updated):''}</div></div><div class="actions"><button class="btn btn-danger btn-icon" onclick="deleteSub('${s.id}')" title="Удалить">&#10005;</button></div>`;
    list.appendChild(el);
  });
}
async function addSub(){const url=$('#subUrl').value.trim(),name=$('#subName').value.trim()||'Sub';if(!url)return toast('Введите URL',true);await api('add_subscription',{url,name});$('#subUrl').value='';$('#subName').value='';toast('Добавлено');loadSubs()}
async function deleteSub(id){if(!confirm('Удалить подписку?'))return;await api('delete_subscription',{id});toast('Удалено');loadSubs()}
async function updateSubs(){$('#subSpinner').style.display='inline-block';const r=await api('update_subscriptions');$('#subSpinner').style.display='none';if(r.error)return toast(r.error,true);toast(`Обновлено: ${r.count} серверов`);loadSubs();loadServers()}

// Keys
async function loadKeys(){
  const keys=await api('keys','');
  const list=$('#keyList');list.innerHTML='';
  if(!Array.isArray(keys)||!keys.length){list.innerHTML='<div style="text-align:center;color:var(--text2);padding:16px">Нет ключей</div>';return}
  keys.forEach(k=>{
    const el=document.createElement('div');el.className='list-item';
    el.innerHTML=`<div class="toggle ${k.enabled?'on':''}" onclick="toggleKey('${k.id}')"></div><div class="info"><div class="name">${esc(k.name)}<span class="badge badge-${k.type}">${k.type||'?'}</span></div><div class="meta">${esc((k.link||'').substring(0,60))}...</div></div><div class="actions"><button class="btn btn-danger btn-icon" onclick="deleteKey('${k.id}')" title="Удалить">&#10005;</button></div>`;
    list.appendChild(el);
  });
}
async function addKey(){const link=$('#keyLink').value.trim(),name=$('#keyName').value.trim()||'Key';if(!link)return toast('Введите ключ',true);await api('add_key',{link,name});$('#keyLink').value='';$('#keyName').value='';toast('Добавлено');loadKeys()}
async function deleteKey(id){if(!confirm('Удалить?'))return;await api('delete_key',{id});toast('Удалено');loadKeys()}
async function toggleKey(id){await api('toggle_key',{id});loadKeys()}

// Domains
let manualDomains=[];
let v2flyDomains={};
async function loadDomains(){
  const r=await api('domains','');
  if(r&&r.manual){manualDomains=r.manual;v2flyDomains=r.v2fly||{}}
  else if(Array.isArray(r)){manualDomains=r;v2flyDomains={}}
  else{manualDomains=[];v2flyDomains={}}
  const v2count=Object.keys(v2flyDomains).length;
  $('#domainCount').textContent=manualDomains.length+(v2count?' + '+v2count+' v2fly':'');
  renderDomains();
}
function filterDomains(){renderDomains()}
function renderDomains(){
  const q=($('#domainSearch').value||'').toLowerCase();
  const list=$('#domainList');list.innerHTML='';
  // Manual domains
  const filteredManual=q?manualDomains.filter(d=>d.toLowerCase().includes(q)):manualDomains;
  const showManual=filteredManual.slice(0,100);
  if(showManual.length){
    showManual.forEach(d=>{const el=document.createElement('div');el.className='list-item';el.innerHTML=`<div class="info"><div class="name">${esc(d)}</div></div><button class="btn btn-danger btn-icon btn-sm" onclick="deleteDomain('${esc(d)}')" title="Удалить">&#10005;</button>`;list.appendChild(el)});
    if(filteredManual.length>100){const m=document.createElement('div');m.style.cssText='text-align:center;color:var(--text2);padding:8px;font-size:12px';m.textContent=`...и ещё ${filteredManual.length-100}`;list.appendChild(m)}
  }
  // V2fly grouped domains
  const groups={};
  Object.entries(v2flyDomains).forEach(([d,src])=>{if(!q||d.toLowerCase().includes(q)){if(!groups[src])groups[src]=[];groups[src].push(d)}});
  const groupNames=Object.keys(groups).sort();
  if(groupNames.length){
    groupNames.forEach(src=>{
      const hdr=document.createElement('div');hdr.className='list-item';hdr.style.cssText='background:rgba(99,102,241,.08);cursor:pointer;user-select:none';
      const doms=groups[src];
      hdr.innerHTML=`<div class="info"><div class="name" style="font-weight:600"><span class="badge" style="background:rgba(99,102,241,.15);color:var(--accent2);margin-right:6px">v2fly</span>${esc(src)}<span style="color:var(--text2);font-weight:400;margin-left:8px">${doms.length} доменов</span></div></div><svg class="v2g-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="transition:transform .2s"><polyline points="6 9 12 15 18 9"/></svg>`;
      const wrap=document.createElement('div');wrap.style.display='none';
      doms.slice(0,50).forEach(d=>{const el=document.createElement('div');el.className='list-item';el.style.paddingLeft='28px';el.style.opacity='.8';el.innerHTML=`<div class="info"><div class="name" style="font-size:12px">${esc(d)}</div></div>`;wrap.appendChild(el)});
      if(doms.length>50){const m=document.createElement('div');m.style.cssText='text-align:center;color:var(--text2);padding:6px;font-size:11px;padding-left:28px';m.textContent=`...и ещё ${doms.length-50}`;wrap.appendChild(m)}
      hdr.onclick=()=>{const open=wrap.style.display!=='none';wrap.style.display=open?'none':'block';hdr.querySelector('.v2g-arrow').style.transform=open?'':'rotate(180deg)'};
      list.appendChild(hdr);list.appendChild(wrap);
    });
  }
  if(!showManual.length&&!groupNames.length){list.innerHTML='<div style="text-align:center;color:var(--text2);padding:16px">'+(q?'Ничего не найдено':'Нет доменов')+'</div>'}
}
async function addDomains(val){if(!val||!val.trim())return toast('Введите домен(ы)',true);const r=await api('add_domains',{domains:val.trim()});if(r.error)return toast(r.error,true);toast(`Добавлено. Всего: ${r.count}`);$('#domainInput').value='';loadDomains()}
async function deleteDomain(d){await api('delete_domain',{domain:d});toast('Удалено');loadDomains()}

// IPs
async function loadIps(){const ips=await api('ips','');const list=$('#ipList');list.innerHTML='';$('#ipCount').textContent=Array.isArray(ips)?ips.length:0;if(!Array.isArray(ips)||!ips.length){list.innerHTML='<div style="text-align:center;color:var(--text2);padding:16px">Нет IP</div>';return}ips.forEach(ip=>{const el=document.createElement('div');el.className='list-item';el.innerHTML=`<div class="info"><div class="name">${esc(ip)}</div></div><button class="btn btn-danger btn-icon btn-sm" onclick="deleteIp('${esc(ip)}')" title="Удалить">&#10005;</button>`;list.appendChild(el)})}
async function addIps(val){if(!val||!val.trim())return toast('Введите IP',true);const r=await api('add_ips',{ips:val.trim()});if(r.error)return toast(r.error,true);toast(`Добавлено. Всего: ${r.count}`);$('#ipInput').value='';loadIps()}
async function deleteIp(ip){await api('delete_ip',{ip});toast('Удалено');loadIps()}

// GitHub / V2fly Lists
let v2flyTimer=null;
function v2flyDoSearch(){
  clearTimeout(v2flyTimer);
  const q=$('#v2flySearch').value.trim();
  if(q.length<1){$('#v2flyResults').innerHTML='<div style="text-align:center;color:var(--text2);padding:12px;font-size:12px">Введите минимум 1 символ</div>';return}
  v2flyTimer=setTimeout(async()=>{
    const r=await api('v2fly_search',`q=${encodeURIComponent(q)}`);
    const box=$('#v2flyResults');
    if(!r.results||!r.results.length){box.innerHTML='<div style="text-align:center;color:var(--text2);padding:12px">Ничего не найдено</div>';return}
    const added=new Set(r.added||[]);
    box.innerHTML=r.results.map(name=>{
      const isAdded=added.has(name);
      return `<div class="list-item" style="${isAdded?'opacity:.5':''}"><div class="info"><div class="name" style="font-family:monospace">${esc(name)}</div></div>${isAdded?'<span style="color:var(--green);font-size:11px">добавлен</span>':'<button class="btn btn-primary btn-sm" onclick="v2flyAdd(\''+esc(name)+'\')">Добавить</button>'}</div>`
    }).join('');
  },300);
}
async function v2flyAdd(name){
  toast('Загрузка '+name+'...');
  const r=await api('v2fly_add',{name});
  if(r.error)return toast(r.error,true);
  toast(`${name}: +${r.count} доменов`);
  v2flyDoSearch();loadGhLists();loadDomains();
}
async function v2flyRefreshAll(){
  $('#v2flySpinner').style.display='inline-block';
  const lists=await api('github_lists','');
  if(!Array.isArray(lists)){$('#v2flySpinner').style.display='none';return}
  let total=0;
  for(const l of lists){
    if(l.source==='v2fly'&&l.name){
      const r=await api('v2fly_refresh',{name:l.name});
      if(r.ok)total+=r.new||0;
    } else if(l.url){
      // URL-based list
    }
  }
  const r2=await api('update_github_lists');
  if(r2.ok)total+=(r2.new_domains||0);
  $('#v2flySpinner').style.display='none';
  toast(`Обновлено: +${total} новых доменов`);
  loadGhLists();loadDomains();
}
async function loadGhLists(){
  const lists=await api('github_lists','');
  const list=$('#ghList');list.innerHTML='';
  if(!Array.isArray(lists)||!lists.length){list.innerHTML='<div style="text-align:center;color:var(--text2);padding:16px">Нет списков. Добавьте URL с GitHub.</div>';return}
  lists.forEach(l=>{
    const el=document.createElement('div');el.className='list-item';
    el.innerHTML=`<div class="toggle ${l.enabled?'on':''}" onclick="toggleGhList('${l.id}')"></div><div class="info"><div class="name">${esc(l.name)}${l.source==='v2fly'?'<span class="badge" style="background:rgba(99,102,241,.15);color:var(--accent2)">v2fly</span>':''}<span class="badge" style="background:rgba(6,182,212,.2);color:var(--cyan)">${l.count||0} доменов</span></div><div class="meta">${l.url?esc(l.url):'v2fly/'+esc(l.name)} ${l.updated?'• '+esc(l.updated):''}</div></div><div class="actions">${l.source==='v2fly'?`<button class="btn btn-ghost btn-sm" onclick="v2flyRefresh('${esc(l.name)}')" title="Обновить"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg></button>`:''}<button class="btn btn-danger btn-icon" onclick="deleteGhList('${l.id}')" title="Удалить">&#10005;</button></div>`;
    list.appendChild(el);
  });
}
async function v2flyRefresh(name){toast('Обновление '+name+'...');const r=await api('v2fly_refresh',{name});if(r.error)return toast(r.error,true);toast(`${name}: +${r.new} новых`);loadGhLists();loadDomains()}
async function deleteGhList(id){if(!confirm('Удалить список?'))return;await api('delete_github_list',{id});toast('Удалено');loadGhLists()}
async function toggleGhList(id){await api('toggle_github_list',{id});loadGhLists()}

// Devices
async function loadDevices(){
  const[vpn,lan]=await Promise.all([api('devices',''),api('lan_devices','')]);
  const fullList=$('#fullvpnList');fullList.innerHTML='';
  if(Array.isArray(vpn)&&vpn.length){vpn.forEach(d=>{const el=document.createElement('div');el.className='list-item';el.innerHTML=`<div class="info"><div class="name">${esc(d.hostname||d.mac)}</div><div class="meta">${esc(d.mac)} ${d.ip?'• '+d.ip:''}</div></div><button class="btn btn-danger btn-icon btn-sm" onclick="deleteDevice('${esc(d.mac)}')" title="Удалить">&#10005;</button>`;fullList.appendChild(el)})}
  else fullList.innerHTML='<div style="text-align:center;color:var(--text2);padding:10px;font-size:12px">Нет устройств с полным VPN</div>';
  const vpnMacs=new Set((vpn||[]).map(d=>d.mac.toUpperCase()));
  const lanList=$('#lanList');lanList.innerHTML='';
  if(Array.isArray(lan)&&lan.length){lan.forEach(d=>{if(vpnMacs.has(d.mac.toUpperCase()))return;const el=document.createElement('div');el.className='list-item';el.innerHTML=`<div class="info"><div class="name">${esc(d.hostname||d.mac)}</div><div class="meta">${esc(d.mac)} • ${esc(d.ip)}${d.active?' • <span class="green">online</span>':''}</div></div><button class="btn btn-success btn-icon btn-sm" onclick="addDeviceByMac('${esc(d.mac)}')" title="Добавить в VPN">+</button>`;lanList.appendChild(el)})}
  else lanList.innerHTML='<div style="text-align:center;color:var(--text2);padding:10px;font-size:12px">Не удалось получить список. Возможно Keenetic API недоступен.</div>';
}
async function addDevice(){const mac=$('#macInput').value.trim().toUpperCase();if(!mac)return toast('Введите MAC',true);const r=await api('add_device',{mac});if(r.error)return toast(r.error,true);$('#macInput').value='';toast('Добавлено');loadDevices()}
async function addDeviceByMac(mac){await api('add_device',{mac});toast('Добавлено');loadDevices()}
async function deleteDevice(mac){await api('delete_device',{mac});toast('Удалено');loadDevices()}

// WireGuard
async function loadWgPeers(){
  const peers=await api('wg_peers','');
  const list=$('#wgPeerList');list.innerHTML='';
  if(!Array.isArray(peers)||!peers.length){list.innerHTML='<div style="text-align:center;color:var(--text2);padding:16px">Нет клиентов WireGuard</div>';return}
  peers.forEach(p=>{
    const el=document.createElement('div');el.className='list-item';
    const online=p.last_handshake&&!p.last_handshake.includes('None');
    el.innerHTML=`<div style="width:8px;height:8px;border-radius:50%;background:${online?'var(--green)':'var(--border)'};flex-shrink:0" title="${online?'Online':'Offline'}"></div><div class="info"><div class="name">${esc(p.name||'Unknown')}<span class="badge" style="background:rgba(99,102,241,.15);color:var(--accent2)">${esc(p.ip)}</span></div><div class="meta">${p.last_handshake?'Handshake: '+esc(p.last_handshake):'Нет подключений'}${p.rx?' • ↓'+esc(p.rx)+' ↑'+esc(p.tx):''}</div></div><div class="actions">${p.has_config?`<button class="btn btn-ghost btn-sm" onclick="showWgConfig('${esc(p.name)}')" title="Конфиг"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>`:''}<button class="btn btn-danger btn-icon btn-sm" onclick="wgDeletePeer('${esc(p.name||p.pubkey)}')" title="Удалить">&#10005;</button></div>`;
    list.appendChild(el);
  });
}
async function wgAddPeer(){const name=$('#wgName').value.trim();if(!name)return toast('Введите имя',true);const r=await api('wg_add_peer',{name});if(r.error)return toast(r.error,true);$('#wgName').value='';toast(`Добавлен: ${r.name} (${r.ip})`);loadWgPeers()}
async function wgDeletePeer(name){if(!confirm(`Удалить клиент ${name}?`))return;await api('wg_delete_peer',{name});toast('Удалено');loadWgPeers()}
async function wgRestart(){toast('Перезапуск WireGuard...');await api('wg_restart');toast('WG перезапущен');setTimeout(loadStatus,3000)}

async function showWgConfig(name){
  currentWgName=name;
  const r=await api('wg_get_config',`name=${encodeURIComponent(name)}`);
  if(r.error)return toast(r.error,true);
  $('#wgModalTitle').textContent=name;
  $('#wgModalConfig').textContent=r.config;
  $('#wgModalQr').style.display='none';
  $('#wgModal').classList.add('show');
}

function closeModal(){$('#wgModal').classList.remove('show')}

function copyConfig(){
  const text=$('#wgModalConfig').textContent;
  navigator.clipboard.writeText(text).then(()=>toast('Скопировано')).catch(()=>{
    const ta=document.createElement('textarea');ta.value=text;document.body.appendChild(ta);ta.select();document.execCommand('copy');document.body.removeChild(ta);toast('Скопировано');
  });
}

async function showQr(){
  const r=await api('wg_qrcode',`name=${encodeURIComponent(currentWgName)}`);
  if(r.error)return toast(r.error,true);
  const qrBox=$('#wgModalQr');
  qrBox.textContent=r.qr;
  qrBox.style.display=qrBox.style.display==='none'?'block':'none';
}

$('#wgModal').addEventListener('click',e=>{if(e.target===e.currentTarget)closeModal()});

// Logs
async function loadLogs(type){if(type)currentLogType=type;const lines=$('#logLines').value;const data=await api('logs',`type=${currentLogType}&lines=${lines}`);const view=$('#logView');view.textContent=Array.isArray(data)?data.join('\n')||'(пусто)':JSON.stringify(data);view.scrollTop=view.scrollHeight}
async function clearLogs(){await api('clear_logs');toast('Очищено');loadLogs()}

function esc(s){if(!s)return'';const d=document.createElement('div');d.textContent=String(s);return d.innerHTML}

// Init
loadStatus();loadServers();
setInterval(loadStatus,30000);
</script>
</body>
</html>
