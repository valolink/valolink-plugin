/* Valolink comment mode. Vanilla, loaded only for users who may comment.
 *
 * A comment is anchored three ways and found again down a ladder: the
 * element chain from the clicked element up to the nearest id or the body
 * (tag, stable classes, position among same-tag siblings, a bit of text),
 * the quote (the element's text, or the selected phrase) with its
 * surroundings, and the page. Resolution: chain and quote agree → exact;
 * quote found elsewhere → moved; chain matches part-way → near, pinned on
 * the deepest ancestor still there; nothing → detached, listed on the
 * panel. The ladder runs again while scripts add content after load.
 */
(function () {
  "use strict";
  const cfg = window.valolinkComments;
  if (!cfg || !cfg.rest) return;
  const T = cfg.i18n || {};
  const KEY_MODE = "vl-comment-mode";
  const KEY_VISIBLE = "vl-comments-visible";
  const KEY_RESOLVED = "vl-comments-show-resolved";
  const STATE_CLASSES = /^(is-|has-|active|open|hover|focus|visible|hidden|loaded|loading|current|selected|show|shown|collapsed|expanded|animate|animated|in-view|sticky|fixed)/i;
  const TEXT_TAGS = "p,h1,h2,h3,h4,h5,h6,li,td,th,blockquote,figcaption,summary,dt,dd,label,button,a,span,strong,em,small,cite,q,pre,code,address";

  const store = {
    get: (k, d) => { try { const v = localStorage.getItem(k); return v === null ? d : v === "1"; } catch (e) { return d; } },
    set: (k, v) => { try { localStorage.setItem(k, v ? "1" : "0"); } catch (e) { /* private mode */ } },
  };
  let mode = store.get(KEY_MODE, false);
  let visible = store.get(KEY_VISIBLE, true);
  let showResolved = store.get(KEY_RESOLVED, false);
  let comments = [];
  const pins = new Map(); // id → { el, pin, how }
  let hoverEl = null;
  let pop = null;
  let panel = null;
  let openId = null;

  // ---------------------------------------------------------------- helpers
  const norm = (s) => (s || "").replace(/\s+/g, " ").trim();
  const ownText = (el) => norm(Array.from(el.childNodes).filter((n) => n.nodeType === 3).map((n) => n.textContent).join(" "));
  const fullText = (el) => norm(el.textContent);
  const stableClasses = (el) => Array.from(el.classList || []).filter((c) => !STATE_CLASSES.test(c) && !/^vl-c-/.test(c)).slice(0, 8);
  const nthOfTag = (el) => { let n = 0; for (const s of el.parentElement ? el.parentElement.children : []) { if (s === el) return n; if (s.tagName === el.tagName) n++; } return n; };
  const isOurs = (el) => !!(el && el.closest && el.closest(".vl-c-pop,.vl-c-panel,.vl-c-pin,#wpadminbar"));

  async function api(path, method, body) {
    const res = await fetch(cfg.rest + path, {
      method: method || "GET",
      headers: { "Content-Type": "application/json", "X-WP-Nonce": cfg.nonce },
      body: body ? JSON.stringify(body) : undefined,
      credentials: "same-origin",
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json.message || res.status);
    return json;
  }

  // -------------------------------------------------------------- anchoring
  // The element a click means: the nearest one with text of its own.
  function targetFor(el) {
    let cur = el;
    while (cur && cur !== document.body) {
      if (ownText(cur) || cur.matches(TEXT_TAGS) || cur.tagName === "IMG") return cur;
      cur = cur.parentElement;
    }
    return el;
  }

  function anchorFor(el, selection) {
    const chain = [];
    let cur = el;
    while (cur && cur !== document.body && chain.length < 12) {
      chain.unshift({ tag: cur.tagName.toLowerCase(), id: cur.id || "", classes: stableClasses(cur), nth: nthOfTag(cur), text: ownText(cur).slice(0, 80) });
      if (cur.id) break;
      cur = cur.parentElement;
    }
    let quote = selection || elText(el).slice(0, 400);
    if (!quote) {
      // A card, an icon, a background image: name what is in it, or on it.
      const img = el.querySelector && el.querySelector("img");
      if (img) quote = elText(img);
      else { const bg = getComputedStyle(el).backgroundImage.match(/url\(["']?([^"')]+)/); if (bg) quote = bg[1]; }
    }
    const prev = el.previousElementSibling ? fullText(el.previousElementSibling).slice(-60) : "";
    const next = el.nextElementSibling ? fullText(el.nextElementSibling).slice(0, 60) : "";
    return { quote, prefix: prev, suffix: next, tag: el.tagName.toLowerCase(), selection: !!selection, chain };
  }

  // One step of the chain among a parent's children.
  function matchStep(parent, step, wantText) {
    const kids = Array.from(parent.children).filter((k) => k.tagName.toLowerCase() === step.tag);
    if (!kids.length) return null;
    if (step.id) { const byId = kids.find((k) => k.id === step.id); if (byId) return byId; }
    let cands = kids;
    if (step.classes && step.classes.length) {
      const byClass = kids.filter((k) => step.classes.every((c) => k.classList.contains(c)));
      if (byClass.length) cands = byClass;
    }
    if (cands.length > 1 && step.text) {
      const byText = cands.filter((k) => ownText(k).slice(0, 80) === step.text);
      if (byText.length) cands = byText;
    }
    if (cands.length > 1 && typeof step.nth === "number" && cands[step.nth]) return cands[step.nth];
    return cands[0] || null;
  }

  // What a quote is compared against: an image is its alt or source, a
  // background image its URL, anything else its text.
  function elText(el) {
    if (el.tagName === "IMG") return norm(el.getAttribute("alt") || el.getAttribute("src") || "");
    return fullText(el);
  }

  // The element the chain found is trusted; the quote only confirms the
  // content is still what the comment was written on, at any length.
  function quoteMatches(el, quote) {
    if (!quote) return true;
    const t = elText(el);
    const q = norm(quote);
    if (t === q || t.includes(q)) return true;
    if (!t && el.tagName !== "IMG") { const bg = getComputedStyle(el).backgroundImage; return bg.includes(q); }
    return t.length >= 12 && q.includes(t);
  }

  function findByQuote(quote, tag) {
    const q = norm(quote);
    if (q.length < 12) return null;
    const sel = tag ? tag : TEXT_TAGS + ",img";
    let best = null;
    for (const el of document.querySelectorAll(sel)) {
      if (isOurs(el)) continue;
      const t = elText(el);
      if (t === q) return el;
      if (t.includes(q) && (!best || t.length < elText(best).length)) best = el;
    }
    return best;
  }

  // The ladder. Returns { el, how } or { el: null, how: "detached" }.
  function resolve(anchor) {
    if (!anchor) return { el: null, how: "detached" };
    const chain = anchor.chain || [];
    let cur = document.body;
    let deepest = null;
    if (chain.length && chain[0].id) {
      const root = document.getElementById(chain[0].id);
      if (root) { deepest = root; cur = root; chain.slice(1).forEach((step) => { if (!cur) return; const next = matchStep(cur, step, true); if (next) { deepest = next; cur = next; } else cur = null; }); }
      else cur = null;
    } else {
      for (const step of chain) { const next = matchStep(cur, step, true); if (next) { deepest = next; cur = next; } else { cur = null; break; } }
    }
    if (cur && deepest && quoteMatches(deepest, anchor.quote)) return { el: deepest, how: "exact" };
    const byQuote = findByQuote(anchor.quote, anchor.tag);
    if (byQuote) return { el: byQuote, how: "moved" };
    if (deepest && deepest !== document.body) return { el: deepest, how: "near" };
    return { el: null, how: "detached" };
  }

  // ---------------------------------------------------------------- pins
  function placePin(entry) {
    const { el, pin } = entry;
    if (!el || !el.isConnected) { pin.style.display = "none"; return; }
    const r = el.getBoundingClientRect();
    pin.style.display = visible ? "" : "none";
    pin.style.left = window.scrollX + r.left + 8 + "px";
    pin.style.top = window.scrollY + r.top + 2 + "px";
  }

  function renderPins() {
    for (const [id, entry] of pins) { entry.pin.remove(); pins.delete(id); }
    comments.forEach((c, i) => {
      if (c.status === "resolved" && !showResolved) return;
      const found = resolve(c.anchor);
      c._how = found.how;
      if (found.how !== c.resolution) {
        api("/" + c.id + "/resolution", "POST", { resolution: found.how }).catch(() => {});
        c.resolution = found.how;
      }
      if (!found.el) return;
      const pin = document.createElement("div");
      pin.className = "vl-c-pin";
      pin.textContent = String(i + 1);
      pin.dataset.status = c.status;
      pin.dataset.how = found.how;
      pin.title = c.text.slice(0, 120);
      pin.addEventListener("click", (e) => { e.preventDefault(); e.stopPropagation(); openThread(c, pin); });
      document.body.appendChild(pin);
      pins.set(c.id, { el: found.el, pin, how: found.how });
      placePin(pins.get(c.id));
    });
    updatePanel();
    updateBar();
  }

  let layoutTimer = null;
  const relayout = () => { if (layoutTimer) return; layoutTimer = setTimeout(() => { layoutTimer = null; for (const e of pins.values()) placePin(e); if (pop && pop._anchorEl) positionPop(pop, pop._anchorEl); }, 60); };
  window.addEventListener("scroll", relayout, { passive: true });
  window.addEventListener("resize", relayout);

  // Content that arrives after load: run the ladder again for a while.
  let observeUntil = Date.now() + 15000;
  let observeTimer = null;
  const observer = new MutationObserver(() => {
    if (Date.now() > observeUntil) { observer.disconnect(); return; }
    if (observeTimer) return;
    observeTimer = setTimeout(() => { observeTimer = null; if (comments.some((c) => c._how !== "exact")) renderPins(); else relayout(); }, 400);
  });
  function observe(ms) { observeUntil = Date.now() + ms; observer.observe(document.body, { childList: true, subtree: true }); }

  // -------------------------------------------------------------- popovers
  function closePop() { if (pop) { pop.remove(); pop = null; } document.querySelectorAll(".vl-c-target").forEach((e) => e.classList.remove("vl-c-target")); document.querySelectorAll(".vl-c-pin.vl-c-open").forEach((e) => e.classList.remove("vl-c-open")); openId = null; }
  function positionPop(p, el) {
    const r = el.getBoundingClientRect();
    const width = Math.min(320, window.innerWidth - 24);
    let left = window.scrollX + r.left;
    if (left + width > window.scrollX + window.innerWidth - 12) left = window.scrollX + window.innerWidth - width - 12;
    p.style.left = Math.max(12, left) + "px";
    p.style.top = window.scrollY + r.bottom + 6 + "px";
  }
  function makePop(el) {
    closePop();
    pop = document.createElement("div");
    pop.className = "vl-c-pop";
    pop._anchorEl = el;
    document.body.appendChild(pop);
    positionPop(pop, el);
    return pop;
  }
  const esc = (s) => String(s || "").replace(/[&<>"]/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]));
  const when = (s) => { try { const d = new Date(s.replace(" ", "T") + "Z"); return d.toLocaleString("fi-FI", { dateStyle: "short", timeStyle: "short" }); } catch (e) { return s; } };

  function openComposer(el, selection) {
    const anchor = anchorFor(el, selection);
    const p = makePop(el);
    el.classList.add("vl-c-target");
    p.innerHTML = '<div class="vl-c-quote">' + esc(anchor.quote.slice(0, 160)) + '</div><textarea placeholder="' + esc(T.write) + '"></textarea><div class="vl-c-row"><button type="button" class="vl-c-cancel">' + esc(T.cancel) + '</button><button type="button" class="vl-c-primary vl-c-send">' + esc(T.send) + "</button></div>";
    const ta = p.querySelector("textarea");
    ta.focus();
    const send = async () => {
      const text = ta.value.trim();
      if (!text) return;
      ta.disabled = true;
      try {
        const created = await api("", "POST", { post_id: cfg.postId, url: cfg.url, anchor, quote: anchor.quote, text });
        comments.unshift(created);
        closePop();
        renderPins();
      } catch (e) { ta.disabled = false; alert(e.message); }
    };
    p.querySelector(".vl-c-send").addEventListener("click", send);
    p.querySelector(".vl-c-cancel").addEventListener("click", closePop);
    ta.addEventListener("keydown", (e) => { if ((e.ctrlKey || e.metaKey) && e.key === "Enter") send(); if (e.key === "Escape") closePop(); });
  }

  function openThread(c, pinEl) {
    const entry = pins.get(c.id);
    const el = entry ? entry.el : pinEl;
    const p = makePop(el);
    if (entry) entry.el.classList.add("vl-c-target");
    if (pinEl && pinEl.classList) pinEl.classList.add("vl-c-open");
    openId = c.id;
    const badge = c.status === "resolved" ? T.resolved : c.status === "addressed" ? T.addressed : (c._how && c._how !== "exact" ? T[c._how] : "");
    const msg = (m, reply) => '<div class="vl-c-msg' + (reply ? " vl-c-reply" : "") + '"><div class="vl-c-meta">' + esc(m.author_name) + " · " + esc(when(m.created_at)) + "</div><div>" + esc(m.text).replace(/\n/g, "<br>") + "</div></div>";
    const canDelete = c.author_id === cfg.user.id || cfg.user.admin;
    p.innerHTML =
      '<div class="vl-c-quote">' + esc((c.quote || "").slice(0, 160)) + "</div>" +
      (badge ? '<span class="vl-c-badge">' + esc(badge) + "</span>" : "") +
      msg(c) + (c.replies || []).map((r) => msg(r, true)).join("") +
      '<textarea placeholder="' + esc(T.reply) + '…"></textarea>' +
      '<div class="vl-c-row">' +
      (canDelete ? '<button type="button" class="vl-c-danger vl-c-delete">' + esc(T.delete) + "</button>" : "") +
      '<button type="button" class="vl-c-status">' + esc(c.status === "resolved" ? T.reopen : T.resolve) + "</button>" +
      '<button type="button" class="vl-c-primary vl-c-send">' + esc(T.reply) + "</button></div>";
    const ta = p.querySelector("textarea");
    p.querySelector(".vl-c-send").addEventListener("click", async () => {
      const text = ta.value.trim();
      if (!text) return;
      try { const r = await api("/" + c.id + "/replies", "POST", { text }); c.replies = (c.replies || []).concat([r]); openThread(c, pinEl); } catch (e) { alert(e.message); }
    });
    ta.addEventListener("keydown", (e) => { if ((e.ctrlKey || e.metaKey) && e.key === "Enter") p.querySelector(".vl-c-send").click(); if (e.key === "Escape") closePop(); });
    p.querySelector(".vl-c-status").addEventListener("click", async () => {
      try {
        const updated = await api("/" + c.id + "/status", "POST", { status: c.status === "resolved" ? "open" : "resolved" });
        Object.assign(c, updated);
        closePop();
        renderPins();
      } catch (e) { alert(e.message); }
    });
    const del = p.querySelector(".vl-c-delete");
    if (del) del.addEventListener("click", async () => {
      if (!confirm(T.confirm)) return;
      try { await api("/" + c.id, "DELETE"); comments = comments.filter((x) => x.id !== c.id); closePop(); renderPins(); } catch (e) { alert(e.message); }
    });
  }

  // ---------------------------------------------------------------- panel
  function updatePanel() {
    if (!visible || !comments.length) { if (panel) { panel.remove(); panel = null; } return; }
    if (!panel) { panel = document.createElement("div"); panel.className = "vl-c-panel"; document.body.appendChild(panel); }
    const shown = comments.filter((c) => c.status !== "resolved" || showResolved);
    panel.innerHTML = "<h4>" + esc(T.comments) + " (" + shown.length + ")</h4>" +
      (shown.length ? shown.map((c, i) => {
        const n = comments.indexOf(c) + 1;
        const state = c.status === "resolved" ? T.resolved : c.status === "addressed" ? T.addressed : (c._how === "detached" ? T.detached : c._how === "near" ? T.near : c._how === "moved" ? T.moved : "");
        return '<div class="vl-c-item" data-id="' + c.id + '"><div class="vl-c-meta">' + n + " · " + esc(c.author_name) + " · " + esc(when(c.created_at)) + (state ? ' <span class="vl-c-badge">' + esc(state) + "</span>" : "") + "</div><div>" + esc(c.text.slice(0, 140)) + "</div></div>";
      }).join("") : "<div>" + esc(T.none) + "</div>") +
      '<div class="vl-c-foot"><button type="button" class="vl-c-toggle-resolved">' + esc(showResolved ? T.hideAll : T.showAll) + "</button></div>";
    panel.querySelectorAll(".vl-c-item").forEach((item) => item.addEventListener("click", () => {
      const c = comments.find((x) => x.id === Number(item.dataset.id));
      const entry = pins.get(c.id);
      if (entry) { entry.el.scrollIntoView({ behavior: "smooth", block: "center" }); setTimeout(() => openThread(c, entry.pin), 350); }
      else openThread(c, panel);
    }));
    panel.querySelector(".vl-c-toggle-resolved").addEventListener("click", () => { showResolved = !showResolved; store.set(KEY_RESOLVED, showResolved); renderPins(); });
  }

  // ------------------------------------------------------------- admin bar
  function updateBar() {
    const modeNode = document.getElementById("wp-admin-bar-valolink-comment-mode");
    const visNode = document.getElementById("wp-admin-bar-valolink-comments-visible");
    if (modeNode) { modeNode.classList.toggle("vl-c-on", mode); const l = modeNode.querySelector(".ab-label"); if (l) l.textContent = mode ? T.modeOn : T.modeOff; }
    if (visNode) { const open = comments.filter((c) => c.status !== "resolved").length; const l = visNode.querySelector(".ab-label"); if (l) l.textContent = T.comments + " (" + open + ")" + (visible ? "" : " ·"); }
    document.body.classList.toggle("vl-c-mode", mode);
  }
  function bindBar() {
    const modeNode = document.getElementById("wp-admin-bar-valolink-comment-mode");
    const visNode = document.getElementById("wp-admin-bar-valolink-comments-visible");
    if (modeNode) modeNode.addEventListener("click", (e) => { e.preventDefault(); mode = !mode; store.set(KEY_MODE, mode); if (!mode) { closePop(); if (hoverEl) { hoverEl.classList.remove("vl-c-hover"); hoverEl = null; } } if (mode) visible = true; updateBar(); renderPins(); });
    if (visNode) visNode.addEventListener("click", (e) => { e.preventDefault(); visible = !visible; store.set(KEY_VISIBLE, visible); closePop(); renderPins(); observe(5000); });
  }

  // ------------------------------------------------------- mode interaction
  document.addEventListener("mousemove", (e) => {
    if (!mode || isOurs(e.target)) return;
    const el = targetFor(e.target);
    if (el === hoverEl || el === document.body) return;
    if (hoverEl) hoverEl.classList.remove("vl-c-hover");
    hoverEl = el;
    if (hoverEl) hoverEl.classList.add("vl-c-hover");
  });
  document.addEventListener("click", (e) => {
    if (!mode || isOurs(e.target)) return;
    const sel = window.getSelection ? norm(String(window.getSelection())) : "";
    const el = targetFor(e.target);
    if (!el || el === document.body) return;
    e.preventDefault();
    e.stopPropagation();
    openComposer(el, sel && el.contains(window.getSelection().anchorNode) ? sel : "");
  }, true);
  document.addEventListener("keydown", (e) => { if (e.key === "Escape") closePop(); });

  // ------------------------------------------------------------------ boot
  async function boot() {
    bindBar();
    updateBar();
    if (cfg.postId) {
      try { comments = (await api("?post_id=" + cfg.postId + "&status=all")).comments || []; } catch (e) { comments = []; }
    }
    renderPins();
    observe(15000);
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot); else boot();
})();
