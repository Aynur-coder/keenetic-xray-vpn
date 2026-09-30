// Устройства: LAN devices from Keenetic (`lan_devices`) merged with the full-VPN MAC list
// (`devices`) — each with «Выборочно | Всё через VPN» (add_device / delete_device, applied at
// once) — plus WireGuard clients (devices-wg.js), hidden when WireGuard is switched off.
import { html, useState, useEffect, useMemo } from '../vendor/preact-htm.js';
import { api, errorText } from '../api.js';
import { store } from '../store.js';
import { Button } from '../components/button.js';
import { Card } from '../components/card.js';
import { EmptyState } from '../components/empty.js';
import { SearchInput } from '../components/search.js';
import { toast } from '../components/toast.js';
import { reportApply, APPLY_TIMEOUT_MS } from './routes-common.js';
import { WireGuardCard } from './devices-wg.js';

// lan_devices logs in to Keenetic's own API (3 HTTP round-trips) — give it room.
const LAN_TIMEOUT_MS = 20000;
const MAC_RE = /^([0-9A-F]{2}:){5}[0-9A-F]{2}$/;

// 'aa-bb-cc-dd-ee-ff' / 'aabb.ccdd.eeff' / 'AABBCCDDEEFF' → 'AA:BB:CC:DD:EE:FF'; '' if not a MAC.
export function normalizeMac(input) {
  const hex = String(input || '').trim().toUpperCase().replace(/[:\-.\s]/g, '');
  if (!/^[0-9A-F]{12}$/.test(hex)) return '';
  const mac = hex.match(/../g).join(':');
  return MAC_RE.test(mac) ? mac : '';
}

// One row per MAC: every LAN device, plus full-VPN MACs Keenetic doesn't list (offline).
// Online first, then by name.
function mergeDevices(lan, fullMacs, fullInfo) {
  const rows = new Map();
  for (const d of lan) {
    const mac = String(d.mac || '').toUpperCase();
    if (!mac || rows.has(mac)) continue;
    rows.set(mac, { mac, ip: d.ip || '', name: d.hostname || '', online: !!d.active });
  }
  for (const mac of fullMacs) {
    if (rows.has(mac)) continue;
    const info = fullInfo[mac] || {};
    rows.set(mac, { mac, ip: info.ip || '', name: info.hostname || '', online: false });
  }
  return [...rows.values()].sort((a, b) => (Number(b.online) - Number(a.online))
    || (a.name || '￿').localeCompare(b.name || '￿', 'ru')
    || a.mac.localeCompare(b.mac));
}

function matches(d, q) {
  if (!q) return true;
  return [d.name, d.ip, d.mac].some((v) => v && v.toLowerCase().includes(q));
}

function ModeSwitch({ device, full, busy, onChange }) {
  const label = `Режим: ${device.name || device.mac}`;
  const options = [[false, 'Выборочно'], [true, 'Всё через VPN']];
  return html`
    <div class="segmented dev-mode" role="radiogroup" aria-label=${label}
      aria-busy=${busy ? 'true' : undefined}>
      ${options.map(([value, text]) => html`
        <button key=${text} type="button" role="radio" class="segmented__item"
          aria-checked=${full === value ? 'true' : 'false'} disabled=${busy}
          onClick=${() => full !== value && onChange(value)}>
          ${busy && full === value ? html`<span class="spinner" aria-hidden="true"></span>` : null}
          ${text}
        </button>`)}
    </div>
  `;
}

