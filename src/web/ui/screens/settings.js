// Настройки: one scrollable page of section cards — Общие, Обновления, Функции, Диагностика,
// Безопасность, Опасная зона (+ Сеанс for remote sessions). Desktop gets a small section nav
// on the left; on a phone the cards are simply stacked.
import { html, useState, useEffect } from '../vendor/preact-htm.js';
import { api, errorText, refreshOverview } from '../api.js';
import { useStore } from '../store.js';
import { setTheme } from '../theme.js';
import { Card } from '../components/card.js';
import { Button } from '../components/button.js';
import { Toggle } from '../components/toggle.js';
import { toast } from '../components/toast.js';
import { confirm } from '../components/confirm.js';
import { useUpdater, UpdatesCard } from './settings-update.js';
import { waitText } from './login.js';

const THEMES = [['auto', 'Авто'], ['light', 'Светлая'], ['dark', 'Тёмная']];
// logs_enabled regenerates the config and restarts Xray under the 60 s apply lock.
const APPLY_TIMEOUT_MS = 70000;
// test_kn_password logs in to the Keenetic API and lists devices.
const KN_TIMEOUT_MS = 30000;
const MIN_PASSWORD = 4;
// set_ui_password's refusal for a missing/wrong current password (shared limiter with login).
const CURRENT_WRONG = 'Неверный текущий пароль';

const FEATURES = {
  wireguard: { label: 'WireGuard', hint: 'VPN-сервер для подключения к домашней сети извне',
    on: 'WireGuard включён', off: 'WireGuard выключен' },
  adguard: { label: 'AdGuard', hint: 'DNS через AdGuard Home — нужен для правил по доменам',
    on: 'AdGuard включён', off: 'AdGuard выключен' },
  auto_update: { label: 'Автообновление', hint: 'Раз в сутки ставить новую версию самостоятельно',
    on: 'Автообновление включено', off: 'Автообновление выключено' },
  logs_enabled: { label: 'Сырые логи Xray',
    hint: 'Запись error/access-логов Xray. При переключении Xray перезапускается',
    on: 'Логи Xray включены', off: 'Логи Xray выключены' },
};

function FeatureRow({ id, features, busy, onToggle }) {
  const f = FEATURES[id];
  const ready = !!features;
  return html`
    <div class="setting">
      <div class="setting__text">
        <span class="setting__label" id=${`feat-${id}`}>${f.label}</span>
        <span class="setting__hint">${f.hint}</span>
      </div>
      ${busy[id] ? html`<span class="spinner setting__spin" aria-hidden="true"></span>` : null}
      <${Toggle} checked=${ready && !!features[id]} label=${f.label}
        disabled=${!ready || !!busy[id]} onChange=${(v) => onToggle(id, v)} />
    </div>
  `;
}

function useFeatures() {
  const [features, setFeatures] = useState(null);
  const [busy, setBusy] = useState({}); // {feature id: true} while its request runs

  useEffect(() => {
    api('get_features', null, { quiet: true }).then((r) => {
      if (!r.error) setFeatures(r);
    });
  }, []);

  async function toggle(id, value) {
    const f = FEATURES[id];
    setBusy((b) => ({ ...b, [id]: true }));
    setFeatures((cur) => ({ ...cur, [id]: value }));
    const res = await api('set_features', { [id]: value }, {
      quiet: true, timeout: id === 'logs_enabled' ? APPLY_TIMEOUT_MS : 20000,
    });
    setBusy((b) => ({ ...b, [id]: false }));
    if (res.error) {
      setFeatures((cur) => ({ ...cur, [id]: !value }));
      toast(`Не удалось изменить «${f.label}»: ${errorText(res.error)}`, 'error');
    } else if (res.xray_running === false) {
      // Saved, but the restart that applies it left Xray down.
      toast('Настройка сохранена, но Xray не запустился — подробности в событиях', 'error');
    } else {
      toast(value ? f.on : f.off, 'success');
    }
    refreshOverview();
  }
  return { features, busy, toggle };
}

