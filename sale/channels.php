<?php
/**
 * channels.php — where a sale came from, and what it sells for there.
 *
 * A channel is not an event. An event is a place and a weekend with an
 * entry cost; a channel is how the customer reached us, and it does not
 * end. Both can be true of one order, which is why they are separate
 * columns and this is a separate page from events.php.
 *
 * The price list here holds only the items a channel charges differently
 * for. Anything without a row sells at the catalogue price, so raising a
 * catalogue price still reaches every channel that has not deliberately
 * overridden it — a copy of the whole catalogue per channel would go
 * stale the first time a price moved.
 */
require 'config.php';
require_once __DIR__ . '/lib_trade.php';
$me = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        if (!trade_ready($conn)) {
            throw new Exception('The channels tables are not in the database yet. '
                . 'Run sale/api/migrations/2026-09-channels-accounts-stock.sql first.');
        }
        $action = $_POST['action'] ?? '';

        if ($action === 'add' || $action === 'edit') {
            $id       = (int)($_POST['id'] ?? 0);
            $name     = trim($_POST['name'] ?? '');
            $settles  = isset($_POST['settles_later']) ? 1 : 0;
            $sort     = (int)($_POST['sort_order'] ?? 0);
            $notes    = trim($_POST['notes'] ?? '');
            if ($name === '') throw new Exception('Channel name is required.');

            // The slug is the stable key: it is what the apps match on, so
            // renaming "Amazon" to "Amazon India" must not break anything
            // pointing at it. Only a new channel gets one derived.
            if ($action === 'add') {
                $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
                $slug = trim($slug, '-');
                if ($slug === '') $slug = 'channel';
                $base = $slug; $n = 2;
                while (true) {
                    $chk = $conn->prepare('SELECT id FROM channels WHERE slug = ?');
                    $chk->bind_param('s', $slug); $chk->execute();
                    $taken = (bool)$chk->get_result()->fetch_assoc();
                    $chk->close();
                    if (!$taken) break;
                    $slug = $base . '-' . $n++;
                }
                $s = $conn->prepare(
                    'INSERT INTO channels (name, slug, settles_later, sort_order, notes)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $s->bind_param('ssiis', $name, $slug, $settles, $sort, $notes);
                $s->execute(); $s->close();
                flash('Channel added.');
            } else {
                $s = $conn->prepare(
                    'UPDATE channels SET name = ?, settles_later = ?, sort_order = ?, notes = ?
                      WHERE id = ?'
                );
                $s->bind_param('siisi', $name, $settles, $sort, $notes, $id);
                $s->execute(); $s->close();
                flash('Channel updated.');
            }

        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $s = $conn->prepare('UPDATE channels SET is_active = 1 - is_active WHERE id = ?');
            $s->bind_param('i', $id); $s->execute(); $s->close();
            flash('Channel updated.');

        } elseif ($action === 'prices') {
            $cid = (int)($_POST['channel_id'] ?? 0);
            if (!isset(channel_map($conn)[$cid])) throw new Exception('Unknown channel.');

            $prices  = $_POST['price'] ?? [];
            $changed = 0; $cleared = 0;
            foreach ($prices as $item => $raw) {
                $item = (string)$item;
                if (!array_key_exists($item, $ITEMS)) continue;
                $raw = trim((string)$raw);

                // Empty means "no override" — back to the catalogue price.
                // That is different from zero, which would be a free item.
                if ($raw === '') {
                    $d = $conn->prepare('DELETE FROM channel_prices WHERE channel_id = ? AND item = ?');
                    $d->bind_param('is', $cid, $item);
                    $d->execute();
                    $cleared += $d->affected_rows > 0 ? 1 : 0;
                    $d->close();
                    continue;
                }
                $price = round((float)$raw, 2);
                if ($price < 0) throw new Exception('A price cannot be negative.');

                $u = $conn->prepare(
                    'INSERT INTO channel_prices (channel_id, item, price) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE price = VALUES(price)'
                );
                $u->bind_param('isd', $cid, $item, $price);
                $u->execute();
                // affected_rows is 1 for an insert, 2 for a changed update,
                // 0 when the value was already exactly this.
                if ($u->affected_rows > 0) $changed++;
                $u->close();
            }
            $name = channel_map($conn)[$cid]['name'];
            if ($changed === 0 && $cleared === 0) {
                flash('No price changed for ' . $name . '.');
            } else {
                flash(sprintf(
                    '%s: %d price%s set, %d back to the catalogue price. '
                    . 'This applies to new sales only — orders already taken keep '
                    . 'the price they were sold at.',
                    $name, $changed, $changed === 1 ? '' : 's', $cleared
                ));
            }
        }
    } catch (Exception $ex) {
        flash($ex->getMessage(), 'error');
    }
    header('Location: channels.php' . (isset($_POST['channel_id']) ? '?c=' . (int)$_POST['channel_id'] : ''));
    exit;
}

