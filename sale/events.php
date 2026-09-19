<?php
require 'config.php';
$me = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add' || $action === 'edit') {
            $name  = trim($_POST['name'] ?? '');
            $paid  = isset($_POST['is_paid']) ? 1 : 0;
            $cost  = $paid ? round((float)($_POST['entry_cost'] ?? 0), 2) : 0;
            $start = $_POST['start_date'] ?: null;
            $end   = $_POST['end_date'] ?: null;
            $notes = trim($_POST['notes'] ?? '');
            $notes = $notes !== '' ? $notes : null;

            if ($name === '') throw new Exception('Event name is required.');
            if ($cost < 0)    throw new Exception('Entry cost cannot be negative.');
            if ($start && $end && $end < $start) throw new Exception('End date is before the start date.');

            if ($action === 'add') {
                $s = $conn->prepare('INSERT INTO events (name, is_paid, entry_cost, start_date, end_date, notes)
                                     VALUES (?,?,?,?,?,?)');
                $s->bind_param('sidsss', $name, $paid, $cost, $start, $end, $notes);
                $s->execute(); $s->close();
                flash("Event \"$name\" created.");
            } else {
                $id = (int)($_POST['id'] ?? 0);
                if ($id < 1) throw new Exception('Invalid event.');
                $s = $conn->prepare('UPDATE events SET name=?, is_paid=?, entry_cost=?, start_date=?, end_date=?, notes=? WHERE id=?');
                $s->bind_param('sidsssi', $name, $paid, $cost, $start, $end, $notes, $id);
                $s->execute(); $s->close();
                flash('Event updated.');
            }
        } elseif ($action === 'credit_partner') {
            // Credit an event's net payable (full order total minus what has
            // already been credited from this event) to a partner's account.
            $id  = (int)($_POST['id'] ?? 0);
            $pid = (int)($_POST['partner_id'] ?? 0);
            if ($id < 1) throw new Exception('Invalid event.');

            $chk = $conn->prepare('SELECT id FROM partners WHERE id = ?');
            $chk->bind_param('i', $pid); $chk->execute();
            if (!$chk->get_result()->fetch_assoc()) throw new Exception('Choose a partner to credit.');
            $chk->close();

            // Full payable for the event.
            $rev = $conn->prepare('SELECT COALESCE(SUM(total),0) t FROM orders WHERE event_id = ?');
            $rev->bind_param('i', $id); $rev->execute();
            $revenue = (float)$rev->get_result()->fetch_assoc()['t'];
            $rev->close();

            // Already credited from this event (any partner).
            $don = $conn->prepare("SELECT COALESCE(SUM(amount),0) t FROM account_movements
                                   WHERE event_id = ? AND direction = 'credit'");
            $don->bind_param('i', $id); $don->execute();
            $already = (float)$don->get_result()->fetch_assoc()['t'];
            $don->close();

            $net = round($revenue - $already, 2);
            if ($net <= 0.001) throw new Exception('Nothing left to credit for this event.');

            // Look up event + partner names for a readable source line.
            $nm = $conn->prepare('SELECT e.name en, p.name pn FROM events e, partners p WHERE e.id=? AND p.id=?');
            $nm->bind_param('ii', $id, $pid); $nm->execute();
            $names = $nm->get_result()->fetch_assoc(); $nm->close();
            $src  = 'Event sales: ' . ($names['en'] ?? ('#'.$id));
            $note = 'Auto-credited full payable for event.';
            $today = date('Y-m-d');

            $s = $conn->prepare(
                "INSERT INTO account_movements (mov_date, partner_id, event_id, direction, amount, source, note)
                 VALUES (?,?,?,'credit',?,?,?)"
            );
            $s->bind_param('siidss', $today, $pid, $id, $net, $src, $note);
            $s->execute(); $s->close();
            flash('Credited ' . money($net) . ' to ' . ($names['pn'] ?? 'partner') . ' from ' . ($names['en'] ?? 'event') . '.');

        } elseif ($action === 'toggle_active') {
            $id = (int)($_POST['id'] ?? 0);
            $s = $conn->prepare('UPDATE events SET is_active = 1 - is_active WHERE id = ?');
            $s->bind_param('i', $id); $s->execute(); $s->close();
            flash('Event updated.');
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            // Orders keep their history; event_id becomes NULL via the FK.
            $s = $conn->prepare('DELETE FROM events WHERE id = ?');
            $s->bind_param('i', $id); $s->execute(); $s->close();
            flash('Event deleted. Its orders were kept as offline.');
        }
    } catch (Exception $ex) {
        flash($ex->getMessage(), 'error');
    }
    header('Location: events.php');
    exit;
}

