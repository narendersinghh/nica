/* Find Match quiz: steps from #quiz-config, results from the match API. */
(() => {
    'use strict';
    const root = document.querySelector('[data-quiz]');
    const cfgEl = document.getElementById('quiz-config');
    if (!root || !cfgEl) return;
    const cfg = JSON.parse(cfgEl.textContent);
    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const answers = {};
    cfg.steps.forEach((s) => { answers[s.key] = []; });
    answers.sub = [];
    let flow = [];
    let i = 0;

    root.innerHTML = '<div class="quiz__bar"><i></i></div><div class="quiz__body" aria-live="polite"></div>'
        + '<div class="quiz__foot"><button type="button" class="quiz__back">Back</button><button type="button" class="btn btn--dark quiz__next">Continue</button></div>';
    const body = root.querySelector('.quiz__body');
    const bar = root.querySelector('.quiz__bar i');
    const next = root.querySelector('.quiz__next');
    const back = root.querySelector('.quiz__back');
    const foot = root.querySelector('.quiz__foot');

    // The "narrow it down" step appears after categories when chosen ones have sub-collections.
    const build = () => {
        flow = [];
        cfg.steps.forEach((s) => {
            flow.push(s);
            if (s.key !== 'cat') return;
            const opts = [];
            answers.cat.forEach((ci) => (s.options[ci].children || []).forEach((c, si) => opts.push({ name: c.name, id: ci + '.' + si })));
            answers.sub = answers.sub.filter((id) => opts.some((o) => o.id === id));
            if (opts.length) flow.push({ key: 'sub', display: 'text', question: 'Let us narrow it down', subtitle: 'Choose the collections inside your selection, or continue to see them all.', options: opts });
        });
    };
    const optionId = (s, o, n) => (s.key === 'sub' ? o.id : n);
    const required = () => !!flow[i].required;
    const syncButtons = () => {
        const n = answers[flow[i].key].length;
        next.disabled = required() && !n;
        next.textContent = i === flow.length - 1 ? 'Show My Matches' : (!n && !required() ? 'Skip' : 'Continue');
        back.style.visibility = i === 0 ? 'hidden' : 'visible';
    };
    const tile = (s, o) => {
        if (s.display === 'text') return '';
        if (s.display === 'swatch' || o.swatch) return '<span class="quiz__swatch" data-swatch="' + esc(o.swatch) + '"></span>';
        if (o.image) return '<img src="' + esc(o.image) + '" alt="" loading="lazy">';
        return '<span class="quiz__ph">' + esc(o.name.charAt(0)) + '</span>';
    };
    const draw = () => {
        const s = flow[i];
        bar.style.width = (i / (flow.length + 1)) * 100 + '%';
        foot.hidden = false;
        let h = '<p class="quiz__tag">Step ' + (i + 1) + ' of ' + flow.length + '</p><h2 class="quiz__q">' + esc(s.question) + '</h2><p class="quiz__sub">' + esc(s.subtitle) + '</p>'
            + '<div class="quiz__opts quiz__opts--' + esc(s.display || 'image') + '" role="group" aria-label="' + esc(s.question) + '">';
        s.options.forEach((o, n) => {
            const id = optionId(s, o, n);
            const on = answers[s.key].indexOf(id) > -1;
            h += '<button type="button" class="quiz__op' + (on ? ' is-selected' : '') + '" aria-pressed="' + on + '" data-id="' + esc(id) + '">'
                + tile(s, o) + '<span class="quiz__lb">' + esc(o.name) + '</span>' + (o.sub ? '<span class="quiz__sm">' + esc(o.sub) + '</span>' : '') + '<span class="quiz__ck" aria-hidden="true"></span></button>';
        });
        body.innerHTML = h + '</div>';
        body.querySelectorAll('[data-swatch]').forEach((el) => { el.style.backgroundColor = el.dataset.swatch; });
        syncButtons();
        if (root.getBoundingClientRect().top < 0) root.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };
    const results = () => {
        bar.style.width = '100%';
        foot.hidden = true;
        body.innerHTML = '<p class="quiz__loading">Finding your perfect match...</p>';
        const q = new URLSearchParams();
        Object.keys(answers).forEach((k) => { if (answers[k].length) q.set(k, answers[k].join(',')); });
        fetch(cfg.api + '?' + q.toString(), { headers: { Accept: 'application/json' } })
            .then((r) => { if (!r.ok) throw new Error(r.status); return r.json(); })
            .then((data) => show(data.items || [], !!data.soft))
            .catch(() => { body.innerHTML = '<p class="quiz__error">Something went wrong. Please try again.</p><div class="quiz__act"><button type="button" class="btn btn--dark quiz__restart">Start Over</button></div>'; });
    };
    const show = (items, soft) => {
        const title = items.length ? (soft ? 'Closest to your picks' : 'Curated for you') : 'No close match yet';
        const sub = items.length
            ? (soft ? 'Nothing matched every filter you chose, so here are the pieces that match the most of them.' : 'Based on your answers, these are the pieces closest to what you are after.')
            : 'Try widening your selection and run the finder again.';
        let h = '<p class="quiz__tag">Your Results</p><h2 class="quiz__q">' + title + '</h2><p class="quiz__sub">' + sub + '</p>';
        if (items.length) {
            h += '<div class="quiz__results">';
            items.forEach((p) => {
                h += '<a class="quiz__card" href="' + esc(p.url) + '">' + (p.image ? '<img src="' + esc(p.image) + '" alt="" loading="lazy">' : '<span class="quiz__noimg"></span>')
                    + '<span class="quiz__ci"><span class="quiz__mt">' + p.match + '% match</span>' + (p.vendor ? '<span class="quiz__vn">By: ' + esc(p.vendor) + '</span>' : '')
                    + '<span class="quiz__nm">' + esc(p.name) + '</span><span class="quiz__pr">' + p.price + '</span></span></a>';
            });
            h += '</div>';
        }
        body.innerHTML = h + '<div class="quiz__act"><button type="button" class="btn btn--dark quiz__restart">Start Over</button></div>';
    };
    root.addEventListener('click', (e) => {
        const op = e.target.closest('.quiz__op');
        if (op) {
            const k = flow[i].key;
            const raw = op.dataset.id;
            const id = k === 'sub' ? raw : +raw;
            const at = answers[k].indexOf(id);
            if (at > -1) answers[k].splice(at, 1); else answers[k].push(id);
            op.classList.toggle('is-selected', at === -1);
            op.setAttribute('aria-pressed', at === -1 ? 'true' : 'false');
            syncButtons();
            return;
        }
        if (e.target.closest('.quiz__restart')) {
            Object.keys(answers).forEach((k) => { answers[k] = []; });
            i = 0; build(); draw();
        }
    });
    next.addEventListener('click', () => {
        if (required() && !answers[flow[i].key].length) return;
        if (flow[i].key === 'cat') build();
        if (i < flow.length - 1) { i++; draw(); } else { results(); }
    });
    back.addEventListener('click', () => { if (i > 0) { i--; draw(); } });
    build();
    draw();
})();
