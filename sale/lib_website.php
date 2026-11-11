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