$ready    = trade_ready($conn);
$channels = $ready ? channels_all($conn, false) : [];

// Which channel's price list is open. Default to the first that already
// has overrides, so the page opens on something worth looking at.
$openId = (int)($_GET['c'] ?? 0);
if ($openId === 0 && $channels) {
    foreach ($channels as $c) {
        if (channel_price_map($conn, $c['id'])) { $openId = $c['id']; break; }
    }
}
$openPrices = $openId ? channel_price_map($conn, $openId) : [];

// Sales per channel, so a channel is never retired without seeing what
// is pointing at it.
$useCount = [];
if ($ready && db_column_exists($conn, 'orders', 'channel_id')) {
    $res = $conn->query(
        'SELECT channel_id, COUNT(*) n, COALESCE(SUM(total),0) v
           FROM orders WHERE channel_id IS NOT NULL GROUP BY channel_id'
    );
    while ($res && ($r = $res->fetch_assoc())) {
        $useCount[(int)$r['channel_id']] = ['n' => (int)$r['n'], 'v' => (float)$r['v']];
    }
}

$settlement = $ready ? channel_settlement($conn) : [];

$PAGE  = 'channels';
$TITLE = 'Sales channels';
$flash = flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sales channels · Stall Orders</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f5f7;color:#1c1e21;line-height:1.5}
  input,select{font-family:inherit}
  input:focus,select:focus{outline:2px solid #1877f2;outline-offset:-1px}
  .flash{padding:11px 14px;border-radius:8px;margin-bottom:16px;font-size:14px}
  .f-success{background:#e3f5eb;color:#1a7f4b;border:1px solid #b8e3ca}
  .f-error{background:#fdeceb;color:#c0392b;border:1px solid #f5c6c2}
</style>
</head>
<body>
<?php require 'layout.php'; ?>
<?php if ($flash): ?><div class="flash f-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>
<style>
  .card{background:#fff;border:1px solid #e4e6eb;border-radius:12px;padding:18px;margin-bottom:16px}
  .card h2{font-size:16px;margin-bottom:4px}
  .desc{font-size:13px;color:#65676b;margin-bottom:14px}
  .grid{display:grid;grid-template-columns:1.6fr .8fr 1.6fr auto;gap:12px;align-items:end}
  .grid.edit{grid-template-columns:1.4fr .7fr 1.4fr auto}
  label{display:block;font-size:12px;color:#65676b;margin-bottom:4px}
  input[type=text],input[type=number]{width:100%;padding:9px 11px;border:1px solid #ccd0d5;border-radius:8px;font-size:14px}
  .check{display:flex;align-items:center;gap:7px;font-size:13px;color:#1c1e21;padding-bottom:9px}
  button.primary{background:#1877f2;color:#fff;border:none;border-radius:8px;padding:10px 18px;font-size:14px;cursor:pointer}
  button.link{background:none;border:none;color:#1877f2;cursor:pointer;font-size:13px;padding:0}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th,td{text-align:left;padding:10px 8px;border-bottom:1px solid #eef0f2}
  th{font-size:12px;color:#65676b;text-transform:uppercase;letter-spacing:.03em}
  td.num,th.num{text-align:right;font-variant-numeric:tabular-nums}
  tr.dim{opacity:.5}
  .badge{font-size:11px;padding:2px 8px;border-radius:999px;white-space:nowrap}
  .b-on{background:#e6f4ea;color:#1a7f37}.b-off{background:#f0f0f0;color:#666}
  .b-late{background:#fff3e0;color:#b26a00}
  .edit-row{display:none;background:#f7f8fa}
  .edit-row.show{display:table-row}
  .chips{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
  .chips a{text-decoration:none;font-size:13px;padding:7px 14px;border-radius:999px;
    border:1px solid #ccd0d5;color:#1c1e21;background:#fff}
  .chips a.on{background:#1877f2;color:#fff;border-color:#1877f2}
  .pricegrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px}
  .pricegrid .row{border:1px solid #eef0f2;border-radius:10px;padding:10px 12px}
  .pricegrid .nm{font-size:13px;font-weight:600;margin-bottom:6px}
  .pricegrid .base{font-size:12px;color:#65676b;margin-top:5px}
  .warn{background:#fff8e1;border:1px solid #ffe0a3;color:#7a5800;padding:12px 14px;
    border-radius:10px;font-size:13px;margin-bottom:16px}
  @media(max-width:700px){ .grid,.grid.edit{grid-template-columns:1fr} }
</style>

<?php if (!$ready): ?>
  <div class="warn">
    <strong>Not set up yet.</strong> The channels and accounts tables are missing.
    Run <code>sale/api/migrations/2026-09-channels-accounts-stock.sql</code> against the
    database, then reload this page. Nothing else on the site is affected until you do.
  </div>
<?php else: ?>

<div class="card">
  <h2>Sales channels</h2>
  <p class="desc">
    Where a sale came from. This is separate from Events: an order can be a
    WhatsApp sale <em>and</em> a stall sale at the same time.
    <strong>Pays out later</strong> marks a marketplace that sells today and
    sends the money in a lump later — its payouts are recorded against an
    account without being counted as revenue a second time.
  </p>

  <form method="post" class="grid">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="add">
    <div><label>Channel name</label><input type="text" name="name" maxlength="60" required placeholder="e.g. Flipkart"></div>
    <div><label>Order</label><input type="number" name="sort_order" value="70" step="10"></div>
    <div><label>Note (optional)</label><input type="text" name="notes" maxlength="255"></div>
    <div class="check"><input type="checkbox" name="settles_later" id="sl-new"><label for="sl-new" style="margin:0">Pays out later</label></div>
    <div><button class="primary">Add channel</button></div>
  </form>
</div>

<div class="card">
  <h2>All channels</h2>
  <table>
    <thead><tr>
      <th>Channel</th><th>Settlement</th><th class="num">Orders</th><th class="num">Sold</th>
      <th class="num">Own prices</th><th>Status</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($channels as $c):
        $u  = $useCount[$c['id']] ?? ['n' => 0, 'v' => 0.0];
        $ov = count(channel_price_map($conn, $c['id']));
    ?>
      <tr class="<?= $c['is_active'] ? '' : 'dim' ?>">
        <td>
          <strong><?= e($c['name']) ?></strong>
          <?php if ($c['notes']): ?><div style="font-size:12px;color:#65676b"><?= e($c['notes']) ?></div><?php endif; ?>
        </td>
        <td><?= $c['settles_later']
              ? '<span class="badge b-late">Pays out later</span>'
              : '<span style="font-size:12px;color:#65676b">Money at the time</span>' ?></td>
        <td class="num"><?= (int)$u['n'] ?></td>
        <td class="num"><?= $u['v'] > 0 ? e(money($u['v'])) : '—' ?></td>
        <td class="num">
          <?php if ($ov > 0): ?>
            <a href="channels.php?c=<?= (int)$c['id'] ?>"><?= $ov ?></a>
          <?php else: ?>
            <a href="channels.php?c=<?= (int)$c['id'] ?>" style="color:#65676b">set</a>
          <?php endif; ?>
        </td>
        <td><span class="badge <?= $c['is_active'] ? 'b-on' : 'b-off' ?>"><?= $c['is_active'] ? 'Active' : 'Off' ?></span></td>
        <td style="text-align:right;white-space:nowrap">
          <button class="link" type="button" onclick="document.getElementById('ed<?= (int)$c['id'] ?>').classList.toggle('show')">Edit</button>
          &nbsp;
          <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <button class="link"><?= $c['is_active'] ? 'Turn off' : 'Turn on' ?></button>
          </form>
        </td>
      </tr>
      <tr class="edit-row" id="ed<?= (int)$c['id'] ?>">
        <td colspan="7">
          <form method="post" class="grid edit">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <div><label>Name</label><input type="text" name="name" value="<?= e($c['name']) ?>" maxlength="60" required></div>
            <div><label>Order</label><input type="number" name="sort_order" value="<?= (int)$c['sort_order'] ?>" step="10"></div>
            <div><label>Note</label><input type="text" name="notes" value="<?= e((string)$c['notes']) ?>" maxlength="255"></div>
            <div class="check">
              <input type="checkbox" name="settles_later" id="sl<?= (int)$c['id'] ?>" <?= $c['settles_later'] ? 'checked' : '' ?>>
              <label for="sl<?= (int)$c['id'] ?>" style="margin:0">Pays out later</label>
            </div>
            <div><button class="primary">Save</button></div>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($settlement): ?>
<div class="card">
  <h2>Sold against paid out</h2>
  <p class="desc">
    For each channel that pays later: what its orders came to, and what has
    actually arrived. The difference is money still owed — or the
    marketplace's commission, once a payout is in.
    Record a payout on the
    <a href="movements.php">Account movements</a> page with kind
    <em>Marketplace payout</em>, and it lands here rather than being counted
    as revenue on top of the orders it pays for.
  </p>
  <table>
    <thead><tr><th>Channel</th><th class="num">Orders</th><th class="num">Sold</th>
      <th class="num">Payouts</th><th class="num">Received</th><th class="num">Difference</th></tr></thead>
    <tbody>
    <?php foreach ($settlement as $s): ?>
      <tr>
        <td><?= e($s['channel']) ?></td>
        <td class="num"><?= (int)$s['orders'] ?></td>
        <td class="num"><?= e(money($s['sold'])) ?></td>
        <td class="num"><?= (int)$s['payouts'] ?></td>
        <td class="num"><?= e(money($s['received'])) ?></td>
        <td class="num"><?= e(money($s['outstanding'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="card">
  <h2>Prices for one channel</h2>
  <p class="desc">
    Fill in only the items this channel charges differently for. Leave a box
    empty and it sells at the catalogue price, so a catalogue change still
    reaches it. <strong>Changing a price here affects new sales only</strong> —
    an order already taken keeps the price it was sold at.
  </p>

  <div class="chips">
    <?php foreach ($channels as $c): ?>
      <a href="channels.php?c=<?= (int)$c['id'] ?>" class="<?= $openId === $c['id'] ? 'on' : '' ?>"><?= e($c['name']) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($openId && isset(channel_map($conn)[$openId])): ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="prices">
      <input type="hidden" name="channel_id" value="<?= (int)$openId ?>">
      <div class="pricegrid">
        <?php foreach ($ITEMS as $item => $base): ?>
          <div class="row">
            <div class="nm"><?= e($item) ?></div>
            <input type="number" step="0.01" min="0" name="price[<?= e($item) ?>]"
                   value="<?= isset($openPrices[$item]) ? e(number_format($openPrices[$item], 2, '.', '')) : '' ?>"
                   placeholder="<?= e(number_format((float)$base, 2, '.', '')) ?>">
            <div class="base">Catalogue <?= e(money($base)) ?><?php
              if (isset($openPrices[$item])) {
                  $d = $openPrices[$item] - (float)$base;
                  echo ' · ' . ($d >= 0 ? '+' : '') . e(money($d)) . ' here';
              } ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <div style="margin-top:16px"><button class="primary">Save prices for <?= e(channel_map($conn)[$openId]['name']) ?></button></div>
    </form>
  <?php else: ?>
    <p class="desc">Pick a channel above to set its prices.</p>
  <?php endif; ?>
</div>

<?php endif; ?>
</body>
</html>
