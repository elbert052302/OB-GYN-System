(function () {
  'use strict';

  const apiBase = new URL('./api/', document.currentScript.src);
  let csrfToken = '';

  async function request(action, payload) {
    const options = {
      method: payload ? 'POST' : 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    };

    if (payload) {
      if (!csrfToken) {
        const csrfResponse = await fetch(new URL('auth.php?action=csrf', apiBase), {
          method: 'GET',
          credentials: 'same-origin',
          headers: { Accept: 'application/json' }
        });
        const csrfBody = await csrfResponse.json();
        if (!csrfResponse.ok) throw new Error(csrfBody.error || 'Could not prepare a secure request.');
        csrfToken = csrfBody.csrfToken;
      }
      options.headers['Content-Type'] = 'application/json';
      options.headers['X-CSRF-Token'] = csrfToken;
      options.body = JSON.stringify(payload);
    }

    const response = await fetch(new URL(`auth.php?action=${encodeURIComponent(action)}`, apiBase), options);
    const result = await response.json();
    if (result.csrfToken) csrfToken = result.csrfToken;
    if (!response.ok) throw new Error(result.error || 'The request could not be completed.');
    return result;
  }

  function showMessage(target, message, isError) {
    target.textContent = message;
    target.setAttribute('role', isError ? 'alert' : 'status');
    target.style.color = isError ? '#9b3d3d' : '';
  }

  function populateTimeZoneSelect(id) {
    const select = document.getElementById(id);
    if (!select) return;

    const localTimeZone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    const supportedTimeZones = typeof Intl.supportedValuesOf === 'function'
      ? Intl.supportedValuesOf('timeZone')
      : [];
    const timeZones = Array.from(new Set([...supportedTimeZones, localTimeZone])).sort();
    for (const timeZone of timeZones) {
      const option = document.createElement('option');
      option.value = timeZone;
      option.textContent = timeZone;
      select.append(option);
    }
    select.value = localTimeZone;
  }

  function appendVerification(form, email, role, targetUrl, developmentCode) {
    form.hidden = true;
    const panel = document.createElement('section');
    panel.className = 'verification-box';
    const title = document.createElement('h2');
    title.textContent = 'Verify your email';
    const description = document.createElement('p');
    description.textContent = developmentCode
      ? `Local development verification code: ${developmentCode}`
      : `Enter the six-digit code sent to ${email}.`;
    const verificationForm = document.createElement('form');
    const field = document.createElement('div');
    field.className = 'field';
    const label = document.createElement('label');
    label.textContent = 'Verification code';
    const code = document.createElement('input');
    code.type = 'text';
    code.inputMode = 'numeric';
    code.autocomplete = 'one-time-code';
    code.pattern = '[0-9]{6}';
    code.maxLength = 6;
    code.required = true;
    label.htmlFor = 'emailVerificationCode';
    code.id = 'emailVerificationCode';
    field.append(label, code);
    const button = document.createElement('button');
    button.type = 'submit';
    button.className = form.querySelector('button[type="submit"]')?.className || 'primary-btn';
    button.textContent = 'Verify Email & Continue';
    const status = document.createElement('p');
    status.className = 'auth-status';
    status.setAttribute('aria-live', 'polite');
    verificationForm.append(field, button, status);
    panel.append(title, description, verificationForm);
    form.insertAdjacentElement('afterend', panel);

    verificationForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      button.disabled = true;
      try {
        const result = await request('verify', { email, code: code.value.trim() });
        if (result.user.role !== role) throw new Error('The verified account type does not match this registration.');
        window.location.href = targetUrl;
      } catch (error) {
        showMessage(status, error.message, true);
        button.disabled = false;
      }
    });
  }

  async function requireRole(role, loginUrl) {
    try {
      const result = await request('me');
      if (result.user.role !== role) window.location.replace(loginUrl);
      return result.user.role === role ? result.user : null;
    } catch (error) {
      window.location.replace(loginUrl);
      return null;
    }
  }

  window.GentleCareApi = {
    request,
    showMessage,
    populateTimeZoneSelect,
    appendVerification,
    requireRole
  };
}());
