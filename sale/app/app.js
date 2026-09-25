/*
 * MadeForU Sales — the web app.
 *
 * Plain JavaScript on purpose: no build step, no node_modules, nothing to
 * compile. The whole thing is four files you drop on the server next to
 * the API, which is what makes it maintainable by whoever inherits it.
 *
 * It talks to the same api/ the Android app does, so the rules live in one
 * place: prices come from the catalogue, duplicate lines merge, an order
 * cannot be overpaid, delivered implies ready. None of that is
 * re-implemented here — the server decides and this shows the answer.
 */

/* ── Config and storage ───────────────────────────────────────── */

// Served from the same site, so the API is a sibling folder. Overridable
// from the sign-in screen for anyone testing against another server.
const DEFAULT_API = new URL('../api/', location.href).href;

/*
 * Bump BUILD whenever these files change. It is the only way to answer
 * "did my upload actually land?" from the phone: Settings prints it, so a
 * partner can read it back instead of everyone guessing whether the
 * browser, the server or the app is the stale one. It must match the
 * CACHE name in sw.js.
 */
const BUILD = '2026-09-25.2';

/** What this build of the app expects the server to be able to do. */
const NEEDS_FEATURES = ['revenue_breakdown', 'expense_create', 'price_history',
                        'all_channel_revenue', 'reprice_open', 'wholesale'];

const store = {
  get token() { return localStorage.getItem('mfu.token') || ''; },
  set token(v) { v ? localStorage.setItem('mfu.token', v) : localStorage.removeItem('mfu.token'); },
  get name() { return localStorage.getItem('mfu.name') || ''; },
  set name(v) { localStorage.setItem('mfu.name', v || ''); },
  // 'auto' follows the phone's own light/dark setting; the other two
  // override it. Stored per device, not per account — the partner using a
  // shared login on a bright stall wants light, not whatever someone else
  // picked at home.
  get theme() { return localStorage.getItem('mfu.theme') || 'auto'; },
  set theme(v) { localStorage.setItem('mfu.theme', v || 'auto'); },
  get api() { return localStorage.getItem('mfu.api') || DEFAULT_API; },
  set api(v) {
    const clean = (v || '').trim();
    if (clean) localStorage.setItem('mfu.api', clean.endsWith('/') ? clean : clean + '/');
    else localStorage.removeItem('mfu.api');
  },
};

/* ── Formatting ───────────────────────────────────────────────── */

// Indian grouping: 1,23,456 — not 123,456. Every partner reads in lakhs.
const inr = new Intl.NumberFormat('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const inr0 = new Intl.NumberFormat('en-IN', { maximumFractionDigits: 0 });

function money(v) {
  const n = Number(v) || 0;
  // The minus goes before the symbol: -₹2,500, never ₹-2,500.
  return (n < 0 ? '-₹' : '₹') + inr.format(Math.abs(n));
}
function moneyShort(v) {
  const n = Number(v) || 0;
  return (n < 0 ? '-₹' : '₹') + inr0.format(Math.abs(n));
}
function signed(v) {
  const n = Number(v) || 0;
  // The same hyphen money() uses. A gap showing −₹6,046.07 beside a
  // profit share showing -₹39,344.57 reads as two different kinds of
  // number when it is only two different characters.
  return (n >= 0 ? '+' : '-') + '₹' + inr.format(Math.abs(n));
}
function prettyDate(raw) {
  if (!raw) return '—';
  const d = new Date(String(raw).replace(' ', 'T'));
  if (isNaN(d)) return raw;
  return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
}
function relativeDay(raw) {
  if (!raw) return '—';
  const d = new Date(String(raw).replace(' ', 'T'));
  if (isNaN(d)) return raw;
  const today = new Date();
  const sameDay = (a, b) => a.toDateString() === b.toDateString();
  const yesterday = new Date(today); yesterday.setDate(today.getDate() - 1);
  if (sameDay(d, today)) return 'Today';
  if (sameDay(d, yesterday)) return 'Yesterday';
  return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
}
function today() { return new Date().toISOString().slice(0, 10); }
function monthStart() { const d = new Date(); d.setDate(1); return d.toISOString().slice(0, 10); }
function daysAgo(n) { const d = new Date(); d.setDate(d.getDate() - n); return d.toISOString().slice(0, 10); }

// Escaped everywhere text reaches the DOM. Customer names and product
// names are user input and they end up inside innerHTML.
function esc(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

/* The same stable-colour-per-label the Android app uses for its tiles. */
const TILE_COLOURS = ['#7A3DF5', '#00A0A8', '#E8833A', '#2E8B57', '#C2185B', '#5C6BC0', '#00897B', '#8D6E63'];
function tileColour(label) {
  const s = String(label || '').toLowerCase();
  if (!s.trim()) return TILE_COLOURS[0];
  let h = 7;
  for (let i = 0; i < s.length; i++) h = (Math.imul(h, 31) + s.charCodeAt(i)) | 0;
  return TILE_COLOURS[Math.abs(h) % TILE_COLOURS.length];
}
function initials(name) {
  const w = String(name || '').trim().split(/\s+/).filter(Boolean);
  if (!w.length) return '?';
  return (w.length === 1 ? w[0].slice(0, 2) : w[0][0] + w[1][0]).toUpperCase();
}
function tile(label, glyph) {
  const c = tileColour(label);
  return `<div class="tile" style="background:${c}22;color:${c}">${glyph || esc(initials(label))}</div>`;
}

/* ── API ──────────────────────────────────────────────────────── */

async function api(endpoint, action, { body = null, params = {} } = {}) {
  const url = new URL(endpoint, store.api);
  url.searchParams.set('action', action);
  Object.entries(params).forEach(([k, v]) => {
    if (v !== '' && v != null) url.searchParams.set(k, v);
  });

  const init = { method: body ? 'POST' : 'GET', headers: { Accept: 'application/json' } };
  if (store.token) init.headers.Authorization = 'Bearer ' + store.token;
  if (body) {
    init.headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(body);
  }

  let response;
  try {
    response = await fetch(url, init);
  } catch (e) {
    throw new ApiError('network', 'No connection. Check the signal and try again.');
  }

  const text = await response.text();
  let data;
  try {
    data = JSON.parse(text);
  } catch (first) {
    // A server that has not been updated yet can put a PHP warning
    // AFTER the closing brace -- raised while PHP shuts down, so no
    // amount of buffering beforehand catches it. The document itself is
    // perfectly good; there is just prose stuck to the end of it.
    //
    // Salvage it rather than failing the whole screen, but say so in the
    // console so it is not silently lived with: the real fix is on the
    // server, and api_send() there now makes it impossible.
    const body = text.trim();
    // Junk can land on either side: a warning raised during shutdown goes
    // after the closing brace, while anything printed before the API's
    // output buffer opens -- text sitting in front of a file's <?php, say
    // -- goes in front of it. Look for a JSON document anywhere in the
    // body rather than assuming which end is dirty.
    const last = Math.max(body.lastIndexOf('}'), body.lastIndexOf(']'));
    if (last > 0) {
      // Try the first few opening braces. Junk in front can contain one
      // of its own, so the first candidate is not always the real start.
      let from = -1;
      for (let tries = 0; tries < 6; tries++) {
        from = body.indexOf('{', from + 1);
        if (from < 0 || from > last) break;
        try {
          data = JSON.parse(body.slice(from, last + 1));
          const before = body.slice(0, from).trim();
          const after = body.slice(last + 1).trim();
          console.warn('Server wrapped the JSON in junk.',
            before ? 'Before: ' + before.slice(0, 200) : '',
            after ? 'After: ' + after.slice(0, 200) : '');
          break;
        } catch (ignored) { /* try the next opening brace */ }
      }
    }
  }

  // The server tells us when it caught stray output. Say so once, in the
  // console: the screen works, but something on the server is printing
  // where it should not, and that is worth fixing rather than living with.
  if (data && data.notice) console.warn('Server notice:', data.notice);

  if (data === undefined) {
    // Shared hosting loves to prepend a warning or serve an error page.
    //
    // This used to say only "the server sent a reply the app could not
    // read", which names nothing: the same sentence covered a 500, a
    // missing file, a PHP warning printed in front of the JSON and an
    // empty body. Whoever saw it could only guess, and so could I. It
    // now says which call, what status came back, and what the first
    // line of the reply actually was -- which is usually the PHP error
    // itself, and names the problem outright.
    const where = endpoint + '?action=' + action;
    const body = text.trim();

    if (body === '') {
      throw new ApiError('bad_response',
        `${where} returned HTTP ${response.status} with an empty reply. ` +
        'That is usually a PHP fatal error on the server with error display ' +
        'switched off — a file that is missing or half-uploaded. Re-upload ' +
        'the whole api/ folder and the sale/ files beside it.');
    }
    if (body.startsWith('<')) {
      throw new ApiError('bad_response',
        `${where} returned a web page instead of data (HTTP ${response.status}). ` +
        'Either the address is wrong or that file is not on the server.');
    }
    // Something else entirely. The salvage above already tried to find a
    // JSON document anywhere in the body and failed, so say which of the
    // two this is -- a reply with no data in it at all is a different
    // problem from data with rubbish around it, and the fix differs.
    const firstLine = body.split('\n')[0].slice(0, 200);
    const hasBrace = body.indexOf('{') >= 0;
    throw new ApiError('bad_response',
      `${where} replied with no usable data (HTTP ${response.status}, ${body.length} bytes). ` +
      (hasBrace
        ? 'There is data in there but it could not be read. '
        : 'The reply contains no data at all — this looks like a file being served ' +
          'instead of the API. Check that file on the server. ') +
      'It begins: ' + firstLine);
  }

  if (!data.ok) {
    const err = data.error || {};
    if (['bad_token', 'expired_token', 'no_token'].includes(err.code)) {
      store.token = '';
      render();
      throw new ApiError(err.code, 'Your session ended. Please sign in again.');
    }
    throw new ApiError(err.code || 'failed', err.message || 'Something went wrong.');
  }
  return data;
}

class ApiError extends Error {
  constructor(code, message) { super(message); this.code = code; }
}


/* Inline SVG rather than emoji: a glyph like ▧ falls back to a hatched
   box on some phones, and colour emoji ignore the surrounding colour.
   These inherit currentColor and render identically everywhere. */
const ICONS = {
  home: '<path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10v9.5h13V10"/>',
  orders: '<path d="M4 6h16M4 12h16M4 18h10"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  stats: '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
  money: '<path d="M6 4h9a4 4 0 0 1 0 8H6m0-4h12M6 12l8 8"/>',
  expenses: '<path d="M6 4h9a4 4 0 0 1 0 8H6m0-4h12M6 12l8 8"/>',
  events: '<path d="M12 4 3 20h18L12 4z"/><path d="M12 12v8"/>',
  catalog: '<path d="M20.6 13.4 12 22l-9-9V4h9l8.6 9.4z"/><circle cx="7.5" cy="7.5" r="1.4"/>',
  bills: '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3z"/><path d="M9 8h6M9 12h6"/>',
  wholesale: '<path d="M3 7h18l-1.5 12H4.5z"/><path d="M8 7V5a4 4 0 0 1 8 0v2"/>',
  caret: '<path d="m6 9 6 6 6-6"/>',
  close: '<path d="M6 6l12 12M18 6 6 18"/>',
  sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9 6.3 6.3M17.7 17.7l1.4 1.4M19.1 4.9 17.7 6.3M6.3 17.7l-1.4 1.4"/>',
  moon: '<path d="M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5z"/>',
  auto: '<circle cx="12" cy="12" r="8.5"/><path d="M12 3.5v17a8.5 8.5 0 0 0 0-17z" fill="currentColor" stroke="none"/>',
  settings: '<circle cx="12" cy="12" r="3.2"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M19.1 4.9 17 7M7 17l-2.1 2.1"/>',
};

/** An icon at a given size, inheriting the colour it sits in. */
function svg(name, size) {
  return `<svg viewBox="0 0 24 24" width="${size || 20}" height="${size || 20}" fill="none"
    stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"
    aria-hidden="true">${ICONS[name] || ''}</svg>`;
}

/* ── Theme ────────────────────────────────────────────────────────
 * The stylesheet carries both palettes. This only decides which one
 * applies, by setting data-theme on <html>: 'auto' removes the attribute
 * and lets the prefers-color-scheme media query win.
 */
const THEMES = [
  { id: 'auto', label: 'Automatic', ico: 'auto', swatch: 'auto', hint: "follows your phone" },
  { id: 'light', label: 'Light', ico: 'sun', swatch: 'light', hint: 'always light' },
  { id: 'dark', label: 'Dark', ico: 'moon', swatch: 'dark', hint: 'always dark' },
];

function applyTheme(value) {
  const theme = THEMES.some((t) => t.id === value) ? value : 'auto';
  const root = document.documentElement;
  if (theme === 'auto') root.removeAttribute('data-theme');
  else root.setAttribute('data-theme', theme);

  // iOS paints the status bar and the area behind the keyboard from this,
  // so it has to move with the palette or a dark app gets a white notch.
  const dark = theme === 'dark'
    || (theme === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches);
  const meta = document.querySelector('meta[name="theme-color"]');
  if (meta) meta.setAttribute('content', dark ? '#12151F' : '#F54A77');
}

// Applied before the first paint so the app never flashes the wrong
// palette, and re-applied when the phone itself switches at sunset.
applyTheme(store.theme);
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
  if (store.theme === 'auto') applyTheme('auto');
});

/* ── Shell ────────────────────────────────────────────────────── */

const app = document.getElementById('app');
const tabbar = document.getElementById('tabbar');
const toastEl = document.getElementById('toast');
let toastTimer = null;

function toast(message) {
  toastEl.textContent = message;
  toastEl.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { toastEl.hidden = true; }, 3200);
}

function setHtml(html, { tabs = true } = {}) {
  app.innerHTML = html;
  app.scrollTop = 0;
  window.scrollTo(0, 0);
  tabbar.hidden = !tabs || !store.token;
  // Both live outside #app so a re-render cannot leave them behind on a
  // screen they do not belong to.
  document.querySelectorAll('.fab, .sheetwrap').forEach((n) => n.remove());
}

/**
 * A section that opens and closes — <details> doing the work, so it keeps
 * working with JavaScript mid-render and needs no state of its own.
 * `badge` is the bit of summary that has to be readable while it is shut:
 * the basket total, the number of line items.
 */
function fold(title, subtitle, body, { open = false, badge = '' } = {}) {
  return `<details class="fold"${open ? ' open' : ''}>
    <summary>
      <div class="grow"><div class="t">${esc(title)}</div>
        ${subtitle ? `<div class="s">${esc(subtitle)}</div>` : ''}</div>
      ${badge ? `<span class="badge">${esc(badge)}</span>` : ''}
      <span class="caret">${svg('caret', 18)}</span>
    </summary>
    <div class="foldbody">${body}</div>
  </details>`;
}

function spinner() { return '<div class="spinner"></div>'; }
function errorBox(message) { return `<div class="error">${esc(message)}</div>`; }

/**
 * A form that slides up over the current screen. Returns the panel so the
 * caller can wire its fields; closing is the backdrop, the X, or Escape.
 * Nothing is routed — the list behind stays exactly where it was.
 */
