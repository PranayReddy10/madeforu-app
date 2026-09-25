<?php
require 'config.php';
require_once __DIR__ . '/lib_push.php';   // tells the other partners' phones
$me = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
csrf_check();

$action = $_POST['action'] ?? '';

/**
 * Customer details. Both are optional: a stall sale where nobody wants to
 * give a number is a walk-in, stored as name 'Walk-in' with an empty
 * phone — which is what the app and the web app already record.
 *
 * Demanding both here meant a walk-in order taken on a phone could not be
 * edited on the website at all: open it, change anything, and it was
 * refused with "Phone must be 10 digits", with no phone number to give.
 * A number that IS entered is still validated.
 */
function customer(array $post): array {
    $name  = trim($post['name'] ?? '');
    $rawPh = trim($post['phone'] ?? '');
    $phone = $rawPh === '' ? '' : normalise_phone($rawPh);
    $notes = trim($post['notes'] ?? '');

    if ($rawPh !== '' && strlen($phone) !== 10) {
        throw new Exception('Phone must be 10 digits, or leave it empty for a walk-in sale.');
    }
    if ($name === '') $name = 'Walk-in';

    return [$name, $phone, ($notes !== '' ? $notes : null)];
}

/** Prices come from $ITEMS, never the form. Duplicate products merge. */
/**
 * Build the order lines.
 *
 * $agreed carries the unit_price each line was ALREADY sold at, on an
 * edit. Those lines keep it: a sale is a thing that happened at a price,
 * not a thing that gets recalculated whenever the catalogue moves.
 * Without this, raising a product's price and then merely ticking an old
 * order "ready" turned a paid-in-full sale into one with a balance the
 * customer never agreed to. Only lines genuinely new to the order are
 * priced at today's rate; a new sale ([] passed) is all today's, as
 * before.
 */
function lines(array $post, array $ITEMS, array $agreed = [], array $costs = [],
               array $agreedCosts = []): array {
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
        // been retired from the catalogue -- otherwise the day a product
        // is hidden, every past order containing it becomes uneditable.
        if (!isset($ITEMS[$name]) && !array_key_exists($name, $agreed)) {
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
        $cost = array_key_exists($name, $agreedCosts)
            ? (float)$agreedCosts[$name]
            : (float)($costs[$name] ?? 0);
        $lt    = round($unit * $q, 2);
        $total += $lt;
        $out[] = ['item'=>$name, 'qty'=>$q, 'unit'=>$unit, 'cost'=>$cost, 'lt'=>$lt];
    }
    return [$out, round($total, 2)];
}

/**
 * What an order's lines were sold at: item => unit_price, and the costs
 * beside them. Read BEFORE the rewrite, because the rewrite is a DELETE
 * and the old prices are gone after it.
 */
function sold_prices(mysqli $conn, int $orderId): array {
    $hasCost = order_items_has_cost($conn);
    $s = $conn->prepare(
        $hasCost
            ? 'SELECT item, unit_price, unit_cost FROM order_items WHERE order_id = ?'
            : 'SELECT item, unit_price FROM order_items WHERE order_id = ?'
    );
    $s->bind_param('i', $orderId);
    $s->execute();
    $res = $s->get_result();
    $prices = []; $costs = [];
    while ($r = $res->fetch_assoc()) {
        $prices[$r['item']] = (float)$r['unit_price'];
        $costs[$r['item']]  = (float)($r['unit_cost'] ?? 0);
    }
    $s->close();
    return [$prices, $costs];
}

/** Today's cost per item, for stamping onto a new sale. */
function item_costs(mysqli $conn): array {
    $out = [];
    try {
        $res = $conn->query('SELECT item, unit_cost FROM product_costs');
        while ($res && ($r = $res->fetch_assoc())) $out[$r['item']] = (float)$r['unit_cost'];
    } catch (Throwable $e) { /* optional table */ }
    return $out;
}

/**
 * Does order_items have the unit_cost column yet?
 *
 * The migration is run by hand in phpMyAdmin and may not have been yet.
 * Naming the column unconditionally would mean a shop that uploaded the
 * PHP first could not take an order at all.
 */
function order_items_has_cost(mysqli $conn): bool {
    static $has = null;
    if ($has !== null) return $has;
    try {
        $r = $conn->query("SHOW COLUMNS FROM order_items LIKE 'unit_cost'");
        $has = (bool)($r && $r->fetch_row());
    } catch (Throwable $e) { $has = false; }
    return $has;
}

