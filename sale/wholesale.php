<?php
/**
 * wholesale.php — a notebook for wholesale buyers.
 *
 * Somebody who buys in bulk comes back every few weeks and takes a few
 * things at a price that was argued over. The only question worth
 * answering is "what has this person taken from me, and at what" — so
 * that next time they come, you can look.
 *
 * It is a page on its own and it stays on its own. Nothing here is an
 * order, nothing is counted as revenue or profit, nothing draws stock
 * and nothing appears on the Money page, Stats or the dashboard. The
 * three tables it uses are read by no other file in the project. That
 * is deliberate, and the migration says so too, because this is exactly
 * the kind of thing that gets helpfully added into a total later and
 * quietly moves every figure on the Money screen.
 *
 * Prices are typed in every time and never looked up from the
 * catalogue. A wholesale price is negotiated, is not the counter price,
 * and must not move when the catalogue moves — the same failure that
 * hit the order book, designed out here from the start.
 */
require 'config.php';
require_once __DIR__ . '/lib_push.php';   // tells the other partners' phones
$me = require_login();

/** Are the wholesale tables there yet? The SQL lands separately. */
function wholesale_ready(mysqli $conn): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $conn->query('SELECT 1 FROM wholesale_customers LIMIT 1'); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $back = 'wholesale.php';
    try {
        if (!wholesale_ready($conn)) {
            throw new Exception('The wholesale tables are not in the database yet. '
                . 'Run sale/api/migrations/2026-09-wholesale.sql first.');
        }
        $action = $_POST['action'] ?? '';

        // ── A buyer ────────────────────────────────────────────────
        if ($action === 'add_customer' || $action === 'edit_customer') {
            $id    = (int)($_POST['id'] ?? 0);
            $name  = trim($_POST['name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $shop  = trim($_POST['shop'] ?? '');
            $place = trim($_POST['place'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            if ($name === '') throw new Exception('A name is required.');

            if ($action === 'add_customer') {
                $s = $conn->prepare(
                    'INSERT INTO wholesale_customers (name, phone, shop, place, notes)
                     VALUES (?,?,?,?,?)'
                );
                $s->bind_param('sssss', $name, $phone, $shop, $place, $notes);
                $s->execute();
                $newId = $s->insert_id;
                $s->close();
                flash($name . ' added.');
                $back = 'wholesale.php?c=' . $newId;
            } else {
                $s = $conn->prepare(
                    'UPDATE wholesale_customers SET name=?, phone=?, shop=?, place=?, notes=?
                      WHERE id=?'
                );
                $s->bind_param('sssssi', $name, $phone, $shop, $place, $notes, $id);
                $s->execute(); $s->close();
                flash('Saved.');
                $back = 'wholesale.php?c=' . $id;
            }

        } elseif ($action === 'toggle_customer') {
            $id = (int)($_POST['id'] ?? 0);
            $s = $conn->prepare('UPDATE wholesale_customers SET is_active = 1 - is_active WHERE id = ?');
            $s->bind_param('i', $id); $s->execute(); $s->close();
            flash('Saved.');

        // ── A visit, with what they took ───────────────────────────
        } elseif ($action === 'add_visit') {
            $cid   = (int)($_POST['customer_id'] ?? 0);
            $date  = $_POST['visit_date'] ?? '';
            $note  = trim($_POST['note'] ?? '');
            $items = $_POST['item'] ?? [];
            $qtys  = $_POST['quantity'] ?? [];
            $rates = $_POST['unit_price'] ?? [];

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new Exception('Pick a valid date.');

            $chk = $conn->prepare('SELECT name FROM wholesale_customers WHERE id = ?');
            $chk->bind_param('i', $cid); $chk->execute();
            $cust = $chk->get_result()->fetch_assoc();
            $chk->close();
            if (!$cust) throw new Exception('Choose who bought.');

            if (!is_array($items) || !is_array($qtys) || !is_array($rates)
                || count($items) !== count($qtys) || count($items) !== count($rates)) {
                throw new Exception('Malformed product list.');
            }

            // Collect the lines first, so a bad one stops the whole
            // entry rather than leaving half a visit recorded.
            $rows = []; $total = 0.0;
            foreach ($items as $i => $raw) {
                $name = trim((string)$raw);
                if ($name === '') continue;             // a blank row is just unused
                $q = (int)$qtys[$i];
                $p = round((float)$rates[$i], 2);
                if ($q < 1)   throw new Exception('Quantity for "' . $name . '" must be at least 1.');
                if ($p < 0)   throw new Exception('Price for "' . $name . '" cannot be negative.');
                $lt = round($q * $p, 2);
                $total += $lt;
                $rows[] = [$name, $q, $p, $lt];
            }
            if (!$rows) throw new Exception('Add at least one product.');

            $conn->begin_transaction();
            try {
                $s = $conn->prepare(
                    'INSERT INTO wholesale_visits (customer_id, visit_date, note, created_by)
                     VALUES (?,?,?,?)'
                );
                $s->bind_param('issi', $cid, $date, $note, $me['id']);
                $s->execute();
                $vid = $s->insert_id;
                $s->close();

                $s = $conn->prepare(
                    'INSERT INTO wholesale_items (visit_id, item, quantity, unit_price, line_total)
                     VALUES (?,?,?,?,?)'
                );
                foreach ($rows as [$n, $q, $p, $lt]) {
                    $s->bind_param('isidd', $vid, $n, $q, $p, $lt);
                    $s->execute();
                }
                $s->close();
                $conn->commit();
            } catch (Exception $ex) { $conn->rollback(); throw $ex; }

            flash(count($rows) . ' item' . (count($rows) === 1 ? '' : 's')
                . ' recorded for ' . $cust['name'] . ' — ' . money($total) . '.');
            $back = 'wholesale.php?c=' . $cid;

        } elseif ($action === 'delete_visit') {
            $vid = (int)($_POST['id'] ?? 0);
            $cid = (int)($_POST['customer_id'] ?? 0);
            // The items go with it: the foreign key cascades.
            $s = $conn->prepare('DELETE FROM wholesale_visits WHERE id = ?');
            $s->bind_param('i', $vid); $s->execute(); $s->close();
            flash('Entry removed.');
            $back = 'wholesale.php?c=' . $cid;
        }
    } catch (Exception $ex) {
        flash($ex->getMessage(), 'error');
        if (isset($_POST['customer_id'])) $back = 'wholesale.php?c=' . (int)$_POST['customer_id'];
    }
    header('Location: ' . $back);
    exit;
}

$ready  = wholesale_ready($conn);
$openId = (int)($_GET['c'] ?? 0);

// Everyone, with how often they come and what they have taken. Totals
// here are this page's own arithmetic over its own tables; they are not
// revenue and appear nowhere else.
$customers = [];
if ($ready) {
    $res = $conn->query(
        'SELECT c.*,
                (SELECT COUNT(*) FROM wholesale_visits v WHERE v.customer_id = c.id) visits,
                (SELECT MAX(v.visit_date) FROM wholesale_visits v WHERE v.customer_id = c.id) last_visit,
                (SELECT COALESCE(SUM(i.line_total),0)
                   FROM wholesale_items i
                   JOIN wholesale_visits v ON v.id = i.visit_id
                  WHERE v.customer_id = c.id) taken
           FROM wholesale_customers c
          ORDER BY c.is_active DESC, c.name'
    );
    while ($res && ($r = $res->fetch_assoc())) $customers[] = $r;
}

// The one being looked at.
$open = null; $visits = []; $summary = []; $openTotal = 0.0; $openUnits = 0;
if ($ready && $openId > 0) {
    foreach ($customers as $c) if ((int)$c['id'] === $openId) $open = $c;

    if ($open) {
        $s = $conn->prepare(
            'SELECT v.id, v.visit_date, v.note, v.created_at, a.name AS by_name
               FROM wholesale_visits v
               LEFT JOIN admins a ON a.id = v.created_by
              WHERE v.customer_id = ?
              ORDER BY v.visit_date DESC, v.id DESC'
        );
        $s->bind_param('i', $openId);
        $s->execute();
        $visits = $s->get_result()->fetch_all(MYSQLI_ASSOC);
        $s->close();

        // Lines, grouped under their visit.
        if ($visits) {
            $ids = implode(',', array_map(fn($v) => (int)$v['id'], $visits));
            $res = $conn->query(
                "SELECT visit_id, item, quantity, unit_price, line_total
                   FROM wholesale_items WHERE visit_id IN ($ids) ORDER BY id"
            );
            $byVisit = [];
            while ($res && ($r = $res->fetch_assoc())) $byVisit[(int)$r['visit_id']][] = $r;
            foreach ($visits as $i => $v) {
                $lines = $byVisit[(int)$v['id']] ?? [];
                $visits[$i]['lines'] = $lines;
                $visits[$i]['total'] = array_sum(array_column($lines, 'line_total'));
            }
        }

        // What they buy, added up across every visit — the thing you
        // actually want when they walk in again.
        $s = $conn->prepare(
            'SELECT i.item,
                    SUM(i.quantity) qty,
                    SUM(i.line_total) total,
                    MIN(i.unit_price) lo,
                    MAX(i.unit_price) hi,
                    MAX(v.visit_date) last_date,
                    COUNT(DISTINCT v.id) times
               FROM wholesale_items i
               JOIN wholesale_visits v ON v.id = i.visit_id
              WHERE v.customer_id = ?
              GROUP BY i.item
              ORDER BY total DESC'
        );
        $s->bind_param('i', $openId);
        $s->execute();
        $summary = $s->get_result()->fetch_all(MYSQLI_ASSOC);
        $s->close();

        foreach ($summary as $r) {
            $openTotal += (float)$r['total'];
            $openUnits += (int)$r['qty'];
        }
    }
}

// Catalogue names, offered as suggestions only. A wholesale buyer may
// take something that is not a retail product at all, so the field
// accepts anything typed into it.
$suggestions = array_keys($ITEMS);

$PAGE  = 'wholesale';
$TITLE = 'Wholesale buyers';
$flash = flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Wholesale buyers · Stall Orders</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f5f7;color:#1c1e21;line-height:1.5}
  input,select,textarea{font-family:inherit}
  input:focus,select:focus,textarea:focus{outline:2px solid #1877f2;outline-offset:-1px}
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
  label{display:block;font-size:12px;color:#65676b;margin-bottom:4px}
  input[type=text],input[type=date],input[type=number],select,textarea{
    width:100%;padding:9px 11px;border:1px solid #ccd0d5;border-radius:8px;font-size:14px;background:#fff}
  textarea{min-height:60px;resize:vertical}
  .grid{display:grid;grid-template-columns:1.4fr 1fr 1.2fr 1.2fr auto;gap:12px;align-items:end}
  button.primary{background:#1877f2;color:#fff;border:none;border-radius:8px;padding:10px 18px;font-size:14px;cursor:pointer}
  button.ghost{background:#fff;border:1px solid #ccd0d5;border-radius:8px;padding:9px 14px;font-size:14px;cursor:pointer}
  button.link{background:none;border:none;color:#1877f2;cursor:pointer;font-size:13px;padding:0}
  button.del{background:none;border:none;color:#c0392b;cursor:pointer;font-size:13px;padding:0}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th,td{text-align:left;padding:9px 8px;border-bottom:1px solid #eef0f2}
  th{font-size:12px;color:#65676b;text-transform:uppercase;letter-spacing:.03em}
  td.num,th.num{text-align:right;font-variant-numeric:tabular-nums}
  tr.dim{opacity:.55}
  tr.total td{font-weight:700;border-top:2px solid #e4e6eb;border-bottom:none}
  .muted{color:#65676b}
  .scroll{overflow-x:auto}
  .tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:16px}
  .tile{background:#fff;border:1px solid #e4e6eb;border-radius:12px;padding:14px 16px}
  .tile .k{font-size:12px;color:#65676b;text-transform:uppercase;letter-spacing:.03em}
  .tile .v{font-size:21px;font-weight:700;margin-top:3px;font-variant-numeric:tabular-nums}
  .warn{background:#fff8e1;border:1px solid #ffe0a3;color:#7a5800;padding:12px 14px;
    border-radius:10px;font-size:13px;margin-bottom:16px}
  .note{background:#f7f8fa;border:1px solid #e9ebee;color:#454749;padding:11px 14px;
    border-radius:10px;font-size:13px;margin-bottom:16px}
  .visit{border:1px solid #eef0f2;border-radius:10px;padding:12px 14px;margin-bottom:10px}
  .visit .hd{display:flex;align-items:center;gap:10px;margin-bottom:8px;flex-wrap:wrap}
  .visit .hd .d{font-weight:600}
  .lines{display:grid;grid-template-columns:2.2fr .6fr 1fr 1fr auto;gap:10px;align-items:end;margin-bottom:8px}
  .badge{font-size:11px;padding:2px 8px;border-radius:999px}
  .b-on{background:#e6f4ea;color:#1a7f37}.b-off{background:#f0f0f0;color:#666}
  .back{text-decoration:none;color:#1877f2;font-size:14px;display:inline-block;margin-bottom:12px}
  @media(max-width:760px){ .grid,.lines{grid-template-columns:1fr} }
</style>

<div class="note">
  A notebook, nothing more. Entries here are <strong>not orders</strong> — they
  are not counted in revenue or profit, they do not show on the Money page,
  Stats or the dashboard, and they do not move stock. Prices are whatever you
  type, and they never change afterwards.
</div>

<?php if (!$ready): ?>
  <div class="warn">
    <strong>Not set up yet.</strong> Run
    <code>sale/api/migrations/2026-09-wholesale.sql</code> against the database,
    then reload. Nothing else on the site is affected until you do.
  </div>

<?php elseif ($open): ?>
  <!-- ── One buyer ───────────────────────────────────────────── -->
  <a class="back" href="wholesale.php">‹ All wholesale buyers</a>

  <div class="card">
    <h2><?= e($open['name']) ?></h2>
    <p class="desc">
      <?php
        $bits = array_filter([
          $open['shop'] ?: null,
          $open['place'] ?: null,
          $open['phone'] ?: null,
        ]);
        echo $bits ? e(implode(' · ', $bits)) : '<span class="muted">No contact details yet</span>';
      ?>
      <?php if (!$open['is_active']): ?> <span class="badge b-off">Not active</span><?php endif; ?>
    </p>
    <?php if ($open['notes']): ?><p class="desc"><?= e($open['notes']) ?></p><?php endif; ?>
    <button class="link" type="button" onclick="document.getElementById('editc').style.display='block';this.style.display='none'">Edit details</button>
    <div id="editc" style="display:none;margin-top:12px">
      <form method="post" class="grid">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="edit_customer">
        <input type="hidden" name="id" value="<?= (int)$open['id'] ?>">
        <div><label>Name</label><input type="text" name="name" value="<?= e($open['name']) ?>" maxlength="120" required></div>
        <div><label>Phone</label><input type="text" name="phone" value="<?= e((string)$open['phone']) ?>" maxlength="20"></div>
        <div><label>Shop</label><input type="text" name="shop" value="<?= e((string)$open['shop']) ?>" maxlength="160"></div>
        <div><label>Place</label><input type="text" name="place" value="<?= e((string)$open['place']) ?>" maxlength="160"></div>
        <div><button class="primary">Save</button></div>
      </form>
      <form method="post" style="margin-top:10px">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="edit_customer">
        <input type="hidden" name="id" value="<?= (int)$open['id'] ?>">
        <input type="hidden" name="name" value="<?= e($open['name']) ?>">
        <input type="hidden" name="phone" value="<?= e((string)$open['phone']) ?>">
        <input type="hidden" name="shop" value="<?= e((string)$open['shop']) ?>">
        <input type="hidden" name="place" value="<?= e((string)$open['place']) ?>">
        <label>Notes</label>
        <textarea name="notes" maxlength="500"><?= e((string)$open['notes']) ?></textarea>
        <button class="primary" style="margin-top:8px">Save notes</button>
      </form>
    </div>
  </div>

  <div class="tiles">
    <div class="tile"><div class="k">Times they came</div><div class="v"><?= count($visits) ?></div></div>
    <div class="tile"><div class="k">Pieces taken</div><div class="v"><?= $openUnits ?></div></div>
    <div class="tile"><div class="k">Value taken</div><div class="v"><?= e(money($openTotal)) ?></div></div>
    <div class="tile"><div class="k">Last visit</div><div class="v" style="font-size:16px">
      <?= $open['last_visit'] ? e(date('d M Y', strtotime($open['last_visit']))) : '—' ?></div></div>
  </div>

  <div class="card">
    <h2>Record what they took</h2>
    <p class="desc">
      Type the price you agreed. It is saved as typed and never
      recalculated, so a catalogue change later will not touch it.
    </p>
    <form method="post" id="visitForm">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="add_visit">
      <input type="hidden" name="customer_id" value="<?= (int)$open['id'] ?>">
      <div style="display:grid;grid-template-columns:200px 1fr;gap:12px;margin-bottom:14px">
        <div><label>Date</label><input type="date" name="visit_date" value="<?= e(date('Y-m-d')) ?>" required></div>
        <div><label>Note (optional)</label><input type="text" name="note" maxlength="500" placeholder="e.g. paid cash, collected himself"></div>
      </div>
      <div id="lines"></div>
      <button class="ghost" type="button" onclick="addLine()" style="margin-top:6px">+ Another product</button>
      <div style="margin-top:14px;display:flex;align-items:center;gap:14px;flex-wrap:wrap">
        <button class="primary">Save entry</button>
        <span class="muted">Total <strong id="runTotal">₹0.00</strong></span>
      </div>
    </form>
  </div>

  <?php if ($summary): ?>
  <div class="card">
    <h2>What this buyer takes</h2>
    <p class="desc">Everything they have ever bought, added up. The price range
      shows where it has moved between visits.</p>
    <div class="scroll">
    <table>
      <thead><tr><th>Product</th><th class="num">Times</th><th class="num">Pieces</th>
        <th class="num">Price</th><th class="num">Value</th><th>Last taken</th></tr></thead>
      <tbody>
      <?php foreach ($summary as $r): ?>
        <tr>
          <td><?= e($r['item']) ?></td>
          <td class="num"><?= (int)$r['times'] ?></td>
          <td class="num"><?= (int)$r['qty'] ?></td>
          <td class="num"><?= abs((float)$r['lo'] - (float)$r['hi']) < 0.005
                ? e(money((float)$r['lo']))
                : e(money((float)$r['lo'])) . ' – ' . e(money((float)$r['hi'])) ?></td>
          <td class="num"><?= e(money((float)$r['total'])) ?></td>
          <td><?= e(date('d M Y', strtotime($r['last_date']))) ?></td>
        </tr>
      <?php endforeach; ?>
        <tr class="total">
          <td>Total</td><td class="num"></td><td class="num"><?= $openUnits ?></td>
          <td class="num"></td><td class="num"><?= e(money($openTotal)) ?></td><td></td>
        </tr>
      </tbody>
    </table>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2><?= count($visits) ?> visit<?= count($visits) === 1 ? '' : 's' ?></h2>
    <?php if (!$visits): ?>
      <p class="desc" style="margin-bottom:0">Nothing recorded for this buyer yet.</p>
    <?php endif; ?>
    <?php foreach ($visits as $v): ?>
      <div class="visit">
        <div class="hd">
          <span class="d"><?= e(date('d M Y', strtotime($v['visit_date']))) ?></span>
          <span class="muted"><?= e(money((float)$v['total'])) ?></span>
          <?php if ($v['note']): ?><span class="muted">· <?= e($v['note']) ?></span><?php endif; ?>
          <span style="margin-left:auto">
            <form method="post" onsubmit="return confirm('Remove this entry?')" style="display:inline">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="delete_visit">
              <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
              <input type="hidden" name="customer_id" value="<?= (int)$open['id'] ?>">
              <button class="del">Remove</button>
            </form>
          </span>
        </div>
        <div class="scroll">
        <table>
          <thead><tr><th>Product</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Value</th></tr></thead>
          <tbody>
          <?php foreach ($v['lines'] as $l): ?>
            <tr>
              <td><?= e($l['item']) ?></td>
              <td class="num"><?= (int)$l['quantity'] ?></td>
              <td class="num"><?= e(money((float)$l['unit_price'])) ?></td>
              <td class="num"><?= e(money((float)$l['line_total'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php if ($v['by_name']): ?>
          <div class="muted" style="font-size:12px;margin-top:6px">Written down by <?= e($v['by_name']) ?></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <datalist id="productNames">
    <?php foreach ($suggestions as $n): ?><option value="<?= e($n) ?>"></option><?php endforeach; ?>
  </datalist>
  <script>
    /* Lines are built here rather than rendered server-side so that a
       blank row is always waiting and nothing needs a page reload. */
    let seq = 0;
    function addLine(item = '', qty = 1, price = '') {
      const id = 'ln' + (seq++);
      const div = document.createElement('div');
      div.className = 'lines';
      div.id = id;
      div.innerHTML = `
        <div><label>Product</label>
          <input type="text" name="item[]" list="productNames" autocomplete="off"
                 placeholder="type anything" value="${item.replace(/"/g, '&quot;')}"></div>
        <div><label>Qty</label>
          <input type="number" name="quantity[]" min="1" step="1" value="${qty}" oninput="recalc()"></div>
        <div><label>Price each (₹)</label>
          <input type="number" name="unit_price[]" min="0" step="0.01" value="${price}"
                 placeholder="0.00" oninput="recalc()"></div>
        <div><label>Line</label>
          <input type="text" class="lt" value="₹0.00" readonly tabindex="-1"
                 style="background:#f7f8fa;color:#65676b"></div>
        <div><button class="ghost" type="button" onclick="dropLine('${id}')">×</button></div>`;
      document.getElementById('lines').appendChild(div);
      recalc();
    }
    function dropLine(id) {
      const box = document.getElementById('lines');
      if (box.children.length <= 1) { addLine(); }
      document.getElementById(id).remove();
      recalc();
    }
    function recalc() {
      let total = 0;
      document.querySelectorAll('#lines .lines').forEach((row) => {
        const q = parseInt(row.querySelector('[name="quantity[]"]').value) || 0;
        const p = parseFloat(row.querySelector('[name="unit_price[]"]').value) || 0;
        const lt = q * p;
        total += lt;
        row.querySelector('.lt').value = '₹' + lt.toFixed(2);
      });
      document.getElementById('runTotal').textContent = '₹' + total.toFixed(2);
    }
    addLine();
  </script>

<?php else: ?>
  <!-- ── Everyone ────────────────────────────────────────────── -->
  <div class="card">
    <h2>Add a wholesale buyer</h2>
    <p class="desc">Somebody who comes back and buys in bulk. Only the name is required.</p>
    <form method="post" class="grid">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="add_customer">
      <div><label>Name</label><input type="text" name="name" maxlength="120" required placeholder="e.g. Ravi"></div>
      <div><label>Phone</label><input type="text" name="phone" maxlength="20"></div>
      <div><label>Shop</label><input type="text" name="shop" maxlength="160" placeholder="e.g. Sri Gift Corner"></div>
      <div><label>Place</label><input type="text" name="place" maxlength="160" placeholder="e.g. Ameerpet"></div>
      <div><button class="primary">Add</button></div>
    </form>
  </div>

  <div class="card">
    <h2><?= count($customers) ?> wholesale buyer<?= count($customers) === 1 ? '' : 's' ?></h2>
    <p class="desc">Open one to see every visit and what they took.</p>
    <div class="scroll">
    <table>
      <thead><tr><th>Name</th><th>Shop / place</th><th>Phone</th>
        <th class="num">Visits</th><th class="num">Value taken</th><th>Last visit</th><th></th></tr></thead>
      <tbody>
      <?php if (!$customers): ?>
        <tr><td colspan="7" class="muted" style="padding:18px 8px">
          Nobody yet. Add a buyer above, then record what they take each time they come.</td></tr>
      <?php endif; ?>
      <?php foreach ($customers as $c): ?>
        <tr class="<?= $c['is_active'] ? '' : 'dim' ?>">
          <td><a href="wholesale.php?c=<?= (int)$c['id'] ?>"><strong><?= e($c['name']) ?></strong></a></td>
          <td><?= e(trim(implode(' · ', array_filter([$c['shop'], $c['place']])))) ?: '—' ?></td>
          <td><?= $c['phone'] ? e($c['phone']) : '—' ?></td>
          <td class="num"><?= (int)$c['visits'] ?></td>
          <td class="num"><?= e(money((float)$c['taken'])) ?></td>
          <td><?= $c['last_visit'] ? e(date('d M Y', strtotime($c['last_visit']))) : '—' ?></td>
          <td style="text-align:right;white-space:nowrap">
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="toggle_customer">
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button class="link"><?= $c['is_active'] ? 'Hide' : 'Show' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
<?php endif; ?>

</body>
</html>
