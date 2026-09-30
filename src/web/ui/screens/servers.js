// Серверы: subscriptions, keys and servers in one place. A sticky toolbar (search, filters,
// «Проверить все», «Добавить»), then one collapsible card per source with its servers.
// Tapping a row selects the server; star and «⋮» menu act on the row itself. Only
// subscriptions can be turned off (source menu) — a server has no on/off of its own.
import { html, useState, useEffect } from '../vendor/preact-htm.js';
import { api, errorText, refreshServers, refreshOverview } from '../api.js';
import { store, useStore } from '../store.js';
import { Button } from '../components/button.js';
import { Badge } from '../components/badge.js';
import { Menu } from '../components/menu.js';
import { Sheet } from '../components/sheet.js';
import { SearchInput } from '../components/search.js';
import { EmptyState } from '../components/empty.js';
import { Icon } from '../components/icons.js';
import { toast } from '../components/toast.js';
import { confirm } from '../components/confirm.js';
import { applyServerChoice } from './server-picker.js';
import { AddLinkSheet, plural } from './add-link.js';
import { flagOf, eventTime } from './overview.js';

// Apply-backed actions wait up to the backend's 60 s apply lock.
const APPLY_TIMEOUT_MS = 70000;
// server_probe runs a temporary Xray and two checks through it (~20 s worst case).
const PROBE_TIMEOUT_MS = 45000;
// ping_servers pings every server in one request (parallel TCP connects).
const PING_TIMEOUT_MS = 60000;

const SERVER_FORMS = ['сервер', 'сервера', 'серверов'];
const PROTO_LABEL = {
  vless: 'VLESS', ss: 'SS', shadowsocks: 'SS', trojan: 'Trojan',
  hysteria2: 'Hysteria2',
};

export function protoLabel(proto) {
  return PROTO_LABEL[proto] || proto || '—';
}

// '🇳🇱 Amsterdam' → 'NL'; '' when the name doesn't start with a flag.
export function countryOf(name) {
  const cps = [...String(name || '').trim()].slice(0, 2).map((c) => c.codePointAt(0));
  if (cps.length < 2 || cps.some((cp) => cp < 0x1f1e6 || cp > 0x1f1ff)) return '';
  return String.fromCharCode(...cps.map((cp) => cp - 0x1f1e6 + 65));
}

export function pingTone(ms) {
  if (typeof ms !== 'number') return 'muted';
  if (ms < 150) return 'green';
  if (ms < 400) return 'orange';
  return 'red';
}

function patchServers(fn) {
  const cur = store.get().servers;
  if (cur && cur.servers) store.set({ servers: { ...cur, servers: fn(cur.servers) } });
}

function patchServer(id, patch) {
  patchServers((list) => list.map((s) => (s.id === id ? { ...s, ...patch } : s)));
}

// Outcome of an apply-backed mutation. The backend writes the change before applying,
// so after a failed apply the list is reloaded to show what is really stored.
function applied(res, failText) {
  if (res.error) {
    toast(`${failText}: ${errorText(res.error)}`, 'error');
    refreshServers();
    refreshOverview();
    return false;
  }
  refreshOverview();
  if (res.xray_running === false) toast('Xray не запустился — подробности в событиях', 'error');
  return true;
}

function sourceTitle(src) {
  return src.kind === 'keys' ? 'Мои ключи' : (src.name || src.id);
}

// ---------- filtering ----------

const NO_FILTERS = { proto: '', country: '', favorites: false };

function filterCount(f) {
  return (f.proto ? 1 : 0) + (f.country ? 1 : 0) + (f.favorites ? 1 : 0);
}

function matcher(query, filters) {
  const q = query.trim().toLowerCase();
  return (s) => (!q || [s.name, s.host].some((v) => String(v || '').toLowerCase().includes(q)))
    && (!filters.proto || protoLabel(s.proto) === protoLabel(filters.proto))
    && (!filters.country || countryOf(s.name) === filters.country)
    && (!filters.favorites || s.favorite);
}

