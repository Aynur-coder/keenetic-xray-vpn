// Серверы — stub for the shell (Task 12 builds the real screen).
import { html } from '../vendor/preact-htm.js';
import { EmptyState } from '../components/empty.js';

export function ServersScreen() {
  return html`
    <${EmptyState} icon="servers" title="Список серверов появится здесь"
      text="Пока серверами можно управлять в старом интерфейсе."
      action=${html`<a class="btn btn--secondary btn--md" href="legacy.php">Открыть старый
        интерфейс</a>`} />
  `;
}
