/* MadeForU storefront. Plain JS, no build step — upload to Hostinger as-is. */
(function () {
    'use strict';
    var $ = function (s, r) { return (r || document).querySelector(s); };
    var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
    var csrf = ($('meta[name="csrf-token"]') || {}).content;
    var inr = function (n) {
        n = Math.round(n * 100) / 100;
        var s = Math.floor(n).toString(), last3 = s.slice(-3), rest = s.slice(0, -3);
        if (rest) last3 = ',' + last3;
        var paise = Math.round((n - Math.floor(n)) * 100);
        return '₹' + rest.replace(/\B(?=(\d{2})+(?!\d))/g, ',') + last3 + (paise ? '.' + String(paise).padStart(2, '0') : '');
    };
    var toastTimer;
    function toast(html) {
        var t = $('.toast'); if (!t) return;
        t.innerHTML = html; t.hidden = false;
        clearTimeout(toastTimer); toastTimer = setTimeout(function () { t.hidden = true; }, 3500);
    }
    function track(e) { if (window.mfuTrack) window.mfuTrack(e); }

    /* Drawer */
    $$('[data-open]').forEach(function (b) {
        b.addEventListener('click', function () { var d = document.getElementById(b.dataset.open); d.classList.add('open'); d.setAttribute('aria-hidden', 'false'); document.body.style.overflow = 'hidden'; });
    });
    $$('[data-close]').forEach(function (b) {
        b.addEventListener('click', function () { var d = document.getElementById(b.dataset.close); d.classList.remove('open'); d.setAttribute('aria-hidden', 'true'); document.body.style.overflow = ''; });
    });

    /* Search suggestions */
    $$('[data-suggest]').forEach(function (form) {
        var input = $('input', form), box = $('.suggest', form), timer, ctrl, idx = -1;
        input.addEventListener('input', function () {
            clearTimeout(timer);
            var q = input.value.trim();
            if (q.length < 2) { box.hidden = true; return; }
            timer = setTimeout(function () {
                if (ctrl) ctrl.abort();
                ctrl = window.AbortController ? new AbortController() : null;
                fetch(form.dataset.suggest + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' }, signal: ctrl && ctrl.signal })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        var html = d.categories.map(function (c) { return '<a href="' + c.url + '" class="s-cat">in <b>' + esc(c.name) + '</b></a>'; }).join('')
                            + d.products.map(function (p) { return '<a href="' + p.url + '"><img src="' + p.image + '" alt="">' + esc(p.name) + '<b>' + p.price + '</b></a>'; }).join('');
                        box.innerHTML = html || '<a href="' + form.action + '?q=' + encodeURIComponent(q) + '">Search for “' + esc(q) + '”</a>';
                        box.hidden = false; idx = -1;
                    }).catch(function () {});
            }, 180);
        });
        input.addEventListener('keydown', function (e) {
            var items = $$('a', box); if (box.hidden || !items.length) return;
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault(); idx = (idx + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
                items.forEach(function (a, i) { a.classList.toggle('on', i === idx); });
            } else if (e.key === 'Enter' && idx >= 0) { e.preventDefault(); location.href = items[idx].href; }
            else if (e.key === 'Escape') box.hidden = true;
        });
        document.addEventListener('click', function (e) { if (!form.contains(e.target)) box.hidden = true; });
    });
    function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    /* Gallery: swipe on phones, thumbs on desktop */
    $$('[data-gallery]').forEach(function (g) {
        var track_ = $('[data-gallery-track]', g), dots = $$('.gallery-dots span', g), thumbs = $$('[data-goto]', g);
        function mark() {
            var i = Math.round(track_.scrollLeft / track_.clientWidth);
            dots.forEach(function (d, j) { d.classList.toggle('on', i === j); });
            thumbs.forEach(function (t, j) { t.classList.toggle('on', i === j); });
        }
        track_.addEventListener('scroll', function () { window.requestAnimationFrame(mark); }, { passive: true });
        thumbs.forEach(function (t) { t.addEventListener('click', function () { track_.scrollTo({ left: track_.clientWidth * +t.dataset.goto, behavior: 'smooth' }); }); });
        mark();
    });

    /* Banner carousel auto-advance */
    $$('[data-carousel]').forEach(function (c) {
        if (c.children.length < 2) return;
        setInterval(function () {
            if (document.hidden) return;
            var next = c.scrollLeft + c.clientWidth;
            c.scrollTo({ left: next >= c.scrollWidth - 4 ? 0 : next, behavior: 'smooth' });
        }, 5000);
    });

    /* Quantity steppers */
    $$('[data-qty]').forEach(function (q) {
        var input = $('input', q);
        $$('[data-step]', q).forEach(function (b) {
            b.addEventListener('click', function () {
                var v = Math.max(+input.min || 0, Math.min(+input.max || 99, (+input.value || 0) + +b.dataset.step));
                input.value = v; input.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });
    });
    var cartTimer;
    $$('[data-autosubmit]').forEach(function (i) {
        i.addEventListener('change', function () { clearTimeout(cartTimer); cartTimer = setTimeout(function () { i.form.submit(); }, 500); });
    });

    /* Character counters */
    $$('[data-count-chars]').forEach(function (el) {
        if (!el.maxLength || el.maxLength < 0) return;
        var c = document.createElement('small'); c.className = 'char-count'; el.after(c);
        var upd = function () { c.textContent = el.value.length + ' / ' + el.maxLength; };
        el.addEventListener('input', upd); upd();
    });

    /* Product page */
    var form = $('[data-buy-form]');
    if (form) productPage(form);

    function productPage(form) {
        var variants = JSON.parse(form.dataset.variants || '[]');
        var groups = $$('.opt-group', form);
        var variantInput = $('[data-variant-input]', form);
        var priceEls = $$('[data-price]'), regEl = $('[data-regular]'), saveEl = $('[data-save]');
        var base = null, baseRegular = null, chosenVariant = null;
        var firstPrice = priceEls[0] ? priceEls[0].textContent : '';

        function selection() {
            var sel = {};
            groups.forEach(function (g) { var c = $('input:checked', g); if (c) sel[g.dataset.attr] = c.value; });
            return sel;
        }
        function extras() {
            var e = 0;
            $$('[data-extra-select]', form).forEach(function (s) { var o = s.selectedOptions[0]; e += o ? +(o.dataset.price || 0) : 0; });
            return e;
        }
        function render() {
            if (base === null) { priceEls.forEach(function (p) { p.textContent = firstPrice; }); return; }
            var now = base + extras(), reg = baseRegular + extras();
            priceEls.forEach(function (p) { p.textContent = inr(now); });
            if (regEl) { regEl.textContent = inr(reg); regEl.hidden = !(reg > now); }
            if (saveEl) { saveEl.textContent = 'Save ' + Math.round(100 * (reg - now) / reg) + '%'; saveEl.hidden = !(reg > now); }
        }
        function matchVariant() {
            var sel = selection(), keys = Object.keys(sel);
            groups.forEach(function (g) { var c = $('input:checked', g); $('[data-attr-chosen]', g).textContent = c ? c.value : ''; });
            // Grey out values that no in-stock variant offers with the other choices made so far.
            groups.forEach(function (g) {
                $$('.opt', g).forEach(function (o) {
                    var val = $('input', o).value;
                    var ok = variants.some(function (v) {
                        if (!v.in_stock || v.options[g.dataset.attr] !== val) return false;
                        return keys.every(function (k) { return k === g.dataset.attr || v.options[k] === sel[k]; });
                    });
                    o.classList.toggle('na', !ok);
                });
            });
            if (keys.length < groups.length) { chosenVariant = null; variantInput.value = ''; base = null; render(); return; }
            chosenVariant = variants.find(function (v) { return keys.every(function (k) { return v.options[k] === sel[k]; }); }) || null;
            variantInput.value = chosenVariant ? chosenVariant.id : '';
            if (chosenVariant) {
                base = chosenVariant.price; baseRegular = chosenVariant.regular;
                if (chosenVariant.image) {
                    var first = $('.slide img'); if (first) { first.src = chosenVariant.image; var src = first.parentNode.querySelector('source'); if (src) src.remove(); }
                    var tr = $('[data-gallery-track]'); if (tr) tr.scrollTo({ left: 0, behavior: 'smooth' });
                }
            } else { base = null; }
            render();
        }
        if (groups.length) {
            groups.forEach(function (g) { $$('input', g).forEach(function (i) { i.addEventListener('change', matchVariant); }); });
            // Preselect from ?variant= (links in the Google feed) or when each attribute has a single value.
            var want = new URLSearchParams(location.search).get('variant');
            var pre = variants.find(function (v) { return String(v.id) === want; });
            if (pre) groups.forEach(function (g) { var i = $$('input', g).find(function (x) { return x.value === pre.options[g.dataset.attr]; }); if (i) i.checked = true; });
            groups.forEach(function (g) { var ins = $$('input', g); if (ins.length === 1) ins[0].checked = true; });
            matchVariant();
        } else {
            var m = (firstPrice || '').replace(/[^\d.]/g, '');
            base = +m; baseRegular = regEl && !regEl.hidden ? +regEl.textContent.replace(/[^\d.]/g, '') : base;
        }
        $$('[data-extra-select]', form).forEach(function (s) { s.addEventListener('change', render); });

        $$('[data-upload]', form).forEach(function (u) { uploader(u, form.dataset.uploadUrl); });

        var errBox = $('[data-form-error]', form);
        function showError(msg) { errBox.textContent = msg; errBox.hidden = false; errBox.scrollIntoView({ behavior: 'smooth', block: 'center' }); }

        form.addEventListener('submit', function (e) {
            errBox.hidden = true;
            if (groups.length && !variantInput.value) { e.preventDefault(); showError('Please choose ' + groups.map(function (g) { return g.dataset.attr.toLowerCase(); }).join(' and ') + '.'); return; }
            if ($$('[data-upload]', form).some(function (u) { return u.dataset.busy === '1'; })) { e.preventDefault(); showError('Please wait — your photos are still uploading.'); return; }
            var missing = $$('[data-upload]', form).find(function (u) { return u.dataset.required === '1' && $$('input[type=hidden]', u).length < +u.dataset.min; });
            if (missing) { e.preventDefault(); showError('Please upload ' + missing.dataset.min + ' photo' + (+missing.dataset.min > 1 ? 's' : '') + ' to personalise this gift.'); missing.scrollIntoView({ behavior: 'smooth', block: 'center' }); return; }
            if (e.submitter && e.submitter.name === 'buy_now') return; // straight to checkout
            if (!window.fetch) return;
            e.preventDefault();
            var btn = $('[data-add]', form); btn.disabled = true;
            fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf } })
                .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
                .then(function (res) {
                    btn.disabled = false;
                    if (!res.ok) { var errs = res.d.errors ? Object.values(res.d.errors).map(function (x) { return x[0]; }) : [res.d.message]; showError(errs.join(' ')); return; }
                    $$('[data-cart-count]').forEach(function (b) { b.textContent = res.d.count; b.hidden = false; });
                    toast(esc(res.d.message) + ' <a href="/cart">View cart</a>');
                    track(res.d.event);
                    $$('[data-upload]', form).forEach(function (u) { u.reset && u.reset(); });
                })
                .catch(function () { btn.disabled = false; form.submit(); });
        });

        // Sticky bar on phones once the buy form scrolls away
        var bar = $('[data-sticky-buy]');
        if (bar && 'IntersectionObserver' in window) {
            new IntersectionObserver(function (es) { bar.classList.toggle('show', !es[0].isIntersecting && es[0].boundingClientRect.top < 0); }).observe($('.buy-row', form));
            $('[data-scroll-buy]', bar).addEventListener('click', function () { (form.querySelector('.personalise') || form).scrollIntoView({ behavior: 'smooth', block: 'start' }); });
        }

        var wl = $('[data-wishlist]');
        if (wl) wl.addEventListener('submit', function (e) {
            e.preventDefault();
            fetch(wl.action, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf } }).then(function (r) { return r.json(); }).then(function (d) {
                $('button', wl).textContent = d.added ? '♥ Saved' : '♡ Save to wishlist';
                if (d.added) track({ event: 'add_to_wishlist', ecommerce: {} });
            });
        });
    }

    /* Photo uploader: N slots, each holds one uploaded photo; picking several files fills the next empty slots. */
    function uploader(u, url) {
        var slots = $$('[data-slot]', u), countEl = $('[data-count]', u), name = u.dataset.name, max = +u.dataset.max;
        function update() {
            var n = $$('input[type=hidden]', u).length;
            countEl.textContent = n; u.classList.toggle('done', n >= +u.dataset.min);
            u.dataset.busy = $$('.slot.busy', u).length ? '1' : '0';
        }
        function empty() { return slots.filter(function (s) { return !s.classList.contains('filled') && !s.classList.contains('busy'); }); }
        function clear(slot) {
            slot.classList.remove('filled', 'busy');
            $$('img, .rm, .warn, .noprev, .bar, input[type=hidden]', slot).forEach(function (x) { x.remove(); });
            update();
        }
        u.reset = function () { slots.forEach(clear); };
        slots.forEach(function (slot) {
            var input = $('input[type=file]', slot);
            input.addEventListener('change', function () {
                var files = Array.prototype.slice.call(input.files), free = empty();
                if (!slot.classList.contains('filled') && free.indexOf(slot) > 0) { free.splice(free.indexOf(slot), 1); free.unshift(slot); }
                if (files.length > free.length) toast('Only ' + max + ' photo' + (max > 1 ? 's' : '') + ' for this one — extra photos were skipped.');
                files.slice(0, free.length).forEach(function (f, i) { send(free[i], f); });
                input.value = '';
            });
            slot.addEventListener('click', function (e) { if (slot.classList.contains('filled') || slot.classList.contains('busy')) e.preventDefault(); });
        });
        function send(slot, file) {
            slot.classList.add('busy');
            var bar = document.createElement('span'); bar.className = 'bar'; bar.style.width = '0'; slot.appendChild(bar);
            if (/^image\/(jpe?g|png|webp|gif)$/i.test(file.type)) {
                var img = new Image(); img.src = URL.createObjectURL(file); img.alt = 'Your photo'; slot.appendChild(img);
            }
            update();
            var fd = new FormData(); fd.append('file', file);
            var xhr = new XMLHttpRequest();
            xhr.open('POST', url); xhr.setRequestHeader('X-CSRF-TOKEN', csrf); xhr.setRequestHeader('Accept', 'application/json');
            xhr.upload.onprogress = function (e) { if (e.lengthComputable) bar.style.width = (100 * e.loaded / e.total) + '%'; };
            xhr.onload = function () {
                var d = {}; try { d = JSON.parse(xhr.responseText); } catch (x) {}
                bar.remove(); slot.classList.remove('busy');
                if (xhr.status !== 200) { clear(slot); toast(esc((d.errors && d.errors.file && d.errors.file[0]) || d.message || 'Upload failed. Please try again.')); return; }
                slot.classList.add('filled');
                if (!$('img', slot)) { var im = new Image(); im.alt = 'Your photo'; im.onerror = function () { im.remove(); var t = document.createElement('span'); t.className = 'noprev'; t.textContent = d.name; slot.appendChild(t); }; im.src = d.thumb; slot.appendChild(im); }
                var h = document.createElement('input'); h.type = 'hidden'; h.name = name; h.value = d.token; slot.appendChild(h);
                var rm = document.createElement('button'); rm.type = 'button'; rm.className = 'rm'; rm.setAttribute('aria-label', 'Remove photo'); rm.textContent = '×';
                rm.onclick = function (e) { e.preventDefault(); e.stopPropagation(); fetch(url + '/' + d.token, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' } }); clear(slot); };
                slot.appendChild(rm);
                if (d.low_res) { var w = document.createElement('span'); w.className = 'warn'; w.textContent = 'Low quality'; w.title = 'This photo is small and may print blurry'; slot.appendChild(w); }
                update();
            };
            xhr.onerror = function () { clear(slot); toast('Upload failed — check your connection and try again.'); };
            xhr.send(fd);
        }
        update();
    }

    /* Checkout */
    var co = $('[data-checkout]');
    if (co) {
        var tot = $('[data-place-total]', co);
        $$('input[name=payment_method]', co).forEach(function (r) { r.addEventListener('change', function () { if (r.checked) tot.textContent = r.dataset.total; }); });
        var pin = $('[data-pincode]', co);
        pin.addEventListener('input', function () {
            if (!/^[1-9]\d{5}$/.test(pin.value)) return;
            fetch('https://api.postalpincode.in/pincode/' + pin.value).then(function (r) { return r.json(); }).then(function (d) {
                var po = d && d[0] && d[0].PostOffice && d[0].PostOffice[0]; if (!po) return;
                var city = $('[data-city]', co), st = $('[data-state]', co);
                if (!city.value) city.value = po.District;
                var opt = $$('option', st).find(function (o) { return o.value.toLowerCase() === po.State.toLowerCase(); }); if (opt) st.value = opt.value;
            }).catch(function () {});
        });
        co.addEventListener('submit', function () { var b = $('[data-place]', co); setTimeout(function () { b.disabled = true; b.textContent = 'Placing order…'; }, 0); });
    }

    /* Installable app */
    if ('serviceWorker' in navigator && location.protocol === 'https:') {
        window.addEventListener('load', function () { navigator.serviceWorker.register('/sw.js').catch(function () {}); });
    }
})();