// Filter options present in the list: [{value, label}], in order of first appearance.
function options(servers) {
  const protos = new Map();
  const countries = new Map();
  for (const s of servers) {
    const pl = protoLabel(s.proto);
    if (s.proto && !protos.has(pl)) protos.set(pl, { value: s.proto, label: pl });
    const cc = countryOf(s.name);
    if (cc && !countries.has(cc)) countries.set(cc, { value: cc, label: `${flagOf(cc)} ${cc}` });
  }
  return {
    protos: [...protos.values()],
    countries: [...countries.values()].sort((a, b) => a.value.localeCompare(b.value)),
  };
}

function ChipGroup({ label, value, items, onChange }) {
  return html`
    <div class="fgroup" role="group" aria-label=${label}>
      <span class="fgroup__label">${label}</span>
      <div class="fgroup__chips">
        <button type="button" class="fchip" aria-pressed=${value ? 'false' : 'true'}
          onClick=${() => onChange('')}>Все</button>
        ${items.map((it) => html`
          <button key=${it.value} type="button" class="fchip"
            aria-pressed=${value === it.value ? 'true' : 'false'}
            onClick=${() => onChange(value === it.value ? '' : it.value)}>${it.label}</button>
        `)}
      </div>
    </div>
  `;
}

function Filters({ filters, setFilters, opts }) {
  const set = (patch) => setFilters({ ...filters, ...patch });
  return html`
    <div class="filters">
      ${opts.protos.length > 1 ? html`<${ChipGroup} label="Протокол" value=${filters.proto}
        items=${opts.protos} onChange=${(proto) => set({ proto })} />` : null}
      ${opts.countries.length > 1 ? html`<${ChipGroup} label="Страна" value=${filters.country}
        items=${opts.countries} onChange=${(country) => set({ country })} />` : null}
      <button type="button" class="fchip fchip--fav" aria-pressed=${filters.favorites
        ? 'true' : 'false'} onClick=${() => set({ favorites: !filters.favorites })}>
        <${Icon} name="star" size=${16} class=${filters.favorites ? 'icon--filled' : ''} />
        Избранные</button>
    </div>
  `;
}

// ---------- row ----------

function PingCell({ server, pinging }) {
  if (pinging) return html`<span class="srv__ping tone-muted" aria-label="Проверяю пинг">…</span>`;
  const ms = server.ping_ms;
  const text = typeof ms === 'number' ? `${ms} мс` : '—';
  const title = server.proto === 'hysteria2' ? 'Hysteria2 работает по UDP — пинг не измеряется'
    : typeof ms === 'number' ? 'Пинг (TCP)' : 'Нет данных о пинге';
  return html`<span class=${`srv__ping tone-${pingTone(ms)}`} title=${title}>${text}</span>`;
}

function GoogleChip({ cc }) {
  if (!cc) return null;
  return html`
    <span class=${`gchip ${cc === 'RU' ? 'gchip--red' : ''}`} title="Страна для Google">
      <span class="sr-only">Страна для Google: </span>G ${flagOf(cc)} ${cc}
    </span>
  `;
}