function openSheet(title, html) {
  document.querySelectorAll('.sheetwrap').forEach((n) => n.remove());
  const wrap = document.createElement('div');
  wrap.className = 'sheetwrap';
  wrap.innerHTML = `<div class="panel" role="dialog" aria-modal="true" aria-label="${esc(title)}">
    <div class="grabber"></div>
    <div class="head"><h1 class="grow">${esc(title)}</h1>
      <button class="back" data-close>${svg('close', 18)}</button></div>
    ${html}</div>`;
  document.body.appendChild(wrap);

  const close = () => { wrap.remove(); document.removeEventListener('keydown', onKey); };
  const onKey = (e) => { if (e.key === 'Escape') close(); };
  document.addEventListener('keydown', onKey);
  // Only the backdrop closes it — a tap inside the panel must not, or
  // every tap on a label would dismiss a half-filled form.
  wrap.addEventListener('click', (e) => { if (e.target === wrap) close(); });
  wrap.querySelector('[data-close]').onclick = close;
  return { wrap, panel: wrap.querySelector('.panel'), close };
}

/* Routing is a hash and a table. Back is the browser's own back. */
const routes = {};
function route(name, handler) { routes[name] = handler; }
function go(hash) { location.hash = hash; }

async function render() {
  const hash = location.hash.replace(/^#\/?/, '') || 'home';
  const [name, ...rest] = hash.split('/');

  if (!store.token) { screenLogin(); return; }

  const handler = routes[name] || routes.home;
  paintTabs(name);
  try {
    await handler(...rest);
  } catch (e) {
    if (e instanceof ApiError && ['bad_token', 'expired_token', 'no_token'].includes(e.code)) return;
    setHtml(`<div class="screen">${errorBox(e.message || 'Something went wrong.')}
      <button class="btn ghost" onclick="location.reload()">Reload</button></div>`);
  }
}

const TABS = [
  { id: 'home', label: 'Home', ico: 'home' },
  { id: 'orders', label: 'Orders', ico: 'orders' },
  { id: 'new', label: 'New sale', ico: 'plus' },
  { id: 'stats', label: 'Stats', ico: 'stats' },
  { id: 'money', label: 'Money', ico: 'money' },
];

function paintTabs(current) {
  tabbar.hidden = !store.token;
  tabbar.innerHTML = TABS.map((t) => `
    <button data-go="${t.id}" ${t.id === current ? 'aria-current="page"' : ''}>
      <span class="ico">${svg(t.ico, 21)}</span><span>${t.label}</span>
    </button>`).join('');
}

// One delegated listener rather than a handler per element: the screens
// are re-rendered constantly and per-element listeners would leak.
document.addEventListener('click', (event) => {
  const target = event.target.closest('[data-go]');
  if (target) { event.preventDefault(); go(target.dataset.go); }
});

window.addEventListener('hashchange', render);

/* ── Sign in ──────────────────────────────────────────────────── */

function screenLogin(message) {
  tabbar.hidden = true;
  app.innerHTML = `
    <div class="login">
      <div class="logo"><img src="icons/icon-512.png" alt="MadeForU"></div>
      <div class="tag">Made for your moments</div>
      <div class="sub">Sales, orders and partner accounts</div>
      <div class="sheet">
        <h1>Welcome back</h1>
        <p class="muted center">Same phone and password as the website</p>
        <div id="loginError">${message ? errorBox(message) : ''}</div>
        <label class="field"><span>Phone number</span>
          <input id="phone" type="tel" inputmode="numeric" maxlength="10" autocomplete="username">
        </label>
        <label class="field"><span>Password</span>
          <input id="password" type="password" autocomplete="current-password">
        </label>
        <div id="serverWrap" hidden>
          <label class="field"><span>Server address</span>
            <input id="server" type="url" placeholder="${esc(DEFAULT_API)}" value="${esc(store.api === DEFAULT_API ? '' : store.api)}">
          </label>
        </div>
        <div style="height:16px"></div>
        <button class="btn" id="signin">Sign in</button>
        <button class="btn ghost small" id="toggleServer" style="margin:12px auto 0">Use a different server</button>
        ${installHint()}
      </div>
    </div>`;

  const phone = document.getElementById('phone');
  phone.addEventListener('input', () => {
    // Pasted numbers arrive as "+91 98765 43210" more often than not.
    phone.value = phone.value.replace(/\D/g, '').slice(0, 10);
  });
  document.getElementById('toggleServer').onclick = () => {
    const wrap = document.getElementById('serverWrap');
    wrap.hidden = !wrap.hidden;
  };
  document.getElementById('password').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') signIn();
  });
  document.getElementById('signin').onclick = signIn;
}

async function signIn() {
  const button = document.getElementById('signin');
  const phone = document.getElementById('phone').value.trim();
  const password = document.getElementById('password').value;
  const server = document.getElementById('server');
  const box = document.getElementById('loginError');

  if (phone.length !== 10) { box.innerHTML = errorBox('Enter the 10-digit phone number you sign in with.'); return; }
  if (!password) { box.innerHTML = errorBox('Enter your password.'); return; }

  if (server && server.value.trim()) store.api = server.value;
  button.disabled = true;
  button.textContent = 'Signing in…';
  try {
    const data = await api('auth.php', 'login', {
      body: { phone, password, device: 'Web · ' + (navigator.platform || 'browser') },
    });
    store.token = data.token;
    store.name = (data.admin && data.admin.name) || '';
    go('home');
    render();
  } catch (e) {
    box.innerHTML = errorBox(e.message);
    button.disabled = false;
    button.textContent = 'Sign in';
  }
}

/* iOS has no install prompt — it is a Share-sheet action, so say so. */
function installHint() {
  const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone;
  if (standalone) return '';
  const iOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
  if (!iOS) return '';
  return `<div class="install">
    <b>Add to your Home Screen</b><br>
    Tap Share <b>↑</b> at the bottom of Safari, then <b>Add to Home Screen</b>.
    It then opens like an app, full screen, with no address bar.
  </div>`;
}

/* ── Home ─────────────────────────────────────────────────────── */

const RANGES = [
  { id: 'today', label: 'Today', from: today, bucket: 'day' },
  { id: 'week', label: '7 days', from: () => daysAgo(6), bucket: 'day' },
  { id: 'month', label: 'This month', from: monthStart, bucket: 'day' },
  { id: 'quarter', label: '90 days', from: () => daysAgo(89), bucket: 'week' },
  { id: 'year', label: 'This year', from: () => today().slice(0, 4) + '-01-01', bucket: 'month' },
];
let homeRange = 'month';

route('home', async () => {
  setHtml(`<div class="screen">
    <div class="head">
      <div class="grow">
        <h1>${esc(greeting())}${store.name ? ', ' + esc(store.name.split(' ')[0]) : ''}</h1>
        <div class="sub">${esc(prettyDate(today()))}</div>
      </div>
    </div>
    <div class="chips" id="ranges"></div>
    <div id="body">${spinner()}</div>
  </div>`);
  paintRanges('ranges', homeRange, (id) => { homeRange = id; routes.home(); });

  const range = RANGES.find((r) => r.id === homeRange);
  const params = { from: range.from(), to: today() };

  const [dash, series] = await Promise.all([
    api('stats.php', 'dashboard', { params }),
    api('stats.php', 'series', { params: { ...params, bucket: range.bucket } }).catch(() => null),
  ]);

  const h = dash.headline, q = dash.queues, t = dash.today;
  document.getElementById('body').innerHTML = `
    <div class="hero">
      <div class="row"><span class="label">Today</span>
        <span class="pill" style="background:rgba(255,255,255,.22);color:#fff">${t.orders} orders</span></div>
      <div class="big">${money(t.revenue)}</div>
      <div class="meta">${moneyShort(t.collected)} collected · ${moneyShort(t.product_profit)} profit</div>
    </div>

    <div class="grid2" style="margin-top:10px">
      ${statCard('Revenue', moneyShort(h.revenue), changeText(dash.change.revenue))}
      ${statCard('Orders', String(h.orders), changeText(dash.change.orders))}
      ${statCard('Collected', moneyShort(h.collected), 'of ' + moneyShort(h.revenue) + ' billed', 'pos')}
      ${statCard('Outstanding', moneyShort(h.outstanding),
        h.outstanding > 0.5 ? 'still to collect' : 'nothing pending', h.outstanding > 0.5 ? 'neg' : '')}
    </div>

    <section>
      <h2 class="section">Manage</h2>
      <div class="grid2">
        ${actionTile('wholesale', 'Wholesale buyers', 'wholesale')}
        ${actionTile('expenses', 'Expenses', 'expenses')}
        ${actionTile('events', 'Events & stalls', 'events')}
        ${actionTile('catalog', 'Products & prices', 'catalog')}
        ${actionTile('bills', 'Bill book', 'bills')}
        ${actionTile('settings', 'Settings', 'settings')}
      </div>
    </section>

    <section>
      <h2 class="section">Revenue trend
        <span class="hint">${esc(prettyDate(dash.range.from))} – ${esc(prettyDate(dash.range.to))}</span></h2>
      <div class="card">${series ? sparkline(series.points) : '<p class="muted">Chart unavailable.</p>'}</div>
    </section>

    <section>
      <h2 class="section">Needs attention</h2>
      <div class="card">
        ${queueRow('To make', q.to_make, 'orders not marked ready', 'all')}
        ${queueRow('To hand over', q.to_hand_over, 'ready, waiting for the customer', 'all')}
        ${queueRow('Money owed', q.owing, money(q.owed_amount) + ' across unpaid orders', 'unpaid')}
      </div>
    </section>
  </div>`;
});

function greeting() {
  const h = new Date().getHours();
  return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
}
function statCard(label, value, caption, tone) {
  return `<div class="card stat"><div class="l">${esc(label)}</div>
    <div class="v ${tone || ''}">${esc(value)}</div>
    <div class="c">${esc(caption || '')}</div></div>`;
}
function changeText(pct) {
  // No baseline means no percentage: "+100% against nothing" is not news.
  if (pct == null) return 'vs last period';
  return (pct >= 0 ? '▲ +' : '▼ ') + pct + '% vs last period';
}
function actionTile(hash, label, icon) {
  const c = tileColour(label);
  return `<button class="card tap" data-go="${hash}" style="display:flex;align-items:center;gap:10px;text-align:left">
    <span class="tile" style="background:${c}22;color:${c};width:38px;height:38px">${svg(icon, 19)}</span>
    <span style="font-weight:600;font-size:14px;line-height:1.25">${esc(label)}</span></button>`;
}
function queueRow(label, count, detail, filter) {
  return `<button class="row" data-go="orders/${filter}" style="width:100%;text-align:left">
    <div class="grow"><div class="t">${esc(label)}</div><div class="s">${esc(detail)}</div></div>
    <div class="amt">${count}</div></button>`;
}

/* A sparkline in inline SVG: no chart library for four shapes. */
function sparkline(points) {
  if (!points || !points.length) return '<p class="muted">No sales in this period.</p>';
  const values = points.map((p) => Number(p.revenue) || 0);
  const max = Math.max(...values, 1);
  const w = 320, h = 110, step = points.length > 1 ? w / (points.length - 1) : 0;
  const xy = values.map((v, i) => [points.length > 1 ? i * step : w / 2, h - (v / max) * (h - 12)]);
  const line = xy.map(([x, y], i) => `${i ? 'L' : 'M'}${x.toFixed(1)},${y.toFixed(1)}`).join(' ');
  const area = `${line} L${w},${h} L0,${h} Z`;
  const peak = values.indexOf(Math.max(...values));

  return `<svg viewBox="0 0 ${w} ${h}" style="width:100%;height:130px" preserveAspectRatio="none" role="img"
      aria-label="Revenue trend">
    <defs><linearGradient id="g" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0%" stop-color="#F54A77" stop-opacity=".34"/>
      <stop offset="100%" stop-color="#F54A77" stop-opacity="0"/>
    </linearGradient></defs>
    <path d="${area}" fill="url(#g)"/>
    <path d="${line}" fill="none" stroke="#F54A77" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
    ${xy[peak] ? `<circle cx="${xy[peak][0].toFixed(1)}" cy="${xy[peak][1].toFixed(1)}" r="4" fill="#F54A77"/>` : ''}
  </svg>
  <div class="row" style="border:none;padding-top:6px">
    <span class="s grow">${esc(points[0].label)}</span>
    <span class="s">${esc(points[points.length - 1].label)}</span>
  </div>`;
}

function paintRanges(id, current, onPick) {
  const el = document.getElementById(id);
  el.innerHTML = RANGES.map((r) =>
    `<button class="chip" data-range="${r.id}" aria-pressed="${r.id === current}">${r.label}</button>`).join('');
  el.querySelectorAll('[data-range]').forEach((b) => {
    b.onclick = () => onPick(b.dataset.range);
  });
}

/* ── Orders ───────────────────────────────────────────────────── */

const orderFilters = { q: '', pay: 'all', status: 'all' };

route('orders', async (pay) => {
  if (pay) orderFilters.pay = pay;
  setHtml(`<div class="screen">
    <div class="head"><h1 class="grow">Orders</h1>
      <button class="btn small" data-go="new">New sale</button></div>
    <input id="q" type="search" placeholder="Order no, name, phone, item or AWB" value="${esc(orderFilters.q)}">
    <div class="chips" style="margin-top:10px" id="payChips"></div>
    <div class="chips" style="margin-top:8px" id="statusChips"></div>
    <div id="body">${spinner()}</div>
  </div>`);

  chipRow('payChips', [
    ['all', 'All payments'], ['unpaid', 'Unpaid'], ['partial', 'Part paid'], ['paid', 'Paid'],
  ], orderFilters.pay, (v) => { orderFilters.pay = v; routes.orders(); });
  chipRow('statusChips', [
    ['all', 'Any status'], ['pending', 'To make'], ['ready', 'Ready'],
    ['delivered', 'Delivered'], ['dispatched', 'Shipped'],
  ], orderFilters.status, (v) => { orderFilters.status = v; routes.orders(); });

  const search = document.getElementById('q');
  let timer = null;
  search.addEventListener('input', () => {
    // Search when typing pauses. A request per keystroke would put eight
    // in flight for "Shravani" and show whichever came back last.
    clearTimeout(timer);
    timer = setTimeout(() => { orderFilters.q = search.value.trim(); loadOrders(); }, 350);
  });

  await loadOrders();
});

async function loadOrders() {
  const body = document.getElementById('body');
  if (!body) return;
  const data = await api('orders.php', 'list', {
    params: { q: orderFilters.q, pay: orderFilters.pay, status: orderFilters.status, limit: 40 },
  });
  const s = data.summary;
  body.innerHTML = `
    <div class="card" style="margin-top:12px;display:flex;justify-content:space-between">
      <div class="stat"><div class="l">Orders</div><div class="v" style="font-size:17px">${s.count}</div></div>
      <div class="stat"><div class="l">Billed</div><div class="v" style="font-size:17px">${moneyShort(s.total)}</div></div>
      <div class="stat"><div class="l">Collected</div><div class="v pos" style="font-size:17px">${moneyShort(s.paid)}</div></div>
      <div class="stat"><div class="l">Balance</div><div class="v ${s.balance > 0.5 ? 'neg' : ''}" style="font-size:17px">${moneyShort(s.balance)}</div></div>
    </div>
    ${data.orders.length ? `<div class="card" style="margin-top:10px">${data.orders.map(orderRow).join('')}</div>`
      : '<p class="muted center" style="margin-top:30px">Nothing matches these filters.</p>'}`;
}

/**
 * "Round Magnet x2, Keychain x1" -> ["Round Magnet x2", "Keychain x1"].
 *
 * The server joins lines with ", ", and a product name may carry a comma
 * of its own, so a split only counts where it follows a quantity.
 */
