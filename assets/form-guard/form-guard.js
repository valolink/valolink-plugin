/* Valolink form guard: fetch a signed token into every guarded form (Form guard module; began as valolink-cf7-guard 0.2.0). */
(function () {
    'use strict';

    var cfg = window.vlgConfig;
    if (!cfg || !cfg.forms || !window.fetch) {
        return;
    }

    var token = '';
    var fetchedAt = 0;       // local time of fetch, ms
    var pending = null;
    var retryTimer = null;

    function forms() {
        return document.querySelectorAll(cfg.forms);
    }

    function guarded(form) {
        return form && form.matches && form.matches(cfg.forms);
    }

    function ensureInputs() {
        forms().forEach(function (form) {
            var input = form.querySelector('input[name="' + cfg.field + '"]');
            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = cfg.field;
                form.appendChild(input);
            }
            input.value = token;

            // Decoy: a text field moved off-screen and hidden from keyboard
            // and screen readers. Bots that fill every input fill this too.
            if (cfg.decoy && !form.querySelector('input[name="' + cfg.decoy + '"]')) {
                var wrap = document.createElement('div');
                wrap.setAttribute('aria-hidden', 'true');
                wrap.style.cssText = 'position:absolute!important;left:-10000px!important;top:auto;width:1px;height:1px;overflow:hidden;';
                var label = document.createElement('label');
                label.textContent = 'Jätä tämä kenttä tyhjäksi';
                var decoy = document.createElement('input');
                decoy.type = 'text';
                decoy.name = cfg.decoy;
                decoy.value = '';
                decoy.tabIndex = -1;
                decoy.autocomplete = 'off';
                label.appendChild(decoy);
                wrap.appendChild(label);
                form.appendChild(wrap);
            }
        });
    }

    function fetchToken(attempt) {
        if (pending) {
            return pending;
        }
        attempt = attempt || 0;
        var url = cfg.url + (cfg.url.indexOf('?') === -1 ? '?' : '&') + '_=' + Date.now();
        pending = fetch(url, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (res) {
                if (!res.ok) {
                    throw new Error('HTTP ' + res.status);
                }
                return res.json();
            })
            .then(function (data) {
                if (!data || !data.token) {
                    throw new Error('no token');
                }
                token = data.token;
                fetchedAt = Date.now();
                ensureInputs();
                pending = null;
                return token;
            })
            .catch(function () {
                pending = null;
                // Back off: 2 s, 5 s, 15 s, 30 s, then every minute.
                var delays = [2000, 5000, 15000, 30000, 60000];
                clearTimeout(retryTimer);
                retryTimer = setTimeout(function () {
                    fetchToken(attempt + 1);
                }, delays[Math.min(attempt, delays.length - 1)]);
                return '';
            });
        return pending;
    }

    function refreshIfStale() {
        if (!token || Date.now() - fetchedAt > cfg.refreshAfter * 1000) {
            fetchToken();
        }
    }

    function tokenReady() {
        return token && Date.now() - fetchedAt >= (cfg.minAge + 1) * 1000;
    }

    // A submit without a usable token (fetch still running, failed, or token
    // just issued) is held until one is ready, then submitted again. Runs in
    // the capture phase so CF7's own submit handler never sees the held event.
    // The button that was pressed is passed on: WooCommerce's sign-in and
    // registration handlers only act when their button's name is posted.
    var passthrough = false;
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (passthrough || tokenReady() || !guarded(form)) {
            return;
        }
        var submitter = e.submitter && e.submitter.form === form ? e.submitter : null;
        e.preventDefault();
        e.stopImmediatePropagation();
        if (form.getAttribute('data-vlg-waiting')) {
            return;
        }
        form.setAttribute('data-vlg-waiting', '1');
        form.classList.add('submitting'); // shows CF7's spinner while held
        (token ? Promise.resolve(token) : fetchToken()).then(function (t) {
            var wait = t ? Math.max(0, (cfg.minAge + 1) * 1000 - (Date.now() - fetchedAt)) : 0;
            setTimeout(function () {
                form.removeAttribute('data-vlg-waiting');
                form.classList.remove('submitting');
                // Without a token this still submits, so the visitor gets
                // CF7's visible error instead of a silently dead button.
                passthrough = true;
                try {
                    if (form.requestSubmit) {
                        form.requestSubmit(submitter || undefined);
                    } else if (form.classList.contains('wpcf7-form')) {
                        form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
                    } else {
                        // Browsers without requestSubmit (before Safari 16): a
                        // plain submit, carrying the pressed button by hand.
                        if (submitter && submitter.name) {
                            var carry = document.createElement('input');
                            carry.type = 'hidden';
                            carry.name = submitter.name;
                            carry.value = submitter.value;
                            form.appendChild(carry);
                        }
                        HTMLFormElement.prototype.submit.call(form);
                    }
                } finally {
                    passthrough = false;
                }
            }, wait);
        });
    }, true);

    // After a successful send the token is spent; after a rejection it may be
    // stale. Drop it at once so a quick next submit is held until the new
    // one arrives.
    function renew() {
        token = '';
        ensureInputs();
        fetchToken();
    }
    document.addEventListener('wpcf7mailsent', renew);
    document.addEventListener('wpcf7spam', renew);

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            refreshIfStale();
        }
    });
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) {
            renew();
        }
    });
    setInterval(refreshIfStale, 5 * 60 * 1000);

    function start() {
        if (!forms().length) {
            return;
        }
        ensureInputs();
        fetchToken();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
