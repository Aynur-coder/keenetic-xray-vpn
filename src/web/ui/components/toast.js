// Toasts: call toast(message, type) from anywhere; <Toasts/> (mounted once in app.js)
// renders them inside an aria-live region.
import { html, useState, useEffect } from '../vendor/preact-htm.js';
import { Icon } from './icons.js';

const DURATION_MS = { info: 4000, success: 3000, error: 6000 };

let items = [];
let nextId = 1;
const listeners = new Set();

function emit() {
  listeners.forEach((fn) => fn(items));
}

export function dismissToast(id) {
  items = items.filter((t) => t.id !== id);
  emit();
}

// type: 'info' | 'success' | 'error'. An identical message already on screen is not repeated.
export function toast(message, type = 'info') {
  const text = String(message || '');
  if (!text) return;
  if (items.some((t) => t.message === text && t.type === type)) return;
  const id = nextId++;
  items = [...items, { id, message: text, type }].slice(-4);
  emit();
  setTimeout(() => dismissToast(id), DURATION_MS[type] || DURATION_MS.info);
}

const ICON = { info: 'info', success: 'check', error: 'alert' };

export function Toasts() {
  const [list, setList] = useState(items);
  useEffect(() => {
    listeners.add(setList);
    return () => listeners.delete(setList);
  }, []);
  return html`
    <div class="toasts" role="status" aria-live="polite">
      ${list.map((t) => html`
        <div key=${t.id} class=${`toast toast--${t.type}`}>
          <${Icon} name=${ICON[t.type] || 'info'} />
          <span class="toast__text">${t.message}</span>
          <button type="button" class="toast__close" aria-label="Закрыть"
            onClick=${() => dismissToast(t.id)}>
            <${Icon} name="close" />
          </button>
        </div>
      `)}
    </div>
  `;
}
