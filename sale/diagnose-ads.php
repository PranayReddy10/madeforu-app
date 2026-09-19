<?php
/**
 * diagnose-ads.php — works out WHY the ads figure is zero.
 * Read-only: it changes nothing. Delete once fixed.
 */
require 'config.php';
$me = require_login();

function ok($b)   { return $b ? '<b style="color:#1a7f4b">OK</b>' : '<b style="color:#b23a2c">PROBLEM</b>'; }
function h($s)    { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ads diagnosis</title>
<style>
 body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f5f7;padding:18px;line-height:1.55;color:#1c1e21}
 .card{background:#fff;border:1px solid #dfe1e5;border-radius:10px;padding:16px;margin-bottom:14px;max-width:900px}
 h2{font-size:15px;margin:0 0 10px}
 table{width:100%;border-collapse:collapse;font-size:13px}
 th{text-align:left;padding:6px;border-bottom:2px solid #dfe1e5;font-size:11px;text-transform:uppercase;color:#65676b}
 td{padding:6px;border-bottom:1px solid #eceef0}
 code{background:#f2f3f5;padding:1px 5px;border-radius:4px;font-size:12px}
 .big{font-size:26px;font-weight:700}
 .verdict{background:#fff8e8;border:1px solid #f0c26b;border-radius:8px;padding:12px;margin-bottom:14px;max-width:900px}
 .good{background:#e3f5eb;border-color:#b8e3ca}
</style></head><body>

<h1 style="font-size:19px;margin-bottom:14px">Why is the ads figure zero?</h1>

<?php
// ── 1. Does the table exist? ───────────────────────────────
$tblExists = false;
$r = $conn->query("SHOW TABLES LIKE 'meesho_ads'");
$tblExists = $r && $r->num_rows > 0;
?>
<div class="card">
  <h2>1. Does the <code>meesho_ads</code> table exist?</h2>
  <?= ok($tblExists) ?>
  <?php if (!$tblExists): ?>
    <p>The table is missing — <code>meesho-schema-v2.sql</code> did not finish.
       Run it again (with <code>u291217659_sale</code> selected in phpMyAdmin).</p>
  <?php endif; ?>
</div>

<?php if ($tblExists):
  $cnt   = (int)$conn->query('SELECT COUNT(*) c FROM meesho_ads')->fetch_assoc()['c'];
  $sum   = (float)$conn->query('SELECT COALESCE(SUM(total_cost),0) t FROM meesho_ads')->fetch_assoc()['t'];
  $sumAll= $conn->query('SELECT COALESCE(SUM(ad_cost),0) a, COALESCE(SUM(credits),0) c,
                                COALESCE(SUM(gst),0) g, COALESCE(SUM(total_cost),0) t
                           FROM meesho_ads')->fetch_assoc();
?>
<div class="card">
  <h2>2. How many ad rows are stored?</h2>
  <p class="big"><?= number_format($cnt) ?> rows</p>
  <?= ok($cnt > 0) ?>
  <?php if ($cnt === 0): ?>
    <p><strong>The table is empty.</strong> The ads never imported. Most likely the whole
       import was rolled back by an error, or the file's "Ads Cost" sheet was not read.
       Check the import history below.</p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>3. Column totals (is the money actually stored?)</h2>
  <table>
    <tr><th>Column</th><th>Sum</th><th>Meaning</th></tr>
    <tr><td><code>ad_cost</code></td><td><?= number_format((float)$sumAll['a'],2) ?></td>
        <td>raw spend before credits</td></tr>
    <tr><td><code>credits</code></td><td><?= number_format((float)$sumAll['c'],2) ?></td>
        <td>waivers Meesho gave you</td></tr>
    <tr><td><code>gst</code></td><td><?= number_format((float)$sumAll['g'],2) ?></td><td>tax</td></tr>
    <tr><td><strong>total_cost</strong></td>
        <td><strong><?= number_format((float)$sumAll['t'],2) ?></strong></td>
        <td><strong>what the stats page sums</strong></td></tr>
  </table>
  <?php if ($cnt > 0 && (float)$sumAll['t'] == 0.0): ?>
    <p style="margin-top:10px"><strong>Rows exist but total_cost is 0.</strong>
       The rows imported but the amount column did not parse.</p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>4. Date coverage (the stats page filters on these)</h2>
  <?php
    $d = $conn->query('SELECT MIN(duration_date) dmin, MAX(duration_date) dmax,
                              MIN(deduction_date) bmin, MAX(deduction_date) bmax,
                              SUM(duration_date IS NULL) nulldur
                         FROM meesho_ads')->fetch_assoc();
  ?>
  <table>
    <tr><th>Field</th><th>Earliest</th><th>Latest</th></tr>
    <tr><td><code>duration_date</code> (when the ad ran) — <em>stats group by this</em></td>
        <td><?= h($d['dmin'] ?? '—') ?></td><td><?= h($d['dmax'] ?? '—') ?></td></tr>
    <tr><td><code>deduction_date</code> (when billed)</td>
        <td><?= h($d['bmin'] ?? '—') ?></td><td><?= h($d['bmax'] ?? '—') ?></td></tr>
  </table>
  <p style="margin-top:8px">Rows with a NULL <code>duration_date</code>:
     <strong><?= (int)$d['nulldur'] ?></strong>
     <?= ((int)$d['nulldur'] === $cnt && $cnt > 0)
          ? '— <span style="color:#b23a2c">all of them; stats fall back to deduction_date</span>' : '' ?></p>
</div>

<div class="card">
  <h2>5. Exactly what the stats page computes</h2>
  <?php
    $ADS_DATE = 'COALESCE(duration_date, deduction_date)';
    $ranges = [
      'All time'   => [null, null],
      'This month' => [date('Y-m-01'), date('Y-m-t')],
      'Last month' => [date('Y-m-01', strtotime('first day of last month')),
                       date('Y-m-t',  strtotime('last day of last month'))],
      'Last 30 days'=> [date('Y-m-d', strtotime('-29 days')), date('Y-m-d')],
    ];
  ?>
  <table>
    <tr><th>Range</th><th>From</th><th>To</th><th>Rows</th><th>Ads total</th></tr>
    <?php foreach ($ranges as $label => [$lo, $hi]):
        $w = [];
        if ($lo) $w[] = "$ADS_DATE >= '" . $conn->real_escape_string($lo) . "'";
        if ($hi) $w[] = "$ADS_DATE <= '" . $conn->real_escape_string($hi) . "'";
        $sql = 'SELECT COUNT(*) c, COALESCE(SUM(total_cost),0) t FROM meesho_ads'
             . ($w ? ' WHERE ' . implode(' AND ', $w) : '');
        $row = $conn->query($sql)->fetch_assoc();
    ?>
      <tr>
        <td><?= h($label) ?></td><td><?= h($lo ?? '—') ?></td><td><?= h($hi ?? '—') ?></td>
        <td><?= (int)$row['c'] ?></td>
        <td><strong><?= number_format((float)$row['t'], 2) ?></strong></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <p style="margin-top:8px">Today is <code><?= date('Y-m-d') ?></code>.
     If "All time" shows a number but a preset shows 0, it's a date-range issue.
     If "All time" is 0 too, the data isn't there.</p>
</div>

<div class="card">
  <h2>6. Newest 10 ad rows</h2>
  <?php $rows = $conn->query('SELECT * FROM meesho_ads ORDER BY id DESC LIMIT 10')->fetch_all(MYSQLI_ASSOC); ?>
  <?php if (!$rows): ?><p>No rows.</p><?php else: ?>
  <table>
    <tr><th>id</th><th>duration</th><th>deducted</th><th>campaign</th>
        <th>ad_cost</th><th>credits</th><th>gst</th><th>total</th></tr>
    <?php foreach ($rows as $x): ?>
      <tr><td><?= (int)$x['id'] ?></td><td><?= h($x['duration_date']) ?></td>
          <td><?= h($x['deduction_date']) ?></td><td><?= h($x['campaign_id']) ?></td>
          <td><?= h($x['ad_cost']) ?></td><td><?= h($x['credits']) ?></td>
          <td><?= h($x['gst']) ?></td><td><strong><?= h($x['total_cost']) ?></strong></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <h2>7. Import history</h2>
  <?php
    $hasLog = $conn->query("SHOW TABLES LIKE 'meesho_imports'");
    if (!$hasLog || $hasLog->num_rows === 0) {
        echo '<p>' . ok(false) . ' <code>meesho_imports</code> table missing — schema v2 unfinished.</p>';
    } else {
        $hist = $conn->query('SELECT * FROM meesho_imports ORDER BY id DESC LIMIT 10')->fetch_all(MYSQLI_ASSOC);
        if (!$hist) {
            echo '<p><b style="color:#b23a2c">No imports logged at all.</b> '
               . 'The payment file was never successfully imported — if you saw a red error '
               . 'on the import page, the whole thing rolled back, ads included.</p>';
        } else { ?>
          <table>
            <tr><th>when</th><th>file</th><th>orders new</th><th>orders upd</th>
                <th>ads new</th><th>ads skipped</th></tr>
            <?php foreach ($hist as $x): ?>
              <tr><td><?= h(date('d M H:i', strtotime($x['imported_at']))) ?></td>
                  <td style="font-size:11px;word-break:break-all"><?= h($x['filename']) ?></td>
                  <td><?= (int)$x['orders_new'] ?></td><td><?= (int)$x['orders_updated'] ?></td>
                  <td><strong><?= (int)$x['ads_new'] ?></strong></td>
                  <td><?= (int)$x['ads_skipped'] ?></td></tr>
            <?php endforeach; ?>
          </table>
          <p style="margin-top:8px">Note: "ads new" was mis-counted by a bug in the first
             version of the importer (it read the wrong counter), so a 0 there does
             <em>not</em> prove nothing imported. Trust the row count in section 2.</p>
    <?php }
    } ?>
</div>

<div class="card">
  <h2>8. Server can read .xlsx?</h2>
  <p>zip extension: <?= ok(class_exists('ZipArchive')) ?>
     &nbsp;|&nbsp; simplexml: <?= ok(function_exists('simplexml_load_string')) ?></p>
  <?php if (!class_exists('ZipArchive')): ?>
    <p style="color:#b23a2c">Without the zip extension the importer cannot open the file at all.
       Enable it in Hostinger's PHP settings.</p>
  <?php endif; ?>
</div>

<?php
// ── Verdict ────────────────────────────────────────────────
$verdict = '';
if (!$tblExists) {
    $verdict = 'The <code>meesho_ads</code> table does not exist. Run meesho-schema-v2.sql.';
} elseif ($cnt === 0) {
    $verdict = 'The table exists but is <strong>empty</strong> — no ads were ever imported. '
             . 'Re-upload the payment file on the import page and watch for a red error message. '
             . 'If the orders imported but ads did not, tell me what the error said.';
} elseif ((float)$sum === 0.0) {
    $verdict = 'Ad rows exist but every <code>total_cost</code> is 0 — the amount column did not parse.';
} else {
    $verdict = 'Ads ARE in the database: <strong>' . number_format($cnt) . ' rows, ₹'
             . number_format($sum, 2) . '</strong>. If the stats page still shows 0, '
             . 'check section 5 — a date preset may be excluding them.';
}
$isGood = $tblExists && $cnt > 0 && (float)$sum > 0;
?>
<div class="verdict <?= $isGood ? 'good' : '' ?>">
  <strong>Verdict:</strong> <?= $verdict ?>
</div>

<p style="max-width:900px;font-size:13px;color:#65676b">
  Expected once both payment files are in: <strong>87 rows, ₹3,865.98</strong>
  (June ₹3,228.81 billed + July ₹637.17 billed).
</p>

</body></html>
