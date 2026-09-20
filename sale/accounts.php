<?php
/**
 * accounts.php — the places money actually sits.
 *
 * Account movements have always recorded which PARTNER a credit belonged
 * to, plus a free-text source saying things like "Meesho payout". What
 * was never recorded is which account the money landed in, so there was
 * no way to answer "how much is in the current account" without opening
 * the bank app.
 *
 * An account may belong to a partner (a personal UPI that business money
 * sometimes lands in) or to nobody in particular (the shared current
 * account). Both are real, and the settlement maths has to tell them
 * apart, which is why partner_id is optional rather than required.
 *
 * Balances here count every kind of movement, unlike revenue. A transfer
 * between partners is not revenue, but it genuinely moves money out of
 * one account and into another — a balance that ignored it would not
 * match the bank.
 */
require 'config.php';
require_once __DIR__ . '/lib_trade.php';
$me = require_login();

$KINDS = ['bank' => 'Bank account', 'upi' => 'UPI', 'cash' => 'Cash',
          'wallet' => 'Wallet', 'other' => 'Other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        if (!trade_ready($conn)) {
            throw new Exception('The accounts tables are not in the database yet. '
                . 'Run sale/api/migrations/2026-09-channels-accounts-stock.sql first.');
        }
        $action = $_POST['action'] ?? '';

        if ($action === 'add' || $action === 'edit') {
            $id    = (int)($_POST['id'] ?? 0);
            $name  = trim($_POST['name'] ?? '');
            $kind  = (string)($_POST['kind'] ?? 'bank');
            $notes = trim($_POST['notes'] ?? '');
            $pidRaw = trim((string)($_POST['partner_id'] ?? ''));
            // Blank means the business holds it, not "partner zero".
            $pid   = ($pidRaw === '' || $pidRaw === '0') ? null : (int)$pidRaw;

            if ($name === '') throw new Exception('Account name is required.');
            if (!isset($KINDS[$kind])) $kind = 'other';

            if ($pid !== null) {
                $chk = $conn->prepare('SELECT id FROM partners WHERE id = ?');
                $chk->bind_param('i', $pid); $chk->execute();
                if (!$chk->get_result()->fetch_assoc()) $pid = null;
                $chk->close();
            }

            if ($action === 'add') {
                $chk = $conn->prepare('SELECT id FROM accounts WHERE name = ?');
                $chk->bind_param('s', $name); $chk->execute();
                if ($chk->get_result()->fetch_assoc()) {
                    throw new Exception('An account with that name already exists.');
                }
                $chk->close();
                $s = $conn->prepare(
                    'INSERT INTO accounts (name, kind, partner_id, notes) VALUES (?, ?, ?, ?)'
                );
                $s->bind_param('ssis', $name, $kind, $pid, $notes);
                $s->execute(); $s->close();
                flash('Account added.');
            } else {
                $chk = $conn->prepare('SELECT id FROM accounts WHERE name = ? AND id <> ?');
                $chk->bind_param('si', $name, $id); $chk->execute();
                if ($chk->get_result()->fetch_assoc()) {
                    throw new Exception('Another account already has that name.');
                }
                $chk->close();
                $s = $conn->prepare(
                    'UPDATE accounts SET name = ?, kind = ?, partner_id = ?, notes = ? WHERE id = ?'
                );
                $s->bind_param('ssisi', $name, $kind, $pid, $notes, $id);
                $s->execute(); $s->close();
                flash('Account updated.');
            }

        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $s = $conn->prepare('UPDATE accounts SET is_active = 1 - is_active WHERE id = ?');
            $s->bind_param('i', $id); $s->execute(); $s->close();
            flash('Account updated.');
        }
    } catch (Exception $ex) {
        flash($ex->getMessage(), 'error');
    }
    header('Location: accounts.php');
    exit;
}

$ready    = trade_ready($conn);
$accounts = $ready ? accounts_all($conn, false) : [];
$balances = $ready ? account_balances($conn) : [];

$partners = [];
$res = $conn->query('SELECT id, name FROM partners WHERE is_active = 1 ORDER BY name');
while ($res && ($r = $res->fetch_assoc())) $partners[] = $r;

// Index the balances by id so each account row can show its own.
$balById = [];
$unassigned = null;
foreach ($balances as $b) {
    if ($b['id'] === null) { $unassigned = $b; continue; }
    $balById[$b['id']] = $b;
}
$totalHeld = 0.0;
foreach ($balances as $b) $totalHeld += $b['balance'];

