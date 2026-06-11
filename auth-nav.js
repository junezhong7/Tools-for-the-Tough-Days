(function () {
  'use strict';

  function byId(id) {
    return document.getElementById(id);
  }

  function setVisible(element, visible) {
    if (!element) {
      return;
    }

    element.style.display = visible ? '' : 'none';
  }

  function getFirstName(fullName) {
    var trimmed = String(fullName || '').trim();
    if (!trimmed) {
      return '';
    }

    return trimmed.split(/\s+/)[0];
  }

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

  async function getAuthState() {
    if (window.AuthGuard && typeof window.AuthGuard.getState === 'function') {
      return window.AuthGuard.getState();
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
        return {
          authenticated: true,
          user: data.user || null,
        };
      }
    } catch (_err) {
      // Treat failed checks as logged out.
    }

    return {
      authenticated: false,
      user: null,
    };
  }

  async function logout() {
    try {
      await fetch('/api/auth.php?action=logout', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json'
        }
      });
    } catch (_err) {
      // Even if request fails, continue to login screen.
    }

    window.location.href = '/login.html';
  }

  async function initNavAuth() {
    var signIn = byId('navSignIn');
    var signUp = byId('navSignUp');
    var dashboard = byId('navDashboard');
    var signOut = byId('navSignOut');

    if (!signIn && !signUp && !dashboard && !signOut) {
      return;
    }

    var auth = await getAuthState();
    var loggedIn = !!auth.authenticated;
    var firstName = getFirstName(auth.user && auth.user.full_name);

    if (loggedIn) {
      getIdleLogoutManager().ensure();
    } else if (window.TTTDIdleLogout && typeof window.TTTDIdleLogout.stop === 'function') {
      window.TTTDIdleLogout.stop();
    }

    setVisible(signIn, !loggedIn);
    setVisible(signUp, !loggedIn);
    setVisible(dashboard, loggedIn);
    setVisible(signOut, loggedIn);

    // Show a greeting alongside the Dashboard link — don't overwrite its label.
    var existingGreeting = byId('navGreeting');
    if (existingGreeting) {
      existingGreeting.parentNode.removeChild(existingGreeting);
    }
    if (loggedIn && firstName && dashboard) {
      var greeting = document.createElement('span');
      greeting.id = 'navGreeting';
      greeting.textContent = 'Hi, ' + firstName;
      greeting.style.cssText = 'font-size:13px; color:var(--warm-grey); white-space:nowrap;';
      dashboard.parentNode.insertBefore(greeting, dashboard);
    }

    if (signOut) {
      signOut.addEventListener('click', function () {
        logout();
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initNavAuth);
  } else {
    initNavAuth();
  }
})();