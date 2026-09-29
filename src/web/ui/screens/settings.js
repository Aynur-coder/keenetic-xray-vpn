// Настройки — stub for the shell (Task 13 builds the real page). Only the theme choice lives
// here for now, since the shell owns theming.
import { html } from '../vendor/preact-htm.js';
import { useStore } from '../store.js';
import { setTheme } from '../theme.js';
import { Card } from '../components/card.js';

const OPTIONS = [['auto', 'Авто'], ['light', 'Светлая'], ['dark', 'Тёмная']];

export function SettingsScreen() {
  const theme = useStore((s) => s.theme);
  const version = useStore((s) => (s.overview ? s.overview.version : ''));
  return html`
    <${Card} title="Общие">
      <div class="field">
        <span class="field__label" id="theme-label">Тема</span>
        <div class="segmented" role="radiogroup" aria-labelledby="theme-label">
          ${OPTIONS.map(([id, label]) => html`
            <button key=${id} type="button" role="radio" class="segmented__item"
              aria-checked=${theme === id ? 'true' : 'false'}
              onClick=${() => setTheme(id)}>${label}</button>
          `)}
        </div>
      </div>
      ${version ? html`<p class="muted">Версия ${version}</p>` : null}
      <p><a href="legacy.php">Остальные настройки — в старом интерфейсе</a></p>
    </${Card}>
  `;
}
