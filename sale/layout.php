<?php
/**
 * Shared sidebar + page shell. Include right after require_login().
 * Before including, set:
 *   $PAGE  = 'orders'|'products'|'costs'|'events'|'event_costs'|'admins'  (for highlight)
 *   $TITLE = 'Orders'  (heading shown top-left)
 * Optionally set $TOPBAR_HTML to inject controls (e.g. the event dropdown)
 * into the top bar's right side.
 *
 * This file opens <body>, the sidebar, and <main class="shell-main"> ... it is
 * closed by layout_end.php at the bottom of each page.
 */
if (!isset($PAGE))  $PAGE  = '';
if (!isset($TITLE)) $TITLE = 'Madeforu Order Management';
$who = function_exists('current_admin') && current_admin() ? current_admin()['name'] : '';

function nav_item(string $page, string $current, string $href, string $label, string $svg): string {
    $active = $page === $current ? ' active' : '';
    return "<a class=\"$active\" href=\"$href\"><svg class=\"ico\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\">$svg</svg><span>$label</span></a>";
}
?>
<style>
  /* ── Shared shell / sidebar ─────────────────────────────────── */
  .shell{display:flex;min-height:100vh}
  .shell-side{width:230px;flex-shrink:0;background:#1c2431;color:#e4e6eb;
              position:fixed;top:0;left:0;bottom:0;overflow-y:auto;z-index:60;
              transition:transform .22s ease}
  .shell-side .brand{padding:18px 18px 14px;border-bottom:1px solid #2c3648;
                     display:flex;justify-content:space-between;align-items:center;gap:8px}
  .shell-side .brand h1{font-size:16px;font-weight:600;color:#fff}
  .shell-side .brand p{font-size:12px;color:#9aa4b2;margin-top:2px;
                       white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:150px}
  .shell-side nav{padding:10px}
  .shell-side nav a{display:flex;align-items:center;gap:11px;padding:11px 12px;border-radius:8px;
                    color:#cdd3dc;text-decoration:none;font-size:14px;margin-bottom:2px}
  .shell-side nav a:hover{background:#2a3446;color:#fff}
  .shell-side nav a.active{background:#1877f2;color:#fff;font-weight:500}
  .shell-side nav a.signout{color:#f0a0a0}
  .shell-side nav a.signout:hover{background:#3a2630;color:#ffc9c9}
  .shell-side .ico{width:18px;height:18px;flex-shrink:0}
  .shell-side .sep{height:1px;background:#2c3648;margin:8px 6px}
  .side-x{display:none;background:none;border:none;color:#9aa4b2;font-size:22px;cursor:pointer;line-height:1}

  .shell-main{flex:1;margin-left:230px;padding:16px;min-width:0;width:100%}

  .shell-top{display:flex;justify-content:space-between;align-items:center;gap:12px;
             margin-bottom:20px;flex-wrap:wrap}
  .shell-top h1{font-size:20px;font-weight:600}
  .shell-top .who{font-size:13px;color:#65676b}
  .shell-burger{display:none;background:#1c2431;color:#fff;border:none;border-radius:8px;
                width:42px;height:42px;font-size:20px;cursor:pointer;flex-shrink:0}
  .shell-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:55}

  /* ── Mobile ─────────────────────────────────────────────────── */
  @media(max-width:860px){
    .shell-side{transform:translateX(-100%);width:250px;box-shadow:2px 0 16px rgba(0,0,0,.3)}
    .shell-side.open{transform:translateX(0)}
    .side-x{display:block}
    .shell-main{margin-left:0;padding:12px}
    .shell-burger{display:inline-flex;align-items:center;justify-content:center}
    .shell-backdrop.show{display:block}
  }

  /* ── Global: keep wide tables inside their card on phones ──────
     Any table wrapped in <div class="tscroll">…</div> scrolls sideways
     instead of spilling off the screen edge. Defined once here so every
     page inherits it. max-width:100% + the min-width:0 chain below force
     the scroll container to stay within the viewport so it can actually
     scroll rather than stretch the page. */
  .tscroll{display:block;width:100%;max-width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}
  .tscroll table{min-width:520px}
  /* Cards and the main column must be allowed to shrink below their
     content width, otherwise a wide table stretches the whole page and
     the tscroll box never clips. */
  .shell-main .card{max-width:100%;overflow:hidden}
  .shell-main .card .tscroll{overflow-x:auto}
</style>

<div class="shell">
  <aside class="shell-side" id="shellSide">
    <div class="brand">
      <div>
        <h1>Orders Management</h1>
        <?php if ($who): ?><p><?= e($who) ?></p><?php endif; ?>
      </div>
      <button class="side-x" onclick="shellNav(false)" aria-label="Close menu">&times;</button>
    </div>
    <nav>
      <?= nav_item('dashboard', $PAGE, 'dashboard.php', 'Dashboard',
          '<rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/>') ?>
      <?= nav_item('orders', $PAGE, 'index.php', 'Orders',
          '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/>') ?>
      <?= nav_item('products', $PAGE, 'products.php', 'Products &amp; prices',
          '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>') ?>
      <?= nav_item('share', $PAGE, 'share.php', 'Share links',
          '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.6" y1="13.5" x2="15.4" y2="17.5"/><line x1="15.4" y1="6.5" x2="8.6" y2="10.5"/>') ?>
      <?= nav_item('costs', $PAGE, 'costs.php', 'Manufacture price',
          '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>') ?>
      <div class="sep"></div>
      <?= nav_item('events', $PAGE, 'events.php', 'Events',
          '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>') ?>
      <?= nav_item('event_costs', $PAGE, 'event_costs.php', 'Event P&amp;L',
          '<path d="M3 3v18h18"/><path d="M18 17V9M13 17V5M8 17v-3"/>') ?>
      <div class="sep"></div>
      <?= nav_item('investment', $PAGE, 'investment.php', 'Investment summary',
          '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>') ?>
      <?= nav_item('expenses', $PAGE, 'expenses.php', 'Expenses',
          '<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>') ?>
      <?= nav_item('movements', $PAGE, 'movements.php', 'Account movements',
          '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>') ?>
      <?= nav_item('partners', $PAGE, 'partners.php', 'Partners',
          '<path d="M17 21v-2a4 4 0 0 0-3-3.87M9 21v-2a4 4 0 0 1 3-3.87"/><circle cx="12" cy="7" r="4"/>') ?>
      <div class="sep"></div>
      <?= nav_item('meesho', $PAGE, 'meesho.php', 'Meesho orders',
          '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/>') ?>
      <?= nav_item('meesho_products', $PAGE, 'meesho_products.php', 'Meesho products',
          '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>') ?>
      <?= nav_item('meesho_import', $PAGE, 'meesho_import.php', 'Import payments',
          '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>') ?>
      <?= nav_item('meesho_stats', $PAGE, 'meesho_stats.php', 'Meesho stats',
          '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>') ?>
      <div class="sep"></div>
      <?= nav_item('dealers', $PAGE, 'dealers.php', 'Stock dealers',
          '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>') ?>
      <?= nav_item('purchases', $PAGE, 'purchases.php', 'Stock purchases',
          '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>') ?>
      <?= nav_item('wholesale', $PAGE, 'wholesale.php', 'Wholesale buyers',
            '<path d="M3 7h18l-1.5 12H4.5z"/><path d="M8 7V5a4 4 0 0 1 8 0v2"/>') ?>
      <?= nav_item('material_audit', $PAGE, 'material_audit.php', 'Material audit',
            '<path d="M4 4h12l4 4v12H4z"/><path d="M8 12h8M8 16h5"/>') ?>
      <?= nav_item('stock', $PAGE, 'stock.php', 'Stock on hand',
          '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>') ?>
      <div class="sep"></div>
      <?= nav_item('admins', $PAGE, 'admins.php', 'Admins',
          '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>') ?>
      <?= nav_item('push_settings', $PAGE, 'push_settings.php', 'Notifications',
          '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>') ?>
      <a class="signout" href="logout.php">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        <span>Sign out</span>
      </a>
    </nav>
  </aside>

  <div class="shell-backdrop" id="shellBackdrop" onclick="shellNav(false)"></div>

  <main class="shell-main">
    <div class="shell-top">
      <div style="display:flex;align-items:center;gap:12px">
        <button class="shell-burger" onclick="shellNav(true)" aria-label="Open menu">&#9776;</button>
        <div>
          <h1><?= e($TITLE) ?></h1>
          <?php if ($who): ?><p class="who">Signed in as <?= e($who) ?></p><?php endif; ?>
        </div>
      </div>
      <?= $TOPBAR_HTML ?? '' ?>
    </div>

<script>
function shellNav(open){
  document.getElementById('shellSide').classList.toggle('open', open);
  document.getElementById('shellBackdrop').classList.toggle('show', open);
}
</script>