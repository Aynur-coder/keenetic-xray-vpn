// Устройства → WireGuard: clients of the router's WireGuard server — status by last
// handshake, traffic, «Добавить клиента», a config sheet (QR image, text, copy, .conf
// download), delete, and a WireGuard restart. Refreshes every 15 s while the tab is visible.
import { html, useState, useEffect, useRef } from '../vendor/preact-htm.js';
import { api, errorText } from '../api.js';
import { useStore } from '../store.js';
import { useVisiblePolling } from '../poll.js';
import { Badge } from '../components/badge.js';
import { Button } from '../components/button.js';
import { Card } from '../components/card.js';
import { EmptyState } from '../components/empty.js';
import { Icon } from '../components/icons.js';
import { Menu } from '../components/menu.js';
import { Sheet } from '../components/sheet.js';
import { toast } from '../components/toast.js';
import { confirm } from '../components/confirm.js';
import { formatBytes } from './diag-connections.js';
import { plural } from './add-link.js';

const REFRESH_MS = 15000;
// A peer that shook hands within 3 minutes is connected (WireGuard re-handshakes every 2).
const ONLINE_SECONDS = 180;
// Adding a peer looks up the router's public IP (up to 5 s) for the client's Endpoint.
const ADD_TIMEOUT_MS = 20000;
const RESTART_TIMEOUT_MS = 30000;

// Same rule as wg_peer_name_valid() in lib/wireguard.php.
const NAME_RE = /^[A-Za-z0-9_\-А-Яа-яЁё]{1,32}$/;
export function peerNameError(name) {
  if (!name) return 'Введите имя клиента';
  if (name.length > 32) return 'Не длиннее 32 символов';
  if (!NAME_RE.test(name) || name === 'wg0') {
    return 'Только буквы (латиница, кириллица), цифры, _ и - — без пробелов и кавычек';
  }
  return '';
}

export function agoText(seconds) {
  const s = Math.max(0, Number(seconds) || 0);
  if (s < 60) return 'только что';
  const m = Math.floor(s / 60);
  if (m < 60) return `${m} мин назад`;
  const h = Math.floor(m / 60);
  if (h < 24) return `${h} ч назад`;
  const d = Math.floor(h / 24);
  return `${d} ${plural(d, ['день', 'дня', 'дней'])} назад`;
}

function isOnline(peer) {
  return typeof peer.handshake_ago === 'number' && peer.handshake_ago < ONLINE_SECONDS;
}

function peerLabel(peer) {
  return peer.name || `без имени (${String(peer.pubkey).slice(0, 8)}…)`;
}

function PeerRow({ peer, onConfig, onDelete }) {
  const online = isOnline(peer);
  const seen = typeof peer.handshake_ago === 'number'
    ? `подключение ${agoText(peer.handshake_ago)}` : 'ещё не подключался';
  const traffic = typeof peer.rx_bytes === 'number'
    ? `↓ ${formatBytes(peer.rx_bytes)} ↑ ${formatBytes(peer.tx_bytes)}`
    : (peer.rx ? `↓ ${peer.rx} ↑ ${peer.tx}` : '');
  return html`
    <li class="wg-peer" data-name=${peer.name}>
      <span class=${`lan__dot ${online ? 'is-on' : ''}`} aria-hidden="true"></span>
      <div class="wg-peer__text">
        <span class="wg-peer__name">
          ${peer.name || html`<span class="tone-muted">Без имени</span>`}
          ${peer.ip ? html`<span class="wg-peer__ip mono">${peer.ip}</span>` : null}
          <span class="sr-only">${online ? ', подключён' : ', не подключён'}</span>
        </span>
        <span class="wg-peer__meta">
          <span>${seen}</span>${traffic ? html`<span class="mono">${traffic}</span>` : null}
        </span>
      </div>
      <${Menu} label=${`Действия: ${peerLabel(peer)}`} items=${[
        peer.has_config && { label: 'Конфиг', onSelect: () => onConfig(peer.name) },
        { label: 'Удалить', danger: true, onSelect: () => onDelete(peer) },
      ]} />
    </li>
  `;
}

// Text copy that also works over plain http (no navigator.clipboard outside secure contexts).
async function copyText(text) {
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text);
      return true;
    }
  } catch (e) { /* fall through to the textarea path */ }
  const ta = document.createElement('textarea');
  ta.value = text;
  ta.setAttribute('readonly', '');
  ta.style.position = 'fixed';
  ta.style.opacity = '0';
  document.body.appendChild(ta);
  ta.select();
  let ok = false;
  try {
    ok = document.execCommand('copy');
  } catch (e) {
    ok = false;
  }
  ta.remove();
  return ok;
}

