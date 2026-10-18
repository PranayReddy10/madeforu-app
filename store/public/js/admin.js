(function () {
  'use strict';
  const $ = (s, el) => (el || document).querySelector(s);
  const $$ = (s, el) => Array.from((el || document).querySelectorAll(s));

  // Confirm destructive buttons
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-confirm]');
    if (b && !confirm(b.dataset.confirm)) { e.preventDefault(); e.stopPropagation(); }
  }, true);

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
