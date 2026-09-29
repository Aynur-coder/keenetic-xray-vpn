// EmptyState: centered icon + title + explanation + optional action.
import { html } from '../vendor/preact-htm.js';
import { Icon } from './icons.js';

export function EmptyState({ icon = 'info', title, text, action }) {
  return html`
    <div class="empty">
      <span class="empty__icon"><${Icon} name=${icon} size=${28} /></span>
      ${title ? html`<p class="empty__title">${title}</p>` : null}
      ${text ? html`<p class="empty__text">${text}</p>` : null}
      ${action ? html`<div class="empty__action">${action}</div>` : null}
    </div>
  `;
}
