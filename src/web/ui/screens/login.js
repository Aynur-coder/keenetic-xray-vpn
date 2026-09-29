// Вход — minimal working password form for the 401 flow.
import { html, useState } from '../vendor/preact-htm.js';
import { api, errorText, takeReturnHash } from '../api.js';
import { Button } from '../components/button.js';
import { Card } from '../components/card.js';

export function LoginScreen() {
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  async function onSubmit(e) {
    e.preventDefault();
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
    let text = errorText(res.error);
    if (res.error === 'too_many_attempts' && res.retry_after) {
      text = `Слишком много попыток входа, подождите ${res.retry_after} с`;
    }
    setError(text);
  }

  return html`
    <div class="login">
      <${Card} title="Вход в панель">
        <form class="form" onSubmit=${onSubmit} noValidate>
          <label class="field">
            <span class="field__label">Пароль</span>
            <input class="input" type="password" autocomplete="current-password" autofocus
              value=${password} onInput=${(e) => setPassword(e.currentTarget.value)}
              aria-invalid=${error ? 'true' : undefined} aria-describedby="login-error" />
          </label>
          <p id="login-error" class="form__error" role="alert">${error}</p>
          <${Button} type="submit" variant="primary" loading=${busy}>Войти</${Button}>
        </form>
      </${Card}>
    </div>
  `;
}
