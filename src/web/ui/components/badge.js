// Badge: small status label. tone 'neutral' | 'accent' | 'green' | 'orange' | 'red'.
import { html } from '../vendor/preact-htm.js';

export function Badge({ tone = 'neutral', dot = false, children }) {
  return html`
    <span class=${`badge badge--${tone}`}>
      ${dot ? html`<span class="badge__dot" aria-hidden="true"></span>` : null}${children}
    </span>
  `;
}
