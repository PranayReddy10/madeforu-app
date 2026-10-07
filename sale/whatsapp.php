<?php
/**
 * WhatsApp: send one message, or the same message to a list from Excel
 * or CSV, each personalised with that row's values ({Name}, {Amount}, …).
 *
 * Free mode opens each chat in WhatsApp with the message typed in, one
 * click per contact. Automatic mode sends by itself through Meta's
 * WhatsApp Business Cloud API: approved templates only, charged per
 * message by Meta. See lib_whatsapp.php for why there is no third way.
 */
require 'config.php';
require_once __DIR__ . '/lib_whatsapp.php';
$me = require_login();
wa_ensure_tables($conn);

const WA_SAMPLE_NUMBERS = ['919876543210', '919123456780', '919988776655'];

// ── Demo file ──────────────────────────────────────────────────────
if (($_GET['sample'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="whatsapp-contacts-sample.csv"');
    echo "\xEF\xBB\xBF";   // so Excel reads ₹ and names in Indian scripts correctly
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Phone', 'Name', 'Order', 'Amount', 'City']);
    fputcsv($out, ['98765 43210', 'Priya Sharma', 'MFU-0101', '450', 'Hyderabad']);
    fputcsv($out, ['+91 91234 56780', 'Rahul Verma', 'MFU-0102', '1200', 'Secunderabad']);
    fputcsv($out, ['09988776655', 'Anita Rao', 'MFU-0103', '650', 'Warangal']);
    fclose($out);
    exit;
}

/** JSON for the page's own requests. */
function wa_json(array $payload): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Actions ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $ajax = !empty($_POST['ajax']);
    $back = 'whatsapp.php';

    try {
        // Free mode: the page opened this recipient's chat; record it.
        if ($action === 'opened') {
            $id = (int)($_POST['id'] ?? 0);
            $s = $conn->prepare("UPDATE wa_recipients SET status = 'opened', sent_at = NOW() WHERE id = ? AND status = 'pending'");
            $s->bind_param('i', $id);
            $s->execute();
            $s->close();
            wa_json(['ok' => true]);
        }

        // Automatic mode: send the next one through the Cloud API.
        if ($action === 'send_next') {
            $cid = (int)($_POST['c'] ?? 0);
            $s = $conn->prepare('SELECT * FROM wa_campaigns WHERE id = ?');
            $s->bind_param('i', $cid);
            $s->execute();
            $camp = $s->get_result()->fetch_assoc();
            $s->close();
            if (!$camp || $camp['mode'] !== 'auto') wa_json(['ok' => false, 'error' => 'Not an automatic campaign.']);
            if (!wa_api_ready($conn)) wa_json(['ok' => false, 'error' => 'The WhatsApp Cloud API is not set up.']);

            for ($tries = 0; $tries < 5; $tries++) {
                $s = $conn->prepare("SELECT id, phone, name, vars_json FROM wa_recipients
                                      WHERE campaign_id = ? AND status = 'pending' ORDER BY id LIMIT 1");
                $s->bind_param('i', $cid);
                $s->execute();
                $r = $s->get_result()->fetch_assoc();
                $s->close();
                if (!$r) wa_json(['ok' => true, 'done' => true]);
                // Claim it, so two open tabs never send the same message twice.
                $s = $conn->prepare("UPDATE wa_recipients SET status = 'sending' WHERE id = ? AND status = 'pending'");
                $s->bind_param('i', $r['id']);
                $s->execute();
                $claimed = $s->affected_rows === 1;
                $s->close();
                if ($claimed) break;
                $r = null;
            }
            if (!$r) wa_json(['ok' => true, 'done' => false, 'busy' => true]);

            $vars = json_decode((string)$r['vars_json'], true) ?: [];
            $error = wa_api_send_template($conn, $r['phone'], $camp['template_name'], $camp['template_lang'],
                wa_template_values($camp['template_params'], $vars));
            $status = $error === null ? 'sent' : 'failed';
            $err = (string)$error;
            $s = $conn->prepare('UPDATE wa_recipients SET status = ?, error = ?, sent_at = NOW() WHERE id = ?');
            $s->bind_param('ssi', $status, $err, $r['id']);
            $s->execute();
            $s->close();
            wa_json(['ok' => true, 'done' => false, 'id' => (int)$r['id'], 'name' => $r['name'],
                     'phone' => $r['phone'], 'status' => $status, 'error' => $error]);
        }

        if ($action === 'campaign') {
            $name = trim((string)($_POST['name'] ?? ''));
            $mode = ($_POST['mode'] ?? 'free') === 'auto' ? 'auto' : 'free';
            $message = trim((string)($_POST['message'] ?? ''));
            $tName = trim((string)($_POST['template_name'] ?? ''));
            $tLang = trim((string)($_POST['template_lang'] ?? ''));
            $tParams = trim((string)($_POST['template_params'] ?? ''));
            $f = $_FILES['list'] ?? null;
            if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) throw new Exception('Choose the Excel or CSV file with your contacts.');
            if ($f['error'] !== UPLOAD_ERR_OK) throw new Exception('The upload failed (error ' . (int)$f['error'] . '). Try again.');
            if ($mode === 'free' && $message === '') throw new Exception('Write the message to send.');
            if ($mode === 'auto') {
                if (!wa_api_ready($conn)) throw new Exception('Automatic sending needs the WhatsApp Cloud API set up first (bottom of this page).');
                if ($tName === '') throw new Exception('Automatic sending needs the name of an approved template.');
            }

            [$columns, $rows] = wa_read_list($f['tmp_name'], $f['name']);
            $phoneCol = wa_phone_column($columns);
            if ($phoneCol === null) {
                throw new Exception('No phone column found. Name the column with the numbers "Phone" (columns found: '
                    . implode(', ', $columns) . ').');
            }
            $nameCol = wa_name_column($columns);
            if ($name === '') $name = pathinfo($f['name'], PATHINFO_FILENAME) . ' · ' . date('j M');

            $optout = wa_optouts($conn);
            $seen = [];
            $add = [];
            $skipped = ['invalid' => 0, 'duplicate' => 0, 'optout' => 0, 'sample' => 0];
            foreach ($rows as $row) {
                $p = wa_phone((string)$row[$phoneCol]);
                if ($p === null) { $skipped['invalid']++; continue; }
                if (in_array($p, WA_SAMPLE_NUMBERS, true)) { $skipped['sample']++; continue; }
                if (isset($optout[$p])) { $skipped['optout']++; continue; }
                if (isset($seen[$p])) { $skipped['duplicate']++; continue; }
                $seen[$p] = true;
                $add[] = [$p, $nameCol ? mb_substr((string)$row[$nameCol], 0, 120) : '', $row];
            }
            if (!$add) {
                throw new Exception('No contact in that file can be messaged: ' . $skipped['invalid'] . ' invalid number(s), '
                    . $skipped['sample'] . ' sample row(s), ' . $skipped['optout'] . ' opted out, '
                    . $skipped['duplicate'] . ' duplicate(s). Replace the sample rows with your own contacts.');
            }

            $conn->begin_transaction();
            $cols = json_encode($columns, JSON_UNESCAPED_UNICODE);
            $by = (int)$me['id'];
            $s = $conn->prepare('INSERT INTO wa_campaigns (name, mode, message, template_name, template_lang, template_params, columns_json, created_by)
                                 VALUES (?,?,?,?,?,?,?,?)');
            $s->bind_param('sssssssi', $name, $mode, $message, $tName, $tLang, $tParams, $cols, $by);
            $s->execute();
            $cid = (int)$conn->insert_id;
            $s->close();
            $s = $conn->prepare('INSERT INTO wa_recipients (campaign_id, phone, name, vars_json) VALUES (?,?,?,?)');
            foreach ($add as [$p, $n, $row]) {
                $v = json_encode($row, JSON_UNESCAPED_UNICODE);
                $s->bind_param('isss', $cid, $p, $n, $v);
                $s->execute();
            }
            $s->close();
            $conn->commit();

            $notes = array_filter([
                $skipped['invalid'] ? $skipped['invalid'] . ' invalid number(s)' : '',
                $skipped['duplicate'] ? $skipped['duplicate'] . ' duplicate(s)' : '',
                $skipped['optout'] ? $skipped['optout'] . ' opted out' : '',
                $skipped['sample'] ? $skipped['sample'] . ' sample row(s)' : '',
            ]);
            flash(count($add) . ' contact(s) ready.' . ($notes ? ' Skipped: ' . implode(', ', $notes) . '.' : ''));
            $back = 'whatsapp.php?c=' . $cid;

        } elseif ($action === 'retry_failed') {
            $cid = (int)($_POST['c'] ?? 0);
            $s = $conn->prepare("UPDATE wa_recipients SET status = 'pending', error = '' WHERE campaign_id = ? AND status IN ('failed','sending')");
            $s->bind_param('i', $cid);
            $s->execute();
            $n = $s->affected_rows;
            $s->close();
            flash("$n message(s) queued again.");
            $back = 'whatsapp.php?c=' . $cid;

        } elseif ($action === 'reset_opened') {
            $cid = (int)($_POST['c'] ?? 0);
            $s = $conn->prepare("UPDATE wa_recipients SET status = 'pending', sent_at = NULL WHERE campaign_id = ? AND status = 'opened'");
            $s->bind_param('i', $cid);
            $s->execute();
            $n = $s->affected_rows;
            $s->close();
            flash("$n contact(s) put back in the queue.");
            $back = 'whatsapp.php?c=' . $cid;

        } elseif ($action === 'delete_campaign') {
            $cid = (int)($_POST['c'] ?? 0);
            foreach (['DELETE FROM wa_recipients WHERE campaign_id = ?', 'DELETE FROM wa_campaigns WHERE id = ?'] as $sql) {
                $s = $conn->prepare($sql);
                $s->bind_param('i', $cid);
                $s->execute();
                $s->close();
            }
            flash('List deleted.');

        } elseif ($action === 'single_api') {
            if (!wa_api_ready($conn)) throw new Exception('Set up the WhatsApp Cloud API first (bottom of this page).');
            $p = wa_phone((string)($_POST['phone'] ?? ''));
            if ($p === null) throw new Exception('That phone number is not valid.');
            $tName = trim((string)($_POST['template_name'] ?? ''));
            if ($tName === '') throw new Exception('Give the name of an approved template.');
            $params = array_map('trim', array_filter(explode('|', (string)($_POST['values'] ?? '')), fn($v) => trim($v) !== ''));
            $error = wa_api_send_template($conn, $p, $tName, trim((string)($_POST['template_lang'] ?? '')), $params);
            if ($error !== null) throw new Exception('WhatsApp did not send it: ' . $error);
            flash('Sent to +' . $p . '.');

        } elseif ($action === 'api_settings') {
            $token = trim((string)($_POST['wa_api_token'] ?? ''));
            if ($token !== '') wa_setting_set($conn, 'wa_api_token', $token);   // blank = keep the saved one
            foreach (['wa_api_phone_id', 'wa_api_version', 'wa_template_name', 'wa_template_lang', 'wa_template_params'] as $k) {
                wa_setting_set($conn, $k, trim((string)($_POST[$k] ?? '')));
            }
            flash('WhatsApp Cloud API settings saved.');

        } elseif ($action === 'api_forget') {
            wa_setting_set($conn, 'wa_api_token', '');
            flash('Access token removed. Automatic sending is off until a new one is saved.');

        } elseif ($action === 'optout') {
            wa_setting_set($conn, 'wa_optout', trim((string)($_POST['wa_optout'] ?? '')));
            flash('Do-not-message list saved. Those numbers are skipped in every new list.');
        }
    } catch (Throwable $ex) {
        try { $conn->rollback(); } catch (Throwable $e) { /* nothing was open */ }
        if ($ajax) wa_json(['ok' => false, 'error' => $ex->getMessage()]);
        flash($ex->getMessage(), 'error');
        if (!empty($_POST['c'])) $back = 'whatsapp.php?c=' . (int)$_POST['c'];
    }
    header('Location: ' . $back);
    exit;
}

// ── Page ───────────────────────────────────────────────────────────
$apiReady = wa_api_ready($conn);
$cfg = [
    'phone_id' => wa_setting($conn, 'wa_api_phone_id'),
    'version'  => wa_setting($conn, 'wa_api_version', 'v22.0'),
    't_name'   => wa_setting($conn, 'wa_template_name'),
    't_lang'   => wa_setting($conn, 'wa_template_lang', 'en'),
    't_params' => wa_setting($conn, 'wa_template_params'),
    'optout'   => wa_setting($conn, 'wa_optout'),
];
$campaign = null;
if (!empty($_GET['c'])) {
    $cid = (int)$_GET['c'];
    $s = $conn->prepare('SELECT * FROM wa_campaigns WHERE id = ?');
    $s->bind_param('i', $cid);
    $s->execute();
    $campaign = $s->get_result()->fetch_assoc();
    $s->close();
}
$flash = flash();
$defaultMessage = "Hi {first_name}! 🎁\nYour MadeForU order {Order} of ₹{Amount} is ready.\nThank you for shopping with us!";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WhatsApp · Stall Orders</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f5f7;color:#1c1e21;padding:16px;line-height:1.5}
  .card{background:#fff;border:1px solid #dfe1e5;border-radius:10px;padding:18px;margin-bottom:16px}
  .card h2{font-size:16px;font-weight:600;margin-bottom:6px}
  p.hint{font-size:13px;color:#65676b;margin-bottom:12px}
  label{display:block;font-size:13px;color:#65676b;margin-bottom:4px}
  input,textarea,select{width:100%;padding:9px 10px;border:1px solid #ccd0d5;border-radius:6px;font-size:14px;font-family:inherit;background:#fff}
  textarea{min-height:110px}
  input[type=file]{padding:7px;background:#fafbfc}
  input[type=radio]{width:auto;margin-right:6px}
  .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:12px}
  button,.btn{padding:9px 16px;border:1px solid #ccd0d5;border-radius:6px;background:#fff;font-size:14px;cursor:pointer;
              font-family:inherit;text-decoration:none;color:#1c1e21;display:inline-block}
  button:hover,.btn:hover{background:#f0f2f5}
  .primary{background:#1877f2;color:#fff;border-color:#1877f2} .primary:hover{background:#166fe5}
  .wa{background:#25D366;color:#fff;border-color:#25D366} .wa:hover{background:#1ebe5b}
  .danger{color:#c0392b;border-color:#f0c0bb}
  .flash{padding:11px 14px;border-radius:8px;margin-bottom:16px;font-size:14px}
  .f-success{background:#e3f5eb;color:#1a7f4b;border:1px solid #b8e3ca}
  .f-error{background:#fdeceb;color:#c0392b;border:1px solid #f5c6c2}
  .modes{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin-bottom:12px}
  .mode{border:2px solid #dfe1e5;border-radius:10px;padding:12px 14px;cursor:pointer;display:block;color:#1c1e21}
  .mode:has(input:checked){border-color:#25D366;background:#f2fbf5}
  .mode b{font-size:15px} .mode small{display:block;color:#65676b;font-size:12.5px;margin-top:4px}
  .tag{display:inline-block;font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;margin-left:6px}
  .t-free{background:#e3f5eb;color:#1a7f4b} .t-paid{background:#fff4e0;color:#9a6300}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th{text-align:left;padding:8px;border-bottom:2px solid #dfe1e5;font-size:12px;text-transform:uppercase;color:#65676b}
  td{padding:8px;border-bottom:1px solid #eceef0;vertical-align:top}
  .scroll{overflow-x:auto}
  .st{font-size:12px;font-weight:600;padding:2px 8px;border-radius:20px;white-space:nowrap}
  .s-pending{background:#f0f2f5;color:#65676b} .s-opened,.s-sent{background:#e3f5eb;color:#1a7f4b}
  .s-failed{background:#fdeceb;color:#c0392b} .s-sending{background:#e7f0fd;color:#1877f2}
  .bar{height:10px;background:#eceef0;border-radius:99px;overflow:hidden;margin:10px 0}
  .bar div{height:100%;background:#25D366;transition:width .3s}
  .preview{white-space:pre-wrap;background:#e7fbe6;border:1px solid #c8eec5;border-radius:10px;padding:10px 12px;font-size:14px}
  .stats{display:flex;gap:18px;flex-wrap:wrap;font-size:14px;margin:6px 0}
  .stats b{font-size:20px;display:block}
  details summary{cursor:pointer;color:#1877f2;font-size:14px;font-weight:600}
  code{background:#f0f2f5;padding:1px 5px;border-radius:4px;font-size:12.5px}
  .row-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:12px}
</style>
</head>
<body>
<?php
  $PAGE  = 'whatsapp';
  $TITLE = 'WhatsApp';
  require 'layout.php';
?>

  <?php if ($flash): ?><div class="flash f-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if ($campaign): ?>
<?php
  $cid = (int)$campaign['id'];
  $counts = ['pending' => 0, 'opened' => 0, 'sent' => 0, 'failed' => 0, 'sending' => 0];
  $s = $conn->prepare('SELECT status, COUNT(*) n FROM wa_recipients WHERE campaign_id = ? GROUP BY status');
  $s->bind_param('i', $cid);
  $s->execute();
  foreach ($s->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $counts[$r['status']] = (int)$r['n'];
  $s->close();
  $total = array_sum($counts);
  $done = $counts['opened'] + $counts['sent'];
  $s = $conn->prepare('SELECT id, phone, name, vars_json, status, error, sent_at FROM wa_recipients WHERE campaign_id = ? ORDER BY id');
  $s->bind_param('i', $cid);
  $s->execute();
  $recipients = $s->get_result()->fetch_all(MYSQLI_ASSOC);
  $s->close();
  $isAuto = $campaign['mode'] === 'auto';
  // Free mode: every pending chat's link is prepared here, so a click
  // opens it straight away; opening it after a request would be blocked
  // as a pop-up.
  $queue = [];
  foreach ($recipients as $r) {
      if ($r['status'] !== 'pending' || $isAuto) continue;
      $vars = json_decode((string)$r['vars_json'], true) ?: [];
      $queue[] = ['id' => (int)$r['id'], 'phone' => $r['phone'], 'name' => $r['name'],
                  'text' => wa_fill((string)$campaign['message'], $vars)];
  }
  $first = $recipients[0] ?? null;
  $firstVars = $first ? (json_decode((string)$first['vars_json'], true) ?: []) : [];
?>
  <p style="margin-bottom:12px"><a href="whatsapp.php">‹ All lists</a></p>
  <div class="card">
    <h2><?= e($campaign['name']) ?>
      <span class="tag <?= $isAuto ? 't-paid' : 't-free' ?>"><?= $isAuto ? 'Automatic · Cloud API' : 'Free · one click each' ?></span></h2>
    <div class="stats">
      <div><b><?= $total ?></b>contacts</div>
      <div><b id="nDone"><?= $done ?></b><?= $isAuto ? 'sent' : 'opened' ?></div>
      <div><b id="nLeft"><?= $counts['pending'] ?></b>to go</div>
      <?php if ($isAuto): ?><div><b id="nFail" style="color:#c0392b"><?= $counts['failed'] ?></b>failed</div><?php endif; ?>
    </div>
    <div class="bar"><div id="bar" style="width:<?= $total ? round($done / $total * 100) : 0 ?>%"></div></div>

    <?php if ($first): ?>
      <label style="margin-top:8px">How the first message reads</label>
      <div class="preview"><?= e($isAuto
          ? 'Template "' . $campaign['template_name'] . '" with: ' . implode(' | ', wa_template_values($campaign['template_params'], $firstVars))
          : wa_fill((string)$campaign['message'], $firstVars)) ?></div>
    <?php endif; ?>

    <?php if (!$isAuto): ?>
      <div class="row-actions">
        <label style="margin:0">Open in</label>
        <select id="target" style="width:auto">
          <option value="web">WhatsApp Web (browser)</option>
          <option value="app">WhatsApp app on this computer</option>
          <option value="phone">Phone (wa.me link)</option>
        </select>
        <button class="wa" id="openNext" <?= $queue ? '' : 'disabled' ?>>Open next chat ›</button>
        <span id="nextWho" class="hint" style="font-size:13px;color:#65676b"></span>
      </div>
      <p class="hint" style="margin-top:10px">Each click opens the next contact's chat with the message already typed; press
        <b>Send</b> (or Enter) in WhatsApp, come back, click again. WhatsApp Web reuses one tab. Free, and safe for
        your number.</p>
    <?php else: ?>
      <div class="row-actions">
        <button class="wa" id="startAuto" <?= $counts['pending'] ? '' : 'disabled' ?>>Start sending</button>
        <button id="pauseAuto" disabled>Pause</button>
        <span id="autoNote" style="font-size:13px;color:#65676b"></span>
      </div>
      <p class="hint" style="margin-top:10px">Sends one message every second or so through Meta's API; keep this page open
        until it finishes (pause and resume any time). Meta charges per message, and new WhatsApp Business numbers can
        message a limited number of people a day until Meta raises the limit.</p>
    <?php endif; ?>

    <div class="row-actions">
      <?php if ($counts['failed'] || $counts['sending']): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="retry_failed"><input type="hidden" name="c" value="<?= $cid ?>">
          <button>Retry failed (<?= $counts['failed'] + $counts['sending'] ?>)</button></form>
      <?php endif; ?>
      <?php if ($counts['opened']): ?>
        <form method="post" onsubmit="return confirm('Put every opened contact back in the queue?')"><?= csrf_field() ?>
          <input type="hidden" name="action" value="reset_opened"><input type="hidden" name="c" value="<?= $cid ?>">
          <button>Start the queue again</button></form>
      <?php endif; ?>
      <form method="post" onsubmit="return confirm('Delete this list and its history?')"><?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_campaign"><input type="hidden" name="c" value="<?= $cid ?>">
        <button class="danger">Delete list</button></form>
    </div>
  </div>

  <div class="card">
    <h2>Contacts</h2>
    <div class="scroll"><table>
      <thead><tr><th>#</th><th>Name</th><th>Phone</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($recipients as $i => $r): ?>
        <tr id="r<?= (int)$r['id'] ?>">
          <td><?= $i + 1 ?></td>
          <td><?= e($r['name'] !== '' ? $r['name'] : '—') ?></td>
          <td>+<?= e($r['phone']) ?></td>
          <td><span class="st s-<?= e($r['status']) ?>"><?= e(['pending' => 'To send', 'opened' => 'Opened', 'sent' => 'Sent',
              'failed' => 'Failed', 'sending' => 'Sending…'][$r['status']] ?? $r['status']) ?></span>
            <?php if ($r['error'] !== ''): ?><br><small style="color:#c0392b"><?= e($r['error']) ?></small><?php endif; ?></td>
          <td><?php if (!$isAuto): $v = json_decode((string)$r['vars_json'], true) ?: []; ?>
            <a href="<?= e(wa_link($r['phone'], wa_fill((string)$campaign['message'], $v), 'phone')) ?>" target="_blank"
               rel="noopener" data-one="<?= (int)$r['id'] ?>">Open</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

  <script>
  (function () {
    const csrf = <?= json_encode(csrf_token()) ?>;
    const cid = <?= $cid ?>;
    const total = <?= $total ?>;
    let done = <?= $done ?>, left = <?= $counts['pending'] ?>, failed = <?= $counts['failed'] ?>;
    const post = (data) => fetch('whatsapp.php', {
      method: 'POST', credentials: 'same-origin',
      body: new URLSearchParams(Object.assign({ csrf, ajax: '1' }, data)),
    }).then((r) => r.json());
    const paint = () => {
      document.getElementById('nDone').textContent = done;
      document.getElementById('nLeft').textContent = left;
      const f = document.getElementById('nFail'); if (f) f.textContent = failed;
      document.getElementById('bar').style.width = (total ? Math.round(done / total * 100) : 0) + '%';
    };
    const mark = (id, cls, label, error) => {
      const row = document.getElementById('r' + id); if (!row) return;
      row.cells[3].innerHTML = '<span class="st s-' + cls + '">' + label + '</span>'
        + (error ? '<br><small style="color:#c0392b"></small>' : '');
      if (error) row.cells[3].querySelector('small').textContent = error;
    };

    // ── Free: one click, one chat ─────────────────────────────────
    const queue = <?= json_encode($queue, JSON_UNESCAPED_UNICODE) ?>;
    const next = document.getElementById('openNext');
    const target = document.getElementById('target');
    if (target) {
      try { target.value = localStorage.getItem('mfu.waTarget') || (/Android|iPhone|iPad/.test(navigator.userAgent) ? 'phone' : 'web'); } catch (e) {}
      target.onchange = () => { try { localStorage.setItem('mfu.waTarget', target.value); } catch (e) {} };
    }
    const who = () => {
      const el = document.getElementById('nextWho');
      if (el) el.textContent = queue.length ? 'Next: ' + (queue[0].name || '+' + queue[0].phone) : 'All done.';
    };
    const link = (q) => {
      const text = encodeURIComponent(q.text);
      if (target.value === 'app') return 'whatsapp://send?phone=' + q.phone + '&text=' + text;
      if (target.value === 'phone') return 'https://wa.me/' + q.phone + '?text=' + text;
      return 'https://web.whatsapp.com/send?phone=' + q.phone + '&text=' + text;
    };
    if (next) {
      who();
      next.onclick = () => {
        const q = queue.shift(); if (!q) return;
        // Opened inside the click itself, so no pop-up blocker objects.
        if (target.value === 'app') window.location.href = link(q);
        else window.open(link(q), 'mfu_whatsapp');
        mark(q.id, 'opened', 'Opened');
        done++; left--; paint(); who();
        if (!queue.length) next.disabled = true;
        post({ action: 'opened', id: q.id }).catch(() => {});
      };
    }
    document.querySelectorAll('[data-one]').forEach((a) => a.addEventListener('click', () => {
      const id = Number(a.dataset.one);
      const i = queue.findIndex((q) => q.id === id);
      if (i >= 0) { queue.splice(i, 1); done++; left--; paint(); who(); }
      mark(id, 'opened', 'Opened');
      post({ action: 'opened', id }).catch(() => {});
    }));

    // ── Automatic: Cloud API, one at a time ───────────────────────
    const start = document.getElementById('startAuto');
    const pause = document.getElementById('pauseAuto');
    const note = document.getElementById('autoNote');
    let running = false;
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    async function loop() {
      while (running) {
        let r;
        try { r = await post({ action: 'send_next', c: cid }); }
        catch (e) { note.textContent = 'Connection lost; paused. Press Start to continue.'; break; }
        if (!r.ok) { note.textContent = r.error; break; }
        if (r.done) {
          note.textContent = 'Finished.'; start.disabled = true;
          // Failures get a Retry button, which the page draws on load.
          if (failed) { note.textContent = 'Finished, with ' + failed + ' failed. Refreshing…'; setTimeout(() => location.reload(), 1500); }
          break;
        }
        if (r.busy) { await sleep(1500); continue; }
        left--;
        if (r.status === 'sent') { done++; mark(r.id, 'sent', 'Sent'); }
        else { failed++; mark(r.id, 'failed', 'Failed', r.error); }
        note.textContent = (r.status === 'sent' ? 'Sent to ' : 'Failed: ') + (r.name || '+' + r.phone);
        paint();
        await sleep(1100);
      }
      running = false;
      if (start && left > 0) start.disabled = false;
      if (pause) pause.disabled = true;
    }
    if (start) start.onclick = () => { running = true; start.disabled = true; pause.disabled = false; note.textContent = 'Sending…'; loop(); };
    if (pause) pause.onclick = () => { running = false; note.textContent = 'Pausing after this message…'; };
    window.addEventListener('beforeunload', (e) => { if (running) { e.preventDefault(); e.returnValue = ''; } });
  })();
  </script>

<?php else: ?>

  <div class="card">
    <h2>Free or paid?</h2>
    <div class="modes" style="margin:10px 0 0">
      <div class="mode" style="cursor:default"><b>Free</b><span class="tag t-free">₹0</span>
        <small>Each chat opens in WhatsApp (Web, desktop app or phone) with your message typed in; you press Send.
          One click per contact. Any wording you like. No setup, and no risk to your number.</small></div>
      <div class="mode" style="cursor:default"><b>Automatic</b><span class="tag t-paid">paid per message</span>
        <small>Sends by itself through Meta's official WhatsApp Business Cloud API. Only templates Meta has approved;
          Meta charges per message (marketing costs more than order updates; see Meta's pricing for India). Needs a
          one-time setup at the bottom of this page.</small></div>
    </div>
    <p class="hint" style="margin:10px 0 0">Not offered: bots that click through WhatsApp Web for you. They break
      WhatsApp's rules and are the usual way business numbers get banned.</p>
  </div>

  <div class="card">
    <h2>Send one message</h2>
    <div class="grid">
      <div><label for="oPhone">Phone</label><input id="oPhone" inputmode="tel" placeholder="98765 43210"></div>
      <div><label for="oTarget">Open in</label>
        <select id="oTarget"><option value="web">WhatsApp Web</option><option value="app">WhatsApp app (computer)</option>
          <option value="phone">Phone (wa.me)</option></select></div>
    </div>
    <label for="oText">Message</label>
    <textarea id="oText" placeholder="Hi! Your order is ready…"></textarea>
    <div class="row-actions"><button class="wa" id="oOpen">Open in WhatsApp</button>
      <span id="oErr" style="color:#c0392b;font-size:13px"></span></div>
    <?php if ($apiReady): ?>
      <details style="margin-top:14px"><summary>…or send it automatically with an approved template</summary>
        <form method="post" style="margin-top:10px">
          <?= csrf_field() ?><input type="hidden" name="action" value="single_api">
          <div class="grid">
            <div><label>Phone</label><input name="phone" required inputmode="tel"></div>
            <div><label>Template name</label><input name="template_name" value="<?= e($cfg['t_name']) ?>" required></div>
            <div><label>Language</label><input name="template_lang" value="<?= e($cfg['t_lang']) ?>"></div>
          </div>
          <label>Values for {{1}}, {{2}}, … separated by |</label>
          <input name="values" placeholder="Priya | MFU-0101 | 450">
          <div class="row-actions"><button class="wa">Send now</button></div>
        </form>
      </details>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Send to a list (Excel or CSV)</h2>
    <p class="hint">One contact per row. A column named <b>Phone</b> is required; every other column (Name, Order,
      Amount, City, …) can be used in the message as <code>{Name}</code>, <code>{Order}</code>…, and
      <code>{first_name}</code> is the first word of the name. Numbers can be written any way (98765 43210, +91…,
      0…). Duplicates and numbers on the do-not-message list are skipped.
      <br>Demo file: <a href="samples/whatsapp-contacts-sample.xlsx" download><b>Excel (.xlsx)</b></a> ·
      <a href="?sample=csv"><b>CSV</b></a>. Replace its rows with your contacts, save, upload. (Its three sample
      numbers are skipped automatically, so a forgotten demo row never gets a message.)</p>
    <form method="post" enctype="multipart/form-data" id="campaignForm">
      <?= csrf_field() ?><input type="hidden" name="action" value="campaign">
      <div class="grid">
        <div><label for="list">Contacts file (.xlsx or .csv)</label>
          <input id="list" type="file" name="list" accept=".xlsx,.csv,text/csv" required></div>
        <div><label for="cname">Name this list <small>(optional)</small></label>
          <input id="cname" name="name" placeholder="Diwali offer · regulars"></div>
      </div>
      <div class="modes">
        <label class="mode"><input type="radio" name="mode" value="free" checked><b>Free</b><span class="tag t-free">₹0</span>
          <small>Your own wording; one click per contact.</small></label>
        <label class="mode"><input type="radio" name="mode" value="auto" <?= $apiReady ? '' : 'disabled' ?>><b>Automatic</b>
          <span class="tag t-paid">paid</span>
          <small><?= $apiReady ? 'An approved template, sent by itself.' : 'Set up the Cloud API below first.' ?></small></label>
      </div>
      <div id="freeFields">
        <label for="message">Message</label>
        <textarea id="message" name="message"><?= e($defaultMessage) ?></textarea>
        <label style="margin-top:10px">Preview with the demo file's first row</label>
        <div class="preview" id="preview"></div>
      </div>
      <div id="autoFields" style="display:none">
        <div class="grid">
          <div><label>Approved template name</label><input name="template_name" value="<?= e($cfg['t_name']) ?>"></div>
          <div><label>Language code</label><input name="template_lang" value="<?= e($cfg['t_lang']) ?>" placeholder="en"></div>
        </div>
        <label>Columns for the template's {{1}}, {{2}}, … in order</label>
        <input name="template_params" value="<?= e($cfg['t_params']) ?>" placeholder="Name, Order, Amount">
      </div>
      <div class="row-actions"><button class="primary">Upload and prepare</button></div>
    </form>
  </div>

  <?php
    $lists = $conn->query(
      "SELECT c.id, c.name, c.mode, c.created_at, COUNT(r.id) total,
              SUM(r.status IN ('opened','sent')) done, SUM(r.status = 'failed') failed
         FROM wa_campaigns c LEFT JOIN wa_recipients r ON r.campaign_id = c.id
        GROUP BY c.id ORDER BY c.id DESC LIMIT 50")->fetch_all(MYSQLI_ASSOC);
  ?>
  <div class="card">
    <h2>Lists</h2>
    <?php if (!$lists): ?><p class="hint">None yet.</p><?php else: ?>
    <div class="scroll"><table>
      <thead><tr><th>List</th><th>Mode</th><th>Progress</th><th>Created</th></tr></thead>
      <tbody>
      <?php foreach ($lists as $l): ?>
        <tr><td><a href="?c=<?= (int)$l['id'] ?>"><?= e($l['name']) ?></a></td>
          <td><span class="tag <?= $l['mode'] === 'auto' ? 't-paid' : 't-free' ?>"><?= $l['mode'] === 'auto' ? 'Automatic' : 'Free' ?></span></td>
          <td><?= (int)$l['done'] ?> / <?= (int)$l['total'] ?><?= (int)$l['failed'] ? ' · <span style="color:#c0392b">' . (int)$l['failed'] . ' failed</span>' : '' ?></td>
          <td><?= e(date('j M, g:i a', strtotime((string)$l['created_at']))) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Do-not-message list</h2>
    <p class="hint">Anyone who asks not to be messaged: put their number here and every new list skips it.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="optout">
      <textarea name="wa_optout" placeholder="One number per line" style="min-height:80px"><?= e($cfg['optout']) ?></textarea>
      <div class="row-actions"><button>Save list</button></div></form>
  </div>

  <div class="card">
    <details <?= $apiReady ? '' : '' ?>><summary>Automatic sending: WhatsApp Business Cloud API setup
      <?= $apiReady ? '<span class="tag t-free">connected</span>' : '<span class="tag t-paid">not set up</span>' ?></summary>
      <p class="hint" style="margin-top:10px">
        1. At <b>developers.facebook.com</b> create an app (type <i>Business</i>) and add <b>WhatsApp</b>.<br>
        2. In WhatsApp → API Setup, add your business phone number (a number used for the API cannot also stay in the
           normal WhatsApp app) and copy its <b>Phone number ID</b>.<br>
        3. In Business Settings → System users, create a system user, give it the app, and generate a
           <b>permanent access token</b> with <code>whatsapp_business_messaging</code>.<br>
        4. In WhatsApp Manager → Message templates, create a template, e.g. <i>order_ready</i>: "Hi {{1}}, your order
           {{2}} of ₹{{3}} is ready." Wait for Meta to approve it.<br>
        5. Add a payment method in Meta Business; messages are billed per message by Meta, not by this site.</p>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="api_settings">
        <div class="grid">
          <div><label>Permanent access token <?= $apiReady ? '<small>(saved; leave blank to keep)</small>' : '' ?></label>
            <input name="wa_api_token" type="password" autocomplete="off" placeholder="<?= $apiReady ? '••••••••' : 'EAAG…' ?>"></div>
          <div><label>Phone number ID</label><input name="wa_api_phone_id" value="<?= e($cfg['phone_id']) ?>"></div>
          <div><label>Graph API version</label><input name="wa_api_version" value="<?= e($cfg['version']) ?>"></div>
        </div>
        <div class="grid">
          <div><label>Default template name</label><input name="wa_template_name" value="<?= e($cfg['t_name']) ?>" placeholder="order_ready"></div>
          <div><label>Default language code</label><input name="wa_template_lang" value="<?= e($cfg['t_lang']) ?>" placeholder="en"></div>
          <div><label>Default columns for {{1}}, {{2}}, …</label><input name="wa_template_params" value="<?= e($cfg['t_params']) ?>" placeholder="Name, Order, Amount"></div>
        </div>
        <div class="row-actions"><button class="primary">Save</button></div>
      </form>
      <?php if ($apiReady): ?>
        <form method="post" style="margin-top:10px" onsubmit="return confirm('Remove the access token?')"><?= csrf_field() ?>
          <input type="hidden" name="action" value="api_forget"><button class="danger">Remove access token</button></form>
      <?php endif; ?>
    </details>
  </div>

  <script>
  (function () {
    // Send one: built here, so the chat opens inside the click.
    document.getElementById('oOpen').onclick = () => {
      const err = document.getElementById('oErr');
      let d = document.getElementById('oPhone').value.replace(/\D/g, '').replace(/^0+/, '');
      if (d.length === 10) d = '<?= defined('COUNTRY_CODE') ? COUNTRY_CODE : '91' ?>' + d;
      if (d.length < 11) { err.textContent = 'Enter a valid phone number.'; return; }
      err.textContent = '';
      const t = encodeURIComponent(document.getElementById('oText').value);
      const how = document.getElementById('oTarget').value;
      if (how === 'app') window.location.href = 'whatsapp://send?phone=' + d + '&text=' + t;
      else window.open(how === 'phone' ? 'https://wa.me/' + d + '?text=' + t
                                       : 'https://web.whatsapp.com/send?phone=' + d + '&text=' + t, 'mfu_whatsapp');
    };

    // Free / automatic fields.
    const form = document.getElementById('campaignForm');
    const sync = () => {
      const auto = form.querySelector('input[name=mode]:checked').value === 'auto';
      document.getElementById('freeFields').style.display = auto ? 'none' : '';
      document.getElementById('autoFields').style.display = auto ? '' : 'none';
    };
    form.querySelectorAll('input[name=mode]').forEach((r) => r.onchange = sync);
    sync();

    // Live preview with the demo row.
    const demo = { phone: '98765 43210', name: 'Priya Sharma', first_name: 'Priya', order: 'MFU-0101', amount: '450', city: 'Hyderabad' };
    const msg = document.getElementById('message');
    const preview = () => {
      document.getElementById('preview').textContent = msg.value.replace(/\{\s*([^{}]+?)\s*\}/g, (m, k) => {
        k = k.replace(/\s+/g, '').toLowerCase();
        return k in demo ? demo[k] : m;
      });
    };
    msg.oninput = preview;
    preview();
  })();
  </script>

<?php endif; ?>

<?php require 'layout_end.php'; ?>
</body>
</html>