function ConfigSheet({ name, onClose }) {
  const [state, setState] = useState({ config: null, svg: '', qrText: '', error: '' });
  const [href, setHref] = useState('');

  useEffect(() => {
    let live = true;
    Promise.all([
      api('wg_get_config', { name }, { method: 'GET', quiet: true }),
      api('wg_qrcode', { name, format: 'svg' }, { method: 'GET', quiet: true }),
    ]).then(([conf, qr]) => {
      if (!live) return;
      if (conf.error) {
        setState({ config: null, svg: '', qrText: '', error: errorText(conf.error) });
        return;
      }
      const svg = typeof qr.svg === 'string' && qr.svg.trim().startsWith('<svg') ? qr.svg : '';
      // Without SVG support the router sends qrencode's UTF-8 blocks; anything else in `qr`
      // is an error message (e.g. no qrencode at all), not a code.
      const text = !svg && typeof qr.qr === 'string' && /[█▀▄]/.test(qr.qr) ? qr.qr : '';
      setState({ config: String(conf.config || ''), svg, qrText: text, error: '' });
    });
    return () => { live = false; };
  }, [name]);

  // «Скачать .conf»: a Blob URL, released when the sheet closes.
  useEffect(() => {
    if (state.config === null) return undefined;
    const url = URL.createObjectURL(new Blob([state.config], { type: 'text/plain' }));
    setHref(url);
    return () => URL.revokeObjectURL(url);
  }, [state.config]);

  async function copy() {
    if (await copyText(state.config)) toast('Конфиг скопирован', 'success');
    else toast('Не удалось скопировать — выделите текст вручную', 'error');
  }

  let body;
  if (state.error) body = html`<p class="tone-red">${state.error}</p>`;
  else if (state.config === null) {
    body = html`<p class="muted srv-loading"><span class="spinner"></span> Загрузка…</p>`;
  } else {
    body = html`
      <p class="muted wg-conf__hint">Отсканируйте QR-код в приложении WireGuard или
        импортируйте файл .conf.</p>
      <div class="wg-conf__qr">
        ${state.svg ? html`<img class="wg-conf__img" alt=${`QR-код конфигурации ${name}`}
            src=${`data:image/svg+xml;utf8,${encodeURIComponent(state.svg)}`} />`
          : state.qrText ? html`<pre class="wg-conf__qrtext"
              aria-label=${`QR-код ${name}`}>${state.qrText}</pre>`
          : html`<p class="muted">Не удалось получить QR-код — используйте файл .conf.</p>`}
      </div>
      <pre class="wg-conf__text mono" aria-label="Текст конфигурации">${state.config}</pre>
    `;
  }

  const ready = state.config !== null && !state.error;
  return html`
    <${Sheet} open=${true} title=${`Конфиг: ${name}`} onClose=${onClose}
      footer=${html`
        <${Button} icon="copy" disabled=${!ready} onClick=${copy}>
          Копировать</${Button}>
        ${ready && href ? html`<a class="btn btn--secondary btn--md" href=${href}
          download=${`${name}.conf`}><${Icon} name="download" size=${18} />
          <span>Скачать .conf</span></a>` : null}`}>
      <div class="wg-conf">${body}</div>
    </${Sheet}>
  `;
}

function AddPeerForm({ onAdded }) {
  const [name, setName] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  async function submit(e) {
    e.preventDefault();
    const n = name.trim();
    const bad = peerNameError(n);
    setError(bad);
    if (bad) return;
    setBusy(true);
    const res = await api('wg_add_peer', { name: n }, { quiet: true, timeout: ADD_TIMEOUT_MS });
    setBusy(false);
    if (res.error) {
      setError(errorText(res.error));
      return;
    }
    setName('');
    toast(`Клиент «${res.name || n}» добавлен${res.ip ? ` (${res.ip})` : ''}`, 'success');
    onAdded(res.name || n);
  }

  return html`
    <form class="lan-add" onSubmit=${submit} novalidate>
      <label class="field__label" for="wg-name">Новый клиент</label>
      <div class="lan-add__row">
        <input id="wg-name" class="input" value=${name} placeholder="Например, Телефон_Анны"
          maxlength="64" autocomplete="off" spellcheck="false"
          aria-invalid=${error ? 'true' : undefined}
          aria-describedby=${error ? 'wg-name-err' : 'wg-name-hint'}
          onInput=${(e) => {
            const v = e.currentTarget.value;
            setName(v);
            // Point out a bad character as it is typed; «empty» waits for submit.
            setError(v.trim() ? peerNameError(v.trim()) : '');
          }} />
        <${Button} type="submit" variant="primary" icon="plus" loading=${busy}>
          Добавить клиента</${Button}>
      </div>
      ${error ? html`<span class="field__error" id="wg-name-err" role="alert">${error}</span>`
        : html`<span class="field__hint" id="wg-name-hint">
            Буквы, цифры, _ и -, до 32 символов</span>`}
    </form>
  `;
}