function splitItems(text) {
  if (!text) return [];
  const found = String(text).match(/.*? x\d+(?=, |$)/g);
  return found ? found.map((x) => x.replace(/^, /, '')) : [String(text)];
}

function orderRow(o) {
  const glyph = o.is_delivered ? '✓' : o.is_ready ? '▣' : '◷';
  const state = o.is_delivered ? 'Delivered' : o.is_ready ? 'Ready' : 'To make';
  const payTone = o.pay_status === 'paid' ? 'pos' : o.pay_status === 'partial' ? 'warn' : 'neg';
  const payLabel = o.pay_status === 'paid' ? 'Paid' : o.pay_status === 'partial' ? 'Part paid' : 'Unpaid';
  // One item per line, two at most; the rest are counted rather than
  // squeezed onto a line nobody can read.
  const items = splitItems(o.items_text);
  const shown = items.slice(0, 2);
  const more = items.length - shown.length;
  return `<button class="row" data-go="order/${o.id}" style="width:100%;text-align:left;align-items:flex-start">
    ${tile(o.is_walk_in ? 'Walk in' : o.name, glyph)}
    <div class="grow">
      <div class="t">${esc(o.is_walk_in ? 'Walk-in' : o.name)}</div>
      ${!o.is_walk_in && o.phone ? `<div class="s">${esc(o.phone)}</div>` : ''}
      <div class="s">${esc(o.order_no)} · ${esc(relativeDay(o.created_at))} · ${esc(state)}</div>
      ${shown.length ? `<div class="items">${shown.map((i) => `<div>• ${esc(i)}</div>`).join('')}${
        more > 0 ? `<div class="more">+${more} more item${more === 1 ? '' : 's'}</div>` : ''}</div>` : ''}
    </div>
    <div class="amt">${moneyShort(o.total)}
      <div class="s ${payTone}">${o.balance > 0.5 ? moneyShort(o.balance) + ' due' : payLabel}</div></div>
  </button>`;
}

function chipRow(id, options, current, onPick) {
  const el = document.getElementById(id);
  el.innerHTML = options.map(([v, label]) =>
    `<button class="chip" data-v="${esc(v)}" aria-pressed="${v === current}">${esc(label)}</button>`).join('');
  el.querySelectorAll('[data-v]').forEach((b) => { b.onclick = () => onPick(b.dataset.v); });
}

/* ── One order ────────────────────────────────────────────────── */

route('order', async (id) => {
  setHtml(`<div class="screen"><div class="head">
    <button class="back" data-go="orders">‹</button><h1 class="grow">Order</h1></div>
    <div id="body">${spinner()}</div></div>`);
  await paintOrder(id);
});

async function paintOrder(id) {
  const { order: o } = await api('orders.php', 'get', { params: { id } });
  const settled = o.balance <= 0.5;

  // Today's prices, only so a line sold at a different one can say so.
  // A sale keeps the price it was made at; showing both side by side is
  // what stops that looking like a mistake.
  let catalogueNow = {};
  try {
    const c = await api('catalog.php', 'products', { params: { include_hidden: 'true' } });
    (c.products || []).forEach((p) => { catalogueNow[p.name] = p.price; });
  } catch (e) { /* the order still renders without it */ }

  document.getElementById('body').innerHTML = `
    <div class="hero" style="${settled ? '' : 'background:linear-gradient(135deg,#B3261E,#7A1610)'}">
      <div class="row" style="border:none;padding:0">
        <span class="label grow">${settled ? 'Fully paid' : 'Balance due'}</span>
        ${o.is_walk_in ? '<span class="pill" style="background:rgba(255,255,255,.22);color:#fff">Walk-in</span>' : ''}
      </div>
      <div class="big">${money(settled ? o.total : o.balance)}</div>
      <div class="meta">${money(o.paid_amount)} collected of ${money(o.total)}</div>
    </div>

    <div class="btnrow">
      ${o.balance > 0.5 ? '<button class="btn" id="pay">Take payment</button>' : ''}
      <button class="btn ghost" data-go="bill/${o.id}">${o.has_bill ? 'Bill' : 'Make bill'}</button>
    </div>

    <section><h2 class="section">Status</h2>
      <div class="chips">
        <button class="chip" id="toggleReady" aria-pressed="${o.is_ready}">${o.is_ready ? 'Ready' : 'Mark ready'}</button>
        <button class="chip" id="toggleDelivered" aria-pressed="${o.is_delivered}">${o.is_delivered ? 'Delivered' : 'Mark delivered'}</button>
      </div>
    </section>

    <section><h2 class="section">Order</h2><div class="card">
      ${detailRow('Customer', o.is_walk_in ? 'Walk-in (no details)' : o.name)}
      ${o.phone ? detailRow('Phone', o.phone) : ''}
      ${detailRow('Number', o.order_no)}
      ${detailRow('Channel', o.event_name || 'Direct / walk-up')}
      ${detailRow('Taken', prettyDate(o.created_at))}
      ${o.notes ? detailRow('Notes', o.notes) : ''}
    </div></section>

    <section><h2 class="section">Items</h2><div class="card">
      ${o.items.map((i) => `<div class="row"><div class="grow">
        <div class="t" style="font-weight:500">${esc(i.item)} × ${i.quantity}</div>
        <div class="s">at ${money(i.unit_price)} each${
          catalogueNow[i.item] != null && Math.abs(catalogueNow[i.item] - i.unit_price) > 0.005
            ? ' · now ' + money(catalogueNow[i.item]) + ' in the catalogue' : ''}</div></div>
        <div class="amt">${money(i.line_total)}</div></div>`).join('')}
      <div class="row"><div class="grow s">Subtotal</div><div class="amt">${money(o.subtotal)}</div></div>
      ${o.extra_charge > 0.001 ? detailRow(o.extra_charge_reason || 'Extra charge', '+' + money(o.extra_charge)) : ''}
      ${o.discount > 0.001 ? detailRow(o.discount_reason || 'Discount', '-' + money(o.discount)) : ''}
      <div class="row"><div class="grow t">Total</div><div class="amt">${money(o.total)}</div></div>
    </div></section>

    ${o.event_id ? '' : `<section><h2 class="section">Shipping</h2><div class="card">
      ${o.awb ? `
        ${detailRow('Tracking number', o.awb)}
        ${o.dispatch_date ? detailRow('Dispatched', prettyDate(o.dispatch_date)) : ''}
        ${o.track_url ? `<div class="row"><div class="grow"><div class="s">Tracking link</div>
          <a href="${esc(o.track_url)}" target="_blank" rel="noopener" style="word-break:break-all;font-size:13px">${esc(o.track_url)}</a></div></div>
          <div class="btnrow">
            <a class="btn small" href="${esc(o.track_url)}" target="_blank" rel="noopener" style="flex:1">Track parcel</a>
            <button class="btn small ghost" id="copyTrack" style="flex:1">Copy link</button>
          </div>` : ''}`
        : '<p class="muted">Not shipped yet. Add the Delhivery tracking number (AWB) once the parcel is booked.</p>'}
      <label class="field"><span>Tracking number (AWB)</span>
        <input id="awb" value="${esc(o.awb || '')}" placeholder="e.g. 1234567890123" autocapitalize="characters"></label>
      <label class="field"><span>Dispatch date</span>
        <input id="awbDate" type="date" value="${esc(o.dispatch_date || today())}"></label>
      <div class="btnrow">
        <button class="btn small" id="saveAwb" style="flex:1">${o.awb ? 'Update tracking' : 'Save tracking'}</button>
        ${o.awb ? '<button class="btn small danger" id="clearAwb" style="flex:1">Clear</button>' : ''}
      </div>
    </div></section>`}

    <section><h2 class="section">Payments</h2><div class="card">
      ${o.payments.length ? o.payments.map((p) => `
        <div class="row"><div class="grow">
          <div class="t">${money(p.amount)}</div>
          <div class="s">${esc(String(p.mode).toUpperCase())} · ${esc(prettyDate(p.created_at))}${p.taken_by ? ' · ' + esc(p.taken_by) : ''}</div>
        </div>
        <button class="btn small danger" data-pay-delete="${p.id}">Remove</button></div>`).join('')
        : '<p class="muted">Nothing collected yet.</p>'}
    </div></section>

    ${o.whatsapp && o.whatsapp.order_link ? `<section><h2 class="section">Tell the customer</h2>
      <a class="btn ghost" href="${esc(o.whatsapp.order_link)}" target="_blank" rel="noopener">Send order summary on WhatsApp</a>
      ${o.whatsapp.dispatch_link ? `<a class="btn ghost" style="margin-top:8px" href="${esc(o.whatsapp.dispatch_link)}" target="_blank" rel="noopener">Send tracking details</a>` : ''}
    </section>` : ''}`;

  const pay = document.getElementById('pay');
  if (pay) pay.onclick = () => takePayment(o);
  const copyTrack = document.getElementById('copyTrack');
  if (copyTrack) copyTrack.onclick = async () => {
    try { await navigator.clipboard.writeText(o.track_url); toast('Tracking link copied.'); }
    catch (e) { prompt('Copy the tracking link:', o.track_url); }
  };
  const saveAwb = document.getElementById('saveAwb');
  if (saveAwb) {
    const send = async (awb, button) => {
      button.disabled = true;
      try {
        const r = await api('orders.php', 'dispatch', {
          body: { id: o.id, is_online: awb !== '', awb, dispatch_date: document.getElementById('awbDate').value || today() },
        });
        toast(r.message || 'Tracking saved.');
        await paintOrder(o.id);
      } catch (e) { toast(e.message); button.disabled = false; }
    };
    saveAwb.onclick = () => {
      const awb = document.getElementById('awb').value.trim().toUpperCase();
      if (!awb) { toast('Enter the tracking number first.'); return; }
      send(awb, saveAwb);
    };
    const clearAwb = document.getElementById('clearAwb');
    if (clearAwb) clearAwb.onclick = () => { if (confirm('Clear the tracking number?')) send('', clearAwb); };
  }
  document.getElementById('toggleReady').onclick = () => toggleOrder(o.id, 'is_ready');
  document.getElementById('toggleDelivered').onclick = () => toggleOrder(o.id, 'is_delivered');
  document.querySelectorAll('[data-pay-delete]').forEach((b) => {
    b.onclick = async () => {
      b.disabled = true;
      try {
        const r = await api('orders.php', 'delete_payment', { body: { id: o.id, payment_id: Number(b.dataset.payDelete) } });
        toast(r.message || 'Payment removed.');
        await paintOrder(o.id);
      } catch (e) { toast(e.message); b.disabled = false; }
    };
  });
}

function detailRow(label, value) {
  return `<div class="row"><div class="grow s">${label}</div><div class="amt">${esc(value)}</div></div>`;
}

async function toggleOrder(id, field) {
  try {
    const r = await api('orders.php', 'toggle', { body: { id: Number(id), field } });
    toast(r.message || 'Updated.');
    await paintOrder(id);
  } catch (e) { toast(e.message); }
}

async function takePayment(order) {
  const raw = prompt(`Balance ${money(order.balance)}. How much is being paid?`,
    String(Math.round(order.balance * 100) / 100));
  if (raw == null) return;
  const amount = Number(String(raw).replace(/[^\d.]/g, ''));
  if (!(amount > 0)) { toast('Enter an amount greater than zero.'); return; }
  const mode = (prompt('Cash, UPI, card or other?', 'cash') || 'cash').toLowerCase();
  try {
    const r = await api('orders.php', 'add_payment', {
      body: { id: order.id, amount, payment_mode: mode, note: '' },
    });
    toast(r.message || 'Payment recorded.');
    await paintOrder(order.id);
  } catch (e) { toast(e.message); }
}

/* ── New sale ─────────────────────────────────────────────────── */

let draft = null;

