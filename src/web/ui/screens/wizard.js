// Мастер настройки (#/setup): first-run setup in five steps, full-screen without navigation.
// Port of the legacy wizard: 1 panel password, 2 Keenetic password (test, then save),
// 3 subscriptions/keys (add_link), 4 server choice (select_server starts Xray),
// 5 WireGuard on/off → set_features, complete_onboarding, start. Every step can be skipped;
// errors stay inline; «Далее» and friends are disabled while a request runs.
import { html, useState, useEffect, useRef } from '../vendor/preact-htm.js';
import { api, errorText, goToLogin, refreshOverview, refreshServers } from '../api.js';
import { Button } from '../components/button.js';
import { Icon } from '../components/icons.js';
import { Toggle } from '../components/toggle.js';
import { EmptyState } from '../components/empty.js';
import { toast } from '../components/toast.js';
import {
  usePanelPassword, PanelPasswordFields, useKeeneticPassword, KeeneticPasswordField, knBusyLabel,
} from './password-forms.js';
import { useAddLink, AddLinkFields, AddLinkSummary } from './add-link.js';
import { ServerList } from './server-picker.js';

const STEPS = ['Пароль панели', 'Пароль Keenetic', 'Подписка или ключ', 'Выбор сервера',
  'WireGuard'];
// `start` and set_features' apply wait behind the 60 s apply lock.
const APPLY_TIMEOUT_MS = 70000;

function Progress({ step }) {
  return html`
    <ol class="wizard__steps" aria-label="Шаги настройки">
      ${STEPS.map((title, i) => html`
        <li key=${i} class=${`wizard__step ${i < step ? 'is-done' : ''} ${i === step
          ? 'is-current' : ''}`} aria-current=${i === step ? 'step' : undefined}>
          <span class="wizard__dot" aria-hidden="true">
            ${i < step ? html`<${Icon} name="check" size=${14} />` : i + 1}</span>
          <span class="wizard__label">${title}</span>
        </li>
      `)}
    </ol>
  `;
}

// Назад · Пропустить · Далее. The primary button submits the step's <form>.
function Footer({ nav, busy, next = 'Далее', skip = 'Пропустить', onSkip }) {
  return html`
    <div class="wizard__foot">
      ${nav.back ? html`
        <${Button} variant="ghost" class="wizard__back" disabled=${busy} onClick=${nav.back}>
          Назад</${Button}>` : null}
      <${Button} variant="ghost" disabled=${busy} onClick=${onSkip || nav.next}>${skip}</${Button}>
      <${Button} type="submit" variant="primary" loading=${busy}>${next}</${Button}>
    </div>
  `;
}

function PanelStep({ nav, auth, onSaved }) {
  const pw = usePanelPassword(auth);
  const isSet = !!(auth && auth.password_set);

  async function onSubmit(e) {
    e.preventDefault();
    if (!(await pw.submit())) return;
    toast(isSet ? 'Пароль панели изменён' : 'Пароль панели задан', 'success');
    onSaved();
    nav.next();
  }

  return html`
    <form class="form wizard__form" onSubmit=${onSubmit} noValidate>
      <p class="muted">
        Нужен, чтобы открывать панель не из домашней сети — например, через WireGuard.
        ${isSet ? ' Пароль уже задан — можно сменить его или пропустить шаг.' : ''}
      </p>
      <${PanelPasswordFields} pw=${pw} />
      <${Footer} nav=${nav} busy=${pw.busy} />
    </form>
  `;
}

function KeeneticStep({ nav, knSet, onSaved }) {
  const kn = useKeeneticPassword();

  async function onSubmit(e) {
    e.preventDefault();
    const count = await kn.submit();
    if (count === null) return;
    toast(`Пароль Keenetic сохранён — найдено устройств: ${count}`, 'success');
    onSaved();
    nav.next();
  }

  return html`
    <form class="form wizard__form" onSubmit=${onSubmit} noValidate>
      <p class="muted">
        Пароль admin от роутера — тот же, которым вы входите на
        <span class="mono">http://192.168.1.1</span>. Нужен, чтобы показывать устройства сети
        и пускать их через VPN целиком.${knSet ? ' Сейчас пароль сохранён.' : ''}
      </p>
      <${KeeneticPasswordField} kn=${kn} />
      <${Footer} nav=${nav} busy=${!!kn.busy} next=${knBusyLabel(kn.busy, 'Далее')} />
    </form>
  `;
}

function AddStep({ nav }) {
  const link = useAddLink();

  function onSubmit(e) {
    e.preventDefault();
    if (link.result) nav.next();
    else link.submit();
  }

  return html`
    <form class="form wizard__form" onSubmit=${onSubmit} noValidate>
      <p class="muted">
        Вставьте ссылку на подписку или ключи серверов — серверы загрузятся сами. Нет
        подписки — пропустите шаг, добавить можно позже в разделе «Серверы».
      </p>
      ${link.result ? html`
        <${AddLinkSummary} result=${link.result} />
        <div class="actions">
          <${Button} variant="secondary" icon="plus" onClick=${link.again}>Добавить ещё</${Button}>
        </div>`
        : html`<${AddLinkFields} link=${link} />`}
      <${Footer} nav=${nav} busy=${link.busy}
        next=${link.busy ? 'Добавляю…' : link.result ? 'Далее' : 'Добавить'} />
    </form>
  `;
}

function ServerStep({ nav }) {
  const [busy, setBusy] = useState(false);
  const empty = html`<${EmptyState} icon="servers" title="Серверов пока нет"
    text="Добавить подписку или ключ можно позже в разделе «Серверы». Пропустите шаг." />`;
  return html`
    <form class="form wizard__form" noValidate
      onSubmit=${(e) => { e.preventDefault(); nav.next(); }}>
      <p class="muted">
        Нажмите на сервер — он сразу применится. Поменять его можно в любой момент
        в разделе «Серверы».
      </p>
      <${ServerList} onDone=${() => {}} onBusy=${setBusy} empty=${empty} />
      <${Footer} nav=${nav} busy=${busy} />
    </form>
  `;
}

