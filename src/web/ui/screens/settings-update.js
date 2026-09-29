// Настройки → Обновления: check, «Что нового», install / rollback with live progress.
// useUpdater() owns the state and the status_update polling (every 1.5 s while a run is in
// progress) so the page can start a rollback from «Опасная зона» too; <UpdatesCard/> shows it.
import { html, useState, useEffect, useRef } from '../vendor/preact-htm.js';
import { api, errorText, refreshOverview, OFFLINE_ERROR } from '../api.js';
import { store } from '../store.js';
import { Card } from '../components/card.js';
import { Button } from '../components/button.js';
import { Icon } from '../components/icons.js';
import { Sheet } from '../components/sheet.js';
import { toast } from '../components/toast.js';
import { confirm } from '../components/confirm.js';
import { Changelog } from '../components/markdown.js';

const POLL_MS = 1500;
// update.sh --check talks to GitHub (mirrors, retries); apply_update first downloads the
// freshest updater through several mirrors before it detaches.
const CHECK_TIMEOUT_MS = 45000;
const APPLY_TIMEOUT_MS = 120000;
// A run whose state has not finished after this long is reported as stuck.
const STUCK_MS = 10 * 60 * 1000;
const RUNNING = ['starting', 'downloading', 'applying'];

// Log markers of update.sh → user-facing steps and the progress they stand for.
const STEPS = [
  { label: 'Скачивание', pct: 10, patterns: ['Скачиваю установщик', 'STEP  Resolving version',
    'STEP  Downloading release', 'STEP  Bootstrap', 'STEP  Preflight',
    'STEP  Checking Entware'] },
  { label: 'Резервная копия', pct: 38, patterns: ['STEP  Backing up'] },
  { label: 'Применение файлов', pct: 58, patterns: ['STEP  Applying files'] },
  { label: 'Миграции', pct: 78, patterns: ['STEP  Running migrations',
    'STEP  Registering auto-update'] },
  { label: 'Завершение', pct: 92, patterns: ['STEP  Restarting services',
    'services left running'] },
];

// status_update → {phase: 'running'|'done'|'failed', step, pct, message, log}.
export function progressOf(r) {
  const log = r.log_tail || '';
  const logDone = /Already at latest|Nothing to do|Установка завершена/.test(log);
  const logFailed = /\bERROR:/.test(log) && !/ERROR.*lock/i.test(log);
  let phase = 'running';
  if (r.status === 'done' || logDone) phase = 'done';
  else if (r.status === 'failed' || logFailed) phase = 'failed';
  let step = -1;
  STEPS.forEach((s, i) => {
    if (s.patterns.some((p) => log.includes(p))) step = i;
  });
  if (step < 0 && r.status === 'downloading') step = 0;
  let pct = r.status === 'applying' ? 5 : 2;
  if (step >= 0) pct = STEPS[step].pct;
  if (phase === 'done') pct = 100;
  return { phase, step, pct, message: r.message || '', log };
}

