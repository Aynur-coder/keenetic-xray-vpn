// Маршруты: pieces shared by routes.js, routes-services.js and routes-rules.js —
// target constants, the target <select> and the apply-outcome toast.
import { html } from '../vendor/preact-htm.js';
import { errorText } from '../api.js';
import { toast } from '../components/toast.js';

// Apply-backed rule changes wait up to the backend's 60 s apply lock.
export const APPLY_TIMEOUT_MS = 70000;

export const TARGET_PROXY = 'proxy';
export const TARGET_DIRECT = 'direct';

// <select> choices for a rule target, built once per render of the screen (not per row):
// every server grouped by its source. TargetSelect adds «VPN (активный сервер)» and
// «Напрямую» around them.
export function targetChoices(serversData) {
  const sources = (serversData && serversData.sources) || [];
  const servers = (serversData && serversData.servers) || [];
  const groups = sources.map((src) => ({
    label: src.kind === 'keys' ? 'Мои ключи' : (src.name || src.id),
    options: servers.filter((s) => s.source === src.id).map((s) => ({
      value: s.id,
      label: `${s.name || s.id}${src.enabled ? '' : ' — подписка выключена'}`,
    })),
  })).filter((g) => g.options.length);
  return { groups, ids: new Set(servers.map((s) => s.id)) };
}

// A `value` that is no longer a known server stays selectable, so the select shows the
// truth instead of silently jumping to another option.
export function TargetSelect({ value, onChange, label, disabled = false, choices, id }) {
  const current = value || TARGET_PROXY;
  const known = current === TARGET_PROXY || current === TARGET_DIRECT || choices.ids.has(current);
  return html`
    <select class="select" id=${id} aria-label=${label} value=${current} disabled=${disabled}
      onChange=${(e) => onChange(e.currentTarget.value)}>
      <option value=${TARGET_PROXY}>VPN (активный сервер)</option>
      ${choices.groups.map((g) => html`
        <optgroup key=${g.label} label=${g.label}>
          ${g.options.map((o) => html`<option key=${o.value} value=${o.value}>${o.label}</option>`)}
        </optgroup>`)}
      ${known ? null : html`<option value=${current}>Удалённый сервер (идёт через активный)</option>`}
      <option value=${TARGET_DIRECT}>Напрямую</option>
    </select>
  `;
}

// Outcome of an apply-backed change: toast on failure, a warning when Xray didn't start.
export function reportApply(res, failText) {
  if (res.error) {
    toast(`${failText}: ${errorText(res.error)}`, 'error');
    return false;
  }
  if (res.xray_running === false) toast('Xray не запустился — подробности в событиях', 'error');
  else if (res.warning === 'server_disabled') {
    toast('Подписка этого сервера выключена — пока трафик идёт через активный сервер', 'info');
  }
  return true;
}
