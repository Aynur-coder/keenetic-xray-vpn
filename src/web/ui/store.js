// Shared app state: one plain object, shallow-merged patches, subscriber callbacks.
// Components read it through useStore(selector) and re-render only when their slice changes.
import { useState, useEffect } from './vendor/preact-htm.js';

const state = {
  overview: null, // last successful `overview` response (kept while offline)
  ips: null,      // last `check_ips` response
  servers: null,  // last `servers` response
  offline: false, // true after a network failure / timeout, false after any answer
  theme: 'auto',  // 'auto' | 'light' | 'dark' (the preference, not the resolved value)
};

const listeners = new Set();

export const store = {
  get() {
    return state;
  },
  set(patch) {
    let changed = false;
    for (const key of Object.keys(patch)) {
      if (state[key] !== patch[key]) {
        state[key] = patch[key];
        changed = true;
      }
    }
    if (changed) listeners.forEach((fn) => fn(state));
  },
  subscribe(fn) {
    listeners.add(fn);
    return () => listeners.delete(fn);
  },
};

export function useStore(selector) {
  const [value, setValue] = useState(() => selector(state));
  useEffect(() => {
    // Re-read once in case the store changed between render and subscribe.
    setValue(() => selector(state));
    return store.subscribe((s) => setValue(() => selector(s)));
  }, []);
  return value;
}