export function useUpdater() {
  const [check, setCheck] = useState({ loading: true, result: null, error: '' });
  const [job, setJob] = useState(null);
  const timer = useRef(null);
  const alive = useRef(true);
  const startedAt = useRef(0);

  function stopPolling() {
    clearInterval(timer.current);
    timer.current = null;
  }

  async function runCheck(force) {
    setCheck((c) => ({ ...c, loading: true, error: '' }));
    const r = await api('check_update', force ? { force: 1 } : null,
      { method: 'GET', quiet: true, timeout: CHECK_TIMEOUT_MS });
    if (!alive.current) return;
    if (r.error) {
      setCheck({ loading: false, result: null, error: errorText(r.error) });
      return;
    }
    setCheck({ loading: false, result: r, error: '' });
    const ov = store.get().overview;
    if (ov && ov.update_available !== !!r.available) {
      store.set({ overview: { ...ov, update_available: !!r.available } });
    }
  }

  function finish(kind, ok) {
    if (ok) {
      toast(kind === 'rollback' ? 'Откат выполнен' : 'Обновление установлено', 'success');
      const ov = store.get().overview;
      // The header dot follows overview.update_available; clear it right away.
      if (ov) store.set({ overview: { ...ov, update_available: false } });
      setCheck((c) => (c.result && kind === 'update'
        ? { ...c, result: { ...c.result, current: c.result.latest, available: false } } : c));
      refreshOverview();
    } else {
      toast(kind === 'rollback' ? 'Откат не удался' : 'Обновление не удалось', 'error');
    }
  }

  async function poll(kind) {
    const r = await api('status_update', null, { quiet: true });
    if (!alive.current || r.error) return; // services restart mid-update: keep polling
    const p = progressOf(r);
    if (p.phase === 'running' && Date.now() - startedAt.current > STUCK_MS) {
      p.phase = 'failed';
      p.message = 'Процесс обновления не отвечает больше 10 минут';
    }
    setJob((j) => (j ? { ...j, ...p } : j));
    if (p.phase !== 'running') {
      stopPolling();
      finish(kind, p.phase === 'done');
    }
  }

  function startPolling(kind) {
    stopPolling();
    startedAt.current = Date.now();
    poll(kind);
    timer.current = setInterval(() => poll(kind), POLL_MS);
  }

  async function install() {
    const r = check.result || {};
    setJob({ kind: 'update', from: r.current, to: r.latest, phase: 'running', step: -1, pct: 2,
      message: 'Запускаю обновление…', log: '' });
    const res = await api('apply_update', {}, { quiet: true, timeout: APPLY_TIMEOUT_MS });
    if (!alive.current) return;
    // Offline here usually means the request outlived the web server restart: the run
    // is going on anyway, so follow it instead of reporting a failure.
    if (res.error && res.error !== 'already_running' && res.error !== OFFLINE_ERROR) {
      setJob(null);
      toast(`Не удалось запустить обновление: ${errorText(res.error)}`, 'error');
      return;
    }
    startPolling('update');
  }

  async function rollback() {
    const ok = await confirm({
      title: 'Откатить обновление?',
      message: 'Будет восстановлена предыдущая версия из резервной копии, сделанной при '
        + 'последнем обновлении. Сервисы перезапустятся, VPN прервётся примерно на минуту.',
      confirmLabel: 'Откатить',
      danger: true,
    });
    if (!ok) return false;
    setJob({ kind: 'rollback', phase: 'running', step: -1, pct: 2,
      message: 'Запускаю откат…', log: '' });
    const res = await api('rollback_update', {}, { quiet: true });
    if (!alive.current) return true;
    if (res.error) {
      setJob(null);
      toast(`Не удалось запустить откат: ${errorText(res.error)}`, 'error');
      return true;
    }
    startPolling('rollback');
    return true;
  }

  // Coming back to the page during a run (or reloading it) picks the progress up again.
  async function resume() {
    const r = await api('status_update', null, { quiet: true });
    if (!alive.current || r.error || !RUNNING.includes(r.status)) return;
    if (Date.now() / 1000 - (r.updated_at || 0) > STUCK_MS / 1000) return;
    const kind = /откат/i.test(r.message || '') ? 'rollback' : 'update';
    setJob({ kind, ...progressOf(r) });
    startPolling(kind);
  }

  useEffect(() => {
    alive.current = true;
    runCheck(false);
    resume();
    return () => {
      alive.current = false;
      stopPolling();
    };
  }, []);

  return {
    check, job, runCheck, install, rollback, dismiss: () => setJob(null),
  };
}

function StatusLine({ check, version }) {
  const r = check.result;
  if (check.loading && !r) {
    return html`<p class="upd-status"><span class="spinner" aria-hidden="true"></span>
      Проверяю обновления…</p>`;
  }
  if (check.error) {
    return html`
      <div class="upd-status">
        <${Icon} name="alert" class="tone-red" />
        <span class="upd-status__text">
          <strong>Не удалось проверить обновления</strong>
          <span class="muted">${check.error}. Установлена версия ${version || '—'}.</span>
        </span>
      </div>`;
  }
  if (r && r.available) {
    return html`
      <div class="upd-status">
        <${Icon} name="info" class="tone-accent" />
        <span class="upd-status__text">
          <strong>Доступна версия ${r.latest}</strong>
          <span class="muted">Установлена ${r.current}. Обновление занимает около минуты,
            VPN продолжит работать.</span>
        </span>
      </div>`;
  }
  return html`
    <div class="upd-status">
      <${Icon} name="check" class="tone-green" />
      <span class="upd-status__text">
        <strong>Установлена последняя версия ${(r && r.current) || version || ''}</strong>
        <span class="muted">Обновлений нет</span>
      </span>
    </div>`;
}

