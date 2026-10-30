/* The NiCa Collection: site interactions (no dependencies). */
(() => {
    'use strict';

    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    /* Pages from the page cache are shared, so their forms carry no CSRF token.
       Fetch one just before the first submit, then send the form as usual. */
    let tokenPromise = null;
    const fetchToken = () => tokenPromise ??= fetch((document.documentElement.dataset.base || '') + '/csrf-token', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then((r) => r.json()).then((d) => {
            $$('input[name="_token"]').forEach((i) => { i.value = d.token; });
            const meta = $('meta[name="csrf-token"]');
            if (meta) meta.content = d.token;
            return d.token;
        });
    document.addEventListener('submit', (e) => {
        const form = e.target;
        const input = form.querySelector('input[name="_token"]');
        if (!input || input.value !== '' || (form.getAttribute('method') || '').toLowerCase() !== 'post') return;
        e.preventDefault();
        e.stopImmediatePropagation();
        const submitter = e.submitter && e.submitter.form === form ? e.submitter : undefined;
        fetchToken().then(() => (form.requestSubmit ? form.requestSubmit(submitter) : form.submit()))
            .catch(() => { tokenPromise = null; form.submit(); });
    }, true);

    /* Mobile menu: open/close, Escape, focus return, scroll lock */
    const menu = $('#mobile-menu');
    if (menu) {
        let opener = null;
        const open = (btn) => {
            opener = btn;
            menu.hidden = false;
            document.body.style.overflow = 'hidden';
            $$('[data-menu-open]').forEach((b) => b.setAttribute('aria-expanded', 'true'));
            ($('a, button', $('.mobile-menu__panel', menu)) || menu).focus();
        };
        const close = () => {
            menu.hidden = true;
            document.body.style.overflow = '';
            $$('[data-menu-open]').forEach((b) => b.setAttribute('aria-expanded', 'false'));
            if (opener) opener.focus();
        };
        $$('[data-menu-open]').forEach((btn) => btn.addEventListener('click', () => open(btn)));
        $$('[data-menu-close]', menu).forEach((el) => el.addEventListener('click', close));
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !menu.hidden) close();
        });
        // Keep keyboard focus inside the open menu.
        menu.addEventListener('keydown', (e) => {
            if (e.key !== 'Tab') return;
            const items = $$('a, button', menu).filter((el) => el.offsetParent !== null);
            const first = items[0];
            const last = items[items.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        });
        window.matchMedia('(min-width: 1025px)').addEventListener('change', (mq) => { if (mq.matches && !menu.hidden) close(); });
    }

    /* Video dialogs: load the video only when opened */
    $$('[data-video-open]').forEach((btn) => {
        const dialog = document.getElementById(btn.dataset.videoOpen);
        if (!dialog || typeof dialog.showModal !== 'function') return;
        const video = $('video', dialog);
        btn.addEventListener('click', () => {
            if (video && !video.src) video.src = video.dataset.src;
            dialog.showModal();
            if (video) video.play().catch(() => {});
        });
        const stop = () => { if (video) video.pause(); };
        dialog.addEventListener('close', stop);
        dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); });
        $$('[data-dialog-close]', dialog).forEach((el) => el.addEventListener('click', () => dialog.close()));
    });

    /* Carousels: prev/next scroll by one card */
    $$('[data-carousel]').forEach((carousel) => {
        const track = $('[data-carousel-track]', carousel);
        if (!track) return;
        const step = () => {
            const card = track.firstElementChild;
            const gap = parseFloat(getComputedStyle(track).columnGap) || 0;
            return card ? card.getBoundingClientRect().width + gap : track.clientWidth;
        };
        const go = (dir) => {
            const max = track.scrollWidth - track.clientWidth - 2;
            if (dir > 0 && track.scrollLeft >= max) track.scrollTo({ left: 0, behavior: 'smooth' });
            else if (dir < 0 && track.scrollLeft <= 2) track.scrollTo({ left: track.scrollWidth, behavior: 'smooth' });
            else track.scrollBy({ left: dir * step(), behavior: 'smooth' });
        };
        const prev = $('[data-carousel-prev]', carousel);
        const next = $('[data-carousel-next]', carousel);
        if (prev) prev.addEventListener('click', () => go(-1));
        if (next) next.addEventListener('click', () => go(1));
    });

    /* Print buttons */
    $$('[data-print]').forEach((el) => el.addEventListener('click', (e) => { e.preventDefault(); window.print(); }));

    /* Contact form: preselect the role from ?role= (links from For Stylists etc.) */
    const roleParam = new URLSearchParams(location.search).get('role');
    if (roleParam) {
        const radio = document.querySelector(`.role-tabs input[value="${CSS.escape(roleParam)}"]`);
        if (radio) radio.checked = true;
    }

    /* Colour swatches: colour comes from data-swatch (inline styles are blocked by the CSP) */
    $$('[data-swatch]').forEach((el) => { el.style.backgroundColor = el.dataset.swatch; });

    /* Sort dropdowns submit on change */
    $$('select[data-autosubmit]').forEach((s) => s.addEventListener('change', () => s.form.submit()));

    /* Tabs (WAI-ARIA tablist with arrow-key support) */
    $$('[data-tabs]').forEach((box) => {
        const tabs = $$('[role="tab"]', box);
        const select = (tab, focus) => {
            tabs.forEach((t) => {
                const on = t === tab;
                t.setAttribute('aria-selected', on ? 'true' : 'false');
                t.tabIndex = on ? 0 : -1;
                const panel = document.getElementById(t.getAttribute('aria-controls'));
                if (panel) panel.hidden = !on;
            });
            if (focus) tab.focus();
        };
        tabs.forEach((t, i) => {
            t.addEventListener('click', () => select(t));
            t.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowRight') select(tabs[(i + 1) % tabs.length], true);
                if (e.key === 'ArrowLeft') select(tabs[(i - 1 + tabs.length) % tabs.length], true);
            });
        });
        // Deep link (#reviews) and in-page links to a panel open the right tab.
        const openFromHash = () => {
            const t = tabs.find((x) => '#' + x.getAttribute('aria-controls') === location.hash);
            if (t) { select(t); t.scrollIntoView({ block: 'start' }); }
        };
        window.addEventListener('hashchange', openFromHash);
        openFromHash();
    });

    /* Product gallery: thumbnails + lightbox */
    $$('[data-gallery]').forEach((g) => {
        const slides = $$('.gallery__slide', g);
        const thumbs = $$('[data-gallery-thumb]', g);
        const show = (n) => {
            slides.forEach((s, i) => { s.hidden = i !== n; s.classList.toggle('is-active', i === n); });
            thumbs.forEach((t, i) => t.classList.toggle('is-active', i === n));
        };
        thumbs.forEach((t) => t.addEventListener('click', () => show(+t.dataset.galleryThumb)));
        const box = document.getElementById('gallery-lightbox');
        if (box && typeof box.showModal === 'function') {
            const img = $('[data-lightbox-img]', box);
            slides.forEach((s) => s.addEventListener('click', () => { img.src = s.dataset.galleryZoom; box.showModal(); }));
            box.addEventListener('click', (e) => { if (e.target === box) box.close(); });
            $$('[data-dialog-close]', box).forEach((b) => b.addEventListener('click', () => box.close()));
        }
        g.showImage = (url) => {
            const i = slides.findIndex((s) => s.dataset.galleryZoom && url && url.indexOf(s.dataset.galleryZoom.replace(/\.[a-z]+$/, '')) === 0);
            if (i >= 0) show(i);
        };
    });

    /* Variant picker: match chosen values to a variant (missing value = "any") */
    const pickerForm = $('[data-variant-picker]');
    const pickerData = document.getElementById('variant-data');
    if (pickerForm && pickerData) {
        const data = JSON.parse(pickerData.textContent);
        const fieldsets = $$('fieldset[data-attribute]', pickerForm);
        const input = $('[data-variant-input]', pickerForm);
        const addBtn = $('[data-add]', pickerForm);
        const stock = $('[data-stock]', pickerForm);
        const hint = $('[data-hint]', pickerForm);
        const clear = $('[data-clear]', pickerForm);
        const qty = $('input[name="quantity"]', pickerForm);
        const priceEl = $('[data-price]');
        const initialPrice = priceEl ? priceEl.innerHTML : '';
        const money = (c) => '$' + (c / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const chosen = () => {
            const sel = {};
            fieldsets.forEach((fs) => { const r = $('input:checked', fs); if (r) sel[fs.dataset.attribute] = +r.value; });
            return sel;
        };
        const fits = (v, sel, skip) => Object.keys(sel).every((a) => a === skip || v.values[a] === undefined || v.values[a] === sel[a]);
        const update = () => {
            const sel = chosen();
            // Disable values that no available variant can satisfy with the other choices.
            fieldsets.forEach((fs) => {
                const a = fs.dataset.attribute;
                $$('input', fs).forEach((r) => {
                    const test = Object.assign({}, sel, { [a]: +r.value });
                    const possible = data.variants.some((v) => v.in_stock && fits(v, test));
                    r.disabled = !possible && !r.checked;
                    r.parentElement.classList.toggle('is-unavailable', !possible);
                });
                const c = $('input:checked', fs);
                $('[data-chosen]', fs).textContent = c ? ': ' + c.dataset.name : '';
            });
            const complete = fieldsets.every((fs) => $('input:checked', fs));
            const match = complete ? data.variants.find((v) => fits(v, sel)) : null;
            if (clear) clear.hidden = !Object.keys(sel).length;
            if (match) {
                input.value = match.id;
                addBtn.disabled = !match.in_stock;
                stock.textContent = !match.in_stock ? 'Out of stock' : (match.stock !== null && match.stock <= 5 ? match.stock + ' in stock' : 'In stock');
                if (qty) qty.max = match.stock !== null ? Math.max(1, match.stock) : 99;
                if (priceEl) priceEl.innerHTML = match.price < match.regular
                    ? '<span class="price"><del class="price__regular">' + money(match.regular) + '</del> <ins class="price__sale">' + money(match.price) + '</ins></span>'
                    : '<span class="price"><span class="price__amount">' + money(match.price) + '</span></span>';
                if (match.image && data.images[match.image]) { const g = $('[data-gallery]'); if (g && g.showImage) g.showImage(data.images[match.image]); }
                if (hint) hint.hidden = true;
            } else {
                input.value = '';
                addBtn.disabled = true;
                stock.textContent = complete ? 'This combination is not available.' : '';
                if (priceEl) priceEl.innerHTML = initialPrice;
                if (hint) hint.hidden = complete;
            }
        };
        pickerForm.addEventListener('change', update);
        if (clear) clear.addEventListener('click', () => { $$('input[type="radio"]', pickerForm).forEach((r) => { r.checked = false; }); update(); });
        // Pre-select attributes with a single value.
        fieldsets.forEach((fs) => { const r = $$('input', fs); if (r.length === 1) r[0].checked = true; });
        update();
    }

    /* Cart drawer + AJAX add to cart (forms still work without JS) */
    const drawer = document.getElementById('cart-drawer');
    const post = (url, body) => fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
        .then((r) => r.json().then((j) => { if (!r.ok && !j.message) throw new Error(r.status); return j; }));
    const paintCart = (data) => {
        $$('[data-cart-count]').forEach((el) => { el.textContent = data.count; });
        $$('[data-cart-total]').forEach((el) => { el.textContent = data.subtotal; });
        const body = drawer && $('[data-drawer-body]', drawer);
        if (body && data.drawer !== undefined) body.innerHTML = data.drawer;
    };
    let drawerOpener = null;
    const openDrawer = (opener) => {
        if (!drawer) return;
        drawerOpener = opener || document.activeElement;
        drawer.hidden = false;
        document.body.style.overflow = 'hidden';
        const close = $('.drawer__close', drawer);
        if (close) close.focus();
    };
    const closeDrawer = () => {
        if (!drawer || drawer.hidden) return;
        drawer.hidden = true;
        document.body.style.overflow = '';
        if (drawerOpener) drawerOpener.focus();
    };
    if (drawer) {
        $$('[data-drawer-close]', drawer).forEach((el) => el.addEventListener('click', closeDrawer));
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeDrawer(); });
        $$('[data-cart-toggle]').forEach((link) => link.addEventListener('click', (e) => {
            if (location.pathname.match(/\/(cart|checkout)\/$/)) return; // on cart/checkout the link just navigates
            e.preventDefault();
            openDrawer(link);
            fetch(link.href.replace(/\/cart\/?$/, '/cart/summary'), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((r) => r.json()).then(paintCart).catch(() => { location.href = link.href; });
        }));
        drawer.addEventListener('submit', (e) => {
            const form = e.target.closest('[data-cart-remove]');
            if (!form) return;
            e.preventDefault();
            post(form.action, new FormData(form)).then(paintCart).catch(() => form.submit());
        });
    }
    $$('form.buy-form').forEach((form) => form.addEventListener('submit', (e) => {
        if (!drawer || !window.fetch) return;
        e.preventDefault();
        const btn = $('[data-add]', form);
        if (btn) { btn.disabled = true; btn.classList.add('is-loading'); }
        post(form.action, new FormData(form)).then((data) => {
            paintCart(data);
            if (data.ok) {
                openDrawer(btn);
                document.dispatchEvent(new CustomEvent('nica:add_to_cart', { detail: { quantity: +(new FormData(form).get('quantity') || 1) } }));
            }
            else {
                let note = $('.buy-form__error', form);
                if (!note) { note = document.createElement('p'); note.className = 'buy-form__error'; note.setAttribute('role', 'alert'); form.appendChild(note); }
                note.textContent = data.message;
            }
        }).catch(() => form.submit()).finally(() => { if (btn) { btn.disabled = false; btn.classList.remove('is-loading'); } });
    }));

    /* Shipping method radios submit their form on change */
    $$('[data-autosubmit-radio]').forEach((r) => r.addEventListener('change', () => r.form.submit()));

    /* Show/hide password */
    $$('[data-password-toggle]').forEach((b) => b.addEventListener('click', () => {
        const input = b.parentElement.querySelector('input');
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    }));

    /* Registration: vendor fields only for "I am a vendor"; shop URL follows the shop name */
    $$('[data-register]').forEach((form) => {
        const fields = $('[data-vendor-fields]', form);
        const sync = () => {
            const vendor = ($('input[name="role"]:checked', form) || {}).value === 'vendor';
            fields.hidden = !vendor;
            $$('input', fields).forEach((i) => { i.disabled = !vendor; });
        };
        $$('input[name="role"]', form).forEach((r) => r.addEventListener('change', sync));
        sync();
        const name = $('input[name="shopname"]', form);
        const url = $('[data-shopurl]', form);
        const preview = $('[data-shopurl-preview]', form);
        let touched = url.value !== '';
        const slug = (s) => s.toLowerCase().normalize('NFKD').replace(/[\u0300-\u036f]/g, '').replace(/&/g, ' and ').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80);
        name.addEventListener('input', () => { if (!touched) { url.value = slug(name.value); preview.textContent = url.value; } });
        url.addEventListener('input', () => { touched = true; url.value = slug(url.value); preview.textContent = url.value; });
    });

    /* Accordions (FAQs): one open per group, animated with native <details> */
    $$('[data-accordion]').forEach((group) => {
        const items = $$('details', group);
        items.forEach((d) => d.addEventListener('toggle', () => {
            if (d.open) items.forEach((o) => { if (o !== d) o.open = false; });
        }));
    });

    // Stylist dashboard: copy link, progress meter, payout method panels.
    document.querySelectorAll('[data-copy]').forEach((btn) => btn.addEventListener('click', async () => {
        const input = document.querySelector(btn.dataset.copy);
        if (!input) return;
        input.select();
        try { await navigator.clipboard.writeText(input.value); } catch (e) { document.execCommand('copy'); }
        const label = btn.textContent; btn.textContent = 'Copied!'; setTimeout(() => { btn.textContent = label; }, 1800);
    }));
    document.querySelectorAll('[data-select-all]').forEach((i) => i.addEventListener('focus', () => i.select()));
    document.querySelectorAll('.meter [data-width]').forEach((el) => { el.style.width = Math.min(100, +el.dataset.width) + '%'; });
    document.querySelectorAll('[data-payout-method]').forEach((sel) => {
        const sync = () => document.querySelectorAll('[data-payout-panel]').forEach((p) => { p.hidden = p.dataset.payoutPanel !== sel.value; });
        sel.addEventListener('change', sync); sync();
    });
})();