function GeneralCard({ id }) {
  const theme = useStore((s) => s.theme);
  const version = useStore((s) => (s.overview ? s.overview.version : ''));
  return html`
    <${Card} id=${id} title="Общие" class="settings__card">
      <div class="setting">
        <div class="setting__text">
          <span class="setting__label" id="theme-label">Тема</span>
          <span class="setting__hint">«Авто» следует за настройкой системы</span>
        </div>
        <div class="segmented" role="radiogroup" aria-labelledby="theme-label">
          ${THEMES.map(([value, label]) => html`
            <button key=${value} type="button" role="radio" class="segmented__item"
              aria-checked=${theme === value ? 'true' : 'false'}
              onClick=${() => setTheme(value)}>${label}</button>
          `)}
        </div>
      </div>
      <div class="setting">
        <div class="setting__text">
          <span class="setting__label">Версия</span>
          <span class="setting__hint">VKeen — Xray VPN для Keenetic</span>
        </div>
        <span class="mono">${version || '—'}</span>
      </div>
    </${Card}>
  `;
}

// One labelled input with its inline error (aria-describedby / aria-invalid).
function Field({ id, label, value, onInput, error, autocomplete, hint }) {
  const describedBy = [error ? `${id}-err` : '', hint ? `${id}-hint` : ''].join(' ').trim();
  return html`
    <div class="field">
      <label class="field__label" for=${id}>${label}</label>
      <input id=${id} class="input" type="password" autocomplete=${autocomplete}
        value=${value} onInput=${(e) => onInput(e.currentTarget.value)}
        aria-invalid=${error ? 'true' : undefined}
        aria-describedby=${describedBy || undefined} />
      ${hint ? html`<span class="field__hint" id=${`${id}-hint`}>${hint}</span>` : null}
      ${error ? html`<span class="field__error" id=${`${id}-err`}>${error}</span>` : null}
    </div>
  `;
}

function validatePanel(form, needCurrent) {
  const errors = {};
  if (needCurrent && !form.current) errors.current = 'Введите текущий пароль';
  if (form.next.length < MIN_PASSWORD) errors.next = `Минимум ${MIN_PASSWORD} символа`;
  if (!form.repeat) errors.repeat = 'Повторите новый пароль';
  else if (form.repeat !== form.next) errors.repeat = 'Пароли не совпадают';
  return errors;
}

function PanelPasswordForm({ auth, onChanged }) {
  const empty = { current: '', next: '', repeat: '' };
  const [form, setForm] = useState(empty);
  const [errors, setErrors] = useState({});
  const [tried, setTried] = useState(false);
  const [busy, setBusy] = useState(false);
  // Inside the home network the panel opens without a password, so whoever is here may
  // (re)set it — that is also the only way back in after forgetting it. Remote sessions
  // must prove the current one.
  const needCurrent = !!(auth && auth.password_set && !auth.local);

  function update(key, value) {
    const next = { ...form, [key]: value };
    setForm(next);
    if (tried) setErrors(validatePanel(next, needCurrent));
  }

  async function onSubmit(e) {
    e.preventDefault();
    setTried(true);
    const errs = validatePanel(form, needCurrent);
    setErrors(errs);
    if (Object.keys(errs).length) return;
    setBusy(true);
    const data = { password: form.next };
    if (needCurrent) data.current = form.current;
    const res = await api('set_ui_password', data, { quiet: true });
    setBusy(false);
    if (res.error === CURRENT_WRONG) {
      setErrors({ current: CURRENT_WRONG });
      return;
    }
    if (res.error === 'too_many_attempts') {
      const wait = res.retry_after ? ` Повторите через ${waitText(res.retry_after)}.` : '';
      setErrors({ current: `Слишком много неверных попыток.${wait}` });
      return;
    }
    if (res.error === 'password_too_short') {
      setErrors({ next: `Минимум ${MIN_PASSWORD} символа` });
      return;
    }
    if (res.error) {
      setErrors({ form: errorText(res.error) });
      return;
    }
    setForm(empty);
    setErrors({});
    setTried(false);
    toast(auth && auth.password_set ? 'Пароль панели изменён' : 'Пароль панели задан', 'success');
    onChanged();
  }

  const isSet = !!(auth && auth.password_set);
  return html`
    <form class="form settings__form" onSubmit=${onSubmit} noValidate
      aria-labelledby="pw-panel-title">
      <div class="setting__text">
        <h3 class="settings__sub" id="pw-panel-title">Пароль панели</h3>
        <span class="setting__hint">
          ${isSet ? 'Нужен для входа в панель не из домашней сети.'
            : 'Пароль не задан — извне панель недоступна. Задайте его, чтобы входить удалённо.'}
        </span>
      </div>
      ${needCurrent ? html`
        <${Field} id="pw-current" label="Текущий пароль" value=${form.current}
          autocomplete="current-password" error=${errors.current}
          onInput=${(v) => update('current', v)} />` : null}
      <${Field} id="pw-next" label="Новый пароль" value=${form.next}
        autocomplete="new-password" error=${errors.next}
        hint=${`Не короче ${MIN_PASSWORD} символов`}
        onInput=${(v) => update('next', v)} />
      <${Field} id="pw-repeat" label="Повторите пароль" value=${form.repeat}
        autocomplete="new-password" error=${errors.repeat}
        onInput=${(v) => update('repeat', v)} />
      ${errors.form ? html`<p class="form__error" role="alert">${errors.form}</p>` : null}
      <${Button} type="submit" variant="primary" loading=${busy}>
        ${isSet ? 'Сменить пароль' : 'Задать пароль'}</${Button}>
    </form>
  `;
}