function Progress({ job, onRollback, onDismiss }) {
  const title = job.kind === 'rollback' ? 'Откат обновления'
    : job.from && job.to ? `Обновление ${job.from} → ${job.to}` : 'Обновление';
  const done = job.phase === 'done';
  const failed = job.phase === 'failed';
  return html`
    <div class="upd-progress">
      <div class="upd-progress__head">
        <strong>${title}</strong>
        <span class=${`upd-progress__pct ${done ? 'tone-green' : ''}`}>${job.pct}%</span>
      </div>
      <div class="progress" role="progressbar" aria-label=${title} aria-valuemin="0"
        aria-valuemax="100" aria-valuenow=${job.pct}>
        <span class=${`progress__fill ${failed ? 'is-failed' : ''}`}
          style=${`width: ${job.pct}%`}></span>
      </div>
      ${job.kind === 'update' ? html`
        <ol class="upd-steps">
          ${STEPS.map((s, i) => {
            let state = 'pending';
            if (done || i < job.step) state = 'done';
            else if (i === job.step || (job.step < 0 && i === 0)) {
              state = failed ? 'failed' : 'active';
            }
            return html`
              <li key=${s.label} class=${`upd-step is-${state}`}>
                <span class="upd-step__icon" aria-hidden="true">
                  ${state === 'done' ? html`<${Icon} name="check" size=${16} />`
                    : state === 'active' ? html`<span class="spinner"></span>`
                      : state === 'failed' ? html`<${Icon} name="close" size=${16} />`
                        : html`<span class="upd-step__dot"></span>`}
                </span>
                <span>${s.label}</span>
                <span class="sr-only">${{ done: '— готово', active: '— выполняется',
                  failed: '— ошибка', pending: '' }[state]}</span>
              </li>`;
          })}
        </ol>` : null}
      <p class="upd-progress__msg" role="status" aria-live="polite">
        ${done ? (job.kind === 'rollback' ? 'Откат выполнен.' : 'Обновление установлено.')
          : failed ? job.message || 'Не удалось завершить.' : job.message}
      </p>
      ${job.log ? html`
        <details class="upd-log">
          <summary>Журнал</summary>
          <pre class="upd-log__text">${job.log}</pre>
        </details>` : null}
      ${done ? html`
        <div class="actions">
          <${Button} variant="primary" icon="restart" onClick=${() => location.reload()}>
            Обновить страницу</${Button}>
        </div>` : null}
      ${failed ? html`
        <div class="actions">
          ${job.kind === 'update' ? html`
            <${Button} variant="secondary" onClick=${onRollback}>Откатить</${Button}>` : null}
          <${Button} variant="ghost" onClick=${onDismiss}>Закрыть</${Button}>
        </div>` : null}
    </div>
  `;
}

function ChangelogSheet({ open, onClose, fallback, current }) {
  // {markdown, current} | {error}. `fallback` = check_update's section for the new version
  // (update.sh prints it without its «## [x]» heading, so the caller adds one).
  const [data, setData] = useState(null);
  useEffect(() => {
    if (!open || (data && !data.error)) return;
    let live = true;
    setData(null);
    api('changelog_full', null, { quiet: true, timeout: CHECK_TIMEOUT_MS }).then((r) => {
      if (live) setData(r.error || !r.markdown ? { error: true } : r);
    });
    return () => {
      live = false;
    };
  }, [open]);

  let body;
  if (!data) {
    body = html`<p class="upd-status"><span class="spinner" aria-hidden="true"></span>
      Загружаю историю изменений…</p>`;
  } else if (data.error && fallback) {
    body = html`<${Changelog} markdown=${fallback} />`;
  } else if (data.error) {
    body = html`<p class="muted">Не удалось загрузить историю изменений.</p>`;
  } else {
    body = html`<${Changelog} markdown=${data.markdown} current=${data.current || current} />`;
  }
  return html`
    <${Sheet} open=${open} title="Что нового" onClose=${onClose}>${body}</${Sheet}>
  `;
}

export function UpdatesCard({ id, updater, version }) {
  const { check, job, runCheck, install, rollback, dismiss } = updater;
  const [notesOpen, setNotesOpen] = useState(false);
  const r = check.result;
  const running = !!job && job.phase === 'running';
  return html`
    <${Card} id=${id} title="Обновления" class="settings__card">
      <${StatusLine} check=${check} version=${version} />
      ${job ? html`<${Progress} job=${job} onRollback=${rollback} onDismiss=${dismiss} />` : null}
      <div class="actions">
        ${r && r.available && !job ? html`
          <${Button} variant="primary" icon="play" onClick=${install}>
            Установить ${r.latest}</${Button}>` : null}
        <${Button} variant="secondary" icon="restart" loading=${check.loading && !!r}
          disabled=${running || (check.loading && !r)} onClick=${() => runCheck(true)}>
          Проверить</${Button}>
        <${Button} variant="secondary" onClick=${() => setNotesOpen(true)}>Что нового</${Button}>
        <${Button} variant="ghost" disabled=${running} onClick=${rollback}>Откатить…</${Button}>
      </div>
      <${ChangelogSheet} open=${notesOpen} onClose=${() => setNotesOpen(false)}
        fallback=${r && r.changelog ? `## [${r.latest}]\n${r.changelog}` : ''}
        current=${(r && r.current) || version} />
    </${Card}>
  `;
}
