/* Checkout: field validation display, Stripe Payment Element (deferred intent) or local test gateway. */
(() => {
    'use strict';
    const form = document.querySelector('[data-checkout]');
    if (!form) return;
    const btn = document.querySelector('[data-place-order]');
    const errorBox = document.querySelector('[data-checkout-error]');
    const shipToggle = form.querySelector('[data-toggle-shipping]');
    const shipFields = form.querySelector('[data-shipping-fields]');
    const btnLabel = btn ? btn.textContent : '';

    const setShipping = () => {
        if (!shipToggle || !shipFields) return;
        shipFields.hidden = !shipToggle.checked;
        shipFields.querySelectorAll('input, select').forEach((el) => { el.disabled = !shipToggle.checked; });
    };
    if (shipToggle) { shipToggle.addEventListener('change', setShipping); setShipping(); }

    const showError = (msg) => { errorBox.textContent = msg; errorBox.hidden = !msg; if (msg) errorBox.scrollIntoView({ block: 'center', behavior: 'smooth' }); };
    const clearFieldErrors = () => {
        document.querySelectorAll('.field--error').forEach((f) => f.classList.remove('field--error'));
        document.querySelectorAll('[data-generated-error], [data-error]').forEach((p) => { if (p.dataset.generatedError) p.remove(); else p.hidden = true; });
    };
    const showFieldErrors = (errors) => {
        let first = null;
        Object.keys(errors).forEach((name) => {
            const input = document.querySelector('[name="' + name + '"]');
            const slot = document.querySelector('[data-error="' + name + '"]');
            if (slot) { slot.textContent = errors[name]; slot.hidden = false; first = first || slot; return; }
            if (!input) return;
            const wrap = input.closest('.field') || input.parentElement;
            wrap.classList.add('field--error');
            const p = document.createElement('p');
            p.className = 'field__error'; p.dataset.generatedError = '1'; p.textContent = errors[name];
            wrap.appendChild(p);
            first = first || input;
        });
        if (first) first.focus();
    };
    const busy = (on) => { if (!btn) return; btn.disabled = on; btn.textContent = on ? 'Processing…' : btnLabel; };

    // Stripe Payment Element, created with the cart total before an order exists.
    const peHost = document.getElementById('payment-element');
    let stripe = null; let elements = null;
    if (peHost && window.Stripe) {
        stripe = window.Stripe(peHost.dataset.publishableKey);
        elements = stripe.elements({
            mode: 'payment', amount: +form.dataset.total, currency: form.dataset.currency,
            appearance: { theme: 'stripe', variables: { colorPrimary: '#2C211C', borderRadius: '10px', fontFamily: 'Inter, system-ui, sans-serif' } },
        });
        elements.create('payment', { layout: 'accordion' }).mount(peHost);
    }

    const place = () => fetch(form.action, {
        method: 'POST', body: new FormData(form), credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    }).then((r) => r.json().then((j) => ({ ok: r.ok, data: j })));

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearFieldErrors(); showError('');
        busy(true);
        try {
            if (elements) {
                const { error: submitError } = await elements.submit();
                if (submitError) { showError(submitError.message); busy(false); return; }
            }
            const { ok, data } = await place();
            if (!ok) {
                if (data.errors) showFieldErrors(data.errors);
                showError(data.error || 'Something went wrong. Please try again.');
                if (data.reload) setTimeout(() => location.reload(), 2500);
                busy(false); return;
            }
            if (stripe) {
                const fd = new FormData(form);
                const { error } = await stripe.confirmPayment({
                    elements, clientSecret: data.clientSecret,
                    confirmParams: {
                        return_url: data.returnUrl,
                        payment_method_data: { billing_details: {
                            name: fd.get('billing_first_name') + ' ' + fd.get('billing_last_name'), email: fd.get('email'), phone: fd.get('billing_phone') || undefined,
                            address: { line1: fd.get('billing_address_1'), line2: fd.get('billing_address_2') || undefined, city: fd.get('billing_city'), state: fd.get('billing_state'), postal_code: fd.get('billing_postcode'), country: 'US' },
                        } },
                    },
                });
                // Only reached on immediate errors (card declined, validation); success redirects.
                showError(error && error.message ? error.message : 'Payment could not be completed.');
                busy(false);
                return;
            }
            // Local test gateway.
            const outcome = (form.querySelector('[name="test_outcome"]:checked') || document.querySelector('[name="test_outcome"]:checked') || {}).value || 'approve';
            const fd = new FormData(); fd.append('intent', data.intentId); fd.append('outcome', outcome); fd.append('_token', form.querySelector('[name="_token"]').value);
            await fetch(form.action.replace(/place$/, 'test-pay'), { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } });
            location.href = data.returnUrl;
        } catch (err) {
            showError('Network error. Please check your connection and try again.');
            busy(false);
        }
    });
})();