function FinishStep({ nav, wireguard }) {
  const [wg, setWg] = useState(wireguard);
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState([]);
  const [applyWg, setApplyWg] = useState(true);

  // Runs every call even when one fails, so a single hiccup doesn't block finishing.
  async function finish(withWg) {
    setApplyWg(withWg);
    setBusy(true);
    setErrors([]);
    const errs = [];
    if (withWg) {
      const f = await api('set_features', { wireguard: wg }, { quiet: true, timeout: 20000 });
      if (f.error) errs.push(`WireGuard: ${errorText(f.error)}`);
    }
    const c = await api('complete_onboarding', {}, { quiet: true });
    if (c.error) errs.push(`Не удалось отметить настройку завершённой: ${errorText(c.error)}`);
    // Without a single server there is nothing to start.
    const srv = await refreshServers();
    if (!srv.error && Array.isArray(srv.servers) && srv.servers.length) {
      const s = await api('start', {}, { quiet: true, timeout: APPLY_TIMEOUT_MS });
      if (s.error) errs.push(`Xray не запустился: ${errorText(s.error)}`);
      else if (s.xray_running === false) {
        errs.push('Xray не запустился — подробности в разделе «Диагностика»');
      }
    }
    setBusy(false);
    refreshOverview();
    if (errs.length) {
      setErrors(errs);
      return;
    }
    toast('Готово! Можно пользоваться.', 'success');
    location.hash = '#/';
  }

  const failed = errors.length > 0;
  return html`
    <form class="form wizard__form" noValidate
      onSubmit=${(e) => { e.preventDefault(); finish(failed ? applyWg : true); }}>
      <p class="muted">
        Встроенный WireGuard-сервер — чтобы подключаться к домашней сети с телефона или
        ноутбука. Включить или выключить можно и позже, в настройках.
      </p>
      <div class="setting">
        <div class="setting__text">
          <span class="setting__label" id="wiz-wg">Использовать WireGuard</span>
          <span class="setting__hint">${wg ? 'Включён' : 'Выключен'}</span>
        </div>
        <${Toggle} checked=${wg} label="Использовать WireGuard" disabled=${busy}
          onChange=${setWg} />
      </div>
      ${failed ? html`
        <div class="callout callout--red" role="alert">
          <${Icon} name="alert" />
          <div class="callout__text"><strong>Не всё получилось</strong>
            ${errors.map((t, i) => html`<span key=${i} class="callout__sub">${t}</span>`)}
            <span class="callout__sub">Можно повторить или перейти к обзору и разобраться
              там.</span>
          </div>
        </div>` : null}
      ${failed ? html`
        <${Footer} nav=${nav} busy=${busy} next="Повторить" skip="На обзор"
          onSkip=${() => { location.hash = '#/'; }} />`
        : html`<${Footer} nav=${nav} busy=${busy} next="Готово"
          onSkip=${() => finish(false)} />`}
    </form>
  `;
}

export function WizardScreen() {
  const [step, setStep] = useState(0);
  const [status, setStatus] = useState(null); // get_onboarding_status
  const [auth, setAuth] = useState(null);     // auth_status
  const heading = useRef(null);
  const firstRender = useRef(true);

  function loadStatus() {
    api('get_onboarding_status', null, { quiet: true }).then((r) => {
      if (!r.error) setStatus(r);
    });
  }
  function loadAuth() {
    api('auth_status', null, { quiet: true }).then((r) => {
      if (r.error) return;
      // Opened from outside the home network without a session: log in first, then back.
      if (r.authenticated === false) goToLogin();
      else setAuth(r);
    });
  }
  useEffect(() => {
    document.title = 'Мастер настройки · VKeen';
    loadStatus();
    loadAuth();
  }, []);

  useEffect(() => {
    if (firstRender.current) {
      firstRender.current = false;
      return;
    }
    window.scrollTo(0, 0);
    if (heading.current) heading.current.focus({ preventScroll: true });
  }, [step]);

  const nav = {
    back: step > 0 ? () => setStep(step - 1) : null,
    next: () => setStep(Math.min(step + 1, STEPS.length - 1)),
  };
  const wireguard = status && status.features && typeof status.features.wireguard === 'boolean'
    ? status.features.wireguard : true;

  let body;
  if (step === 0) body = html`<${PanelStep} nav=${nav} auth=${auth} onSaved=${loadAuth} />`;
  else if (step === 1) {
    body = html`<${KeeneticStep} nav=${nav} knSet=${!!(status && status.kn_pass_set)}
      onSaved=${loadStatus} />`;
  } else if (step === 2) body = html`<${AddStep} nav=${nav} />`;
  else if (step === 3) body = html`<${ServerStep} nav=${nav} />`;
  else body = html`<${FinishStep} nav=${nav} wireguard=${wireguard} />`;

  return html`
    <div class="wizard">
      <main class="card wizard__card" aria-labelledby="wizard-title">
        <div class="wizard__top">
          <div class="wizard__brand">
            <span class="brand__mark" aria-hidden="true"></span>VKeen · Мастер настройки
          </div>
          <${Progress} step=${step} />
        </div>
        <div class="wizard__head">
          <p class="wizard__num">Шаг ${step + 1} из ${STEPS.length}</p>
          <h1 class="wizard__title" id="wizard-title" tabindex="-1" ref=${heading}>
            ${STEPS[step]}</h1>
        </div>
        ${body}
      </main>
    </div>
  `;
}