route('new', async () => {
  draft = draft && draft.keep ? draft : {
    lines: {}, name: '', phone: '', notes: '', paid: '', mode: 'cash', eventId: '',
    discount: '', discountReason: '', extra: '', extraReason: '',
  };
  draft.keep = false;

  setHtml(`<div class="screen"><div class="head"><h1 class="grow">New sale</h1></div>
    <div id="body">${spinner()}</div></div>`);

  const boot = await api('catalog.php', 'bootstrap');
  const products = boot.products || [];
  const events = boot.events || [];

  // Every section folds, products included. A sale is four decisions and
  // only one of them is on screen at a time, so nobody scrolls past the
  // catalogue to reach the phone number. Products start open because
  // that is the one section every sale needs.
  document.getElementById('body').innerHTML = `
    ${events.length ? `<div class="chips" id="eventChips"></div>` : ''}
    <div id="productFold" style="margin-top:10px"></div>
    <div style="margin-top:10px">${fold('Customer', 'Optional — leave empty for a walk-in', `
      <label class="field"><span>Name</span><input id="cname" value="${esc(draft.name)}"></label>
      <label class="field"><span>Phone</span>
        <input id="cphone" type="tel" inputmode="numeric" maxlength="10" value="${esc(draft.phone)}"></label>
      <label class="field"><span>Notes</span><input id="cnotes" value="${esc(draft.notes)}"></label>`,
      { open: !!(draft.name || draft.phone || draft.notes) })}</div>
    <div style="margin-top:10px" id="adjustFold"></div>
    <div style="margin-top:10px" id="paidFold"></div>
    <div id="summary"></div>
    <button class="btn" id="save" style="margin-top:14px">Save sale</button>`;

  if (events.length) {
    chipRow('eventChips', [['', 'Direct / walk-up']].concat(events.map((e) => [String(e.id), e.name])),
      draft.eventId, (v) => {
        stashCustomer();
        draft.eventId = v;
        // keep must be true BEFORE the re-render: routes.new() reads it
        // synchronously on its first line, so setting it afterwards was
        // always too late and threw the basket away.
        draft.keep = true;
        routes.new();
      });
  }

  const productFold = document.getElementById('productFold');
  const adjustFold = document.getElementById('adjustFold');
  const paidFold = document.getElementById('paidFold');

  // fold() renders a <details> and sets `open` from its argument every
  // time, so a repaint would slam the section shut while somebody was
  // typing in it. Remember whether it is open and hand that back.
  let adjustOpen = !!(draft.discount || draft.extra);

  /** Lines, and what they come to — one place, used by four renderers. */
  function basket() {
    const rows = Object.entries(draft.lines);
    const units = rows.reduce((n, [, q]) => n + q, 0);
    const subtotal = rows.reduce((sum, [name, qty]) => {
      const p = products.find((x) => x.name === name);
      return sum + (p ? p.price * qty : 0);
    }, 0);

    // Mirrors the server: an extra charge is never negative (that would
    // be a discount), and the discount ceiling is the whole charged
    // base, so a discount can cancel a delivery fee.
    const extra = Math.max(0, Number(draft.extra) || 0);
    const base = subtotal + extra;
    const discount = Math.min(Math.max(0, Number(draft.discount) || 0), base);
    const total = base - discount;
    return { rows, units, subtotal, extra, discount, total };
  }

  // Built once, then only its subtitle and badge are refreshed.
  //
  // Repainting the whole fold on every change was the obvious way and
  // the wrong one: the change event fires on blur, so tapping straight
  // from the discount box to the extra-charge box destroyed the second
  // input at the moment the tap was landing on it. Nothing visibly
  // broke, the keystrokes just went nowhere. Building it once means the
  // inputs are never replaced while somebody is using them.
  let adjustBuilt = false;

  function paintAdjust() {
    const b = basket();
    const bits = [];
    if (b.discount > 0) bits.push('-' + money(b.discount));
    if (b.extra > 0) bits.push('+' + money(b.extra));
    const subtitle = bits.length ? bits.join(' · ') : 'None';

    if (adjustBuilt) {
      adjustFold.querySelector('summary .s').textContent = subtitle;
      const badge = adjustFold.querySelector('summary .badge');
      if (badge) badge.textContent = bits.length ? String(bits.length) : '';
      return;
    }

    adjustFold.innerHTML = fold('Discount & extra charges', subtitle, `
      <label class="field"><span>Discount (₹)</span>
        <input id="disc" inputmode="decimal" value="${esc(draft.discount)}"></label>
      <label class="field"><span>Why the discount</span>
        <input id="discWhy" value="${esc(draft.discountReason)}"
               placeholder="e.g. regular customer"></label>
      <label class="field"><span>Extra charge (₹)</span>
        <input id="extra" inputmode="decimal" value="${esc(draft.extra)}"
               placeholder="delivery, rush, packing"></label>
      <label class="field"><span>Why the extra charge</span>
        <input id="extraWhy" value="${esc(draft.extraReason)}"></label>`,
      // A badge is always rendered, even when empty, so that later
      // repaints have something to write into.
      { open: !!(draft.discount || draft.extra), badge: ' ' });
    adjustBuilt = true;

    const bind = (id, key) => {
      const el = document.getElementById(id);
      el.addEventListener('input', () => { draft[key] = el.value; paintSummary(); });
    };
    bind('disc', 'discount');
    bind('discWhy', 'discountReason');
    bind('extra', 'extra');
    bind('extraWhy', 'extraReason');

    // Reflect the values the server will actually use, once the field
    // is left: a discount larger than the bill is capped, and a
    // negative extra charge is not a thing.
    ['disc', 'extra'].forEach((id) => {
      const el = document.getElementById(id);
      el.addEventListener('blur', () => {
        const bb = basket();
        if (id === 'disc' && (Number(draft.discount) || 0) !== bb.discount) {
          draft.discount = bb.discount ? String(bb.discount) : '';
          el.value = draft.discount;
        }
        if (id === 'extra' && (Number(draft.extra) || 0) !== bb.extra) {
          draft.extra = bb.extra ? String(bb.extra) : '';
          el.value = draft.extra;
        }
        paintSummary();
      });
    });
  }

  function paintPaid() {
    // Re-rendering replaces the input, which would drop the caret in the
    // middle of a number. If someone is typing in it, leave it alone —
    // the subtitle is stale for a moment, the keyboard is not.
    if (document.activeElement && document.activeElement.id === 'paid') return;
    const b = basket();
    paidFold.innerHTML = fold('Money taken now',
      b.total > 0 ? 'Bill comes to ' + money(b.total) : 'Cash, UPI, card or other', `
      <label class="field"><span>Amount</span>
        <input id="paid" inputmode="decimal" value="${esc(draft.paid)}"></label>
      <div class="chips" style="margin-top:10px" id="modeChips"></div>`,
      { open: !!draft.paid, badge: draft.paid ? money(Number(draft.paid) || 0) : '' });

    // Re-rendering the fold throws the old inputs away, so both the value
    // and the handlers are re-bound here rather than once at startup.
    const paidInput = document.getElementById('paid');
    paidInput.addEventListener('input', () => { draft.paid = paidInput.value; });
    paidInput.addEventListener('change', paintPaid);
    const modes = [['cash', 'Cash'], ['upi', 'UPI'], ['card', 'Card'], ['other', 'Other']];
    chipRow('modeChips', modes, draft.mode, (v) => { draft.mode = v; paintPaid(); });
  }

  function paintProducts() {
    const b = basket();
    productFold.innerHTML = fold('Products',
      b.units ? b.units + (b.units === 1 ? ' item' : ' items') + ' · ' + money(b.subtotal)
              : 'Tap + to add to the bill',
      '<div id="products"></div>',
      { open: true, badge: b.units ? String(b.units) : '' });

    const list = document.getElementById('products');
    list.innerHTML = products.map((p) => {
      const qty = draft.lines[p.name] || 0;
      return `<div class="row">
        ${p.image_url ? `<img class="tile" src="${esc(p.image_url)}" alt="" style="object-fit:cover">` : tile(p.name)}
        <div class="grow"><div class="t">${esc(p.name)}</div>
          <div class="s">${money(p.price)}${qty ? ' · ' + money(p.price * qty) : ''}</div></div>
        <div style="display:flex;align-items:center;gap:8px">
          ${qty ? `<button class="btn small ghost" data-minus="${esc(p.name)}">−</button>
                   <b style="min-width:18px;text-align:center">${qty}</b>` : ''}
          <button class="btn small" data-plus="${esc(p.name)}">+</button>
        </div></div>`;
    }).join('');
    list.querySelectorAll('[data-plus]').forEach((b) => b.onclick = () => {
      const n = b.dataset.plus; draft.lines[n] = (draft.lines[n] || 0) + 1; paintProducts(); paintSummary();
    });
    list.querySelectorAll('[data-minus]').forEach((b) => b.onclick = () => {
      const n = b.dataset.minus;
      draft.lines[n] = (draft.lines[n] || 0) - 1;
      if (draft.lines[n] <= 0) delete draft.lines[n];
      paintProducts(); paintSummary();
    });
  }

  function paintSummary() {
    // Shown so the counter can read the total back before taking money.
    // The server recomputes it from the catalogue regardless — this is a
    // display, not the price.
    const { rows, subtotal, extra, discount, total } = basket();
    paintAdjust();
    paintPaid();
    document.getElementById('summary').innerHTML = rows.length ? `
      <section><h2 class="section">This bill</h2><div class="card">
        ${rows.map(([n, q]) => {
          const p = products.find((x) => x.name === n);
          return detailRow(`${esc(n)} × ${q}`, money(p ? p.price * q : 0));
        }).join('')}
        ${(extra > 0 || discount > 0) ? detailRow('Subtotal', money(subtotal)) : ''}
        ${extra > 0 ? detailRow('Extra charge' + (draft.extraReason ? ' — ' + esc(draft.extraReason) : ''),
                                '+' + money(extra)) : ''}
        ${discount > 0 ? detailRow('Discount' + (draft.discountReason ? ' — ' + esc(draft.discountReason) : ''),
                                   '-' + money(discount)) : ''}
        <div class="row"><div class="grow t">Total</div><div class="amt">${money(total)}</div></div>
      </div></section>` : '';
  }

  paintProducts();
  paintSummary();

  const phone = document.getElementById('cphone');
  phone.addEventListener('input', () => { phone.value = phone.value.replace(/\D/g, '').slice(0, 10); });

  // The customer fold survives a re-render only because its values are
  // copied onto the draft first; the inputs themselves are thrown away.
  function stashCustomer() {
    const read = (id) => (document.getElementById(id) || {}).value || '';
    draft.name = read('cname').trim();
    draft.phone = read('cphone').trim();
    draft.notes = read('cnotes').trim();
  }
  ['cname', 'cphone', 'cnotes'].forEach((id) => {
    document.getElementById(id).addEventListener('change', stashCustomer);
  });

  document.getElementById('save').onclick = async () => {
    const button = document.getElementById('save');
    stashCustomer();
    const items = Object.entries(draft.lines).map(([item, quantity]) => ({ item, quantity }));
    if (!items.length) { toast('Add at least one product.'); return; }
    button.disabled = true; button.textContent = 'Saving…';
    try {
      const r = await api('orders.php', 'create', {
        body: {
          items,
          name: draft.name,
          phone: draft.phone,
          notes: draft.notes,
          event_id: draft.eventId || null,
          discount: Number(draft.discount) || 0,
          discount_reason: draft.discountReason,
          extra_charge: Number(draft.extra) || 0,
          extra_charge_reason: draft.extraReason,
          paid_amount: Number(draft.paid || 0),
          payment_mode: draft.mode,
        },
      });
      draft = null;
      toast(r.message || 'Order saved.');
      go('order/' + r.order.id);
    } catch (e) {
      toast(e.message);
      button.disabled = false; button.textContent = 'Save sale';
    }
  };
});

/* ── Bill ─────────────────────────────────────────────────────── */

route('bill', async (orderId) => {
  setHtml(`<div class="screen"><div class="head">
    <button class="back" data-go="order/${esc(orderId)}">‹</button><h1 class="grow">Bill</h1></div>
    <div id="body">${spinner()}</div></div>`);

  const { bill } = await api('bills.php', 'get', { params: { order_id: orderId } });
  // The printable page is served by the API; showing it in a frame means
  // the phone and the paper are the same document, not two renderings.
  const printUrl = new URL('bills.php', store.api);
  printUrl.searchParams.set('action', 'html');
  printUrl.searchParams.set('order_id', orderId);
  printUrl.searchParams.set('auth', store.token);

  const rollUrl = new URL(printUrl);
  rollUrl.searchParams.set('size', 'thermal');

  document.getElementById('body').innerHTML = `
    <div class="card">
      <div class="row" style="border:none;padding-top:0">
        <div class="grow"><div class="t">${esc(bill.bill_no)}</div>
          <div class="s">${esc(prettyDate(bill.issued_at))}</div></div>
        <div class="amt">${money(bill.order.total)}</div>
      </div>
    </div>
    <div class="btnrow">
      <a class="btn" href="${esc(printUrl.href)}" target="_blank" rel="noopener">Open &amp; print</a>
      <button class="btn ghost" id="share">Share link</button>
    </div>
    <div class="btnrow">
      <a class="btn ghost" href="${esc(rollUrl.href)}" target="_blank" rel="noopener">Counter roll</a>
      <button class="btn ghost" id="reissue">Re-issue</button>
    </div>
    <section><h2 class="section">Preview</h2>
      <iframe class="billframe" src="${esc(printUrl.href)}" title="Bill preview"></iframe></section>`;

  document.getElementById('share').onclick = async () => {
    const text = `Bill ${bill.bill_no} from ${bill.business.name}\n`
      + `Order ${bill.order.order_no} · ${money(bill.order.total)}`
      + (bill.order.balance > 0.001 ? `\nBalance due: ${money(bill.order.balance)}` : '')
      + (bill.public_url ? `\n\n${bill.public_url}` : '');
    // The Web Share API is the native sheet on iOS and Android; the
    // clipboard is the fallback on a desktop browser.
    if (navigator.share) {
      try { await navigator.share({ title: 'Bill ' + bill.bill_no, text }); return; } catch (e) { /* cancelled */ }
    }
    try { await navigator.clipboard.writeText(text); toast('Bill details copied.'); }
    catch (e) { toast('Could not share on this device.'); }
  };

  document.getElementById('reissue').onclick = async () => {
    try {
      const r = await api('bills.php', 'issue', { body: { order_id: Number(orderId), refresh: true } });
      toast(r.message || 'Bill re-issued.');
      routes.bill(orderId);
    } catch (e) { toast(e.message); }
  };
});

route('bills', async () => {
  setHtml(`<div class="screen"><div class="head">
    <button class="back" data-go="home">‹</button><h1 class="grow">Bill book</h1></div>
    <div id="body">${spinner()}</div></div>`);
  const { bills } = await api('bills.php', 'list', { params: { limit: 60 } });
  document.getElementById('body').innerHTML = bills.length ? `<div class="card">${bills.map((b) => `
    <button class="row" data-go="bill/${b.order_id}" style="width:100%;text-align:left">
      ${tile(b.bill_no, svg('bills', 18))}
      <div class="grow"><div class="t">${esc(b.bill_no)}</div>
        <div class="s">${esc(b.customer)} · ${esc(b.order_no)} · ${esc(prettyDate(b.issued_at))}</div></div>
      <div class="amt">${moneyShort(b.total)}${b.balance > 0.5
        ? `<div class="s neg">${moneyShort(b.balance)} due</div>` : ''}</div>
    </button>`).join('')}</div>`
    : '<p class="muted center" style="margin-top:30px">No bills issued yet.</p>';
});

/* ── Stats ────────────────────────────────────────────────────── */

let statsRange = 'month';

route('stats', async () => {
  setHtml(`<div class="screen"><div class="head"><h1 class="grow">Statistics</h1></div>
    <div class="chips" id="ranges"></div><div id="body">${spinner()}</div></div>`);
  paintRanges('ranges', statsRange, (id) => { statsRange = id; routes.stats(); });

  const range = RANGES.find((r) => r.id === statsRange);
  const params = { from: range.from(), to: today() };
  const [dash, breakdown] = await Promise.all([
    api('stats.php', 'dashboard', { params }),
    api('stats.php', 'breakdown', { params }),
  ]);
  const h = dash.headline;

  document.getElementById('body').innerHTML = `
    <div class="grid2" style="margin-top:8px">
      ${statCard('Revenue', moneyShort(h.revenue), changeText(dash.change.revenue))}
      ${statCard('Item cost', moneyShort(h.cogs), 'what the goods cost us')}
      ${statCard('Product profit', moneyShort(h.product_profit),
        h.margin_pct != null ? h.margin_pct + '% margin' : 'revenue − item cost', 'pos')}
      ${statCard('Discounts', moneyShort(h.discount), 'across ' + h.orders + ' orders')}
    </div>

    ${listCard('What sold', breakdown.products.slice(0, 8).map((p) => [
      `${p.item} (${p.qty})`, moneyShort(p.revenue), moneyShort(p.profit) + ' profit'])) }
    ${listCard('How customers paid', breakdown.modes.map((m) => [
      String(m.mode).toUpperCase(), moneyShort(m.amount), m.count + ' payments'])) }
    ${listCard('Where it sold', breakdown.channels.map((c) => [
      c.channel, moneyShort(c.revenue), c.orders + ' orders'])) }
    ${breakdown.customers.length ? listCard('Top customers', breakdown.customers.slice(0, 8).map((c) => [
      c.name, moneyShort(c.spent), c.phone + ' · ' + c.orders + ' orders'])) : ''}`;
});

function listCard(title, rows) {
  if (!rows.length) return '';
  return `<section><h2 class="section">${esc(title)}</h2><div class="card">
    ${rows.map(([label, amount, sub]) => `<div class="row">${tile(label)}
      <div class="grow"><div class="t">${esc(label)}</div><div class="s">${esc(sub || '')}</div></div>
      <div class="amt">${esc(amount)}</div></div>`).join('')}
  </div></section>`;
}

/* ── Wholesale buyers ─────────────────────────────────────────── */

/*
 * A notebook, kept away from the money. Nothing recorded here is an
 * order: it is not revenue, not profit, it does not appear on Money or
 * Stats and it does not move stock. Prices are typed each time and are
 * never looked up from the catalogue, because a wholesale price is
 * negotiated and must not move when the catalogue moves.
 */

route('wholesale', async (id) => {
  // #wholesale shows the list, #wholesale/5 shows that buyer. One entry
  // in the route table, because route() assigns rather than appends and
  // a second registration would silently replace the first.
  if (id) return wholesaleBuyer(id);

  setHtml(`<div class="screen"><div class="head">
    <button class="back" data-go="home">‹</button><h1 class="grow">Wholesale buyers</h1></div>
    <div id="body">${spinner()}</div></div>`);

  const d = await api('wholesale.php', 'list');
  const body = document.getElementById('body');

  if (!d.ready) {
    body.innerHTML = errorBox(d.message || 'Wholesale is not set up on the server yet.');
    return;
  }

  const list = d.customers || [];
  body.innerHTML = `
    <p class="muted" style="margin-bottom:12px">
      What each bulk buyer has taken, and at what. Not counted in revenue or profit.
    </p>
    <button class="btn" id="addBuyer">Add a buyer</button>
    ${list.length ? `<section><h2 class="section">${list.length} buyer${list.length === 1 ? '' : 's'}</h2>
      <div class="card">${list.map((c) => `
        <button class="row" data-go="wholesale/${c.id}" style="width:100%;text-align:left">
          ${tile(c.name)}
          <div class="grow">
            <div class="t">${esc(c.name)}${c.is_active ? '' : ' <span class="pill">hidden</span>'}</div>
            <div class="s wrap">${esc([c.shop, c.place].filter(Boolean).join(' · ') || 'No shop recorded')}</div>
            <div class="s wrap">${c.visits} visit${c.visits === 1 ? '' : 's'}${
              c.last_visit ? ' · last ' + esc(prettyDate(c.last_visit)) : ''}</div>
          </div>
          <div class="amt">${money(c.taken)}</div>
        </button>`).join('')}</div></section>`
      : '<p class="muted center" style="margin-top:24px">No buyers yet. Add one, then record what they take each time they come.</p>'}`;

  document.getElementById('addBuyer').onclick = () => buyerSheet();
});

