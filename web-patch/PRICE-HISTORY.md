# The website needs this too: a price change must not reach a completed sale

The app and the web app are fixed. `save.php` on the website still has the
same fault, and one edit there undoes the protection everywhere, because
all three write to the same `orders` table.

## The fault

Open any completed order on `edit.php`, change anything at all — tick
"ready", fix a spelling in the notes — and press save. `save.php` deletes
every `order_items` row and re-inserts them **at today's catalogue price**:

```php
[$rows, $subtotal] = lines($_POST, $ITEMS);   // $ITEMS = today's prices
...
$s = $conn->prepare('DELETE FROM order_items WHERE order_id = ?');
```

So raising Square Magnet from ₹50 to ₹60 and then merely ticking an old
order "ready" turns a paid-in-full ₹500 sale into a ₹600 one with ₹100
apparently still owed. The customer never agreed to it and nobody will
ever collect it — but the balance is now on the books, in the partner
accounts, and on any bill reissued afterwards.

We reproduced this on a copy of the live database before fixing it.

## The fix

Two changes to `save.php`. Nothing else, and every existing order keeps
behaving exactly as it does now.

### 1. `lines()` learns to honour prices already agreed

Replace the function with this:

```php
/**
 * Build the order lines.
 *
 * $agreed carries the unit_price each line was ALREADY sold at, on an
 * edit. Those lines keep it: a sale is a thing that happened at a price,
 * not a thing that gets recalculated whenever the catalogue moves. Only
 * lines genuinely new to the order are priced at today's rate. Passing
 * [] (a new sale) prices everything at today's, as before.
 */
function lines(array $post, array $ITEMS, array $agreed = []): array {
    $items = $post['item'] ?? [];
    $qtys  = $post['quantity'] ?? [];
    if (!is_array($items) || !is_array($qtys) || count($items) !== count($qtys)) {
        throw new Exception('Malformed product list.');
    }

    $merged = [];
    foreach ($items as $i => $name) {
        $name = (string)$name;
        if ($name === '') continue;
        // An item already on this order stays valid even if it has since
        // been removed from the catalogue -- otherwise an old order
        // becomes uneditable the day a product is retired.
        if (!isset($ITEMS[$name]) && !isset($agreed[$name])) {
            throw new Exception('Invalid product: ' . $name);
        }
        $q = (int)$qtys[$i];
        if ($q < 1 || $q > 999) throw new Exception('Quantity must be between 1 and 999.');
        $merged[$name] = ($merged[$name] ?? 0) + $q;
    }
    if (!$merged) throw new Exception('Add at least one product.');

    $out = []; $total = 0.0;
    foreach ($merged as $name => $q) {
        $unit = array_key_exists($name, $agreed)
            ? (float)$agreed[$name]            // sold at this -- keep it
            : (float)$ITEMS[$name];            // new line -- today's price
        $lt    = round($unit * $q, 2);
        $total += $lt;
        $out[] = ['item'=>$name, 'qty'=>$q, 'unit'=>$unit, 'lt'=>$lt];
    }
    return [$out, round($total, 2)];
}

/**
 * What an order's lines were sold at: item => unit_price. Read BEFORE the
 * rewrite below, because the rewrite is a DELETE and the old prices are
 * gone after it.
 */
function sold_prices(mysqli $conn, int $orderId): array {
    $s = $conn->prepare('SELECT item, unit_price FROM order_items WHERE order_id = ?');
    $s->bind_param('i', $orderId);
    $s->execute();
    $res = $s->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) $out[$r['item']] = (float)$r['unit_price'];
    $s->close();
    return $out;
}
```

### 2. The `update` branch passes them in

In `case 'update':`, replace this line:

```php
        [$rows, $subtotal]      = lines($_POST, $ITEMS);
```

with:

```php
        // Read the agreed prices before the rewrite below deletes them.
        $agreed = sold_prices($conn, $id);
        [$rows, $subtotal]      = lines($_POST, $ITEMS, $agreed);
```

**Leave `case 'create':` alone.** A new sale should be priced at today's
catalogue, which is what it already does.

## Optional, but worth it

If you have run `api/migrations/2026-09-price-history.sql`, `order_items`
also has a `unit_cost` column that freezes what the goods cost us at the
moment of sale, so putting your own costs up no longer restates the profit
on every sale ever made. To have the website fill it too, change both
`INSERT INTO order_items` statements in `save.php` from:

```php
'INSERT INTO order_items (order_id, item, quantity, unit_price, line_total)
 VALUES (?,?,?,?,?)'
...
$s->bind_param('isidd', $id, $r['item'], $r['qty'], $r['unit'], $r['lt']);
```

to:

```php
'INSERT INTO order_items (order_id, item, quantity, unit_price, unit_cost, line_total)
 VALUES (?,?,?,?,?,?)'
...
$s->bind_param('isiddd', $id, $r['item'], $r['qty'], $r['unit'], $r['cost'], $r['lt']);
```

and give each row a `cost` in `lines()` — today's `product_costs` value
for a new line, and for an existing line the `unit_cost` already on it,
read by `sold_prices()` the same way the price is.

Without this the website still works: rows it writes keep `unit_cost` at
0, and Stats falls back to the live `product_costs` table for those, which
is exactly what it did before.

## Checking it worked

1. Note a completed, fully-paid order and what it totals.
2. Put one of its products up by ₹10 in `products.php`.
3. Open that order in `edit.php`, tick something harmless, save.
4. The total must be unchanged and the balance must still be zero.
5. Take a new order for the same product: it must charge the new price.
