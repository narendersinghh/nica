/* The NiCa Collection: dashboard behaviour. Progressive enhancement only:
   every form also works without JavaScript. */
(function () {
    'use strict';

    var $ = function (sel, root) { return (root || document).querySelector(sel); };
    var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

    /* ---------------------------------------------------------- sidebar */
    var side = $('[data-panel-side]');
    var scrim = $('.panel__scrim');
    var burger = $('.panel__burger');
    function toggleSide(open) {
        if (!side) return;
        side.classList.toggle('is-open', open);
        if (scrim) scrim.hidden = !open;
        if (burger) burger.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) { var first = $('a', side); if (first) first.focus(); }
    }
    $$('[data-panel-toggle]').forEach(function (el) {
        el.addEventListener('click', function () { toggleSide(!side.classList.contains('is-open')); });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && side && side.classList.contains('is-open')) { toggleSide(false); if (burger) burger.focus(); }
    });

    /* ---------------------------------------------------------- small helpers */
    $$('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!window.confirm(form.getAttribute('data-confirm'))) e.preventDefault();
        });
    });
    $$('[data-width]').forEach(function (el) { el.style.width = Math.max(0, Math.min(100, +el.getAttribute('data-width'))) + '%'; });
    $$('[data-swatch]').forEach(function (el) {
        var c = el.getAttribute('data-swatch');
        if (/^#[0-9a-f]{3,8}$/i.test(c)) el.style.background = c;
    });
    $$('[data-show]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = $(btn.getAttribute('data-show'));
            if (target) { target.hidden = false; btn.hidden = true; var f = $('input, select', target); if (f) f.focus(); }
        });
    });
    $$('[data-autosubmit]').forEach(function (el) {
        el.addEventListener('change', function () { if (el.form) el.form.submit(); });
    });
    $$('[data-print]').forEach(function (btn) { btn.addEventListener('click', function () { window.print(); }); });

    // Radio groups that switch panels: data-type-toggle / data-method-toggle.
    function panelSwitch(toggleAttr, panelAttr) {
        var radios = $$('[' + toggleAttr + ']');
        if (!radios.length) return;
        var update = function () {
            var value = (radios.filter(function (r) { return r.checked; })[0] || {}).value;
            $$('[' + panelAttr + ']').forEach(function (p) { p.hidden = p.getAttribute(panelAttr) !== value; });
            if (panelAttr === 'data-type-panel') $$('[data-type-only]').forEach(function (p) { p.hidden = p.getAttribute('data-type-only') !== value; });
        };
        radios.forEach(function (r) { r.addEventListener('change', update); });
        update();
    }
    panelSwitch('data-type-toggle', 'data-type-panel');
    panelSwitch('data-method-toggle', 'data-method-panel');

    /* ---------------------------------------------------------- images */
    var grid = $('[data-media-grid]');
    if (grid) {
        var input = $('[data-media-input]', grid);
        var drop = $('[data-media-drop]', grid);
        var pending = new DataTransfer();

        var relabel = function () {
            $$('.media-tile__badge', grid).forEach(function (b) { b.remove(); });
            var first = $('[data-media-tile]', grid);
            if (first) {
                var badge = document.createElement('span');
                badge.className = 'media-tile__badge';
                badge.textContent = 'Main';
                first.appendChild(badge);
            }
        };
        grid.addEventListener('click', function (e) {
            var tile = e.target.closest('[data-media-tile]');
            if (!tile) return;
            if (e.target.closest('[data-media-remove]')) { tile.remove(); relabel(); markDirty(); }
            if (e.target.closest('[data-media-first]')) { grid.insertBefore(tile, grid.firstChild); relabel(); markDirty(); }
        });

        // New files: preview tiles; the real upload happens with the form.
        var renderNew = function () {
            $$('.media-tile--new', grid).forEach(function (t) { t.remove(); });
            Array.prototype.forEach.call(pending.files, function (file, i) {
                var tile = document.createElement('div');
                tile.className = 'media-tile media-tile--new';
                var img = document.createElement('img');
                img.alt = '';
                img.src = URL.createObjectURL(file);
                tile.appendChild(img);
                var bar = document.createElement('div');
                bar.className = 'media-tile__bar';
                var rm = document.createElement('button');
                rm.type = 'button';
                rm.setAttribute('aria-label', 'Remove ' + file.name);
                rm.textContent = '×';
                rm.addEventListener('click', function () {
                    var next = new DataTransfer();
                    Array.prototype.forEach.call(pending.files, function (f, j) { if (j !== i) next.items.add(f); });
                    pending = next;
                    input.files = pending.files;
                    renderNew();
                });
                bar.appendChild(document.createElement('span'));
                bar.appendChild(rm);
                tile.appendChild(bar);
                grid.insertBefore(tile, drop);
            });
            relabel();
        };
        var addFiles = function (files) {
            Array.prototype.forEach.call(files, function (f) { if (/^image\//.test(f.type)) pending.items.add(f); });
            input.files = pending.files;
            renderNew();
            markDirty();
        };
        input.addEventListener('change', function () {
            var chosen = Array.prototype.slice.call(input.files);
            input.files = pending.files; // keep earlier picks
            addFiles(chosen);
        });
        ['dragenter', 'dragover'].forEach(function (t) { drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
        ['dragleave', 'drop'].forEach(function (t) { drop.addEventListener(t, function () { drop.classList.remove('is-over'); }); });
        drop.addEventListener('drop', function (e) { e.preventDefault(); addFiles(e.dataTransfer.files); });
    }

    // Single image inputs (logo/banner): show the chosen file name.
    $$('[data-single-preview]').forEach(function (inp) {
        inp.addEventListener('change', function () {
            var label = inp.closest('label');
            var span = label && $('span', label);
            if (span && inp.files[0]) span.textContent = inp.files[0].name;
        });
    });

    /* ---------------------------------------------------------- variations */
    var rows = $('[data-variant-rows]');
    if (rows) {
        var boxes = $$('input[name="attribute_values[]"]');
        var emptyNote = $('[data-variant-empty]');
        var stockOptions = [['instock', 'In stock'], ['outofstock', 'Out of stock'], ['onbackorder', 'Backorder']];

        var cell = function (tr, html) { var td = document.createElement('td'); if (html) td.appendChild(html); tr.appendChild(td); return td; };
        var field = function (type, name, value, label, extra) {
            var el = document.createElement('input');
            el.type = type; el.name = name; el.value = value || '';
            el.setAttribute('aria-label', label);
            Object.keys(extra || {}).forEach(function (k) { el.setAttribute(k, extra[k]); });
            return el;
        };
        var buildRow = function (key, label) {
            var tr = document.createElement('tr');
            tr.setAttribute('data-key', key);
            var base = 'variants[' + key + ']';
            var en = field('checkbox', base + '[enabled]', '1', 'Offer ' + label, { 'data-variant-enabled': '' });
            en.checked = true;
            cell(tr, en);
            var lab = cell(tr); lab.className = 'vlabel'; lab.textContent = label;
            cell(tr, field('text', base + '[price]', '', label + ' price', { inputmode: 'decimal', 'data-variant-price': '' }));
            cell(tr, field('text', base + '[sale_price]', '', label + ' sale price', { inputmode: 'decimal' }));
            cell(tr, field('text', base + '[sku]', '', label + ' SKU', { maxlength: '64' }));
            cell(tr, field('number', base + '[stock_qty]', '', label + ' quantity', { min: '0', max: '99999', placeholder: '—' }));
            var sel = document.createElement('select');
            sel.name = base + '[stock_status]';
            sel.setAttribute('aria-label', label + ' availability');
            stockOptions.forEach(function (o) { var opt = document.createElement('option'); opt.value = o[0]; opt.textContent = o[1]; sel.appendChild(opt); });
            cell(tr, sel);
            return tr;
        };
        var rebuild = function () {
            // attribute id -> [ [valueId, name], ... ] in page order
            var groups = {}, order = [];
            var useAttr = {};
            $$('[data-variation-attr]').forEach(function (c) { useAttr[c.value] = c.checked; });
            boxes.forEach(function (b) {
                if (!b.checked || !useAttr[b.getAttribute('data-attr')]) return;
                var a = +b.getAttribute('data-attr');
                if (!groups[a]) { groups[a] = []; order.push(a); }
                groups[a].push([+b.value, b.getAttribute('data-name')]);
            });
            order.sort(function (x, y) { return x - y; });
            var combos = [[]];
            order.forEach(function (a) {
                var next = [];
                combos.forEach(function (c) { groups[a].forEach(function (v) { next.push(c.concat([[a, v[0], v[1]]])); }); });
                combos = next;
            });
            if (!order.length) combos = [];
            var existing = {};
            $$('tr[data-key]', rows).forEach(function (tr) { existing[tr.getAttribute('data-key')] = tr; });
            rows.textContent = '';
            combos.forEach(function (c) {
                var key = c.map(function (p) { return p[0] + '-' + p[1]; }).join('_');
                var label = c.map(function (p) { return p[2]; }).join(' / ');
                rows.appendChild(existing[key] || buildRow(key, label));
            });
            if (emptyNote) emptyNote.hidden = combos.length > 0;
        };
        boxes.concat($$('[data-variation-attr]')).forEach(function (b) { b.addEventListener('change', rebuild); });
        rows.addEventListener('change', function (e) {
            if (e.target.matches('[data-variant-enabled]')) e.target.closest('tr').classList.toggle('is-off', !e.target.checked);
        });
        var bulkBtn = $('[data-bulk-apply]');
        if (bulkBtn) bulkBtn.addEventListener('click', function () {
            var v = $('#f-bulk').value.trim();
            if (!v) return;
            $$('[data-variant-price]', rows).forEach(function (inp) { inp.value = v; });
            markDirty();
        });
    }

    /* ---------------------------------------------------------- unsaved changes */
    var dirty = false;
    function markDirty() { dirty = true; }
    var productForm = $('[data-product-form]');
    if (productForm) {
        productForm.addEventListener('input', markDirty);
        productForm.addEventListener('submit', function () { dirty = false; });
        window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
    }
})();