function ServerRow({ server, usable, isKey, busy, locked, pinging, handlers }) {
  const name = server.name || server.id;
  const dim = !usable;
  return html`
    <li class=${`srv ${server.active ? 'is-active' : ''} ${dim ? 'is-off' : ''}`}
      data-id=${server.id}>
      <button type="button" class="star" aria-pressed=${server.favorite ? 'true' : 'false'}
        aria-label=${`Избранное: ${name}`} onClick=${() => handlers.favorite(server)}>
        <${Icon} name="star" size=${20} class=${server.favorite ? 'icon--filled' : ''} />
      </button>
      <button type="button" class="srv__main" aria-current=${server.active ? 'true' : undefined}
        aria-disabled=${locked || !usable ? 'true' : undefined}
        aria-busy=${busy === 'select' ? 'true' : undefined}
        onClick=${() => handlers.select(server, usable)}>
        <span class="srv__name">${name}</span>
        <span class="srv__sub">
          <${Badge} tone=${server.active ? 'accent' : 'neutral'}>
            ${protoLabel(server.proto)}</${Badge}>
          <span class="srv__host">${server.host || ''}</span>
          <${GoogleChip} cc=${server.google_country} />
        </span>
        <span class="srv__end">
          ${busy === 'select' || busy === 'probe'
            ? html`<span class="spinner" aria-hidden="true"></span>`
            : server.active
              ? html`<${Icon} name="check" size=${18} label="Текущий сервер" />` : null}
          <${PingCell} server=${server} pinging=${pinging} />
        </span>
      </button>
      <${Menu} label=${`Действия: ${name}`} items=${[
        { label: 'Проверить страну', onSelect: () => handlers.probe(server, 'country') },
        { label: 'Реальная задержка', onSelect: () => handlers.probe(server, 'delay') },
        isKey && { label: 'Переименовать', onSelect: () => handlers.rename(server) },
        isKey && { label: 'Удалить', danger: true, onSelect: () => handlers.remove(server) },
      ]} />
    </li>
  `;
}

// ---------- source group ----------

function SourceCard({ source, servers, open, onToggle, busy, rowProps }) {
  const isKeys = source.kind === 'keys';
  const title = sourceTitle(source);
  const listId = `src-list-${source.id}`;
  const meta = [`${source.count} ${plural(source.count, SERVER_FORMS)}`];
  if (!isKeys) {
    const when = eventTime(source.updated);
    meta.push(when ? `обновлено ${when}` : 'не обновлялась');
  }
  return html`
    <section class=${`card src ${source.enabled ? '' : 'is-off'}`} data-source=${source.id}>
      <header class="src__head">
        <button type="button" class="src__toggle" aria-expanded=${open ? 'true' : 'false'}
          aria-controls=${listId} onClick=${onToggle}>
          <${Icon} name="chevron" size=${18} class=${`src__chev ${open ? 'is-open' : ''}`} />
          <span class="src__text">
            <span class="src__title">${title}</span>
            <span class="src__meta">${busy || meta.join(' · ')}</span>
          </span>
          ${source.enabled ? null : html`<${Badge} tone="orange">Выключена</${Badge}>`}
        </button>
        ${busy ? html`<span class="spinner src__spin" aria-hidden="true"></span>` : null}
        ${isKeys ? null : html`<${Menu} label=${`Действия: ${title}`} disabled=${!!busy} items=${[
          { label: 'Обновить', onSelect: () => rowProps.handlers.updateSource(source) },
          { label: source.enabled ? 'Выключить' : 'Включить',
            onSelect: () => rowProps.handlers.toggleSource(source) },
          { label: 'Удалить', danger: true,
            onSelect: () => rowProps.handlers.removeSource(source) },
        ]} />`}
      </header>
      ${source.error ? html`<p class="src__error"><${Icon} name="alert" size=${16} />
        <span>${source.error}</span></p>` : null}
      ${open ? html`
        <div id=${listId}>
          ${servers.length ? html`
            <ul class="srv-list" aria-label=${title}>
              ${servers.map((s) => html`
                <${ServerRow} key=${s.id} server=${s} isKey=${isKeys}
                  usable=${source.enabled} busy=${rowProps.busy[s.id]}
                  locked=${rowProps.locked} pinging=${rowProps.pinging}
                  handlers=${rowProps.handlers} />
              `)}
            </ul>` : html`<p class="src__empty muted">${isKeys
              ? 'Ключей нет — добавьте ссылку vless://, ss://, trojan:// или hysteria2://.'
              : 'В подписке нет серверов.'}</p>`}
        </div>` : null}
    </section>
  `;
}

// ---------- dialogs ----------

