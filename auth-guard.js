(function () {
  'use strict';

  var state = {
    checked: false,
    authenticated: false,
    user: null,
  };

  function getRedirectTarget() {
    var params = new URLSearchParams(window.location.search);
    return params.get('redirect') || '/support.html';
  }

  function getCurrentPathWithQuery() {
    return window.location.pathname + window.location.search;
  }

  function goToLoginWithRedirect() {
    var redirect = encodeURIComponent(getCurrentPathWithQuery());
    window.location.replace('/login.html?redirect=' + redirect);
  }

  function goToDashboardOrRedirectParam() {
    window.location.replace(getRedirectTarget());
  }

  async function fetchAuthState() {
    if (state.checked) {
      return state;
    }

    try {
      var response = await fetch('/api/auth.php?action=me', {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Accept': 'application/json'
        }
      });

      if (response.ok) {
        var data = await response.json();
        state.checked = true;
        state.authenticated = true;
        state.user = data.user || null;
        return state;
      }
    } catch (_err) {
      // If auth check fails, treat as logged out and let page handle itself.
    }

    state.checked = true;
    state.authenticated = false;
    state.user = null;
    return state;
  }

  async function enforce(mode) {
    var auth = await fetchAuthState();

    if (mode === 'required' && !auth.authenticated) {
      goToLoginWithRedirect();
      return;
    }

    if (mode === 'guest' && auth.authenticated) {
      goToDashboardOrRedirectParam();
    }
  }

  async function enforceFromBody() {
    var mode = (document.body && document.body.dataset && document.body.dataset.auth) || 'public';

    if (mode === 'required' || mode === 'guest') {
      await enforce(mode);
    }
  }

  window.AuthGuard = {
    getState: fetchAuthState,
    enforce: enforce,
    enforceFromBody: enforceFromBody,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', enforceFromBody);
  } else {
    enforceFromBody();
  }
})();
