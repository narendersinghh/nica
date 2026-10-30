/* The NiCa Collection: cookie consent + analytics loader.
   Nothing third-party loads until the visitor accepts. The choice is kept in
   localStorage (no cookie, so pages stay cacheable). */
(() => {
    'use strict';
    const script = document.currentScript;
    const config = JSON.parse((script && script.dataset.config) || '{}');
    const banner = document.querySelector('[data-consent]');
    const KEY = 'nica_consent';
    const read = () => { try { return localStorage.getItem(KEY); } catch (e) { return null; } };
    const save = (v) => { try { localStorage.setItem(KEY, v); } catch (e) { /* private mode */ } };
    const addScript = (src) => { const s = document.createElement('script'); s.src = src; s.async = true; document.head.appendChild(s); };
    const pageData = (() => { const el = document.getElementById('analytics-data'); try { return el ? JSON.parse(el.textContent) : null; } catch (e) { return null; } })();
    let loaded = false;

    const load = () => {
        if (loaded) return;
        loaded = true;
        if (config.ga4) {
            window.dataLayer = window.dataLayer || [];
            window.gtag = function () { window.dataLayer.push(arguments); };
            window.gtag('js', new Date());
            window.gtag('config', config.ga4, { anonymize_ip: true });
            addScript('https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(config.ga4));
        }
        if (config.meta) {
            const fbq = window.fbq = function () { fbq.callMethod ? fbq.callMethod.apply(fbq, arguments) : fbq.queue.push(arguments); };
            if (!window._fbq) window._fbq = fbq;
            fbq.push = fbq; fbq.loaded = true; fbq.version = '2.0'; fbq.queue = [];
            addScript('https://connect.facebook.net/en_US/fbevents.js');
            fbq('init', config.meta);
            fbq('track', 'PageView');
        }
        if (config.klaviyo) {
            addScript('https://static.klaviyo.com/onsite/js/klaviyo.js?company_id=' + encodeURIComponent(config.klaviyo));
        }
        if (pageData) track(pageData.event, pageData);
    };

    const track = (event, data) => {
        if (!loaded) return;
        if (event === 'purchase') {
            const seen = 'nica_tracked_' + data.transaction_id;
            try { if (localStorage.getItem(seen)) return; localStorage.setItem(seen, '1'); } catch (e) { /* ignore */ }
        }
        if (window.gtag) window.gtag('event', event, data);
        if (window.fbq) {
            const map = { view_item: 'ViewContent', add_to_cart: 'AddToCart', purchase: 'Purchase' };
            if (map[event]) window.fbq('track', map[event], { value: data.value, currency: data.currency || 'USD', content_type: 'product', content_ids: (data.items || []).map((i) => i.item_id) });
        }
    };

    document.addEventListener('nica:add_to_cart', (e) => {
        if (pageData && pageData.event === 'view_item') track('add_to_cart', Object.assign({}, pageData, { items: pageData.items.map((i) => Object.assign({}, i, { quantity: e.detail.quantity })) }));
    });

    const show = () => { if (banner) banner.hidden = false; };
    if (banner) {
        banner.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-consent-choice]');
            if (!btn) return;
            save(btn.dataset.consentChoice);
            banner.hidden = true;
            if (btn.dataset.consentChoice === 'yes') load();
        });
    }
    document.querySelectorAll('[data-consent-open]').forEach((b) => b.addEventListener('click', show));

    const choice = read();
    if (choice === 'yes') load();
    else if (choice === null) show();
})();
