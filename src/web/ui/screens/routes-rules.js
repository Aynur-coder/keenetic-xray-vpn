// Маршруты → Свои правила: add many domains/IPs at once (with target and match mode),
// a searchable, filterable list with per-row target and match mode, and multi-select
// bulk actions that go to the router as ONE rules_batch (one apply for the whole batch).
import { html, useState } from '../vendor/preact-htm.js';
import { api, errorText } from '../api.js';
import { Button } from '../components/button.js';
import { Badge } from '../components/badge.js';
import { Card } from '../components/card.js';
import { Menu } from '../components/menu.js';
import { SearchInput } from '../components/search.js';
import { EmptyState } from '../components/empty.js';
import { Icon } from '../components/icons.js';
import { toast } from '../components/toast.js';
import { confirm } from '../components/confirm.js';
import { plural } from './add-link.js';
import { TargetSelect, reportApply, APPLY_TIMEOUT_MS, TARGET_PROXY } from './routes.js';

const PAGE = 200;
const RULE_FORMS = ['правило', 'правила', 'правил'];
const PLACEHOLDER = 'example.com\nhttps://site.ru/page\n1.2.3.4, 10.0.0.0/8';
const FILTERS = [
  { id: 'all', label: 'Все' },
  { id: 'domain', label: 'Домены' },
  { id: 'ip', label: 'IP' },
];

