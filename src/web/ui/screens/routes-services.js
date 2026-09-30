// Маршруты → Сервисы: connected domain lists (v2fly catalog entries and GitHub URL lists)
// with their target, on/off switch and «⋮» menu, plus the catalog search sheet.
import { html, useState, useEffect, useRef } from '../vendor/preact-htm.js';
import { api, errorText } from '../api.js';
import { Button } from '../components/button.js';
import { Badge } from '../components/badge.js';
import { Card } from '../components/card.js';
import { Menu } from '../components/menu.js';
import { Sheet } from '../components/sheet.js';
import { SearchInput } from '../components/search.js';
import { Toggle } from '../components/toggle.js';
import { EmptyState } from '../components/empty.js';
import { toast } from '../components/toast.js';
import { confirm } from '../components/confirm.js';
import { plural } from './add-link.js';
import { eventTime } from './overview.js';
import { TargetSelect, reportApply, APPLY_TIMEOUT_MS } from './routes.js';

// Downloading a list from GitHub (through RU DPI) + apply.
const FETCH_TIMEOUT_MS = 120000;
// The first catalog search may have to download the catalog itself.
const SEARCH_TIMEOUT_MS = 45000;
const SEARCH_DEBOUNCE_MS = 300;
const DOMAIN_FORMS = ['домен', 'домена', 'доменов'];

function isV2fly(list) {
  return list.source === 'v2fly';
}

function ServiceRow({ list, target, busy, servers, handlers }) {
  const name = list.name || list.id;
  const when = eventTime(list.updated);
  const count = Number(list.count) || 0;
  const meta = [`${count} ${plural(count, DOMAIN_FORMS)}`, when ? `обновлён ${when}` : 'не обновлялся'];
  return html`
    <li class=${`svc ${list.enabled ? '' : 'is-off'}`} data-name=${name}>
      <div class="svc__text">
        <span class="svc__name">${name}
          ${isV2fly(list) ? null : html`<${Badge}>GitHub</${Badge}>`}</span>
        <span class="svc__meta">${busy || meta.join(' · ')}</span>
      </div>
      <div class="svc__controls">
        ${isV2fly(list) ? html`
          <${TargetSelect} value=${target} servers=${servers} disabled=${!!busy}
            label=${`Куда направлять: ${name}`}
            onChange=${(t) => handlers.setTarget(list, t)} />`
          : html`<span class="svc__note muted">домены — в своих правилах</span>`}
        ${busy ? html`<span class="spinner" aria-hidden="true"></span>` : null}
        <${Toggle} checked=${!!list.enabled} disabled=${!!busy} label=${`Включён: ${name}`}
          onChange=${() => handlers.toggle(list)} />
        <${Menu} label=${`Действия: ${name}`} disabled=${!!busy} items=${[
          { label: 'Обновить', onSelect: () => handlers.refresh(list) },
          { label: 'Удалить', danger: true, onSelect: () => handlers.remove(list) },
        ]} />
      </div>
    </li>
  `;
}

