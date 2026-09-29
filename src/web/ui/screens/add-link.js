// AddLinkSheet: the one-field «Добавить» dialog of Серверы. Any mix of subscription URLs
// and keys, one per line → add_link (fetches new subscriptions and applies the config once),
// then a summary: what was added and which lines were skipped and why.
import { html, useState } from '../vendor/preact-htm.js';
import { api, errorText, refreshServers, refreshOverview } from '../api.js';
import { Sheet } from '../components/sheet.js';
import { Button } from '../components/button.js';
import { Icon } from '../components/icons.js';

// Subscription download + apply (60 s lock) in one request.
const ADD_TIMEOUT_MS = 120000;

const PLACEHOLDER = 'https://provider.example/sub/…\nvless://…\nhysteria2://…';

const SKIP_REASON = {
  unrecognized: 'не распознано — нужна ссылка http(s)://, vless://, ss://, trojan://, hysteria2://',
};

// plural(5, ['сервер', 'сервера', 'серверов']) → 'серверов'.
export function plural(n, forms) {
  const a = Math.abs(n) % 100;
  const b = a % 10;
  if (a > 10 && a < 20) return forms[2];
  if (b === 1) return forms[0];
  if (b >= 2 && b <= 4) return forms[1];
  return forms[2];
}

function countText(n, forms) {
  return `${n} ${plural(n, forms)}`;
}

function Summary({ result }) {
  const subs = result.added_subscriptions || 0;
  const keys = result.added_keys || 0;
  const skipped = Array.isArray(result.skipped) ? result.skipped : [];
  const added = [];
  if (subs) added.push(countText(subs, ['подписка', 'подписки', 'подписок']));
  if (keys) added.push(countText(keys, ['ключ', 'ключа', 'ключей']));
  const nothing = !subs && !keys;
  return html`
    <div class="add-result" role="status">
      <div class=${`callout ${nothing ? 'callout--orange' : 'callout--green'}`}>
        <${Icon} name=${nothing ? 'alert' : 'check'} />
        <div class="callout__text">
          <strong>${nothing ? 'Ничего не добавлено' : `Добавлено: ${added.join(', ')}`}</strong>
          ${result.xray_running === false
            ? html`<span class="callout__sub tone-red">Xray не запустился — подробности в
                событиях</span>` : null}
        </div>
      </div>
      ${skipped.length ? html`
        <div class="add-skipped">
          <p class="add-skipped__title">Пропущено: ${skipped.length}</p>
          <ul class="add-skipped__list">
            ${skipped.map((s, i) => html`
              <li key=${i} class="add-skipped__item">
                <span class="add-skipped__line mono">${s.line}</span>
                <span class="add-skipped__reason">${SKIP_REASON[s.reason] || s.reason}</span>
              </li>
            `)}
          </ul>
        </div>` : null}
    </div>
  `;
}

export function AddLinkSheet({ onClose }) {
  const [text, setText] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [result, setResult] = useState(null);

  async function submit(e) {
    e.preventDefault();
    if (busy) return;
    if (!text.trim()) {
      setError('Вставьте хотя бы одну ссылку');
      return;
    }
    setBusy(true);
    setError(null);
    const res = await api('add_link', { text }, { quiet: true, timeout: ADD_TIMEOUT_MS });
    setBusy(false);
    if (res.error) {
      setError(errorText(res.error));
      return;
    }
    setResult(res);
    // The summary in the sheet is the feedback; no toast on top of it.
    if (res.added_subscriptions || res.added_keys) {
      refreshServers();
      refreshOverview();
    }
  }

  function again() {
    setText('');
    setResult(null);
  }

  if (result) {
    return html`
      <${Sheet} key="result" open=${true} title="Добавить" onClose=${onClose}
        footer=${html`
          <${Button} variant="ghost" onClick=${again}>Добавить ещё</${Button}>
          <${Button} variant="primary" onClick=${onClose}>Готово</${Button}>`}>
        <${Summary} result=${result} />
      </${Sheet}>
    `;
  }

  return html`
    <${Sheet} key="form" open=${true} title="Добавить" onClose=${onClose}
      footer=${html`
        <${Button} variant="ghost" onClick=${onClose} disabled=${busy}>Отмена</${Button}>
        <${Button} variant="primary" type="submit" form="add-link-form" loading=${busy}>
          ${busy ? 'Добавляю…' : 'Добавить'}</${Button}>`}>
      <form id="add-link-form" class="form" onSubmit=${submit}>
        <label class="field">
          <span class="field__label">Ссылки — по одной в строке</span>
          <textarea class="input textarea mono" rows="6" value=${text} placeholder=${PLACEHOLDER}
            spellcheck="false" autocapitalize="off" autocomplete="off" disabled=${busy}
            aria-describedby="add-link-hint"
            onInput=${(e) => setText(e.currentTarget.value)}></textarea>
        </label>
        <p id="add-link-hint" class="muted add-hint">
          Ссылка <span class="mono">http(s)://</span> — подписка,
          <span class="mono">vless://</span>,
          <span class="mono">ss://</span>, <span class="mono">trojan://</span>,
          <span class="mono">hysteria2://</span> — отдельный ключ. Можно вставить сразу несколько.
          ${busy ? ' Загружаю подписки и применяю настройки — это может занять до минуты.' : ''}
        </p>
        ${error ? html`
          <div class="callout callout--red" role="alert">
            <${Icon} name="alert" />
            <div class="callout__text"><strong>Не удалось добавить</strong>
              <span class="callout__sub">${error}</span></div>
          </div>` : null}
      </form>
    </${Sheet}>
  `;
}
