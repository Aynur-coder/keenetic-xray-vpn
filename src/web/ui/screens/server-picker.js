// ServerPicker: sheet for choosing the active server (Обзор; reused by Серверы).
// Search, favourites first, then one group per source. Tapping a row applies the choice
// (select_server regenerates the config and restarts Xray) and closes on success;
// a failure stays in the sheet as an inline error. ServerList is the same body without the
// sheet (setup wizard).
import { html, useState, useEffect, useRef } from '../vendor/preact-htm.js';
import { api, errorText, refreshOverview, refreshServers, refreshIps } from '../api.js';
import { store, useStore } from '../store.js';
import { Sheet } from '../components/sheet.js';
import { SearchInput } from '../components/search.js';
import { Badge } from '../components/badge.js';
import { EmptyState } from '../components/empty.js';
import { Icon } from '../components/icons.js';
import { toast } from '../components/toast.js';

// Same wait as the backend's apply lock: a switch can queue behind another apply.
const APPLY_TIMEOUT_MS = 70000;

// [{key, title, servers}] — favourites group first, then sources in the backend's order.
// Servers of a disabled subscription are left out: they can't carry traffic. A server's
// own 'enabled' flag no longer matters here — only subscriptions can be turned off.
export function groupServers(data, query) {
  const sources = (data && data.sources) || [];
  const enabledSource = new Set(sources.filter((s) => s.enabled).map((s) => s.id));
  const q = query.trim().toLowerCase();
  const matches = (s) => !q || [s.name, s.host, s.proto]
    .some((v) => String(v || '').toLowerCase().includes(q));
  const usable = ((data && data.servers) || [])
    .filter((s) => enabledSource.has(s.source) && matches(s));

  const groups = [];
  const favorites = usable.filter((s) => s.favorite);
  if (favorites.length) groups.push({ key: 'fav', title: 'Избранное', servers: favorites });
  for (const src of sources) {
    const list = usable.filter((s) => !s.favorite && s.source === src.id);
    if (!list.length) continue;
    groups.push({ key: `src-${src.id}`, title: src.name || src.id, servers: list });
  }
  return groups;
}

// Switches the active server (select_server regenerates the config and restarts Xray),
// updates the store and toasts the outcome. A failure comes back as {error: text}
// without a toast, so the caller decides where to show it.
export async function applyServerChoice(server) {
  const res = await api('select_server', { id: server.id },
    { quiet: true, timeout: APPLY_TIMEOUT_MS });
  refreshOverview();
  refreshIps();
  if (res.error) {
    refreshServers();
    return { error: errorText(res.error) };
  }
  const cur = store.get().servers;
  if (cur && cur.servers) {
    store.set({ servers: { ...cur, servers: cur.servers.map((s) => ({
      ...s, active: s.id === server.id })) } });
  }
  refreshServers();
  if (res.xray_running === false) toast('Сервер выбран, но Xray не запустился', 'error');
  else toast(`Сервер: ${server.name || server.id}`, 'success');
  return { ok: true };
}

function pingText(ms) {
  return typeof ms === 'number' ? `${ms} мс` : '';
}

function PickerRow({ server, checked, busy, disabled, tabbable, onSelect }) {
  return html`
    <button type="button" role="radio" class=${`pick ${checked ? 'is-current' : ''}`}
      aria-checked=${checked ? 'true' : 'false'} aria-busy=${busy ? 'true' : undefined}
      aria-disabled=${disabled ? 'true' : undefined} tabindex=${tabbable ? 0 : -1}
      data-id=${server.id} onClick=${() => !disabled && onSelect(server)}>
      <span class="pick__mark" aria-hidden="true">
        ${busy ? html`<span class="spinner"></span>`
          : checked ? html`<${Icon} name="check" size=${18} />` : null}
      </span>
      <span class="pick__text">
        <span class="pick__name">${server.name || server.id}</span>
        <span class="pick__sub">${server.host || ''}</span>
      </span>
      <span class="pick__meta">
        <span class="pick__ping">${pingText(server.ping_ms)}</span>
        <${Badge}>${server.proto || '—'}</${Badge}>
      </span>
    </button>
  `;
}

