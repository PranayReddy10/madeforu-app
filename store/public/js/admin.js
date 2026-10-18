(function () {
  'use strict';
  const $ = (s, el) => (el || document).querySelector(s);
  const $$ = (s, el) => Array.from((el || document).querySelectorAll(s));

  // Confirm destructive buttons
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-confirm]');
    if (b && !confirm(b.dataset.confirm)) { e.preventDefault(); e.stopPropagation(); }
  }, true);

  // Select-all checkbox in lists
  document.addEventListener('change', (e) => {
    if (e.target.matches('[data-check-all]')) $$('[data-check]').forEach((c) => { c.checked = e.target.checked; });
  });

  // Copy text to clipboard (order personalisation)
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-copy]');
    if (!b) return;
    navigator.clipboard && navigator.clipboard.writeText(b.dataset.copy).then(() => { const t = b.textContent; b.textContent = 'Copied ✓'; setTimeout(() => { b.textContent = t; }, 1500); });
  });

  // Repeatable rows: <template> uses __i__ as the index placeholder
  const counters = {};
  function nextIndex(name) {
    const box = document.getElementById(name);
    if (!(name in counters)) counters[name] = box ? box.querySelectorAll('[data-row]').length + 1000 : 1000;
    return counters[name]++;
  }
  function addRow(name, tplId, values) {
    const box = document.getElementById(name);
    const tpl = document.getElementById(tplId);
    if (!box || !tpl) return null;
    const html = tpl.innerHTML.replace(/__i__/g, String(nextIndex(name)));
    const holder = document.createElement(box.tagName === 'TBODY' ? 'tbody' : 'div');
    holder.innerHTML = html.trim();
    const row = holder.firstElementChild;
    box.appendChild(row);
    if (values) {
      Object.entries(values).forEach(([k, v]) => {
        const input = row.querySelector('[name$="[' + k + ']"]');
        if (!input) return;
        if (input.type === 'checkbox') input.checked = !!v; else input.value = v;
      });
    }
    initFieldRow(row);
    return row;
  }
  document.addEventListener('click', (e) => {
    const add = e.target.closest('[data-add-row]');
    if (add) { e.preventDefault(); addRow(add.dataset.addRow, add.dataset.tpl); return; }
    const rm = e.target.closest('[data-remove-row]');
    if (rm) { e.preventDefault(); const row = rm.closest('[data-row]'); if (row) row.remove(); }
  });

  // Customisation fields: show only the inputs that apply to the chosen type
  function initFieldRow(row) {
    const sel = row.querySelector('[data-field-type]');
    if (!sel) return;
    const apply = () => {
      $$('[data-show]', row).forEach((el) => {
        el.style.display = el.dataset.show.split(' ').includes(sel.value) ? '' : 'none';
      });
    };
    sel.addEventListener('change', apply);
    apply();
  }
  $$('#fields [data-row]').forEach(initFieldRow);

  // Personalisation presets: one click replaces the fields with a ready-made setup
  const presetBar = $('[data-presets]');
  if (presetBar) {
    const presets = JSON.parse(presetBar.dataset.presets);
    presetBar.addEventListener('click', (e) => {
      const b = e.target.closest('[data-preset]');
      if (!b) return;
      const box = document.getElementById('fields');
      if (box.querySelector('[data-row]') && !confirm('Replace the current personalisation fields with “' + presets[b.dataset.preset].label + '”?')) return;
      box.innerHTML = '';
      presets[b.dataset.preset].fields.forEach((f, i) => {
        const row = addRow('fields', 'tpl-field', Object.assign({ min_files: 1, max_files: 1, max_length: '', help: '', extra_price: 0 }, f, { position: i }));
        const sel = row && row.querySelector('[data-field-type]');
        if (sel) sel.dispatchEvent(new Event('change'));
      });
    });
  }

  // Character counters
  $$('.counter[data-for]').forEach((c) => {
    const input = document.getElementById(c.dataset.for);
    if (!input) return;
    const max = parseInt(c.dataset.max, 10);
    const update = () => {
      const n = input.value.length;
      c.textContent = n + ' / ' + max;
      c.className = 'counter ' + (n > max ? 'over' : n >= max * 0.5 ? 'good' : 'short');
    };
    input.addEventListener('input', update);
    update();
  });

  // Slug from name, and live Google preview
  const slugify = (s) => s.toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  const name = $('#f-name'), slug = $('#f-slug'), mt = $('#f-meta-title'), md = $('#f-meta-desc'),
    focus = $('#f-focus'), desc = $('#f-description');
  if (name && slug) {
    let touched = slug.value !== '';
    slug.addEventListener('input', () => { touched = slug.value !== ''; });
    const sync = () => {
      if (!touched) slug.placeholder = slugify(name.value) || 'auto from name';
      const s = slug.value || slugify(name.value);
      const p1 = $('#slug-preview'), p2 = $('#serp-slug');
      if (p1) p1.textContent = s; if (p2) p2.textContent = s;
      serp();
    };
    [name, slug].forEach((el) => el.addEventListener('input', sync));
  }
  function serp() {
    const t = $('#serp-title'), d = $('#serp-desc');
    if (t) t.textContent = (mt && mt.value) || (name && name.value) || '';
    if (d && md) d.textContent = md.value || d.dataset.fallback || d.textContent;
    focusChecks();
  }
  function focusChecks() {
    const box = $('#focus-checks');
    if (!box || !focus) return;
    const kw = focus.value.trim().toLowerCase();
    if (!kw) { box.innerHTML = ''; return; }
    const title = ((mt && mt.value) || (name && name.value) || '').toLowerCase();
    const s = (slug && (slug.value || slugify(name ? name.value : ''))) || '';
    const checks = [
      ['in title', title.includes(kw)],
      ['in description', md ? md.value.toLowerCase().includes(kw) : false],
      ['in URL', s.includes(slugify(kw))],
      ['in page text', desc ? desc.value.toLowerCase().includes(kw) : false],
    ];
    box.innerHTML = checks.map(([l, ok]) => '<span class="' + (ok ? 'y' : 'n') + '">' + (ok ? '✓' : '✗') + ' Keyword ' + l + '</span>').join('');
  }
  [mt, md, focus, desc].forEach((el) => el && el.addEventListener('input', serp));
  if ($('#serp-desc')) $('#serp-desc').dataset.fallback = $('#serp-desc').textContent;
  serp();

  // Variation box only matters for variable products
  const type = $('#f-type'), vbox = $('#variation-box');
  if (type && vbox) {
    const t = () => { vbox.style.opacity = type.value === 'variable' ? '1' : '.6'; };
    type.addEventListener('change', t); t();
  }

  // Generate every combination of attribute values as variation rows
  const gen = $('#gen-variants');
  if (gen) {
    gen.addEventListener('click', () => {
      const attrs = $$('#attrs [data-row]').map((r) => ({
        name: (r.querySelector('[data-attr-name]').value || '').trim(),
        values: (r.querySelector('[data-attr-values]').value || '').split(',').map((v) => v.trim()).filter(Boolean),
      })).filter((a) => a.name && a.values.length);
      if (!attrs.length) { alert('Add at least one attribute with values first.'); return; }
      let combos = [[]];
      attrs.forEach((a) => {
        const next = [];
        combos.forEach((c) => a.values.forEach((v) => next.push(c.concat([[a.name, v]]))));
        combos = next;
      });
      if (combos.length > 200 && !confirm(combos.length + ' combinations — continue?')) return;
      const norm = (s) => s.split(';').map((x) => x.trim()).filter(Boolean).sort().join(';');
      const existing = new Set($$('#variants [data-variant-options]').map((i) => norm(i.value)));
      const price = ($('#f-price') || {}).value || '';
      let added = 0;
      combos.forEach((c) => {
        const opt = c.map(([k, v]) => k + '=' + v).join('; ');
        if (existing.has(norm(opt))) return;
        addRow('variants', 'tpl-variant', { options: opt, price: price, in_stock: true });
        added++;
      });
      if (type) type.value = 'variable';
      alert(added ? added + ' variation(s) added. Set their prices, then save.' : 'All combinations already exist.');
    });
  }
})();

