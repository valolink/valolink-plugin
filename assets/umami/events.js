/**
 * Umami events for valolink-plugin's Umami module.
 *
 * Contact clicks, downloads, outbound links, form successes, 404s, searches and
 * the site's own CSS-selector rules, sent with umami.track(). With consent
 * required this script also loads the tracker, once the cookie banner gives
 * statistics consent. In test mode every send is cancelled and written to the
 * console instead.
 *
 * Exposes window.ValolinkUmami = { track, onReady } for woo.js.
 *
 * Started as a port of WebDig.DEV Analytics' assets/tracker.js
 * (GPL-2.0-or-later).
 */
(() => {
  'use strict';

  const c = window.valolinkUmamiConfig;
  if (!c || window.ValolinkUmami) return;

  const events = c.events || {};
  const page = c.page || {};
  const waiting = [];
  const readyCallbacks = [];
  let allowed = c.consent !== 'auto';
  let isReady = false;
  let injected = false;
  // The tracker sends its pageview when the document completes; page events
  // wait for that, so that in Umami they follow the pageview they belong to.
  let pageLoaded = document.readyState === 'complete';

  const umamiLoaded = () => !!(window.umami && typeof window.umami.track === 'function');

  /** Umami's limits: 50 properties, strings up to 500 characters; keys kept simple. */
  const clean = (data) => {
    const out = {};
    let count = 0;
    Object.entries(data || {}).forEach(([key, value]) => {
      if (count >= 50 || !/^[a-z0-9_]+$/i.test(key)) return;
      if (typeof value === 'string') {
        if (value === '') return;
        out[key] = value.slice(0, 500);
      } else if ((typeof value === 'number' && Number.isFinite(value)) || typeof value === 'boolean') {
        out[key] = value;
      } else {
        return;
      }
      count++;
    });
    return out;
  };

  function track(name, data) {
    if (!allowed || typeof name !== 'string' || name === '') return;
    if (!isReady) {
      if (waiting.length < 20) waiting.push([name, data]);
      return;
    }
    try {
      window.umami.track(name.slice(0, 50), clean(data));
    } catch (_) {
      /* Never let analytics break the page. */
    }
  }

  function runReadyCallbacks() {
    if (!isReady || !pageLoaded) return;
    readyCallbacks.splice(0).forEach((callback) => {
      try {
        callback();
      } catch (_) {
        /* Next one. */
      }
    });
  }

  /** Runs callback once the tracker is there and the page has loaded. */
  function onReady(callback) {
    readyCallbacks.push(callback);
    runReadyCallbacks();
  }

  function checkReady() {
    if (isReady || !allowed || !umamiLoaded()) return;
    isReady = true;
    waiting.splice(0).forEach(([name, data]) => track(name, data));
    runReadyCallbacks();
  }

  if (!pageLoaded) {
    window.addEventListener(
      'load',
      () =>
        setTimeout(() => {
          pageLoaded = true;
          checkReady();
          runReadyCallbacks();
        }, 0),
      { once: true },
    );
  }

  // The tracker calls this before every send, its own pageviews and
  // data-umami-event clicks included. A falsy return cancels the send.
  window.valolinkUmamiBeforeSend = (type, payload) => {
    if (!allowed) return false;
    if (c.debug) {
      console.info('[Umami]', (payload && payload.name) || 'pageview', payload);
      return false;
    }
    return payload;
  };

  window.ValolinkUmami = { track, onReady };

  // ---------------------------------------------------------------------------
  // Consent (only when the site waits for it)

  function injectTracker() {
    if (injected || !c.tracker) return;
    injected = true;
    const script = document.createElement('script');
    script.src = c.tracker.src;
    script.defer = true;
    Object.entries(c.tracker.attributes || {}).forEach(([name, value]) => script.setAttribute(name, value));
    script.onload = checkReady;
    script.onerror = () => {
      injected = false;
    };
    document.head.appendChild(script);
  }

  /** Mirrored into a first-party cookie so that server-side purchases can honour it. */
  function writeConsentCookie(value) {
    const secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = value
      ? `${c.cookie}=1; path=/; max-age=31536000; SameSite=Lax${secure}`
      : `${c.cookie}=; path=/; max-age=0; SameSite=Lax${secure}`;
  }

  function setConsent(value) {
    const next = !!value;
    writeConsentCookie(next);
    if (next === allowed) return;
    allowed = next;
    if (!allowed) {
      // Withdrawal stops later sends; what was sent cannot be recalled.
      waiting.length = 0;
      return;
    }
    injectTracker();
    checkReady();
  }

  const safeRead = (read) => {
    try {
      return read();
    } catch (_) {
      return undefined;
    }
  };

  /** Consent sources, first found wins. read() is undefined when the source is not on the page. */
  const consentSources = [
    {
      // WP Consent API, which Complianz, CookieYes, Borlabs and others feed.
      read: () => (typeof window.wp_has_consent === 'function' ? !!window.wp_has_consent('statistics') : undefined),
      listen: (cb) =>
        document.addEventListener('wp_listen_for_consent_change', (e) => {
          const changed = (e && e.detail) || {};
          if ('statistics' in changed) cb(changed.statistics === 'allow');
        }),
    },
    {
      // Complianz.
      read: () => (typeof window.cmplz_has_consent === 'function' ? !!window.cmplz_has_consent('statistics') : undefined),
      listen: (cb) =>
        ['cmplz_status_change', 'cmplz_fire_categories', 'cmplz_revoke'].forEach((name) =>
          document.addEventListener(name, () => {
            const value = safeRead(consentSources[1].read);
            if (value !== undefined) cb(value);
          }),
        ),
    },
    {
      // CookieYes.
      read: () => {
        if (typeof window.getCkyConsent !== 'function') return undefined;
        const state = window.getCkyConsent();
        return state && state.categories ? !!state.categories.analytics : undefined;
      },
      listen: (cb) =>
        document.addEventListener('cookieyes_consent_update', (e) => {
          const accepted = e && e.detail && e.detail.accepted;
          if (Array.isArray(accepted)) cb(accepted.includes('analytics'));
        }),
    },
    {
      // Cookiebot.
      read: () => {
        const bot = window.Cookiebot;
        return bot && bot.consent && bot.hasResponse !== false ? !!bot.consent.statistics : undefined;
      },
      listen: (cb) =>
        ['CookiebotOnConsentReady', 'CookiebotOnAccept', 'CookiebotOnDecline'].forEach((name) =>
          window.addEventListener(name, () => {
            const value = safeRead(consentSources[3].read);
            if (value !== undefined) cb(value);
          }),
        ),
    },
  ];

  function detectConsent() {
    for (const source of consentSources) {
      const value = safeRead(source.read);
      if (value !== undefined) {
        setConsent(value);
        return;
      }
    }
  }

  if (c.consent === 'auto') {
    consentSources.forEach((source) => source.listen(setConsent));
    detectConsent();
    // Banners often start after this script.
    if (document.readyState !== 'complete') window.addEventListener('load', detectConsent, { once: true });
  }

  // ---------------------------------------------------------------------------
  // Clicks

  const label = (el) =>
    (el.getAttribute('aria-label') || (el.tagName === 'INPUT' ? el.value : el.textContent) || '')
      .replace(/\s+/g, ' ')
      .trim()
      .slice(0, 100);

  /** www.example.fi and example.fi are the same site. */
  const bareHost = (host) => host.toLowerCase().replace(/^www\./, '');

  const parseUrl = (raw) => {
    try {
      return new URL(raw, location.href);
    } catch (_) {
      return null;
    }
  };

  const decode = (value) => {
    try {
      return decodeURIComponent(value);
    } catch (_) {
      return value;
    }
  };

  const rules = (c.rules || []).filter((rule) => rule && rule.selector && rule.event);
  const downloads = new Set(events.downloads || []);

  function onClick(e) {
    // Main button, or middle button (auxclick) that opens a new tab.
    if (e.type === 'auxclick' && e.button !== 1) return;
    const target = e.target instanceof Element ? e.target : null;
    if (!target) return;

    for (const rule of rules) {
      let el = null;
      try {
        el = target.closest(rule.selector);
      } catch (_) {
        continue; // Not a valid selector.
      }
      if (el) {
        track(rule.event, { text: label(el), element_id: el.id || '' });
        return;
      }
    }

    const link = target.closest('a[href]');
    // Umami sends links with data-umami-event itself.
    if (!link || link.closest('[data-umami-event]')) return;
    const href = (link.getAttribute('href') || '').trim();

    if (/^tel:/i.test(href)) {
      if (events.contact) track('phone_click', { number: decode(href.slice(4)).replace(/[\s()-]/g, '') });
      return;
    }
    if (/^mailto:/i.test(href)) {
      if (events.contact) track('email_click', { address: decode(href.slice(7).split('?')[0]).toLowerCase() });
      return;
    }
    const url = parseUrl(href);
    if (!url || !/^https?:$/.test(url.protocol)) return;

    if (/^(wa\.me|(api\.|web\.)?whatsapp\.com)$/i.test(url.hostname)) {
      if (events.contact) track('whatsapp_click', {});
      return;
    }
    const file = decode(url.pathname.split('/').pop() || '');
    const extension = file.includes('.') ? file.split('.').pop().toLowerCase() : '';
    if (downloads.size && (link.hasAttribute('download') || downloads.has(extension))) {
      track('download', { file, type: extension, domain: url.hostname });
      return;
    }
    if (events.outbound && bareHost(url.hostname) !== bareHost(location.hostname)) {
      track('outbound_click', { domain: url.hostname, url: url.origin + url.pathname, text: label(link) });
    }
  }

  // Capture phase, so a handler that stops the click's propagation does not hide it.
  document.addEventListener('click', onClick, true);
  document.addEventListener('auxclick', onClick, true);

  // ---------------------------------------------------------------------------
  // Forms: only real successes, never field values

  if (events.forms) {
    // Contact Form 7 fires this on the form once the mail has gone.
    document.addEventListener('wpcf7mailsent', (e) => {
      const el = e.target instanceof Element ? e.target : null;
      const form = el && (el.matches('form') ? el : el.querySelector('form'));
      track('form_success', {
        plugin: 'cf7',
        form_id: String((e.detail && e.detail.contactFormId) || ''),
        form: (form && form.getAttribute('aria-label')) || '',
      });
    });

    // Gravity Forms: the module puts a marker into text confirmations, shown
    // after a reload or inserted over AJAX.
    const seenMarkers = new WeakSet();
    const scanGravity = () =>
      document.querySelectorAll('[data-valolink-umami-form]').forEach((marker) => {
        if (seenMarkers.has(marker)) return;
        seenMarkers.add(marker);
        track('form_success', {
          plugin: 'gravityforms',
          form_id: marker.getAttribute('data-valolink-umami-form') || '',
          form: marker.getAttribute('data-valolink-umami-form-title') || '',
        });
      });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scanGravity);
    else scanGravity();
    if (window.jQuery) window.jQuery(document).on('gform_confirmation_loaded', () => setTimeout(scanGravity, 0));
  }

  // ---------------------------------------------------------------------------
  // What the page itself is

  onReady(() => {
    if (page.not_found) {
      track('not_found', { path: location.pathname, referrer: document.referrer || '(none)' });
    }
    if (page.search) {
      track('search', { term: page.search.term || '', results: Number(page.search.results) || 0 });
    }
  });

  // ---------------------------------------------------------------------------
  // Start

  if (c.consent !== 'auto') {
    // The tracker tag comes before this script; if an optimiser reordered
    // them, wait for it.
    checkReady();
    if (!isReady) document.addEventListener('DOMContentLoaded', checkReady);
  }
})();
