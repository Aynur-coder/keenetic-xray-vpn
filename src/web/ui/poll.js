// useVisiblePolling: runs `task` now and every `ms` while the browser tab is visible and
// `enabled` is true; catches up as soon as the tab becomes visible again. The latest `task`
// is always the one called, so it may close over fresh state. A change in `deps` restarts
// the cycle (immediate run), e.g. when a filter changes.
import { useEffect, useRef } from './vendor/preact-htm.js';

export function useVisiblePolling(task, ms, enabled = true, deps = []) {
  const latest = useRef(task);
  latest.current = task;
  useEffect(() => {
    if (!enabled) return undefined;
    let timer = null;
    const run = () => latest.current();
    const stop = () => {
      clearInterval(timer);
      timer = null;
    };
    const start = () => {
      stop();
      if (document.visibilityState !== 'visible') return;
      run();
      timer = setInterval(run, ms);
    };
    document.addEventListener('visibilitychange', start);
    start();
    return () => {
      stop();
      document.removeEventListener('visibilitychange', start);
    };
  }, [enabled, ms, ...deps]);
}