/* Media library picker: any [data-media-pick] button opens it.
   data-multiple="1"  → "Use selected" fires a 'media:selected' event on the button (detail: [{path,url,alt}])
   data-multiple="0"  → fills the input named data-input with the image path and updates its preview
   data-folder        → folder new uploads are filed under (products, categories, banners) */
(function () {
  'use strict';
  const modal = document.getElementById('media-picker');
  if (!modal) return;
  const $ = (s, el) => (el || document).querySelector(s);
  const $$ = (s, el) => Array.from((el || document).querySelectorAll(s));
  const csrf = ($('meta[name="csrf-token"]') || {}).content;
  const grid = $('[data-mp-grid]', modal), more = $('[data-mp-more]', modal), empty = $('[data-mp-empty]', modal);
  const search = $('[data-mp-search]', modal), folderSel = $('[data-mp-folder]', modal);
  const useBtn = $('[data-mp-use]', modal), count = $('[data-mp-count]', modal);
  let state = null, nextUrl = null, loading = false, searchTimer = null;

  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  function open(btn) {
    state = { btn, multiple: btn.dataset.multiple === '1', input: btn.dataset.input || '', folder: btn.dataset.folder || 'general', selected: new Map() };
    $('#mp-title', modal).textContent = state.multiple ? 'Choose images' : 'Choose an image';
    modal.hidden = false;
    document.documentElement.classList.add('mp-open');
    tab('library');
    updateFoot();
    load(true);
    setTimeout(() => search.focus(), 50);
  }
  function close() {
    modal.hidden = true;
    document.documentElement.classList.remove('mp-open');
    if (state && state.btn) state.btn.focus();
    state = null;
  }
  function tab(name) {
    $$('[data-mp-tab]', modal).forEach((t) => { const on = t.dataset.mpTab === name; t.classList.toggle('on', on); t.setAttribute('aria-selected', on); });
    $$('[data-mp-pane]', modal).forEach((p) => { p.hidden = p.dataset.mpPane !== name; });
  }
  function listUrl() {
    const u = new URL(modal.dataset.listUrl, location.href);
    if (search.value.trim()) u.searchParams.set('q', search.value.trim());
    if (folderSel.value) u.searchParams.set('folder', folderSel.value);
    return u.toString();
  }
  function load(reset) {
    if (loading) return;
    loading = true;
    const url = reset ? listUrl() : nextUrl;
    if (reset) { grid.innerHTML = ''; }
    fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then((r) => r.json())
      .then((d) => {
        d.data.forEach((m) => grid.appendChild(item(m)));
        nextUrl = d.next_page_url;
        more.hidden = !nextUrl;
        empty.hidden = grid.children.length > 0;
      })
      .catch(() => { empty.hidden = false; empty.textContent = 'Could not load the library. Check your connection.'; })
      .finally(() => { loading = false; });
  }
  function item(m, prepend) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'mp-item' + (state && state.selected.has(m.path) ? ' on' : '');
    b.dataset.path = m.path;
    b.title = m.name + (m.width ? ' · ' + m.width + '×' + m.height : '');
    b.setAttribute('aria-label', m.alt || m.name);
    b.innerHTML = '<img src="' + esc(m.url) + '" alt="" loading="lazy"><span class="mp-check" aria-hidden="true">✓</span><span class="mp-name">' + esc(m.name) + '</span>';
    b._media = m;
    b.addEventListener('click', () => toggle(b));
    b.addEventListener('dblclick', () => { if (!state.multiple) { select(b, true); use(); } });
    return b;
  }
  function select(b, on) {
    if (!state.multiple && on) { state.selected.clear(); $$('.mp-item.on', grid).forEach((x) => x.classList.remove('on')); }
    if (on) state.selected.set(b._media.path, b._media); else state.selected.delete(b._media.path);
    b.classList.toggle('on', on);
    updateFoot();
  }
  function toggle(b) { select(b, !state.selected.has(b._media.path)); }
  function updateFoot() {
    const n = state ? state.selected.size : 0;
    count.textContent = n ? n + ' selected' : 'No image selected';
    useBtn.disabled = !n;
  }
  function use() {
    if (!state || !state.selected.size) return;
    const items = Array.from(state.selected.values()).map((m) => ({ path: m.path, url: m.url, alt: m.alt || '' }));
    const btn = state.btn;
    if (state.multiple) {
      btn.dispatchEvent(new CustomEvent('media:selected', { detail: items, bubbles: true }));
    } else {
      fill(btn, state.input, items[0]);
    }
    close();
  }
  function fill(btn, name, m) {
    const scope = btn.closest('form') || document;
    let input = name ? scope.querySelector('[name="' + CSS.escape(name) + '"]') : null;
    if (!input && name) {
      input = document.createElement('input'); input.type = 'hidden'; input.name = name;
      (btn.closest('form') || btn.parentNode).appendChild(input);
    }
    if (input) { input.value = m ? m.path : ''; input.dispatchEvent(new Event('change', { bubbles: true })); }
    const previews = [];
    if (btn.dataset.preview) previews.push(...$$(btn.dataset.preview));
    if (name) previews.push(...$$('img[data-preview-for="' + CSS.escape(name) + '"]', scope));
    previews.forEach((img) => { if (m) { img.src = m.url; img.hidden = false; } else { img.removeAttribute('src'); img.hidden = true; } });
  }

  // Uploads: one request per file so each shows its own progress
  const fileInput = $('[data-mp-file]', modal), drop = $('[data-mp-drop]', modal), progress = $('[data-mp-progress]', modal);
  function upload(files) {
    Array.from(files).forEach((file) => {
      const li = document.createElement('li');
      li.innerHTML = '<span class="mp-pname">' + esc(file.name) + '</span><span class="mp-bar"><i></i></span><span class="mp-status">0%</span>';
      progress.prepend(li);
      const fd = new FormData();
      fd.append('files[]', file);
      fd.append('folder', state ? state.folder : 'general');
      const xhr = new XMLHttpRequest();
      xhr.open('POST', modal.dataset.uploadUrl);
      xhr.setRequestHeader('X-CSRF-TOKEN', csrf);
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.upload.onprogress = (e) => {
        if (!e.lengthComputable) return;
        const p = Math.round(100 * e.loaded / e.total);
        $('i', li).style.width = p + '%'; $('.mp-status', li).textContent = p + '%';
      };
      xhr.onload = () => {
        let d = {};
        try { d = JSON.parse(xhr.responseText); } catch (err) { /* not JSON */ }
        if (xhr.status !== 200) {
          li.classList.add('err');
          $('.mp-status', li).textContent = (d.errors && Object.values(d.errors)[0][0]) || d.message || 'Upload failed';
          return;
        }
        li.classList.add('ok');
        $('.mp-status', li).textContent = 'Done';
        (d.data || []).forEach((m) => {
          const b = item(m);
          grid.prepend(b);
          empty.hidden = true;
          if (state) select(b, true);
        });
        // Back to the library once every upload in this batch has finished.
        if (!$$('li:not(.ok):not(.err)', progress).length) tab('library');
      };
      xhr.onerror = () => { li.classList.add('err'); $('.mp-status', li).textContent = 'Network error'; };
      xhr.send(fd);
    });
  }
  fileInput.addEventListener('change', () => { upload(fileInput.files); fileInput.value = ''; });
  ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('over'); }));
  ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove('over'); }));
  drop.addEventListener('drop', (e) => { if (e.dataTransfer.files.length) upload(e.dataTransfer.files); });
  // Dropping files anywhere on the open library goes to upload too
  $('.mp-panel', modal).addEventListener('dragover', (e) => { if (e.dataTransfer.types.includes('Files')) { e.preventDefault(); tab('upload'); } });

  document.addEventListener('click', (e) => {
    const pick = e.target.closest('[data-media-pick]');
    if (pick) { e.preventDefault(); open(pick); return; }
    const clear = e.target.closest('[data-media-clear]');
    if (clear) { e.preventDefault(); fill(clear, clear.dataset.input, null); }
  });
  $$('[data-mp-close]', modal).forEach((b) => b.addEventListener('click', close));
  $$('[data-mp-tab]', modal).forEach((t) => t.addEventListener('click', () => tab(t.dataset.mpTab)));
  useBtn.addEventListener('click', use);
  more.addEventListener('click', () => load(false));
  search.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => load(true), 250); });
  folderSel.addEventListener('change', () => load(true));
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !modal.hidden) close(); });

  // Media page: drag files onto the upload card
  $$('[data-drop-upload]').forEach((form) => {
    const input = $('input[type=file]', form);
    ['dragenter', 'dragover'].forEach((ev) => form.addEventListener(ev, (e) => { e.preventDefault(); form.classList.add('over'); }));
    ['dragleave', 'drop'].forEach((ev) => form.addEventListener(ev, (e) => { e.preventDefault(); form.classList.remove('over'); }));
    form.addEventListener('drop', (e) => { if (e.dataTransfer.files.length) { input.files = e.dataTransfer.files; form.submit(); } });
  });

  // Product gallery: rows added from the picker, reordered by drag or arrows
  const gallery = document.getElementById('gallery');
  if (gallery) {
    const tpl = document.getElementById('tpl-gallery');
    let n = gallery.querySelectorAll('[data-gallery-row]').length + 1000;
    const refresh = () => {
      const rows = $$('[data-gallery-row]', gallery);
      rows.forEach((r, i) => { const badge = $('.g-main', r); if (badge) badge.hidden = i !== 0; });
      const none = $('[data-gallery-empty]'); if (none) none.hidden = rows.length > 0;
    };
    const addImage = (m) => {
      if ($$('[data-gallery-row] input[name$="[path]"]', gallery).some((i) => i.value === m.path)) return;
      const holder = document.createElement('div');
      holder.innerHTML = tpl.innerHTML.replace(/__i__/g, String(n++)).trim();
      const row = holder.firstElementChild;
      $('input[name$="[path]"]', row).value = m.path;
      $('input[name$="[alt]"]', row).value = m.alt || '';
      $('img', row).src = m.url;
      gallery.appendChild(row);
    };
    document.addEventListener('media:selected', (e) => {
      if (!e.target.closest('[data-gallery-add]')) return;
      e.detail.forEach(addImage);
      refresh();
    });
    gallery.addEventListener('click', (e) => {
      const row = e.target.closest('[data-gallery-row]');
      if (!row) return;
      if (e.target.closest('[data-gallery-remove]')) { e.preventDefault(); row.remove(); refresh(); return; }
      const mv = e.target.closest('[data-move]');
      if (mv) {
        e.preventDefault();
        if (mv.dataset.move === '-1' && row.previousElementSibling) gallery.insertBefore(row, row.previousElementSibling);
        if (mv.dataset.move === '1' && row.nextElementSibling) gallery.insertBefore(row.nextElementSibling, row);
        refresh();
      }
    });
    let dragged = null;
    gallery.addEventListener('dragstart', (e) => {
      dragged = e.target.closest('[data-gallery-row]');
      if (dragged) { dragged.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; }
    });
    gallery.addEventListener('dragend', () => { if (dragged) dragged.classList.remove('dragging'); dragged = null; refresh(); });
    gallery.addEventListener('dragover', (e) => {
      if (!dragged) return;
      e.preventDefault();
      const over = e.target.closest('[data-gallery-row]');
      if (!over || over === dragged) return;
      const r = over.getBoundingClientRect();
      const after = (e.clientX - r.left) > r.width / 2;
      gallery.insertBefore(dragged, after ? over.nextSibling : over);
    });
    refresh();
  }
})();

