// Вход: centered password card. Enter submits; a wrong password says how many attempts are
// left; after too many the router answers 429 with retry_after (s) and the form stays
// locked with a live countdown. Success returns to the route that sent the user here.
import { html, useState, useEffect, useRef } from '../vendor/preact-htm.js';
import { api, errorText, takeReturnHash } from '../api.js';
import { Button } from '../components/button.js';
import { Icon } from '../components/icons.js';

// 3540 → '59 мин', 45 → '45 с', 125 → '2 мин 5 с'.
export function waitText(seconds) {
  const s = Math.max(0, Math.ceil(seconds));
  if (s < 60) return `${s} с`;
  const m = Math.floor(s / 60);
  const rest = s % 60;
  if (m >= 10 || rest === 0) return `${Math.ceil(s / 60)} мин`;
  return `${m} мин ${rest} с`;
}

export function LoginScreen() {
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [lockedUntil, setLockedUntil] = useState(0);
  const [now, setNow] = useState(Date.now());
  const input = useRef(null);

  const locked = lockedUntil > now;

  useEffect(() => {
    if (input.current) input.current.focus();
  }, []);

  useEffect(() => {
    if (!lockedUntil) return undefined;
    const timer = setInterval(() => {
      const t = Date.now();
      setNow(t);
      if (t >= lockedUntil) {
        clearInterval(timer);
        setLockedUntil(0);
        setError('');
        if (input.current) input.current.focus();
      }
    }, 1000);
    return () => clearInterval(timer);
  }, [lockedUntil]);

  async function onSubmit(e) {
    e.preventDefault();
    if (busy || locked) return;
    if (!password) {
      setError(errorText('no_password'));
      return;
    }
    setBusy(true);
    setError('');
    const res = await api('login', { password }, { quiet: true });
    setBusy(false);
    if (res.ok) {
      setPassword('');
      location.hash = takeReturnHash();
      return;
    }
    setPassword('');
    if (res.error === 'too_many_attempts') {
      if (res.retry_after) {
        const t = Date.now();
        setNow(t);
        setLockedUntil(t + res.retry_after * 1000);
      } else {
        setError(errorText(res.error));
      }
      return;
    }
    let text = errorText(res.error);
    if (res.error === 'invalid_credentials' && typeof res.remaining === 'number') {
      text = `${text} (осталось попыток: ${res.remaining})`;
    }
    setError(text);
    if (input.current) input.current.focus();
  }

  const message = locked
    ? `Слишком много попыток входа. Повторите через ${waitText((lockedUntil - now) / 1000)}.`
    : error;
  return html`
    <div class="login">
      <section class="card login__card" aria-labelledby="login-title">
        <div class="login__head">
          <span class="login__icon"><${Icon} name="lock" size=${22} /></span>
          <h2 class="login__title" id="login-title">Вход в панель</h2>
          <p class="muted">Панель открыта не из домашней сети — введите пароль панели.</p>
        </div>
        <form class="form" onSubmit=${onSubmit} noValidate>
          <label class="field">
            <span class="field__label">Пароль</span>
            <input class="input" type="password" autocomplete="current-password" ref=${input}
              value=${password} disabled=${locked}
              onInput=${(e) => { setPassword(e.currentTarget.value); setError(''); }}
              aria-invalid=${message ? 'true' : undefined} aria-describedby="login-error" />
          </label>
          <p id="login-error" class="form__error" role="alert">${message}</p>
          <${Button} type="submit" variant="primary" class="login__submit" loading=${busy}
            disabled=${locked}>Войти</${Button}>
        </form>
      </section>
    </div>
  `;
}
