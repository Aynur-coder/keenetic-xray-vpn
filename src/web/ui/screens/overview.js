// Обзор: VPN state with start/stop/restart, the current server (ping, countries, probe),
// IPs, warnings with their fix-it action, and the last 5 events.
// Everything shown about the tunnel follows overview.state, so no two cards can disagree.
import { html, useState, useEffect, useRef } from '../vendor/preact-htm.js';
import {
  api, errorText, refreshOverview, refreshIps, refreshServers,
} from '../api.js';
import { store, useStore } from '../store.js';
import { Card } from '../components/card.js';
import { Badge } from '../components/badge.js';
import { Button } from '../components/button.js';
import { Icon } from '../components/icons.js';
import { toast } from '../components/toast.js';
import { ServerPicker } from './server-picker.js';

// Apply-backed actions wait up to the backend's 60 s apply lock.
const APPLY_TIMEOUT_MS = 70000;
// server_probe runs a temporary Xray and two checks through it (~20 s worst case).
const PROBE_TIMEOUT_MS = 45000;

export const STATE_LABEL = {
  running: ['green', 'Работает'],
  paused: ['orange', 'Пауза: сервер недоступен'],
  stopped: ['red', 'Остановлен'],
  config_error: ['red', 'Ошибка конфига'],
  starting: ['orange', 'Запускается'],
};

const STATE_HINT = {
  running: 'Трафик по правилам идёт через VPN',
  paused: 'Сервер не отвечает — трафик временно идёт напрямую',
  stopped: 'VPN выключен — весь трафик идёт напрямую',
  starting: 'Xray запускается…',
};

const ACTIONS = {
  start: { done: 'VPN запущен' },
  stop: { done: 'VPN остановлен' },
  restart: { done: 'VPN перезапущен' },
};

// 'FI' → '🇫🇮' (regional indicator symbols); '' for anything that isn't two letters.
export function flagOf(cc) {
  if (!/^[A-Za-z]{2}$/.test(cc || '')) return '';
  return String.fromCodePoint(...[...cc.toUpperCase()].map((c) => 0x1f1a5 + c.charCodeAt(0)));
}

// Router timestamps are ISO with the router's offset; show the router's wall clock as is:
// 'HH:MM' for today, 'DD.MM HH:MM' otherwise.
export function eventTime(ts) {
  const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(String(ts || ''));
  if (!m) return '';
  const now = new Date();
  const today = m[1] === String(now.getFullYear())
    && Number(m[2]) === now.getMonth() + 1 && Number(m[3]) === now.getDate();
  return today ? `${m[4]}:${m[5]}` : `${m[3]}.${m[2]} ${m[4]}:${m[5]}`;
}

async function runAction(action, setBusy) {
  setBusy(action);
  const res = await api(action, {}, { timeout: APPLY_TIMEOUT_MS });
  setBusy(null);
  if (!res.error) {
    if (action !== 'stop' && res.xray_running === false) {
      toast('Xray не запустился — подробности в событиях', 'error');
    } else {
      toast(ACTIONS[action].done, 'success');
    }
  }
  refreshOverview();
  refreshIps();
}

// Старт/Стоп follow whether Xray runs, not the label: in config_error Xray may still be
// serving on the old config (→ Стоп) or be down (→ Старт). Older backends lack the field.
function isXrayRunning(overview) {
  if (typeof overview.xray_running === 'boolean') return overview.xray_running;
  return overview.state !== 'stopped';
}

function StateCard({ overview }) {
  const [busy, setBusy] = useState(null);
  const [tone, label] = STATE_LABEL[overview.state] || ['neutral', overview.state];
  const reason = overview.reason || STATE_HINT[overview.state] || '';
  const stopped = !isXrayRunning(overview);
  return html`
    <${Card} class="state-card">
      <div class="state">
        <span class=${`state__dot state__dot--${tone}`} aria-hidden="true"></span>
        <div class="state__text">
          <p class="state__label">${label}</p>
          ${reason ? html`<p class=${`state__reason ${overview.reason ? 'is-error' : ''}`}>
            ${reason}</p>` : null}
        </div>
      </div>
      <div class="actions">
        ${stopped
          ? html`<${Button} variant="primary" icon="play" loading=${busy === 'start'}
              disabled=${!!busy} onClick=${() => runAction('start', setBusy)}>Старт</${Button}>`
          : html`<${Button} variant="secondary" icon="stop" loading=${busy === 'stop'}
              disabled=${!!busy} onClick=${() => runAction('stop', setBusy)}>Стоп</${Button}>`}
        <${Button} variant="secondary" icon="restart" loading=${busy === 'restart'}
          disabled=${!!busy} onClick=${() => runAction('restart', setBusy)}>Перезапуск</${Button}>
      </div>
    </${Card}>
  `;
}