/** Write an order's lines, with the cost frozen on if the column exists. */
function write_lines(mysqli $conn, int $orderId, array $rows): void {
    if (order_items_has_cost($conn)) {
        $s = $conn->prepare(
            'INSERT INTO order_items (order_id, item, quantity, unit_price, unit_cost, line_total)
             VALUES (?,?,?,?,?,?)'
        );
        foreach ($rows as $r) {
            $s->bind_param('isiddd', $orderId, $r['item'], $r['qty'], $r['unit'],
                           $r['cost'], $r['lt']);
            $s->execute();
        }
    } else {
        $s = $conn->prepare(
            'INSERT INTO order_items (order_id, item, quantity, unit_price, line_total)
             VALUES (?,?,?,?,?)'
        );
        foreach ($rows as $r) {
            $s->bind_param('isidd', $orderId, $r['item'], $r['qty'], $r['unit'], $r['lt']);
            $s->execute();
        }
    }
    $s->close();
}

function mode(array $post): string {
    $m = $post['payment_mode'] ?? 'cash';
    return in_array($m, ['cash','upi','card','other'], true) ? $m : 'cash';
}

/** Read and validate the additional charge. Returns [amount, reason|null]. */
function extra_charge(array $post): array {
    $x = round((float)($post['extra_charge'] ?? 0), 2);
    if ($x < 0) throw new Exception('Additional charge cannot be negative.');
    $x = clamp_extra_charge($x);

    $r = trim($post['extra_charge_reason'] ?? '');
    return [$x, ($r !== '' && $x > 0) ? $r : null];
}

/**
 * Read the Delhivery dispatch fields off a form post.
 * Both are optional. An AWB with no date defaults to today, because a parcel
 * with a tracking number has necessarily left the building.
 * Clearing the AWB clears the date too -- a dispatch date with no parcel is
 * meaningless. Note this is unrelated to extra_charge, which covers custom
 * work and add-ons on the products themselves.
 */
function dispatch(array $post): array {
    // Unticking the "online delivery" box clears the parcel entirely, even
    // if the hidden inputs still carry an old AWB.
    if (empty($post['is_online'])) return [null, null];

    $awb  = trim($post['awb'] ?? '');
    $date = trim($post['dispatch_date'] ?? '');

    if ($awb === '') return [null, null];

    // Delhivery AWBs are digits, but stay permissive: reject only what is
    // obviously not a tracking number rather than guessing their format.
    if (strlen($awb) > 60) throw new Exception('Tracking number is too long.');
    if (!preg_match('/^[A-Za-z0-9\-]+$/', $awb)) {
        throw new Exception('Tracking number should contain only letters, numbers and dashes.');
    }

    if ($date === '') {
        $date = date('Y-m-d');
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new Exception('Dispatch date is invalid.');
    }
    return [$awb, $date];
}

/**
 * Read and clamp the discount. Returns [amount, reason|null].
 * The ceiling is subtotal + extra charge, so a discount can cancel a
 * delivery fee.
 */
function discount(array $post, float $subtotal, float $extra = 0.0): array {
    $d = round((float)($post['discount'] ?? 0), 2);
    if ($d < 0) throw new Exception('Discount cannot be negative.');
    $base = $subtotal + $extra;
    if ($d > $base + 0.001) {
        throw new Exception('Discount of ' . money($d) . ' exceeds the order value of '
                          . money($base) . '.');
    }
    $d = clamp_discount($subtotal, $d, $extra);

    $r = trim($post['discount_reason'] ?? '');
    return [$d, ($r !== '' && $d > 0) ? $r : null];
}