export function WireGuardCard() {
  const [peers, setPeers] = useState(null);
  const [error, setError] = useState('');
  const [config, setConfig] = useState(null); // peer name whose config sheet is open
  const [restarting, setRestarting] = useState(false);
  const wgUp = useStore((s) => (s.overview ? s.overview.wg_up : undefined));
  const loading = useRef(false);

  async function load() {
    if (loading.current) return;
    loading.current = true;
    const res = await api('wg_peers', null, { quiet: true });
    loading.current = false;
    if (Array.isArray(res)) {
      setPeers(res);
      setError('');
    } else {
      setError(errorText(res.error || 'Некорректный ответ роутера'));
    }
  }
  useVisiblePolling(load, REFRESH_MS);

  async function remove(peer) {
    const label = peerLabel(peer);
    const ok = await confirm({
      title: 'Удалить клиента?', danger: true, confirmLabel: 'Удалить',
      message: `Клиент «${label}» больше не сможет подключиться к WireGuard.`,
    });
    if (!ok) return;
    const who = peer.name ? { name: peer.name } : { pubkey: peer.pubkey };
    const res = await api('wg_delete_peer', who, { quiet: true });
    if (res.error) toast(`Не удалось удалить «${label}»: ${errorText(res.error)}`, 'error');
    else toast(`Клиент «${label}» удалён`, 'success');
    load();
  }

  async function restart() {
    const ok = await confirm({
      title: 'Перезапустить WireGuard?', confirmLabel: 'Перезапустить',
      message: 'Все клиенты WireGuard отключатся на несколько секунд и подключатся снова.',
    });
    if (!ok) return;
    setRestarting(true);
    const res = await api('wg_restart', {}, { quiet: true, timeout: RESTART_TIMEOUT_MS });
    setRestarting(false);
    if (res.error) toast(`Не удалось перезапустить WireGuard: ${errorText(res.error)}`, 'error');
    else toast('WireGuard перезапущен', 'success');
    load();
  }

  const online = peers ? peers.filter(isOnline).length : 0;
  let list;
  if (!peers) {
    list = error
      ? html`<${EmptyState} icon="alert" title="Не удалось получить клиентов WireGuard"
          text=${error} action=${html`<${Button} onClick=${load}>Повторить</${Button}>`} />`
      : html`<p class="muted srv-loading"><span class="spinner"></span> Загрузка…</p>`;
  } else if (!peers.length) {
    list = html`<${EmptyState} icon="lock" title="Клиентов пока нет"
      text="Клиент WireGuard — телефон или ноутбук, который подключается к домашней сети
        извне. Добавьте его и отсканируйте QR-код в приложении WireGuard." />`;
  } else {
    list = html`
      <p class="muted lan__meta">Клиентов: ${peers.length} · подключено: ${online}</p>
      ${error ? html`<p class="tone-red diag__stale">Не удалось обновить: ${error}</p>` : null}
      <ul class="wg-list" aria-label="Клиенты WireGuard">
        ${peers.map((p) => html`<${PeerRow} key=${p.pubkey || p.name} peer=${p}
          onConfig=${setConfig} onDelete=${remove} />`)}
      </ul>
    `;
  }

  const status = wgUp === undefined ? null : html`<${Badge} tone=${wgUp ? 'green' : 'neutral'}
    dot=${true}>${wgUp ? 'работает' : 'остановлен'}</${Badge}>`;
  const title = html`WireGuard ${status}`;
  return html`
    <${Card} title=${title} class="wg-card" actions=${html`
      <${Button} variant="ghost" size="sm" icon="restart" loading=${restarting}
        onClick=${restart}>Перезапустить WireGuard</${Button}>`}>
      ${list}
      <${AddPeerForm} onAdded=${(name) => { load(); setConfig(name); }} />
      ${config ? html`<${ConfigSheet} name=${config} onClose=${() => setConfig(null)} />` : null}
    </${Card}>
  `;
}
