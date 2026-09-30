// Диагностика → События: the events journal (api `events`), newest first, grouped by day,
// with level / type filters. Refreshes every 10 s while the browser tab is visible.
import { html, useState, useRef } from '../vendor/preact-htm.js';
import { api, errorText } from '../api.js';
import { useVisiblePolling } from '../poll.js';
import { Badge } from '../components/badge.js';
import { Button } from '../components/button.js';
import { Card } from '../components/card.js';
import { EmptyState } from '../components/empty.js';
import { Icon } from '../components/icons.js';

const REFRESH_MS = 10000;
const LIMIT = 200;

const LEVELS = [
  { value: 'info', label: 'Инфо' },
  { value: 'warn', label: 'Внимание' },
  { value: 'error', label: 'Ошибки' },
];
const LEVEL_LABEL = { info: 'Инфо', warn: 'Внимание', error: 'Ошибка' };

// Event types written by api.php / xray-manager.sh (spec §5, events.log).
const TYPES = {
  server: 'Сервер', service: 'Xray', rules: 'Правила', subscription: 'Подписки',
  watchdog: 'Watchdog', config: 'Конфиг', update: 'Обновление', firewall: 'Firewall',
};

// Router timestamps: '2026-09-30T09:12:00+03:00', '…Z', or BusyBox's '…+0300' (no colon,
// which not every Date parser accepts). null when unparseable.
export function parseTs(ts) {
  const iso = String(ts || '').trim().replace(' ', 'T').replace(/([+-]\d{2})(\d{2})$/, '$1:$2');
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? null : d;
}

const pad = (n) => String(n).padStart(2, '0');

function dayKey(d) {
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

// «Сегодня», «Вчера», else dd.mm (dd.mm.yyyy for another year).
export function dayLabel(d, now = new Date()) {
  const yesterday = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
  if (dayKey(d) === dayKey(now)) return 'Сегодня';
  if (dayKey(d) === dayKey(yesterday)) return 'Вчера';
  const dm = `${pad(d.getDate())}.${pad(d.getMonth() + 1)}`;
  return d.getFullYear() === now.getFullYear() ? dm : `${dm}.${d.getFullYear()}`;
}

function timeOf(d) {
  return `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

// [{label, items:[{ev, date}]}] in the order received (newest first).
function groupByDay(events) {
  const groups = [];
  let last = null;
  for (const ev of events) {
    const date = parseTs(ev.ts);
    const key = date ? dayKey(date) : '?';
    if (!last || last.key !== key) {
      last = { key, label: date ? dayLabel(date) : 'Без даты', items: [] };
      groups.push(last);
    }
    last.items.push({ ev, date });
  }
  return groups;
}

function LevelChips({ value, onChange }) {
  return html`
    <div class="fgroup" role="group" aria-label="Уровень">
      <span class="fgroup__label">Уровень</span>
      <div class="fgroup__chips">
        <button type="button" class="fchip" aria-pressed=${value ? 'false' : 'true'}
          onClick=${() => onChange('')}>Все</button>
        ${LEVELS.map((l) => html`
          <button key=${l.value} type="button" class=${`fchip fchip--${l.value}`}
            aria-pressed=${value === l.value ? 'true' : 'false'}
            onClick=${() => onChange(value === l.value ? '' : l.value)}>${l.label}</button>`)}
      </div>
    </div>
  `;
}

function EventItem({ ev, date }) {
  const level = LEVEL_LABEL[ev.level] ? ev.level : 'info';
  const icon = level === 'info' ? 'info' : 'alert';
  return html`
    <li class=${`event dev event--${level}`}>
      <time class="event__time" datetime=${ev.ts}>${date ? timeOf(date) : '—'}</time>
      <span class="event__icon">
        <${Icon} name=${icon} size=${16} label=${LEVEL_LABEL[level]} /></span>
      <span class="dev__body">
        ${ev.type ? html`<${Badge}>${TYPES[ev.type] || ev.type}</${Badge}>` : null}
        <span class="event__msg">${ev.msg}</span>
      </span>
    </li>
  `;
}

export function EventsTab() {
  const [level, setLevel] = useState('');
  const [type, setType] = useState('');
  const [state, setState] = useState({ events: null, error: '' });
  const seq = useRef(0);

  async function load() {
    const my = ++seq.current;
    const res = await api('events', { limit: LIMIT, level: level || null, type: type || null },
      { method: 'GET', quiet: true });
    if (my !== seq.current) return; // filters changed while this one was on its way
    if (res.error) setState((s) => ({ ...s, error: errorText(res.error) }));
    else setState({ events: Array.isArray(res.events) ? res.events : [], error: '' });
  }
  useVisiblePolling(load, REFRESH_MS, true, [level, type]);

  function changeFilter(fn, value) {
    setState({ events: null, error: '' });
    fn(value);
  }

  const { events, error } = state;
  let body;
  if (events === null) {
    body = error
      ? html`<${EmptyState} icon="alert" title="Не удалось загрузить события" text=${error}
          action=${html`<${Button} onClick=${load}>Повторить</${Button}>`} />`
      : html`<p class="muted srv-loading"><span class="spinner"></span> Загрузка…</p>`;
  } else if (events.length === 0) {
    body = html`<${EmptyState} icon="check"
      title=${level || type ? 'Нет событий по этому фильтру' : 'Событий пока нет'} />`;
  } else {
    body = html`
      ${error ? html`<p class="tone-red diag__stale">Не удалось обновить: ${error}</p>` : null}
      ${groupByDay(events).map((g) => html`
        <section key=${g.key} class="dev-day" aria-label=${g.label}>
          <h3 class="dev-day__title">${g.label}</h3>
          <ul class="events">
            ${g.items.map((it, i) => html`<${EventItem} key=${`${it.ev.ts}-${i}`} ...${it} />`)}
          </ul>
        </section>`)}
    `;
  }

  return html`
    <${Card} class="diag-card">
      <div class="filters diag__filters">
        <${LevelChips} value=${level} onChange=${(v) => changeFilter(setLevel, v)} />
        <label class="fgroup">
          <span class="fgroup__label">Тип</span>
          <select class="select select--sm dev__type" value=${type}
            onChange=${(e) => changeFilter(setType, e.currentTarget.value)}>
            <option value="">Все типы</option>
            ${Object.entries(TYPES).map(([v, l]) => html`
              <option key=${v} value=${v}>${l}</option>`)}
          </select>
        </label>
      </div>
      <div class="dev-list" aria-busy=${events === null ? 'true' : 'false'}>
        ${body}
      </div>
    </${Card}>
  `;
}
