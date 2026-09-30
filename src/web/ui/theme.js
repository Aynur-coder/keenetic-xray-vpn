// Theme preference: 'auto' | 'light' | 'dark'. Resolved to <html data-theme="light|dark">;
// 'auto' follows the system and keeps following it (change listener). Persisted in
// localStorage (same key as the legacy UI) and on the router via set_features.theme.
import { store } from './store.js';
import { api } from './api.js';

const STORAGE_KEY = 'xrayvpn:theme';
const THEMES = ['auto', 'light', 'dark'];
const darkQuery = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

function resolve(pref) {
  if (pref === 'light' || pref === 'dark') return pref;
  return darkQuery && !darkQuery.matches ? 'light' : 'dark';
}

function apply(pref) {
  document.documentElement.dataset.theme = resolve(pref);
}

if (darkQuery) {
  const onSystemChange = () => {
    if (store.get().theme === 'auto') apply('auto');
  };
  if (darkQuery.addEventListener) darkQuery.addEventListener('change', onSystemChange);
  else if (darkQuery.addListener) darkQuery.addListener(onSystemChange);
}

function remember(pref) {
  try {
    localStorage.setItem(STORAGE_KEY, pref);
  } catch (e) {
    // private mode / blocked storage: the router copy still persists it
  }
}

// Called once at startup, before the first render, to avoid a flash of the wrong theme.
export function initTheme() {
  let pref = 'auto';
  try {
    pref = localStorage.getItem(STORAGE_KEY) || 'auto';
  } catch (e) {
    pref = 'auto';
  }
  if (!THEMES.includes(pref)) pref = 'auto';
  store.set({ theme: pref });
  apply(pref);
}

// The router's copy wins (it is shared between devices); no write-back.
export function adoptRouterTheme(pref) {
  if (!THEMES.includes(pref) || pref === store.get().theme) return;
  store.set({ theme: pref });
  apply(pref);
  remember(pref);
}

// User choice: apply immediately, then persist locally and on the router.
export async function setTheme(pref) {
  if (!THEMES.includes(pref)) return;
  store.set({ theme: pref });
  apply(pref);
  remember(pref);
  await api('set_features', { theme: pref });
}