function KeeneticPasswordForm({ knSet, onSaved }) {
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState('');

  async function onSubmit(e) {
    e.preventDefault();
    if (!password) {
      setError('Введите пароль');
      return;
    }
    setError('');
    setBusy('test');
    const t = await api('test_kn_password', { password }, { quiet: true, timeout: KN_TIMEOUT_MS });
    if (t.error || !t.ok) {
      setBusy('');
      setError(t.error ? errorText(t.error)
        : 'Пароль не подошёл: Keenetic не отдал список устройств');
      return;
    }
    setBusy('save');
    const s = await api('set_kn_password', { password }, { quiet: true });
    setBusy('');
    if (s.error) {
      setError(errorText(s.error));
      return;
    }
    setPassword('');
    toast(`Пароль Keenetic сохранён — найдено устройств: ${t.count || 0}`, 'success');
    onSaved();
  }

  return html`
    <form class="form settings__form" onSubmit=${onSubmit} noValidate
      aria-labelledby="pw-kn-title">
      <div class="setting__text">
        <h3 class="settings__sub" id="pw-kn-title">Пароль Keenetic</h3>
        <span class="setting__hint">
          Пароль admin от веб-интерфейса роутера — нужен, чтобы видеть устройства сети.
          ${knSet === true ? ' Сейчас сохранён.' : knSet === false ? ' Сейчас не задан.' : ''}
        </span>
      </div>
      <${Field} id="pw-kn" label="Пароль admin" value=${password} autocomplete="off"
        error=${error} onInput=${(v) => { setPassword(v); setError(''); }} />
      <${Button} type="submit" variant="primary" loading=${!!busy}>
        ${busy === 'test' ? 'Проверяю…' : busy === 'save' ? 'Сохраняю…' : 'Проверить и сохранить'}
      </${Button}>
    </form>
  `;
}

function SecurityCard({ id, auth, knSet, reload }) {
  return html`
    <${Card} id=${id} title="Безопасность" class="settings__card">
      <div class="settings__forms">
        <${PanelPasswordForm} auth=${auth} onChanged=${reload} />
        <${KeeneticPasswordForm} knSet=${knSet} onSaved=${reload} />
      </div>
    </${Card}>
  `;
}

