<?php
/**
 * Did any order get repriced by the old save.php?
 *
 * Until this was fixed, editing an order deleted its lines and re-inserted
 * them at whatever the catalogue said at that moment. An order edited after
 * a price change therefore holds the new price, not the one it sold at.
 *
 * Two independent ways to spot it, both read-only. This writes nothing.
 *
 *   A bill is a frozen snapshot taken when it was issued. If an order's
 *   total no longer matches its bill, something changed after the bill
 *   was given to the customer.
 *
 *   product_price_history records when each price changed. A line whose
 *   unit_price equals a price that only came into effect AFTER the order
 *   was created cannot be what it sold at.
 *
 * Run it from the browser once, signed in: /check-repricing.php
 */
require 'config.php';
$me = require_login();

header('Content-Type: text/plain; charset=utf-8');

echo "Repricing check\n";
echo "===============\n\n";

// ── 1. orders that disagree with their own bill ──────────────────
echo "1. Orders whose total no longer matches the bill issued for them\n\n";
$found = 0;
$res = $conn->query(
    'SELECT b.bill_no, b.issued_at, b.snapshot, o.id, o.order_no, o.total
       FROM bills b JOIN orders o ON o.id = b.order_id
      ORDER BY b.issued_at DESC'
);
while ($res && ($r = $res->fetch_assoc())) {
    $snap = json_decode($r['snapshot'], true);
    if (!is_array($snap)) continue;
    // The snapshot's shape has varied; look for a total wherever it sits.
    $billed = $snap['order']['total'] ?? $snap['total'] ?? null;
    if ($billed === null) continue;
    if (abs((float)$billed - (float)$r['total']) > 0.01) {
        $found++;
        printf("   %-16s %-14s billed %10s   now %10s   (%+.2f)\n",
            $r['order_no'], $r['bill_no'], money((float)$billed),
            money((float)$r['total']), (float)$r['total'] - (float)$billed);
    }
}
echo $found ? "\n   $found order(s) changed after their bill was issued.\n"
            : "   None. Every billed order still matches its bill.\n";

// ── 2. lines sitting at a price that did not exist yet ───────────
echo "\n2. Order lines priced at a rate introduced after the order was taken\n\n";
$found2 = 0;
try {
    $res = $conn->query(
        'SELECT o.order_no, o.created_at, oi.item, oi.unit_price,
                h.price, h.changed_at
           FROM order_items oi
           JOIN orders o ON o.id = oi.order_id
           JOIN product_price_history h
             ON h.item = oi.item COLLATE utf8mb4_unicode_ci
          WHERE ABS(h.price - oi.unit_price) < 0.001
            AND h.changed_at > o.created_at
            AND h.note IS NULL
          ORDER BY o.created_at DESC'
    );
    while ($res && ($r = $res->fetch_assoc())) {
        $found2++;
        printf("   %-16s %-18s at %8s, a price set on %s\n",
            $r['order_no'], $r['item'], money((float)$r['unit_price']),
            date('d M Y', strtotime($r['changed_at'])));
    }
} catch (Throwable $e) {
    echo "   (price history not available: " . $e->getMessage() . ")\n";
}
echo $found2 ? "\n   $found2 line(s) look repriced.\n"
             : "   None found.\n";

echo "\nDone. Nothing was changed by this check.\n";
$conn->close();
