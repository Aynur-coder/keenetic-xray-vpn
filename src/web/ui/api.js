// The only way the UI talks to api.php. Never throws: every outcome is an object,
// failures are {error: '<text>'}.
//   api(action)                       GET  api.php?action=…
//   api(action, data)                 POST form-encoded
//   api(action, data, {method:'GET'}) GET with data as query parameters
//   {quiet: true}                     no toast on error (caller shows it itself)
//   {timeout: ms}                     longer wait for slow actions (apply, probe)
import { store } from './store.js';
import { toast } from './components/toast.js';

const TIMEOUT_MS = 10000;
export const OFFLINE_ERROR = 'Роутер не отвечает';

// Backend error codes that are not human text yet.
const ERROR_TEXT = {
  auth_required: 'Нужно войти',
  invalid_credentials: 'Неверный пароль',
  too_many_attempts: 'Слишком много попыток входа, подождите',
  password_too_short: 'Пароль слишком короткий',
  no_password: 'Введите пароль',
  check_failed: 'Не удалось проверить обновления — нет связи с GitHub',
  cache_invalid: 'Не удалось проверить обновления',
  already_running: 'Обновление уже выполняется',
};

export function errorText(error) {
  return ERROR_TEXT[error] || String(error);
}

// Where to return after login; set when a 401 sends the user to #/login.
let returnHash = '#/';
export function takeReturnHash() {
  const h = returnHash;
  returnHash = '#/';
  return h;
}

function toQuery(data) {
  const params = new URLSearchParams();
  for (const [k, v] of Object.entries(data || {})) {
    if (v === undefined || v === null) continue;
    params.append(k, typeof v === 'boolean' ? (v ? '1' : '0') : String(v));
  }
  return params;
}

export async function api(action, data, opts = {}) {
  const method = opts.method || (data ? 'POST' : 'GET');
  const quiet = !!opts.quiet;
  const timeoutMs = opts.timeout || TIMEOUT_MS;
  const query = new URLSearchParams({ action });
  const init = { method, credentials: 'same-origin', headers: { Accept: 'application/json' } };
  if (method === 'GET') {
    for (const [k, v] of toQuery(data)) query.append(k, v);
  } else {
    init.body = toQuery(data);
  }

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  init.signal = controller.signal;

  let res;
  let text;
  try {
    res = await fetch(`api.php?${query}`, init);
    text = await res.text();
  } catch (e) {
    // Network failure, DNS, refused connection or our own timeout abort.
    return offline(quiet);
  } finally {
    clearTimeout(timer);
  }

  // A gateway/proxy answering for a dead backend is the same as no answer.
  if (res.status === 502 || res.status === 503 || res.status === 504) return offline(quiet);
  store.set({ offline: false });

  if (res.status === 401) {
    const here = location.hash || '#/';
    if (!here.startsWith('#/login')) {
      returnHash = here;
      location.hash = '#/login';
    }
    return { error: 'auth_required' };
  }

  let body;
  try {
    body = text === '' ? {} : JSON.parse(text);
  } catch (e) {
    body = { error: `Некорректный ответ роутера (HTTP ${res.status})` };
  }
  if (body === null || typeof body !== 'object') body = { value: body };
  if (!res.ok && !body.error) body = { ...body, error: `Ошибка роутера (HTTP ${res.status})` };

  if (body.error && !quiet) toast(errorText(body.error), 'error');
  return body;
}

function offline(quiet) {
  store.set({ offline: true });
  if (!quiet) toast(OFFLINE_ERROR, 'error');
  return { error: OFFLINE_ERROR };
}

// Loaders for the shared store slices (polling in app.js, screens after a change).
export async function refreshOverview() {
  const res = await api('overview', null, { quiet: true });
  if (!res.error) store.set({ overview: res });
}

export async function refreshIps() {
  const res = await api('check_ips', null, { quiet: true });
  if (!res.error) store.set({ ips: res });
}

export async function refreshServers() {
  const res = await api('servers', null, { quiet: true });
  if (!res.error) store.set({ servers: res });
  return res;
}
