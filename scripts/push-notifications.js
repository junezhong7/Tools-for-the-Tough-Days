/**
 * Tools for the Tough Days — "Notifications on this device" toggle (my-preference.html)
 *
 * Push is managed per browser/phone and separately from email reminders, so the
 * toggle takes effect straight away instead of waiting for "Save my preferences".
 * Talks to /api/push.php; notifications are displayed by /sw.js.
 */
(function () {
  'use strict';

  var section = document.getElementById('pushSection');
  if (!section) {
    return;
  }

  var toggle   = document.getElementById('pushToggle');
  var statusEl = document.getElementById('pushStatus');
  var testBtn  = document.getElementById('pushTestBtn');
  var iosHint  = document.getElementById('pushIosHint');

  var DEFAULT_STATUS = 'Changes apply straight away';
  var BLOCKED_STATUS = 'Notifications are blocked for this site. You can allow them in your browser or phone settings.';

  var isIos = /iPad|iPhone|iPod/.test(navigator.userAgent) ||
              (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  var supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

  var publicKey = '';
  var busy = false;

  function setStatus(text) {
    statusEl.textContent = text || DEFAULT_STATUS;
  }

  function setOn(on) {
    toggle.checked = on;
    testBtn.hidden = !on;
  }

  function disable(text) {
    toggle.disabled = true;
    setOn(false);
    setStatus(text);
  }

  function urlBase64ToUint8Array(value) {
    var padded = value + '='.repeat((4 - value.length % 4) % 4);
    var raw = window.atob(padded.replace(/-/g, '+').replace(/_/g, '/'));
    var bytes = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) {
      bytes[i] = raw.charCodeAt(i);
    }
    return bytes;
  }

  function sameKey(buffer, bytes) {
    if (!buffer) {
      return true; // some browsers don't expose the key; assume it matches
    }
    var current = new Uint8Array(buffer);
    if (current.length !== bytes.length) {
      return false;
    }
    for (var i = 0; i < bytes.length; i++) {
      if (current[i] !== bytes[i]) {
        return false;
      }
    }
    return true;
  }

  async function post(action, body) {
    var res = await fetch('/api/push.php?action=' + action, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    });
    var data = await res.json().catch(function () { return {}; });
    if (!res.ok) {
      throw new Error(data.message || 'Request failed');
    }
    return data;
  }

  async function currentSubscription() {
    var registration = await navigator.serviceWorker.ready;
    return registration.pushManager.getSubscription();
  }

  // Subscribes this browser (re-subscribing if the server's key changed) and
  // stores the subscription server-side. Needs notification permission already granted.
  async function subscribe() {
    var registration = await navigator.serviceWorker.ready;
    var key = urlBase64ToUint8Array(publicKey);
    var subscription = await registration.pushManager.getSubscription();

    if (subscription && !sameKey(subscription.options.applicationServerKey, key)) {
      await subscription.unsubscribe();
      subscription = null;
    }
    if (!subscription) {
      subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
    }

    await post('subscribe', { subscription: subscription.toJSON() });
  }

  function turnOn() {
    busy = true;
    // requestPermission must be called straight from the tap: Safari ignores it after an await.
    var permission = Notification.permission === 'granted'
      ? Promise.resolve('granted')
      : Notification.requestPermission();

    permission
      .then(function (result) {
        if (result !== 'granted') {
          if (result === 'denied') {
            disable(BLOCKED_STATUS);
          } else {
            setOn(false);
            setStatus('Notifications were not turned on.');
          }
          return;
        }
        setStatus('Turning on…');
        return subscribe().then(function () {
          setOn(true);
          setStatus('On for this device');
        });
      })
      .catch(function () {
        setOn(false);
        setStatus('Could not turn on notifications. Please try again.');
      })
      .finally(function () {
        busy = false;
      });
  }

  function turnOff() {
    busy = true;
    setStatus('Turning off…');

    currentSubscription()
      .then(function (subscription) {
        if (!subscription) {
          return;
        }
        return post('unsubscribe', { endpoint: subscription.endpoint }).then(function () {
          return subscription.unsubscribe();
        });
      })
      .then(function () {
        setOn(false);
        setStatus('Off for this device');
      })
      .catch(function () {
        setOn(true);
        setStatus('Could not turn off notifications. Please try again.');
      })
      .finally(function () {
        busy = false;
      });
  }

  toggle.addEventListener('change', function () {
    if (busy) {
      toggle.checked = !toggle.checked;
      return;
    }
    if (toggle.checked) {
      turnOn();
    } else {
      turnOff();
    }
  });

  testBtn.addEventListener('click', function () {
    testBtn.disabled = true;
    setStatus('Sending a test notification…');

    currentSubscription()
      .then(function (subscription) {
        if (!subscription) {
          throw new Error('Notifications are not turned on for this device.');
        }
        return post('test', { endpoint: subscription.endpoint });
      })
      .then(function () {
        setStatus('Test sent. It should arrive within a few seconds.');
      })
      .catch(function (err) {
        setStatus(err.message || 'Could not send a test notification.');
      })
      .finally(function () {
        testBtn.disabled = false;
      });
  });

  (async function init() {
    if (!supported) {
      if (isIos && !isStandalone) {
        // iPhone/iPad only allow push from a Home Screen app, never a Safari tab.
        iosHint.hidden = false;
        disable('Add this site to your Home Screen first (see below).');
      } else {
        disable('This browser does not support notifications.');
      }
      return;
    }

    try {
      var res = await fetch('/api/push.php?action=config', { credentials: 'include' });
      var config = res.ok ? await res.json() : null;
      if (!config || !config.configured) {
        disable('Notifications are not available yet.');
        return;
      }
      publicKey = config.public_key;

      await navigator.serviceWorker.register('/sw.js');

      if (Notification.permission === 'denied') {
        disable(BLOCKED_STATUS);
        return;
      }

      var subscription = await currentSubscription();
      if (subscription && Notification.permission === 'granted') {
        setOn(true);
        setStatus('On for this device');
        // Keep the server copy fresh (subscriptions rotate, and devices can change hands).
        subscribe().catch(function () {});
      }
      toggle.disabled = false;
    } catch (_err) {
      disable('Notifications are not available right now.');
    }
  })();
})();
