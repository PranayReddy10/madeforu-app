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

const store = {
  get token() { return localStorage.getItem('mfu.token') || ''; },
  set token(v) { v ? localStorage.setItem('mfu.token', v) : localStorage.removeItem('mfu.token'); },
  get name() { return localStorage.getItem('mfu.name') || ''; },
  set name(v) { localStorage.setItem('mfu.name', v || ''); },
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
  return (n >= 0 ? '+' : '−') + '₹' + inr.format(Math.abs(n));
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
  } catch (e) {
    // Shared hosting loves to prepend a warning or serve an error page.
    throw new ApiError('bad_response', text.trim().startsWith('<')
      ? 'The server returned a web page instead of data. Check the server address.'
      : 'The server sent a reply the app could not read.');
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
  settings: '<circle cx="12" cy="12" r="3.2"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M19.1 4.9 17 7M7 17l-2.1 2.1"/>',
};

/** An icon at a given size, inheriting the colour it sits in. */
function svg(name, size) {
  return `<svg viewBox="0 0 24 24" width="${size || 20}" height="${size || 20}" fill="none"
    stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"
    aria-hidden="true">${ICONS[name] || ''}</svg>`;
}

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
}

function spinner() { return '<div class="spinner"></div>'; }
function errorBox(message) { return `<div class="error">${esc(message)}</div>`; }

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

function orderRow(o) {
  const glyph = o.is_delivered ? '✓' : o.is_ready ? '▣' : '◷';
  const state = o.is_delivered ? 'Delivered' : o.is_ready ? 'Ready' : 'To make';
  const payTone = o.pay_status === 'paid' ? 'pos' : o.pay_status === 'partial' ? 'warn' : 'neg';
  const payLabel = o.pay_status === 'paid' ? 'Paid' : o.pay_status === 'partial' ? 'Part paid' : 'Unpaid';
  return `<button class="row" data-go="order/${o.id}" style="width:100%;text-align:left">
    ${tile(o.is_walk_in ? 'Walk in' : o.name, glyph)}
    <div class="grow">
      <div class="t">${esc(o.is_walk_in ? 'Walk-in' : o.name)}</div>
      <div class="s">${esc(o.order_no)} · ${esc(relativeDay(o.created_at))} · ${esc(state)}</div>
      ${o.items_text ? `<div class="s">${esc(o.items_text)}</div>` : ''}
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
      ${o.items.map((i) => detailRow(`${esc(i.item)} × ${i.quantity}`, money(i.line_total))).join('')}
      <div class="row"><div class="grow s">Subtotal</div><div class="amt">${money(o.subtotal)}</div></div>
      ${o.extra_charge > 0.001 ? detailRow(o.extra_charge_reason || 'Extra charge', '+' + money(o.extra_charge)) : ''}
      ${o.discount > 0.001 ? detailRow(o.discount_reason || 'Discount', '−' + money(o.discount)) : ''}
      <div class="row"><div class="grow t">Total</div><div class="amt">${money(o.total)}</div></div>
    </div></section>

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
  draft = draft && draft.keep ? draft : { lines: {}, name: '', phone: '', notes: '', paid: '', mode: 'cash', eventId: '' };
  draft.keep = false;

  setHtml(`<div class="screen"><div class="head"><h1 class="grow">New sale</h1></div>
    <div id="body">${spinner()}</div></div>`);

  const boot = await api('catalog.php', 'bootstrap');
  const products = boot.products || [];
  const events = boot.events || [];

  document.getElementById('body').innerHTML = `
    ${events.length ? `<div class="chips" id="eventChips"></div>` : ''}
    <section><h2 class="section">Products</h2><div class="card" id="products"></div></section>
    <section><h2 class="section">Customer <span class="hint">optional — leave empty for a walk-in</span></h2>
      <div class="card">
        <label class="field"><span>Name</span><input id="cname" value="${esc(draft.name)}"></label>
        <label class="field"><span>Phone</span>
          <input id="cphone" type="tel" inputmode="numeric" maxlength="10" value="${esc(draft.phone)}"></label>
        <label class="field"><span>Notes</span><input id="cnotes" value="${esc(draft.notes)}"></label>
      </div></section>
    <section><h2 class="section">Money taken now</h2><div class="card">
      <label class="field"><span>Amount</span><input id="paid" inputmode="decimal" value="${esc(draft.paid)}"></label>
      <div class="chips" style="margin-top:10px" id="modeChips"></div>
    </div></section>
    <div id="summary"></div>
    <button class="btn" id="save" style="margin-top:14px">Save sale</button>`;

  if (events.length) {
    chipRow('eventChips', [['', 'Direct / walk-up']].concat(events.map((e) => [String(e.id), e.name])),
      draft.eventId, (v) => { draft.eventId = v; routes.new(); draft.keep = true; });
  }
  chipRow('modeChips', [['cash', 'Cash'], ['upi', 'UPI'], ['card', 'Card'], ['other', 'Other']],
    draft.mode, (v) => { draft.mode = v; chipRow('modeChips', [['cash','Cash'],['upi','UPI'],['card','Card'],['other','Other']], v, () => {}); });

  const list = document.getElementById('products');
  function paintProducts() {
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
    const rows = Object.entries(draft.lines);
    // Shown so the counter can read the total back before taking money.
    // The server recomputes it from the catalogue regardless — this is a
    // display, not the price.
    const subtotal = rows.reduce((sum, [name, qty]) => {
      const p = products.find((x) => x.name === name);
      return sum + (p ? p.price * qty : 0);
    }, 0);
    document.getElementById('summary').innerHTML = rows.length ? `
      <section><h2 class="section">This bill</h2><div class="card">
        ${rows.map(([n, q]) => {
          const p = products.find((x) => x.name === n);
          return detailRow(`${esc(n)} × ${q}`, money(p ? p.price * q : 0));
        }).join('')}
        <div class="row"><div class="grow t">Total</div><div class="amt">${money(subtotal)}</div></div>
      </div></section>` : '';
  }

  paintProducts();
  paintSummary();

  const phone = document.getElementById('cphone');
  phone.addEventListener('input', () => { phone.value = phone.value.replace(/\D/g, '').slice(0, 10); });

  document.getElementById('save').onclick = async () => {
    const button = document.getElementById('save');
    const items = Object.entries(draft.lines).map(([item, quantity]) => ({ item, quantity }));
    if (!items.length) { toast('Add at least one product.'); return; }
    button.disabled = true; button.textContent = 'Saving…';
    try {
      const r = await api('orders.php', 'create', {
        body: {
          items,
          name: document.getElementById('cname').value.trim(),
          phone: phone.value.trim(),
          notes: document.getElementById('cnotes').value.trim(),
          event_id: draft.eventId || null,
          paid_amount: Number(document.getElementById('paid').value || 0),
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
      ${detailRow('Revenue (all sales)', money(d.business.revenue))}
      ${detailRow('Expenses', money(d.business.expenses))}
      <div class="row"><div class="grow t">Business profit</div>
        <div class="amt ${d.business.profit >= 0 ? 'pos' : 'neg'}">${money(d.business.profit)}</div></div>
      ${detailRow('Already distributed', money(d.business.distributed))}
      ${detailRow('Undistributed', money(d.business.remaining))}
      ${d.business.remaining <= 0.5 ? `<p class="muted" style="margin-top:8px">
        Nothing to distribute yet. This counts every expense, including stock and equipment, so it
        stays negative until those purchases have been earned back.</p>` : ''}
    </div></section>

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
      : '<p class="muted center" style="margin-top:30px">No expenses in this period.</p>'}`;
});

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
      ${e.discount > 0.5 ? detailRow('Discount', '−' + money(e.discount)) : ''}
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

