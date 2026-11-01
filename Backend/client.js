(function () {
  'use strict';

  const apiBase = new URL('./api/', document.currentScript.src);
  let csrfToken = '';

  async function fetchJson(url, options) {
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 15000);
    try {
      const response = await fetch(url, { ...options, signal: controller.signal });
      let body;
      try {
        body = await response.json();
      } catch {
        throw new Error('GentleCare returned an unexpected response. Please try again later.');
      }
      return { response, body };
    } catch (error) {
      if (error.name === 'AbortError') {
        throw new Error('GentleCare took too long to respond. Please try again.');
      }
      if (error instanceof TypeError) {
        throw new Error('Unable to connect to GentleCare. Check your connection and try again.');
      }
      throw error;
    } finally {
      window.clearTimeout(timeout);
    }
  }

  async function requestApi(endpoint, action, payload) {
    const options = {
      method: payload ? 'POST' : 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    };

    if (payload) {
      if (!csrfToken) {
        const { response: csrfResponse, body: csrfBody } = await fetchJson(new URL('auth.php?action=csrf', apiBase), {
          method: 'GET',
          credentials: 'same-origin',
          headers: { Accept: 'application/json' }
        });
        if (!csrfResponse.ok) throw new Error(responseError(csrfBody, 'Could not prepare a secure request.'));
        if (!csrfBody || typeof csrfBody.csrfToken !== 'string' || csrfBody.csrfToken === '') {
          throw new Error('Could not prepare a secure request. Refresh the page and try again.');
        }
        csrfToken = csrfBody.csrfToken;
      }
      options.headers['Content-Type'] = 'application/json';
      options.headers['X-CSRF-Token'] = csrfToken;
      options.body = JSON.stringify(payload);
    }

    const requestUrl = action
      ? `${endpoint}?action=${encodeURIComponent(action)}`
      : endpoint;
    const { response, body: result } = await fetchJson(new URL(requestUrl, apiBase), options);
    if (result && typeof result === 'object' && result.csrfToken) csrfToken = result.csrfToken;
    if (!response.ok) {
      if (response.status === 403) csrfToken = '';
      throw new Error(responseError(result, 'The request could not be completed.'));
    }
    return result;
  }

  function responseError(body, fallback) {
    if (!body || typeof body !== 'object') return fallback;
    if (typeof body.error === 'string') return body.error;
    if (body.error && typeof body.error.message === 'string') return body.error.message;
    return fallback;
  }

  function request(action, payload) {
    return requestApi('auth.php', action, payload);
  }

  function showMessage(target, message, isError) {
    target.textContent = message;
    target.setAttribute('role', isError ? 'alert' : 'status');
    target.style.color = isError ? '#9b3d3d' : '';
  }

  async function populateCountrySelect(id) {
    const select = document.getElementById(id);
    if (!select) return;

    const response = await fetch(new URL('countries.php', apiBase), {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    });
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || 'Could not load the country list.');

    const displayNames = new Intl.DisplayNames([navigator.language || 'en'], { type: 'region' });
    const countries = result.countries
      .filter((country) => /^[A-Z]{2}$/.test(country.code) && typeof country.timeZone === 'string')
      .map((country) => ({ ...country, name: displayNames.of(country.code) || country.code }))
      .sort((first, second) => first.name.localeCompare(second.name));
    if (countries.length === 0) throw new Error('The country list is unavailable.');

    select.replaceChildren(...countries.map((country) => {
      const option = document.createElement('option');
      option.value = country.code;
      option.textContent = country.name;
      option.dataset.timeZone = country.timeZone;
      return option;
    }));
    select.value = countries.some((country) => country.code === 'PH') ? 'PH' : countries[0].code;
    select.disabled = false;
  }

  async function populatePhilippineLocation(prefix) {
    const provinceSelect = document.getElementById(`${prefix}Province`);
    const citySelect = document.getElementById(`${prefix}City`);
    const barangaySelect = document.getElementById(`${prefix}Barangay`);
    const status = document.getElementById(`${prefix}LocationStatus`);
    if (!provinceSelect || !citySelect || !barangaySelect || !status) return;

    let provinceRequest = 0;
    let cityRequest = 0;
    const setOptions = (select, rows, placeholder) => {
      select.replaceChildren(new Option(placeholder, ''));
      rows.forEach((row) => select.add(new Option(row.name, row.code)));
      select.disabled = rows.length === 0;
    };
    const load = async (parameters) => {
      const query = new URLSearchParams(parameters);
      const response = await fetch(new URL(`geography.php?${query}`, apiBase), {
        method: 'GET',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || 'Could not load the Philippine location list.');
      return result;
    };

    setOptions(provinceSelect, [], 'Loading provinces and regions…');
    setOptions(citySelect, [], 'Select a province or region first');
    setOptions(barangaySelect, [], 'Select a city or municipality first');
    status.textContent = 'Loading PSGC provinces and regions…';
    const result = await load({ action: 'provinces' });
    setOptions(provinceSelect, result.provinces, 'Select a province or region');
    if (result.provinces.length === 0) throw new Error('No Philippine provinces or regions are available in the PSGC dataset.');
    status.textContent = 'Choose the province or region, city or municipality, and barangay. Enter a street if applicable.';

    provinceSelect.addEventListener('change', async () => {
      const requestId = ++provinceRequest;
      ++cityRequest;
      setOptions(citySelect, [], 'Loading cities and municipalities…');
      setOptions(barangaySelect, [], 'Select a city or municipality first');
      if (!provinceSelect.value) {
        setOptions(citySelect, [], 'Select a province or region first');
        status.textContent = 'Choose a province or region to load its cities and municipalities.';
        return;
      }
      status.textContent = 'Loading cities and municipalities…';
      try {
        const response = await load({ action: 'cities', province_code: provinceSelect.value });
        if (requestId !== provinceRequest) return;
        setOptions(citySelect, response.cities, 'Select a city or municipality');
        status.textContent = response.cities.length
          ? 'Choose a city or municipality.'
          : 'No cities or municipalities are available for this selection.';
      } catch (error) {
        if (requestId === provinceRequest) status.textContent = error.message;
      }
    });

    citySelect.addEventListener('change', async () => {
      const requestId = ++cityRequest;
      setOptions(barangaySelect, [], 'Loading barangays…');
      if (!citySelect.value || !provinceSelect.value) {
        setOptions(barangaySelect, [], 'Select a city or municipality first');
        status.textContent = 'Choose a city or municipality to load its barangays.';
        return;
      }
      status.textContent = 'Loading barangays…';
      try {
        const response = await load({
          action: 'barangays',
          province_code: provinceSelect.value,
          city_code: citySelect.value
        });
        if (requestId !== cityRequest) return;
        setOptions(barangaySelect, response.barangays, 'Select a barangay');
        status.textContent = response.barangays.length
          ? 'Choose a barangay. Street is optional and can be entered below.'
          : 'No barangays are available for this city or municipality.';
      } catch (error) {
        if (requestId === cityRequest) status.textContent = error.message;
      }
    });
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
    requestApi,
    showMessage,
    populateCountrySelect,
    populatePhilippineLocation,
    appendVerification,
    requireRole
  };
}());
