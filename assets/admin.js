(() => {
  'use strict';
  const admin = window.STBDAdmin;
  if (!admin) return;

  const connectionBtn = document.getElementById('stbd-test-connection');
  const connectionResults = document.getElementById('stbd-connection-results');
  if (connectionBtn && connectionResults) {
    connectionBtn.addEventListener('click', async () => {
      connectionBtn.disabled = true;
      connectionResults.textContent = 'Testing connection…';
      try {
        const data = new FormData();
        data.set('action', 'stbd_test_connection');
        data.set('nonce', admin.connectionNonce);
        const response = await fetch(admin.url, { method: 'POST', credentials: 'same-origin', body: data });
        const body = await response.json();
        if (!response.ok || !body.success) {
          throw new Error((body.data && body.data.message) || 'Connection test failed.');
        }
        connectionResults.textContent = body.data.message;
      } catch (error) {
        connectionResults.textContent = error.message;
      } finally {
        connectionBtn.disabled = false;
      }
    });
  }

  const form = document.getElementById('stbd-diagnostic');
  if (!form) return;
  const results = document.getElementById('stbd-test-results');
  form.addEventListener('submit', async event => {
    event.preventDefault();
    const tokenField = form.querySelector('[name="stbd_turnstile_token"]');
    const token = tokenField ? tokenField.value : '';
    const button = form.querySelector('[type="submit"]');
    button.disabled = true;
    results.textContent = 'Running security tests…';
    const verify = async value => {
      const data = new FormData();
      data.set('action', 'stbd_test'); data.set('nonce', admin.nonce);
      data.set('stbd_turnstile_token', value);
      const response = await fetch(admin.url, { method: 'POST', credentials: 'same-origin', body: data });
      const body = await response.json();
      if (!response.ok || !body.success) throw new Error(body.data?.message || 'Test request failed.');
      return body.data;
    };
    try {
      const checks = [];
      for (const [label, value, expected] of [['Missing token rejected', '', false], ['Invalid token rejected', 'invalid-test-token', false], ['Valid token accepted', token, true], ['Reused token rejected', token, false]]) {
        const data = await verify(value);
        checks.push({ label, passed: data.hook_ran && data.accepted === expected && (expected || data.turnstile_rejected) });
      }
      results.replaceChildren();
      const headline = document.createElement('strong');
      headline.textContent = checks.every(check => check.passed) ? 'All four security checks passed.' : 'One or more checks failed. Review the keys, hostname, and installed Breakdance version.';
      const list = document.createElement('ul');
      checks.forEach(check => { const item = document.createElement('li'); item.textContent = `${check.passed ? 'PASS' : 'FAIL'} — ${check.label}`; list.append(item); });
      results.append(headline, list);
    } catch (error) {
      results.textContent = error.message;
    } finally {
      button.disabled = false;
      form.dispatchEvent(new Event('stbd:reset'));
    }
  });
})();
