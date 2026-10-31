/* Turnstile Connector for Breakdance: no secrets are sent to this script. */
(() => {
  'use strict';
  const config = window.STBDConfig;
  if (!config) return;
  const states = new Map();
  let apiPromise;

  function loadApi() {
    if (apiPromise) return apiPromise;
    apiPromise = new Promise((resolve, reject) => {
      const started = Date.now();
      let timer;
      const check = () => {
        if (window.turnstile && typeof window.turnstile.render === 'function') {
          clearInterval(timer);
          resolve(window.turnstile);
        } else if (Date.now() - started > 20000) {
          clearInterval(timer);
          reject(new Error('Security check could not load. Please check your connection and retry.'));
        }
      };
      const existing = [...document.scripts].some(s => s.src.startsWith('https://challenges.cloudflare.com/turnstile/v0/api.js'));
      if (!existing) {
        const script = document.createElement('script');
        script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
        script.async = true;
        script.dataset.stbdApi = 'true';
        script.onerror = () => { clearInterval(timer); script.remove(); reject(new Error('Security check could not load. Please check your connection and retry.')); };
        document.head.append(script);
      }
      timer = setInterval(check, 100);
      check();
    }).catch(error => { apiPromise = null; throw error; });
    return apiPromise;
  }

  function message(state, text, error = false) {
    state.status.textContent = text;
    state.status.dataset.error = String(error);
    state.retry.hidden = !error;
  }

  function reset(state) {
    state.token.value = '';
    state.sent = false;
    message(state, '');
    if (state.widgetId !== null && window.turnstile) {
      window.turnstile.reset(state.widgetId);
    } else {
      render(state);
    }
  }

  async function render(state) {
    if (state.rendering || state.widgetId !== null || !state.form.isConnected) return;
    if (!state.siteKey) {
      message(state, 'Security verification is unavailable. Please try again later.', true);
      return;
    }
    state.rendering = true;
    message(state, '');
    try {
      const api = await loadApi();
      if (!state.form.isConnected) return;
      state.widgetId = api.render(state.widget, {
        sitekey: state.siteKey,
        action: state.action,
        theme: config.theme || 'auto',
        size: state.widget.clientWidth > 0 && state.widget.clientWidth < 300 ? 'compact' : 'flexible',
        'response-field': false,
        'refresh-expired': 'auto',
        callback: token => { state.token.value = token; message(state, ''); },
        'expired-callback': () => { state.token.value = ''; message(state, 'Verification expired. Please wait for the check to refresh.'); },
        'timeout-callback': () => { state.token.value = ''; message(state, 'Verification timed out. Please retry.', true); },
        'error-callback': () => { state.token.value = ''; message(state, 'Security verification is unavailable. Please retry.', true); return true; }
      });
    } catch (error) {
      message(state, error.message, true);
    } finally {
      state.rendering = false;
    }
  }

  function initialize(form) {
    if (states.has(form)) return;
    const diagnostic = form.dataset.stbdDiagnostic === 'true';
    let options;
    try { options = JSON.parse(form.dataset.options || '{}'); } catch (_) { return; }
    if (!diagnostic && options.slug !== 'custom') return;
    const post = form.querySelector('input[name="post_id"]')?.value;
    const id = form.querySelector('input[name="form_id"]')?.value;
    if (!/^\d+$/.test(post || '') || !/^\d+$/.test(id || '')) return;
    // Native horizontal forms use implicit grid columns. Give the challenge a
    // full row without squeezing the inputs into those implicit columns.
    if (!diagnostic && (form.classList.contains('breakdance-form--horizontal') || getComputedStyle(form).gridAutoFlow.includes('column'))) {
      form.classList.add('stbd-horizontal-form');
    }
    const wrapper = document.createElement('div');
    wrapper.className = 'stbd-verification';
    wrapper.tabIndex = -1;
    const widget = document.createElement('div');
    widget.className = 'stbd-widget';
    const token = document.createElement('input');
    token.type = 'hidden'; token.name = 'stbd_turnstile_token';
    const status = document.createElement('p');
    status.className = 'stbd-status'; status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
    const retry = document.createElement('button');
    retry.type = 'button'; retry.className = 'stbd-retry'; retry.textContent = 'Retry security check'; retry.hidden = true;
    wrapper.append(widget, token, status, retry);
    const footer = form.querySelector('.breakdance-form-footer, .stbd-test-footer');
    if (footer) footer.before(wrapper); else form.append(wrapper);
    const state = { form, wrapper, widget, token, status, retry, siteKey: config.siteKey, action: `stbd_p${Number(post)}_f${Number(id)}`, widgetId: null, rendering: false, sent: false, wasLoading: false };
    states.set(form, state);
    retry.addEventListener('click', () => reset(state));
    form.addEventListener('submit', event => {
      if (!token.value || state.sent) {
        event.preventDefault(); event.stopImmediatePropagation();
        if (!state.sent) { message(state, 'Please complete the security check before submitting.', true); wrapper.focus(); }
        return;
      }
      state.sent = true;
    }, true);
    form.addEventListener('reset', () => { if (!diagnostic) queueMicrotask(() => reset(state)); });
    form.addEventListener('stbd:reset', () => reset(state));
    const observer = new MutationObserver(() => {
      const loading = form.classList.contains('is-loading');
      if (state.wasLoading && !loading) reset(state);
      state.wasLoading = loading;
    });
    observer.observe(form, { attributes: true, attributeFilter: ['class'] });
    state.observer = observer;
    render(state);
  }

  function scan() {
    document.querySelectorAll('form.breakdance-form, form[data-stbd-diagnostic="true"]').forEach(initialize);
    states.forEach((state, form) => {
      if (!form.isConnected) {
        state.observer.disconnect();
        if (state.widgetId !== null && window.turnstile) window.turnstile.remove(state.widgetId);
        states.delete(form);
      }
    });
  }
  function start() {
    scan();
    let queued = false;
    new MutationObserver(() => {
      if (queued) return;
      queued = true;
      queueMicrotask(() => { queued = false; scan(); });
    }).observe(document.body, { childList: true, subtree: true });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true }); else start();
})();