// The picker's body without the sheet: search, list, inline error. Also shown inline by the
// setup wizard. onDone() runs after a successful switch (or a tap on the current server);
// onBusy(bool) reports a switch in flight; `empty` replaces the no-servers state;
// `focusSearch` focuses the search field on desktop.
export function ServerList({ onDone, onBusy, empty, focusSearch = false }) {
  const data = useStore((s) => s.servers);
  const [query, setQuery] = useState('');
  const [busyId, setBusyId] = useState(null);
  const [error, setError] = useState(null);
  const [loadError, setLoadError] = useState(null);
  const [focusId, setFocusId] = useState(null);
  const body = useRef(null);

  useEffect(() => {
    refreshServers().then((res) => setLoadError(res.error ? errorText(res.error) : null));
    if (!focusSearch) return undefined;
    // Desktop only: on a phone focusing the field would pop the keyboard over the list.
    // After the Sheet's own initial focus (its effect runs after this one).
    const t = setTimeout(() => {
      if (!window.matchMedia('(min-width: 900px)').matches || !body.current) return;
      const input = body.current.querySelector('input');
      if (input) input.focus();
    }, 0);
    return () => clearTimeout(t);
  }, []);

  const groups = groupServers(data, query);
  const flat = groups.flatMap((g) => g.servers);
  const current = flat.find((s) => s.active);
  const tabId = flat.some((s) => s.id === focusId) ? focusId
    : current ? current.id : flat.length ? flat[0].id : null;

  function setBusy(id) {
    setBusyId(id);
    if (onBusy) onBusy(!!id);
  }

  async function select(server) {
    if (busyId) return;
    if (server.active) {
      onDone();
      return;
    }
    setBusy(server.id);
    setError(null);
    const res = await applyServerChoice(server);
    setBusy(null);
    if (res.error) {
      setError(res.error);
      return;
    }
    onDone();
  }

  // Roving focus: arrows/Home/End move between rows, Enter/Space selects (a native button).
  // Arrows don't select, unlike a plain radio group: every selection restarts Xray.
  function onKeyDown(e) {
    const keys = ['ArrowDown', 'ArrowUp', 'Home', 'End'];
    if (!keys.includes(e.key) || !flat.length) return;
    e.preventDefault();
    const i = Math.max(0, flat.findIndex((s) => s.id === tabId));
    let next = i;
    if (e.key === 'ArrowDown') next = Math.min(flat.length - 1, i + 1);
    else if (e.key === 'ArrowUp') next = Math.max(0, i - 1);
    else if (e.key === 'Home') next = 0;
    else next = flat.length - 1;
    const id = flat[next].id;
    setFocusId(id);
    const el = [...e.currentTarget.querySelectorAll('[role="radio"]')]
      .find((b) => b.dataset.id === id);
    if (el) el.focus();
  }

  // No search box over a genuinely empty list.
  const showSearch = !data || flat.length > 0 || !!query;
  let list;
  if (!data) {
    list = loadError
      ? html`<${EmptyState} icon="alert" title="Не удалось загрузить серверы" text=${loadError} />`
      : html`<p class="picker__loading muted"><span class="spinner"></span> Загрузка…</p>`;
  } else if (!flat.length) {
    list = query
      ? html`<${EmptyState} icon="search" title="Ничего не найдено"
          text="Попробуйте другое название, адрес или протокол." />`
      : empty || html`<${EmptyState} icon="servers" title="Нет доступных серверов"
          text="Добавьте подписку или ключ в разделе «Серверы»."
          action=${html`<a class="btn btn--secondary btn--md" href="#/servers"
            onClick=${onDone}>К серверам</a>`} />`;
  } else {
    list = html`
      <div class="picker__list" role="radiogroup" aria-label="Серверы"
        aria-busy=${busyId ? 'true' : undefined} onKeyDown=${onKeyDown}>
        ${groups.map((g) => html`
          <div key=${g.key} class="picker__group" role="group" aria-label=${g.title}>
            <div class="picker__head" aria-hidden="true">${g.title}</div>
            ${g.servers.map((s) => html`
              <${PickerRow} key=${s.id} server=${s} checked=${!!s.active}
                busy=${busyId === s.id} disabled=${!!busyId && busyId !== s.id}
                tabbable=${s.id === tabId} onSelect=${select} />
            `)}
          </div>
        `)}
      </div>
    `;
  }

  return html`
    <div class="picker" ref=${body}>
      ${showSearch ? html`
        <${SearchInput} value=${query} onInput=${setQuery}
          placeholder="Поиск по названию, адресу, протоколу" label="Поиск сервера" />` : null}
      ${error ? html`
        <div class="callout callout--red" role="alert">
          <${Icon} name="alert" />
          <div class="callout__text"><strong>Не удалось переключить сервер</strong>
            <span class="callout__sub">${error}</span></div>
        </div>` : null}
      ${list}
    </div>
  `;
}

export function ServerPicker({ onClose }) {
  return html`
    <${Sheet} open=${true} title="Выбор сервера" onClose=${onClose}>
      <${ServerList} onDone=${onClose} focusSearch=${true} />
    </${Sheet}>
  `;
}
