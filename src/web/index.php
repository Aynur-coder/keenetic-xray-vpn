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
:root[data-theme="light"] {
  --bg: #f5f7fb;
  --card: #ffffff;
  --card2: #f0f2f8;
  --border: #e1e5ee;
  --accent: #4f46e5;
  --accent2: #6366f1;
  --green: #059669;
  --red: #dc2626;
  --orange: #d97706;
  --cyan: #0891b2;
  --text: #1e293b;
  --text2: #64748b;
  --shadow: 0 4px 24px rgba(15,23,42,.08);
}
*{margin:0;padding:0;box-sizing:border-box}
:focus{outline:none}
:focus-visible{outline:2px solid var(--accent);outline-offset:2px;border-radius:6px}
.btn:focus-visible{outline-offset:3px}
input:focus-visible,textarea:focus-visible,select:focus-visible{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(99,102,241,.18)}
/* Reduced motion: drop non-essential animations */
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms !important;transition-duration:.01ms !important;animation-iteration-count:1 !important}}

/* Live status dot in header */
.live-dot{display:inline-block;width:9px;height:9px;border-radius:50%;background:var(--text2);margin-left:8px;vertical-align:middle;box-shadow:0 0 0 0 rgba(16,185,129,0);transition:background .2s}
.live-dot.on{background:var(--green);animation:livePulse 2s infinite}
.live-dot.off{background:var(--red)}
@keyframes livePulse{0%{box-shadow:0 0 0 0 rgba(16,185,129,.5)}70%{box-shadow:0 0 0 8px rgba(16,185,129,0)}100%{box-shadow:0 0 0 0 rgba(16,185,129,0)}}
body{font-family:-apple-system,'SF Pro Display','Inter','Segoe UI',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.app{max-width:1120px;margin:0 auto;padding:20px}

/* Header */
.header{display:flex;align-items:center;justify-content:space-between;padding:20px 24px;margin-bottom:24px;background:linear-gradient(135deg,#4f46e5 0%,#7c3aed 100%);border-radius:16px;box-shadow:var(--shadow)}
.header h1{font-size:20px;font-weight:700;color:#fff;display:flex;align-items:center;gap:10px}
.header-controls{display:flex;gap:8px}
.header .btn-ghost{border-color:rgba(255,255,255,.3);color:rgba(255,255,255,.9);background:rgba(255,255,255,.08)}
.header .btn-ghost:hover{border-color:rgba(255,255,255,.6);color:#fff;background:rgba(255,255,255,.18)}
.header .btn-success{background:rgba(16,185,129,.8);border-color:transparent}
.header .btn-success:hover{background:rgba(16,185,129,1)}
.header .btn-danger{background:rgba(239,68,68,.8);border-color:transparent}
.header .btn-danger:hover{background:rgba(239,68,68,1)}

/* Status bar */
.status-bar{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:24px}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:16px;transition:transform .15s}
.stat-card:hover{transform:translateY(-2px)}
.stat-label{font-size:11px;text-transform:uppercase;letter-spacing:1px;color:var(--text2);margin-bottom:6px;display:flex;align-items:center;gap:6px}
.stat-label svg{width:14px;height:14px;opacity:.6}
.stat-value{font-size:18px;font-weight:700;word-break:break-all;min-height:22px;display:flex;align-items:center}
.stat-value.loading::after{content:'';display:block;width:70%;height:16px;border-radius:6px;background:linear-gradient(90deg,var(--card2) 0%,var(--card) 50%,var(--card2) 100%);background-size:200% 100%;animation:shimmer 1.4s infinite}
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
/* Toast container & toasts */
.toast-container{position:fixed;bottom:20px;right:20px;display:flex;flex-direction:column-reverse;gap:8px;z-index:2000;max-width:calc(100vw - 40px);pointer-events:none}
.toast{display:flex;align-items:flex-start;gap:10px;background:var(--card);border:1px solid var(--border);color:var(--text);padding:12px 16px;border-radius:12px;font-size:13px;font-weight:500;min-width:260px;max-width:360px;box-shadow:0 8px 32px rgba(0,0,0,.4);transform:translateX(120%);opacity:0;transition:transform .25s ease-out,opacity .25s;pointer-events:auto;cursor:default}
.toast.show{transform:translateX(0);opacity:1}
.toast.hide{transform:translateX(120%);opacity:0}
.toast .icon{flex-shrink:0;margin-top:1px;display:flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;font-size:11px;color:#fff;font-weight:700}
.toast .msg{flex:1;line-height:1.4;word-break:break-word}
.toast .close{background:none;border:none;color:var(--text2);cursor:pointer;font-size:18px;line-height:1;padding:0 4px;margin:-4px -4px -4px 0;opacity:.5;transition:opacity .15s}
.toast .close:hover{opacity:1}
.toast.t-success{border-color:var(--green)} .toast.t-success .icon{background:var(--green)}
.toast.t-error{border-color:var(--red)}     .toast.t-error .icon{background:var(--red)}
.toast.t-warn{border-color:var(--orange)}   .toast.t-warn .icon{background:var(--orange);color:#111}
.toast.t-info{border-color:var(--accent)}   .toast.t-info .icon{background:var(--accent)}

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
/* iOS Safari: 16px+ input prevents auto-zoom on focus */
@media(max-width:640px){
  input[type="text"],input[type="url"],input[type="password"],input[type="number"],textarea,select{font-size:16px !important}
}

@media(max-width:640px){
  .app{padding:10px}
  .header{flex-direction:column;gap:12px;text-align:center;padding:16px}
  .header h1{font-size:18px}
  .header-controls{flex-wrap:wrap;justify-content:center}
  .status-bar{grid-template-columns:1fr 1fr;gap:8px}
  .stat-card{padding:12px}
  .stat-value{font-size:15px}
  .tabs{flex-wrap:nowrap;overflow-x:auto;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch;scrollbar-width:none}
  .tabs::-webkit-scrollbar{display:none}
  .tab{scroll-snap-align:start;flex-shrink:0;min-height:40px}
  .input-group{flex-direction:column}
  input.flex1{min-width:auto;width:100%}
  .btn{min-height:40px;font-size:14px}
  .panel{padding:16px;border-radius:12px}
  .toast-container{bottom:12px;right:12px;left:12px;max-width:none}
  .toast{min-width:0;max-width:none;width:100%}
}

@media(max-width:480px){
  .status-bar{grid-template-columns:1fr}
}

@media(max-width:600px){
  .overlay{padding:0;align-items:stretch}
  .overlay-card{border-radius:0;max-width:100%;min-height:100vh;padding:24px 18px}
  .settings-card{max-height:100vh}
}

/* ============ Overlay (login + wizard) ============ */
.overlay{position:fixed;inset:0;background:rgba(15,17,23,.85);backdrop-filter:blur(8px);display:none;align-items:center;justify-content:center;z-index:1000;padding:20px;overflow-y:auto}
.overlay.show{display:flex}
.overlay-card{background:var(--card);border:1px solid var(--border);border-radius:20px;max-width:480px;width:100%;padding:32px;box-shadow:0 24px 64px rgba(0,0,0,.5);animation:cardIn .25s ease-out}
@keyframes cardIn{from{opacity:0;transform:translateY(12px) scale(.97)}to{opacity:1;transform:none}}
.overlay-card h2{font-size:22px;margin-bottom:8px;display:flex;align-items:center;gap:10px}
.overlay-card .lead{color:var(--text2);font-size:13px;margin-bottom:24px;line-height:1.5}
.overlay-card label{display:block;font-size:12px;color:var(--text2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
.overlay-card input[type=text],.overlay-card input[type=password],.overlay-card input[type=url]{width:100%;font-size:16px;padding:12px 14px;margin-bottom:16px}
.overlay-card .btn-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
.overlay-card .btn-row .btn{flex:1;justify-content:center}
.overlay-error{color:var(--red);font-size:12px;margin-top:-8px;margin-bottom:12px;min-height:16px}
.overlay-info{color:var(--text2);font-size:12px;margin-top:8px}

/* Wizard progress dots */
.wizard-progress{display:flex;gap:6px;margin-bottom:24px;justify-content:center}
.wizard-progress .dot{width:8px;height:8px;border-radius:50%;background:var(--border);transition:all .2s}
.wizard-progress .dot.done{background:var(--green)}
.wizard-progress .dot.current{background:var(--accent);transform:scale(1.4)}

.wizard-step{display:none}
.wizard-step.active{display:block}
.wizard-step .step-num{font-size:11px;color:var(--accent2);text-transform:uppercase;letter-spacing:1px;font-weight:600}

.wizard-server-list{max-height:300px;overflow-y:auto;border:1px solid var(--border);border-radius:10px;padding:6px;background:var(--bg)}
.wizard-server-list .skel{height:52px;background:linear-gradient(90deg,var(--card2) 0%,var(--card) 50%,var(--card2) 100%);background-size:200% 100%;animation:shimmer 1.5s infinite;border-radius:8px;margin-bottom:4px}
.wizard-server-list .radio-item{margin-bottom:4px;padding:10px 14px}
.wizard-server-list .radio-item .name{font-size:14px;font-weight:600}
.wizard-server-list .radio-item .meta{font-size:12px;color:var(--text2);margin-top:2px}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}

.wizard-toggle{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;background:var(--card2);border:1px solid var(--border);border-radius:10px;margin-bottom:16px}
.wizard-toggle .label{font-weight:600;font-size:14px}
.wizard-toggle .desc{font-size:12px;color:var(--text2);margin-top:2px}
.tog{position:relative;width:42px;height:24px;border-radius:12px;background:var(--border);cursor:pointer;transition:background .2s;flex-shrink:0}
.tog::after{content:'';position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;transition:transform .2s}
.tog.on{background:var(--green)}
.tog.on::after{transform:translateX(18px)}

@media(max-width:640px){
  .overlay-card{padding:24px 20px;border-radius:16px}
}

/* ============ Update bell + modal ============ */
#btnUpdate{position:relative}
.update-badge{position:absolute;top:-2px;right:-2px;background:var(--red);color:#fff;font-size:9px;font-weight:700;border-radius:8px;min-width:16px;height:16px;display:inline-flex;align-items:center;justify-content:center;padding:0 4px;border:2px solid #4f46e5}
.update-modal-card{max-width:560px}
.update-version-row{display:flex;align-items:center;gap:12px;padding:14px 16px;background:var(--card2);border-radius:10px;margin-bottom:16px;border:1px solid var(--border)}
.update-version-row .from{color:var(--text2);text-decoration:line-through}
.update-version-row .arrow{color:var(--accent2)}
.update-version-row .to{color:var(--green);font-weight:700;font-size:16px}
.update-changelog{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:14px 16px;max-height:240px;overflow-y:auto;font-size:13px;line-height:1.6;white-space:pre-wrap;color:var(--text);margin-bottom:16px;font-family:monospace}
.update-progress{margin-top:16px}
.update-progress-bar{width:100%;height:6px;background:var(--border);border-radius:3px;overflow:hidden;margin-bottom:10px}
.update-progress-bar .fill{height:100%;background:linear-gradient(90deg,var(--accent),var(--accent2));width:0;transition:width .4s ease;border-radius:3px}
.update-progress-bar.indeterminate .fill{width:30%;animation:updateProgressIndet 1.4s infinite ease-in-out}
@keyframes updateProgressIndet{0%{margin-left:-30%}100%{margin-left:100%}}
.update-progress-msg{font-size:13px;color:var(--text)}
.update-log{margin-top:12px;font-family:monospace;font-size:11px;color:var(--text2);background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:10px;max-height:140px;overflow-y:auto;white-space:pre-wrap}

/* ============ Settings modal ============ */
.settings-card{max-width:560px;max-height:90vh;overflow-y:auto}
.settings-section{margin-bottom:24px}
.settings-section h3{font-size:11px;text-transform:uppercase;letter-spacing:1.2px;color:var(--text2);margin-bottom:10px;padding-bottom:8px;border-bottom:1px solid var(--border);font-weight:700}
.settings-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 0;border-bottom:1px solid var(--border)}
.settings-row:last-child{border-bottom:none}
.settings-row .label{font-weight:600;font-size:14px}
.settings-row .desc{font-size:12px;color:var(--text2);margin-top:2px}
.settings-row .meta{font-size:11px;color:var(--text2);font-family:monospace}
.settings-row .actions{display:flex;gap:6px;flex-shrink:0}
.danger-zone{border:1px solid var(--red);border-radius:10px;padding:14px;background:rgba(239,68,68,.05);margin-top:12px}
.danger-zone h3{color:var(--red);border:none}
</style>
</head>
<body>

<!-- ============ LOGIN OVERLAY ============ -->
<div class="overlay" id="loginOverlay" role="dialog" aria-modal="true">
  <div class="overlay-card">
    <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Вход в Xray VPN</h2>
    <p class="lead">Удалённый доступ требует пароль. Если ты в локальной сети роутера — обнови страницу, авторизация не нужна.</p>
    <label for="loginPass">Пароль</label>
    <input type="password" id="loginPass" autocomplete="current-password" placeholder="••••••••">
    <div class="overlay-error" id="loginError">&nbsp;</div>
    <div class="btn-row">
      <button class="btn btn-primary" id="btnLogin">Войти</button>
    </div>
  </div>
</div>

<!-- ============ WIZARD OVERLAY ============ -->
<div class="overlay" id="wizardOverlay" role="dialog" aria-modal="true">
  <div class="overlay-card">
    <div class="wizard-progress" id="wizardDots">
      <span class="dot current"></span><span class="dot"></span><span class="dot"></span><span class="dot"></span><span class="dot"></span>
    </div>

    <!-- Step 1: UI password -->
    <div class="wizard-step active" data-step="1">
      <div class="step-num">Шаг 1 из 5</div>
      <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Пароль веб-интерфейса</h2>
      <p class="lead">Понадобится, если ты будешь открывать UI извне локальной сети (например, через WireGuard). Минимум 4 символа.</p>
      <label for="w1Pass">Новый пароль</label>
      <input type="password" id="w1Pass" placeholder="Минимум 4 символа" autocomplete="new-password">
      <label for="w1Pass2">Повтори</label>
      <input type="password" id="w1Pass2" placeholder="Повтори пароль" autocomplete="new-password">
      <div class="overlay-error" id="w1Error">&nbsp;</div>
      <div class="btn-row">
        <button class="btn btn-ghost" data-skip="1">Пропустить</button>
        <button class="btn btn-primary" data-next="1">Далее</button>
      </div>
    </div>

    <!-- Step 2: Keenetic admin password -->
    <div class="wizard-step" data-step="2">
      <div class="step-num">Шаг 2 из 5</div>
      <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg> Пароль Keenetic</h2>
      <p class="lead">Чтобы показывать список устройств в локальной сети (для маршрутизации по MAC), нужен пароль admin от роутера. Это тот же пароль, которым ты входишь на <code>http://192.168.1.1</code>.</p>
      <label for="w2Pass">Пароль admin</label>
      <input type="password" id="w2Pass" placeholder="Пароль роутера">
      <div class="overlay-error" id="w2Error">&nbsp;</div>
      <div class="overlay-info" id="w2Info">&nbsp;</div>
      <div class="btn-row">
        <button class="btn btn-ghost" data-skip="2">Пропустить</button>
        <button class="btn btn-ghost" id="w2Test">Проверить</button>
        <button class="btn btn-primary" data-next="2" disabled id="w2Next">Далее</button>
      </div>
    </div>

    <!-- Step 3: First subscription -->
    <div class="wizard-step" data-step="3">
      <div class="step-num">Шаг 3 из 5</div>
      <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> Первая подписка</h2>
      <p class="lead">Вставь URL подписки на VPN-серверы (формат VLESS/SS). Сервера загрузятся автоматически. Если у тебя нет подписки — пропусти, ключи можно добавить вручную позже.</p>
      <label for="w3Name">Название</label>
      <input type="text" id="w3Name" placeholder="Например, MyVPN">
      <label for="w3Url">URL подписки</label>
      <input type="url" id="w3Url" placeholder="https://...">
      <div class="overlay-error" id="w3Error">&nbsp;</div>
      <div class="overlay-info" id="w3Info">&nbsp;</div>
      <div class="btn-row">
        <button class="btn btn-ghost" data-skip="3">Пропустить</button>
        <button class="btn btn-primary" data-next="3" id="w3Next">Загрузить</button>
      </div>
    </div>

    <!-- Step 4: Pick server -->
    <div class="wizard-step" data-step="4">
      <div class="step-num">Шаг 4 из 5</div>
      <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg> Выбери сервер</h2>
      <p class="lead">Выбери один сервер. Его можно поменять в любой момент во вкладке «Серверы».</p>
      <div class="wizard-server-list" id="w4Servers">
        <div class="skel"></div><div class="skel"></div><div class="skel"></div><div class="skel"></div>
      </div>
      <div class="overlay-error" id="w4Error">&nbsp;</div>
      <div class="btn-row">
        <button class="btn btn-ghost" data-skip="4">Пропустить</button>
        <button class="btn btn-primary" data-next="4" id="w4Next" disabled>Применить и запустить</button>
      </div>
    </div>

    <!-- Step 5: WireGuard -->
    <div class="wizard-step" data-step="5">
      <div class="step-num">Шаг 5 из 5</div>
      <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><path d="M5 12.55a11 11 0 0 1 14.08 0M1.42 9a16 16 0 0 1 21.16 0M8.53 16.11a6 6 0 0 1 6.95 0M12 20h.01"/></svg> WireGuard сервер</h2>
      <p class="lead">Включает встроенный WireGuard-сервер на роутере. Полезно если хочешь подключаться к домашней сети с телефона/ноутбука. Можно включить позже в настройках.</p>
      <div class="wizard-toggle">
        <div>
          <div class="label">Использовать WireGuard</div>
          <div class="desc">Слушать порт 500, сеть 10.50.0.0/24</div>
        </div>
        <div class="tog on" id="w5Tog"></div>
      </div>
      <div class="btn-row">
        <button class="btn btn-success" data-next="5" id="w5Finish">Завершить настройку</button>
      </div>
    </div>
  </div>
</div>

<!-- ============ UPDATE MODAL ============ -->
<div class="overlay" id="updateOverlay" role="dialog" aria-modal="true">
  <div class="overlay-card update-modal-card">
    <h2 id="updateTitle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg> Обновление</h2>
    <div id="updateBody">
      <div class="update-version-row">
        <span class="from" id="updateFrom">—</span>
        <span class="arrow">→</span>
        <span class="to" id="updateTo">—</span>
      </div>
      <div style="font-size:11px;color:var(--text2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">Что нового</div>
      <div class="update-changelog" id="updateChangelog">Загружаю…</div>
    </div>
    <div id="updateProgress" class="update-progress" hidden>
      <div class="update-progress-bar indeterminate"><div class="fill"></div></div>
      <div class="update-progress-msg" id="updateProgressMsg">Запускаю…</div>
      <div class="update-log" id="updateProgressLog"></div>
    </div>
    <div class="btn-row" id="updateButtons">
      <button class="btn btn-ghost" onclick="closeUpdate()">Закрыть</button>
      <button class="btn btn-ghost" onclick="checkUpdate(true)">Проверить</button>
      <button class="btn btn-primary" id="updateApplyBtn" onclick="applyUpdate()" hidden>Применить</button>
    </div>
  </div>
</div>

<!-- ============ SETTINGS MODAL ============ -->
<div class="overlay" id="settingsOverlay" role="dialog" aria-modal="true">
  <div class="overlay-card settings-card">
    <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg> Настройки</h2>

    <div class="settings-section">
      <h3>Общие</h3>
      <div class="settings-row">
        <div>
          <div class="label">Версия</div>
          <div class="meta" id="setVersion">—</div>
        </div>
        <div class="actions">
          <button class="btn btn-ghost btn-sm" onclick="closeSettings();openUpdate()">Проверить обновления</button>
        </div>
      </div>
      <div class="settings-row">
        <div>
          <div class="label">Автообновление</div>
          <div class="desc">Cron проверяет GitHub раз в день в 04:30 и ставит свежую версию.</div>
        </div>
        <div class="tog" id="togAutoUpdate"></div>
      </div>
    </div>

    <div class="settings-section">
      <h3>Функции</h3>
      <div class="settings-row">
        <div>
          <div class="label">WireGuard</div>
          <div class="desc">Сервер для VPN-клиентов (телефон, ноутбук). 10.50.0.0/24.</div>
        </div>
        <div class="tog" id="togWireguard"></div>
      </div>
      <div class="settings-row">
        <div>
          <div class="label">AdGuard Home</div>
          <div class="desc">DNS-маршрутизация: какие домены идут через VPN.</div>
        </div>
        <div class="tog" id="togAdguard"></div>
      </div>
      <div class="settings-row">
        <div>
          <div class="label">Тема</div>
          <div class="desc">Тёмная, светлая или авто (по системе).</div>
        </div>
        <div class="actions">
          <select id="themeSelect" style="font-size:13px">
            <option value="auto">Авто</option>
            <option value="dark">Тёмная</option>
            <option value="light">Светлая</option>
          </select>
        </div>
      </div>
    </div>

    <div class="settings-section">
      <h3>Безопасность</h3>
      <div class="settings-row">
        <div>
          <div class="label">Пароль веб-интерфейса</div>
          <div class="desc">Используется при удалённом доступе. Локалка без пароля.</div>
        </div>
        <div class="actions">
          <button class="btn btn-ghost btn-sm" onclick="changeUiPassword()">Сменить</button>
        </div>
      </div>
      <div class="settings-row">
        <div>
          <div class="label">Пароль admin Keenetic</div>
          <div class="desc" id="setKnPassStatus">—</div>
        </div>
        <div class="actions">
          <button class="btn btn-ghost btn-sm" onclick="resetKnPassword()">Перезаписать</button>
        </div>
      </div>
    </div>

    <div class="settings-section">
      <h3>Опасная зона</h3>
      <div class="danger-zone">
        <div class="settings-row">
          <div>
            <div class="label">Откатить обновление</div>
            <div class="desc">Восстановить предыдущую версию из последнего бэкапа.</div>
          </div>
          <button class="btn btn-warn btn-sm" onclick="rollbackUpdate()">Откатить</button>
        </div>
        <div class="settings-row">
          <div>
            <div class="label">Перезапустить мастер</div>
            <div class="desc">Данные (ключи, подписки, домены) <strong>не удаляются</strong>. Удаляется только метка завершения — снова покажет 5 шагов.</div>
          </div>
          <button class="btn btn-warn btn-sm" onclick="restartWizard()">Запустить мастер</button>
        </div>
      </div>
    </div>

    <div class="btn-row" style="margin-top:16px">
      <button class="btn btn-ghost" onclick="closeSettings()">Закрыть</button>
      <button class="btn btn-danger btn-sm" onclick="doLogout()" id="btnLogoutSettings" hidden>Выйти</button>
    </div>
  </div>
</div>

<div class="app">

<div class="header">
  <h1>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
    Xray VPN Manager
    <span class="live-dot" id="liveDot" title="Состояние службы" aria-label="Состояние службы"></span>
  </h1>
  <div class="header-controls">
    <button class="btn btn-ghost btn-icon" id="btnUpdate" onclick="openUpdate()" title="Обновления" aria-label="Обновления">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
      <span class="update-badge" id="updateBadge" hidden></span>
    </button>
    <button class="btn btn-ghost btn-icon" onclick="openSettings()" title="Настройки" aria-label="Настройки">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
    </button>
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
    <div class="stat-value loading" id="stStatus"></div>
  </div>
  <div class="stat-card">
    <div class="stat-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> VPN IP</div>
    <div class="stat-value loading" id="stVpnIp"></div>
  </div>
  <div class="stat-card">
    <div class="stat-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9"/></svg> Реальный IP</div>
    <div class="stat-value loading" id="stRealIp"></div>
  </div>
  <div class="stat-card">
    <div class="stat-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg> Память</div>
    <div class="stat-value loading" id="stMem"></div>
  </div>
  <div class="stat-card">
    <div class="stat-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> WireGuard</div>
    <div class="stat-value loading" id="stWg"></div>
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
  <button class="tab" data-tab="wireguard" data-feature="wireguard"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg> WireGuard</button>
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
  <p class="panel-desc">
    Устройства добавляются по <strong>MAC-адресу</strong> — он стабильный даже при смене IP.
    IP определяется автоматически из Keenetic и используется для маршрутизации внутри Xray.
    Если устройство было offline при последнем запуске Xray — <strong>перезапусти Xray</strong> когда оно онлайн.
  </p>
  <div class="input-group">
    <input type="text" class="flex1" id="macInput" placeholder="MAC-адрес (AA:BB:CC:DD:EE:FF)">
    <button class="btn btn-primary" onclick="addDevice()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Добавить</button>
  </div>
  <div id="fullvpnNoIpWarn" style="display:none;padding:10px 14px;background:rgba(245,158,11,.1);border:1px solid var(--orange);border-radius:8px;font-size:12px;color:var(--orange);margin-bottom:12px">
    <strong>⚠ IP не определён для одного или нескольких устройств.</strong>
    Убедись что устройство онлайн, затем перезапусти Xray — маршрутизация применится.
    <button class="btn btn-warn btn-sm" style="margin-left:8px;margin-top:4px" onclick="doAction('restart')">Перезапустить Xray</button>
  </div>
  <div style="margin-bottom:12px">
    <div class="panel-title" style="font-size:13px">Устройства с полным VPN:</div>
    <div id="fullvpnList" class="list" style="margin-bottom:12px"></div>
  </div>
  <div class="section-divider"></div>
  <div>
    <div class="panel-title" style="font-size:13px">Все устройства в сети (из Keenetic):</div>
    <p style="font-size:12px;color:var(--text2);margin-bottom:8px">Нажми «+» чтобы включить полный VPN для устройства.</p>
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

<div class="toast-container" id="toasts" role="region" aria-live="polite" aria-label="Уведомления"></div>

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

function toast(msg, typeOrErr='info', opts={}){
  // Back-compat: toast(msg, true) → error
  let type = typeOrErr;
  if(typeof typeOrErr==='boolean') type = typeOrErr ? 'error' : 'success';
  if(!['success','error','warn','info'].includes(type)) type='info';
  const container=$('#toasts');
  if(!container) return;
  const el=document.createElement('div');
  el.className='toast t-'+type;
  el.setAttribute('role', type==='error' ? 'alert' : 'status');
  const icons={success:'✓', error:'!', warn:'!', info:'i'};
  el.innerHTML = `<div class="icon" aria-hidden="true">${icons[type]}</div><div class="msg"></div><button class="close" aria-label="Закрыть">×</button>`;
  el.querySelector('.msg').textContent = String(msg);
  container.appendChild(el);
  requestAnimationFrame(()=>el.classList.add('show'));

  const ttl = opts.ttl || (type==='error' ? 6000 : 4000);
  let timer = setTimeout(dismiss, ttl);
  function dismiss(){
    clearTimeout(timer);
    el.classList.remove('show');
    el.classList.add('hide');
    setTimeout(()=>el.remove(), 250);
  }
  el.querySelector('.close').addEventListener('click', dismiss);
  el.addEventListener('mouseenter', ()=>clearTimeout(timer));
  el.addEventListener('mouseleave', ()=>{ timer = setTimeout(dismiss, 2500); });

  // Swipe-to-dismiss on touch devices
  let startX=null;
  el.addEventListener('touchstart', e=>{ startX = e.touches[0].clientX; }, {passive:true});
  el.addEventListener('touchmove', e=>{
    if(startX===null) return;
    const dx = e.touches[0].clientX - startX;
    if(dx>0){ el.style.transform = `translateX(${dx}px)`; el.style.opacity = String(Math.max(0, 1 - dx/200)); }
  }, {passive:true});
  el.addEventListener('touchend', e=>{
    if(startX===null) return;
    const dx = (e.changedTouches[0]?.clientX ?? startX) - startX;
    if(dx>80) dismiss();
    else { el.style.transform=''; el.style.opacity=''; }
    startX = null;
  });
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

// Status — fast path: no IP checks
async function loadStatus(){
  const s=await api('status','');
  if(s.error)return;
  const stEl=$('#stStatus');
  if(s.running){
    stEl.textContent='Работает';stEl.className='stat-value green';
    $('#btnToggleText').textContent='Стоп';$('#btnToggle').className='btn btn-danger';
    $('#liveDot').className='live-dot on';
  }else{
    stEl.textContent='Остановлен';stEl.className='stat-value red';
    $('#btnToggleText').textContent='Старт';$('#btnToggle').className='btn btn-success';
    $('#liveDot').className='live-dot off';
  }
  $('#stMem').textContent=s.mem_used&&s.mem_total?`${s.mem_used}/${s.mem_total} MB`:'—';
  $('#stMem').className='stat-value';
  $('#stWg').textContent=s.wg_up?'Активен':'Выкл';
  $('#stWg').className='stat-value '+(s.wg_up?'green':'red');

  // IPs are slow (curl through VPN) — start async, don't block
  _loadIPs();
}

// IP check — async, called from loadStatus and periodically
let _ipsLoading=false;
async function _loadIPs(){
  if(_ipsLoading)return;
  _ipsLoading=true;
  // Show "проверка..." while waiting, but don't show skeleton
  const vpnEl=$('#stVpnIp'), realEl=$('#stRealIp');
  if(vpnEl.className.includes('loading')){ vpnEl.textContent='…'; vpnEl.className='stat-value'; }
  if(realEl.className.includes('loading')){ realEl.textContent='…'; realEl.className='stat-value'; }
  try{
    const r=await api('check_ips','');
    vpnEl.textContent=r.vpn_ip||'—';
    vpnEl.className='stat-value '+(r.vpn_ip?'green':'red');
    realEl.textContent=r.real_ip||'—';
    realEl.className='stat-value';
  }catch(e){
    vpnEl.textContent='—';vpnEl.className='stat-value red';
    realEl.textContent='—';realEl.className='stat-value';
  }finally{_ipsLoading=false;}
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

  let hasNoIp=false;
  if(Array.isArray(vpn)&&vpn.length){
    vpn.forEach(d=>{
      const hasIp=!!d.ip;
      if(!hasIp) hasNoIp=true;
      const dot=`<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:${hasIp?'var(--green)':'var(--orange)'};flex-shrink:0;margin-right:6px;vertical-align:middle" title="${hasIp?'IP определён: '+d.ip:'IP не найден — устройство offline?'}"></span>`;
      const ipLabel=hasIp?`<span style="color:var(--green)">${esc(d.ip)}</span>`:`<span style="color:var(--orange)">нет IP</span>`;
      const el=document.createElement('div');el.className='list-item';
      el.innerHTML=`<div class="info"><div class="name">${dot}${esc(d.hostname||d.mac)}</div><div class="meta">${esc(d.mac)} • ${ipLabel}</div></div><button class="btn btn-danger btn-icon btn-sm" onclick="deleteDevice('${esc(d.mac)}')" title="Удалить" aria-label="Удалить">&#10005;</button>`;
      fullList.appendChild(el);
    });
  } else {
    fullList.innerHTML='<div style="text-align:center;color:var(--text2);padding:10px;font-size:12px">Нет устройств с полным VPN</div>';
  }

  const warn=$('#fullvpnNoIpWarn');
  if(warn) warn.style.display=(hasNoIp&&Array.isArray(vpn)&&vpn.length)?'block':'none';

  const vpnMacs=new Set((vpn||[]).map(d=>d.mac.toUpperCase()));
  const lanList=$('#lanList');lanList.innerHTML='';
  if(Array.isArray(lan)&&lan.length){
    lan.forEach(d=>{
      if(vpnMacs.has(d.mac.toUpperCase()))return;
      const el=document.createElement('div');el.className='list-item';
      el.innerHTML=`<div class="info"><div class="name">${esc(d.hostname||d.mac)}</div><div class="meta">${esc(d.mac)} • ${esc(d.ip)}${d.active?' • <span class="green">online</span>':''}</div></div><button class="btn btn-success btn-icon btn-sm" onclick="addDeviceByMac('${esc(d.mac)}')" title="Добавить в полный VPN" aria-label="Добавить в VPN">+</button>`;
      lanList.appendChild(el);
    });
  } else {
    lanList.innerHTML='<div style="text-align:center;color:var(--text2);padding:10px;font-size:12px">Не удалось получить список. Возможно Keenetic API недоступен.</div>';
  }
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

// ============ LOGIN ============
function showLogin(){
  $('#loginOverlay').classList.add('show');
  setTimeout(()=>$('#loginPass').focus(),100);
}
function hideLogin(){ $('#loginOverlay').classList.remove('show'); }
async function tryLogin(){
  const pass=$('#loginPass').value;
  const errEl=$('#loginError');
  errEl.textContent=' ';
  if(!pass){ errEl.textContent='Введи пароль'; return; }
  const r=await api('login',{password:pass});
  if(r.ok){ hideLogin(); init(); return; }
  if(r.error==='too_many_attempts'){ errEl.textContent=`Слишком много попыток. Попробуй через ${Math.ceil(r.retry_after/60)} мин`; return; }
  errEl.textContent='Неверный пароль'+(r.remaining!==undefined?` (осталось попыток: ${r.remaining})`:'');
  $('#loginPass').value='';
}
$('#btnLogin').addEventListener('click', tryLogin);
$('#loginPass').addEventListener('keydown', e=>{ if(e.key==='Enter') tryLogin(); });

// ============ WIZARD ============
let wizardStep=1;
let wizardState={wg:true};

function showWizard(){
  $('#wizardOverlay').classList.add('show');
  setWizardStep(1);
}
function hideWizard(){ $('#wizardOverlay').classList.remove('show'); }

function setWizardStep(n){
  wizardStep=n;
  document.querySelectorAll('.wizard-step').forEach(el=>el.classList.toggle('active', +el.dataset.step===n));
  document.querySelectorAll('#wizardDots .dot').forEach((d,i)=>{
    d.classList.remove('done','current');
    if(i+1<n) d.classList.add('done');
    if(i+1===n) d.classList.add('current');
  });
}

function nextStep(){ setWizardStep(wizardStep+1); }

// Step 1: set UI password
document.querySelector('[data-next="1"]').addEventListener('click', async ()=>{
  const p1=$('#w1Pass').value, p2=$('#w1Pass2').value;
  const err=$('#w1Error'); err.textContent=' ';
  if(p1.length<4){ err.textContent='Минимум 4 символа'; return; }
  if(p1!==p2){ err.textContent='Пароли не совпадают'; return; }
  const r=await api('set_ui_password',{password:p1});
  if(r.error){ err.textContent='Ошибка: '+r.error; return; }
  nextStep();
});

// Step 2: test/save Keenetic password
$('#w2Test').addEventListener('click', async ()=>{
  const p=$('#w2Pass').value;
  const err=$('#w2Error'), info=$('#w2Info');
  err.textContent=' '; info.textContent='Проверяю...';
  $('#w2Next').disabled=true;
  if(!p){ err.textContent='Введи пароль'; info.textContent=' '; return; }
  const r=await api('test_kn_password',{password:p});
  if(!r.ok){ err.textContent='Не удалось подключиться к Keenetic. Проверь пароль.'; info.textContent=' '; return; }
  info.textContent=`✓ OK. Найдено устройств: ${r.count}`;
  await api('set_kn_password',{password:p});
  $('#w2Next').disabled=false;
});
document.querySelector('[data-next="2"]').addEventListener('click', ()=>{ nextStep(); loadWizardServers(); });

// Step 3: subscription
$('#w3Next').addEventListener('click', async ()=>{
  const name=$('#w3Name').value.trim() || 'Subscription';
  const url=$('#w3Url').value.trim();
  const err=$('#w3Error'), info=$('#w3Info');
  err.textContent=' ';
  if(!url){ err.textContent='Введи URL подписки'; return; }
  info.textContent='Сохраняю...';
  $('#w3Next').disabled=true;
  let r=await api('add_subscription',{name, url});
  if(r.error){ err.textContent='Ошибка: '+r.error; $('#w3Next').disabled=false; info.textContent=' '; return; }
  info.textContent='Загружаю серверы...';
  r=await api('update_subscriptions');
  $('#w3Next').disabled=false;
  if(r.error){ err.textContent='Ошибка обновления: '+r.error; info.textContent=' '; return; }
  info.textContent=`✓ Готово`;
  setTimeout(()=>{ nextStep(); loadWizardServers(); }, 400);
});

// Step 4: pick server
// Parse vless:// ss:// trojan:// links → {proto, addr, port}
function _parseLinkInfo(link){
  if(!link) return {proto:'',addr:'',port:''};
  const proto=(link.split('://')[0]||'').toUpperCase();
  const rest=(link.split('://')[1]||'').split('#')[0];
  const atIdx=rest.lastIndexOf('@');
  if(atIdx<0) return {proto,addr:'',port:''};
  const hostPart=rest.slice(atIdx+1).split('?')[0];
  const lc=hostPart.lastIndexOf(':');
  const addr=lc>0?hostPart.slice(0,lc).replace(/\/+$/,''):hostPart.replace(/\/+$/,'');
  const port=lc>0?hostPart.slice(lc+1).replace(/[^0-9]/g,''):'';
  return {proto,addr,port};
}

async function loadWizardServers(){
  const list=$('#w4Servers');
  list.innerHTML='<div class="skel"></div><div class="skel"></div><div class="skel"></div>';
  const [srv,keys]=await Promise.all([api('subscription_servers',''),api('keys','')]);
  const all=[
    ...(Array.isArray(srv)?srv:[]).filter(s=>s.enabled!==false).map(s=>({...s,src:'sub'})),
    ...(Array.isArray(keys)?keys:[]).filter(k=>k.enabled!==false).map(k=>({...k,src:'key'}))
  ];
  if(all.length===0){
    list.innerHTML='<div style="padding:24px;text-align:center;color:var(--text2);font-size:13px">Нет серверов. Можно добавить позже на вкладке «Серверы».</div>';
    $('#w4Next').textContent='Пропустить';$('#w4Next').disabled=false;
    $('#w4Next').onclick=()=>{ wizardState.serverTag=null; nextStep(); };
    return;
  }

  // Search bar for many servers
  list.innerHTML='';
  if(all.length>5){
    const inp=document.createElement('input');
    inp.type='text';inp.placeholder='Поиск…';
    inp.style.cssText='width:100%;margin-bottom:6px;font-size:14px;padding:8px 10px';
    inp.addEventListener('input',()=>{
      const q=inp.value.toLowerCase();
      list.querySelectorAll('.srv-item').forEach(el=>{
        el.style.display=el.dataset.name.includes(q)?'':'none';
      });
    });
    list.appendChild(inp);
  }

  all.forEach((s)=>{
    const info=_parseLinkInfo(s.link);
    const serverId=s.id;
    const name=s.name||serverId||'Сервер';
    const meta=[
      info.proto||'VPN',
      info.addr?(info.port?`${info.addr}:${info.port}`:info.addr):null
    ].filter(Boolean).join(' · ');
    const srcLabel=s.src==='key'?'ключ':(s.sub||'подписка');

    const div=document.createElement('div');
    div.className='radio-item srv-item';
    div.dataset.name=name.toLowerCase();
    div.innerHTML=`
      <div class="radio-dot"></div>
      <div class="info" style="min-width:0">
        <div class="name" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(name)}</div>
        <div class="meta" style="display:flex;gap:8px;align-items:center">
          <span>${esc(meta)}</span>
          <span style="font-size:10px;padding:1px 6px;border-radius:4px;background:rgba(99,102,241,.15);color:var(--accent2);flex-shrink:0">${esc(srcLabel)}</span>
        </div>
      </div>`;
    div.addEventListener('click',()=>{
      list.querySelectorAll('.radio-item').forEach(x=>x.classList.remove('selected'));
      div.classList.add('selected');
      wizardState.serverTag=serverId;
      $('#w4Next').disabled=false;
    });
    list.appendChild(div);
  });
}

$('#w4Next').addEventListener('click', async ()=>{
  const err=$('#w4Error'); err.textContent=' ';
  if(wizardState.serverTag){
    const r=await api('select_server',{id:wizardState.serverTag});
    if(r.error){ err.textContent='Ошибка: '+r.error; return; }
    // Start xray
    await api('start');
  }
  nextStep();
});

// Step 5: WireGuard toggle + finish
$('#w5Tog').addEventListener('click', ()=>{
  wizardState.wg=!wizardState.wg;
  $('#w5Tog').classList.toggle('on', wizardState.wg);
});

$('#w5Finish').addEventListener('click', async ()=>{
  await api('set_features',{wireguard: wizardState.wg ? '1' : '0'});
  await api('complete_onboarding');
  hideWizard();
  loadStatus();
  loadServers();
  toast('Готово! Можно пользоваться.');
});

// Skip buttons (work for any step)
document.querySelectorAll('[data-skip]').forEach(b=>{
  b.addEventListener('click', ()=>{
    if(wizardStep===4 && !wizardState.serverTag) wizardState.serverTag=null;
    if(wizardStep<5) nextStep();
    else $('#w5Finish').click();
  });
});

// ============ UPDATES ============
let _updateLatest=null;
let _updatePollHandle=null;

async function checkUpdate(force=false){
  const r=await api('check_update', force?'force=1':'');
  if(r.error){ $('#updateChangelog').textContent='Не удалось проверить обновления'; return null; }
  _updateLatest=r;
  $('#updateFrom').textContent='v'+(r.current||'?');
  $('#updateTo').textContent='v'+(r.latest||'?');
  if(r.available){
    $('#updateChangelog').textContent=r.changelog||'(нет описания)';
    $('#updateApplyBtn').hidden=false;
    $('#updateBadge').textContent='1';
    $('#updateBadge').hidden=false;
  }else{
    $('#updateChangelog').textContent='Установлена последняя версия v'+(r.current||'?');
    $('#updateApplyBtn').hidden=true;
    $('#updateBadge').hidden=true;
  }
  // cache
  try{ localStorage.setItem('xrayvpn:update:check', JSON.stringify({r, ts:Date.now()})); }catch(e){}
  return r;
}

function openUpdate(){
  $('#updateOverlay').classList.add('show');
  $('#updateBody').hidden=false;
  $('#updateProgress').hidden=true;
  $('#updateButtons').style.display='flex';
  checkUpdate(false);
}

function closeUpdate(){
  $('#updateOverlay').classList.remove('show');
  if(_updatePollHandle){ clearInterval(_updatePollHandle); _updatePollHandle=null; }
}

async function applyUpdate(){
  if(!confirm('Применить обновление? Сервисы будут перезапущены.')) return;
  $('#updateBody').hidden=true;
  $('#updateProgress').hidden=false;
  $('#updateProgressMsg').textContent='Запускаю...';
  $('#updateProgressLog').textContent='';
  $('#updateButtons').style.display='none';
  const r=await api('apply_update',{});
  if(r.error){ toast('Ошибка: '+r.error,true); $('#updateButtons').style.display='flex'; return; }
  _updatePollHandle=setInterval(pollUpdateStatus, 2000);
}

async function pollUpdateStatus(){
  const r=await api('status_update','');
  if(r.error) return;
  $('#updateProgressMsg').textContent=r.message||r.status||'…';
  if(r.log_tail) $('#updateProgressLog').textContent=r.log_tail;
  if(r.status==='done' || r.status==='failed'){
    if(_updatePollHandle){ clearInterval(_updatePollHandle); _updatePollHandle=null; }
    const ok=r.status==='done';
    const bar=$('#updateProgress .update-progress-bar');
    if(bar){ bar.classList.remove('indeterminate'); }
    const fill=$('#updateProgress .update-progress-bar .fill');
    if(fill){ fill.style.width='100%'; }
    $('#updateButtons').innerHTML = ok
      ? '<button class="btn btn-success" onclick="location.reload()">Обновить страницу</button>'
      : '<button class="btn btn-ghost" onclick="closeUpdate()">Закрыть</button><button class="btn btn-warn" onclick="api(\'rollback_update\',{}).then(()=>{toast(\'Откат запущен\'); _updatePollHandle=setInterval(pollUpdateStatus,2000)})">Откатить</button>';
    $('#updateButtons').style.display='flex';
    toast(ok?'Обновление установлено':'Обновление не удалось', !ok);
  }
}

// Fire-and-forget background check at startup (cached 6h server-side too)
async function _backgroundUpdateCheck(){
  // Use localStorage cache to avoid even hitting api.php within 6h
  try{
    const raw=localStorage.getItem('xrayvpn:update:check');
    if(raw){
      const c=JSON.parse(raw);
      if(Date.now()-c.ts < 6*3600*1000){
        if(c.r && c.r.available){
          $('#updateBadge').textContent='1';
          $('#updateBadge').hidden=false;
        }
        return;
      }
    }
  }catch(e){}
  const r=await api('check_update','');
  if(r && !r.error && r.available){
    $('#updateBadge').textContent='1';
    $('#updateBadge').hidden=false;
  }
  try{ localStorage.setItem('xrayvpn:update:check', JSON.stringify({r, ts:Date.now()})); }catch(e){}
}

// ============ SETTINGS ============
let _features={wireguard:true, adguard:true, auto_update:false, theme:'auto'};

function _applyTog(el, on){ el.classList.toggle('on', !!on); }

async function loadSettings(){
  const s=await api('status','');
  $('#setVersion').textContent='v'+(s.version||'dev');
  const ob=await api('get_onboarding_status','');
  $('#setKnPassStatus').textContent = ob.kn_pass_set ? 'Установлен' : 'Не задан';

  _features = s.features || _features;
  _applyTog($('#togWireguard'), _features.wireguard);
  _applyTog($('#togAdguard'), _features.adguard);
  _applyTog($('#togAutoUpdate'), _features.auto_update);
  $('#themeSelect').value = _features.theme || 'auto';

  $('#btnLogoutSettings').hidden = !!s.local;
}

function openSettings(){
  $('#settingsOverlay').classList.add('show');
  loadSettings();
}
function closeSettings(){ $('#settingsOverlay').classList.remove('show'); }

async function _setFeature(key, val){
  const r=await api('set_features', {[key]: val ? '1' : '0'});
  if(r && !r.error) _features = r;
  return r;
}

// Wire toggles (set after DOM)
function _wireSettings(){
  $('#togWireguard').addEventListener('click', async ()=>{
    const cur=$('#togWireguard').classList.contains('on');
    _applyTog($('#togWireguard'), !cur);
    await _setFeature('wireguard', !cur);
    applyFeatureVisibility();
    toast(cur ? 'WireGuard выключен' : 'WireGuard включён');
  });
  $('#togAdguard').addEventListener('click', async ()=>{
    const cur=$('#togAdguard').classList.contains('on');
    _applyTog($('#togAdguard'), !cur);
    await _setFeature('adguard', !cur);
    toast(cur ? 'AdGuard отключён' : 'AdGuard включён');
  });
  $('#togAutoUpdate').addEventListener('click', async ()=>{
    const cur=$('#togAutoUpdate').classList.contains('on');
    _applyTog($('#togAutoUpdate'), !cur);
    await _setFeature('auto_update', !cur);
    toast(cur ? 'Автообновление выключено' : 'Автообновление включено');
  });
  $('#themeSelect').addEventListener('change', async (e)=>{
    const t=e.target.value;
    document.documentElement.dataset.theme = t;
    _features.theme = t;
    await api('set_features', {theme: t});
    try{ localStorage.setItem('xrayvpn:theme', t); }catch(e){}
    toast('Тема: '+(t==='auto'?'авто':t==='dark'?'тёмная':'светлая'));
  });
}

async function changeUiPassword(){
  const p=prompt('Новый пароль (минимум 4 символа):');
  if(p===null) return;
  if(p.length<4){ toast('Минимум 4 символа', true); return; }
  const p2=prompt('Повтори пароль:');
  if(p!==p2){ toast('Пароли не совпадают', true); return; }
  const r=await api('set_ui_password', {password:p});
  if(r.error){ toast('Ошибка: '+r.error, true); return; }
  toast('Пароль обновлён');
}

async function resetKnPassword(){
  const p=prompt('Новый пароль admin Keenetic (тот же, что на http://192.168.1.1):');
  if(p===null) return;
  if(!p){ toast('Пустой пароль', true); return; }
  const t=await api('test_kn_password', {password:p});
  if(!t.ok){ toast('Пароль не подходит — проверь', true); return; }
  await api('set_kn_password', {password:p});
  toast('Пароль сохранён ('+t.count+' устройств)');
  loadSettings();
}

async function rollbackUpdate(){
  if(!confirm('Откатить на предыдущую версию? Сервисы перезапустятся.')) return;
  const r=await api('rollback_update', {});
  if(r.error){ toast('Ошибка: '+r.error, true); return; }
  closeSettings();
  openUpdate();
  $('#updateBody').hidden=true;
  $('#updateProgress').hidden=false;
  $('#updateProgressMsg').textContent='Откатываю...';
  _updatePollHandle=setInterval(pollUpdateStatus, 2000);
}

async function restartWizard(){
  if(!confirm('Показать мастер настройки снова?\n\nВсе данные (ключи, подписки, домены, WireGuard) останутся. Удаляется только метка "настройка завершена".')) return;
  await api('reset_onboarding', {});
  closeSettings();
  location.reload();
}

async function doLogout(){
  await api('logout', {});
  location.reload();
}

// Theme: load from localStorage and apply early in init
function applyTheme(t){
  if(t==='auto'){
    const mq = window.matchMedia('(prefers-color-scheme: light)');
    document.documentElement.dataset.theme = mq.matches ? 'light' : 'dark';
  } else {
    document.documentElement.dataset.theme = t;
  }
}

function applyFeatureVisibility(){
  // Hide WG tab when feature disabled
  document.querySelectorAll('[data-feature]').forEach(el=>{
    const feat = el.dataset.feature;
    const enabled = _features[feat];
    el.hidden = !enabled;
  });
}

// ============ INIT ============
async function init(){
  // Theme early
  let t='auto';
  try{ t = localStorage.getItem('xrayvpn:theme') || 'auto'; }catch(e){}
  applyTheme(t);

  const s=await api('status','');
  if(s.error==='auth_required' || (s.authenticated===false && s.local===false)){
    showLogin();
    return;
  }
  _features = s.features || _features;
  applyFeatureVisibility();

  if(s.onboarded===false){
    showWizard();
    return;
  }
  // Normal startup
  loadStatus(); loadServers();
  setInterval(loadStatus, 30000);
  _wireSettings();
  _backgroundUpdateCheck();
}
init();
</script>
</body>
</html>