function RenameSheet({ server, onClose }) {
  const [name, setName] = useState(server.name || '');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  async function submit(e) {
    e.preventDefault();
    const value = name.trim();
    if (!value) {
      setError('Введите название');
      return;
    }
    setBusy(true);
    const res = await api('rename_key', { id: server.id, name: value }, { quiet: true });
    setBusy(false);
    if (res.error) {
      setError(errorText(res.error));
      return;
    }
    patchServer(server.id, { name: value });
    refreshOverview();
    toast('Ключ переименован', 'success');
    onClose();
  }

  return html`
    <${Sheet} open=${true} title="Переименовать ключ" onClose=${onClose}
      footer=${html`
        <${Button} variant="ghost" onClick=${onClose}>Отмена</${Button}>
        <${Button} variant="primary" type="submit" form="rename-form" loading=${busy}>
          Сохранить</${Button}>`}>
      <form id="rename-form" class="form" onSubmit=${submit}>
        <p class="muted">Сейчас:
          <strong class="rename__old">${server.name || server.id}</strong></p>
        <label class="field">
          <span class="field__label">Новое название</span>
          <input class="input" value=${name} maxlength="100" autocomplete="off"
            onInput=${(e) => setName(e.currentTarget.value)} />
        </label>
        ${error ? html`<p class="form__error" role="alert">${error}</p>` : null}
      </form>
    </${Sheet}>
  `;
}

// ---------- screen ----------

function useIsDesktop() {
  const query = '(min-width: 900px)';
  const [desk, setDesk] = useState(() => window.matchMedia(query).matches);
  useEffect(() => {
    const mq = window.matchMedia(query);
    const on = () => setDesk(mq.matches);
    mq.addEventListener('change', on);
    return () => mq.removeEventListener('change', on);
  }, []);
  return desk;
}