route('events', async () => {
  setHtml(`<div class="screen"><div class="head">
    <button class="back" data-go="home">‹</button><h1 class="grow">Events &amp; stalls</h1></div>
    <div id="body">${spinner()}</div></div>`);
  const { events } = await api('catalog.php', 'events');
  document.getElementById('body').innerHTML = events.length ? `<div class="card">${events.map((e) => `
    <div class="row">${tile(e.name, svg('events', 18))}
      <div class="grow"><div class="t">${esc(e.name)}</div>
        <div class="s">${e.order_count} orders${e.start_date ? ' · ' + esc(prettyDate(e.start_date)) : ''}
          · ${e.is_active ? 'Open' : 'Closed'}</div></div>
      <div class="amt">${moneyShort(e.revenue)}</div></div>`).join('')}</div>`
    : '<p class="muted center" style="margin-top:30px">No events yet.</p>';
});

route('catalog', async () => {
  setHtml(`<div class="screen"><div class="head">
    <button class="back" data-go="home">‹</button><h1 class="grow">Products &amp; prices</h1></div>
    <div id="body">${spinner()}</div></div>`);
  const { products } = await api('catalog.php', 'products', { params: { include_hidden: 'true' } });
  document.getElementById('body').innerHTML = `<div class="card">${products.map((p) => `
    <div class="row" style="${p.is_active ? '' : 'opacity:.55'}">
      ${p.image_url ? `<img class="tile" src="${esc(p.image_url)}" alt="" style="object-fit:cover">` : tile(p.name)}
      <div class="grow"><div class="t">${esc(p.name)}${p.is_active ? '' : ' · hidden'}</div>
        <div class="s">Costs ${money(p.unit_cost)}${p.margin_pct != null ? ' · ' + p.margin_pct + '% margin' : ''}</div></div>
      <div class="amt">${money(p.price)}<div class="s pos">+${moneyShort(p.margin)}</div></div>
    </div>`).join('')}</div>
    <p class="muted" style="margin-top:12px">Prices are edited in the Android app or on the website.</p>`;
});

route('settings', async () => {
  const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone;
  setHtml(`<div class="screen"><div class="head">
    <button class="back" data-go="home">‹</button><h1 class="grow">Settings</h1></div>

    <div class="card"><div class="stat"><div class="l">Signed in as</div>
      <div class="v" style="font-size:19px">${esc(store.name || 'this device')}</div></div></div>

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

/* ── Boot ─────────────────────────────────────────────────────── */

if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('sw.js').catch(() => { /* http, or blocked — the app still works */ });
  });
}

render();
