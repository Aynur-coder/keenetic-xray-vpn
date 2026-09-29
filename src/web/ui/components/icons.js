// Inline SVG icons (24×24, stroke = currentColor). Decorative by default (aria-hidden);
// pass `label` to make an icon meaningful to screen readers.
import { html } from '../vendor/preact-htm.js';

const PATHS = {
  overview: html`<rect x="3" y="3" width="7" height="9" rx="1.5" /><rect x="14" y="3" width="7"
    height="5" rx="1.5" /><rect x="14" y="12" width="7" height="9" rx="1.5" /><rect x="3" y="16"
    width="7" height="5" rx="1.5" />`,
  servers: html`<circle cx="12" cy="12" r="9" /><path d="M3 12h18" /><path
    d="M12 3a14 14 0 0 1 0 18a14 14 0 0 1 0-18z" />`,
  routes: html`<circle cx="6" cy="19" r="2" /><circle cx="18" cy="5" r="2" /><path
    d="M8 19h7.5a3.5 3.5 0 0 0 0-7h-7a3.5 3.5 0 0 1 0-7H16" />`,
  devices: html`<rect x="2" y="4" width="14" height="10" rx="1.5" /><path d="M6 18h6" /><rect
    x="17" y="8" width="5" height="12" rx="1.2" />`,
  diagnostics: html`<path d="M3 12h4l3-8 4 16 3-8h4" />`,
  settings: html`<circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2
    2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7
    0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7
    0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1
    2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1
    1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0
    1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z" />`,
  info: html`<circle cx="12" cy="12" r="9" /><path d="M12 11v5" /><path d="M12 8h.01" />`,
  check: html`<path d="M5 12.5l4.5 4.5L19 7.5" />`,
  alert: html`<path d="M12 3l9.5 17h-19z" /><path d="M12 10v4" /><path d="M12 17h.01" />`,
  close: html`<path d="M6 6l12 12M18 6L6 18" />`,
  search: html`<circle cx="11" cy="11" r="7" /><path d="M20 20l-4-4" />`,
  star: html`<path d="M12 3.5l2.6 5.4 5.9.8-4.3 4.1 1 5.8L12 16.8l-5.2 2.8 1-5.8-4.3-4.1
    5.9-.8z" />`,
  chevron: html`<path d="M9 6l6 6-6 6" />`,
  external: html`<path d="M14 4h6v6" /><path d="M20 4l-9 9" /><path
    d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5" />`,
  offline: html`<path d="M3 3l18 18" /><path d="M8.5 16.5a5 5 0 0 1 7 0" /><path
    d="M5 12.9a10 10 0 0 1 4.2-2.6" /><path d="M14.8 10.3A10 10 0 0 1 19 12.9" /><path
    d="M2 9.3a15 15 0 0 1 4.3-2.9" /><path d="M10.6 5.1A15 15 0 0 1 22 9.3" /><path
    d="M12 20h.01" />`,
  lock: html`<rect x="4" y="11" width="16" height="10" rx="2" /><path d="M8 11V7a4 4 0 0 1 8
    0v4" />`,
};

export function Icon({ name, label, size = 20, class: cls = '' }) {
  const body = PATHS[name] || PATHS.info;
  const a11y = label ? { role: 'img', 'aria-label': label } : { 'aria-hidden': 'true' };
  return html`
    <svg class=${`icon ${cls}`} width=${size} height=${size} viewBox="0 0 24 24" fill="none"
      stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
      focusable="false" ...${a11y}>${body}</svg>
  `;
}