function DeviceRow({ device, full, busy, error, onChange }) {
  return html`
    <li class=${`lan ${full ? 'is-full' : ''}`} data-mac=${device.mac}>
      <span class=${`lan__dot ${device.online ? 'is-on' : ''}`}
        title=${device.online ? 'В сети' : 'Не в сети'} aria-hidden="true"></span>
      <span class="lan__name">
        ${device.name || html`<span class="tone-muted">Без имени</span>`}
        ${device.online ? null : html`<span class="lan__off">не в сети</span>`}
        <span class="sr-only">${device.online ? ', в сети' : ''}</span>
      </span>
      <span class="lan__ip mono">${device.ip || html`<span class="tone-muted">нет IP</span>`}</span>
      <span class="lan__mac mono">${device.mac}</span>
      <span class="lan__mode">
        <${ModeSwitch} device=${device} full=${full} busy=${busy} onChange=${onChange} />
      </span>
      ${error ? html`<p class="lan__error" role="alert">${error}</p>` : null}
    </li>
  `;
}

function AddByMac({ fullSet, busy, onAdd }) {
  const [value, setValue] = useState('');
  const [error, setError] = useState('');
  async function submit(e) {
    e.preventDefault();
    const mac = normalizeMac(value);
    if (!mac) {
      setError('Нужен MAC-адрес вида AA:BB:CC:DD:EE:FF');
      return;
    }
    if (fullSet.has(mac)) {
      setError('Это устройство уже идёт через VPN целиком');
      return;
    }
    setError('');
    if (await onAdd(mac)) setValue('');
  }
  return html`
    <form class="lan-add" onSubmit=${submit} novalidate>
      <label class="field__label" for="lan-mac">Устройства нет в списке? Добавьте по MAC</label>
      <div class="lan-add__row">
        <input id="lan-mac" class="input mono" value=${value} placeholder="AA:BB:CC:DD:EE:FF"
          autocomplete="off" spellcheck="false" aria-invalid=${error ? 'true' : undefined}
          aria-describedby=${error ? 'lan-mac-err' : undefined}
          onInput=${(e) => { setValue(e.currentTarget.value); setError(''); }} />
        <${Button} type="submit" icon="plus" loading=${busy}>Всё через VPN</${Button}>
      </div>
      ${error ? html`<span class="field__error" id="lan-mac-err">${error}</span>` : null}
    </form>
  `;
}