function CountryChip({ label, cc }) {
  const known = !!cc;
  const tone = cc === 'RU' ? 'red' : known ? 'neutral' : 'muted';
  return html`
    <span class=${`chip chip--${tone}`}>
      <span class="chip__label">${label}:</span>
      ${known ? html`<span>${flagOf(cc)} ${cc}</span>` : html`<span>не проверено</span>`}
    </span>
  `;
}

function ServerCard({ overview, onPick }) {
  const servers = useStore((s) => s.servers);
  const [probing, setProbing] = useState(false);
  const server = overview.effective || overview.active;
  const row = server && servers && servers.servers
    ? servers.servers.find((s) => s.id === server.id) : null;

  async function probe() {
    setProbing(true);
    const res = await api('server_probe', { id: server.id },
      { quiet: true, timeout: PROBE_TIMEOUT_MS });
    setProbing(false);
    if (res.error || res.ok === false) {
      toast(`Проверка не удалась: ${errorText(res.error || 'нет ответа')}`, 'error');
      return;
    }
    const cur = store.get().servers;
    if (cur && cur.servers) {
      store.set({ servers: { ...cur, servers: cur.servers.map((s) => (s.id === server.id
        ? { ...s, google_country: res.google_country, exit_country: res.exit_country } : s)) } });
    }
    const parts = [`Google: ${res.google_country || '?'}`, `выход: ${res.exit_country || '?'}`];
    if (typeof res.delay_ms === 'number') parts.push(`${res.delay_ms} мс`);
    toast(parts.join(' · '), res.google_country === 'RU' ? 'error' : 'success');
    refreshOverview();
  }

  const pick = html`<${Button} variant="primary" icon="swap" onClick=${onPick}>
    Сменить сервер</${Button}>`;
  if (!server) {
    return html`
      <${Card} title="Текущий сервер">
        <p class="muted">Сервер не выбран.</p>
        <div class="actions">${pick}</div>
      </${Card}>
    `;
  }
  const ping = row && typeof row.ping_ms === 'number' ? `${row.ping_ms} мс` : null;
  return html`
    <${Card} title="Текущий сервер">
      <div class="server">
        <p class="server__name">${server.name || server.id}</p>
        <div class="server__meta">
          ${server.proto ? html`<${Badge} tone="accent">${server.proto}</${Badge}>` : null}
          <span class="muted">${ping ? `Пинг ${ping}` : 'Пинг —'}</span>
        </div>
      </div>
      <div class="chips">
        <${CountryChip} label="Для Google" cc=${row && row.google_country} />
        <${CountryChip} label="Для остальных" cc=${row && row.exit_country} />
        <${Button} variant="ghost" size="sm" loading=${probing} onClick=${probe}
          aria-label="Проверить страну выхода">
          ${probing ? 'Проверяю…' : 'Проверить'}</${Button}>
      </div>
      <div class="actions">${pick}</div>
    </${Card}>
  `;
}

// VPN IP follows overview.state first: a stale check_ips answer must never say
// "185.x.x.x" next to «Остановлен».
const VPN_IP_OFF = {
  stopped: { value: 'Остановлен', tone: 'red', note: 'Трафик идёт напрямую' },
  paused: { value: 'На паузе', tone: 'orange', note: 'Сервер не отвечает, трафик идёт напрямую' },
  starting: { value: 'Запускается', tone: 'orange' },
};

function vpnIpView(overview, ips) {
  if (VPN_IP_OFF[overview.state]) return VPN_IP_OFF[overview.state];
  if (!isXrayRunning(overview)) return VPN_IP_OFF.stopped;
  if (!ips) return { value: 'Проверяется…', tone: 'muted' };
  if (ips.vpn_ip) return { value: ips.vpn_ip, tone: 'text', mono: true };
  const reason = ips.reason && ips.reason.message;
  return { value: 'Нет ответа', tone: 'red', note: reason || '' };
}

function IpCard({ overview }) {
  const ips = useStore((s) => s.ips);
  const [checking, setChecking] = useState(false);
  const vpn = vpnIpView(overview, ips);

  async function recheck() {
    setChecking(true);
    await refreshIps();
    setChecking(false);
  }

  return html`
    <${Card} title="IP-адреса" actions=${html`
      <${Button} variant="ghost" size="sm" icon="restart" loading=${checking} onClick=${recheck}
        aria-label="Проверить IP-адреса заново">Обновить</${Button}>`}>
      <dl class="ips">
        <div class="ips__item">
          <dt>VPN IP</dt>
          <dd class=${`ips__value tone-${vpn.tone} ${vpn.mono ? 'mono' : ''}`}>${vpn.value}</dd>
          ${vpn.note ? html`<dd class="ips__note">${vpn.note}</dd>` : null}
        </div>
        <div class="ips__item">
          <dt>Реальный IP</dt>
          <dd class=${`ips__value ${ips && ips.real_ip ? 'mono' : 'tone-muted'}`}>
            ${ips ? ips.real_ip || '—' : 'Проверяется…'}</dd>
        </div>
      </dl>
    </${Card}>
  `;
}

