// Tools for the Tough Days — first-party UTM capture (independent of GA4)
// Records the first-touch campaign context in a JSON cookie so it can be
// persisted at signup and logged on the business.html CTA redirects.
(function () {
  'use strict';

  var COOKIE_NAME = 'tttd_utm';
  var MAX_AGE_DAYS = 90;
  var FIELDS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid'];

  function getCookie(name) {
    var match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : null;
  }

  function setCookie(name, value, days) {
    var secure = window.location.protocol === 'https:' ? '; Secure' : '';
    var expires = new Date(Date.now() + days * 864e5).toUTCString();
    document.cookie = name + '=' + encodeURIComponent(value) +
      '; expires=' + expires + '; path=/; SameSite=Lax' + secure;
  }

  try {
    if (getCookie(COOKIE_NAME)) {
      return; // first-touch already recorded — never overwrite
    }

    var params = new URLSearchParams(window.location.search);
    var data = {};
    var hasSignal = false;

    FIELDS.forEach(function (key) {
      var v = params.get(key);
      if (v) {
        data[key] = v.slice(0, 255);
        hasSignal = true;
      }
    });

    if (!hasSignal) {
      return; // organic/direct visit — nothing to record
    }

    data.landing_page = (window.location.pathname + window.location.search).slice(0, 512);
    data.referrer = (document.referrer || '').slice(0, 512);
    data.captured_at = new Date().toISOString();

    setCookie(COOKIE_NAME, JSON.stringify(data), MAX_AGE_DAYS);
  } catch (e) {
    // Never let tracking break the page.
  }
})();
