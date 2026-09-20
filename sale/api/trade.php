<?php
/**
 * Channels and accounts for the apps.
 *
 * Read-heavy on purpose. Adding a channel or an account is a decision
 * about how the business is organised, not something to do from a phone
 * mid-sale, so the apps list and use them and the website manages them —
 * the same split as products, where a phone can reprice but renaming
 * (which propagates through seven tables) stays on the website.
 *
 * The one thing the apps can change here is a channel's prices, because
 * that is a shopkeeper's decision made where the shopkeeper is.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib_trade.php';

$me = api_require_auth($conn);

/**
 * Everything the sale screen needs to price through a channel, in one
 * call: the channels and their overrides together.
 *
 * Sent together rather than fetched per channel because the app prices
 * lines as you type. A round trip on every change of the channel picker
 * would show the wrong total until it landed, which is exactly the
 * disagreement this is meant to prevent.
 */
function trade_channels_payload(mysqli $conn): array {
    $prices = channel_prices_all($conn);
    $out    = [];
    foreach (channels_all($conn, false) as $c) {
        $c['prices'] = $prices[$c['id']] ?? new stdClass();
        $out[] = $c;
    }
    return $out;
}

api_dispatch([

    // ── GET list — channels, accounts, and what is owed per channel ──
    'list' => function () use ($conn) {
        if (!trade_ready($conn)) {
            // Named plainly rather than returned as an empty list: an app
            // that silently shows nothing makes a missing migration look
            // like a broken screen.
            api_ok([
                'ready'       => false,
                'channels'    => [],
                'accounts'    => [],
                'settlement'  => [],
                'message'     => 'Channels and accounts are not set up on the server yet. '
                               . 'Run sale/api/migrations/2026-09-channels-accounts-stock.sql.',
            ]);
        }
        api_ok([
            'ready'      => true,
            'channels'   => trade_channels_payload($conn),
            'accounts'   => accounts_all($conn, false),
            'settlement' => channel_settlement($conn),
        ]);
    },

    // ── POST set_channel_price ────────────────────────────────────
    //
    // An empty or absent price clears the override, which is different
    // from zero: zero is a free item, absent is "sells at the catalogue
    // price". Collapsing the two would make it impossible to stop
    // overriding without deleting the channel.
    'set_channel_price' => function () use ($conn, $ITEMS) {
        if (!trade_ready($conn)) throw new ApiInputError('Channels are not set up on the server yet.');

        $channelId = (int)api_in('channel_id', 0);
        if (!isset(channel_map($conn)[$channelId])) throw new ApiInputError('Unknown channel.');

        $item = trim((string)api_in('item', ''));
        if ($item === '') throw new ApiInputError('Which product?');
        if (!array_key_exists($item, $ITEMS)) throw new ApiInputError('Unknown product: ' . $item);

        $raw = api_in('price', null);
        if ($raw === null || trim((string)$raw) === '') {
            $s = $conn->prepare('DELETE FROM channel_prices WHERE channel_id = ? AND item = ?');
            $s->bind_param('is', $channelId, $item);
            $s->execute();
            $s->close();
            api_ok([
                'channels' => trade_channels_payload($conn),
                'message'  => $item . ' now sells at the catalogue price of '
                            . money((float)$ITEMS[$item]) . ' here.',
            ]);
        }

        $price = round((float)$raw, 2);
        if ($price < 0) throw new ApiInputError('A price cannot be negative.');

        $s = $conn->prepare(
            'INSERT INTO channel_prices (channel_id, item, price) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE price = VALUES(price)'
        );
        $s->bind_param('isd', $channelId, $item, $price);
        $s->execute();
        $s->close();

        $name = channel_map($conn)[$channelId]['name'];
        api_ok([
            'channels' => trade_channels_payload($conn),
            'message'  => $item . ' is ' . money($price) . ' on ' . $name
                        . '. This applies to new sales only — orders already taken '
                        . 'keep the price they were sold at.',
        ]);
    },

    // ── GET material_audit — bought against used ──────────────────
    'material_audit' => function () use ($conn) {
        require_once __DIR__ . '/../stock_lib.php';
        if (!stock_draws_ready($conn)) {
            api_ok([
                'ready'   => false,
                'rows'    => [],
                'message' => 'Raw material tracking is not set up on the server yet.',
            ]);
        }
        $rows = stock_usage($conn);
        $drawn = (int)($conn->query(
            "SELECT COUNT(DISTINCT ref_id) c FROM stock_draws WHERE ref_type = 'order'"
        )->fetch_assoc()['c'] ?? 0);

        $bought = $used = $stock = 0.0;
        foreach ($rows as $r) {
            $bought += $r['bought_value'];
            $used   += $r['used_value'];
            $stock  += $r['stock_value'];
        }
        api_ok([
            'ready'         => true,
            'rows'          => $rows,
            'orders_drawn'  => $drawn,
            'totals'        => [
                'bought' => round($bought, 2),
                'used'   => round($used, 2),
                'stock'  => round($stock, 2),
            ],
        ]);
    },

    // ── GET order_material — what one order ate ───────────────────
    'order_material' => function () use ($conn) {
        require_once __DIR__ . '/../stock_lib.php';
        $id = (int)api_in('id', 0);
        if ($id < 1) throw new ApiInputError('Which order?');
        api_ok(['draws' => stock_draws_for($conn, 'order', $id)]);
    },
]);