try {
    switch ($action) {

    // ── CREATE ────────────────────────────────────────────
    case 'create': {
        [$name, $phone, $notes]   = customer($_POST);
        // A new sale: today's prices, and today's costs frozen on.
        [$rows, $subtotal]        = lines($_POST, $ITEMS, [], item_costs($conn));
        [$extra, $extraReason]    = extra_charge($_POST);
        [$disc, $discReason]      = discount($_POST, $subtotal, $extra);

        // Event is optional. Blank = offline (NULL). Validate it exists.
        $eventId = ($_POST['event_id'] ?? '') !== '' ? (int)$_POST['event_id'] : null;
        if ($eventId !== null) {
            $chk = $conn->prepare('SELECT id FROM events WHERE id = ?');
            $chk->bind_param('i', $eventId);
            $chk->execute();
            if (!$chk->get_result()->fetch_assoc()) $eventId = null;  // vanished -> offline
            $chk->close();
        }

        $total = round($subtotal + $extra - $disc, 2);

        $paid = round((float)($_POST['paid_amount'] ?? 0), 2);
        if ($paid < 0)      throw new Exception('Payment cannot be negative.');
        if ($paid > $total) throw new Exception('Payment cannot exceed the order total of ' . money($total) . '.');

        // Status can be set at creation. Delivered forces ready.
        [$ready, $delivered] = normalise_status(
            isset($_POST['is_ready'])     ? 1 : 0,
            isset($_POST['is_delivered']) ? 1 : 0
        );

        $conn->begin_transaction();
        try {
            $orderNo = generate_order_no($conn);

            $s = $conn->prepare(
                'INSERT INTO orders (order_no, name, phone, event_id, subtotal, discount,
                                     discount_reason, extra_charge, extra_charge_reason,
                                     total, paid_amount,
                                     is_ready, is_delivered, notes, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,0,?,?,?,?)'
            );
            $s->bind_param('sssiddsdsdiisi', $orderNo, $name, $phone, $eventId, $subtotal, $disc,
                           $discReason, $extra, $extraReason, $total, $ready, $delivered,
                           $notes, $me['id']);
            $s->execute();
            $orderId = $conn->insert_id;
            $s->close();

            write_lines($conn, $orderId, $rows);

            if ($paid > 0) {
                $m    = mode($_POST);
                $note = 'Initial payment';
                $s = $conn->prepare(
                    'INSERT INTO payments (order_id, amount, mode, note, taken_by) VALUES (?,?,?,?,?)'
                );
                $s->bind_param('idssi', $orderId, $paid, $m, $note, $me['id']);
                $s->execute();
                $s->close();
            }

            recalc_total($conn, $orderId);
            recalc_paid($conn, $orderId);
            $conn->commit();
        } catch (Exception $ex) { $conn->rollback(); throw $ex; }

        $bal = $total - $paid;
        flash("Order $orderNo created — "
            . ($disc > 0 ? money($subtotal) . ' less ' . money($disc) . ' discount = ' : 'total ')
            . money($total)
            . ($bal > 0.001 ? ', balance ' . money($bal) : ', fully paid'));
        // Return to the list the order belongs to: its event, or offline.
        $backEvent = $eventId !== null ? (string)$eventId : '0';
        header('Location: index.php?event=' . urlencode($backEvent));
        exit;
    }

    // ── UPDATE ────────────────────────────────────────────
    case 'update': {
        $id = (int)($_POST['id'] ?? 0);
        if ($id < 1) throw new Exception('Invalid order id.');

        [$name, $phone, $notes] = customer($_POST);
        // Read the agreed prices before the rewrite below deletes them.
        [$agreedPrices, $agreedCosts] = sold_prices($conn, $id);
        [$rows, $subtotal]      = lines($_POST, $ITEMS, $agreedPrices,
                                        item_costs($conn), $agreedCosts);
        [$extra, $extraReason]  = extra_charge($_POST);
        [$disc, $discReason]    = discount($_POST, $subtotal, $extra);
        [$awb, $dispatchDate]   = dispatch($_POST);

        $total = round($subtotal + $extra - $disc, 2);

        $s = $conn->prepare('SELECT paid_amount FROM orders WHERE id = ?');
        $s->bind_param('i', $id);
        $s->execute();
        $cur = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$cur) throw new Exception('Order not found.');

        // A discount, a removed item, or a lowered charge all reduce the
        // total. If it drops below what has already been collected the
        // customer has overpaid, and the balance goes negative. Block it
        // and say which levers to pull.
        $alreadyPaid = (float)$cur['paid_amount'];
        if ($total < $alreadyPaid - 0.001) {
            throw new Exception('New total ' . money($total) . ' is less than the '
                . money($alreadyPaid) . ' already collected. Reduce the discount, '
                . 'add back items or charges, or delete a payment first.');
        }

        $ready     = isset($_POST['is_ready'])     ? 1 : 0;
        $delivered = isset($_POST['is_delivered']) ? 1 : 0;
        // A parcel with a tracking number was necessarily made first, so an
        // AWB forces ready. It does not force delivered -- it is in transit.
        if ($awb !== null) $ready = 1;
        [$ready, $delivered] = normalise_status($ready, $delivered);

        $conn->begin_transaction();
        try {
            $s = $conn->prepare(
                'UPDATE orders SET name=?, phone=?, notes=?, discount=?, discount_reason=?,
                                   extra_charge=?, extra_charge_reason=?,
                                   is_ready=?, is_delivered=?, awb=?, dispatch_date=? WHERE id=?'
            );
            $s->bind_param('sssdsdsiissi', $name, $phone, $notes, $disc, $discReason,
                           $extra, $extraReason, $ready, $delivered,
                           $awb, $dispatchDate, $id);
            $s->execute();
            $s->close();

            $s = $conn->prepare('DELETE FROM order_items WHERE order_id = ?');
            $s->bind_param('i', $id);
            $s->execute();
            $s->close();

            write_lines($conn, $id, $rows);

            recalc_total($conn, $id);
            $conn->commit();
        } catch (Exception $ex) { $conn->rollback(); throw $ex; }

        flash('Order updated.');
        header('Location: edit.php?id=' . $id);
        exit;
    }

    // ── ADD PAYMENT ───────────────────────────────────────
    case 'add_payment': {
        $id  = (int)($_POST['id'] ?? 0);
        $amt = round((float)($_POST['amount'] ?? 0), 2);
        if ($id < 1)   throw new Exception('Invalid order id.');
        if ($amt <= 0) throw new Exception('Payment must be greater than zero.');

        $s = $conn->prepare('SELECT total, paid_amount FROM orders WHERE id = ?');
        $s->bind_param('i', $id);
        $s->execute();
        $o = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$o) throw new Exception('Order not found.');

        $balance = (float)$o['total'] - (float)$o['paid_amount'];
        if ($balance <= 0.001) throw new Exception('This order is already fully paid.');
        if ($amt > $balance + 0.001) {
            throw new Exception('Payment of ' . money($amt) . ' exceeds the balance of ' . money($balance) . '.');
        }

        $m    = mode($_POST);
        $note = trim($_POST['note'] ?? '');
        $note = $note !== '' ? $note : null;

        $conn->begin_transaction();
        try {
            $s = $conn->prepare(
                'INSERT INTO payments (order_id, amount, mode, note, taken_by) VALUES (?,?,?,?,?)'
            );
            $s->bind_param('idssi', $id, $amt, $m, $note, $me['id']);
            $s->execute();
            $s->close();
            recalc_paid($conn, $id);
            $conn->commit();
        } catch (Exception $ex) { $conn->rollback(); throw $ex; }

        $newBal = $balance - $amt;
        flash(money($amt) . ' received. '
            . ($newBal > 0.001 ? 'Balance now ' . money($newBal) . '.' : 'Order fully paid.'));
        header('Location: edit.php?id=' . $id);
        exit;
    }

    // ── DELETE PAYMENT ────────────────────────────────────
    case 'delete_payment': {
        $pid = (int)($_POST['payment_id'] ?? 0);
        $id  = (int)($_POST['id'] ?? 0);
        if ($pid < 1 || $id < 1) throw new Exception('Invalid payment.');

        $conn->begin_transaction();
        try {
            $s = $conn->prepare('DELETE FROM payments WHERE id = ? AND order_id = ?');
            $s->bind_param('ii', $pid, $id);
            $s->execute();
            $s->close();
            recalc_paid($conn, $id);
            $conn->commit();
        } catch (Exception $ex) { $conn->rollback(); throw $ex; }

        flash('Payment removed.');
        header('Location: edit.php?id=' . $id);
        exit;
    }

    // ── TOGGLE ready / delivered, keeping them consistent ──
    case 'toggle': {
        $id    = (int)($_POST['id'] ?? 0);
        $field = $_POST['field'] ?? '';
        if (!in_array($field, ['is_ready','is_delivered'], true)) throw new Exception('Invalid field.');
        if ($id < 1) throw new Exception('Invalid order id.');

        $s = $conn->prepare('SELECT is_ready, is_delivered FROM orders WHERE id = ?');
        $s->bind_param('i', $id);
        $s->execute();
        $o = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$o) throw new Exception('Order not found.');

        $ready     = (int)$o['is_ready'];
        $delivered = (int)$o['is_delivered'];

        if ($field === 'is_delivered') {
            $delivered = 1 - $delivered;
            // Marking delivered auto-marks ready. You cannot hand over
            // something that was never made.
            if ($delivered === 1 && $ready === 0) {
                $ready = 1;
                flash('Marked delivered. Ready was set automatically.');
            }
        } else {
            $ready = 1 - $ready;
            // Un-readying a delivered order would be an impossible state.
            if ($ready === 0 && $delivered === 1) {
                $delivered = 0;
                flash('Marked not ready. Delivery was cleared.');
            }
        }

        [$ready, $delivered] = normalise_status($ready, $delivered);

        $s = $conn->prepare('UPDATE orders SET is_ready = ?, is_delivered = ? WHERE id = ?');
        $s->bind_param('iii', $ready, $delivered, $id);
        $s->execute();
        $s->close();
        break;
    }

    // ── DELETE ORDER ──────────────────────────────────────
    case 'delete': {
        $id = (int)($_POST['id'] ?? 0);
        if ($id < 1) throw new Exception('Invalid order id.');
        // Note the order's event before removing it, so we can return to
        // that list rather than the default view.
        $ev = $conn->prepare('SELECT event_id FROM orders WHERE id = ?');
        $ev->bind_param('i', $id);
        $ev->execute();
        $evRow = $ev->get_result()->fetch_assoc();
        $ev->close();
        $backEvent = ($evRow && $evRow['event_id'] !== null) ? (string)(int)$evRow['event_id'] : '0';

        $s = $conn->prepare('DELETE FROM orders WHERE id = ?');
        $s->bind_param('i', $id);
        $s->execute();
        $s->close();
        flash('Order deleted.');
        header('Location: index.php?event=' . urlencode($backEvent));
        exit;
    }

    // ── CREDIT OFFLINE SALES TO A PARTNER (date-range batch) ──────
    case 'credit_offline': {
        $pid  = (int)($_POST['partner_id'] ?? 0);
        $from = trim($_POST['from'] ?? '');
        $to   = trim($_POST['to'] ?? '');
        if ($pid < 1) throw new Exception('Choose a partner to credit.');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            throw new Exception('Pick a valid from and to date.');
        }

        $chk = $conn->prepare('SELECT name FROM partners WHERE id = ?');
        $chk->bind_param('i', $pid); $chk->execute();
        $prow = $chk->get_result()->fetch_assoc(); $chk->close();
        if (!$prow) throw new Exception('Partner not found.');
        $pname = $prow['name'];

        // Uncredited offline orders (no event, not yet credited) in the range.
        // DATE(created_at) so the range is inclusive by calendar day.
        $sel = $conn->prepare(
            "SELECT COALESCE(SUM(total),0) amt, COUNT(*) n
             FROM orders
             WHERE event_id IS NULL AND credited_mov_id IS NULL
               AND DATE(created_at) >= ? AND DATE(created_at) <= ?"
        );
        $sel->bind_param('ss', $from, $to);
        $sel->execute();
        $r = $sel->get_result()->fetch_assoc(); $sel->close();
        $amount = round((float)$r['amt'], 2);
        $count  = (int)$r['n'];
        if ($count === 0 || $amount <= 0.001) {
            throw new Exception('No uncredited offline orders in that date range.');
        }

        $today = date('Y-m-d');
        $src = 'Offline sales ' . $from . ' to ' . $to;
        $note = $count . ' order(s), full payable.';

        $conn->begin_transaction();
        try {
            // Create the credit movement.
            $ins = $conn->prepare(
                "INSERT INTO account_movements (mov_date, partner_id, direction, kind, amount, source, note)
                 VALUES (?,?,'credit','normal',?,?,?)"
            );
            $ins->bind_param('sidss', $today, $pid, $amount, $src, $note);
            $ins->execute();
            $movId = (int)$conn->insert_id;
            $ins->close();

            // Stamp those exact orders so they can't be credited again.
            $upd = $conn->prepare(
                "UPDATE orders SET credited_mov_id = ?
                 WHERE event_id IS NULL AND credited_mov_id IS NULL
                   AND DATE(created_at) >= ? AND DATE(created_at) <= ?"
            );
            $upd->bind_param('iss', $movId, $from, $to);
            $upd->execute();
            $upd->close();

            $conn->commit();
        } catch (Exception $ex) { $conn->rollback(); throw $ex; }

        flash('Credited ' . money($amount) . ' from ' . $count . ' offline order(s) to ' . $pname . '.');
        header('Location: movements.php');
        exit;
    }

    default:
        throw new Exception('Unknown action.');
    }
} catch (Exception $e) {
    flash($e->getMessage(), 'error');
}

$conn->close();
header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
exit;