$PAGE  = 'accounts';
$TITLE = 'Accounts';
$flash = flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Accounts · Stall Orders</title>
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
  .grid{display:grid;grid-template-columns:1.5fr 1fr 1.2fr 1.4fr auto;gap:12px;align-items:end}
  label{display:block;font-size:12px;color:#65676b;margin-bottom:4px}
  input[type=text],select{width:100%;padding:9px 11px;border:1px solid #ccd0d5;border-radius:8px;font-size:14px;background:#fff}
  button.primary{background:#1877f2;color:#fff;border:none;border-radius:8px;padding:10px 18px;font-size:14px;cursor:pointer}
  button.link{background:none;border:none;color:#1877f2;cursor:pointer;font-size:13px;padding:0}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th,td{text-align:left;padding:10px 8px;border-bottom:1px solid #eef0f2}
  th{font-size:12px;color:#65676b;text-transform:uppercase;letter-spacing:.03em}
  td.num,th.num{text-align:right;font-variant-numeric:tabular-nums}
  tr.dim{opacity:.5}
  tr.total td{font-weight:700;border-top:2px solid #e4e6eb;border-bottom:none}
  .badge{font-size:11px;padding:2px 8px;border-radius:999px;white-space:nowrap}
  .b-on{background:#e6f4ea;color:#1a7f37}.b-off{background:#f0f0f0;color:#666}
  .b-biz{background:#eef2ff;color:#3b4cca}.b-own{background:#fff3e0;color:#b26a00}
  .edit-row{display:none;background:#f7f8fa}
  .edit-row.show{display:table-row}
  .warn{background:#fff8e1;border:1px solid #ffe0a3;color:#7a5800;padding:12px 14px;
    border-radius:10px;font-size:13px;margin-bottom:16px}
  .neg{color:#c0392b}
  @media(max-width:700px){ .grid{grid-template-columns:1fr} }
</style>

<?php if (!$ready): ?>
  <div class="warn">
    <strong>Not set up yet.</strong> The accounts table is missing. Run
    <code>sale/api/migrations/2026-09-channels-accounts-stock.sql</code> against the
    database, then reload this page.
  </div>
<?php else: ?>

<div class="card">
  <h2>Add an account</h2>
  <p class="desc">
    Anywhere money sits. Leave <em>Held by</em> empty for a shared business
    account; pick a partner when it is their own UPI or bank account that
    business money lands in.
  </p>
  <form method="post" class="grid">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="add">
    <div><label>Account name</label><input type="text" name="name" maxlength="80" required placeholder="e.g. ICICI current"></div>
    <div><label>Type</label><select name="kind">
      <?php foreach ($KINDS as $k => $lbl): ?><option value="<?= e($k) ?>"><?= e($lbl) ?></option><?php endforeach; ?>
    </select></div>
    <div><label>Held by</label><select name="partner_id">
      <option value="">The business</option>
      <?php foreach ($partners as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
    </select></div>
    <div><label>Note (optional)</label><input type="text" name="notes" maxlength="255"></div>
    <div><button class="primary">Add</button></div>
  </form>
</div>

<div class="card">
  <h2>Accounts and balances</h2>
  <p class="desc">
    Credits in, debits out, from every account movement — transfers and
    profit shares included, because those move real money even though they
    are not revenue.
  </p>
  <table>
    <thead><tr>
      <th>Account</th><th>Type</th><th>Held by</th>
      <th class="num">In</th><th class="num">Out</th><th class="num">Balance</th>
      <th>Status</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($accounts as $a):
        $b = $balById[$a['id']] ?? ['credit' => 0.0, 'debit' => 0.0, 'balance' => 0.0];
    ?>
      <tr class="<?= $a['is_active'] ? '' : 'dim' ?>">
        <td>
          <strong><?= e($a['name']) ?></strong>
          <?php if ($a['notes']): ?><div style="font-size:12px;color:#65676b"><?= e($a['notes']) ?></div><?php endif; ?>
        </td>
        <td><?= e($KINDS[$a['kind']] ?? $a['kind']) ?></td>
        <td><?= $a['partner_id'] === null
              ? '<span class="badge b-biz">The business</span>'
              : '<span class="badge b-own">' . e((string)$a['partner_name']) . '</span>' ?></td>
        <td class="num"><?= e(money($b['credit'])) ?></td>
        <td class="num"><?= e(money($b['debit'])) ?></td>
        <td class="num <?= $b['balance'] < 0 ? 'neg' : '' ?>"><?= e(money($b['balance'])) ?></td>
        <td><span class="badge <?= $a['is_active'] ? 'b-on' : 'b-off' ?>"><?= $a['is_active'] ? 'Active' : 'Off' ?></span></td>
        <td style="text-align:right;white-space:nowrap">
          <button class="link" type="button" onclick="document.getElementById('ea<?= (int)$a['id'] ?>').classList.toggle('show')">Edit</button>
          &nbsp;
          <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
            <button class="link"><?= $a['is_active'] ? 'Turn off' : 'Turn on' ?></button>
          </form>
        </td>
      </tr>
      <tr class="edit-row" id="ea<?= (int)$a['id'] ?>">
        <td colspan="8">
          <form method="post" class="grid">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
            <div><label>Name</label><input type="text" name="name" value="<?= e($a['name']) ?>" maxlength="80" required></div>
            <div><label>Type</label><select name="kind">
              <?php foreach ($KINDS as $k => $lbl): ?>
                <option value="<?= e($k) ?>" <?= $a['kind'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
              <?php endforeach; ?>
            </select></div>
            <div><label>Held by</label><select name="partner_id">
              <option value="">The business</option>
              <?php foreach ($partners as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= $a['partner_id'] === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
              <?php endforeach; ?>
            </select></div>
            <div><label>Note</label><input type="text" name="notes" value="<?= e((string)$a['notes']) ?>" maxlength="255"></div>
            <div><button class="primary">Save</button></div>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>

    <?php if ($unassigned): ?>
      <tr>
        <td><strong>Not assigned to an account</strong>
          <div style="font-size:12px;color:#65676b">
            <?= (int)$unassigned['movements'] ?> movement<?= $unassigned['movements'] === 1 ? '' : 's' ?>
            recorded before accounts existed. Open one on the
            <a href="movements.php">movements page</a> to say where it landed.
          </div>
        </td>
        <td>—</td><td>—</td>
        <td class="num"><?= e(money($unassigned['credit'])) ?></td>
        <td class="num"><?= e(money($unassigned['debit'])) ?></td>
        <td class="num <?= $unassigned['balance'] < 0 ? 'neg' : '' ?>"><?= e(money($unassigned['balance'])) ?></td>
        <td></td><td></td>
      </tr>
    <?php endif; ?>

      <tr class="total">
        <td colspan="5">Held in total</td>
        <td class="num <?= $totalHeld < 0 ? 'neg' : '' ?>"><?= e(money($totalHeld)) ?></td>
        <td colspan="2"></td>
      </tr>
    </tbody>
  </table>
</div>

<?php endif; ?>
</body>
</html>
