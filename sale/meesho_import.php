<?php
require 'config.php';
require 'meesho_xlsx.php';
$me = require_login();

// ── Panel status -> our enum ───────────────────────────────
function panel_status(string $raw): ?string {
    $s = strtolower(trim($raw));
    $map = [
        'delivered'        => 'delivered',
        'return'           => 'returned',
        'returned'         => 'returned',
        'rto'              => 'rto',
        'shipped'          => 'shipped',
        'cancelled'        => 'cancelled',
        'canceled'         => 'cancelled',
        'on hold'          => 'on_hold',
        'out for delivery' => 'out_for_delivery',
        'door step exchanged' => 'returned',
        'exchanged'        => 'returned',
    ];
    return $map[$s] ?? null;
}

/** Pull "Set of 3" / "Pack of 2" out of a listing name. */
function listing_set_size(string $name): ?int {
    if (preg_match('/(?:set|pack)\s*of\s*(\d+)/i', $name, $m)) return (int)$m[1];
    return null;
}

$report = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    csrf_check();
    try {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $codes = [
                UPLOAD_ERR_INI_SIZE   => 'File is larger than the server allows.',
                UPLOAD_ERR_FORM_SIZE  => 'File is too large.',
                UPLOAD_ERR_PARTIAL    => 'Upload was interrupted — try again.',
                UPLOAD_ERR_NO_FILE    => 'Please choose a file first.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server has no temp folder configured.',
                UPLOAD_ERR_CANT_WRITE => 'Server could not write the upload.',
            ];
            $err = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
            throw new Exception($codes[$err] ?? 'Upload failed.');
        }
        $tmp      = $_FILES['file']['tmp_name'];
        $origName = $_FILES['file']['name'];
        if (!preg_match('/\.xlsx$/i', $origName)) {
            throw new Exception('Please upload the .xlsx payment file exactly as downloaded from the Meesho panel.');
        }

        // ── Build SKU -> product lookup ─────────────────────
        $skuToProduct = [];   // sku => ['id'=>, 'name'=>]
        $r = $conn->query('SELECT id, name, sku FROM meesho_products WHERE sku IS NOT NULL AND sku <> ""');
        while ($p = $r->fetch_assoc()) $skuToProduct[strtoupper(trim($p['sku']))] = $p;

        $ordersNew = 0; $ordersUpd = 0; $adsNew = 0; $adsSkip = 0;
        $unknownSku = []; $unknownStatus = []; $createdSubs = []; $adsErrors = [];
        $periodFrom = null; $periodTo = null;

        $conn->begin_transaction();

        // ── 1. Order Payments ───────────────────────────────
        $sheets = xlsx_sheet_names($tmp);
        if (!in_array('Order Payments', $sheets, true)) {
            throw new Exception('This file has no "Order Payments" sheet. Sheets found: ' . implode(', ', $sheets));
        }
        $rows = xlsx_read_sheet($tmp, 'Order Payments');

        // Header is row 2 (index 1); row 3 is a formula legend; data from row 4.
        $hdr = [];
        foreach (($rows[1] ?? []) as $i => $h) $hdr[strtolower(trim($h))] = $i;
        $need = ['sub order no', 'order date', 'product name', 'supplier sku',
                 'live order status', 'quantity', 'final settlement amount'];
        foreach ($need as $col) {
            if (!isset($hdr[$col])) {
                throw new Exception('Expected column "' . $col . '" not found in Order Payments. '
                    . 'Has Meesho changed the file format?');
            }
        }
        $ix = function(string $name) use ($hdr) { return $hdr[strtolower($name)] ?? null; };
        $get = function(array $row, ?int $i) { return ($i === null) ? '' : trim((string)($row[$i] ?? '')); };

        $iSub   = $ix('sub order no');       $iOrdDt = $ix('order date');
        $iDisp  = $ix('dispatch date');      $iProd  = $ix('product name');
        $iSku   = $ix('supplier sku');       $iSrc   = $ix('order source');
        $iStat  = $ix('live order status');  $iList  = $ix('listing price (incl. taxes)');
        $iQty   = $ix('quantity');           $iTxn   = $ix('transaction id');
        $iPayDt = $ix('payment date');       $iSettle= $ix('final settlement amount');
        $iSale  = $ix('total sale amount (incl. shipping & gst)');
        $iRet   = $ix('total sale return amount (incl. shipping & gst)');
        $iComm  = $ix('meesho commission (incl. gst)');
        $iShip  = $ix('shipping charge (incl. gst)');

        // Prepared statements
        $selBySub = $conn->prepare('SELECT id FROM meesho_orders WHERE sub_order_id = ? LIMIT 1');

        $upd = $conn->prepare(
            'UPDATE meesho_orders SET
                order_date       = COALESCE(?, order_date),
                dispatch_date    = COALESCE(?, dispatch_date),
                product_id       = COALESCE(?, product_id),
                product_name     = COALESCE(?, product_name),
                sku              = ?,
                listing_name     = ?,
                quantity         = ?,
                status           = ?,
                settlement_price = ?,
                listing_price    = ?,
                sale_amount      = ?,
                return_amount    = ?,
                commission       = ?,
                shipping_charge  = ?,
                is_ad_order      = ?,
                payment_date     = ?,
                transaction_id   = ?,
                from_panel       = 1
             WHERE sub_order_id = ?'
        );

        $ins = $conn->prepare(
            'INSERT INTO meesho_orders
               (order_date, dispatch_date, customer_name, product_id, product_name, sku,
                listing_name, quantity, order_id, sub_order_id, status, settlement_price,
                listing_price, sale_amount, return_amount, commission, shipping_charge,
                is_ad_order, payment_date, transaction_id, from_panel, needs_review, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,1,?)'
        );

        for ($i = 3; $i < count($rows); $i++) {
            $row = $rows[$i];
            $sub = $get($row, $iSub);
            if ($sub === '') continue;

            $orderDate = xlsx_to_date($get($row, $iOrdDt));
            $dispDate  = xlsx_to_date($get($row, $iDisp));
            $payDate   = xlsx_to_date($get($row, $iPayDt));
            if ($orderDate) {
                if ($periodFrom === null || $orderDate < $periodFrom) $periodFrom = $orderDate;
                if ($periodTo   === null || $orderDate > $periodTo)   $periodTo   = $orderDate;
            }

            $listing = $get($row, $iProd);
            $sku     = strtoupper($get($row, $iSku));
            $statRaw = $get($row, $iStat);
            $status  = panel_status($statRaw);
            if ($status === null) {
                $unknownStatus[$statRaw] = ($unknownStatus[$statRaw] ?? 0) + 1;
                $status = 'label_pending';
            }

            // Product: match on SKU (stable). Listing names change.
            $prodId = null; $prodName = null;
            if ($sku !== '' && isset($skuToProduct[$sku])) {
                $prodId   = (int)$skuToProduct[$sku]['id'];
                $prodName = $skuToProduct[$sku]['name'];
            } elseif ($sku !== '') {
                $unknownSku[$sku] = ($unknownSku[$sku] ?? 0) + 1;
            }

            $qty     = (int)($get($row, $iQty) ?: 1);
            if ($qty < 1) $qty = 1;
            $settle  = $get($row, $iSettle);
            $settleV = ($settle === '') ? null : round((float)$settle, 2);
            $listPr  = $get($row, $iList);   $listPrV = ($listPr === '') ? null : round((float)$listPr, 2);
            $saleA   = $get($row, $iSale);   $saleAV  = ($saleA  === '') ? null : round((float)$saleA, 2);
            $retA    = $get($row, $iRet);    $retAV   = ($retA   === '') ? null : round((float)$retA, 2);
            $commA   = $get($row, $iComm);   $commAV  = ($commA  === '') ? null : round((float)$commA, 2);
            $shipA   = $get($row, $iShip);   $shipAV  = ($shipA  === '') ? null : round((float)$shipA, 2);
            $isAd    = (stripos($get($row, $iSrc), 'ad') !== false) ? 1 : 0;
            $txn     = $get($row, $iTxn) ?: null;

            // Does it already exist?
            $selBySub->bind_param('s', $sub);
            $selBySub->execute();
            $exists = $selBySub->get_result()->fetch_assoc();

            if ($exists) {
                // types, in order:
                //  1 s orderDate   2 s dispDate  3 i prodId    4 s prodName
                //  5 s sku         6 s listing   7 i qty       8 s status
                //  9 d settle     10 d listPr   11 d sale     12 d ret
                // 13 d comm       14 d ship     15 i isAd     16 s payDate
                // 17 s txn        18 s sub
                $upd->bind_param(
                    'ssisssisddddddisss',
                    $orderDate, $dispDate, $prodId, $prodName, $sku, $listing,
                    $qty, $status, $settleV, $listPrV, $saleAV, $retAV, $commAV, $shipAV,
                    $isAd, $payDate, $txn, $sub
                );
                $upd->execute();
                $ordersUpd++;
            } else {
                // No customer name in the panel file — fall back to the listing,
                // and flag the row so it shows up for review.
                $placeholder = '(from panel)';
                $orderIdOnly = explode('_', $sub)[0];
                // types, in order:
                //  1 s orderDate    2 s dispDate    3 s placeholder  4 i prodId
                //  5 s prodName     6 s sku         7 s listing      8 i qty
                //  9 s orderIdOnly 10 s sub        11 s status      12 d settle
                // 13 d listPr      14 d sale       15 d ret         16 d comm
                // 17 d ship        18 i isAd       19 s payDate     20 s txn
                // 21 i me[id]
                $ins->bind_param(
                    'sssisssisssddddddissi',
                    $orderDate, $dispDate, $placeholder, $prodId, $prodName, $sku,
                    $listing, $qty, $orderIdOnly, $sub, $status, $settleV,
                    $listPrV, $saleAV, $retAV, $commAV, $shipAV, $isAd,
                    $payDate, $txn, $me['id']
                );
                $ins->execute();
                $ordersNew++;
                $createdSubs[] = $sub;
            }
        }
        $selBySub->close(); $upd->close(); $ins->close();

        // ── 2. Ads Cost ─────────────────────────────────────
        if (in_array('Ads Cost', $sheets, true)) {
            $ar = xlsx_read_sheet($tmp, 'Ads Cost');
            $ah = [];
            foreach (($ar[1] ?? []) as $i => $h) $ah[strtolower(trim($h))] = $i;

            $aDur  = $ah['deduction duration'] ?? null;
            $aDate = $ah['deduction date'] ?? null;
            $aCamp = $ah['campaign id'] ?? null;
            $aCost = $ah['ad cost'] ?? null;
            $aCred = $ah['credits / waivers / discounts'] ?? null;
            $aGst  = $ah['gst'] ?? null;
            $aTot  = $ah['total ads cost'] ?? null;

            if ($aDate !== null && $aCamp !== null && $aTot !== null) {
                // Unique key makes re-importing the same file a no-op.
                $insAd = $conn->prepare(
                    'INSERT IGNORE INTO meesho_ads
                       (deduction_date, duration_date, campaign_id, ad_cost, credits, gst, total_cost)
                     VALUES (?,?,?,?,?,?,?)'
                );
                for ($i = 3; $i < count($ar); $i++) {
                    $row = $ar[$i];
                    $d = xlsx_to_date(trim((string)($row[$aDate] ?? '')));
                    $c = trim((string)($row[$aCamp] ?? ''));
                    if (!$d || $c === '') continue;
                    $dur  = xlsx_to_date(trim((string)($row[$aDur] ?? '')));
                    // The file gives costs as negatives; store positive magnitudes.
                    $cost = abs((float)($row[$aCost] ?? 0));
                    $cred = abs((float)($row[$aCred] ?? 0));
                    $gst  = abs((float)($row[$aGst]  ?? 0));
                    $tot  = abs((float)($row[$aTot]  ?? 0));
                    $insAd->bind_param('sssdddd', $d, $dur, $c, $cost, $cred, $gst, $tot);
                    if (!$insAd->execute()) {
                        $adsErrors[] = 'Row ' . ($i + 1) . ': ' . $insAd->error;
                        continue;
                    }
                    // NOTE: must read affected_rows from the STATEMENT, not the
                    // connection — $conn->affected_rows returns -1 for prepared
                    // statements, which made this always count as "skipped".
                    if ($insAd->affected_rows > 0) $adsNew++; else $adsSkip++;
                }
                $insAd->close();
            } else {
                $adsErrors[] = 'The "Ads Cost" sheet is missing an expected column '
                             . '(needs Deduction Date, Campaign ID, Total Ads Cost).';
            }
        } else {
            $adsErrors[] = 'This file has no "Ads Cost" sheet.';
        }

        // ── 3. Log the batch ────────────────────────────────
        $log = $conn->prepare(
            'INSERT INTO meesho_imports
               (filename, period_from, period_to, orders_new, orders_updated, ads_new, ads_skipped, imported_by)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $log->bind_param('sssiiiii', $origName, $periodFrom, $periodTo,
                         $ordersNew, $ordersUpd, $adsNew, $adsSkip, $me['id']);
        $log->execute(); $log->close();

        $conn->commit();

        $report = [
            'file'          => $origName,
            'orders_new'    => $ordersNew,
            'orders_upd'    => $ordersUpd,
            'ads_new'       => $adsNew,
            'ads_skipped'   => $adsSkip,
            'unknown_sku'   => $unknownSku,
            'unknown_status'=> $unknownStatus,
            'ads_errors'    => $adsErrors,
            'created'       => $createdSubs,
            'from'          => $periodFrom,
            'to'            => $periodTo,
        ];
        flash("Imported $origName — $ordersUpd updated, $ordersNew new, $adsNew ad rows.");

    } catch (Exception $ex) {
        // Roll back only if a transaction is actually open.
        @$conn->rollback();
        flash($ex->getMessage(), 'error');
    }
}

