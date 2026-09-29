// ListRow: one line of a list. With onClick the main area is a real <button>;
// `trailing` (toggles, menus) sits outside it so its own controls stay separate.
import { html } from '../vendor/preact-htm.js';

export function ListRow({ leading, title, subtitle, trailing, onClick, active = false }) {
  const main = html`
    ${leading ? html`<span class="row__lead">${leading}</span>` : null}
    <span class="row__text">
      <span class="row__title">${title}</span>
      ${subtitle ? html`<span class="row__sub">${subtitle}</span>` : null}
    </span>
  `;
  return html`
    <div class=${`row ${active ? 'is-active' : ''}`}>
      ${onClick
        ? html`<button type="button" class="row__main" onClick=${onClick}
            aria-current=${active ? 'true' : undefined}>${main}</button>`
        : html`<div class="row__main">${main}</div>`}
      ${trailing ? html`<span class="row__trail">${trailing}</span>` : null}
    </div>
  `;
}
