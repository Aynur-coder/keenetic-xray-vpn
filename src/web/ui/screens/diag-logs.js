// Диагностика → Сырые логи: the tail of one log file (api `logs?source=`), errors in red and
// warnings in orange, optional auto-refresh, «Очистить» for the Xray logs (`clear_logs`).
// Sticks to the bottom as new lines arrive unless the user has scrolled up to read.
import { html, useState, useRef, useEffect, useLayoutEffect } from '../vendor/preact-htm.js';
import { api, errorText } from '../api.js';
import { useVisiblePolling } from '../poll.js';
import { Button } from '../components/button.js';
import { Card } from '../components/card.js';
import { Toggle } from '../components/toggle.js';
import { toast } from '../components/toast.js';
import { confirm } from '../components/confirm.js';

const REFRESH_MS = 5000;
const SOURCES = [
  { value: 'xray_error', label: 'Xray — ошибки' },
  { value: 'xray_access', label: 'Xray — доступ' },
  { value: 'adguard', label: 'AdGuard Home' },
  { value: 'manager', label: 'Менеджер VPN' },
  { value: 'update', label: 'Обновления' },
];
const LINES = [50, 100, 200, 500];
// Written only while «Сырые логи Xray» (logs_enabled) is on; clear_logs empties exactly these.
const XRAY_SOURCES = new Set(['xray_error', 'xray_access']);
// Within this many px of the bottom counts as «at the bottom».
const STICK_PX = 24;

// 'error' | 'warn' | '' for one log line: Xray's [Error]/[Warning], AdGuard's [error]/[warn],
// and plain «error …»/«warning …» text.
export function lineLevel(line) {
  if (/\b(error|fatal)/i.test(line)) return 'error';
  if (/\bwarn/i.test(line)) return 'warn';
  return '';
}

// xrayLogs: features.logs_enabled — when off, the Xray sources show a hint instead of a
// (stale or empty) file, and the tab opens on AdGuard.
export function LogsTab({ xrayLogs = true }) {
  const [source, setSource] = useState(xrayLogs ? 'xray_error' : 'adguard');
  const [lines, setLines] = useState(100);
  const [auto, setAuto] = useState(false);
  const [state, setState] = useState({ lines: null, error: '' });
  const [clearing, setClearing] = useState(false);
  const box = useRef(null);
  const stick = useRef(true); // follow new lines (false once the user scrolls up)
  const seq = useRef(0);

  const xrayOff = !xrayLogs && XRAY_SOURCES.has(source);

  async function load() {
    if (xrayOff) return;
    const my = ++seq.current;
    const res = await api('logs', { source, lines }, { method: 'GET', quiet: true });
    if (my !== seq.current) return;
    if (!Array.isArray(res)) {
      setState((s) => ({ ...s, error: errorText(res.error || 'Некорректный ответ роутера') }));
      return;
    }
    const list = res.map(String);
    while (list.length && list[list.length - 1].trim() === '') list.pop();
    setState({ lines: list, error: '' });
  }
  useVisiblePolling(load, REFRESH_MS, auto && !xrayOff, [source, lines]);
  useEffect(() => {
    if (!auto) load(); // without auto-refresh: once per source / line count
  }, [source, lines]);

  useLayoutEffect(() => {
    const el = box.current;
    if (el && stick.current) el.scrollTop = el.scrollHeight;
  }, [state.lines]);

  function onScroll(e) {
    const el = e.currentTarget;
    stick.current = el.scrollHeight - el.scrollTop - el.clientHeight <= STICK_PX;
  }

  function pick(fn, value) {
    stick.current = true;
    setState({ lines: null, error: '' });
    fn(value);
  }

  async function onClear() {
    const ok = await confirm({
      title: 'Очистить логи Xray?',
      message: 'Файлы ошибок и доступа Xray будут очищены. Отменить это нельзя.',
      confirmLabel: 'Очистить',
      danger: true,
    });
    if (!ok) return;
    setClearing(true);
    const res = await api('clear_logs', {}, { quiet: true });
    setClearing(false);
    if (res.error) {
      toast(`Не удалось очистить логи: ${errorText(res.error)}`, 'error');
      return;
    }
    toast('Логи Xray очищены', 'success');
    stick.current = true;
    load();
  }

  const list = state.lines;
  return html`
    <${Card} class="diag-card logs">
      <div class="logs__controls">
        <label class="field logs__field">
          <span class="field__label">Источник</span>
          <select class="select logs__source" value=${source}
            onChange=${(e) => pick(setSource, e.currentTarget.value)}>
            ${SOURCES.map((s) => html`<option key=${s.value} value=${s.value}>${s.label}</option>`)}
          </select>
        </label>
        <label class="field logs__field">
          <span class="field__label">Строк</span>
          <select class="select logs__lines" value=${String(lines)}
            onChange=${(e) => pick(setLines, Number(e.currentTarget.value))}>
            ${LINES.map((n) => html`<option key=${n} value=${String(n)}>${n}</option>`)}
          </select>
        </label>
        <div class="logs__auto">
          <${Toggle} id="logs-auto" checked=${auto} label="Автообновление"
            onChange=${setAuto} />
          <label class="logs__auto-label" for="logs-auto">Автообновление</label>
        </div>
        <div class="logs__actions">
          <${Button} icon="restart" disabled=${xrayOff}
            onClick=${() => { stick.current = true; load(); }}>Обновить</${Button}>
          ${XRAY_SOURCES.has(source) && !xrayOff ? html`
            <${Button} variant="danger-outline" loading=${clearing} onClick=${onClear}>
              Очистить</${Button}>` : null}
        </div>
      </div>
      ${xrayOff ? html`
        <div class="logs__off" role="status">
          <p><strong>Запись логов Xray выключена</strong></p>
          <p class="muted">Включите «Сырые логи Xray» в настройках, чтобы Xray писал ошибки и
            доступ. Логи AdGuard, менеджера и обновлений доступны и без этого.</p>
          <a class="btn btn--secondary btn--md" href="#/settings">Открыть настройки</a>
        </div>` : html`
      ${state.error ? html`<p class="tone-red diag__stale" role="alert">
        ${list ? 'Не удалось обновить' : 'Не удалось загрузить лог'}: ${state.error}</p>` : null}
      <div class="logs__box mono" ref=${box} onScroll=${onScroll} tabindex="0"
        role="log" aria-label="Содержимое лога" aria-busy=${list === null ? 'true' : 'false'}>
        ${list === null ? (state.error ? null
            : html`<p class="muted"><span class="spinner"></span> Загрузка…</p>`)
          : list.length === 0 ? html`<p class="muted logs__empty">Лог пуст.</p>`
          : list.map((ln, i) => {
            const lvl = lineLevel(ln);
            return html`<div key=${i} class=${`logs__line ${lvl ? `logs__line--${lvl}` : ''}`}>${
              ln || ' '}</div>`;
          })}
      </div>`}
    </${Card}>
  `;
}
