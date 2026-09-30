// Button: variant 'primary' | 'secondary' | 'ghost' | 'danger'; `loading` disables it and
// shows a spinner; `icon` is an Icon name. Other props go to the <button>.
import { html } from '../vendor/preact-htm.js';
import { Icon } from './icons.js';

export function Button({
  variant = 'secondary', size = 'md', icon, loading = false, disabled = false,
  type = 'button', class: cls = '', children, ...rest
}) {
  const classes = `btn btn--${variant} btn--${size} ${loading ? 'is-loading' : ''} ${cls}`;
  return html`
    <button type=${type} class=${classes} disabled=${disabled || loading}
      aria-busy=${loading ? 'true' : undefined} ...${rest}>
      ${loading ? html`<span class="spinner" aria-hidden="true"></span>`
        : icon ? html`<${Icon} name=${icon} size=${18} />` : null}
      ${children ? html`<span>${children}</span>` : null}
    </button>
  `;
}
