// Root of the new UI: hash routing, layout (sidebar ≥900 px / bottom nav <900 px), polling.
import { html, render, useState, useEffect, useRef } from './vendor/preact-htm.js';
import { api, OFFLINE_ERROR, refreshOverview, refreshIps } from './api.js';
import { useStore } from './store.js';
import { initTheme, adoptRouterTheme } from './theme.js';
import { Icon } from './components/icons.js';
import { Toasts } from './components/toast.js';
import { ConfirmHost } from './components/confirm.js';
import { EmptyState } from './components/empty.js';
import { OverviewScreen } from './screens/overview.js';
import { ServersScreen } from './screens/servers.js';
import { SettingsScreen } from './screens/settings.js';
import { LoginScreen } from './screens/login.js';
import { LegacyScreen } from './screens/legacy.js';

const OVERVIEW_EVERY_MS = 10000;
const CHECK_IPS_EVERY_MS = 60000;

// Main sections, in nav order. `legacy` = tab id in legacy.php for not-yet-rebuilt sections.
const SECTIONS = [
  { id: '', label: 'Обзор', icon: 'overview', screen: OverviewScreen },
  { id: 'servers', label: 'Серверы', icon: 'servers', screen: ServersScreen },
  { id: 'routes', label: 'Маршруты', icon: 'routes', legacy: 'rules' },
  { id: 'devices', label: 'Устройства', icon: 'devices', legacy: 'devices' },
  { id: 'diagnostics', label: 'Диагностика', icon: 'diagnostics', legacy: 'logs' },
];
const PAGES = [
  ...SECTIONS,
  { id: 'settings', label: 'Настройки', icon: 'settings', screen: SettingsScreen },
  { id: 'login', label: 'Вход', icon: 'lock', screen: LoginScreen },
];

// '#/diagnostics/connections' → 'diagnostics'; '', '#', '#/' → ''.
function currentRoute() {
  return location.hash.replace(/^#\/?/, '').split('/')[0];
}

function useRoute() {
  const [route, setRoute] = useState(currentRoute);
  useEffect(() => {
    const onHash = () => setRoute(currentRoute());
    window.addEventListener('hashchange', onHash);
    return () => window.removeEventListener('hashchange', onHash);
  }, []);
  return route;
}

// Runs `task` now and every `ms` while the tab is visible; catches up when it becomes visible.
function useVisiblePolling(task, ms, enabled) {
  useEffect(() => {
    if (!enabled) return undefined;
    let timer = null;
    const stop = () => {
      clearInterval(timer);
      timer = null;
    };
    const start = () => {
      stop();
      if (document.visibilityState !== 'visible') return;
      task();
      timer = setInterval(task, ms);
    };
    document.addEventListener('visibilitychange', start);
    start();
    return () => {
      stop();
      document.removeEventListener('visibilitychange', start);
    };
  }, [enabled]);
}

function NavLinks({ route, updateDot }) {
  return SECTIONS.map((s) => html`
    <a key=${s.id} class="nav__item" href=${`#/${s.id}`}
      aria-current=${route === s.id ? 'page' : undefined}>
      <${Icon} name=${s.icon} />
      <span class="nav__label">${s.label}</span>
    </a>
  `).concat(updateDot === undefined ? [] : [html`
    <a key="settings" class="nav__item nav__item--settings" href="#/settings"
      aria-current=${route === 'settings' ? 'page' : undefined}>
      <span class="dot-wrap"><${Icon} name="settings" />
        ${updateDot ? html`<span class="dot" aria-hidden="true"></span>` : null}</span>
      <span class="nav__label">Настройки</span>
      ${updateDot ? html`<span class="sr-only">(есть обновление)</span>` : null}
    </a>
  `]);
}

function OfflineBanner() {
  const offline = useStore((s) => s.offline);
  return html`
    <div class="offline-slot" role="status" aria-live="polite">
      ${offline ? html`
        <div class="banner banner--offline">
          <${Icon} name="offline" />
          <span><strong>${OFFLINE_ERROR}</strong>
            <span class="banner__sub"> — показаны последние полученные данные</span></span>
        </div>` : null}
    </div>
  `;
}

function App() {
  const route = useRoute();
  const updateAvailable = useStore((s) => !!(s.overview && s.overview.update_available));
  const page = PAGES.find((p) => p.id === route);
  const heading = useRef(null);
  const firstRender = useRef(true);
  const onLogin = route === 'login';

  useVisiblePolling(refreshOverview, OVERVIEW_EVERY_MS, !onLogin);
  useVisiblePolling(refreshIps, CHECK_IPS_EVERY_MS, !onLogin);

  useEffect(() => {
    document.title = `${page ? page.label : 'Не найдено'} · VKeen`;
    if (firstRender.current) {
      firstRender.current = false;
      return;
    }
    window.scrollTo(0, 0);
    if (heading.current) heading.current.focus({ preventScroll: true });
  }, [route]);

  let content;
  if (!page) {
    content = html`<${EmptyState} icon="alert" title="Такой страницы нет"
      action=${html`<a class="btn btn--secondary btn--md" href="#/">На обзор</a>`} />`;
  } else if (page.legacy) {
    content = html`<${LegacyScreen} tab=${page.legacy} />`;
  } else {
    const Screen = page.screen;
    content = html`<${Screen} />`;
  }

  return html`
    <div class="layout">
      <a class="skip-link" href="#main" onClick=${(e) => {
        e.preventDefault();
        heading.current && heading.current.focus();
      }}>К содержимому</a>
      <aside class="sidebar">
        <div class="brand"><span class="brand__mark" aria-hidden="true"></span>VKeen</div>
        <nav class="nav nav--side" aria-label="Разделы">
          <${NavLinks} route=${route} updateDot=${updateAvailable} />
        </nav>
      </aside>
      <div class="main-col">
        <header class="topbar">
          <h1 class="topbar__title" tabindex="-1" ref=${heading}>
            ${page ? page.label : 'Не найдено'}
          </h1>
          <a class="icon-btn topbar__gear" href="#/settings"
            aria-label=${updateAvailable ? 'Настройки (есть обновление)' : 'Настройки'}
            aria-current=${route === 'settings' ? 'page' : undefined}>
            <span class="dot-wrap"><${Icon} name="settings" />
              ${updateAvailable ? html`<span class="dot" aria-hidden="true"></span>` : null}</span>
          </a>
        </header>
        <${OfflineBanner} />
        <main id="main" class="content">${content}</main>
      </div>
      <nav class="nav nav--bottom" aria-label="Разделы">
        <${NavLinks} route=${route} />
      </nav>
      <${Toasts} />
      <${ConfirmHost} />
    </div>
  `;
}

async function boot() {
  initTheme();
  const root = document.getElementById('app');
  const status = await api('get_onboarding_status', null, { quiet: true });
  if (status.onboarded === false) {
    // The setup wizard still lives in the old interface.
    location.replace('legacy.php');
    return;
  }
  if (status.features && status.features.theme) adoptRouterTheme(status.features.theme);
  root.textContent = '';
  render(html`<${App} />`, root);
}

boot();
