// Sheet: modal dialog — centered on desktop, bottom sheet on phone (CSS decides).
// Traps Tab focus inside, closes on Esc and backdrop click, returns focus on close.
import { html, useEffect, useRef } from '../vendor/preact-htm.js';
import { Icon } from './icons.js';

const FOCUSABLE = 'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]),'
  + ' textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

let titleSeq = 0;

export function Sheet({ open, title, onClose, footer, children }) {
  const panel = useRef(null);
  const titleId = useRef(`sheet-title-${++titleSeq}`);

  useEffect(() => {
    if (!open) return undefined;
    const opener = document.activeElement;
    const node = panel.current;
    const first = node && node.querySelector(FOCUSABLE);
    (first || node).focus();

    function onKeyDown(e) {
      if (e.key === 'Escape') {
        e.stopPropagation();
        onClose && onClose();
        return;
      }
      if (e.key !== 'Tab' || !node) return;
      const items = [...node.querySelectorAll(FOCUSABLE)];
      if (items.length === 0) {
        e.preventDefault();
        return;
      }
      const firstEl = items[0];
      const lastEl = items[items.length - 1];
      if (e.shiftKey && document.activeElement === firstEl) {
        e.preventDefault();
        lastEl.focus();
      } else if (!e.shiftKey && document.activeElement === lastEl) {
        e.preventDefault();
        firstEl.focus();
      }
    }
    document.addEventListener('keydown', onKeyDown, true);
    document.body.classList.add('has-sheet');
    return () => {
      document.removeEventListener('keydown', onKeyDown, true);
      document.body.classList.remove('has-sheet');
      if (opener && opener.focus) opener.focus();
    };
  }, [open]);

  if (!open) return null;
  return html`
    <div class="sheet-backdrop" onClick=${(e) => e.target === e.currentTarget && onClose && onClose()}>
      <div class="sheet" role="dialog" aria-modal="true" aria-labelledby=${titleId.current}
        tabindex="-1" ref=${panel}>
        <header class="sheet__head">
          <h2 class="sheet__title" id=${titleId.current}>${title}</h2>
          <button type="button" class="icon-btn" aria-label="Закрыть" onClick=${onClose}>
            <${Icon} name="close" />
          </button>
        </header>
        <div class="sheet__body">${children}</div>
        ${footer ? html`<footer class="sheet__foot">${footer}</footer>` : null}
      </div>
    </div>
  `;
}
