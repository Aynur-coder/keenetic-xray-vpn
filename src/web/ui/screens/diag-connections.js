// Диагностика → Соединения: live LAN connections (api `connections`) — device, domain,
// address, protocol, route and traffic. A table on desktop, cards on a phone (CSS only).
// Refreshes every 5 s while the browser tab is visible, unless paused.
import { html, useState, useRef, useMemo } from '../vendor/preact-htm.js';
import { api, errorText } from '../api.js';
import { useVisiblePolling } from '../poll.js';
import { Badge } from '../components/badge.js';
import { Button } from '../components/button.js';
import { Card } from '../components/card.js';
import { EmptyState } from '../components/empty.js';
import { SearchInput } from '../components/search.js';

const REFRESH_MS = 5000;
const LIMIT = 300;
// The backend answers within its own ~3 s budget; leave room for a slow router.
const TIMEOUT_MS = 15000;

// Leaks first (they are what this tab exists for), otherwise the router's order.
const ROUTE_ORDER = { leak: 0 };

export function formatBytes(n) {
  const v = Number(n) || 0;
  if (v < 1024) return `${v} Б`;
  const units = ['КБ', 'МБ', 'ГБ', 'ТБ'];
  let x = v / 1024;
  let i = 0;
  while (x >= 1024 && i < units.length - 1) {
    x /= 1024;
    i++;
  }
  return `${x < 10 ? x.toFixed(1).replace('.', ',') : Math.round(x)} ${units[i]}`;
}

function protoLabel(c) {
  if (c.proto === 'udp' && Number(c.dport) === 443) return 'QUIC';
  return String(c.proto || '').toUpperCase();
}

function RouteBadge({ conn }) {
  switch (conn.route) {
    case 'vpn':
      return html`<${Badge} tone="accent">
        ${conn.server ? `VPN · ${conn.server}` : 'VPN'}</${Badge}>`;
    case 'leak':
      return html`<${Badge} tone="red">Утечка</${Badge}>`;
    case 'blocked':
      return html`<${Badge} tone="orange">Заблокировано</${Badge}>`;
    default:
      return html`<${Badge}>Напрямую</${Badge}>`;
  }
}

function address(c) {
  return String(c.dst).includes(':') ? `[${c.dst}]:${c.dport}` : `${c.dst}:${c.dport}`;
}

function matches(c, q) {
  if (!q) return true;
  return [c.device_name, c.device_ip, c.domain, c.dst, c.server]
    .some((v) => v && String(v).toLowerCase().includes(q));
}

function ConnRow({ conn }) {
  return html`
    <tr class=${`conn conn--${conn.route} ${conn.route === 'leak' ? 'is-leak' : ''}`}>
      <td class="conn__device">
        <span class="conn__name">${conn.device_name || conn.device_ip}</span>
        ${conn.device_name ? html`<span class="conn__sub mono">${conn.device_ip}</span>` : null}
      </td>
      <td class="conn__domain">
        ${conn.domain || html`<span class="tone-muted">—</span>`}</td>
      <td class="conn__addr mono" data-proto=${protoLabel(conn)}>${address(conn)}</td>
      <td class="conn__proto">${protoLabel(conn)}</td>
      <td class="conn__route"><${RouteBadge} conn=${conn} /></td>
      <td class="conn__bytes">${formatBytes(conn.bytes)}</td>
    </tr>
  `;
}

export function ConnectionsTab() {
  const [paused, setPaused] = useState(false);
  const [query, setQuery] = useState('');
  const [state, setState] = useState({ data: null, error: '' });
  const busy = useRef(false);

  async function load() {
    if (busy.current) return; // the previous snapshot is still being collected
    busy.current = true;
    const res = await api('connections', { limit: LIMIT },
      { method: 'GET', quiet: true, timeout: TIMEOUT_MS });
    busy.current = false;
    if (res.error) setState((s) => ({ ...s, error: errorText(res.error) }));
    else setState({ data: res, error: '' });
  }
  useVisiblePolling(load, REFRESH_MS, !paused);

  const { data, error } = state;
  const rows = useMemo(() => {
    const list = data && Array.isArray(data.connections) ? data.connections : [];
    return list
      .map((c, i) => ({ c, i }))
      .sort((a, b) => ((ROUTE_ORDER[a.c.route] ?? 1) - (ROUTE_ORDER[b.c.route] ?? 1)) || a.i - b.i)
      .map((x) => x.c);
  }, [data]);
  const q = query.trim().toLowerCase();
  const shown = rows.filter((c) => matches(c, q));
  const leaks = rows.filter((c) => c.route === 'leak').length;

  let body;
  if (!data) {
    body = error
      ? html`<${EmptyState} icon="alert" title="Не удалось получить соединения" text=${error}
          action=${html`<${Button} onClick=${load}>Повторить</${Button}>`} />`
      : html`<p class="muted srv-loading"><span class="spinner"></span> Загрузка…</p>`;
  } else if (rows.length === 0) {
    body = html`<${EmptyState} icon="devices" title="Соединений нет"
      text="Сейчас устройства сети ничего не загружают из интернета." />`;
  } else if (shown.length === 0) {
    body = html`<${EmptyState} icon="search" title="Ничего не найдено"
      text="Поиск идёт по устройству, домену, IP и серверу." />`;
  } else {
    body = html`
      <table class="conn-table">
        <thead>
          <tr><th scope="col">Устройство</th><th scope="col">Домен</th>
            <th scope="col">Адрес</th><th scope="col">Протокол</th>
            <th scope="col">Маршрут</th><th scope="col" class="conn__bytes">Трафик</th></tr>
        </thead>
        <tbody>
          ${shown.map((c, i) => html`<${ConnRow} key=${`${c.device_ip}-${c.dst}-${c.dport}-${i}`}
            conn=${c} />`)}
        </tbody>
      </table>
    `;
  }

  const total = data && typeof data.total === 'number' ? data.total : rows.length;
  return html`
    <${Card} class="diag-card">
      <div class="diag__toolbar">
        <div class="diag__search">
          <${SearchInput} value=${query} onInput=${setQuery}
            placeholder="Домен или устройство" label="Поиск по соединениям" />
        </div>
        <${Button} icon=${paused ? 'play' : 'pause'}
          class="conn__pause" onClick=${() => setPaused(!paused)}>
          ${paused ? 'Продолжить' : 'Пауза'}</${Button}>
      </div>
      ${data ? html`
        <div class="diag__meta">
          <span class="muted">
            ${q ? `Найдено ${shown.length} из ${rows.length}` : `Соединений: ${rows.length}`}
            ${total > rows.length ? ` (всего ${total}, показаны первые ${rows.length})` : ''}
          </span>
          ${leaks ? html`<${Badge} tone="red" dot=${true}>Утечек: ${leaks}</${Badge}>` : null}
          ${paused ? html`<${Badge} tone="orange">На паузе</${Badge}>` : null}
        </div>
        ${data.partial ? html`<p class="diag__note muted">
          Показана часть данных — роутер не успел собрать всё.</p>` : null}
        ${error ? html`<p class="tone-red diag__stale">Не удалось обновить: ${error}</p>` : null}
      ` : null}
      ${body}
    </${Card}>
  `;
}
