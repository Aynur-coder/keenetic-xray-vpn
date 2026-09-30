// Tabs: role="tablist" with arrow-key / Home / End navigation.
// tabs: [{id, label}]; value: selected id; onChange(id).
import { html } from '../vendor/preact-htm.js';

export function Tabs({ tabs, value, onChange, label }) {
  function onKeyDown(e) {
    const i = tabs.findIndex((t) => t.id === value);
    let next = -1;
    if (e.key === 'ArrowRight') next = (i + 1) % tabs.length;
    else if (e.key === 'ArrowLeft') next = (i - 1 + tabs.length) % tabs.length;
    else if (e.key === 'Home') next = 0;
    else if (e.key === 'End') next = tabs.length - 1;
    if (next < 0) return;
    e.preventDefault();
    onChange(tabs[next].id);
    const buttons = e.currentTarget.querySelectorAll('[role="tab"]');
    if (buttons[next]) buttons[next].focus();
  }
  return html`
    <div class="tabs" role="tablist" aria-label=${label} onKeyDown=${onKeyDown}>
      ${tabs.map((t) => html`
        <button key=${t.id} type="button" role="tab" class="tabs__tab"
          aria-selected=${t.id === value ? 'true' : 'false'}
          tabindex=${t.id === value ? 0 : -1} onClick=${() => onChange(t.id)}>${t.label}</button>
      `)}
    </div>
  `;
}
