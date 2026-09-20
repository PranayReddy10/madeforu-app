<?php
/**
 * Channels and accounts — where a sale came from, where the money landed.
 *
 * Shared by the website and the API for the same reason lib_money.php is:
 * two copies of a rule means two answers with no way to tell which is
 * right. Include after config.php; needs nothing but a live mysqli.
 *
 * A note on tolerance. Every function here degrades to "no channels, no
 * accounts" if the migration has not been run yet, rather than taking a
 * page down with an undefined table. The site is uploaded file by file
 * over FTP, so for a while some pages will be new and the database old
 * (or the other way round); that window must not be a 500.
 */
declare(strict_types=1);

if (!function_exists('trade_has_table')) {
    /** Does this table exist? Cached, because it is asked on every page. */
    function trade_has_table(mysqli $conn, string $table): bool {
        static $cache = [];
        if (isset($cache[$table])) return $cache[$table];
        try {
            $s = $conn->prepare(
                'SELECT 1 FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
            );
            $s->bind_param('s', $table);
            $s->execute();
            $found = (bool)$s->get_result()->fetch_row();
            $s->close();
        } catch (Throwable $e) {
            $found = false;
        }
        return $cache[$table] = $found;
    }
}

if (!function_exists('db_column_exists')) {
    /**
     * Does this column exist yet?
     *
     * The website is uploaded over FTP a file at a time, so a page that
     * writes a new column will run for a while against a database that
     * has not got it. Asking first turns "the sale page is broken until
     * the SQL is run" into "the sale page works, and records the new
     * field once the SQL is run".
     *
     * Named differently from the API's db_has_column so the two can
     * coexist when both are loaded.
     */
    function db_column_exists(mysqli $conn, string $table, string $column): bool {
        static $cache = [];
        $key = $table . '.' . $column;
        if (isset($cache[$key])) return $cache[$key];
        try {
            $s = $conn->prepare(
                'SELECT 1 FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
            );
            $s->bind_param('ss', $table, $column);
            $s->execute();
            $found = (bool)$s->get_result()->fetch_row();
            $s->close();
        } catch (Throwable $e) {
            $found = false;
        }
        return $cache[$key] = $found;
    }
}

if (!function_exists('trade_ready')) {
    /** True once the channels/accounts migration has been applied. */
    function trade_ready(mysqli $conn): bool {
        return trade_has_table($conn, 'channels') && trade_has_table($conn, 'accounts');
    }
}

// ── Channels ─────────────────────────────────────────────────────────

if (!function_exists('channels_all')) {
    /**
     * The channel list, in display order.
     *
     * $activeOnly hides retired channels from pickers, but a channel that
     * is off must still be readable — orders sold through it keep pointing
     * at it, and their history should not turn into a blank.
     */
    function channels_all(mysqli $conn, bool $activeOnly = true): array {
        if (!trade_has_table($conn, 'channels')) return [];
        $sql = 'SELECT id, name, slug, settles_later, is_active, sort_order, notes
                  FROM channels';
        if ($activeOnly) $sql .= ' WHERE is_active = 1';
        $sql .= ' ORDER BY sort_order, name';
        $out = [];
        $res = $conn->query($sql);
        while ($res && ($r = $res->fetch_assoc())) {
            $out[] = [
                'id'            => (int)$r['id'],
                'name'          => $r['name'],
                'slug'          => $r['slug'],
                'settles_later' => (int)$r['settles_later'] === 1,
                'is_active'     => (int)$r['is_active'] === 1,
                'sort_order'    => (int)$r['sort_order'],
                'notes'         => $r['notes'],
            ];
        }
        return $out;
    }
}

if (!function_exists('channel_map')) {
    /** id => channel row, retired ones included, for labelling history. */
    function channel_map(mysqli $conn): array {
        $out = [];
        foreach (channels_all($conn, false) as $c) $out[$c['id']] = $c;
        return $out;
    }
}

if (!function_exists('channel_name')) {
    /** A channel's name, or a dash when an order predates channels. */
    function channel_name(mysqli $conn, ?int $id, string $blank = '—'): string {
        if ($id === null) return $blank;
        $m = channel_map($conn);
        return $m[$id]['name'] ?? $blank;
    }
}

