// Настройки: one scrollable page of section cards — Общие, Обновления, Функции, Диагностика,
// Безопасность, Опасная зона (+ Сеанс for remote sessions). Desktop gets a small section nav
// on the left; on a phone the cards are simply stacked.
import { html, useState, useEffect } from '../vendor/preact-htm.js';
import { api, errorText, refreshOverview } from '../api.js';
import { useStore } from '../store.js';
import { setTheme } from '../theme.js';
import { Card } from '../components/card.js';
import { Button } from '../components/button.js';
import { Icon } from '../components/icons.js';
import { Toggle } from '../components/toggle.js';
import { toast } from '../components/toast.js';
import { confirm } from '../components/confirm.js';
import { useUpdater, UpdatesCard } from './settings-update.js';
import {
  usePanelPassword, PanelPasswordFields, useKeeneticPassword, KeeneticPasswordField, knBusyLabel,
} from './password-forms.js';

const THEMES = [['auto', 'Авто'], ['light', 'Светлая'], ['dark', 'Тёмная']];
// logs_enabled regenerates the config and restarts Xray under the 60 s apply lock.
const APPLY_TIMEOUT_MS = 70000;

const FEATURES = {
  wireguard: { label: 'WireGuard', hint: 'VPN-сервер для подключения к домашней сети извне',
    on: 'WireGuard включён', off: 'WireGuard выключен' },
  adguard: { label: 'AdGuard', hint: 'DNS через AdGuard Home — нужен для правил по доменам',
    on: 'AdGuard включён', off: 'AdGuard выключен' },
  auto_update: { label: 'Автообновление', hint: 'Раз в сутки ставить новую версию самостоятельно',
    on: 'Автообновление включено', off: 'Автообновление выключено' },
  diag_events: { label: 'Журнал событий',
    hint: 'Запись и просмотр событий: смена сервера, перезапуски, ошибки, watchdog',
    on: 'Журнал событий включён', off: 'Журнал событий выключен' },
  diag_connections: { label: 'Соединения',
    hint: 'Живые соединения устройств сети: домен, маршрут, утечки мимо VPN',
    on: 'Соединения включены', off: 'Соединения выключены' },
  diag_site_check: { label: 'Проверка сайта',
    hint: 'Куда уходит сайт и какую страну видят Google и Cloudflare',
    on: 'Проверка сайта включена', off: 'Проверка сайта выключена' },
  logs_enabled: { label: 'Сырые логи Xray',
    hint: 'Запись error/access-логов Xray. При переключении Xray перезапускается',
    on: 'Логи Xray включены', off: 'Логи Xray выключены' },
};

function FeatureRow({ id, features, busy, onToggle }) {
  const f = FEATURES[id];
  const ready = !!features;
  const pending = !ready || !!busy[id];
  return html`
    <div class="setting">
      <div class="setting__text">
        <span class="setting__label" id=${`feat-${id}`}>${f.label}</span>
        <span class="setting__hint">${f.hint}</span>
      </div>
      ${pending ? html`<span class="spinner setting__spin" aria-hidden="true"></span>` : null}
      <${Toggle} checked=${ready && !!features[id]} label=${f.label}
        disabled=${pending} busy=${pending} onChange=${(v) => onToggle(id, v)} />
    </div>
  `;
}

// Shown in place of the toggles' section while get_features has failed — never a fake OFF.
function FeatureLoadError({ error, onRetry }) {
  return html`
    <div class="upd-status">
      <${Icon} name="alert" class="tone-red" />
      <span class="upd-status__text">
        <strong>Не удалось загрузить функции</strong>
        <span class="muted">${error}</span>
      </span>
    </div>
    <div class="actions">
      <${Button} variant="secondary" onClick=${onRetry}>Повторить</${Button}>
    </div>
  `;
}

function useFeatures() {
  const [features, setFeatures] = useState(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState({}); // {feature id: true} while its request runs

  function load() {
    setError('');
    api('get_features', null, { quiet: true }).then((r) => {
      if (r.error) setError(errorText(r.error));
      else setFeatures(r);
    });
  }
  useEffect(load, []);

  async function toggle(id, value) {
    if (!features) return; // still loading (or failed) — nothing real to flip yet
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
  return { features, error, busy, toggle, reload: load };
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
      <div class="setting">
        <div class="setting__text">
          <span class="setting__label">Старый интерфейс</span>
          <span class="setting__hint">Прежняя версия панели — на случай, если здесь чего-то
            не хватает</span>
        </div>
        <a class="btn btn--secondary btn--md" href="legacy.php">
          <span>Открыть</span><${Icon} name="external" size=${16} /></a>
      </div>
    </${Card}>
  `;
}

function PanelPasswordForm({ auth, onChanged }) {
  const pw = usePanelPassword(auth);

  async function onSubmit(e) {
    e.preventDefault();
    if (!(await pw.submit())) return;
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
      <${PanelPasswordFields} pw=${pw} />
      <${Button} type="submit" variant="primary" loading=${pw.busy}>
        ${isSet ? 'Сменить пароль' : 'Задать пароль'}</${Button}>
    </form>
  `;
}

function KeeneticPasswordForm({ knSet, onSaved }) {
  const kn = useKeeneticPassword();

  async function onSubmit(e) {
    e.preventDefault();
    const count = await kn.submit();
    if (count === null) return;
    toast(`Пароль Keenetic сохранён — найдено устройств: ${count}`, 'success');
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
      <${KeeneticPasswordField} kn=${kn} />
      <${Button} type="submit" variant="primary" loading=${!!kn.busy}>
        ${knBusyLabel(kn.busy, 'Проверить и сохранить')}
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
      message: 'Откроется мастер первоначальной настройки. Ключи, подписки, правила '
        + 'и WireGuard сохранятся — сбрасывается только отметка «настройка завершена».',
      confirmLabel: 'Запустить мастер',
      danger: true,
    });
    if (!ok) return;
    setBusy(true);
    const res = await api('reset_onboarding', {});
    setBusy(false);
    if (!res.error) location.hash = '#/setup';
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
  const { features, error: featuresError, busy, toggle, reload: reloadFeatures } = useFeatures();
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
          ${featuresError
            ? html`<${FeatureLoadError} error=${featuresError} onRetry=${reloadFeatures} />`
            : html`
              <${FeatureRow} id="wireguard" ...${rowProps} />
              <${FeatureRow} id="adguard" ...${rowProps} />
              <${FeatureRow} id="auto_update" ...${rowProps} />
            `}
        </${Card}>
        <${Card} id="s-diagnostics" title="Диагностика" class="settings__card">
          ${featuresError
            ? html`<${FeatureLoadError} error=${featuresError} onRetry=${reloadFeatures} />`
            : html`
              <${FeatureRow} id="diag_events" ...${rowProps} />
              <${FeatureRow} id="diag_connections" ...${rowProps} />
              <${FeatureRow} id="diag_site_check" ...${rowProps} />
              <${FeatureRow} id="logs_enabled" ...${rowProps} />
            `}
        </${Card}>
        <${SecurityCard} id="s-security" auth=${auth} knSet=${knSet} reload=${loadAuth} />
        <${DangerCard} id="s-danger" updater=${updater} />
        ${remote ? html`<${SessionCard} id="s-session" />` : null}
      </div>
    </div>
  `;
}
