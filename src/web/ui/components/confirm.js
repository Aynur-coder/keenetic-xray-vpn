// Confirm: `await confirm({title, message, confirmLabel, danger})` → true/false.
// <ConfirmHost/> is mounted once in app.js and renders the pending question in a Sheet.
import { html, useState, useEffect } from '../vendor/preact-htm.js';
import { Sheet } from './sheet.js';
import { Button } from './button.js';

let setPending = null;

export function confirm(opts) {
  return new Promise((resolve) => {
    if (!setPending) {
      resolve(false);
      return;
    }
    setPending({ ...opts, resolve });
  });
}

export function ConfirmHost() {
  const [pending, setState] = useState(null);
  useEffect(() => {
    setPending = setState;
    return () => {
      setPending = null;
    };
  }, []);

  function answer(ok) {
    if (pending) pending.resolve(ok);
    setState(null);
  }

  const p = pending || {};
  return html`
    <${Sheet} open=${!!pending} title=${p.title || 'Подтвердите действие'}
      onClose=${() => answer(false)}
      footer=${html`
        <${Button} variant="ghost" onClick=${() => answer(false)}>Отмена</${Button}>
        <${Button} variant=${p.danger ? 'danger' : 'primary'} onClick=${() => answer(true)}>
          ${p.confirmLabel || 'Подтвердить'}
        </${Button}>`}>
      ${p.message ? html`<p class="confirm__text">${p.message}</p>` : null}
    </${Sheet}>
  `;
}