/** Add a buyer, or edit one. */
function buyerSheet(existing = null) {
  const v = existing || { id: 0, name: '', phone: '', shop: '', place: '', notes: '' };
  const sheet = openSheet(existing ? 'Edit buyer' : 'Add a buyer', `
    <div class="card">
      <label class="field"><span>Name</span><input id="bname" value="${esc(v.name)}"></label>
      <label class="field"><span>Phone</span>
        <input id="bphone" type="tel" inputmode="numeric" maxlength="10" value="${esc(v.phone || '')}"></label>
      <label class="field"><span>Shop</span><input id="bshop" value="${esc(v.shop || '')}"></label>
      <label class="field"><span>Place</span><input id="bplace" value="${esc(v.place || '')}"></label>
      <label class="field"><span>Note — optional</span><input id="bnotes" value="${esc(v.notes || '')}"></label>
    </div>
    <button class="btn" id="bsave" style="margin-top:12px">${existing ? 'Save' : 'Add buyer'}</button>`);

  document.getElementById('bsave').onclick = async () => {
    const button = document.getElementById('bsave');
    const name = document.getElementById('bname').value.trim();
    if (!name) { toast('A name is required.'); return; }
    button.disabled = true; button.textContent = 'Saving…';
    try {
      const body = {
        name,
        phone: document.getElementById('bphone').value.trim(),
        shop: document.getElementById('bshop').value.trim(),
        place: document.getElementById('bplace').value.trim(),
        notes: document.getElementById('bnotes').value.trim(),
      };
      if (existing) body.id = existing.id;
      const r = await api('wholesale.php', existing ? 'update_customer' : 'add_customer', { body });
      sheet.close();
      toast(r.message || 'Saved.');
      if (existing) go('wholesale/' + existing.id); else go('wholesale/' + r.id);
    } catch (e) {
      toast(e.message);
      button.disabled = false; button.textContent = existing ? 'Save' : 'Add buyer';
    }
  };
}

/** One buyer: what they take, and every visit. */
async function wholesaleBuyer(id) {
  setHtml(`<div class="screen"><div class="head">
    <button class="back" data-go="wholesale">‹</button><h1 class="grow">Buyer</h1></div>
    <div id="body">${spinner()}</div></div>`);

  const d = await api('wholesale.php', 'get', { params: { id } });
  const c = d.customer, t = d.totals;

  document.getElementById('body').innerHTML = `
    <div class="hero">
      <div class="label">${esc([c.shop, c.place].filter(Boolean).join(' · ') || 'Wholesale buyer')}</div>
      <div class="big">${esc(c.name)}</div>
      <div class="meta">${t.visits} visit${t.visits === 1 ? '' : 's'} · ${t.units} pieces · ${money(t.value)}</div>
    </div>

    <div class="grid2" style="margin-top:10px">
      <button class="btn" id="addVisit">Record what they took</button>
      <button class="btn ghost" id="editBuyer">Edit details</button>
    </div>
    ${c.phone ? `<a class="btn ghost" style="margin-top:8px;display:block;text-align:center"
        href="tel:${esc(c.phone)}">Call ${esc(c.phone)}</a>` : ''}

    ${d.summary.length ? `<section><h2 class="section">What they take</h2><div class="card">
      ${d.summary.map((r) => `<div class="row">${tile(r.item)}
        <div class="grow"><div class="t">${esc(r.item)}</div>
          <div class="s wrap">${r.qty} pieces over ${r.times} visit${r.times === 1 ? '' : 's'}</div>
          <div class="s wrap">${r.low === r.high ? money(r.low) : money(r.low) + ' – ' + money(r.high)} each
            · last ${esc(prettyDate(r.last_date))}</div></div>
        <div class="amt">${money(r.total)}</div></div>`).join('')}
    </div></section>` : ''}

    ${d.visits.length ? `<section><h2 class="section">${d.visits.length} visit${d.visits.length === 1 ? '' : 's'}</h2>
      ${d.visits.map((v) => `<div class="card" style="margin-bottom:10px">
        <div class="row" style="border:none">
          <div class="grow"><div class="t">${esc(prettyDate(v.date))}</div>
            ${v.note ? `<div class="s">${esc(v.note)}</div>` : ''}</div>
          <div class="amt">${money(v.total)}</div>
        </div>
        ${v.items.map((l) => detailRow(`${esc(l.item)} × ${l.quantity}`,
            money(l.unit_price) + '  ·  ' + money(l.line_total))).join('')}
        <button class="btn ghost small" data-visit-del="${v.id}" style="margin-top:8px">Remove this entry</button>
      </div>`).join('')}</section>`
      : '<p class="muted center" style="margin-top:20px">Nothing recorded yet.</p>'}`;

  document.getElementById('addVisit').onclick = () => visitSheet(c);
  document.getElementById('editBuyer').onclick = () => buyerSheet(c);
  document.getElementById('body').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-visit-del]');
    if (!b) return;
    if (!confirm('Remove this entry?')) return;
    try {
      const r = await api('wholesale.php', 'delete_visit', { body: { id: Number(b.dataset.visitDel) } });
      toast(r.message || 'Removed.');
      wholesaleBuyer(id);
    } catch (err) { toast(err.message); }
  });
}

/**
 * Record a visit. Lines are added one at a time and priced by hand —
 * there is deliberately no lookup, because the whole point is the price
 * that was agreed on the day.
 */
function visitSheet(customer) {
  const lines = [{ item: '', quantity: '1', unit_price: '' }];

  const sheet = openSheet('What did ' + customer.name + ' take?', `
    <div class="card">
      <label class="field"><span>Date</span><input id="vdate" type="date" value="${today()}"></label>
      <label class="field"><span>Note — optional</span>
        <input id="vnote" placeholder="e.g. paid cash, collected himself"></label>
    </div>
    <div id="vlines"></div>
    <button class="btn ghost" id="vadd" style="margin-top:10px">+ Another product</button>
    <div class="row" style="border:none;padding:12px 0">
      <div class="grow t">Total</div><div class="amt" id="vtotal">₹0.00</div></div>
    <button class="btn" id="vsave">Save entry</button>`);

  function paint() {
    document.getElementById('vlines').innerHTML = lines.map((l, i) => `
      <div class="card" style="margin-top:10px">
        <label class="field"><span>Product</span>
          <input data-i="${i}" data-k="item" value="${esc(l.item)}" placeholder="type anything"></label>
        <div class="grid2">
          <label class="field"><span>Qty</span>
            <input data-i="${i}" data-k="quantity" inputmode="numeric" value="${esc(l.quantity)}"></label>
          <label class="field"><span>Price each (₹)</span>
            <input data-i="${i}" data-k="unit_price" inputmode="decimal" value="${esc(l.unit_price)}"></label>
        </div>
        ${lines.length > 1 ? `<button class="btn ghost small" data-drop="${i}">Remove</button>` : ''}
      </div>`).join('');

    // Bound after each repaint, because the markup above replaced them.
    document.querySelectorAll('#vlines input').forEach((el) => {
      el.addEventListener('input', () => {
        lines[Number(el.dataset.i)][el.dataset.k] = el.value;
        total();
      });
    });
    document.querySelectorAll('#vlines [data-drop]').forEach((b) => {
      b.onclick = () => { lines.splice(Number(b.dataset.drop), 1); paint(); };
    });
    total();
  }

  function total() {
    const sum = lines.reduce((n, l) =>
      n + (parseInt(l.quantity) || 0) * (parseFloat(l.unit_price) || 0), 0);
    document.getElementById('vtotal').textContent = money(sum);
  }

  document.getElementById('vadd').onclick = () => { lines.push({ item: '', quantity: '1', unit_price: '' }); paint(); };
  paint();

  document.getElementById('vsave').onclick = async () => {
    const button = document.getElementById('vsave');
    const items = lines
      .filter((l) => l.item.trim() !== '')
      .map((l) => ({
        item: l.item.trim(),
        quantity: parseInt(l.quantity) || 0,
        unit_price: parseFloat(l.unit_price) || 0,
      }));
    if (!items.length) { toast('Add at least one product.'); return; }
    if (items.some((i) => i.quantity < 1)) { toast('Every line needs a quantity of at least 1.'); return; }

    button.disabled = true; button.textContent = 'Saving…';
    try {
      const r = await api('wholesale.php', 'add_visit', {
        body: {
          customer_id: customer.id,
          visit_date: document.getElementById('vdate').value || today(),
          note: document.getElementById('vnote').value.trim(),
          items,
        },
      });
      sheet.close();
      toast(r.message || 'Recorded.');
      wholesaleBuyer(customer.id);
    } catch (e) {
      toast(e.message);
      button.disabled = false; button.textContent = 'Save entry';
    }
  };
}

/* ── Money — the investment summary ───────────────────────────── */