function LanDevicesCard() {
  const [data, setData] = useState(null); // {lan, lanError, full: [mac], info: {mac: …}}
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const [query, setQuery] = useState('');
  const [pending, setPending] = useState({}); // mac → requested mode while it is applied
  const [rowError, setRowError] = useState({}); // mac → last apply error
  const [adding, setAdding] = useState(false);

  async function load() {
    setLoading(true);
    const [lan, full] = await Promise.all([
      api('lan_devices', null, { quiet: true, timeout: LAN_TIMEOUT_MS }),
      api('devices', null, { quiet: true, timeout: LAN_TIMEOUT_MS }),
    ]);
    setLoading(false);
    if (!Array.isArray(full)) {
      setError(errorText(full.error || 'Некорректный ответ роутера'));
      return;
    }
    const info = {};
    for (const d of full) info[String(d.mac).toUpperCase()] = d;
    setError('');
    setData({
      lan: Array.isArray(lan) ? lan : [],
      lanError: Array.isArray(lan) ? '' : errorText(lan.error || 'Некорректный ответ роутера'),
      full: Object.keys(info),
      info,
    });
  }
  useEffect(() => { load(); }, []);

  const fullSet = useMemo(() => new Set(data ? data.full : []), [data]);
  const rows = useMemo(() => (data ? mergeDevices(data.lan, data.full, data.info) : []), [data]);

  // Returns true when the change was applied.
  async function setMode(device, full) {
    const { mac } = device;
    const name = device.name || mac;
    const wasRunning = !!(store.get().overview && store.get().overview.xray_running);
    setPending((p) => ({ ...p, [mac]: full }));
    setRowError((e) => ({ ...e, [mac]: '' }));
    const res = await api(full ? 'add_device' : 'delete_device', { mac },
      { quiet: true, timeout: APPLY_TIMEOUT_MS });
    // Shown optimistically meanwhile; on failure the row falls back to the saved mode.
    setPending((p) => {
      const next = { ...p };
      delete next[mac];
      return next;
    });
    if (res.error) {
      setRowError((e) => ({ ...e, [mac]: `Не применилось: ${errorText(res.error)}` }));
      toast(`«${name}»: не удалось сменить режим — ${errorText(res.error)}`, 'error');
      return false;
    }
    setData((d) => ({
      ...d,
      full: full ? [...d.full.filter((m) => m !== mac), mac] : d.full.filter((m) => m !== mac),
      info: full ? { ...d.info, [mac]: { mac, ip: device.ip, hostname: device.name } } : d.info,
    }));
    if (!wasRunning && res.xray_running === false) {
      toast('Сохранено. VPN сейчас выключен — режим заработает после запуска', 'info');
    } else if (reportApply(res, 'Не удалось сменить режим') && res.xray_running !== false) {
      toast(full ? `«${name}»: весь трафик через VPN`
        : `«${name}»: только выбранные сайты через VPN`, 'success');
    }
    return true;
  }

  async function addByMac(mac) {
    setAdding(true);
    const ok = await setMode({ mac, ip: '', name: '' }, true);
    setAdding(false);
    return ok;
  }

  const q = query.trim().toLowerCase();
  const shown = rows.filter((d) => matches(d, q));
  const fullCount = data ? data.full.length : 0;
  const refresh = html`<${Button} variant="ghost" size="sm" icon="restart" loading=${loading}
    onClick=${load} aria-label="Обновить список устройств">Обновить</${Button}>`;

  let body;
  if (!data) {
    body = error
      ? html`<${EmptyState} icon="alert" title="Не удалось получить устройства" text=${error}
          action=${html`<${Button} onClick=${load}>Повторить</${Button}>`} />`
      : html`<p class="muted srv-loading"><span class="spinner"></span> Загрузка…</p>`;
  } else {
    body = html`
      <p class="muted lan__intro">«Выборочно» — через VPN идут только сайты из «Маршрутов».
        «Всё через VPN» — весь трафик устройства, определяется по MAC-адресу.</p>
      ${data.lanError || !data.lan.length ? html`
        <p class="lan__warn tone-orange">Keenetic не отдал список устройств
          ${data.lanError ? `(${data.lanError})` : ''} — проверьте пароль Keenetic в
          ${' '}<a class="link" href="#/settings">настройках</a>.</p>` : null}
      ${rows.length ? html`
        <div class="diag__toolbar">
          <div class="diag__search">
            <${SearchInput} value=${query} onInput=${setQuery} placeholder="Имя, IP или MAC"
              label="Поиск устройства" />
          </div>
        </div>
        <p class="muted lan__meta">
          ${q ? `Найдено ${shown.length} из ${rows.length}` : `Устройств: ${rows.length}`}
          ${fullCount ? ` · всё через VPN: ${fullCount}` : ''}</p>
        ${shown.length ? html`
          <ul class="lan-list" aria-label="Устройства сети">
            ${shown.map((d) => html`<${DeviceRow} key=${d.mac} device=${d}
              full=${d.mac in pending ? pending[d.mac] : fullSet.has(d.mac)}
              busy=${d.mac in pending} error=${rowError[d.mac]}
              onChange=${(full) => setMode(d, full)} />`)}
          </ul>` : html`<${EmptyState} icon="search" title="Ничего не найдено"
            text="Поиск идёт по имени, IP и MAC-адресу." />`}
      ` : null}
      <${AddByMac} fullSet=${fullSet} busy=${adding} onAdd=${addByMac} />
    `;
  }

  return html`
    <${Card} title="Устройства сети" actions=${data ? refresh : null} class="lan-card">
      ${body}
    </${Card}>
  `;
}

export function DevicesScreen() {
  const [features, setFeatures] = useState(null);
  useEffect(() => {
    api('get_features', null, { quiet: true }).then((r) => setFeatures(r.error ? {} : r));
  }, []);
  return html`
    <div class="devices">
      <${LanDevicesCard} />
      ${features && features.wireguard !== false ? html`<${WireGuardCard} />` : null}
    </div>
  `;
}
