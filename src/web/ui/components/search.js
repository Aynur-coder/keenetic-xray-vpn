// SearchInput: text field with a search icon and a clear button. onInput receives the string.
import { html } from '../vendor/preact-htm.js';
import { Icon } from './icons.js';

export function SearchInput({ value = '', onInput, placeholder = 'Поиск', label }) {
  return html`
    <div class="search">
      <${Icon} name="search" size=${18} class="search__icon" />
      <input type="search" class="search__input" value=${value} placeholder=${placeholder}
        aria-label=${label || placeholder} onInput=${(e) => onInput(e.currentTarget.value)} />
      ${value ? html`
        <button type="button" class="search__clear" aria-label="Очистить"
          onClick=${() => onInput('')}><${Icon} name="close" size=${16} /></button>` : null}
    </div>
  `;
}
