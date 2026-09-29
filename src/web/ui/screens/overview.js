// Обзор — stub for the shell (Task 11 builds the real screen). Shows the polled state so the
// shell's polling and offline behaviour are visible.
import { html } from '../vendor/preact-htm.js';
import { useStore } from '../store.js';
import { Card } from '../components/card.js';
import { Badge } from '../components/badge.js';

export const STATE_LABEL = {
  running: ['green', 'Работает'],
  paused: ['orange', 'Пауза: сервер недоступен'],
  stopped: ['red', 'Остановлен'],
  config_error: ['red', 'Ошибка конфига'],
  starting: ['orange', 'Запускается'],
};

export function OverviewScreen() {
  const overview = useStore((s) => s.overview);
  const ips = useStore((s) => s.ips);
  if (!overview) return html`<p class="muted">Загрузка…</p>`;
  const [tone, label] = STATE_LABEL[overview.state] || ['neutral', overview.state];
  const server = overview.effective || overview.active;
  return html`
    <${Card} title="Состояние">
      <p><${Badge} tone=${tone} dot>${label}</${Badge}></p>
      ${server ? html`<p class="muted">Сервер: ${server.name}</p>` : null}
      ${ips ? html`<p class="muted">
        VPN IP: ${ips.vpn_ip || '—'} · Реальный IP: ${ips.real_ip || '—'}
      </p>` : null}
    </${Card}>
  `;
}
