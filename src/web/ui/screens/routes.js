// Маршруты: «Куда пойдёт…» (route_explain) on top, then Сервисы (v2fly/GitHub lists)
// and Свои правила (domains and IPs). All rule data is loaded here once and reloaded
// after every change, so the three blocks always show what the router has stored.
import { html, useState, useEffect, useRef, useMemo } from '../vendor/preact-htm.js';
import { api, errorText, refreshServers } from '../api.js';
import { useStore } from '../store.js';
import { Button } from '../components/button.js';
import { Card } from '../components/card.js';
import { EmptyState } from '../components/empty.js';
import { Icon } from '../components/icons.js';
import { TARGET_DIRECT, targetChoices } from './routes-common.js';
import { ServicesCard } from './routes-services.js';
import { RulesCard } from './routes-rules.js';

const EXPLAIN_DEBOUNCE_MS = 450;

// ---------- «Куда пойдёт…» ----------

// What people paste → what route_explain accepts: 'https://x.com/a?b' → 'x.com'.
export function explainQuery(text) {
  let s = String(text || '').trim().toLowerCase();
  if (/^[\d.]+(\/\d+)?$/.test(s) || (s.split(':').length > 2 && /^[0-9a-f:.]+$/.test(s))) return s;
  s = s.replace(/^[a-z][a-z0-9+.-]*:\/\//, '').split(/[/?#]/)[0];
  s = s.slice(s.lastIndexOf('@') + 1);
  const v6 = /^\[([0-9a-f:.]+)\](:\d+)?$/.exec(s);
  if (v6) return v6[1];
  if (s.split(':').length === 2) s = s.replace(/:\d*$/, '');
  return s.replace(/^\*\./, '').replace(/^\.+|\.+$/g, '');
}

function ruleText(r) {
  if (!r.rule) return 'Ни одно правило не подошло — по умолчанию напрямую';
  if (r.rule.startsWith('list:')) return `Сервис «${r.rule.slice(5)}»`;
  const m = /^(domain|full|keyword|regexp):(.*)$/.exec(r.rule);
  if (!m) return r.kind === 'ip' ? `IP-правило ${r.rule}` : `Правило «${r.rule}» (вхождение)`;
  const what = { domain: 'и поддомены', full: 'только этот домен', keyword: 'вхождение',
    regexp: 'регулярное выражение' }[m[1]];
  return `Правило «${m[2]}» (${what})`;
}

function vpnSetText(v) {
  if (v === true) return 'да';
  if (v === false) return 'нет';
  return 'не удалось проверить';
}

function ExplainResult({ result }) {
  const direct = result.target === TARGET_DIRECT;
  const name = result.target_name || result.target;
  return html`
    <div class="explain__result" data-target=${result.target}>
      <p class="explain__verdict">
        <${Icon} name=${direct ? 'swap' : 'servers'} size=${18} />
        <span><span class="muted">${result.input} → </span>
          <strong class=${direct ? '' : 'tone-accent'}>${name}</strong></span>
      </p>
      <dl class="explain__facts">
        <div><dt>Правило</dt><dd class="explain__rule">${ruleText(result)}</dd></div>
        <div><dt>В VPN-наборе</dt>
          <dd class="explain__set">${vpnSetText(result.in_vpn_set)}</dd></div>
      </dl>
    </div>
  `;
}

function ExplainCard() {
  const [text, setText] = useState('');
  const [state, setState] = useState({ busy: false, result: null, error: null });
  const seq = useRef(0);

  useEffect(() => {
    const q = explainQuery(text);
    const my = ++seq.current;
    if (!q) {
      setState({ busy: false, result: null, error: null });
      return undefined;
    }
    setState((s) => ({ ...s, busy: true }));
    const timer = setTimeout(async () => {
      const res = await api('route_explain', { q }, { method: 'GET', quiet: true });
      if (my !== seq.current) return; // a newer query is on its way
      setState(res.error ? { busy: false, result: null, error: errorText(res.error) }
        : { busy: false, result: res, error: null });
    }, EXPLAIN_DEBOUNCE_MS);
    return () => clearTimeout(timer);
  }, [text]);

  return html`
    <${Card} title="Куда пойдёт…" class="explain">
      <label class="field">
        <span class="sr-only">Домен или IP</span>
        <div class="search">
          <${Icon} name="routes" size=${18} class="search__icon" />
          <input type="text" class="search__input explain__input" value=${text}
            placeholder="Сайт или IP: youtube.com, 1.2.3.4" spellcheck="false"
            autocapitalize="off" autocomplete="off" inputmode="url"
            onInput=${(e) => setText(e.currentTarget.value)} />
          ${state.busy ? html`<span class="spinner explain__spin" aria-hidden="true"></span>` : null}
        </div>
      </label>
      <div aria-live="polite" class="explain__out">
        ${state.error ? html`<p class="tone-red explain__error">${state.error}</p>`
          : state.result ? html`<${ExplainResult} result=${state.result} />`
            : html`<p class="muted explain__hint">Покажу, какое правило сработает и через какой
                сервер пойдёт трафик.</p>`}
      </div>
    </${Card}>
  `;
}

// ---------- data ----------

async function loadRoutes() {
  const [lists, domains, ips, targets] = await Promise.all([
    api('github_lists', null, { quiet: true }),
    api('domains', null, { quiet: true }),
    api('ips', null, { quiet: true }),
    api('rule_targets', null, { quiet: true }),
  ]);
  const failed = [lists, domains, ips, targets].find((r) => r && r.error);
  if (failed) return { error: errorText(failed.error) };
  return {
    lists: Array.isArray(lists) ? lists : [],
    manual: domains && Array.isArray(domains.manual) ? domains.manual : [],
    v2fly: domains && domains.v2fly && typeof domains.v2fly === 'object' ? domains.v2fly : {},
    ips: Array.isArray(ips) ? ips : [],
    targets: targets && typeof targets === 'object' && !Array.isArray(targets) ? targets : {},
  };
}

export function RoutesScreen() {
  const servers = useStore((s) => s.servers);
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const choices = useMemo(() => targetChoices(servers), [servers]);

  async function reload() {
    const res = await loadRoutes();
    if (res.error) {
      setError(res.error);
      return;
    }
    setError(null);
    setData(res);
  }

  useEffect(() => {
    reload();
    if (!servers) refreshServers();
  }, []);

  // Optimistic local edit (target/mode changes); reload() brings back the stored truth.
  function patch(fn) {
    setData((d) => (d ? fn(d) : d));
  }

  let body;
  if (!data) {
    body = error
      ? html`<${EmptyState} icon="alert" title="Не удалось загрузить правила" text=${error}
          action=${html`<${Button} onClick=${reload}>Повторить</${Button}>`} />`
      : html`<p class="muted srv-loading"><span class="spinner"></span> Загрузка…</p>`;
  } else {
    const shared = { data, choices, reload, patch };
    body = html`
      <${ServicesCard} ...${shared} />
      <${RulesCard} ...${shared} />
    `;
  }

  return html`
    <div class="routes">
      <${ExplainCard} />
      ${body}
    </div>
  `;
}