// Lines of the add field that are IPs / CIDRs (the rest go to add_domains). The router
// normalizes both lists again and reports lines that turn out to be the other kind.
export function looksLikeIp(line) {
  let s = line.trim().toLowerCase();
  if (/^\d{1,3}(\.\d{1,3}){3}(\/\d+)?$/.test(s)) return true;
  if (s.split(':').length > 2 && /^[0-9a-f:.]+(\/\d+)?$/.test(s)) return true;
  s = s.replace(/^[a-z][a-z0-9+.-]*:\/\//, '').split(/[/?#]/)[0];
  s = s.slice(s.lastIndexOf('@') + 1);
  return /^\d{1,3}(\.\d{1,3}){3}(:\d+)?$/.test(s) || /^\[[0-9a-f:.]+\](:\d+)?$/.test(s);
}

export function splitLines(text) {
  return String(text || '').split(/[\r\n,;]+/).map((s) => s.trim()).filter(Boolean);
}

// Every own rule as one list, newest first: {key, kind, value, mode?}.
function allRules(data) {
  const domains = data.manual.map((d) => ({
    key: `domain:${String(d.domain).toLowerCase()}`, kind: 'domain',
    value: String(d.domain).toLowerCase(), mode: d.mode,
  }));
  const ips = data.ips.map((ip) => ({ key: `ip:${ip}`, kind: 'ip', value: ip }));
  return [...domains.reverse(), ...ips.reverse()];
}

function splitKey(key) {
  const i = key.indexOf(':');
  return { kind: key.slice(0, i), value: key.slice(i + 1) };
}

// ---------- add form ----------

function AddResult({ result }) {
  const { added, skipped, invalid } = result;
  const nothing = !added;
  return html`
    <div class="add-result rules-add__result" role="status">
      <div class=${`callout ${nothing ? 'callout--orange' : 'callout--green'}`}>
        <${Icon} name=${nothing ? 'alert' : 'check'} />
        <div class="callout__text">
          <strong>${nothing ? 'Ничего не добавлено' : `Добавлено: ${added} ${plural(added,
            RULE_FORMS)}`}</strong>
          ${skipped ? html`<span class="callout__sub">Уже есть в подключённых сервисах:
            ${skipped}</span>` : null}
        </div>
      </div>
      ${invalid.length ? html`
        <div class="add-skipped">
          <p class="add-skipped__title">Не распознано: ${invalid.length}</p>
          <ul class="add-skipped__list">
            ${invalid.map((s, i) => html`
              <li key=${i} class="add-skipped__item">
                <span class="add-skipped__line mono">${s.line}</span>
                <span class="add-skipped__reason">${s.reason}</span>
              </li>`)}
          </ul>
        </div>` : null}
    </div>
  `;
}

function AddRulesForm({ servers, reload }) {
  const [text, setText] = useState('');
  const [target, setTarget] = useState(TARGET_PROXY);
  const [mode, setMode] = useState('suffix');
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState(null);

  async function submit(e) {
    e.preventDefault();
    if (busy) return;
    const lines = splitLines(text);
    if (!lines.length) {
      toast('Введите домен или IP', 'error');
      return;
    }
    const ips = lines.filter(looksLikeIp);
    const domains = lines.filter((l) => !looksLikeIp(l));
    setBusy(true);
    setResult(null);
    const sum = { added: 0, skipped: 0, invalid: [] };
    let failed = false;
    for (const [action, field, part] of [['add_domains', 'domains', domains],
      ['add_ips', 'ips', ips]]) {
      if (!part.length) continue;
      const payload = { [field]: part.join('\n'), target };
      if (action === 'add_domains') payload.mode = mode;
      const res = await api(action, payload, { quiet: true, timeout: APPLY_TIMEOUT_MS });
      if (!reportApply(res, 'Не удалось добавить')) {
        failed = true;
        break;
      }
      sum.added += Number(res.added) || 0;
      sum.skipped += Number(res.skipped) || 0;
      if (Array.isArray(res.invalid)) sum.invalid.push(...res.invalid);
    }
    setBusy(false);
    if (!failed || sum.added) setResult(sum);
    if (!failed) setText(sum.invalid.map((s) => s.line).join('\n'));
    if (sum.added) reload();
  }

  return html`
    <form class="rules-add" onSubmit=${submit}>
      <label class="field">
        <span class="field__label">Домены и IP — по одному в строке</span>
        <textarea class="input textarea mono rules-add__text" rows="3" value=${text}
          placeholder=${PLACEHOLDER} spellcheck="false" autocapitalize="off" autocomplete="off"
          disabled=${busy} onInput=${(e) => setText(e.currentTarget.value)}></textarea>
      </label>
      <div class="rules-add__opts">
        <label class="field">
          <span class="field__label">Куда</span>
          <${TargetSelect} value=${target} onChange=${setTarget} servers=${servers}
            label="Куда направлять новые правила" disabled=${busy} />
        </label>
        <label class="field">
          <span class="field__label">Домены</span>
          <select class="select" value=${mode} disabled=${busy}
            aria-label="Совпадение для новых доменов"
            onChange=${(e) => setMode(e.currentTarget.value)}>
            <option value="suffix">С поддоменами</option>
            <option value="full">Только этот домен</option>
          </select>
        </label>
        <${Button} variant="primary" type="submit" icon="plus" loading=${busy}
          class="rules-add__submit">${busy ? 'Добавляю…' : 'Добавить'}</${Button}>
      </div>
      ${result ? html`<${AddResult} result=${result} />` : null}
    </form>
  `;
}

// ---------- list ----------

function RuleRow({ rule, target, selected, v2flyList, busy, servers, handlers }) {
  const isDomain = rule.kind === 'domain';
  return html`
    <li class=${`rule ${selected ? 'is-selected' : ''}`} data-key=${rule.key}>
      <input type="checkbox" class="rule__check" checked=${selected} disabled=${busy}
        aria-label=${`Выбрать ${rule.value}`}
        onChange=${(e) => handlers.select(rule.key, e.currentTarget.checked)} />
      <span class="rule__text">
        <span class="rule__value mono">${rule.value}</span>
        <span class="rule__badges">
          ${isDomain ? null : html`<${Badge}>IP</${Badge}>`}
          ${v2flyList ? html`<span title="Домен есть в подключённом сервисе, но у правила своё
            направление"><${Badge} tone="orange">исключение из «${v2flyList}»</${Badge}></span>`
            : null}
        </span>
      </span>
      <span class="rule__controls">
        ${isDomain ? html`
          <select class="select select--sm" value=${rule.mode === 'full' ? 'full'
            : rule.mode === 'plain' ? 'plain' : 'suffix'} disabled=${busy}
            aria-label=${`Совпадение: ${rule.value}`}
            title=${rule.mode === 'plain'
              ? 'Старое правило: срабатывает на любой адрес, где встречается этот текст' : undefined}
            onChange=${(e) => handlers.setMode(rule, e.currentTarget.value)}>
            <option value="suffix">Поддомены</option>
            <option value="full">Точный</option>
            ${rule.mode === 'plain' ? html`<option value="plain" disabled>Вхождение</option>`
              : null}
          </select>` : null}
        <${TargetSelect} value=${target} servers=${servers} disabled=${busy}
          label=${`Куда направлять: ${rule.value}`}
          onChange=${(t) => handlers.setTarget(rule, t)} />
        <${Menu} label=${`Действия: ${rule.value}`} disabled=${busy} items=${[
          { label: 'Удалить', danger: true, onSelect: () => handlers.remove([rule.key]) },
        ]} />
      </span>
    </li>
  `;
}

function BulkBar({ count, busy, servers, onTarget, onDelete, onClear }) {
  const [target, setTarget] = useState(TARGET_PROXY);
  return html`
    <div class="bulk" role="region" aria-label="Действия с выбранными">
      <span class="bulk__count">Выбрано: <strong>${count}</strong></span>
      <div class="bulk__actions">
        <${TargetSelect} value=${target} onChange=${setTarget} servers=${servers}
          label="Куда направлять выбранные" disabled=${busy} />
        <${Button} size="sm" variant="secondary" disabled=${busy}
          onClick=${() => onTarget(target)}>Направить</${Button}>
        <${Button} size="sm" variant="danger" disabled=${busy} onClick=${onDelete}>
          Удалить</${Button}>
        <${Button} size="sm" variant="ghost" disabled=${busy} onClick=${onClear}>
          Снять выделение</${Button}>
      </div>
    </div>
  `;
}

export function RulesCard({ data, servers, reload, patch }) {
  const [query, setQuery] = useState('');
  const [filter, setFilter] = useState('all');
  const [limit, setLimit] = useState(PAGE);
  const [selected, setSelected] = useState(() => new Set());
  const [busy, setBusy] = useState(false);

  const rules = allRules(data);
  // Selection survives reloads, but only for rules that still exist.
  const existing = new Set(rules.map((r) => r.key));
  const picked = [...selected].filter((k) => existing.has(k));
  const counts = {
    all: rules.length,
    domain: data.manual.length,
    ip: data.ips.length,
  };
  const q = query.trim().toLowerCase();
  const shown = rules.filter((r) => (filter === 'all' || r.kind === filter)
    && (!q || r.value.includes(q)));
  const page = shown.slice(0, limit);
  const allPageSelected = page.length > 0 && page.every((r) => selected.has(r.key));

  function setSel(fn) {
    setSelected((s) => {
      const next = new Set(s);
      fn(next);
      return next;
    });
  }

  // One rules_batch for any number of edits; the list is reloaded afterwards either way.
  async function batch(ops, doneText, failText) {
    setBusy(true);
    const res = await api('rules_batch', { ops: JSON.stringify(ops) },
      { quiet: true, timeout: APPLY_TIMEOUT_MS });
    setBusy(false);
    const ok = reportApply(res, failText);
    if (ok) toast(doneText, 'success');
    await reload();
    return ok;
  }

  const handlers = {
    select(key, on) {
      setSel((s) => (on ? s.add(key) : s.delete(key)));
    },

    async setTarget(rule, target) {
      patch((d) => ({ ...d, targets: { ...d.targets, [rule.key]: target } }));
      setBusy(true);
      const res = await api('set_rule_target', { key: rule.key, target },
        { quiet: true, timeout: APPLY_TIMEOUT_MS });
      setBusy(false);
      if (reportApply(res, 'Не удалось сменить направление')) toast('Сохранено', 'success');
      reload();
    },

    async setMode(rule, mode) {
      patch((d) => ({ ...d, manual: d.manual.map((m) => (String(m.domain).toLowerCase()
        === rule.value ? { ...m, mode } : m)) }));
      setBusy(true);
      const res = await api('set_domain_match', { domain: rule.value, mode },
        { quiet: true, timeout: APPLY_TIMEOUT_MS });
      setBusy(false);
      if (reportApply(res, 'Не удалось сменить совпадение')) toast('Сохранено', 'success');
      reload();
    },

    async remove(keys) {
      const one = keys.length === 1;
      const ok = await confirm({
        title: one ? 'Удалить правило?' : `Удалить ${keys.length} ${plural(keys.length,
          RULE_FORMS)}?`,
        danger: true, confirmLabel: 'Удалить',
        message: one ? `«${splitKey(keys[0]).value}» перестанет направляться отдельно.`
          : 'Выбранные домены и IP перестанут направляться отдельно.',
      });
      if (!ok) return;
      const ops = keys.map((k) => ({ op: 'delete', ...splitKey(k) }));
      const done = await batch(ops, one ? 'Правило удалено'
        : `Удалено: ${keys.length} ${plural(keys.length, RULE_FORMS)}`, 'Не удалось удалить');
      if (done) setSel((s) => keys.forEach((k) => s.delete(k)));
    },
  };

  async function bulkTarget(target) {
    const keys = picked;
    const ops = keys.map((k) => ({ op: 'target', ...splitKey(k), arg: target }));
    const done = await batch(ops, `Направление изменено: ${keys.length} ${plural(keys.length,
      RULE_FORMS)}`, 'Не удалось изменить направление');
    if (done) setSelected(new Set());
  }

  async function dedup() {
    const ok = await confirm({
      title: 'Убрать дубли?', confirmLabel: 'Убрать',
      message: 'Удалю повторы, домены, которые уже есть в подключённых сервисах (кроме правил'
        + ' со своим направлением), и повторяющиеся IP.',
    });
    if (!ok) return;
    setBusy(true);
    const res = await api('dedup_rules', {}, { quiet: true, timeout: APPLY_TIMEOUT_MS });
    setBusy(false);
    if (reportApply(res, 'Не удалось убрать дубли')) {
      toast(`Убрано: доменов ${res.removed_domains || 0}, IP ${res.removed_ips || 0}`, 'success');
    }
    reload();
  }

  function togglePage(on) {
    setSel((s) => page.forEach((r) => (on ? s.add(r.key) : s.delete(r.key))));
  }

  let list;
  if (!rules.length) {
    list = html`<${EmptyState} icon="routes" title="Своих правил нет"
      text="Добавьте сайт или IP выше — он пойдёт через VPN, конкретный сервер или напрямую." />`;
  } else if (!shown.length) {
    list = html`<${EmptyState} icon="search" title="Ничего не найдено"
      action=${html`<${Button} onClick=${() => { setQuery(''); setFilter('all'); }}>
        Сбросить</${Button}>`} />`;
  } else {
    list = html`
      <label class="rules__all">
        <input type="checkbox" checked=${allPageSelected} disabled=${busy}
          onChange=${(e) => togglePage(e.currentTarget.checked)} />
        Выбрать все${shown.length > page.length ? ' показанные' : ''} (${page.length})
      </label>
      <ul class="rule-list" aria-label="Свои правила">
        ${page.map((r) => html`
          <${RuleRow} key=${r.key} rule=${r} target=${data.targets[r.key]}
            selected=${selected.has(r.key)} busy=${busy} servers=${servers}
            v2flyList=${r.kind === 'domain' ? data.v2fly[r.value] : null}
            handlers=${handlers} />`)}
      </ul>
      ${shown.length > page.length ? html`
        <${Button} variant="secondary" class="rules__more" onClick=${() => setLimit(limit + PAGE)}>
          Показать ещё (осталось ${shown.length - page.length})</${Button}>` : null}
    `;
  }

  return html`
    <${Card} title="Свои правила" class="rules">
      <${AddRulesForm} servers=${servers} reload=${reload} />
      <div class="rules__toolbar">
        <div class="rules__search">
          <${SearchInput} value=${query} onInput=${(v) => { setQuery(v); setLimit(PAGE); }}
            placeholder="Поиск по правилам" label="Поиск по своим правилам" />
        </div>
        <div class="fgroup__chips" role="group" aria-label="Показать">
          ${FILTERS.map((f) => html`
            <button key=${f.id} type="button" class="fchip"
              aria-pressed=${filter === f.id ? 'true' : 'false'}
              onClick=${() => { setFilter(f.id); setLimit(PAGE); }}>
              ${f.label} <span class="fchip__count">${counts[f.id]}</span></button>`)}
        </div>
        <${Button} variant="ghost" size="sm" disabled=${busy || !rules.length}
          onClick=${dedup}>Убрать дубли</${Button}>
      </div>
      ${picked.length ? html`<${BulkBar} count=${picked.length} busy=${busy} servers=${servers}
        onTarget=${bulkTarget} onDelete=${() => handlers.remove(picked)}
        onClear=${() => setSelected(new Set())} />` : null}
      ${list}
    </${Card}>
  `;
}
