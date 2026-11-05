/**
 * WooCommerce browser events for valolink-plugin's Umami module: view_item,
 * add_to_cart, view_cart, begin_checkout. Purchases are sent from the server
 * (WooPurchases.php). Loaded only on sites with WooCommerce, after events.js.
 *
 * Ported from WebDig.DEV Analytics' assets/tracker.js (GPL-2.0-or-later).
 */
(() => {
  'use strict';

  const api = window.ValolinkUmami;
  const c = window.valolinkUmamiConfig;
  if (!api || !c || !c.woo) return;

  const woo = c.woo;

  api.onReady(() => {
    if (woo.product) api.track('view_item', woo.product);
    if (woo.cart) api.track('view_cart', {});
    if (woo.checkout) api.track('begin_checkout', {});
  });

  // One add can show up as several signals: the product form's submit, the
  // classic jQuery event, and the Blocks event WooCommerce translates it into.
  // The first one within the window counts.
  let lastAdd = 0;
  function addToCart(data) {
    const now = Date.now();
    if (now - lastAdd < 1500) return;
    lastAdd = now;
    api.track('add_to_cart', data);
  }

  const number = (value) => {
    const n = parseFloat(value);
    return Number.isFinite(n) && n > 0 ? n : 1;
  };

  // Product page form. A plain submit reloads the page; keepalive lets the
  // event outlive it.
  document.addEventListener('submit', (e) => {
    const form = e.target instanceof Element ? e.target : null;
    if (!form || !form.matches('form.cart')) return;
    const field = (name) => {
      const input = form.querySelector(`[name="${name}"]`);
      return input ? String(input.value || '') : '';
    };
    const productId = field('add-to-cart') || (woo.product && woo.product.product_id) || '';
    addToCart({
      product_id: String(productId),
      variation_id: field('variation_id'),
      name: (woo.product && woo.product.name) || '',
      quantity: number(field('quantity')),
    });
  });

  // Shop and archive pages: AJAX add-to-cart buttons.
  if (window.jQuery) {
    window.jQuery(document.body).on('added_to_cart', (_e, _fragments, _hash, button) => {
      const data = button && typeof button.data === 'function' ? button : null;
      addToCart({
        product_id: String((data && data.data('product_id')) || ''),
        quantity: number(data && data.data('quantity')),
      });
    });
  }

  // Block themes and the Product Collection block. Not bubbling: listen on body.
  document.body.addEventListener('wc-blocks_added_to_cart', () => addToCart({ source: 'blocks' }));
})();