const WARNING_TONE = { watchdog_paused: 'orange', selected_missing: 'orange' };

function WarningItem({ warning, onPick }) {
  const [busy, setBusy] = useState(false);
  const code = warning.code;

  async function updateSubscriptions() {
    setBusy(true);
    const res = await api('update_subscriptions', {}, { timeout: APPLY_TIMEOUT_MS });
    setBusy(false);
    if (!res.error) toast('Подписки обновлены', 'success');
    refreshOverview();
    refreshServers();
  }

  let action = null;
  if (code === 'selected_missing' || code === 'google_ru') {
    action = html`<${Button} size="sm" onClick=${onPick}>Выбрать сервер</${Button}>`;
  } else if (code === 'subscription_expired') {
    action = html`<${Button} size="sm" loading=${busy} onClick=${updateSubscriptions}>
      Обновить подписку</${Button}>`;
  } else if (code === 'no_servers') {
    action = html`<a class="btn btn--secondary btn--sm" href="#/servers">К серверам</a>`;
  }
  const tone = WARNING_TONE[code] || 'red';
  return html`
    <li class=${`callout callout--${tone}`}>
      <${Icon} name="alert" />
      <p class="callout__text">${warning.message}</p>
      ${action ? html`<div class="callout__action">${action}</div>` : null}
    </li>
  `;
}

const LEVEL_ICON = { info: ['info', 'Инфо'], warn: ['alert', 'Внимание'],
  error: ['alert', 'Ошибка'] };

function EventsCard({ events }) {
  const all = html`<a class="link" href="#/diagnostics">Все события</a>`;
  return html`
    <${Card} title="Последние события" actions=${all}>
      ${events === null ? html`<p class="muted">Загрузка…</p>`
        : events.length === 0 ? html`<p class="muted">Событий пока нет.</p>`
        : html`<ul class="events">
          ${events.map((e, i) => {
            const [icon, levelLabel] = LEVEL_ICON[e.level] || LEVEL_ICON.info;
            return html`
              <li key=${`${e.ts}-${i}`} class=${`event event--${e.level || 'info'}`}>
                <time class="event__time" datetime=${e.ts}>${eventTime(e.ts)}</time>
                <span class="event__icon"><${Icon} name=${icon} size=${16}
                  label=${levelLabel} /></span>
                <span class="event__msg">${e.msg}</span>
              </li>`;
          })}
        </ul>`}
    </${Card}>
  `;
}

// Calls fn when value changes after it was first known (the first value is already
// covered by app.js polling / the mount-time load).
function useOnChange(value, fn) {
  const prev = useRef(value);
  useEffect(() => {
    if (prev.current !== null && prev.current !== value) fn();
    prev.current = value;
  }, [value]);
}

export function OverviewScreen() {
  const overview = useStore((s) => s.overview);
  const [picking, setPicking] = useState(false);
  const [events, setEvents] = useState(null);

  const state = overview ? overview.state : null;
  const serverId = overview && (overview.effective || overview.active)
    ? (overview.effective || overview.active).id : null;
  const warningCount = overview ? overview.warnings.length : 0;

  useEffect(() => {
    if (!store.get().servers) refreshServers();
  }, []);

  // A state or server change means new events, a new IP and new ping/country rows.
  useEffect(() => {
    if (!overview) return;
    api('events', { limit: 5 }, { method: 'GET', quiet: true }).then((res) => {
      if (!res.error) setEvents(Array.isArray(res.events) ? res.events : []);
    });
  }, [state, serverId, warningCount, !!overview]);
  useOnChange(state, refreshIps);
  useOnChange(serverId, refreshServers);

  if (!overview) return html`<p class="muted">Загрузка…</p>`;
  const openPicker = () => setPicking(true);
  const warnings = overview.warnings || [];

  return html`
    ${warnings.length ? html`
      <ul class="callouts" aria-label="Предупреждения">
        ${warnings.map((w) => html`<${WarningItem} key=${w.code} warning=${w}
          onPick=${openPicker} />`)}
      </ul>` : null}
    <div class="overview-grid">
      <${StateCard} overview=${overview} />
      <${ServerCard} overview=${overview} onPick=${openPicker} />
      <${IpCard} overview=${overview} />
      <${EventsCard} events=${events} />
    </div>
    ${picking ? html`<${ServerPicker} onClose=${() => setPicking(false)} />` : null}
  `;
}
