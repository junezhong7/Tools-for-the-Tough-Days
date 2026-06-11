(function () {
  'use strict';

  var state = {
    checked: false,
    authenticated: false,
    user: null,
  };

  var IDLE_TIMEOUT_MS = 6 * 60 * 60 * 1000;

  function getIdleLogoutManager() {
    if (window.TTTDIdleLogout && typeof window.TTTDIdleLogout.ensure === 'function') {
      return window.TTTDIdleLogout;
    }

    var LAST_ACTIVITY_KEY = 'tttd:lastActivityAt';
    var timerId = null;
    var bound = false;
    var loggingOut = false;

    function readLastActivity() {
      var raw = window.localStorage.getItem(LAST_ACTIVITY_KEY);
      var ts = Number(raw);
      if (!Number.isFinite(ts) || ts <= 0) {
        return Date.now();
      }
      return ts;
    }

    function writeLastActivity(ts) {
      try {
        window.localStorage.setItem(LAST_ACTIVITY_KEY, String(ts));
      } catch (_err) {
        // Ignore storage failures (private mode, quotas, etc.).
      }
    }

    function clearTimer() {
      if (timerId !== null) {
        window.clearTimeout(timerId);
        timerId = null;
      }
    }

    function getCurrentPathWithQuery() {
      return window.location.pathname + window.location.search;
    }

    function logoutForInactivity() {
      if (loggingOut) {
        return;
      }
      loggingOut = true;

      fetch('/api/auth.php?action=logout', {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true,
        headers: {
          'Accept': 'application/json'
        }
      }).catch(function () {
        // Best effort logout.
      }).finally(function () {
        var mode = (document.body && document.body.dataset && document.body.dataset.auth) || 'public';
        if (mode === 'required') {
          var redirect = encodeURIComponent(getCurrentPathWithQuery());
          window.location.replace('/login.html?redirect=' + redirect);
          return;
        }

        window.location.reload();
      });
    }

    function scheduleFromLastActivity() {
      clearTimer();

      var remaining = IDLE_TIMEOUT_MS - (Date.now() - readLastActivity());
      if (remaining <= 0) {
        logoutForInactivity();
        return;
      }

      timerId = window.setTimeout(logoutForInactivity, remaining);
    }

    function markActivity() {
      writeLastActivity(Date.now());
      scheduleFromLastActivity();
    }

    function bindListenersOnce() {
      if (bound) {
        return;
      }
      bound = true;

      ['click', 'mousemove', 'keydown', 'scroll', 'touchstart'].forEach(function (eventName) {
        window.addEventListener(eventName, markActivity, { passive: true });
      });

      document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
          scheduleFromLastActivity();
        }
      });

      window.addEventListener('storage', function (event) {
        if (event.key === LAST_ACTIVITY_KEY) {
          scheduleFromLastActivity();
        }
      });
    }

    window.TTTDIdleLogout = {
      ensure: function () {
        bindListenersOnce();
        markActivity();
      },
      stop: function () {
        clearTimer();
      }
    };

    return window.TTTDIdleLogout;
  }

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

    if (auth.authenticated) {
      getIdleLogoutManager().ensure();
    }

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