function CatalogSheet({ onClose, onAdded }) {
  const [query, setQuery] = useState('');
  const [state, setState] = useState({ busy: false, results: null, added: [], error: null });
  const [adding, setAdding] = useState(null);
  const seq = useRef(0);

  useEffect(() => {
    const q = query.trim();
    const my = ++seq.current;
    if (!q) {
      setState({ busy: false, results: null, added: [], error: null });
      return undefined;
    }
    setState((s) => ({ ...s, busy: true }));
    const timer = setTimeout(async () => {
      const res = await api('v2fly_search', { q }, {
        method: 'GET', quiet: true, timeout: SEARCH_TIMEOUT_MS });
      if (my !== seq.current) return;
      setState(res.error
        ? { busy: false, results: null, added: [], error: errorText(res.error) }
        : { busy: false, results: res.results || [], added: res.added || [], error: null });
    }, SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(timer);
  }, [query]);

  async function add(name) {
    setAdding(name);
    const res = await api('v2fly_add', { name }, { quiet: true, timeout: FETCH_TIMEOUT_MS });
    setAdding(null);
    if (!reportApply(res, `Не удалось добавить «${name}»`)) return;
    const count = Number(res.count) || 0;
    toast(`«${name}» подключён: ${count} ${plural(count, DOMAIN_FORMS)}`, 'success');
    setState((s) => ({ ...s, added: [...s.added, name] }));
    onAdded();
  }

  const added = new Set(state.added);
  let body;
  if (state.error) body = html`<p class="tone-red">${state.error}</p>`;
  else if (state.results === null) {
    body = html`<p class="muted catalog__hint">${state.busy ? 'Ищу…'
      : 'Каталог v2fly: google, youtube, openai, discord, telegram… Можно по-русски: ютуб, телега.'}
      </p>`;
  } else if (!state.results.length) body = html`<p class="muted">Ничего не найдено</p>`;
  else {
    body = html`
      <ul class="catalog" aria-label="Найденные сервисы">
        ${state.results.map((name) => html`
          <li key=${name} class="catalog__item" data-name=${name}>
            <span class="mono catalog__name">${name}</span>
            ${added.has(name)
              ? html`<${Badge} tone="green">подключён</${Badge}>`
              : html`<${Button} size="sm" variant="primary" loading=${adding === name}
                  disabled=${!!adding && adding !== name}
                  aria-label=${`Подключить ${name}`}
                  onClick=${() => add(name)}>Подключить</${Button}>`}
          </li>`)}
      </ul>`;
  }

  return html`
    <${Sheet} open=${true} title="Добавить сервис" onClose=${onClose}
      footer=${html`<${Button} variant="primary" onClick=${onClose}>Готово</${Button}>`}>
      <div class="form">
        <${SearchInput} value=${query} onInput=${setQuery} placeholder="Название сервиса"
          label="Поиск в каталоге сервисов" />
        ${adding ? html`<p class="muted" role="status">Загружаю «${adding}» с GitHub и применяю
          — это может занять до минуты.</p>` : null}
        ${body}
      </div>
    </${Sheet}>
  `;
}

export function ServicesCard({ data, servers, reload, patch }) {
  const [busy, setBusy] = useState({}); // list id → status text
  const [catalog, setCatalog] = useState(false);

  const setRowBusy = (id, text) => setBusy((b) => {
    const next = { ...b };
    if (text) next[id] = text;
    else delete next[id];
    return next;
  });

  const handlers = {
    async setTarget(list, target) {
      const key = `list:${list.name}`;
      patch((d) => ({ ...d, targets: { ...d.targets, [key]: target } }));
      setRowBusy(list.id, 'Применяю…');
      const res = await api('set_rule_target', { key, target },
        { quiet: true, timeout: APPLY_TIMEOUT_MS });
      setRowBusy(list.id, null);
      if (reportApply(res, 'Не удалось сменить направление')) toast('Сохранено', 'success');
      reload();
    },

    async toggle(list) {
      setRowBusy(list.id, 'Применяю…');
      const res = await api('toggle_github_list', { id: list.id },
        { quiet: true, timeout: APPLY_TIMEOUT_MS });
      setRowBusy(list.id, null);
      if (reportApply(res, `Не удалось ${list.enabled ? 'выключить' : 'включить'} сервис`)) {
        toast(`«${list.name}» ${list.enabled ? 'выключен' : 'включён'}`, 'success');
      }
      reload();
    },

    async refresh(list) {
      setRowBusy(list.id, 'Обновляю…');
      const res = isV2fly(list)
        ? await api('v2fly_refresh', { name: list.name }, { quiet: true, timeout: FETCH_TIMEOUT_MS })
        : await api('update_github_lists', {}, { quiet: true, timeout: FETCH_TIMEOUT_MS });
      setRowBusy(list.id, null);
      if (reportApply(res, `Не удалось обновить «${list.name}»`)) {
        toast(`«${list.name}» обновлён`, 'success');
      }
      reload();
    },

    async remove(list) {
      const ok = await confirm({
        title: 'Удалить сервис?', danger: true, confirmLabel: 'Удалить',
        message: `Список «${list.name}» перестанет влиять на маршруты.`,
      });
      if (!ok) return;
      setRowBusy(list.id, 'Удаляю…');
      const res = await api('delete_github_list', { id: list.id },
        { quiet: true, timeout: APPLY_TIMEOUT_MS });
      setRowBusy(list.id, null);
      if (reportApply(res, `Не удалось удалить «${list.name}»`)) {
        toast(`«${list.name}» удалён`, 'success');
      }
      reload();
    },
  };

  const lists = data.lists;
  const addButton = html`<${Button} variant="primary" size="sm" icon="plus"
    onClick=${() => setCatalog(true)}>Добавить сервис</${Button}>`;
  return html`
    <${Card} title="Сервисы" actions=${lists.length ? addButton : null} class="services">
      ${lists.length ? html`
        <ul class="svc-list" aria-label="Подключённые сервисы">
          ${lists.map((l) => html`
            <${ServiceRow} key=${l.id} list=${l} target=${data.targets[`list:${l.name}`]}
              busy=${busy[l.id]} servers=${servers} handlers=${handlers} />`)}
        </ul>` : html`
        <${EmptyState} icon="routes" title="Сервисы не подключены"
          text="Сервис — готовый список доменов (YouTube, Instagram, OpenAI…). Подключите его,
            и все его сайты пойдут через VPN."
          action=${addButton} />`}
      ${catalog ? html`<${CatalogSheet} onClose=${() => setCatalog(false)}
        onAdded=${reload} />` : null}
    </${Card}>
  `;
}
