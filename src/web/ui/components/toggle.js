// Toggle: an on/off switch (role="switch"). onChange receives the new boolean.
import { html } from '../vendor/preact-htm.js';

export function Toggle({ checked, onChange, label, disabled = false, id }) {
  return html`
    <button type="button" id=${id} class=${`toggle ${checked ? 'is-on' : ''}`} role="switch"
      aria-checked=${checked ? 'true' : 'false'} aria-label=${label} disabled=${disabled}
      onClick=${() => onChange && onChange(!checked)}>
      <span class="toggle__knob" aria-hidden="true"></span>
    </button>
  `;
}