export function ServersScreen() {
  const data = useStore((s) => s.servers);
  const desktop = useIsDesktop();
  const [loadError, setLoadError] = useState(null);
  const [query, setQuery] = useState('');
  const [filters, setFilters] = useState(NO_FILTERS);
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [collapsed, setCollapsed] = useState({});
  const [adding, setAdding] = useState(false);
  const [renaming, setRenaming] = useState(null);
  const [pinging, setPinging] = useState(false);
  const [busy, setBusy] = useState({}); // server id → 'select' | 'probe'
  const [sourceBusy, setSourceBusy] = useState(null); // {id, text} of a source being changed
  const [selecting, setSelecting] = useState(false);

  useEffect(() => {
    refreshServers().then((res) => setLoadError(res.error ? errorText(res.error) : null));
  }, []);

  const setRowBusy = (id, what) => setBusy((b) => {
    const next = { ...b };
    if (what) next[id] = what;
    else delete next[id];
    return next;
  });

  const handlers = {
    async select(server, usable) {
      if (selecting || server.active) return;
      if (!usable) {
        toast('Подписка выключена — включите её, чтобы выбрать сервер', 'info');
        return;
      }
      setSelecting(true);
      setRowBusy(server.id, 'select');
      const res = await applyServerChoice(server);
      setRowBusy(server.id, null);
      setSelecting(false);
      if (res.error) toast(`Не удалось переключить сервер: ${res.error}`, 'error');
    },

    async favorite(server) {
      const value = !server.favorite;
      patchServer(server.id, { favorite: value });
      const res = await api('set_server_flags', { id: server.id, favorite: value });
      if (res.error) patchServer(server.id, { favorite: !value });
    },

    async probe(server, what) {
      setRowBusy(server.id, 'probe');
      const res = await api('server_probe', { id: server.id },
        { quiet: true, timeout: PROBE_TIMEOUT_MS });
      setRowBusy(server.id, null);
      const name = server.name || server.id;
      if (res.error || res.ok === false) {
        toast(`${name}: проверка не удалась — ${errorText(res.error || 'нет ответа')}`, 'error');
        return;
      }
      patchServer(server.id, { google_country: res.google_country,
        exit_country: res.exit_country });
      if (what === 'delay') {
        const ms = typeof res.delay_ms === 'number' ? `${res.delay_ms} мс` : 'нет данных';
        toast(`${name}: реальная задержка ${ms}`, 'success');
      } else {
        toast(`${name}: Google ${res.google_country || '?'} · выход ${res.exit_country || '?'}`,
          res.google_country === 'RU' ? 'error' : 'success');
      }
    },

    rename(server) {
      setRenaming(server);
    },

    async remove(server) {
      const name = server.name || server.id;
      const ok = await confirm({
        title: 'Удалить ключ?', danger: true, confirmLabel: 'Удалить',
        message: `Ключ «${name}» будет удалён без возможности восстановления.`,
      });
      if (!ok) return;
      setRowBusy(server.id, 'probe');
      const res = await api('delete_key', { id: server.id },
        { quiet: true, timeout: APPLY_TIMEOUT_MS });
      setRowBusy(server.id, null);
      if (!applied(res, 'Не удалось удалить ключ')) return;
      const cur = store.get().servers;
      if (cur) {
        store.set({ servers: {
          sources: cur.sources.map((s) => (s.kind === 'keys'
            ? { ...s, count: Math.max(0, s.count - 1) } : s)),
          servers: cur.servers.filter((s) => s.id !== server.id),
        } });
      }
      toast(`Ключ «${name}» удалён`, 'success');
    },

    async updateSource(source) {
      setSourceBusy({ id: source.id, text: 'Обновляю…' });
      const res = await api('update_subscriptions', {}, { timeout: APPLY_TIMEOUT_MS });
      setSourceBusy(null);
      if (res.error) return;
      refreshServers();
      refreshOverview();
      if (res.xray_running === false) toast('Xray не запустился — подробности в событиях', 'error');
      else toast(`Подписки обновлены: ${res.count ?? '?'} ${plural(res.count || 0, SERVER_FORMS)}`,
        'success');
    },

    async toggleSource(source) {
      setSourceBusy({ id: source.id, text: 'Применяю…' });
      const res = await api('toggle_subscription', { id: source.id },
        { quiet: true, timeout: APPLY_TIMEOUT_MS });
      setSourceBusy(null);
      const verb = source.enabled ? 'выключить' : 'включить';
      if (!applied(res, `Не удалось ${verb} подписку`)) return;
      const cur = store.get().servers;
      if (cur) {
        store.set({ servers: { ...cur, sources: cur.sources.map((s) => (s.id === source.id
          ? { ...s, enabled: !s.enabled } : s)) } });
      }
      toast(`Подписка «${sourceTitle(source)}» ${source.enabled ? 'выключена' : 'включена'}`,
        'success');
    },

    async removeSource(source) {
      const title = sourceTitle(source);
      const ok = await confirm({
        title: 'Удалить подписку?', danger: true, confirmLabel: 'Удалить',
        message: `Подписка «${title}» и её ${source.count} ${plural(source.count, SERVER_FORMS)}`
          + ' исчезнут из списка.',
      });
      if (!ok) return;
      setSourceBusy({ id: source.id, text: 'Удаляю…' });
      const res = await api('delete_subscription', { id: source.id },
        { quiet: true, timeout: APPLY_TIMEOUT_MS });
      setSourceBusy(null);
      if (!applied(res, 'Не удалось удалить подписку')) return;
      const cur = store.get().servers;
      if (cur) {
        store.set({ servers: {
          sources: cur.sources.filter((s) => s.id !== source.id),
          servers: cur.servers.filter((s) => s.source !== source.id),
        } });
      }
      toast(`Подписка «${title}» удалена`, 'success');
    },
  };

  async function pingAll() {
    setPinging(true);
    const res = await api('ping_servers', {}, { timeout: PING_TIMEOUT_MS });
    setPinging(false);
    if (res.error || !res.results) return;
    patchServers((list) => list.map((s) => (s.id in res.results
      ? { ...s, ping_ms: res.results[s.id] } : s)));
    const values = Object.values(res.results);
    const alive = values.filter((v) => typeof v === 'number').length;
    toast(`Пинг: ответили ${alive} из ${values.length}`, alive ? 'success' : 'error');
  }

  const sources = (data && data.sources) || [];
  const servers = (data && data.servers) || [];
  const hasSubs = sources.some((s) => s.kind !== 'keys');
  const opts = options(servers);
  const match = matcher(query, filters);
  const narrowed = !!query.trim() || filterCount(filters) > 0;
  const groups = sources
    .map((src) => ({ src, list: servers.filter((s) => s.source === src.id && match(s)) }))
    .filter((g) => !narrowed || g.list.length);
  const rowProps = { busy, locked: selecting, pinging, handlers };
  const nFilters = filterCount(filters);

  let body;
  if (!data) {
    body = loadError
      ? html`<${EmptyState} icon="alert" title="Не удалось загрузить серверы" text=${loadError}
          action=${html`<${Button} onClick=${() => refreshServers().then((r) =>
            setLoadError(r.error ? errorText(r.error) : null))}>Повторить</${Button}>`} />`
      : html`<p class="muted srv-loading"><span class="spinner"></span> Загрузка…</p>`;
  } else if (!hasSubs && !servers.length) {
    body = html`<${EmptyState} icon="servers" title="Серверов пока нет"
      text="Добавьте ссылку на подписку от VPN-провайдера или отдельный ключ vless://, ss://,
        trojan://, hysteria2://. Можно вставить сразу несколько строк."
      action=${html`<${Button} variant="primary" class="btn--lg" icon="plus"
        onClick=${() => setAdding(true)}>Добавить</${Button}>`} />`;
  } else if (!groups.length) {
    body = html`<${EmptyState} icon="search" title="Ничего не найдено"
      text="Попробуйте другое название или адрес, или сбросьте фильтры."
      action=${html`<${Button} onClick=${() => { setQuery(''); setFilters(NO_FILTERS); }}>
        Сбросить</${Button}>`} />`;
  } else {
    body = groups.map(({ src, list }) => html`
      <${SourceCard} key=${src.id} source=${src} servers=${list}
        open=${narrowed || !collapsed[src.id]}
        busy=${sourceBusy && sourceBusy.id === src.id ? sourceBusy.text : null}
        onToggle=${() => setCollapsed({ ...collapsed, [src.id]: !collapsed[src.id] })}
        rowProps=${rowProps} />
    `);
  }

  const showToolbar = data && (hasSubs || servers.length);
  return html`
    <div class="servers">
      ${showToolbar ? html`
        <div class="srv-toolbar">
          <div class="srv-toolbar__row">
            <div class="srv-toolbar__search">
              <${SearchInput} value=${query} onInput=${setQuery}
                placeholder="Поиск по названию или адресу" label="Поиск сервера" />
            </div>
            ${desktop ? null : html`
              <${Button} variant="secondary" icon="filter" class="srv-toolbar__filters"
                aria-label=${nFilters ? `Фильтры (${nFilters})` : 'Фильтры'}
                onClick=${() => setFiltersOpen(true)}>
                ${nFilters ? `${nFilters}` : null}</${Button}>`}
            <div class="srv-toolbar__actions">
              <${Button} variant="secondary" loading=${pinging} onClick=${pingAll}>
                ${pinging ? `Проверяю ${servers.length}…` : 'Проверить все'}</${Button}>
              <${Button} variant="primary" icon="plus" onClick=${() => setAdding(true)}>
                Добавить</${Button}>
            </div>
          </div>
          ${desktop ? html`<${Filters} filters=${filters} setFilters=${setFilters}
            opts=${opts} />` : null}
        </div>` : null}
      <div class="srv-groups">${body}</div>
      ${adding ? html`<${AddLinkSheet} onClose=${() => setAdding(false)} />` : null}
      ${renaming ? html`<${RenameSheet} server=${renaming}
        onClose=${() => setRenaming(null)} />` : null}
      <${Sheet} open=${filtersOpen && !desktop} title="Фильтры"
        onClose=${() => setFiltersOpen(false)}
        footer=${html`
          <${Button} variant="ghost" onClick=${() => setFilters(NO_FILTERS)}>Сбросить</${Button}>
          <${Button} variant="primary" onClick=${() => setFiltersOpen(false)}>Готово</${Button}>`}>
        <${Filters} filters=${filters} setFilters=${setFilters} opts=${opts} />
      </${Sheet}>
    </div>
  `;
}
