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

    setVisible(signIn, !loggedIn);
    setVisible(signUp, !loggedIn);
    setVisible(dashboard, loggedIn);
    setVisible(signOut, loggedIn);

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