/* Settings: tabs, search across all groups, sticky save bar */
(function () {
    var form = document.querySelector('[data-settings]');
    if (!form) return;
    var groups = Array.prototype.slice.call(form.querySelectorAll('[data-set-group]'));
    var tabs = Array.prototype.slice.call(form.querySelectorAll('[data-set-tab]'));
    var search = form.querySelector('[data-set-search]'), none = form.querySelector('[data-set-none]');
    function show(id) {
        if (!groups.some(function (g) { return g.id === id; })) id = groups[0].id;
        groups.forEach(function (g) { g.hidden = g.id !== id; });
        tabs.forEach(function (t) { t.classList.toggle('on', t.dataset.setTab === id); });
        try { localStorage.setItem('settings-tab', id); } catch (e) {}
    }
    tabs.forEach(function (t) {
        t.addEventListener('click', function (e) {
            e.preventDefault(); search.value = ''; filter('');
            show(t.dataset.setTab); history.replaceState(null, '', '#' + t.dataset.setTab);
            window.scrollTo({ top: 0 });
        });
    });
    function filter(q) {
        q = q.trim().toLowerCase();
        form.querySelectorAll('[data-set-group] label').forEach(function (l) { l.hidden = false; });
        if (!q) { var last = location.hash.slice(1); try { last = last || localStorage.getItem('settings-tab'); } catch (e) {} show(last); none.hidden = true; return; }
        var any = false;
        groups.forEach(function (g) {
            var hit = false;
            g.querySelectorAll('label').forEach(function (l) {
                var m = l.textContent.toLowerCase().indexOf(q) !== -1 || g.querySelector('h2').textContent.toLowerCase().indexOf(q) !== -1;
                l.hidden = !m; hit = hit || m;
            });
            g.hidden = !hit; any = any || hit;
        });
        tabs.forEach(function (t) { t.classList.remove('on'); });
        none.hidden = any;
    }
    search.addEventListener('input', function () { filter(search.value); });
    var dirty = form.querySelector('[data-set-dirty]');
    form.addEventListener('input', function (e) { if (e.target !== search) dirty.hidden = false; });
    form.addEventListener('change', function (e) { if (e.target !== search) dirty.hidden = false; });
    filter('');
})();


