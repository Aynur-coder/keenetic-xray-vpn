// Placeholder for sections not rebuilt yet (Маршруты, Устройства, Диагностика):
// points to the matching part of the old interface.
import { html } from '../vendor/preact-htm.js';
import { Card } from '../components/card.js';
import { Icon } from '../components/icons.js';

export function LegacyScreen({ tab }) {
  return html`
    <${Card}>
      <p class="legacy-note">Этот раздел пока в старом интерфейсе</p>
      <a class="btn btn--primary btn--md" href=${`legacy.php#${tab}`}>
        <span>Открыть старый интерфейс</span><${Icon} name="external" size=${18} />
      </a>
    </${Card}>
  `;
}