function DangerCard({ id, updater }) {
  const [busy, setBusy] = useState(false);
  const running = !!updater.job && updater.job.phase === 'running';

  async function onRollback() {
    if (await updater.rollback()) {
      const card = document.getElementById('s-updates');
      if (card) card.scrollIntoView({ block: 'start' });
    }
  }

  async function onWizard() {
    const ok = await confirm({
      title: 'Запустить мастер настройки заново?',
      message: 'Откроется мастер первоначальной настройки (в старом интерфейсе). Ключи, '
        + 'подписки, правила и WireGuard сохранятся — сбрасывается только отметка '
        + '«настройка завершена».',
      confirmLabel: 'Запустить мастер',
      danger: true,
    });
    if (!ok) return;
    setBusy(true);
    const res = await api('reset_onboarding', {});
    setBusy(false);
    if (!res.error) location.href = 'legacy.php';
  }

  return html`
    <${Card} id=${id} title="Опасная зона" class="settings__card settings__danger">
      <div class="setting">
        <div class="setting__text">
          <span class="setting__label">Откатить обновление</span>
          <span class="setting__hint">Вернуть версию, стоявшую до последнего обновления</span>
        </div>
        <${Button} variant="danger-outline" disabled=${running} onClick=${onRollback}>
          Откатить</${Button}>
      </div>
      <div class="setting">
        <div class="setting__text">
          <span class="setting__label">Мастер настройки</span>
          <span class="setting__hint">Пройти первоначальную настройку заново</span>
        </div>
        <${Button} variant="danger-outline" loading=${busy} onClick=${onWizard}>
          Запустить заново</${Button}>
      </div>
    </${Card}>
  `;
}

function SessionCard({ id }) {
  const [busy, setBusy] = useState(false);
  async function onLogout() {
    setBusy(true);
    const res = await api('logout', {});
    setBusy(false);
    if (!res.error) location.hash = '#/login';
  }
  return html`
    <${Card} id=${id} title="Сеанс" class="settings__card">
      <div class="setting">
        <div class="setting__text">
          <span class="setting__label">Вы вошли по паролю</span>
          <span class="setting__hint">Выход завершит сеанс в этом браузере</span>
        </div>
        <${Button} variant="secondary" loading=${busy} onClick=${onLogout}>Выйти</${Button}>
      </div>
    </${Card}>
  `;
}

export function SettingsScreen() {
  const version = useStore((s) => (s.overview ? s.overview.version : ''));
  const updater = useUpdater();
  const { features, busy, toggle } = useFeatures();
  const [auth, setAuth] = useState(null);
  const [knSet, setKnSet] = useState(null);

  function loadAuth() {
    api('auth_status', null, { quiet: true }).then((r) => {
      if (!r.error) setAuth(r);
    });
    api('get_onboarding_status', null, { quiet: true }).then((r) => {
      if (!r.error) setKnSet(!!r.kn_pass_set);
    });
  }
  useEffect(loadAuth, []);

  const remote = !!auth && !auth.local;
  const sections = [
    ['s-general', 'Общие'], ['s-updates', 'Обновления'], ['s-features', 'Функции'],
    ['s-diagnostics', 'Диагностика'], ['s-security', 'Безопасность'],
    ['s-danger', 'Опасная зона'], remote && ['s-session', 'Сеанс'],
  ].filter(Boolean);

  function jump(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.scrollIntoView({ block: 'start' });
    const title = el.querySelector('.card__title');
    if (title) {
      title.setAttribute('tabindex', '-1');
      title.focus({ preventScroll: true });
    }
  }

  const rowProps = { features, busy, onToggle: toggle };
  return html`
    <div class="settings">
      <nav class="settings__nav" aria-label="Разделы настроек">
        ${sections.map(([id, label]) => html`
          <button key=${id} type="button" class="settings__navitem"
            onClick=${() => jump(id)}>${label}</button>`)}
      </nav>
      <div class="settings__cards">
        <${GeneralCard} id="s-general" />
        <${UpdatesCard} id="s-updates" updater=${updater} version=${version} />
        <${Card} id="s-features" title="Функции" class="settings__card">
          <${FeatureRow} id="wireguard" ...${rowProps} />
          <${FeatureRow} id="adguard" ...${rowProps} />
          <${FeatureRow} id="auto_update" ...${rowProps} />
        </${Card}>
        <${Card} id="s-diagnostics" title="Диагностика" class="settings__card">
          <${FeatureRow} id="logs_enabled" ...${rowProps} />
          <p class="muted settings__note">Остальные инструменты диагностики появятся позже.</p>
        </${Card}>
        <${SecurityCard} id="s-security" auth=${auth} knSet=${knSet} reload=${loadAuth} />
        <${DangerCard} id="s-danger" updater=${updater} />
        ${remote ? html`<${SessionCard} id="s-session" />` : null}
      </div>
    </div>
  `;
}