$events = $conn->query(
    "SELECT e.*, (SELECT COUNT(*) FROM orders WHERE event_id = e.id) AS order_count,
                 (SELECT COALESCE(SUM(total),0) FROM orders WHERE event_id = e.id) AS revenue,
                 (SELECT COALESCE(SUM(amount),0) FROM account_movements
                    WHERE event_id = e.id AND direction = 'credit') AS credited
     FROM events e ORDER BY e.is_active DESC, e.id DESC"
)->fetch_all(MYSQLI_ASSOC);

// Active partners for the credit picker.
$partners = [];
$pr = $conn->query('SELECT id, name FROM partners WHERE is_active = 1 ORDER BY name');
while ($p = $pr->fetch_assoc()) $partners[] = $p;

$flash = flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Events · Stall Orders</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f5f7;color:#1c1e21;padding:16px;line-height:1.5}
  .wrap{max-width:1000px;margin:0 auto}
  .nav{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px}
  .nav h1{font-size:21px;font-weight:600}
  .nav .who{font-size:13px;color:#65676b}
  .card{background:#fff;border:1px solid #dfe1e5;border-radius:10px;padding:18px;margin-bottom:16px}
  .card h2{font-size:16px;font-weight:600;margin-bottom:14px}
  label{display:block;font-size:13px;color:#65676b;margin-bottom:4px}
  input,select{width:100%;padding:9px 10px;border:1px solid #ccd0d5;border-radius:6px;font-size:14px;font-family:inherit;background:#fff}
  input:focus,select:focus{outline:2px solid #1877f2;outline-offset:-1px}
  .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:14px}
  .check{display:flex;align-items:center;gap:8px;padding:10px;background:#f7f8fa;border-radius:6px}
  .check input{width:auto}.check label{margin:0;font-size:14px;color:#1c1e21}
  button,.btn{padding:9px 16px;border:1px solid #ccd0d5;border-radius:6px;background:#fff;font-size:14px;cursor:pointer;font-family:inherit;text-decoration:none;color:#1c1e21;display:inline-block}
  .primary{background:#1877f2;color:#fff;border-color:#1877f2}.primary:hover{background:#166fe5}
  .danger{color:#c0392b;border-color:#f0c0bb}.danger:hover{background:#fdeceb}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th{text-align:left;padding:9px 8px;border-bottom:2px solid #dfe1e5;font-size:12px;text-transform:uppercase;color:#65676b;letter-spacing:.4px}
  td{padding:11px 8px;border-bottom:1px solid #eceef0;vertical-align:middle}
  .scroll{overflow-x:auto}
  .badge{display:inline-block;padding:3px 9px;border-radius:20px;font-size:12px;font-weight:500}
  .b-on{background:#e3f5eb;color:#1a7f4b}.b-off{background:#f0f2f5;color:#65676b}
  .b-paid{background:#fdf3e0;color:#a06a00}.b-free{background:#e7f0fd;color:#1451a8}
  .flash{padding:11px 14px;border-radius:8px;margin-bottom:16px;font-size:14px}
  .f-success{background:#e3f5eb;color:#1a7f4b;border:1px solid #b8e3ca}
  .f-error{background:#fdeceb;color:#c0392b;border:1px solid #f5c6c2}
  .acts{display:flex;gap:6px;flex-wrap:wrap}.acts button,.acts .btn{padding:5px 10px;font-size:13px}
  details summary{cursor:pointer;font-size:13px;color:#1877f2;margin-top:8px}
</style>
</head>
<body>
<?php
  $PAGE  = 'events';
  $TITLE = 'Events';
  require 'layout.php';
?>

  <?php if ($flash): ?><div class="flash f-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

  <div class="card">
    <h2>Create event</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <div class="grid">
        <div><label for="name">Event name</label><input id="name" name="name" required maxlength="120"></div>
        <div><label for="start_date">Start date</label><input id="start_date" name="start_date" type="date"></div>
        <div><label for="end_date">End date</label><input id="end_date" name="end_date" type="date"></div>
      </div>
      <div class="grid">
        <div class="check">
          <input type="checkbox" id="is_paid" name="is_paid" value="1" onchange="document.getElementById('entry_cost').disabled=!this.checked">
          <label for="is_paid">Paid entry</label>
        </div>
        <div><label for="entry_cost">Entry cost (₹)</label><input id="entry_cost" name="entry_cost" type="number" step="0.01" min="0" value="0" disabled></div>
        <div><label for="notes">Notes</label><input id="notes" name="notes" maxlength="255"></div>
      </div>
      <button type="submit" class="primary">Create event</button>
    </form>
  </div>

  <div class="card">
    <h2>All events (<?= count($events) ?>)</h2>
    <?php if (!$events): ?>
      <p style="color:#8a8d91;font-size:14px">No events yet. Orders without an event are treated as offline / walk-up.</p>
    <?php else: ?>
    <div class="scroll">
      <table>
        <thead><tr><th>Name</th><th>Entry</th><th>Dates</th><th>Orders</th><th>Revenue</th><th>Net payable</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($events as $ev): ?>
          <tr>
            <td>
              <?= e($ev['name']) ?>
              <?php if ($ev['notes']): ?><div style="font-size:12px;color:#65676b"><?= e($ev['notes']) ?></div><?php endif; ?>
            </td>
            <td>
              <?php if ($ev['is_paid']): ?>
                <span class="badge b-paid">Paid <?= money($ev['entry_cost']) ?></span>
              <?php else: ?>
                <span class="badge b-free">Free</span>
              <?php endif; ?>
            </td>
            <td style="font-size:13px;color:#65676b">
              <?= $ev['start_date'] ? date('d M', strtotime($ev['start_date'])) : '—' ?>
              <?php if ($ev['end_date'] && $ev['end_date'] !== $ev['start_date']): ?>
                → <?= date('d M', strtotime($ev['end_date'])) ?>
              <?php endif; ?>
            </td>
            <td><?= (int)$ev['order_count'] ?></td>
            <td><?= money($ev['revenue']) ?></td>
            <?php $net = round((float)$ev['revenue'] - (float)$ev['credited'], 2); ?>
            <td>
              <?php if ((float)$ev['credited'] > 0.001): ?>
                <div style="font-weight:600"><?= money($net) ?></div>
                <div style="font-size:11px;color:#65676b"><?= money($ev['credited']) ?> credited</div>
              <?php else: ?>
                <?= money($net) ?>
              <?php endif; ?>
            </td>
            <td><span class="badge <?= $ev['is_active'] ? 'b-on' : 'b-off' ?>"><?= $ev['is_active'] ? 'Active' : 'Closed' ?></span></td>
            <td>
              <div class="acts">
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle_active">
                  <input type="hidden" name="id" value="<?= (int)$ev['id'] ?>">
                  <button><?= $ev['is_active'] ? 'Close' : 'Reopen' ?></button>
                </form>
                <a class="btn" href="index.php?event=<?= (int)$ev['id'] ?>">View orders</a>
                <form method="post" onsubmit="return confirm('Delete this event? Its orders stay, as offline.')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$ev['id'] ?>">
                  <button class="danger">Delete</button>
                </form>
              </div>

              <?php if ($net > 0.001 && $partners): ?>
                <details>
                  <summary>Credit <?= money($net) ?> to partner</summary>
                  <form method="post" style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;align-items:flex-end"
                        onsubmit="return confirm('Credit <?= e(money($net)) ?> of event sales to this partner\'s account?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="credit_partner">
                    <input type="hidden" name="id" value="<?= (int)$ev['id'] ?>">
                    <div style="min-width:150px">
                      <label style="font-size:12px">Partner</label>
                      <select name="partner_id" required>
                        <option value="">Choose…</option>
                        <?php foreach ($partners as $p): ?>
                          <option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <button class="primary" style="padding:9px 14px">Credit</button>
                  </form>
                  <p style="font-size:11px;color:#8a8d91;margin-top:6px">
                    Adds a credit for the remaining payable to the chosen partner's account (shown under Account movements).
                  </p>
                </details>
              <?php elseif ($net <= 0.001 && (float)$ev['revenue'] > 0): ?>
                <div style="font-size:11px;color:#1a7f4b;margin-top:6px">Fully credited ✓</div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
<?php require 'layout_end.php'; ?>

</body>
</html>