/* Home page editor: move, switch on/off, add and delete sections, FAQ rows */
(function () {
    var form = document.querySelector('[data-hb]');
    if (!form) return;
    var list = form.querySelector('[data-hb-list]'), dirty = form.querySelector('[data-hb-dirty]');
    function changed() { dirty.hidden = false; }
    form.addEventListener('input', changed);
    form.addEventListener('change', function (e) {
        changed();
        if (e.target.matches('[data-hb-on]')) e.target.closest('[data-hb-card]').classList.toggle('off', !e.target.checked);
        if (e.target.matches('[data-hb-source]')) {
            var card = e.target.closest('[data-hb-card]');
            card.querySelectorAll('[data-hb-when]').forEach(function (el) { el.hidden = el.dataset.hbWhen !== e.target.value; });
        }
        if (e.target.matches('[data-hb-title]')) {
            var small = e.target.closest('[data-hb-card]').querySelector('.hb-name small');
            if (small) small.textContent = e.target.value ? '“' + e.target.value.slice(0, 50) + '”' : '';
        }
    });
    form.addEventListener('click', function (e) {
        var b = e.target.closest('button');
        if (!b) return;
        var card = b.closest('[data-hb-card]');
        if (b.matches('[data-hb-up]') || b.matches('[data-hb-down]')) {
            e.preventDefault();
            var sib = b.matches('[data-hb-up]') ? card.previousElementSibling : card.nextElementSibling;
            if (sib) { b.matches('[data-hb-up]') ? list.insertBefore(card, sib) : list.insertBefore(sib, card); card.classList.add('moved'); setTimeout(function () { card.classList.remove('moved'); }, 500); changed(); }
        } else if (b.matches('[data-hb-delete]')) {
            if (confirm('Delete this section?')) { card.remove(); changed(); }
        } else if (b.matches('[data-hb-add]')) {
            var type = form.querySelector('[data-hb-type]').value;
            var tpl = form.querySelector('[data-hb-template="' + type + '"]');
            var id = Math.random().toString(36).slice(2, 10).replace(/[^a-z0-9]/g, 'x');
            var div = document.createElement('div');
            div.innerHTML = tpl.innerHTML.replace(/xxidxx/g, id);
            var added = div.firstElementChild;
            list.appendChild(added); added.open = true;
            added.scrollIntoView({ behavior: 'smooth', block: 'center' }); changed();
        } else if (b.matches('[data-hb-faq-add]')) {
            var box = card.querySelector('[data-hb-faq]'), name = box.dataset.name, i = Date.now() % 100000;
            var row = document.createElement('div');
            row.className = 'hb-qa';
            row.innerHTML = '<input placeholder="Question"><textarea rows="2" placeholder="Answer"></textarea><button type="button" class="link danger" data-hb-faq-remove>Remove</button>';
            row.querySelector('input').name = name + '[' + i + '][q]';
            row.querySelector('textarea').name = name + '[' + i + '][a]';
            box.appendChild(row); row.querySelector('input').focus(); changed();
        } else if (b.matches('[data-hb-faq-remove]')) {
            b.closest('.hb-qa').remove(); changed();
        }
    });
})();

