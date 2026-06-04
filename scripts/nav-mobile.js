/**
 * nav-mobile.js — hamburger menu for mobile viewports (≤ 800 px)
 * Include this script in every page that has the standard <nav> structure.
 */
(function () {
  /* ── injected CSS ── */
  var CSS = [
    /* hamburger button */
    '.nav-hamburger{display:none;flex-direction:column;justify-content:center;',
    'gap:5px;cursor:pointer;background:none;border:none;padding:8px;margin-left:8px;',
    '-webkit-tap-highlight-color:transparent;}',
    '.nav-hamburger span{display:block;width:22px;height:2px;background:#2a2825;',
    'border-radius:2px;transition:transform .22s ease,opacity .22s ease;pointer-events:none;}',
    '.nav-hamburger.open span:nth-child(1){transform:translateY(7px) rotate(45deg);}',
    '.nav-hamburger.open span:nth-child(2){opacity:0;transform:scaleX(0);}',
    '.nav-hamburger.open span:nth-child(3){transform:translateY(-7px) rotate(-45deg);}',

    /* mobile overrides */
    '@media(max-width:800px){',
    '  .nav-hamburger{display:flex;}',
    '  nav{position:relative;}',
    '  .nav-logo-text{display:none!important;}',
    '  .nav-logo img{height:50px;}',
    /* hide horizontal links */
    '  .nav-links{',
    '    display:none;flex-direction:column;',
    '    position:absolute;top:100%;left:0;right:0;',
    '    background:#fff;',
    '    border-bottom:1px solid rgba(42,40,37,.12);',
    '    padding:8px 20px 16px;',
    '    box-shadow:0 8px 24px rgba(42,40,37,.10);',
    '    z-index:300;gap:0;align-items:stretch;',
    '  }',
    '  .nav-links.mobile-open{display:flex;}',
    /* every direct child link / button */
    '  .nav-links>a,.nav-links>button{',
    '    padding:13px 4px;font-size:15px!important;',
    '    border-bottom:1px solid rgba(42,40,37,.07);',
    '    border-left:none!important;border-right:none!important;',
    '    border-top:none!important;border-radius:0!important;',
    '    width:100%;text-align:left;',
    '    background:none!important;',
    '    color:#2a2825!important;',
    '    font-family:"DM Sans",sans-serif;',
    '    cursor:pointer;text-decoration:none;',
    '  }',
    /* remove border on last visible item before nav-cta */
    '  .nav-links>.nav-login{',
    '    border-top:none!important;',
    '    color:#6b6560!important;',
    '  }',
    /* teal CTA at bottom */
    '  .nav-links>.nav-cta{',
    '    margin-top:10px;',
    '    border-radius:8px!important;',
    '    padding:13px 20px!important;',
    '    text-align:center!important;',
    '    background:#26777B!important;',
    '    color:#fff!important;',
    '    border:none!important;',
    '    font-weight:500;',
    '    border-bottom:none!important;',
    '  }',
    /* ensure all items are visible when menu is open
      (overrides old page-level a:not(.nav-login){display:none} rules)
      no !important so auth.js inline style="display:none" still wins */
    '.nav-links.mobile-open>a,.nav-links.mobile-open>button{display:flex;}',
    '}'
  ].join('');

  var styleEl = document.createElement('style');
  styleEl.textContent = CSS;
  document.head.appendChild(styleEl);

  /* ── DOM init ── */
  function init() {
    var nav = document.querySelector('nav');
    if (!nav) return;
    var navLinks = nav.querySelector('.nav-links');
    if (!navLinks) return;

    /* hamburger button */
    var btn = document.createElement('button');
    btn.className = 'nav-hamburger';
    btn.setAttribute('aria-label', 'Open menu');
    btn.setAttribute('aria-expanded', 'false');
    btn.innerHTML = '<span></span><span></span><span></span>';
    nav.appendChild(btn);

    function close() {
      navLinks.classList.remove('mobile-open');
      btn.classList.remove('open');
      btn.setAttribute('aria-expanded', 'false');
    }

    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = navLinks.classList.toggle('mobile-open');
      btn.classList.toggle('open', open);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    /* close on outside click */
    document.addEventListener('click', function (e) {
      if (!nav.contains(e.target)) close();
    });

    /* close on nav link click */
    navLinks.querySelectorAll('a,button').forEach(function (el) {
      el.addEventListener('click', close);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
