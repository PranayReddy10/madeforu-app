<?php
/**
 * Orders from the website (madeforu.co.in) arrive here once they are
 * delivered, so the accounts in this panel include online sales.
 *
 * The website calls api/website.php with a shared key. The key lives in
 * app_settings ('website_sync_key') and is created on the Website sync page,
 * so nothing is edited on the server by hand.
 *
 * Website orders are tagged with an event called "Website orders": they show
 * under Viewing → Website orders, stay out of "Offline only", and get their
 * own line in Event P&L. Each keeps the website's order number in
 * orders.web_order_no, so sending the same order again updates it instead of
 * adding a copy.
 */
declare(strict_types=1);

const WEBSITE_EVENT_NAME = 'Website orders';

function website_setting(mysqli $conn, string $key): ?string {
    $s = $conn->prepare('SELECT sval FROM app_settings WHERE skey = ?');
    $s->bind_param('s', $key);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    return $row ? (string)$row['sval'] : null;
}

/** Replace the row outright (see push_state_set for why not ON DUPLICATE KEY). */
function website_setting_set(mysqli $conn, string $key, string $value): void {
    $s = $conn->prepare('DELETE FROM app_settings WHERE skey = ?');
    $s->bind_param('s', $key);
    $s->execute();
    $s->close();
    $s = $conn->prepare('INSERT INTO app_settings (skey, sval) VALUES (?, ?)');
    $s->bind_param('ss', $key, $value);
    $s->execute();
    $s->close();
}

/** Add orders.web_order_no the first time it is needed — no phpMyAdmin step. */
function website_ensure_schema(mysqli $conn): void {
    $has = $conn->query("SHOW COLUMNS FROM orders LIKE 'web_order_no'")->num_rows > 0;
    if (!$has) {
        $conn->query('ALTER TABLE orders ADD COLUMN web_order_no VARCHAR(40) NULL DEFAULT NULL, ADD UNIQUE KEY uq_web_order_no (web_order_no)');
        return;
    }
    // "Update database" adds the column but not its index, and the index is
    // what stops the same order being added twice when it is sent twice at once.
    if ($conn->query("SHOW INDEX FROM orders WHERE Key_name = 'uq_web_order_no'")->num_rows === 0) {
        $conn->query('ALTER TABLE orders ADD UNIQUE KEY uq_web_order_no (web_order_no)');
    }
}

/** The "Website orders" event, created on first use. */
function website_event_id(mysqli $conn): int {
    $name = WEBSITE_EVENT_NAME;
    $s = $conn->prepare('SELECT id FROM events WHERE name = ? ORDER BY id LIMIT 1');
    $s->bind_param('s', $name);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    if ($row) return (int)$row['id'];

    $notes = 'Delivered orders from madeforu.co.in, added automatically.';
    $s = $conn->prepare('INSERT INTO events (name, is_paid, entry_cost, is_active, notes) VALUES (?, 0, 0, 1, ?)');
    $s->bind_param('ss', $name, $notes);
    $s->execute();
    $id = (int)$conn->insert_id;
    $s->close();
    return $id;
}

function website_synced_count(mysqli $conn): int {
    try {
        return (int)$conn->query('SELECT COUNT(*) c FROM orders WHERE web_order_no IS NOT NULL')->fetch_assoc()['c'];
    } catch (mysqli_sql_exception $e) {
        return 0;   // column not added yet
    }
}

/*
 * Automatic credit
 * ----------------
 * When a partner is chosen on the Website sync page ('website_credit_partner'),
 * each delivered website order is credited to that partner's account as it
 * arrives: one movement per order, source "Website order <no>", carrying the
 * Website orders event and stamped on the order (orders.credited_mov_id) so
 * the order and its credit stay linked. When the website sends the order
 * again its credit follows the new total; when the order is cancelled or
 * returned its credit is removed.
 *
 * The credit is capped at what the event has not been credited yet. Orders
 * that arrived before automatic credit was switched on may already have been
 * credited by hand from Events → Credit to partner; sending one of those
 * again must not credit it a second time.
 */

/** The partner website orders are credited to, or 0 when that is off. */
function website_credit_partner(mysqli $conn): int {
    $pid = (int)website_setting($conn, 'website_credit_partner');
    if ($pid < 1) return 0;
    $s = $conn->prepare('SELECT id FROM partners WHERE id = ? AND is_active = 1');
    $s->bind_param('i', $pid);
    $s->execute();
    $ok = (bool)$s->get_result()->fetch_assoc();
    $s->close();
    return $ok ? $pid : 0;
}

