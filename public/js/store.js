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
    /* "Added to cart" card: photo, short name, price, View cart / Checkout. Closes itself after a few seconds. */
    var addedTimer;
    function addedCard(a, count) {
        if (!a) return;
        var box = $('[data-added]');
        if (!box) { box = document.createElement('div'); box.className = 'added-card'; box.setAttribute('data-added', ''); box.setAttribute('role', 'status'); document.body.appendChild(box); }
        box.innerHTML = '<button type="button" class="ac-close" aria-label="Close">×</button>'
            + '<p class="ac-title"><span class="ac-tick">✓</span> Added to cart</p>'
            + '<div class="ac-item"><img alt="" width="56" height="56"><div><b class="ac-name"></b><small class="ac-meta"></small></div></div>'
            + '<div class="ac-actions"><a class="btn ghost small" data-view>View cart (' + count + ')</a><a class="btn primary small" data-go>Checkout</a></div>';
        $('img', box).src = a.image; $('.ac-name', box).textContent = a.name;
        $('.ac-meta', box).textContent = 'Qty ' + a.qty + ' · ' + a.price;
        $('[data-view]', box).href = a.cart; $('[data-go]', box).href = a.checkout;
        $('.ac-close', box).addEventListener('click', function () { box.classList.remove('show'); });
        requestAnimationFrame(function () { box.classList.add('show'); });
        clearTimeout(addedTimer);
        addedTimer = setTimeout(function () { box.classList.remove('show'); }, 6000);
        box.onmouseenter = function () { clearTimeout(addedTimer); };
    }

    /* Drawer: the page behind stays still (iOS ignores overflow:hidden on body, so pin it), the list scrolls inside */
    var lockY = 0;
    function lock(on) {
        var html = document.documentElement;
        if (on) { lockY = window.scrollY; document.body.style.top = -lockY + 'px'; html.classList.add('locked'); }
        else { html.classList.remove('locked'); document.body.style.top = ''; window.scrollTo(0, lockY); }
    }
    function drawer(id, open) {
        var d = document.getElementById(id); if (!d || d.classList.contains('open') === open) return;
        d.classList.toggle('open', open); d.setAttribute('aria-hidden', open ? 'false' : 'true');
        $$('[data-open="' + id + '"]').forEach(function (b) { b.setAttribute('aria-expanded', open ? 'true' : 'false'); });
        lock(open);
        if (open) { var f = $('.drawer-head .icon-btn', d); if (f) f.focus(); }
    }
    $$('[data-open]').forEach(function (b) { b.addEventListener('click', function () { drawer(b.dataset.open, true); }); });
    $$('[data-close]').forEach(function (b) { b.addEventListener('click', function () { drawer(b.dataset.close, false); }); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') $$('.drawer.open').forEach(function (d) { drawer(d.id, false); }); });
    // Swipe the drawer closed
    $$('.drawer-panel').forEach(function (p) {
        var x0 = null;
        p.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
        p.addEventListener('touchend', function (e) { if (x0 !== null && x0 - e.changedTouches[0].clientX > 70) drawer(p.closest('.drawer').id, false); x0 = null; });
    });

    /* Category strip in the header: bring the current category into view */
    $$('[data-scroll-active]').forEach(function (t) {
        var on = $('a.on', t); if (on && t.scrollWidth > t.clientWidth) t.scrollLeft = on.offsetLeft - 16;
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
        var track_ = $('[data-gallery-track]', g), thumbs = $$('[data-goto]', g), slides = $$('.slide', g);
        function index() { return Math.round(track_.scrollLeft / track_.clientWidth); }
        function go(i) { i = Math.max(0, Math.min(slides.length - 1, i)); track_.scrollTo({ left: track_.clientWidth * i, behavior: 'smooth' }); }
        function mark() {
            var i = index();
            thumbs.forEach(function (t, j) {
                t.classList.toggle('on', i === j); t.setAttribute('aria-selected', i === j ? 'true' : 'false');
                if (i === j && t.parentNode.scrollWidth > t.parentNode.clientWidth) t.parentNode.scrollTo({ left: t.offsetLeft - 40, behavior: 'smooth' });
            });
            $$('[data-gal-step]', g).forEach(function (b) { b.disabled = (+b.dataset.galStep < 0 && i === 0) || (+b.dataset.galStep > 0 && i === slides.length - 1); });
            var c = $('[data-gallery-count]', g); if (c) c.textContent = (i + 1) + ' / ' + slides.length;
        }
        track_.addEventListener('scroll', function () { window.requestAnimationFrame(mark); }, { passive: true });
        thumbs.forEach(function (t) { t.addEventListener('click', function () { go(+t.dataset.goto); }); });
        $$('[data-gal-step]', g).forEach(function (b) { b.addEventListener('click', function () { go(index() + +b.dataset.galStep); }); });
        // Desktop: hover magnifies the photo under the mouse.
        if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
            slides.forEach(function (s) {
                var img = $('img', s); if (!img || !s.hasAttribute('data-zoom')) return;
                s.addEventListener('mousemove', function (e) {
                    var r = s.getBoundingClientRect();
                    img.style.transformOrigin = ((e.clientX - r.left) / r.width * 100) + '% ' + ((e.clientY - r.top) / r.height * 100) + '%';
                    s.classList.add('zooming');
                });
                s.addEventListener('mouseleave', function () { s.classList.remove('zooming'); });
            });
        }
        // Tap / click: full-screen viewer with swipe, arrows and keyboard.
        function openViewer(start) {
            var urls = slides.map(function (s) { return s.dataset.full; }).filter(Boolean);
            if (!urls.length) return;
            var lb = document.createElement('div');
            lb.className = 'lightbox'; lb.setAttribute('role', 'dialog'); lb.setAttribute('aria-label', 'Product images');
            lb.innerHTML = '<div class="lb-track"></div><button type="button" class="lb-close" aria-label="Close">×</button>'
                + (urls.length > 1 ? '<button type="button" class="lb-arrow prev" aria-label="Previous">‹</button><button type="button" class="lb-arrow next" aria-label="Next">›</button>' : '')
                + '<span class="lb-count"></span>';
            var tr = $('.lb-track', lb), cnt = $('.lb-count', lb);
            urls.forEach(function (u) { var f = document.createElement('figure'); var im = new Image(); im.src = u; im.alt = ''; f.appendChild(im); tr.appendChild(f); });
            function at() { return Math.round(tr.scrollLeft / tr.clientWidth); }
            function show(i, smooth) { tr.scrollTo({ left: tr.clientWidth * Math.max(0, Math.min(urls.length - 1, i)), behavior: smooth ? 'smooth' : 'auto' }); }
            function count() { cnt.textContent = urls.length > 1 ? (at() + 1) + ' / ' + urls.length : ''; }
            function close() { document.removeEventListener('keydown', key); document.documentElement.classList.remove('lb-open'); lb.remove(); go(at()); }
            function key(e) { if (e.key === 'Escape') close(); if (e.key === 'ArrowRight') show(at() + 1, true); if (e.key === 'ArrowLeft') show(at() - 1, true); }
            tr.addEventListener('scroll', function () { window.requestAnimationFrame(count); }, { passive: true });
            $('.lb-close', lb).onclick = close;
            $$('.lb-arrow', lb).forEach(function (b) { b.onclick = function () { show(at() + (b.classList.contains('next') ? 1 : -1), true); }; });
            tr.addEventListener('click', function (e) { if (e.target === tr || e.target.tagName === 'FIGURE') close(); });
            document.addEventListener('keydown', key);
            document.documentElement.classList.add('lb-open');
            document.body.appendChild(lb);
            show(start, false); count();
            $('.lb-close', lb).focus();
        }
        slides.forEach(function (s, i) { if (s.dataset.full) s.addEventListener('click', function () { openViewer(i); }); });
        var openBtn = $('[data-gal-open]', g); if (openBtn) openBtn.addEventListener('click', function () { openViewer(index()); });
        mark();
    });

    /* Customer photos in the cart and checkout: tap to see them large */
    document.addEventListener('click', function (e) {
        var a = e.target.closest && e.target.closest('[data-photo-view]');
        if (!a) return;
        e.preventDefault();
        var box = document.createElement('div');
        box.className = 'photo-view';
        box.innerHTML = '<img alt=""><p></p><button type="button" aria-label="Close">×</button>';
        $('img', box).src = a.href; $('p', box).textContent = a.title || '';
        box.addEventListener('click', function () { box.remove(); });
        document.body.appendChild(box);
    });

    /* Top category menu on desktop: one line only; whatever doesn't fit moves into "More". */
    (function () {
        var more = $('[data-nav-more]');
        if (!more) return;
        var track = more.parentNode, list = $('[data-nav-more-list]', more), btn = $('button', more);
        var desk = window.matchMedia('(min-width: 960px)');
        function items() { return Array.prototype.filter.call(track.children, function (c) { return c !== more; }); }
        function used() {
            var gap = parseFloat(getComputedStyle(track).columnGap) || 0, w = 0, kids = Array.prototype.filter.call(track.children, function (c) { return !c.hidden; });
            kids.forEach(function (c) { w += c.offsetWidth; });
            return w + gap * Math.max(0, kids.length - 1);
        }
        function fit() {
            // Put everything back, then move items from the end into "More" until the line fits.
            Array.prototype.slice.call(list.children).forEach(function (c) { track.insertBefore(c, more); });
            more.hidden = true;
            if (!desk.matches) return;
            var room = track.clientWidth - parseFloat(getComputedStyle(track).paddingLeft) - parseFloat(getComputedStyle(track).paddingRight);
            if (used() <= room) return;
            more.hidden = false;
            var all = items();
            for (var i = all.length - 1; i > 0 && used() > room; i--) list.insertBefore(all[i], list.firstChild);
            btn.classList.toggle('on', !!$('a.on', list));
        }
        var t;
        window.addEventListener('resize', function () { clearTimeout(t); t = setTimeout(fit, 120); });
        if (document.fonts && document.fonts.ready) document.fonts.ready.then(fit);
        fit();
    })();

    /* Sideways rows with arrow buttons (Shop by category) */
    $$('[data-hscroll]').forEach(function (box) {
        var row = $('[data-hscroll-row]', box), prev = $('[data-hscroll-prev]', box), next = $('[data-hscroll-next]', box);
        function update() {
            prev.disabled = row.scrollLeft < 4;
            next.disabled = row.scrollLeft + row.clientWidth >= row.scrollWidth - 4;
            box.classList.toggle('scrollable', row.scrollWidth > row.clientWidth + 4);
        }
        prev.addEventListener('click', function () { row.scrollBy({ left: -row.clientWidth * 0.8, behavior: 'smooth' }); });
        next.addEventListener('click', function () { row.scrollBy({ left: row.clientWidth * 0.8, behavior: 'smooth' }); });
        row.addEventListener('scroll', function () { window.requestAnimationFrame(update); }, { passive: true });
        window.addEventListener('resize', update);
        update();
    });

    /* Home slider: swipe (native scroll-snap), autoplay with a progress dot, arrows on desktop, pauses on touch/hover/hidden tab */
    $$('[data-slider]').forEach(function (sl) {
        var track = $('[data-slider-track]', sl), slides = $$('.hs-slide', sl), dots = $$('[data-slider-dot]', sl);
        var ms = 5000, timer = null, cur = 0, paused = false;
        sl.style.setProperty('--slide-ms', ms + 'ms');
        if (slides.length < 2) return;
        var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
        function go(i, smooth) {
            cur = (i + slides.length) % slides.length;
            track.scrollTo({ left: track.clientWidth * cur, behavior: smooth === false || reduce ? 'auto' : 'smooth' });
            mark();
        }
        function mark() {
            dots.forEach(function (d, j) {
                var on = j === cur;
                if (on && d.classList.contains('on')) return;
                d.classList.toggle('on', on); d.setAttribute('aria-selected', on ? 'true' : 'false');
                if (on) { var s = d.firstElementChild; s.style.animation = 'none'; void s.offsetWidth; s.style.animation = ''; }
            });
            sl.classList.toggle('light-dots', slides[cur].classList.contains('hs-offer'));
        }
        function play() { stop(); if (!paused && !reduce) timer = setTimeout(function () { go(cur + 1); play(); }, ms); }
        function stop() { clearTimeout(timer); }
        function pause(p) { paused = p; sl.classList.toggle('paused', p); p ? stop() : play(); }
        var scrollT;
        track.addEventListener('scroll', function () {
            clearTimeout(scrollT);
            scrollT = setTimeout(function () {
                var i = Math.round(track.scrollLeft / track.clientWidth);
                if (i !== cur) { cur = i; mark(); play(); }
            }, 80);
        }, { passive: true });
        dots.forEach(function (d) { d.addEventListener('click', function () { go(+d.dataset.sliderDot); play(); }); });
        var prev = $('[data-slider-prev]', sl), next = $('[data-slider-next]', sl);
        if (prev) prev.addEventListener('click', function () { go(cur - 1); play(); });
        if (next) next.addEventListener('click', function () { go(cur + 1); play(); });
        sl.addEventListener('mouseenter', function () { pause(true); });
        sl.addEventListener('mouseleave', function () { pause(false); });
        sl.addEventListener('touchstart', function () { pause(true); }, { passive: true });
        sl.addEventListener('touchend', function () { setTimeout(function () { pause(false); }, 1500); }, { passive: true });
        sl.addEventListener('focusin', function () { pause(true); });
        sl.addEventListener('focusout', function () { pause(false); });
        document.addEventListener('visibilitychange', function () { document.hidden ? stop() : play(); });
        window.addEventListener('resize', function () { go(cur, false); });
        mark(); play();
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
        var priceEls = $$('[data-price]').filter(function (el) { return !el.matches('option, input'); }), regEl = $('[data-regular]'), saveEl = $('[data-save]');
        var base = null, baseRegular = null, chosenVariant = null;
        var firstPrice = priceEls[0] ? priceEls[0].textContent : '';

        function selection() {
            var sel = {};
            groups.forEach(function (g) { var c = $('input:checked', g); if (c) sel[g.dataset.attr] = c.value; });
            return sel;
        }
        // Quantity and per-item personalisation: with "Different for each", item 2, 3… get their own box.
        var qtyInput = $('input[name=qty]', form), eachEl = $('[data-each]');
        var choice = $('[data-each-choice]', form), tpl = $('[data-item-template]', form), blocksBox = $('[data-item-blocks]', form);
        function qty() { return Math.max(1, Math.min(99, +qtyInput.value || 1)); }
        function eachMode() { return !!choice && qty() > 1 && $('input[name=custom_each]:checked', form).value === '1'; }
        function liveBlocks() { return $$('[data-item-block]', form).filter(function (b) { return !b.hidden; }); }
        function syncBlocks() {
            if (!choice) return;
            var n = qty(), each = eachMode();
            choice.hidden = n < 2;
            $$('[data-qty-label]', form).forEach(function (l) { l.textContent = n; });
            var tag = $('[data-item-tag]', form); if (tag) tag.hidden = !each;
            for (var i = blocksBox.children.length + 1; each && i < n; i++) {
                var div = document.createElement('div');
                div.innerHTML = tpl.innerHTML.replace(/__N__/g, i).replace(/__NUM__/g, i + 1);
                var block = div.firstElementChild;
                blocksBox.appendChild(block);
                $$('[data-upload]', block).forEach(function (u) { uploader(u, form.dataset.uploadUrl); });
                $$('[data-extra-select]', block).forEach(function (s) { s.addEventListener('change', render); });
            }
            // Extra boxes are hidden and switched off (not sent) rather than deleted, so photos survive a change of mind.
            Array.prototype.forEach.call(blocksBox.children, function (b, k) {
                var on = each && k + 1 < n;
                b.hidden = !on;
                $$('input, select, textarea', b).forEach(function (x) { x.disabled = !on; });
            });
        }
        function extras(block) {
            var e = 0;
            $$('[data-extra-select]', block).forEach(function (s) { var o = s.selectedOptions[0]; e += o ? +(o.dataset.price || 0) : 0; });
            $$('[data-extra-choice]:checked', block).forEach(function (r) { e += +(r.dataset.price || 0); });
            return e;
        }
        function render() {
            if (base === null) { priceEls.forEach(function (p) { p.textContent = firstPrice; }); if (eachEl) eachEl.hidden = true; return; }
            var n = qty(), blocks = liveBlocks(), now = 0, reg = 0;
            if (eachMode()) {
                blocks.forEach(function (b) { now += base + extras(b); reg += baseRegular + extras(b); });
            } else {
                var e = blocks.length ? extras(blocks[0]) : 0;
                now = (base + e) * n; reg = (baseRegular + e) * n;
            }
            priceEls.forEach(function (p) { p.textContent = inr(now); });
            if (eachEl) { eachEl.hidden = n < 2; eachEl.textContent = inr(now / n) + ' each × ' + n; }
            if (regEl) { regEl.textContent = inr(reg); regEl.hidden = !(reg > now); }
            if (saveEl) { saveEl.textContent = 'You save ' + inr(reg - now) + ' (' + Math.round(100 * (reg - now) / reg) + '% off)'; saveEl.hidden = !(reg > now); }
        }
        if (qtyInput) {
            qtyInput.addEventListener('change', function () { syncBlocks(); render(); });
            qtyInput.addEventListener('input', function () { syncBlocks(); render(); });
        }
        $$('input[name=custom_each]', form).forEach(function (r) { r.addEventListener('change', function () { syncBlocks(); render(); }); });
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
            var note = $('[data-stock-note]');
            if (note) {
                var left = chosenVariant ? chosenVariant.stock : null;
                note.hidden = !(left !== null && left !== undefined && left > 0 && left <= +note.dataset.low);
                if (!note.hidden) note.textContent = '🔥 Only ' + left + ' left — order soon';
            }
            var addBtn = $('[data-add]', form);
            if (addBtn && chosenVariant) { addBtn.disabled = !chosenVariant.in_stock; addBtn.textContent = chosenVariant.in_stock ? 'Add to cart' : 'Out of stock'; }
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
        // Options that set the photo count (Print side: One side → 1 photo, Two sides → 2), per item box.
        function photoRule(block) {
            var u = $('[data-photo-target]', block);
            if (!u || !u.limit) return;
            var n = 0;
            $$('[data-extra-select]', block).forEach(function (s) { var o = s.selectedOptions[0]; if (o && o.dataset.photos) n = +o.dataset.photos; });
            $$('[data-extra-choice]:checked', block).forEach(function (r) { if (r.dataset.photos) n = +r.dataset.photos; });
            u.limit(n);
        }
        form.addEventListener('change', function (e) {
            if (!e.target.matches('[data-extra-select], [data-extra-choice]')) return;
            var block = e.target.closest('[data-item-block]') || form;
            photoRule(block);
        });
        // Live preview: the chosen photos laid over the product picture (one box per item).
        function livePreview(block) {
            var lp = $('[data-live-preview]', block);
            if (!lp) return;
            var u = $('[data-photo-target]', block) || $('[data-upload]', block);
            var pics = u ? $$('[data-slot]', u).filter(function (s) { return !s.hidden; }).map(function (s) { var im = $('img', s); return im && im.src ? im.src : null; }).filter(Boolean) : [];
            var img = $('[data-lp-img]', lp), empty = $('[data-lp-empty]', lp), sw = $('[data-lp-switch]', lp);
            var pick = Math.min(+(lp.dataset.pick || 0), Math.max(0, pics.length - 1));
            img.hidden = !pics.length; empty.hidden = !!pics.length;
            if (pics.length) img.src = pics[pick];
            sw.hidden = pics.length < 2;
            sw.innerHTML = '';
            pics.forEach(function (src, i) {
                var b = document.createElement('button'); b.type = 'button'; b.className = i === pick ? 'on' : '';
                b.setAttribute('aria-label', 'Preview photo ' + (i + 1));
                var t = new Image(); t.src = src; t.alt = ''; b.appendChild(t);
                b.onclick = function () { lp.dataset.pick = i; livePreview(block); };
                sw.appendChild(b);
            });
        }
        form.addEventListener('mfu:photos', function (e) { livePreview(e.target.closest('[data-item-block]') || form); });

        // Picture choices (shape, style…): reprice and show the chosen name, in every item box.
        form.addEventListener('change', function (e) {
            if (!e.target.matches('[data-extra-choice]')) return;
            var field = e.target.closest('.field'), pick = field && $('[data-choice-picked]', field);
            if (pick) pick.textContent = '· ' + e.target.value;
            render();
        });
        syncBlocks(); render();

        $$('[data-upload]', form).forEach(function (u) { uploader(u, form.dataset.uploadUrl); });
        $$('[data-item-block]', form).forEach(photoRule);

        var errBox = $('[data-form-error]', form);
        function showError(msg) { errBox.textContent = msg; errBox.hidden = false; errBox.scrollIntoView({ behavior: 'smooth', block: 'center' }); }

        form.addEventListener('submit', function (e) {
            errBox.hidden = true;
            if (groups.length && !variantInput.value) { e.preventDefault(); showError('Please choose ' + groups.map(function (g) { return g.dataset.attr.toLowerCase(); }).join(' and ') + '.'); return; }
            var uploads = liveBlocks().reduce(function (all, b) { return all.concat($$('[data-upload]', b)); }, []);
            if (uploads.some(function (u) { return u.dataset.busy === '1'; })) { e.preventDefault(); showError('Please wait — your photos are still uploading.'); return; }
            var missing = uploads.find(function (u) { return u.dataset.required === '1' && $$('input[type=hidden]', u).length < +u.dataset.min; });
            if (missing) {
                e.preventDefault();
                var item = eachMode() ? ' for item ' + (+missing.closest('[data-item-block]').dataset.itemBlock + 1) : '';
                showError('Please upload ' + missing.dataset.min + ' photo' + (+missing.dataset.min > 1 ? 's' : '') + item + ' to personalise this gift.');
                missing.scrollIntoView({ behavior: 'smooth', block: 'center' }); return;
            }
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
                    addedCard(res.d.added, res.d.count);
                    track(res.d.event);
                    $$('[data-upload]', form).forEach(function (u) { u.reset && u.reset(); });
                    blocksBox && (blocksBox.innerHTML = '');
                    if (qtyInput) { qtyInput.value = 1; syncBlocks(); render(); }
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
        var slots = $$('[data-slot]', u), countEl = $('[data-count]', u), name = u.dataset.name;
        function update() {
            u.dispatchEvent(new CustomEvent('mfu:photos', { bubbles: true }));
            var n = $$('input[type=hidden]', u).length;
            countEl.textContent = n; u.classList.toggle('done', n >= +u.dataset.min);
            u.dataset.busy = $$('.slot.busy', u).length ? '1' : '0';
        }
        function empty() { return slots.filter(function (s) { return !s.hidden && !s.classList.contains('filled') && !s.classList.contains('busy'); }); }
        function clear(slot) {
            slot.classList.remove('filled', 'busy');
            $$('img, .rm, .warn, .noprev, .bar, input[type=hidden]', slot).forEach(function (x) { x.remove(); });
            update();
        }
        u.reset = function () { slots.forEach(clear); };
        // An option such as "Two sides" sets how many photos this field takes: show that many boxes, drop extra photos.
        var lbl = $('[data-upload-label]', u), lblText = lbl ? lbl.firstChild.textContent : '';
        u.limit = function (n) {
            var fromOption = n > 0;
            // Until the customer picks e.g. a print side, we don't know how many photos: show a hint instead of boxes.
            var waiting = !fromOption && u.hasAttribute('data-photo-wait');
            u.classList.toggle('waiting', waiting);
            n = fromOption ? n : +u.dataset.baseMax;
            if (lbl) lbl.firstChild.textContent = n === 1 ? lblText.replace(/\bphotos\b/i, 'photo') : lblText;
            u.dataset.max = n; u.dataset.min = fromOption ? n : u.dataset.baseMin;
            slots.forEach(function (s, i) { if (i >= n && (s.classList.contains('filled') || s.classList.contains('busy'))) clear(s); s.hidden = i >= n; });
            var ml = $('[data-max-label]', u); if (ml) ml.textContent = n;
            update();
        };
        slots.forEach(function (slot) {
            var input = $('input[type=file]', slot);
            input.addEventListener('change', function () {
                var files = Array.prototype.slice.call(input.files), free = empty();
                if (!slot.classList.contains('filled') && free.indexOf(slot) > 0) { free.splice(free.indexOf(slot), 1); free.unshift(slot); }
                var max = +u.dataset.max;
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

    /* Delivery PIN check (product page) — remembers the PIN for next time */
    function checkPin(url, pin, days) {
        return fetch(url + (url.indexOf('?') < 0 ? '?' : '&') + 'pin=' + encodeURIComponent(pin) + (days ? '&days=' + days : ''), { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); });
    }
    $$('[data-pin-check]').forEach(function (f) {
        var input = $('input', f), out = $('[data-pin-result]', f);
        try { var saved = localStorage.getItem('mfu.pin'); if (saved) input.value = saved; } catch (e) {}
        function run() {
            var pin = input.value.replace(/\D/g, '');
            if (!/^[1-9]\d{5}$/.test(pin)) { out.className = 'small pin-no'; out.textContent = 'Please enter a valid 6-digit PIN code.'; return; }
            out.className = 'small muted'; out.textContent = 'Checking…';
            checkPin(f.dataset.pinCheck, pin, f.dataset.days).then(function (d) {
                out.className = 'small ' + (d.ok ? 'pin-ok' : 'pin-no'); out.textContent = (d.ok ? '✓ ' : '') + d.message;
                try { localStorage.setItem('mfu.pin', pin); } catch (e) {}
            }).catch(function () { out.textContent = ''; });
        }
        f.addEventListener('submit', run);
        if (input.value) run();
    });

    /* Checkout */
    var co = $('[data-checkout]');
    if (co) {
        var tot = $('[data-place-total]', co);
        function showTotals(method) {
            $$('[data-totals]', co).forEach(function (t) { t.hidden = (t.dataset.totals === 'cod') !== (method === 'cod'); });
        }
        $$('input[name=payment_method]', co).forEach(function (r) { r.addEventListener('change', function () { if (r.checked) { tot.textContent = r.dataset.total; showTotals(r.value); } }); });
        // PIN: ask the courier as soon as six digits are typed; switch COD off where cash can't be collected.
        var pinIn = $('[data-pincode]', co), pinMsg = $('[data-pin-msg]', co);
        function pinCheck() {
            var pin = pinIn.value.replace(/\D/g, ''), cod = $('input[name=payment_method][value=cod]', co);
            if (!/^[1-9]\d{5}$/.test(pin) || !pinIn.dataset.check) { pinMsg.textContent = ''; return; }
            checkPin(pinIn.dataset.check, pin).then(function (d) {
                pinMsg.className = 'pin-msg ' + (d.ok ? 'pin-ok' : 'pin-no'); pinMsg.textContent = d.message;
                if (cod) {
                    cod.disabled = !d.cod; cod.closest('.pay-opt').style.opacity = d.cod ? '' : '.5';
                    if (!d.cod && cod.checked) { var other = $('input[name=payment_method]:not([value=cod])', co); if (other) { other.checked = true; other.dispatchEvent(new Event('change')); } }
                }
            }).catch(function () {});
        }
        pinIn.addEventListener('input', pinCheck);
        // Live delivery charges: re-price both payment options for the PIN.
        var lastQuote = '';
        function quote() {
            var pin = pinIn.value.replace(/\D/g, '');
            if (!co.dataset.quote || !/^[1-9]\d{5}$/.test(pin) || pin === lastQuote) return;
            lastQuote = pin;
            fetch(co.dataset.quote + '?pin=' + pin, { headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (d) {
                ['online', 'cod'].forEach(function (k) {
                    var box = $('[data-totals=' + k + ']', co);
                    if (box && d[k]) box.innerHTML = d[k].html;
                    $$('input[name=payment_method]', co).forEach(function (r) { if ((r.value === 'cod') === (k === 'cod') && d[k]) r.dataset.total = d[k].total_text; });
                });
                var note = $('[data-cod-note]', co);
                if (note && d.cod) note.textContent = d.cod.note;
                var sel = $('input[name=payment_method]:checked', co);
                if (sel) tot.textContent = sel.dataset.total;
            }).catch(function () {});
        }
        pinIn.addEventListener('input', quote);
        // Remember the cart once we know how to reach the shopper (for a reminder if they don't finish).
        var capT, lastSent = '';
        function capture() {
            var f = { name: co.name.value, email: co.email.value, phone: co.phone.value }, key = JSON.stringify(f);
            if (key === lastSent || !(/\S+@\S+\.\S+/.test(f.email) || f.phone.replace(/\D/g, '').length >= 10)) return;
            lastSent = key;
            fetch(co.dataset.capture, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: key }).catch(function () {});
        }
        ['name', 'email', 'phone'].forEach(function (n) { if (co[n]) co[n].addEventListener('input', function () { clearTimeout(capT); capT = setTimeout(capture, 1200); }); });
        if (co.email.value || co.phone.value) capture();
        if (pinIn.value) { pinCheck(); quote(); }
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

/* Festival campaign: live countdown to the order-by time, and tap-to-copy coupon */
(function () {
    var els = document.querySelectorAll('[data-countdown]');
    function tick() {
        els.forEach(function (el) {
            var ms = new Date(el.dataset.countdown) - new Date();
            if (ms <= 0) { el.textContent = ''; return; }
            var d = Math.floor(ms / 864e5), h = Math.floor(ms % 864e5 / 36e5), m = Math.floor(ms % 36e5 / 6e4);
            if (el.hasAttribute('data-long')) {
                el.innerHTML = [[d, 'days'], [h, 'hours'], [m, 'mins']].map(function (p) { return '<span>' + p[0] + '<small>' + p[1] + '</small></span>'; }).join('');
            } else {
                el.textContent = (d ? d + 'd ' : '') + h + 'h ' + m + 'm left';
            }
        });
    }
    if (els.length) { tick(); setInterval(tick, 30000); }
    document.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('[data-copy]');
        if (!b) return;
        var done = function () { var s = b.querySelector('small'); if (s) s.textContent = 'copied ✓'; };
        if (navigator.clipboard) navigator.clipboard.writeText(b.dataset.copy).then(done, done); else done();
    });
})();
