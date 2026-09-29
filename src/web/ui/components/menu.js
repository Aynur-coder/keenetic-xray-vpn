// Menu: overflow «⋮» button with a popup list of actions (role="menu").
// items: [{label, onSelect, danger?}] — falsy entries are skipped.
// Arrow keys / Home / End move between items, Esc and Tab close, a click outside closes;
// focus returns to the button. Opens upwards when there is no room below.
import { html, useState, useLayoutEffect, useRef } from '../vendor/preact-htm.js';
import { Icon } from './icons.js';

let menuSeq = 0;

export function Menu({ label, items, disabled = false }) {
  const list = items.filter(Boolean);
  const [open, setOpen] = useState(false);
  const [up, setUp] = useState(false);
  const wrap = useRef(null);
  const button = useRef(null);
  const menuId = useRef(`menu-${++menuSeq}`);

  function close(refocus) {
    setOpen(false);
    if (refocus && button.current) button.current.focus();
  }

  // Layout effect: a plain effect runs a frame later and could steal focus back into a menu
  // that a fast Esc has already closed.
  useLayoutEffect(() => {
    if (!open) return undefined;
    const node = wrap.current;
    const first = node && node.querySelector('[role="menuitem"]');
    if (first) first.focus();
    function onDown(e) {
      if (node && !node.contains(e.target)) setOpen(false);
    }
    document.addEventListener('pointerdown', onDown, true);
    return () => document.removeEventListener('pointerdown', onDown, true);
  }, [open]);

  function toggle() {
    if (!open && button.current) {
      const r = button.current.getBoundingClientRect();
      // Rows near the bottom (above the phone nav) open their menu upwards.
      setUp(window.innerHeight - r.bottom < 64 + list.length * 48);
    }
    setOpen(!open);
  }

  function onKeyDown(e) {
    const els = [...wrap.current.querySelectorAll('[role="menuitem"]')];
    const i = els.indexOf(document.activeElement);
    let next = -1;
    if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation();
      close(true);
      return;
    }
    if (e.key === 'Tab') {
      setOpen(false);
      return;
    }
    if (e.key === 'ArrowDown') next = (i + 1) % els.length;
    else if (e.key === 'ArrowUp') next = (i - 1 + els.length) % els.length;
    else if (e.key === 'Home') next = 0;
    else if (e.key === 'End') next = els.length - 1;
    if (next < 0) return;
    e.preventDefault();
    els[next].focus();
  }

  function choose(item) {
    close(true);
    item.onSelect();
  }

  return html`
    <span class="menu" ref=${wrap} onKeyDown=${open ? onKeyDown : undefined}>
      <button type="button" class="icon-btn" ref=${button} aria-label=${label}
        aria-haspopup="menu" aria-expanded=${open ? 'true' : 'false'}
        aria-controls=${open ? menuId.current : undefined} disabled=${disabled}
        onClick=${toggle}>
        <${Icon} name="more" />
      </button>
      ${open ? html`
        <div class=${`menu__pop ${up ? 'menu__pop--up' : ''}`} role="menu" id=${menuId.current}
          aria-label=${label}>
          ${list.map((item, i) => html`
            <button key=${i} type="button" role="menuitem" tabindex="-1"
              class=${`menu__item ${item.danger ? 'menu__item--danger' : ''}`}
              onClick=${() => choose(item)}>${item.label}</button>
          `)}
        </div>` : null}
    </span>
  `;
}
