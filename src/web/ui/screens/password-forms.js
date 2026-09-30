// Password forms shared by Настройки → Безопасность and the setup wizard: the panel
// password (set_ui_password) and the Keenetic admin password (test_kn_password, then
// set_kn_password). Each is a hook with the state + submit logic and a fields component;
// the caller supplies the surrounding form, its submit button and what happens on success.
import { html, useState } from '../vendor/preact-htm.js';
import { api, errorText } from '../api.js';
import { waitText } from './login.js';

// test_kn_password logs in to the Keenetic API and lists devices.
const KN_TIMEOUT_MS = 30000;
export const MIN_PASSWORD = 4;
// set_ui_password's refusal for a missing/wrong current password (shared limiter with login).
const CURRENT_WRONG = 'Неверный текущий пароль';

// One labelled password input with its inline error (aria-describedby / aria-invalid).
export function Field({ id, label, value, onInput, error, autocomplete, hint }) {
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

// Panel password. `auth` is the auth_status answer (null while loading).
// submit() resolves true once the password is saved.
export function usePanelPassword(auth) {
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

  async function submit() {
    if (busy) return false;
    setTried(true);
    const errs = validatePanel(form, needCurrent);
    setErrors(errs);
    if (Object.keys(errs).length) return false;
    setBusy(true);
    const data = { password: form.next };
    if (needCurrent) data.current = form.current;
    const res = await api('set_ui_password', data, { quiet: true });
    setBusy(false);
    if (res.error === CURRENT_WRONG) {
      setErrors({ current: CURRENT_WRONG });
      return false;
    }
    if (res.error === 'too_many_attempts') {
      const wait = res.retry_after ? ` Повторите через ${waitText(res.retry_after)}.` : '';
      setErrors({ current: `Слишком много неверных попыток.${wait}` });
      return false;
    }
    if (res.error === 'password_too_short') {
      setErrors({ next: `Минимум ${MIN_PASSWORD} символа` });
      return false;
    }
    if (res.error) {
      setErrors({ form: errorText(res.error) });
      return false;
    }
    setForm(empty);
    setErrors({});
    setTried(false);
    return true;
  }

  return { form, errors, busy, needCurrent, update, submit };
}

export function PanelPasswordFields({ pw }) {
  const { form, errors, needCurrent, update } = pw;
  return html`
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
  `;
}

// Keenetic admin password: checked against the router first, saved only if it works.
// submit() resolves the device count on success, null otherwise. `busy` is
// '' | 'test' | 'save'.
export function useKeeneticPassword() {
  const [password, setPasswordState] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState('');

  function setPassword(v) {
    setPasswordState(v);
    setError('');
  }

  async function submit() {
    if (busy) return null;
    if (!password) {
      setError('Введите пароль');
      return null;
    }
    setError('');
    setBusy('test');
    const t = await api('test_kn_password', { password }, { quiet: true, timeout: KN_TIMEOUT_MS });
    if (t.error || !t.ok) {
      setBusy('');
      setError(t.error ? errorText(t.error)
        : 'Пароль не подошёл: Keenetic не отдал список устройств');
      return null;
    }
    setBusy('save');
    const s = await api('set_kn_password', { password }, { quiet: true });
    setBusy('');
    if (s.error) {
      setError(errorText(s.error));
      return null;
    }
    setPasswordState('');
    return t.count || 0;
  }

  return { password, setPassword, error, busy, submit };
}

export function KeeneticPasswordField({ kn }) {
  return html`
    <${Field} id="pw-kn" label="Пароль admin" value=${kn.password} autocomplete="off"
      error=${kn.error} onInput=${kn.setPassword} />
  `;
}

// Submit button text for the Keenetic form while test/save runs.
export function knBusyLabel(busy, idle) {
  return busy === 'test' ? 'Проверяю…' : busy === 'save' ? 'Сохраняю…' : idle;
}