if (!function_exists('valid_channel_id')) {
    /**
     * A channel id from a form, or null. Anything unrecognised becomes
     * null rather than an error: a sale is never blocked because a
     * dropdown was stale.
     */
    function valid_channel_id(mysqli $conn, $raw): ?int {
        if ($raw === null || $raw === '' || (int)$raw === 0) return null;
        $id = (int)$raw;
        return isset(channel_map($conn)[$id]) ? $id : null;
    }
}

// ── Per-channel prices ───────────────────────────────────────────────

if (!function_exists('channel_price_map')) {
    /** item => price, only for the items this channel prices differently. */
    function channel_price_map(mysqli $conn, ?int $channelId): array {
        if ($channelId === null || !trade_has_table($conn, 'channel_prices')) return [];
        $s = $conn->prepare('SELECT item, price FROM channel_prices WHERE channel_id = ?');
        $s->bind_param('i', $channelId);
        $s->execute();
        $res = $s->get_result();
        $out = [];
        while ($r = $res->fetch_assoc()) $out[$r['item']] = (float)$r['price'];
        $s->close();
        return $out;
    }
}

if (!function_exists('channel_items')) {
    /**
     * The catalogue as this channel sells it: the normal price list with
     * the channel's own prices laid over the top.
     *
     * Only items the channel actually prices differently need a row, so
     * Amazon can carry three overrides rather than a copy of the whole
     * catalogue that silently goes stale when a catalogue price moves.
     *
     * An override for an item no longer in the catalogue is ignored
     * rather than resurrecting a retired product into the picker.
     */
    function channel_items(mysqli $conn, array $items, ?int $channelId): array {
        $over = channel_price_map($conn, $channelId);
        if (!$over) return $items;
        foreach ($over as $item => $price) {
            if (array_key_exists($item, $items)) $items[$item] = $price;
        }
        return $items;
    }
}

// ── Accounts ─────────────────────────────────────────────────────────

if (!function_exists('accounts_all')) {
    /**
     * Accounts money can land in, with the partner who holds one if any.
     *
     * The join is on partner_id rather than on a name, which is what
     * keeps it out of the collation trouble that name joins hit on this
     * database (products is utf8mb4_unicode_ci, several older tables are
     * utf8mb4_uca1400_ai_ci, and MariaDB refuses to compare the two).
     * An account whose partner has been removed still lists, with an
     * empty partner name.
     */
    function accounts_all(mysqli $conn, bool $activeOnly = true): array {
        if (!trade_has_table($conn, 'accounts')) return [];
        $sql = 'SELECT a.id, a.name, a.kind, a.partner_id, a.is_active, a.notes,
                       p.name AS partner_name
                  FROM accounts a
                  LEFT JOIN partners p ON p.id = a.partner_id';
        if ($activeOnly) $sql .= ' WHERE a.is_active = 1';
        $sql .= ' ORDER BY a.name';
        $out = [];
        $res = $conn->query($sql);
        while ($res && ($r = $res->fetch_assoc())) {
            $out[] = [
                'id'           => (int)$r['id'],
                'name'         => $r['name'],
                'kind'         => $r['kind'],
                'partner_id'   => $r['partner_id'] === null ? null : (int)$r['partner_id'],
                'partner_name' => $r['partner_name'],
                'is_active'    => (int)$r['is_active'] === 1,
                'notes'        => $r['notes'],
            ];
        }
        return $out;
    }
}

if (!function_exists('account_map')) {
    /** id => account row, retired ones included. */
    function account_map(mysqli $conn): array {
        $out = [];
        foreach (accounts_all($conn, false) as $a) $out[$a['id']] = $a;
        return $out;
    }
}

if (!function_exists('valid_account_id')) {
    /** An account id from a form, or null if it is not one of ours. */
    function valid_account_id(mysqli $conn, $raw): ?int {
        if ($raw === null || $raw === '' || (int)$raw === 0) return null;
        $id = (int)$raw;
        return isset(account_map($conn)[$id]) ? $id : null;
    }
}