/* "Choose a design" options in the product's personalisation fields */
document.addEventListener('click', function (e) {
    var add = e.target.closest('[data-choice-add]');
    if (add) {
        var box = add.closest('[data-choices]'), list = box.querySelector('[data-choice-list]');
        var name = box.dataset.base + '[' + Date.now() % 1000000 + ']';
        var row = document.createElement('div');
        row.className = 'choice-row'; row.setAttribute('data-choice-row', '');
        row.innerHTML = '<img class="preview" hidden alt=""><input type="hidden"><button type="button" class="btn small ghost" data-media-pick data-multiple="0" data-folder="options">Picture</button>'
            + '<input placeholder="e.g. Heart" maxlength="60"><input type="number" step="0.01" min="0" value="0" title="Extra price (₹)" class="price-in">'
            + '<button type="button" class="link danger" data-choice-remove aria-label="Remove option">✕</button>';
        row.querySelector('img').setAttribute('data-preview-for', name + '[image]');
        row.querySelector('input[type=hidden]').name = name + '[image]';
        row.querySelector('[data-media-pick]').setAttribute('data-input', name + '[image]');
        var ins = row.querySelectorAll('input:not([type=hidden])');
        ins[0].name = name + '[label]'; ins[1].name = name + '[price]';
        list.appendChild(row); ins[0].focus();
        return;
    }
    var rm = e.target.closest('[data-choice-remove]');
    if (rm) rm.closest('[data-choice-row]').remove();
});

