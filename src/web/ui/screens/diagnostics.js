// Диагностика: tabs События / Соединения / Проверка сайта / Сырые логи, one per sub-route
// (#/diagnostics/events|connections|site|logs). A tab whose tool is switched off in
// «Настройки → Диагностика» is not shown at all; only the active tab is mounted, so its
// auto-refresh stops as soon as another tab is picked.
import { html, useState, useEffect } from '../vendor/preact-htm.js';
import { api, errorText } from '../api.js';
import { Button } from '../components/button.js';
import { EmptyState } from '../components/empty.js';
import { Tabs } from '../components/tabs.js';
import { EventsTab } from './diag-events.js';
import { ConnectionsTab } from './diag-connections.js';
import { SiteCheckTab } from './diag-site.js';
import { LogsTab } from './diag-logs.js';

// id = sub-route, flag = get_features key that switches the tool on.
const TABS = [
  { id: 'events', label: 'События', flag: 'diag_events', screen: EventsTab },
  { id: 'connections', label: 'Соединения', flag: 'diag_connections', screen: ConnectionsTab },
  { id: 'site', label: 'Проверка сайта', flag: 'diag_site_check', screen: SiteCheckTab },
  { id: 'logs', label: 'Сырые логи', flag: 'logs_enabled', screen: LogsTab },
];

// '#/diagnostics/connections' → 'connections'; '#/diagnostics' → ''.
function currentTab() {
  return location.hash.replace(/^#\/?/, '').split('/')[1] || '';
}

function useSubRoute() {
  const [tab, setTab] = useState(currentTab);
  useEffect(() => {
    const onHash = () => setTab(currentTab());
    window.addEventListener('hashchange', onHash);
    return () => window.removeEventListener('hashchange', onHash);
  }, []);
  return tab;
}

export function DiagnosticsScreen() {
  const requested = useSubRoute();
  const [features, setFeatures] = useState(null);
  const [error, setError] = useState('');

  function load() {
    setError('');
    api('get_features', null, { quiet: true }).then((r) => {
      if (r.error) setError(errorText(r.error));
      else setFeatures(r);
    });
  }
  useEffect(load, []);

  const visible = features ? TABS.filter((t) => features[t.flag] !== false) : [];
  const active = visible.find((t) => t.id === requested) || visible[0];

  // Keep the address in step with what is shown (bare #/diagnostics, a hidden or unknown tab).
  useEffect(() => {
    if (active && active.id !== requested) {
      history.replaceState(null, '', `#/diagnostics/${active.id}`);
    }
  }, [active && active.id, requested]);

  if (!features) {
    return error
      ? html`<${EmptyState} icon="alert" title="Не удалось загрузить настройки диагностики"
          text=${error} action=${html`<${Button} onClick=${load}>Повторить</${Button}>`} />`
      : html`<p class="muted srv-loading"><span class="spinner"></span> Загрузка…</p>`;
  }
  if (!active) {
    return html`<${EmptyState} icon="diagnostics" title="Инструменты диагностики выключены"
      text="Журнал событий, соединения, проверку сайта и логи Xray можно включить в настройках."
      action=${html`<a class="btn btn--secondary btn--md" href="#/settings">
        Включить в настройках</a>`} />`;
  }

  const Screen = active.screen;
  return html`
    <div class="diag">
      <${Tabs} label="Инструменты диагностики" tabs=${visible} value=${active.id}
        onChange=${(id) => { location.hash = `#/diagnostics/${id}`; }} />
      <div class="diag__panel" role="tabpanel" aria-label=${active.label}>
        <${Screen} key=${active.id} />
      </div>
    </div>
  `;
}