/** What the event's orders come to, less everything credited from it. */
function website_event_gap(mysqli $conn, int $eventId): float {
    $s = $conn->prepare(
        "SELECT COALESCE((SELECT SUM(total) FROM orders WHERE event_id = ?),0)
              - COALESCE((SELECT SUM(amount) FROM account_movements WHERE event_id = ? AND direction = 'credit'),0) g"
    );
    $s->bind_param('ii', $eventId, $eventId);
    $s->execute();
    $g = (float)$s->get_result()->fetch_assoc()['g'];
    $s->close();
    return round($g, 2);
}

/**
 * Bring one website order's credit in line with its total. Call inside the
 * same transaction that wrote the order, after its total is recalculated.
 * Returns the partner's name and amount when a credit was made or changed.
 */
function website_sync_credit(mysqli $conn, int $orderId, int $eventId, string $webNo): ?array {
    $s = $conn->prepare(
        "SELECT o.total, o.credited_mov_id, m.amount mov_amount, m.partner_id
           FROM orders o LEFT JOIN account_movements m ON m.id = o.credited_mov_id
          WHERE o.id = ?"
    );
    $s->bind_param('i', $orderId);
    $s->execute();
    $o = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$o) return null;

    $movId = (int)($o['credited_mov_id'] ?? 0);
    $have = $o['mov_amount'] !== null ? (float)$o['mov_amount'] : 0.0;
    if ($movId > 0 && $o['mov_amount'] === null) $movId = 0;   // credit was deleted by hand

    // A new credit only when automatic credit is on; an existing one keeps
    // following its order (and its partner) even if it was switched off since.
    $pid = $movId > 0 ? (int)$o['partner_id'] : website_credit_partner($conn);
    if ($pid < 1) return null;

    $total = (float)$o['total'];
    $want = round(max(0.0, min($total, $have + website_event_gap($conn, $eventId))), 2);

    if ($movId > 0 && $want <= 0.001) {
        website_drop_credit($conn, $orderId);
        return null;
    }
    if ($movId > 0) {
        if (abs($want - $have) < 0.005) return null;
        $s = $conn->prepare('UPDATE account_movements SET amount = ? WHERE id = ?');
        $s->bind_param('di', $want, $movId);
        $s->execute();
        $s->close();
    } elseif ($want > 0.001) {
        $today = date('Y-m-d');
        $src = mb_substr('Website order ' . $webNo, 0, 120);
        $note = 'Credited automatically when the website marked it delivered.';
        $s = $conn->prepare(
            "INSERT INTO account_movements (mov_date, partner_id, event_id, direction, kind, amount, source, note)
             VALUES (?,?,?,'credit','normal',?,?,?)"
        );
        $s->bind_param('siidss', $today, $pid, $eventId, $want, $src, $note);
        $s->execute();
        $movId = (int)$conn->insert_id;
        $s->close();
        $s = $conn->prepare('UPDATE orders SET credited_mov_id = ? WHERE id = ?');
        $s->bind_param('ii', $movId, $orderId);
        $s->execute();
        $s->close();
    } else {
        return null;
    }

    $s = $conn->prepare('SELECT name FROM partners WHERE id = ?');
    $s->bind_param('i', $pid);
    $s->execute();
    $name = (string)($s->get_result()->fetch_assoc()['name'] ?? 'partner');
    $s->close();
    return ['partner' => $name, 'amount' => $want];
}

/** Remove the credit a website order made, before the order goes. */
function website_drop_credit(mysqli $conn, int $orderId): void {
    $s = $conn->prepare('SELECT credited_mov_id FROM orders WHERE id = ?');
    $s->bind_param('i', $orderId);
    $s->execute();
    $movId = (int)($s->get_result()->fetch_assoc()['credited_mov_id'] ?? 0);
    $s->close();
    if ($movId < 1) return;
    $s = $conn->prepare('UPDATE orders SET credited_mov_id = NULL WHERE id = ?');
    $s->bind_param('i', $orderId);
    $s->execute();
    $s->close();
    $s = $conn->prepare('DELETE FROM account_movements WHERE id = ?');
    $s->bind_param('i', $movId);
    $s->execute();
    $s->close();
}
