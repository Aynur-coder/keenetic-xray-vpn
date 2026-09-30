// Диагностика → Проверка сайта: one domain → its IPs, the rule that routes it, the exit,
// the exit country as Google and Cloudflare see it through THAT exit (`country_via`: the
// server the rule picks, or «Напрямую»), and recent direct/QUIC flows to it (api
// `site_check`; up to ~30 s on the router when it has to probe a server), summed up in one
// plain sentence.
import { html, useState } from '../vendor/preact-htm.js';
import { api, errorText } from '../api.js';
import { useStore } from '../store.js';
import { Button } from '../components/button.js';
import { Card } from '../components/card.js';
import { Icon } from '../components/icons.js';
import { explainQuery, ruleText, vpnSetText } from './routes.js';
import { TARGET_PROXY } from './routes-common.js';
import { flagOf } from './overview.js';

// The backend may start a server probe (≤ ~25 s) on top of DNS/route/flow work.
const TIMEOUT_MS = 40000;

const DIRECT_NAME = 'Напрямую';

function countryText(cc) {
  return cc ? `${flagOf(cc)} ${cc}`.trim() : 'не удалось определить';
}

// Name of the exit a VPN-routed site uses: the server the backend measured through
// (country_via), else a pinned server's own name, or for «VPN (активный сервер)» the
// server that is actually carrying traffic now.
function exitName(result, overview) {
  const ex = result.explain || {};
  if (result.country_via && result.country_via !== DIRECT_NAME) return result.country_via;
  if (ex.target === TARGET_PROXY || !ex.target) {
    const srv = overview && (overview.effective || overview.active);
    return srv && srv.name ? srv.name : '';
  }
  return ex.target_name || ex.target;
}

export function verdictText(result, overview) {
  if (result.via !== 'vpn') return 'Идёт напрямую — сайт видит ваш настоящий IP.';
  const name = exitName(result, overview);
  const google = result.google_country
    ? ` Google видит: ${countryText(result.google_country)}.` : '';
  return `Идёт через VPN${name ? ` (${name})` : ''}.${google}`;
}

// «Google видит (через 🇪🇪 Tallinn)» — which exit the country was measured through.
function googleLabel(via) {
  if (!via) return 'Google видит';
  return via === DIRECT_NAME ? 'Google видит (напрямую)' : `Google видит (через ${via})`;
}

// Things worth a second look; each only when it actually happened.
function warningsOf(result) {
  const list = [];
  // A direct site is expected to look Russian; only a VPN exit seen as RU is a problem.
  if (result.via === 'vpn' && result.google_country === 'RU') {
    list.push({ tone: 'orange', text: 'Google считает выход российским',
      sub: 'Сервисы Google могут вести себя как в России (регион, ограничения).' });
  }
  if (result.via === 'vpn' && result.direct_flows > 0) {
    list.push({ tone: 'orange', text: `Прямых соединений к сайту: ${result.direct_flows}`,
      sub: 'Часть трафика к этому сайту идёт мимо VPN.' });
  }
  if (result.quic_leaks > 0) {
    list.push({ tone: 'red', text: `QUIC-утечек: ${result.quic_leaks}`,
      sub: 'Браузер обходит VPN по QUIC (UDP 443) — сайт может видеть ваш настоящий IP.' });
  }
  return list;
}

function SiteResult({ result }) {
  const overview = useStore((s) => s.overview);
  const ex = result.explain || {};
  const ips = Array.isArray(result.ips) ? result.ips : [];
  const warnings = warningsOf(result);
  const vpn = result.via === 'vpn';
  return html`
    <div class="site__result" data-via=${result.via}>
      <p class="site__verdict">
        <${Icon} name=${vpn ? 'servers' : 'swap'} size=${20} class=${vpn ? 'tone-accent' : ''} />
        <strong>${verdictText(result, overview)}</strong>
      </p>
      ${warnings.length ? html`
        <ul class="callouts site__warnings">
          ${warnings.map((w) => html`
            <li key=${w.text} class=${`callout callout--${w.tone}`}>
              <${Icon} name="alert" />
              <p class="callout__text"><strong>${w.text}</strong>
                <span class="callout__sub">${w.sub}</span></p>
            </li>`)}
        </ul>` : null}
      <dl class="explain__facts site__facts">
        <div><dt>Сайт</dt><dd>${result.domain}</dd></div>
        <div><dt>IP-адреса</dt>
          <dd class="mono">${ips.length ? ips.join(', ') : 'не удалось получить'}</dd></div>
        <div><dt>Правило</dt><dd>${ruleText(ex)}</dd></div>
        <div><dt>В VPN-наборе</dt><dd>${vpnSetText(result.in_vpn_set)}</dd></div>
        <div><dt>Выход</dt>
          <dd>${vpn ? (exitName(result, overview) || 'VPN') : 'Напрямую'}</dd></div>
        <div><dt>${googleLabel(result.country_via)}</dt>
          <dd class="site__google">${countryText(result.google_country)}
            ${result.country_error ? html` <span class="muted site__reason">
              (${result.country_error})</span>` : null}</dd></div>
        <div><dt>Cloudflare видит</dt><dd>${countryText(result.exit_country)}</dd></div>
        ${result.exit_ip ? html`<div><dt>IP выхода</dt>
          <dd class="mono">${result.exit_ip}</dd></div>` : null}
        ${result.direct_flows != null ? html`<div><dt>Прямые соединения</dt>
          <dd>${result.direct_flows}</dd></div>` : null}
        ${result.quic_leaks != null ? html`<div><dt>QUIC-утечки</dt>
          <dd>${result.quic_leaks}</dd></div>` : null}
      </dl>
    </div>
  `;
}

export function SiteCheckTab() {
  const [text, setText] = useState('');
  const [state, setState] = useState({ busy: false, result: null, error: '' });

  async function onSubmit(e) {
    e.preventDefault();
    if (state.busy) return;
    const domain = explainQuery(text);
    if (!domain) {
      setState({ busy: false, result: null, error: 'Введите домен, например youtube.com' });
      return;
    }
    setState({ busy: true, result: null, error: '' });
    const res = await api('site_check', { domain }, {
      method: 'GET', quiet: true, timeout: TIMEOUT_MS });
    setState(res.error ? { busy: false, result: null, error: errorText(res.error) }
      : { busy: false, result: res, error: '' });
  }

  return html`
    <${Card} class="diag-card site">
      <form class="site__form" onSubmit=${onSubmit} noValidate>
        <label class="sr-only" for="site-domain">Домен</label>
        <input id="site-domain" class="input site__input" type="text" value=${text}
          placeholder="Сайт: youtube.com" spellcheck="false" autocapitalize="off"
          autocomplete="off" inputmode="url" aria-invalid=${state.error ? 'true' : undefined}
          aria-describedby=${state.error ? 'site-error' : undefined}
          onInput=${(e) => setText(e.currentTarget.value)} />
        <${Button} type="submit" variant="primary" loading=${state.busy}>Проверить</${Button}>
      </form>
      <div class="site__out" aria-live="polite">
        ${state.busy ? html`<p class="muted"><span class="spinner"></span>
            Проверяю — DNS, маршрут и страну выхода, до 30 секунд…</p>`
          : state.error ? html`<p class="tone-red" id="site-error" role="alert">${state.error}</p>`
          : state.result ? html`<${SiteResult} result=${state.result} />`
          : html`<p class="muted">Покажу, куда уходит сайт, какую страну видят Google и
              Cloudflare и нет ли соединений в обход VPN.</p>`}
      </div>
    </${Card}>
  `;
}