$history = $conn->query(
    'SELECT i.*, a.name AS admin_name FROM meesho_imports i
       LEFT JOIN admins a ON a.id = i.imported_by
      ORDER BY i.id DESC LIMIT 15'
)->fetch_all(MYSQLI_ASSOC);

$unmapped = $conn->query(
    'SELECT id, name FROM meesho_products WHERE sku IS NULL OR sku = "" ORDER BY sort_order, name'
)->fetch_all(MYSQLI_ASSOC);

$reviewCount = (int)($conn->query('SELECT COUNT(*) c FROM meesho_orders WHERE needs_review = 1')
                          ->fetch_assoc()['c'] ?? 0);
$flash = flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Import Meesho payments · Stall Orders</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f5f7;color:#1c1e21;padding:16px;line-height:1.5}
  .card{background:#fff;border:1px solid #dfe1e5;border-radius:10px;padding:18px;margin-bottom:16px}
  .card h2{font-size:16px;font-weight:600;margin-bottom:6px}
  .card .desc{font-size:13px;color:#65676b;margin-bottom:14px}
  label{display:block;font-size:13px;color:#65676b;margin-bottom:4px}
  input[type=file]{padding:9px;border:1px dashed #ccd0d5;border-radius:6px;font-size:14px;width:100%;background:#fafbfc}
  button{padding:10px 18px;border:1px solid #ccd0d5;border-radius:6px;background:#fff;font-size:14px;cursor:pointer;font-family:inherit}
  .primary{background:#1877f2;color:#fff;border-color:#1877f2}.primary:hover{background:#166fe5}
  table{width:100%;border-collapse:collapse;font-size:13.5px}
  th{text-align:left;padding:9px 8px;border-bottom:2px solid #dfe1e5;font-size:11.5px;text-transform:uppercase;color:#65676b;letter-spacing:.4px}
  td{padding:9px 8px;border-bottom:1px solid #eceef0}
  .flash{padding:11px 14px;border-radius:8px;margin-bottom:16px;font-size:14px}
  .f-success{background:#e3f5eb;color:#1a7f4b;border:1px solid #b8e3ca}
  .f-error{background:#fdeceb;color:#c0392b;border:1px solid #f5c6c2}
  .warn{background:#fff8e8;border:1px solid #f0c26b;border-radius:8px;padding:12px 14px;margin-bottom:14px;font-size:13px}
  .ok{background:#e3f5eb;border:1px solid #b8e3ca;border-radius:8px;padding:12px 14px;margin-bottom:14px;font-size:13px}
  .rep{display:flex;gap:18px;flex-wrap:wrap;margin-bottom:12px}
  .rep div{background:#f7f8fa;border:1px solid #e3e5e9;border-radius:8px;padding:10px 14px;min-width:110px}
  .rep b{display:block;font-size:20px}
  .rep span{font-size:11.5px;color:#65676b}
  code{background:#f2f3f5;padding:1px 5px;border-radius:4px;font-size:12px}
  .steps{font-size:13.5px;color:#3c4043;padding-left:18px}
  .steps li{margin-bottom:5px}
</style>
</head>
<body>
<?php
  $PAGE  = 'meesho_import';
  $TITLE = 'Import Meesho payments';
  require 'layout.php';
?>

  <?php if ($flash): ?><div class="flash f-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

  <?php if ($unmapped): ?>
    <div class="warn">
      <strong>Some products have no SKU.</strong> The importer matches on Supplier SKU,
      so these will never match a panel row:
      <?php $names = array_column($unmapped, 'name'); ?>
      <?= e(implode(', ', $names)) ?>.
      Set their SKU on the <a href="meesho_products.php">Meesho products</a> page.
    </div>
  <?php endif; ?>

  <div class="card">
    <h2>Upload the payment file</h2>
    <p class="desc">Download from the Meesho panel (Payments → Payment file), then upload the
      <code>.xlsx</code> here, exactly as downloaded. It reads two sheets:
      <strong>Order Payments</strong> (settlement per order) and <strong>Ads Cost</strong>
      (your daily ad spend). Uploading the same file twice is safe — orders are matched on
      sub-order number and ad rows are de-duplicated.</p>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="import">
      <label for="file">Payment file (.xlsx)</label>
      <input id="file" name="file" type="file" accept=".xlsx" required>
      <button type="submit" class="primary" style="margin-top:14px">Import file</button>
    </form>
  </div>

  <?php if ($report): ?>
    <div class="card">
      <h2>Import result</h2>
      <div class="rep">
        <div><b><?= (int)$report['orders_upd'] ?></b><span>orders updated</span></div>
        <div><b><?= (int)$report['orders_new'] ?></b><span>orders created</span></div>
        <div><b><?= (int)$report['ads_new'] ?></b><span>ad rows added</span></div>
        <div><b><?= (int)$report['ads_skipped'] ?></b><span>ad rows already had</span></div>
      </div>

      <?php if ($report['orders_new'] > 0): ?>
        <div class="warn">
          <strong><?= (int)$report['orders_new'] ?> order(s) were in the file but not in your list.</strong>
          They've been created with the name <code>(from panel)</code> and flagged for review,
          since the payment file has no customer name or phone.
          <a href="meesho.php?f=review">Review them →</a>
        </div>
      <?php endif; ?>

      <?php if (!empty($report['ads_errors'])): ?>
        <div class="warn">
          <strong>Ads problems:</strong>
          <ul style="margin:6px 0 0 18px">
            <?php foreach (array_slice($report['ads_errors'], 0, 8) as $er): ?>
              <li><?= e($er) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if ($report['ads_new'] == 0 && $report['ads_skipped'] == 0 && empty($report['ads_errors'])): ?>
        <div class="warn">
          <strong>No ad rows were read from this file.</strong> If the panel shows ad spend
          for this period, the "Ads Cost" sheet may be empty in the download — try
          re-downloading it from the Meesho panel.
        </div>
      <?php endif; ?>

      <?php if ($report['unknown_sku']): ?>
        <div class="warn">
          <strong>Unrecognised SKUs</strong> — these rows imported without a product link:
          <?php foreach ($report['unknown_sku'] as $sku => $n): ?>
            <code><?= e($sku) ?></code> (<?= (int)$n ?>)
          <?php endforeach; ?>
          <br>Add the SKU to the matching product on the
          <a href="meesho_products.php">Meesho products</a> page, then re-upload this file.
        </div>
      <?php endif; ?>

      <?php if ($report['unknown_status']): ?>
        <div class="warn">
          <strong>Unrecognised statuses</strong> (stored as "Label not downloaded"):
          <?php foreach ($report['unknown_status'] as $s => $n): ?>
            <code><?= e($s) ?></code> (<?= (int)$n ?>)
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!$report['unknown_sku'] && !$report['unknown_status']
                 && empty($report['ads_errors']) && $report['orders_new'] == 0): ?>
        <div class="ok">Everything matched cleanly — no problems found.</div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($reviewCount > 0): ?>
    <div class="card" style="border-color:#f0c26b">
      <h2><?= $reviewCount ?> order(s) need review</h2>
      <p class="desc" style="margin-bottom:0">These came from a payment file and have no
        customer name yet. <a href="meesho.php?f=review">Open them →</a></p>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2>How this works</h2>
    <ol class="steps">
      <li>Orders are matched on <strong>Sub Order No</strong> — the same key Meesho uses.</li>
      <li>Products are matched on <strong>Supplier SKU</strong>, not the listing name, because
          Meesho renames listings (e.g. <code>Square (Set of 4)</code> and
          <code>3×3 Inch Square (Set of 4)</code> are both <code>MFU-SQ-004</code>).</li>
      <li>A matched order gets its status, settlement, sale amount, commission, shipping and
          ad-order flag overwritten from the file. Your <strong>customer name, phone, AWB and
          packet QR are never touched</strong> — the panel file doesn't contain them.</li>
      <li>An order in the file but not in your list is created and flagged for review.</li>
      <li><strong>Ads cost is billed per campaign per day, not per order</strong>, so it can't be
          attached to any single order. It's tracked as a separate cost bucket and subtracted
          in the statistics.</li>
    </ol>
  </div>

  <div class="card">
    <h2>Recent imports</h2>
    <?php if (!$history): ?>
      <p class="desc" style="margin:0">Nothing imported yet.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>When</th><th>File</th><th>Orders</th><th>Ads</th><th>By</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
          <tr>
            <td style="white-space:nowrap"><?= e(date('d M, H:i', strtotime($h['imported_at']))) ?></td>
            <td style="word-break:break-all;font-size:12px"><?= e($h['filename']) ?></td>
            <td><?= (int)$h['orders_updated'] ?> upd, <?= (int)$h['orders_new'] ?> new</td>
            <td><?= (int)$h['ads_new'] ?> new</td>
            <td><?= e($h['admin_name'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
<?php require 'layout_end.php'; ?>

</body>
</html>