/* Orders list: tick orders, then act on them together */
(function () {
    var form = document.querySelector('[data-bulk]');
    if (!form) return;
    var bar = form.querySelector('[data-bulk-bar]'), count = form.querySelector('[data-bulk-count]'), all = form.querySelector('[data-check-all]');
    function update() {
        var n = form.querySelectorAll('[data-check]:checked').length;
        bar.hidden = n === 0; count.textContent = n + ' selected';
        form.querySelectorAll('[data-check]').forEach(function (c) { c.closest('.ol-row').classList.toggle('picked', c.checked); });
        if (all) all.checked = n > 0 && n === form.querySelectorAll('[data-check]').length;
    }
    form.addEventListener('change', function (e) {
        if (e.target === all) form.querySelectorAll('[data-check]').forEach(function (c) { c.checked = all.checked; });
        update();
    });
})();

/* Product form: test the live Delhivery charge for this product */
document.querySelectorAll('[data-rate-test]').forEach(function (box) {
    const out = box.querySelector('[data-rate-out]');
    const rupees = function (v) { return v == null ? '—' : '₹' + Number(v).toFixed(2); };
    const kg = function (g) { return g + ' g'; };
    box.querySelector('[data-rate-go]').addEventListener('click', function () {
        const pin = box.querySelector('[data-rate-pin]').value.replace(/\D/g, ''), qty = box.querySelector('[data-rate-qty]').value || 1;
        if (pin.length !== 6) { out.textContent = 'Enter a 6-digit PIN.'; return; }
        out.textContent = 'Asking Delhivery…';
        fetch(box.dataset.rateTest + '?pin=' + pin + '&qty=' + qty, { headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (d) {
            const p = d.parcel, lines = [];
            lines.push('Parcel: actual ' + kg(p.actual_g) + (p.size_cm ? ', size ' + p.size_cm.join(' × ') + ' cm = volumetric ' + kg(p.volumetric_g) : ', no size set') + ' → billed as ' + kg(p.chargeable_g) + (p.packaging === 'box' ? ' (box: higher of actual and volumetric)' : ' (flyer: actual weight)'));
            if (d.prepaid.error) lines.push('Delhivery: ' + d.prepaid.error);
            else lines.push('Delhivery prepaid ' + rupees(d.prepaid.total) + ' · COD ' + rupees(d.cod.total) + ' (HTTP ' + d.prepaid.status + ')');
            if (d.customer_pays) lines.push('Customer pays: shipping ' + rupees(d.customer_pays.shipping) + (d.customer_pays.cod_charge != null ? ', COD charge ' + rupees(d.customer_pays.cod_charge) : ''));
            out.innerHTML = '';
            lines.forEach(function (t) { const div = document.createElement('div'); div.textContent = t; out.appendChild(div); });
            const det = document.createElement('details'), pre = document.createElement('pre');
            det.innerHTML = '<summary>What was sent and Delhivery’s full reply</summary>';
            pre.style.cssText = 'white-space:pre-wrap;max-height:240px;overflow:auto;font-size:11px';
            pre.textContent = JSON.stringify({ sent: d.prepaid.sent, reply: d.prepaid.body }, null, 2);
            det.appendChild(pre); out.appendChild(det);
        }).catch(function () { out.textContent = 'Could not reach the store. Try again.'; });
    });
});
