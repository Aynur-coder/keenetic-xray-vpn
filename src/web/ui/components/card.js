// Card: bordered section with an optional title row and actions on the right.
import { html } from '../vendor/preact-htm.js';

export function Card({ title, actions, class: cls = '', id, children }) {
  return html`
    <section class=${`card ${cls}`} id=${id}>
      ${title || actions ? html`
        <header class="card__head">
          ${title ? html`<h2 class="card__title">${title}</h2>` : html`<span></span>`}
          ${actions ? html`<div class="card__actions">${actions}</div>` : null}
        </header>` : null}
      <div class="card__body">${children}</div>
    </section>
  `;
}
