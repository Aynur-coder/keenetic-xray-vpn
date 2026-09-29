// Changelog: renders changelog.md (Keep a Changelog) as vnodes — version headings, section
// headings, bullets, paragraphs, **bold** and `code`. Built from htm templates only, so any
// HTML in the text shows up as text and never becomes markup.
//   current: installed version — marked «текущая», newer versions «новая».
import { html } from '../vendor/preact-htm.js';
import { Badge } from './badge.js';

const INLINE = /(\*\*[^*]+\*\*|`[^`]+`|\[[^\]]+\]\([^)]*\))/g;

function inline(text) {
  return text.split(INLINE).map((part, i) => {
    if (i % 2 === 0) return part;
    if (part.startsWith('**')) return html`<strong>${part.slice(2, -2)}</strong>`;
    if (part.startsWith('`')) return html`<code>${part.slice(1, -1)}</code>`;
    return part.slice(1, part.indexOf(']')); // [text](url) → text: no links out of the router
  });
}

// -1 / 0 / 1 for dotted numeric versions ('0.16.1' vs '0.16.0'); non-numbers compare as 0.
export function compareVersions(a, b) {
  const pa = String(a || '').replace(/^v/, '').split('.').map((n) => parseInt(n, 10) || 0);
  const pb = String(b || '').replace(/^v/, '').split('.').map((n) => parseInt(n, 10) || 0);
  for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
    const d = (pa[i] || 0) - (pb[i] || 0);
    if (d) return d > 0 ? 1 : -1;
  }
  return 0;
}

function parse(md) {
  const blocks = [];
  let list = null;
  let skip = true; // preamble before the first version, and «## [Unreleased]»
  for (const raw of String(md || '').split('\n')) {
    const line = raw.replace(/\r$/, '');
    let m;
    if ((m = /^##\s+\[([^\]]+)\]\s*-?\s*(.*)$/.exec(line))) {
      list = null;
      skip = /unreleased/i.test(m[1]);
      if (!skip) blocks.push({ type: 'version', version: m[1].trim(), date: m[2].trim() });
    } else if (skip) {
      continue;
    } else if (/^#\s+/.test(line)) {
      list = null; // document title
    } else if ((m = /^#{2,6}\s+(.*)$/.exec(line))) {
      list = null;
      blocks.push({ type: 'heading', text: m[1] });
    } else if ((m = /^\s*[-*]\s+(.*)$/.exec(line))) {
      if (!list) {
        list = { type: 'list', items: [] };
        blocks.push(list);
      }
      list.items.push(m[1]);
    } else if (line.trim() === '') {
      list = null;
    } else if (list && /^\s+\S/.test(line)) {
      list.items[list.items.length - 1] += ` ${line.trim()}`; // wrapped bullet
    } else {
      list = null;
      blocks.push({ type: 'text', text: line });
    }
  }
  return blocks;
}

export function Changelog({ markdown, current }) {
  const blocks = parse(markdown);
  if (blocks.length === 0) return html`<p class="muted">Нет описания</p>`;
  return html`
    <div class="changelog">
      ${blocks.map((b, i) => {
        if (b.type === 'version') {
          const cmp = current ? compareVersions(b.version, current) : -1;
          return html`
            <h3 key=${i} class="changelog__ver">
              v${b.version}
              ${b.date ? html`<span class="changelog__date">${b.date}</span>` : null}
              ${cmp === 0 ? html`<${Badge} tone="accent">текущая</${Badge}>` : null}
              ${cmp > 0 ? html`<${Badge} tone="green">новая</${Badge}>` : null}
            </h3>`;
        }
        if (b.type === 'heading') return html`<h4 key=${i} class="changelog__sec">${b.text}</h4>`;
        if (b.type === 'list') {
          return html`<ul key=${i} class="changelog__list">
            ${b.items.map((t, j) => html`<li key=${j}>${inline(t)}</li>`)}</ul>`;
        }
        return html`<p key=${i} class="changelog__text">${inline(b.text)}</p>`;
      })}
    </div>
  `;
}