route('money', async () => {
  setHtml(`<div class="screen"><div class="head"><h1 class="grow">Money</h1></div>
    <div id="body">${spinner()}</div></div>`);
  const d = await api('finance.php', 'overview');
  const t = d.totals;

  document.getElementById('body').innerHTML = `
    <div class="hero">
      <div class="label">Total net invested</div>
      <div class="big">${money(t.contribution)}</div>
      <div class="meta">across ${t.partners} partners · ${Math.round(t.share_pct)}% share each</div>
    </div>

    <div class="grid2" style="margin-top:10px">
      ${statCard('Paid from pocket', moneyShort(t.paid), '')}
      ${statCard('Remaining', moneyShort(t.remaining), moneyShort(t.paid) + ' − ' + moneyShort(t.credited))}
      ${statCard('Credited back', moneyShort(t.credited), '')}
      ${statCard('In accounts', moneyShort(t.balance), 'credited less drawn', t.balance >= 0 ? 'pos' : 'neg')}
    </div>

    ${d.uncredited_offline.orders ? `<div class="card" style="margin-top:10px">
      <div class="t">Offline sales not yet credited</div>
      <p class="muted">${money(d.uncredited_offline.amount)} across ${d.uncredited_offline.orders}
        walk-up orders is sitting outside anyone's account. Credit it on the website.</p></div>` : ''}

    <button class="btn ghost" data-go="movements" style="margin-top:10px">
      Movements — the account ledger</button>

    <section><h2 class="section">Per-partner breakdown</h2>
      <div class="card"><p class="muted" style="margin-bottom:8px">
        Remaining = Paid − Credited. Net invested = Remaining + settle-up. Account balance = Credited − Debited.</p>
      <div class="scroller"><table>
        <thead><tr><th>Partner</th><th>Paid</th><th>Credited</th><th>Debited</th>
          <th>Remaining</th><th>Net invested</th><th>Balance</th></tr></thead>
        <tbody>${d.partners.map((p) => `<tr>
          <td class="b">${esc(p.name)}</td><td>${money(p.paid)}</td><td>${money(p.credited)}</td>
          <td>${money(p.debited)}</td><td>${money(p.remaining)}</td>
          <td class="b">${money(p.contribution)}</td>
          <td class="${p.balance >= 0 ? 'pos' : 'neg'}">${money(p.balance)}</td></tr>`).join('')}
          <tr><td class="b">Total</td><td class="b">${money(t.paid)}</td><td class="b">${money(t.credited)}</td>
          <td class="b">${money(t.debited)}</td><td class="b">${money(t.remaining)}</td>
          <td class="b">${money(t.contribution)}</td><td class="b">${money(t.balance)}</td></tr>
        </tbody></table></div></div></section>

    <section><h2 class="section">Equal share &amp; settle-up</h2>
      <div class="card"><p class="muted">Each partner carries ${Math.round(t.share_pct)}% of both sides.
        Investment gap compares what they paid against ${money(t.fair_paid)}; credit gap compares what
        they drew against ${money(t.fair_credited)} — drawing less counts in their favour. The three
        add up to the Net.</p>
      <div class="scroller" style="margin-top:8px"><table>
        <thead><tr><th>Partner</th><th>Paid</th><th>Investment gap</th><th>Credited</th>
          <th>Credit gap</th><th>Settle-up</th><th>Net</th><th>Position</th></tr></thead>
        <tbody>${d.partners.map((p) => `<tr>
          <td class="b">${esc(p.name)}</td><td>${money(p.paid)}</td>
          <td class="${p.inv_gap >= 0 ? 'pos' : 'neg'}">${signed(p.inv_gap)}</td>
          <td>${money(p.credited)}</td>
          <td class="${p.cred_gap >= 0 ? 'pos' : 'neg'}">${signed(p.cred_gap)}</td>
          <td>${Math.abs(p.adj_gap) < 0.01 ? '—' : signed(p.adj_gap)}</td>
          <td class="b ${p.gap >= 0 ? 'pos' : 'neg'}">${signed(p.gap)}</td>
          <td>${Math.abs(p.gap) < 0.01 ? 'even'
            : p.gap > 0 ? 'overpaid — owed ' + money(p.gap) : 'underpaid — owes ' + money(-p.gap)}</td>
        </tr>`).join('')}</tbody></table></div></div></section>

    ${d.settle_invest.length ? `<section><h2 class="section">To even up</h2><div class="card">
      ${d.settle_invest.map((s) => `<div class="row">${tile(s.from)}
        <div class="grow"><div class="t">${esc(s.from)} → ${esc(s.to)}</div>
          <div class="s">closes the contribution gap</div></div>
        <div class="amt">${moneyShort(s.amount)}</div></div>`).join('')}
      <p class="muted" style="margin-top:8px">Record settlements on the website — this screen shows the plan.</p>
    </div></section>` : ''}

    <section><h2 class="section">Profit &amp; distribution</h2><div class="card">
      ${detailRow('Sales through orders', money(d.business.revenue_orders))}
      ${detailRow('Other channels', money(d.business.revenue_other))}
      ${(d.business.sources && d.business.sources.by_source || []).map((x) =>
        `<div class="row" style="padding:3px 0;border:none">
           <div class="grow s" style="padding-left:14px">${esc(x.source)}${
             x.count ? ' · ' + x.count : ''}</div>
           <div class="s">${money(x.amount)}</div></div>`).join('')}
      <div class="row"><div class="grow t">Revenue (all sales)</div>
        <div class="amt">${money(d.business.revenue)}</div></div>
      ${detailRow('Expenses', money(d.business.expenses))}
      <div class="row"><div class="grow t">Business profit</div>
        <div class="amt ${d.business.profit >= 0 ? 'pos' : 'neg'}">${money(d.business.profit)}</div></div>
      ${detailRow('Already distributed', money(d.business.distributed))}
      ${detailRow('Undistributed', money(d.business.remaining))}
      ${d.business.remaining <= 0.5 ? `<p class="muted" style="margin-top:8px">
        Nothing to distribute yet. This counts every expense, including stock and equipment, so it
        stays negative until those purchases have been earned back.</p>` : ''}
      ${d.business.sources ? `<p class="muted" style="margin-top:8px">
        Money credited into partner accounts from offline sales
        (${money(d.business.sources.already_counted.offline_credits)}) and event sales
        (${money(d.business.sources.already_counted.event_credits)}) is order money moving into an
        account, not new money, so it is counted once — in the orders line above.</p>` : ''}
    </div>
    ${revenueWorking(d.revenue_breakdown, d.business)}</section>

    ${partnerBoxes(d.partner_detail)}

    ${d.categories.length ? `<section><h2 class="section">Expenses by category</h2><div class="card">
      <p class="muted">Pocket-funded purchases only — total ${money(d.category_total)}.</p>
      ${d.categories.map((c) => {
        const pct = d.category_total > 0 ? (c.net / d.category_total * 100) : 0;
        const colour = tileColour(c.category);
        return `<div style="margin-top:12px">
          <div class="row" style="border:none;padding:0 0 5px">
            <div class="grow t" style="font-weight:500">${esc(c.category)}</div>
            <div class="amt">${money(c.net)}</div></div>
          <div style="height:8px;border-radius:99px;background:var(--line);overflow:hidden">
            <div style="height:100%;width:${pct.toFixed(1)}%;background:${colour};border-radius:99px"></div></div>
          <div class="s">${pct.toFixed(1)}% of spending · ${c.count}×</div></div>`;
      }).join('')}
    </div></section>` : ''}`;
});

/**
 * Why "Revenue (all sales)" is the number it is.
 *
 * The same word means four different things across this app, and a
 * partner comparing the Money screen against Stats or the order list has
 * no way to tell which one they are reading. Rather than explain it in
 * support each time, the screen shows its own arithmetic: what the
 * headline adds up from, and every nearby figure it is NOT.
 */
function revenueWorking(r, business) {
  // An older api/finance.php has no revenue_breakdown at all. Rendering
  // nothing here is what made a stale upload look like an app that had
  // not changed, so it says which file is behind instead.
  if (!r || !r.orders) {
    return `<div class="card" style="margin-top:10px">
      <div class="t">Where this number comes from</div>
      <p class="muted warn">The working behind this figure needs a newer
        <code>api/finance.php</code> than the server has. Upload the <code>api/</code> folder and
        it will appear here. Settings &rsaquo; Version says which parts are behind.</p></div>`;
  }
  const body = `
    <p class="muted">Every order ever booked, at its billed total — not what has been
      collected, and not only this year. ${r.orders} orders${r.first_order
        ? ' from ' + esc(prettyDate(r.first_order)) + ' to ' + esc(prettyDate(r.last_order)) : ''}.</p>

    <div class="row" style="margin-top:6px"><div class="grow t" style="font-weight:500">Items, before adjustments</div>
      <div class="amt">${money(r.subtotal)}</div></div>
    ${detailRow('Less discounts given', '-' + money(r.discount))}
    ${detailRow('Plus delivery and extras', '+' + money(r.extra))}
    <div class="row"><div class="grow t">Revenue (all sales)</div>
      <div class="amt">${money(r.total)}</div></div>

    <h3 class="section" style="margin-top:16px;font-size:14px">Numbers this is often confused with</h3>
    <div class="row"><div class="grow"><div class="t" style="font-weight:500">Collected so far</div>
      <div class="s">money actually received${r.outstanding > 0.5
        ? ' · ' + money(r.outstanding) + ' still owed' : ' · nothing outstanding'}</div></div>
      <div class="amt">${money(r.collected)}</div></div>
    <div class="row"><div class="grow"><div class="t" style="font-weight:500">Credited to partner accounts</div>
      <div class="s">${r.credited_orders} of ${r.orders} orders · ${money(r.uncredited)} across
        ${r.uncredited_orders} orders is in nobody\'s account yet</div></div>
      <div class="amt">${money(r.credited)}</div></div>
    <div class="row"><div class="grow"><div class="t" style="font-weight:500">This financial year (${esc(r.year_label)})</div>
      <div class="s">${r.this_year.orders} orders since April — what a year-to-date report shows</div></div>
      <div class="amt">${money(r.this_year.amount)}</div></div>
    <div class="row"><div class="grow"><div class="t" style="font-weight:500">This month</div>
      <div class="s">${r.this_month.orders} orders — close to what the Stats screen shows on its default range</div></div>
      <div class="amt">${money(r.this_month.amount)}</div></div>

    <p class="muted" style="margin-top:12px">The Stats screen totals only the orders inside the range
      picked at the top of it, so its revenue is smaller than this one unless the range covers
      everything. Business profit above subtracts <b>all</b> expenses ever recorded
      (${money(business.expenses)}) from <b>all</b> revenue, so a young business reads negative
      until the stock and equipment it already paid for have been sold on.</p>`;

  return `<div style="margin-top:10px">${fold('Where this number comes from',
    money(r.total) + ' across ' + r.orders + ' orders', body)}</div>`;
}

/**
 * One box per partner, with every figure that concerns them.
 *
 * The two halves are deliberately kept apart and labelled, because they
 * are the thing most easily confused: what a partner put IN from their
 * own pocket (investment) is not the same as what the business EARNED
 * for them (their quarter of the profit), and neither is the same as
 * what has actually reached their account (credited, less drawn). A
 * partner can be owed profit and have drawn nothing.
 */
function partnerBoxes(pd) {
  if (!pd || !pd.partners || !pd.partners.length) return '';
  return `<section><h2 class="section">Each partner in detail</h2>
    <p class="muted" style="margin-bottom:8px">${pd.count} partners, ${pd.share_pct}% each.
      Two totals divide ${pd.count} ways: ${money(pd.total_paid)} paid from pocket
      (${money(pd.fair_paid)} each) and ${money(pd.revenue)} of revenue across every channel
      (${money(pd.revenue_share)} each).</p>
    ${pd.partners.map((x) => `
      <div class="card" style="margin-bottom:10px">
        <div class="row" style="border:none;padding-top:0">
          ${tile(x.name)}
          <div class="grow"><div class="t">${esc(x.name)}</div>
            <div class="s">${x.position === 'even' ? 'square with the others'
              : x.position === 'owed' ? 'owed ' + money(x.investment_gap)
              : 'owes ' + money(-x.investment_gap)}</div></div>
        </div>

        <div class="s" style="margin-top:6px;font-weight:600;color:var(--rose)">PAID FROM OWN POCKET</div>
        ${detailRow('They paid', money(x.paid))}
        ${detailRow('Equal share of ' + money(pd.total_paid), money(x.fair_paid))}
        <div class="row"><div class="grow t">Over or under</div>
          <div class="amt ${x.paid_gap >= 0 ? 'pos' : 'neg'}">${signed(x.paid_gap)}</div></div>

        <div class="s" style="margin-top:10px;font-weight:600;color:var(--rose)">SHARE OF REVENUE</div>
        ${detailRow('All sales, every channel', money(pd.revenue))}
        <div class="row"><div class="grow t">Their ${pd.share_pct}% of it</div>
          <div class="amt">${money(x.revenue_share)}</div></div>

        <div class="s" style="margin-top:10px;font-weight:600;color:var(--rose)">SHARE OF PROFIT</div>
        ${detailRow('Their ' + pd.share_pct + '% of ' + money(pd.profit), money(x.profit_share))}
        ${detailRow('Already distributed', money(x.profit_distributed))}
        <div class="row"><div class="grow t">Still to come</div>
          <div class="amt ${x.profit_pending >= 0 ? 'pos' : 'neg'}">${money(x.profit_pending)}</div></div>

        <div class="s" style="margin-top:10px;font-weight:600;color:var(--rose)">MONEY IN THEIR ACCOUNT</div>
        ${detailRow('Credited to them', money(x.credited))}
        ${detailRow('Drawn out', money(x.debited))}
        <div class="row"><div class="grow t">Balance</div>
          <div class="amt ${x.balance >= 0 ? 'pos' : 'neg'}">${money(x.balance)}</div></div>

        <div class="s" style="margin-top:10px;font-weight:600;color:var(--rose)">SETTLEMENT</div>
        ${detailRow('Paid from pocket', money(x.paid))}
        ${detailRow('Less credited back', '-' + money(x.credited))}
        ${Math.abs(x.settled_adjust) > 0.005
          ? detailRow('Plus settle-up so far', signed(x.settled_adjust)) : ''}
        <div class="row"><div class="grow t">Net invested</div>
          <div class="amt">${money(x.invested_net)}</div></div>
        ${detailRow('An equal share would be', money(x.fair_invested))}
        <div class="row"><div class="grow t">${x.investment_gap >= 0 ? 'Ahead by' : 'Behind by'}</div>
          <div class="amt ${x.investment_gap >= 0 ? 'pos' : 'neg'}">${signed(x.investment_gap)}</div></div>
        <p class="muted">${money(x.paid)} - ${money(x.credited)}${
          Math.abs(x.settled_adjust) > 0.005
            ? (x.settled_adjust >= 0 ? ' + ' : ' - ') + money(Math.abs(x.settled_adjust)) : ''
        } = ${money(x.invested_net)}, against ${money(x.fair_invested)} each.</p>
        ${Math.abs(x.settled_adjust) > 0.005
          ? detailRow('Already settled', signed(x.settled_adjust)) : ''}
        ${x.owes.length ? x.owes.map((o) =>
            `<div class="row"><div class="grow s">pays ${esc(o.to)}</div>
             <div class="amt neg">${money(o.amount)}</div></div>`).join('') : ''}
        ${x.owed.length ? x.owed.map((o) =>
            `<div class="row"><div class="grow s">receives from ${esc(o.from)}</div>
             <div class="amt pos">${money(o.amount)}</div></div>`).join('') : ''}
        ${(!x.owes.length && !x.owed.length && Math.abs(x.settled_adjust) <= 0.005)
          ? '<p class="muted">Nothing outstanding.</p>' : ''}
      </div>`).join('')}
    <p class="muted">Investment is money put in. Profit share is money earned. The account
      balance is what has actually been taken in and not drawn out — three different things,
      which is why they are listed separately.</p>
  </section>`;
}

/**
 * Offer to carry a new price onto orders that are still open.
 *
 * Completed sales are never touched: an order that has been delivered,
 * or paid in full, was agreed at its own price, and moving its total
 * afterwards invents a balance the customer never agreed to. What is
 * left — not delivered AND not fully paid — is still being negotiated,
 * so it is the only thing a price rise can fairly reach, and even then
 * only per order and only on request.
 *
 * Nothing happens unless the partner ticks a box and confirms.
 */
async function offerReprice(item) {
  let d;
  try {
    d = await api('catalog.php', 'reprice_preview', { params: { item } });
  } catch (e) { return; }                 // older server: nothing to offer
  if (!d.orders || !d.orders.length) return;

  const sheet = openSheet('Apply to open orders?', `
    <div class="card">
      <div class="t">${d.count} open order${d.count === 1 ? '' : 's'} still
        ${d.count === 1 ? 'holds' : 'hold'} the old price</div>
      <p class="muted">${d.count === 1 ? 'This has' : 'These have'} not been delivered and
        ${d.count === 1 ? 'is' : 'are'} not paid in full. Delivered and fully-paid orders are
        never changed — they keep the price the customer agreed to.</p>
    </div>
    <section><h2 class="section">Choose which to update</h2><div class="card" id="repList">
      ${d.orders.map((o) => `
        <label class="row" style="cursor:pointer">
          <input type="checkbox" data-rep="${o.id}" ${o.blocked ? 'disabled' : 'checked'}
                 style="width:auto;margin-right:10px">
          <div class="grow"><div class="t">${esc(o.order_no)}</div>
            <div class="s">${esc(o.customer || 'Walk-in')} · ${esc(prettyDate(o.created_at))}${
              o.paid_amount > 0.005 ? ' · ' + money(o.paid_amount) + ' paid' : ''}</div>
            ${o.blocked ? `<div class="s neg">cannot: the new total is below what is already paid</div>` : ''}
          </div>
          <div class="amt">${money(o.new_total)}
            <div class="s ${o.change >= 0 ? 'pos' : 'neg'}">${signed(o.change)}</div></div>
        </label>`).join('')}
    </div></section>
    <div class="card" style="margin-top:10px">
      <div class="row" style="border:none;padding:0">
        <div class="grow t">Total change</div>
        <div class="amt ${d.net_change >= 0 ? 'pos' : 'neg'}" id="repNet">${signed(d.net_change)}</div>
      </div>
    </div>
    <button class="btn" id="repGo" style="margin-top:12px">Update the ticked orders</button>
    <button class="btn ghost" id="repSkip" style="margin-top:8px">Leave them as they are</button>`);

  const picked = () => [...sheet.panel.querySelectorAll('[data-rep]')]
    .filter((c) => c.checked && !c.disabled).map((c) => Number(c.dataset.rep));

  function paintNet() {
    const ids = picked();
    const net = d.orders.filter((o) => ids.includes(o.id))
                        .reduce((t, o) => t + o.change, 0);
    const el = sheet.panel.querySelector('#repNet');
    el.textContent = signed(net);
    el.className = 'amt ' + (net >= 0 ? 'pos' : 'neg');
    sheet.panel.querySelector('#repGo').disabled = ids.length === 0;
  }
  sheet.panel.querySelectorAll('[data-rep]').forEach((c) => c.onchange = paintNet);
  paintNet();

  await new Promise((done) => {
    sheet.panel.querySelector('#repSkip').onclick = () => { sheet.close(); done(); };
    sheet.panel.querySelector('#repGo').onclick = async () => {
      const button = sheet.panel.querySelector('#repGo');
      button.disabled = true; button.textContent = 'Updating…';
      try {
        const r = await api('catalog.php', 'reprice_apply', {
          body: { item, order_ids: picked() },
        });
        toast(r.message || 'Orders updated.');
      } catch (e) { toast(e.message); }
      sheet.close(); done();
    };
  });
}

/* ── Movements — the account ledger, mirroring movements.php ──── */

let movFilter = { partner_id: '', direction: '' };

/** The pill movements.php puts against each kind. */
function movKind(kind) {
  const styles = {
    personal: ['personal', '#fdeceb', '#c0392b'],
    transfer: ['settlement', '#e7f0fd', '#1451a8'],
    profit:   ['profit', '#e3f5eb', '#1a7f4b'],
    invest:   ['settle-up', '#efe7fb', '#5b3ba0'],
  };
  const hit = styles[kind];
  if (!hit) return '';
  return `<span class="pill" style="background:${hit[1]};color:${hit[2]}">${hit[0]}</span>`;
}

route('movements', async () => {
  setHtml(`<div class="screen"><div class="head">
    <button class="back" data-go="money">‹</button><h1 class="grow">Movements</h1></div>
    <div id="body">${spinner()}</div></div>`);

  const [d, boot] = await Promise.all([
    api('finance.php', 'movements', { params: { limit: 100, ...movFilter } }),
    api('catalog.php', 'bootstrap'),
  ]);
  const partners = (boot.partners || []).filter((p) => p.is_active);
  const t = d.totals || { credits: 0, debits: 0, net: 0, count: 0 };

  document.getElementById('body').innerHTML = `
    <div class="grid2" style="margin-top:8px">
      ${statCard('Credits shown', moneyShort(t.credits), 'money in', 'pos')}
      ${statCard('Debits shown', moneyShort(t.debits), 'money out', 'neg')}
    </div>
    <div class="card" style="margin-top:10px">
      <div class="row" style="border:none;padding:0">
        <div class="grow t">Net (credit − debit)</div>
        <div class="amt ${t.net >= 0 ? 'pos' : 'neg'}">${money(t.net)}</div></div>
      <p class="muted">across ${t.count} movement${t.count === 1 ? '' : 's'} matching the filter</p>
    </div>

    <section><h2 class="section">Filter</h2><div class="card">
      <div class="chips" id="movPartner"></div>
      <div class="chips" style="margin-top:8px" id="movDir"></div>
    </div></section>

    <div id="movList"></div>

    <button class="btn" id="addMov" style="margin-top:14px">Add a movement</button>`;

  chipRow('movPartner',
    [['', 'All partners']].concat(partners.map((p) => [String(p.id), p.name])),
    movFilter.partner_id, (v) => { movFilter.partner_id = v; routes.movements(); });
  chipRow('movDir',
    [['', 'Both'], ['credit', 'Credits'], ['debit', 'Debits']],
    movFilter.direction, (v) => { movFilter.direction = v; routes.movements(); });

  document.getElementById('movList').innerHTML = d.movements.length
    ? `<section><h2 class="section">${d.movements.length} movement${d.movements.length === 1 ? '' : 's'}</h2>
       <div class="card">${d.movements.map((m) => {
      // A settle-up moves net investment without moving the account
      // balance, so it shows its invest_adjust rather than its amount —
      // the amount on those rows is zero by design.
      const isInvest = m.kind === 'invest';
      const value = isInvest
        ? (m.invest_adjust >= 0 ? '+' : '-') + money(Math.abs(m.invest_adjust))
        : (m.direction === 'credit' ? '+' : '-') + money(m.amount);
      const tone = isInvest ? '' : (m.direction === 'credit' ? 'pos' : 'neg');
      return `<div class="row">${tile(m.partner)}
        <div class="grow">
          <div class="t">${esc(m.partner)} ${movKind(m.kind)}</div>
          <div class="s">${esc(prettyDate(m.date))}${m.source ? ' · ' + esc(m.source) : ''}${
            m.event_name ? ' · ' + esc(m.event_name) : ''}</div>
          ${m.note ? `<div class="s">${esc(m.note)}</div>` : ''}
          ${isInvest ? '<div class="s">net invested — account balance unchanged</div>' : ''}
        </div>
        <div style="text-align:right">
          <div class="amt ${tone}">${value}</div>
          <button class="btn small ghost" data-mov-del="${m.id}" style="margin-top:4px">Delete</button>
        </div></div>`;
    }).join('')}</div></section>`
    : '<p class="muted center" style="margin-top:24px">No movements match this filter.</p>';

  document.querySelectorAll('[data-mov-del]').forEach((b) => b.onclick = async () => {
    const m = d.movements.find((x) => String(x.id) === b.dataset.movDel);
    const extra = m && m.kind === 'transfer'
      ? '\n\nThis is one side of a settlement — both sides will be removed.' : '';
    if (!confirm('Delete this movement?' + extra)) return;
    try {
      const r = await api('finance.php', 'delete_movement', { body: { id: Number(b.dataset.movDel) } });
      toast(r.message || 'Movement deleted.');
      routes.movements();
    } catch (e) { toast(e.message); }
  });

  document.getElementById('addMov').onclick = () => movementSheet(partners);
});

/**
 * Add a movement — the form from movements.php.
 *
 * Credit is money coming in to a partner's account; debit is money spent
 * out of it. This is the ledger the whole Money screen is built from, so
 * the wording matches the website's exactly rather than being reworded
 * into something that means subtly something else.
 */
function movementSheet(partners) {
  const sheet = openSheet('Add a movement', `
    <div class="card">
      <label class="field"><span>Date</span><input id="mdate" type="date" value="${today()}"></label>
      <label class="field"><span>Partner</span>
        <select id="mpartner">${partners.map((p) =>
          `<option value="${p.id}">${esc(p.name)}</option>`).join('')}</select></label>
      <label class="field"><span>Direction</span>
        <select id="mdir">
          <option value="credit">Credit — money in (sales)</option>
          <option value="debit">Debit — spent from account</option>
        </select></label>
      <label class="field"><span>Amount (₹)</span>
        <input id="mamount" inputmode="decimal" placeholder="0.00"></label>
      <label class="field"><span>Source / purpose</span>
        <input id="msource" placeholder="e.g. Meesho payout"></label>
      <label class="field"><span>Note — optional</span><input id="mnote"></label>
    </div>
    <button class="btn" id="msave" style="margin-top:12px">Add movement</button>`);

  document.getElementById('msave').onclick = async () => {
    const button = document.getElementById('msave');
    const amount = Number(String(document.getElementById('mamount').value).replace(/[^\d.]/g, '')) || 0;
    if (!(amount > 0)) { toast('Enter an amount greater than zero.'); return; }
    button.disabled = true; button.textContent = 'Saving…';
    try {
      const r = await api('finance.php', 'add_movement', {
        body: {
          mov_date: document.getElementById('mdate').value || today(),
          partner_id: Number(document.getElementById('mpartner').value),
          direction: document.getElementById('mdir').value,
          amount,
          source: document.getElementById('msource').value.trim(),
          note: document.getElementById('mnote').value.trim(),
        },
      });
      sheet.close();
      toast(r.message || 'Movement added.');
      routes.movements();
    } catch (e) {
      toast(e.message);
      button.disabled = false; button.textContent = 'Add movement';
    }
  };
}

/* ── Expenses ─────────────────────────────────────────────────── */

let expenseRange = 'month';

route('expenses', async () => {
  setHtml(`<div class="screen"><div class="head">
    <button class="back" data-go="home">‹</button><h1 class="grow">Expenses</h1></div>
    <div class="chips" id="ranges"></div><div id="body">${spinner()}</div></div>`);
  paintRanges('ranges', expenseRange, (id) => { expenseRange = id; routes.expenses(); });

  const range = RANGES.find((r) => r.id === expenseRange);
  const d = await api('expenses.php', 'list', { params: { from: range.from(), to: today(), limit: 80 } });

  document.getElementById('body').innerHTML = `
    <div class="grid2" style="margin-top:10px">
      ${statCard('Spent', moneyShort(d.summary.net), d.summary.count + ' expenses')}
      ${statCard('Saved on discounts', moneyShort(d.summary.discount), 'off ' + moneyShort(d.summary.gross))}
    </div>
    ${d.expenses.length ? `<div class="card" style="margin-top:10px">${d.expenses.map((e) => `
      <button class="row" data-go="expense/${e.id}" style="width:100%;text-align:left">
        ${tile(e.category || e.item)}
        <div class="grow"><div class="t">${esc(e.item)}</div>
          <div class="s">${esc(prettyDate(e.date))} · ${esc(e.category)}${e.paid_by ? ' · paid by ' + esc(e.paid_by) : ''}</div>
          ${(e.item_count || e.payer_count > 1) ? `<div class="s" style="color:var(--rose)">${
            [e.item_count ? e.item_count + (e.item_count === 1 ? ' item' : ' items') : null,
             e.payer_count > 1 ? e.payer_count + ' partners paid' : null].filter(Boolean).join(' · ')
          }</div>` : ''}
        </div>
        <div class="amt">${moneyShort(e.net)}</div></button>`).join('')}</div>`
      : `<p class="muted center" style="margin-top:30px">No expenses in this period.</p>
         <button class="btn" id="addFirst" style="margin-top:14px">Record an expense</button>`}`;

  // A floating button rather than one in the list: the list is the thing
  // partners scroll, and an action that scrolls away is an action nobody
  // finds. Same reason the Android app puts it on the bar.
  document.querySelectorAll('.fab').forEach((n) => n.remove());
  const fab = document.createElement('button');
  fab.className = 'fab';
  fab.innerHTML = svg('plus', 18) + '<span>Add expense</span>';
  fab.onclick = () => expenseSheet();
  document.body.appendChild(fab);

  const first = document.getElementById('addFirst');
  if (first) first.onclick = () => expenseSheet();
});

/**
 * Record an expense, including the split when two partners paid for it.
 *
 * The split is the part that matters: expense_payments is what the Money
 * screen reads as a partner's contribution, so an expense saved against
 * the wrong payer quietly skews every partner's fair share. The server
 * rejects a split that does not add up; this form shows the running
 * remainder so it is obvious before saving.
 */
async function expenseSheet() {
  const sheet = openSheet('New expense', `<div id="expForm">${spinner()}</div>`);
  let boot;
  try {
    boot = await api('catalog.php', 'bootstrap');
  } catch (e) { sheet.close(); toast(e.message); return; }

  const partners = (boot.partners || []).filter((p) => p.is_active);
  if (!partners.length) { sheet.close(); toast('No active partners to record this against.'); return; }
  const categories = (boot.categories || []).map((c) => c.name);
  if (!categories.length) categories.push('Materials', 'Packing', 'Travel', 'Stall', 'Tools', 'Other');

  let split = false;
  const body = sheet.panel.querySelector('#expForm');
  body.innerHTML = `
    <div class="card">
      <label class="field"><span>What was it for</span>
        <input id="xitem" placeholder="Vinyl roll, courier, stall fee…"></label>
      <label class="field"><span>Amount (₹)</span>
        <input id="xamount" inputmode="decimal" placeholder="0.00"></label>
      <label class="field"><span>Discount (₹) — optional</span>
        <input id="xdisc" inputmode="decimal" placeholder="0.00"></label>
      <label class="field"><span>Date</span>
        <input id="xdate" type="date" value="${today()}"></label>
      <label class="field"><span>Category</span>
        <select id="xcat">${categories.map((c) =>
          `<option value="${esc(c)}">${esc(c)}</option>`).join('')}</select></label>
      <label class="field"><span>Paid to — optional</span>
        <input id="xto" placeholder="Shop or supplier"></label>
      <label class="field"><span>Notes — optional</span><input id="xdetails"></label>
    </div>

    <section><h2 class="section">Who paid, from pocket</h2><div class="card">
      <div class="chips" id="xpayer"></div>
      <div class="btnrow"><button class="btn ghost small" id="xsplit">Split between partners</button></div>
      <div id="xsplitbox"></div>
    </div></section>

    <p class="muted" style="margin-top:12px">Receipts are attached on the website — this form does not
      upload files yet.</p>
    <button class="btn" id="xsave" style="margin-top:12px">Save expense</button>`;

  const val = (id) => (document.getElementById(id).value || '').trim();
  const num = (id) => Number(val(id).replace(/[^\d.]/g, '')) || 0;
  const net = () => Math.round((num('xamount') - num('xdisc')) * 100) / 100;

  let paidBy = String(partners[0].id);
  function paintPayer() {
    chipRow('xpayer', partners.map((p) => [String(p.id), p.name]), paidBy, (v) => {
      paidBy = v; paintPayer();
    });
  }
  paintPayer();

  const splitBox = document.getElementById('xsplitbox');
  function paintSplit() {
    document.getElementById('xsplit').textContent = split ? 'One partner paid it all' : 'Split between partners';
    document.getElementById('xpayer').style.display = split ? 'none' : '';
    if (!split) { splitBox.innerHTML = ''; return; }
    splitBox.innerHTML = partners.map((p) => `
      <label class="field"><span>${esc(p.name)} put in (₹)</span>
        <input data-split="${p.id}" inputmode="decimal" placeholder="0.00"></label>`).join('')
      + '<p class="muted" id="xsplitsum" style="margin-top:10px"></p>';
    splitBox.querySelectorAll('[data-split]').forEach((i) => i.addEventListener('input', splitSum));
    splitSum();
  }
  /** The running remainder, so a split that will be rejected is visible first. */
  function splitSum() {
    const rows = [...splitBox.querySelectorAll('[data-split]')];
    const sum = rows.reduce((t, i) => t + (Number(String(i.value).replace(/[^\d.]/g, '')) || 0), 0);
    const left = Math.round((net() - sum) * 100) / 100;
    const el = document.getElementById('xsplitsum');
    if (!el) return;
    el.textContent = Math.abs(left) < 0.01
      ? 'Adds up to ' + money(net()) + '.'
      : left > 0 ? money(left) + ' of ' + money(net()) + ' still unassigned.'
                 : money(-left) + ' more than the expense.';
    el.className = Math.abs(left) < 0.01 ? 'muted pos' : 'muted neg';
  }
  document.getElementById('xsplit').onclick = () => { split = !split; paintSplit(); };
  document.getElementById('xamount').addEventListener('input', splitSum);
  document.getElementById('xdisc').addEventListener('input', splitSum);

  document.getElementById('xsave').onclick = async () => {
    const button = document.getElementById('xsave');
    if (!val('xitem')) { toast('What was the money spent on?'); return; }
    if (num('xamount') <= 0) { toast('Enter an amount greater than zero.'); return; }
    if (num('xdisc') > num('xamount')) { toast('The discount is more than the amount.'); return; }

    const payload = {
      exp_date: val('xdate') || today(),
      item: val('xitem'),
      amount: num('xamount'),
      discount: num('xdisc'),
      category: val('xcat'),
      paid_to: val('xto'),
      details: val('xdetails'),
      paid_by: Number(split ? partners[0].id : paidBy),
    };
    if (split) {
      const rows = [...splitBox.querySelectorAll('[data-split]')]
        .map((i) => ({ partner_id: Number(i.dataset.split),
                       amount: Number(String(i.value).replace(/[^\d.]/g, '')) || 0 }))
        .filter((r) => r.amount > 0);
      if (!rows.length) { toast('Enter what each partner put in.'); return; }
      // The server checks this too and refuses a split that is out; the
      // point of checking here is that the partner is still looking at
      // the numbers and can fix them.
      const sum = Math.round(rows.reduce((t, r) => t + r.amount, 0) * 100) / 100;
      if (Math.abs(sum - net()) > 0.01) {
        toast('The split adds up to ' + money(sum) + ' but the expense is ' + money(net()) + '.');
        return;
      }
      payload.payments = rows;
      payload.paid_by = rows[0].partner_id;
    }

    button.disabled = true; button.textContent = 'Saving…';
    try {
      const r = await api('expenses.php', 'create', { body: payload });
      sheet.close();
      toast(r.message || 'Expense recorded.');
      routes.expenses();
    } catch (e) {
      toast(e.message);
      button.disabled = false; button.textContent = 'Save expense';
    }
  };
}

route('expense', async (id) => {
  setHtml(`<div class="screen"><div class="head">
    <button class="back" data-go="expenses">‹</button><h1 class="grow">Expense</h1></div>
    <div id="body">${spinner()}</div></div>`);
  const { expense: e } = await api('expenses.php', 'get', { params: { id } });
  const linesTotal = (e.items || []).reduce((s, i) => s + Number(i.line_total || 0), 0);
  const matches = Math.abs(linesTotal - e.amount) < 0.01;

  document.getElementById('body').innerHTML = `
    <div class="hero" style="background:linear-gradient(135deg,#B3261E,#7A1610)">
      <div class="label">Spent</div>
      <div class="big">${money(e.net)}</div>
      <div class="meta">${esc(e.item)}</div>
    </div>

    ${(e.items && e.items.length) ? `<section><h2 class="section">What was in it</h2><div class="card">
      ${e.items.map((i) => `<div class="row"><div class="grow">
        <div class="t" style="font-weight:500">${esc(i.descr)}</div>
        <div class="s">${qtyText(i.qty)} × ${money(i.unit_cost)}</div></div>
        <div class="amt">${money(i.line_total)}</div></div>`).join('')}
      <div class="row"><div class="grow t">${e.items.length} item${e.items.length === 1 ? '' : 's'}</div>
        <div class="amt">${money(linesTotal)}</div></div>
      ${matches ? '' : `<p class="muted warn">These lines add up to ${money(linesTotal)}, but the expense is
        ${money(e.amount)}. One side was edited without the other — the expense total is what counts
        towards partner accounts.</p>`}
    </div></section>` : ''}

    <section><h2 class="section">Details</h2><div class="card">
      ${detailRow('Date', prettyDate(e.date))}
      ${detailRow('Category', e.category)}
      ${e.paid_to ? detailRow('Paid to', e.paid_to) : ''}
      ${detailRow('Amount', money(e.amount))}
      ${e.discount > 0.5 ? detailRow('Discount', '-' + money(e.discount)) : ''}
      <div class="row"><div class="grow t">Net</div><div class="amt">${money(e.net)}</div></div>
      ${e.details ? `<p class="muted" style="margin-top:8px">${esc(e.details)}</p>` : ''}
    </div></section>

    <section><h2 class="section">Who paid, from pocket</h2><div class="card">
      ${(e.payers && e.payers.length) ? e.payers.map((p) => `<div class="row">${tile(p.partner)}
        <div class="grow"><div class="t">${esc(p.partner)}</div></div>
        <div class="amt">${money(p.amount)}</div></div>`).join('')
        : '<p class="muted neg">Nobody is recorded as paying for this, so it counts towards no partner\'s investment.</p>'}
      ${(e.payers && e.payers.length > 1) ? `<div class="row"><div class="grow t">Total put in</div>
        <div class="amt">${money(e.payers.reduce((s, p) => s + Number(p.amount || 0), 0))}</div></div>` : ''}
    </div></section>

    ${e.has_receipt ? '<p class="muted">A receipt is attached — receipts are viewed on the website.</p>' : ''}`;
});

function qtyText(q) {
  const n = Number(q) || 0;
  return Number.isInteger(n) ? String(n) : n.toFixed(2);
}

/* ── Events, catalogue, settings ──────────────────────────────── */

route('events', async (id) => {
  if (id) return eventOrders(id);
  setHtml(`<div class="screen"><div class="head">
    <button class="back" data-go="home">‹</button><h1 class="grow">Events &amp; stalls</h1></div>
    <div id="body">${spinner()}</div></div>`);
  const { events } = await api('catalog.php', 'events');
  // Each event opens its own orders: what was sold at that stall, and
  // what is still owed from it.
  document.getElementById('body').innerHTML = events.length ? `<div class="card">${events.map((e) => `
    <button class="row" data-go="events/${e.id}" style="width:100%;text-align:left">${tile(e.name, svg('events', 18))}
      <div class="grow"><div class="t">${esc(e.name)}</div>
        <div class="s">${e.order_count} orders${e.start_date ? ' · ' + esc(prettyDate(e.start_date)) : ''}
          · ${e.is_active ? 'Open' : 'Closed'}</div></div>
      <div class="amt">${moneyShort(e.revenue)}<div class="s">orders ›</div></div></button>`).join('')}</div>`
    : '<p class="muted center" style="margin-top:30px">No events yet.</p>';
});

/** One event's orders, with the same summary the Orders tab heads its list with. */
async function eventOrders(id) {
  setHtml(`<div class="screen"><div class="head">
    <button class="back" data-go="events">‹</button>
    <h1 class="grow" id="evtitle">Event orders</h1></div>
    <div id="body">${spinner()}</div></div>`);
  const [{ events }, data] = await Promise.all([
    api('catalog.php', 'events'),
    api('orders.php', 'list', { params: { event: id, limit: 200 } }),
  ]);
  const e = events.find((x) => String(x.id) === String(id));
  if (e) {
    document.getElementById('evtitle').innerHTML = `${esc(e.name)}
      <div class="sub">${e.start_date ? esc(prettyDate(e.start_date)) + ' · ' : ''}${e.is_active ? 'Open' : 'Closed'}</div>`;
  }
  const s = data.summary;
  document.getElementById('body').innerHTML = `
    <div class="grid2">
      ${statCard('Orders', String(s.count), '')}
      ${statCard('Billed', moneyShort(s.total), '')}
      ${statCard('Collected', moneyShort(s.paid), '', 'pos')}
      ${statCard('Due', moneyShort(s.balance), '', s.balance > 0.5 ? 'neg' : '')}
    </div>
    ${data.orders.length ? `<div class="card" style="margin-top:10px">${data.orders.map(orderRow).join('')}</div>`
      : '<p class="muted center" style="margin-top:30px">No orders at this event yet.</p>'}`;
}

route('catalog', async () => {
  setHtml(`<div class="screen"><div class="head">
    <button class="back" data-go="home">‹</button><h1 class="grow">Products &amp; prices</h1></div>
    <div id="body">${spinner()}</div></div>`);
  const { products } = await api('catalog.php', 'products', { params: { include_hidden: 'true' } });
  document.getElementById('body').innerHTML = `<div class="card">${products.map((p) => `
    <button class="row" data-edit="${esc(p.name)}" style="width:100%;text-align:left;${p.is_active ? '' : 'opacity:.55'}">
      ${p.image_url ? `<img class="tile" src="${esc(p.image_url)}" alt="" style="object-fit:cover">` : tile(p.name)}
      <div class="grow"><div class="t">${esc(p.name)}${p.is_active ? '' : ' · hidden'}</div>
        <div class="s">Costs ${money(p.unit_cost)}${p.margin_pct != null ? ' · ' + p.margin_pct + '% margin' : ''}</div></div>
      <div class="amt">${money(p.price)}<div class="s pos">+${moneyShort(p.margin)}</div></div>
    </button>`).join('')}</div>
    <p class="muted" style="margin-top:12px">Tap a product to change its price. A new price applies to
      sales made from then on — orders already sold keep the price they were sold at.</p>`;

  document.querySelectorAll('[data-edit]').forEach((b) => b.onclick = () => {
    priceSheet(products.find((p) => p.name === b.dataset.edit));
  });
});

/**
 * Change a price, having said plainly what that does and does not touch.
 *
 * The guarantee is enforced on the server — order_items keeps the
 * unit_price each line sold at, and editing an old order no longer
 * re-prices it. This screen's job is to make that visible before someone
 * commits, because "will this change my completed sales?" is the question
 * that stops people putting prices up.
 */
async function priceSheet(product) {
  if (!product) return;
  const sheet = openSheet(product.name, `<div id="priceBody">${spinner()}</div>`);

  let past = 0, history = [];
  try {
    const h = await api('catalog.php', 'price_history', { params: { item: product.name } });
    past = h.past_orders || 0;
    history = h.history || [];
  } catch (e) { /* an older server has no history; the form still works */ }

  sheet.panel.querySelector('#priceBody').innerHTML = `
    <div class="card">
      <label class="field"><span>Selling price (₹)</span>
        <input id="pprice" inputmode="decimal" value="${esc(String(product.price))}"></label>
      <label class="field"><span>What it costs us (₹)</span>
        <input id="pcost" inputmode="decimal" value="${esc(String(product.unit_cost || 0))}"></label>
      <p class="muted" id="pmargin" style="margin-top:10px"></p>
    </div>

    <div class="card" style="margin-top:10px">
      <div class="t">This applies to new sales only</div>
      <p class="muted">${past > 0
        ? `The ${past} order${past === 1 ? '' : 's'} that already include this product keep the price
           ${past === 1 ? 'it was' : 'they were'} sold at. Their totals, bills and the partner
           accounts built on them do not move.`
        : 'Nothing has been sold with this product yet, so there is no history to protect.'}</p>
    </div>

    ${history.length ? `<section><h2 class="section">Price history</h2><div class="card">
      ${history.map((h) => `<div class="row"><div class="grow">
        <div class="t" style="font-weight:500">${money(h.price)}</div>
        <div class="s">${esc(prettyDate(h.changed_at))}${h.changed_by ? ' · ' + esc(h.changed_by) : ''}${
          h.note ? ' · ' + esc(h.note) : ''}</div></div>
        <div class="amt s">cost ${money(h.unit_cost)}</div></div>`).join('')}
    </div></section>` : ''}

    <button class="btn" id="psave" style="margin-top:12px">Save price</button>`;

  const num = (id) => Number(String(document.getElementById(id).value).replace(/[^\d.]/g, '')) || 0;
  function paintMargin() {
    const margin = num('pprice') - num('pcost');
    const pct = num('pprice') > 0 ? (margin / num('pprice') * 100) : 0;
    const el = document.getElementById('pmargin');
    el.textContent = `Margin ${money(margin)} — ${pct.toFixed(1)}% of the price.`;
    el.className = margin >= 0 ? 'muted pos' : 'muted neg';
  }
  ['pprice', 'pcost'].forEach((id) =>
    document.getElementById(id).addEventListener('input', paintMargin));
  paintMargin();

  document.getElementById('psave').onclick = async () => {
    const button = document.getElementById('psave');
    if (num('pprice') < 0) { toast('Price cannot be negative.'); return; }
    button.disabled = true; button.textContent = 'Saving…';
    try {
      const r = await api('catalog.php', 'update_product', {
        body: { id: product.id, price: num('pprice'), unit_cost: num('pcost') },
      });
      sheet.close();
      toast(r.message || 'Price updated.');
      // A price change reaches new sales by itself. Orders still open —
      // not delivered and not paid in full — are the only ones that can
      // reasonably follow it, and only if someone says so.
      await offerReprice(product.name);
      routes.catalog();
    } catch (e) {
      toast(e.message);
      button.disabled = false; button.textContent = 'Save price';
    }
  };
}

route('settings', async () => {
  const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone;
  setHtml(`<div class="screen"><div class="head">
    <button class="back" data-go="home">‹</button><h1 class="grow">Settings</h1></div>

    <div class="card"><div class="stat"><div class="l">Signed in as</div>
      <div class="v" style="font-size:19px">${esc(store.name || 'this device')}</div></div></div>

    <section><h2 class="section">Version</h2><div class="card" id="versions">
      ${spinner()}</div></section>

    <section><h2 class="section">Appearance</h2><div class="card">
      <div class="themes" id="themes"></div>
      <p class="muted" style="margin-top:10px" id="themeHint"></p>
    </div></section>

    <section><h2 class="section">Server</h2><div class="card">
      <label class="field"><span>API address</span><input id="api" value="${esc(store.api)}"></label>
      <button class="btn ghost small" id="saveApi" style="margin-top:12px">Save and test</button>
      <div id="apiResult"></div>
    </div></section>

    <section><h2 class="section">This app</h2><div class="card">
      <p class="muted">${standalone
        ? 'Running from your Home Screen.'
        : 'Running in the browser. Add it to your Home Screen for a full-screen app with its own icon.'}</p>
      ${installHint()}
      <button class="btn ghost small" id="refresh" style="margin-top:12px">Check for updates</button>
    </div></section>

    <section><h2 class="section">Account</h2>
      <button class="btn danger" id="signout">Sign out</button></section>
  </div>`);

  paintVersions();

  const themeBox = document.getElementById('themes');
  const themeHint = document.getElementById('themeHint');
  function paintThemes() {
    themeBox.innerHTML = THEMES.map((t) => `
      <button data-theme="${t.id}" aria-pressed="${t.id === store.theme}">
        <span class="swatch ${t.swatch}"></span>
        <span>${svg(t.ico, 15)} ${t.label}</span>
      </button>`).join('');
    themeHint.textContent = (THEMES.find((t) => t.id === store.theme) || THEMES[0]).hint
      + ' · saved on this device';
    themeBox.querySelectorAll('[data-theme]').forEach((b) => b.onclick = () => {
      store.theme = b.dataset.theme;
      applyTheme(store.theme);
      paintThemes();
    });
  }
  paintThemes();

  document.getElementById('saveApi').onclick = async () => {
    store.api = document.getElementById('api').value;
    const box = document.getElementById('apiResult');
    try {
      await api('auth.php', 'ping');
      box.innerHTML = '<p class="muted pos" style="margin-top:8px">Connected. The server answered.</p>';
    } catch (e) {
      box.innerHTML = errorBox(e.message);
    }
  };
  document.getElementById('refresh').onclick = async () => {
    if ('serviceWorker' in navigator) {
      const regs = await navigator.serviceWorker.getRegistrations();
      await Promise.all(regs.map((r) => r.update()));
    }
    location.reload();
  };
  document.getElementById('signout').onclick = async () => {
    try { await api('auth.php', 'logout', { body: {} }); } catch (e) { /* sign out locally regardless */ }
    store.token = '';
    store.name = '';
    go('home');
    render();
  };
});

/**
 * Is this phone running the files that were just uploaded?
 *
 * Three things update separately and none of them tells you when it has
 * not: the app files on the server, the api/ folder next to them, and the
 * copy the browser cached for offline use. "I updated and nothing
 * changed" is nearly always one of those three, so rather than guess,
 * this prints all three and names the one that is behind.
 */
async function paintVersions() {
  const box = document.getElementById('versions');
  if (!box) return;

  const cached = await (async () => {
    try {
      const names = await caches.keys();
      const mine = names.filter((n) => n.startsWith('madeforu'));
      if (!mine.length) return 'nothing cached';
      return mine.map((n) => n.replace('madeforu-shell-', '')).join(', ');
    } catch (e) { return 'not available'; }
  })();

  let server = null, serverError = '';
  try {
    server = await api('auth.php', 'ping');
  } catch (e) { serverError = e.message; }

  const features = (server && server.features) || [];
  const missing = NEEDS_FEATURES.filter((f) => !features.includes(f));
  // An old server has no `features` key at all, which is itself the answer.
  const serverStale = !!server && (missing.length > 0 || !server.features);

  box.innerHTML = `
    ${detailRow('App build', BUILD)}
    ${detailRow('Offline cache', cached)}
    ${server ? detailRow('Server API', server.api_version || 'older than 1.1.0')
             : `<div class="row"><div class="grow t">Server API</div>
                <div class="amt neg">unreachable</div></div>`}
    ${serverError ? `<p class="muted neg" style="margin-top:8px">${esc(serverError)}</p>` : ''}
    ${serverStale ? `<p class="muted warn" style="margin-top:10px">
        <b>The server is running older API files.</b> The apps are fine — but screens that need
        ${esc(missing.join(', ') || 'the newer API')} will stay blank until the
        <code>api/</code> folder is uploaded to the server again. Uploading the app files alone
        is not enough.</p>`
      : server ? `<p class="muted pos" style="margin-top:10px">Server and app are in step.</p>` : ''}
    <button class="btn ghost small" id="hardRefresh" style="margin-top:12px">Force a fresh copy</button>
    <p class="muted" style="margin-top:8px">Clears the offline cache and reloads. Use this first if
      an update does not show up.</p>`;

  document.getElementById('hardRefresh').onclick = async () => {
    // Belt and braces: drop every cache, drop the worker, then reload. A
    // phone that has been serving a stale shell for days will not come
    // back from reg.update() alone.
    try {
      const names = await caches.keys();
      await Promise.all(names.map((n) => caches.delete(n)));
    } catch (e) { /* private mode, or blocked — the unregister still helps */ }
    try {
      if ('serviceWorker' in navigator) {
        const regs = await navigator.serviceWorker.getRegistrations();
        await Promise.all(regs.map((r) => r.unregister()));
      }
    } catch (e) { /* nothing more to do */ }
    location.reload(true);
  };
}

/* ── Boot ─────────────────────────────────────────────────────── */

if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('sw.js').catch(() => { /* http, or blocked — the app still works */ });
  });
}

render();