if (!function_exists('account_balances')) {
    /**
     * What each account holds: credits in, debits out.
     *
     * Every kind counts here, unlike revenue. A transfer between partners
     * is not revenue but it genuinely moves money out of one account and
     * into another, and an account balance that ignored that would not
     * match the bank.
     *
     * Movements with no account (everything recorded before this feature)
     * are gathered under a null id so the total still reconciles and the
     * unassigned pile is visible rather than quietly dropped.
     */
    function account_balances(mysqli $conn): array {
        if (!trade_ready($conn)) return [];
        $rows = [];
        foreach (accounts_all($conn, false) as $a) {
            $rows[$a['id']] = $a + ['credit' => 0.0, 'debit' => 0.0, 'balance' => 0.0, 'movements' => 0];
        }
        $rows[0] = [
            'id' => null, 'name' => 'Not assigned to an account', 'kind' => 'other',
            'partner_id' => null, 'partner_name' => null, 'is_active' => true,
            'notes' => null, 'credit' => 0.0, 'debit' => 0.0, 'balance' => 0.0, 'movements' => 0,
        ];

        $res = $conn->query(
            "SELECT COALESCE(account_id, 0) aid, direction,
                    COALESCE(SUM(amount),0) v, COUNT(*) n
               FROM account_movements
              GROUP BY aid, direction"
        );
        while ($res && ($r = $res->fetch_assoc())) {
            $aid = (int)$r['aid'];
            if (!isset($rows[$aid])) continue;   // account deleted outright
            $rows[$aid][$r['direction']] += (float)$r['v'];
            $rows[$aid]['movements']     += (int)$r['n'];
        }
        foreach ($rows as $k => $r) {
            $rows[$k]['credit']  = round($r['credit'], 2);
            $rows[$k]['debit']   = round($r['debit'], 2);
            $rows[$k]['balance'] = round($r['credit'] - $r['debit'], 2);
        }
        // Drop the unassigned row when there is nothing in it.
        if ($rows[0]['movements'] === 0) unset($rows[0]);
        return array_values($rows);
    }
}

// ── Channel settlement ───────────────────────────────────────────────

if (!function_exists('channel_settlement')) {
    /**
     * For each channel that pays out later: what its orders came to, what
     * it has actually paid, and the difference.
     *
     * This is the question a marketplace raises that a counter sale does
     * not. An Amazon order is revenue the day it is sold, but the money
     * turns up days later in a lump covering many orders, minus their
     * commission. Sold and received are therefore two different numbers,
     * and the gap between them is either money still owed or fees.
     *
     * `received` counts kind='payout' only. Those movements are
     * deliberately NOT revenue — see revenue_sources() in lib_money.php —
     * because the orders they settle are already counted. Recording both
     * as revenue is precisely the double count this feature exists to
     * avoid.
     */
    function channel_settlement(mysqli $conn): array {
        if (!trade_ready($conn)) return [];
        $out = [];
        foreach (channels_all($conn, false) as $c) {
            if (!$c['settles_later']) continue;

            $s = $conn->prepare(
                'SELECT COALESCE(SUM(total),0) v, COUNT(*) n FROM orders WHERE channel_id = ?'
            );
            $s->bind_param('i', $c['id']);
            $s->execute();
            $o = $s->get_result()->fetch_assoc();
            $s->close();

            $s = $conn->prepare(
                "SELECT COALESCE(SUM(amount),0) v, COUNT(*) n
                   FROM account_movements
                  WHERE channel_id = ? AND direction = 'credit' AND kind = 'payout'"
            );
            $s->bind_param('i', $c['id']);
            $s->execute();
            $p = $s->get_result()->fetch_assoc();
            $s->close();

            $sold     = round((float)$o['v'], 2);
            $received = round((float)$p['v'], 2);
            $out[] = [
                'channel_id'  => $c['id'],
                'channel'     => $c['name'],
                'orders'      => (int)$o['n'],
                'sold'        => $sold,
                'payouts'     => (int)$p['n'],
                'received'    => $received,
                'outstanding' => round($sold - $received, 2),
            ];
        }
        return $out;
    }
}

if (!function_exists('channel_prices_all')) {
    /**
     * Every channel's overrides at once: [channel_id => [item => price]].
     *
     * The sale form needs this in one go. It prices lines in the browser
     * as you type, and if it priced them from the catalogue while the
     * server priced them from the channel, the total on screen would not
     * be the total charged -- the worst kind of disagreement, because it
     * only shows up after the sale is saved.
     */
    function channel_prices_all(mysqli $conn): array {
        if (!trade_has_table($conn, 'channel_prices')) return [];
        $out = [];
        $res = $conn->query('SELECT channel_id, item, price FROM channel_prices');
        while ($res && ($r = $res->fetch_assoc())) {
            $out[(int)$r['channel_id']][$r['item']] = round((float)$r['price'], 2);
        }
        return $out;
    }
}
