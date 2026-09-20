<?php
/**
 * Partner finance: balances, investment fairness, settle-up, ledger.
 *
 * ⚠ KEEP IN SYNC. The equal-share basis below is a third implementation of
 * the same arithmetic that lives in investment.php and, hand-copied again,
 * in movements.php's investment_gaps(). Change one and you must change all
 * three, or two partners will read different numbers off the same data and
 * neither will be able to tell which is right. The basis, spelled out:
 *
 *   paid        = Σ expense_payments.amount          (own pocket, into the business)
 *   credited    = Σ account_movements credits        (business money that came back)
 *   debited     = Σ account_movements debits         (money drawn out of that account)
 *   adj         = Σ account_movements.invest_adjust  (settle-up transfers)
 *
 *   contribution    = paid - credited + adj
 *   fair share      = Σ contribution / number of partners
 *   gap             = contribution - fair share   (negative = owes, positive = is owed)
 *   account balance = credited - debited
 *
 * `debited` is deliberately NOT added back into contribution: a debit is
 * money leaving an account that was already credited, so counting it again
 * would cancel the credit and make anyone who has withdrawn look as if
 * they never took anything.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib_trade.php';   // accounts and channels

$me = api_require_auth($conn);

/**
 * Where the headline "Revenue (all sales)" figure comes from.
 *
 * This exists because the same word — revenue — is four different numbers
 * in this app, and a partner comparing two screens has no way to tell
 * which one they are looking at:
 *
 *   total       Σ orders.total over EVERY order ever. This is what
 *               investment.php's profit card uses, and what this API
 *               returns as business.revenue.
 *   collected   Σ orders.paid_amount — money actually received. Lower
 *               than `total` whenever an order still has a balance.
 *   credited    the part of `total` that has been taken into a partner's
 *               account (orders.credited_mov_id is set). The rest is
 *               sitting outside anyone's account.
 *   period      what the Stats screen shows, which is only the orders
 *               inside the selected date range.
 *
 * Returning all four next to each other is the whole point: whichever
 * number someone is querying, they can see it here beside the headline
 * and read off the difference instead of guessing at it.
 */
function revenue_breakdown_api(mysqli $conn): array {
    $r = $conn->query(
        'SELECT COUNT(*) n,
                COALESCE(SUM(total),0)        total,
                COALESCE(SUM(subtotal),0)     subtotal,
                COALESCE(SUM(discount),0)     discount,
                COALESCE(SUM(extra_charge),0) extra,
                COALESCE(SUM(paid_amount),0)  collected,
                MIN(DATE(created_at))         first_order,
                MAX(DATE(created_at))         last_order
           FROM orders'
    )->fetch_assoc() ?: [];

    $cr = $conn->query(
        'SELECT COUNT(*) n, COALESCE(SUM(total),0) amt
           FROM orders WHERE credited_mov_id IS NOT NULL'
    )->fetch_assoc() ?: ['n' => 0, 'amt' => 0];

    // The Indian financial year runs April to March, the same boundary
    // bill numbers use — so "this year" means the same thing on the bill
    // book and here.
    $fyStart = (date('n') >= 4 ? date('Y') : (string)((int)date('Y') - 1)) . '-04-01';

    $window = function (string $from) use ($conn): array {
        $s = $conn->prepare(
            'SELECT COUNT(*) n, COALESCE(SUM(total),0) amt FROM orders WHERE DATE(created_at) >= ?'
        );
        $s->bind_param('s', $from);
        $s->execute();
        $row = $s->get_result()->fetch_assoc() ?: ['n' => 0, 'amt' => 0];
        $s->close();
        return ['orders' => (int)$row['n'], 'amount' => round((float)$row['amt'], 2)];
    };

    $total     = round((float)($r['total'] ?? 0), 2);
    $collected = round((float)($r['collected'] ?? 0), 2);
    $credited  = round((float)$cr['amt'], 2);

    return [
        'orders'      => (int)($r['n'] ?? 0),
        'total'       => $total,
        // total = subtotal − discount + extra_charge. Shown so the headline
        // can be checked against the order list without a calculator.
        'subtotal'    => round((float)($r['subtotal'] ?? 0), 2),
        'discount'    => round((float)($r['discount'] ?? 0), 2),
        'extra'       => round((float)($r['extra'] ?? 0), 2),
        'collected'   => $collected,
        'outstanding' => round($total - $collected, 2),
        'credited'    => $credited,
        'credited_orders'   => (int)$cr['n'],
        'uncredited'        => round($total - $credited, 2),
        'uncredited_orders' => (int)($r['n'] ?? 0) - (int)$cr['n'],
        'this_month'  => $window(date('Y-m-01')),
        'this_year'   => $window($fyStart),
        'year_label'  => fy_label($fyStart),
        'first_order' => $r['first_order'] ?? null,
        'last_order'  => $r['last_order'] ?? null,
    ];
}

/** The shared definition lives in sale/lib_money.php — see the note there. */
function revenue_sources_api(mysqli $conn): array { return revenue_sources($conn); }

/** Revenue − expenses, and how much of it is already in partners' accounts. */
function business_profit_api(mysqli $conn): array {
    $src = revenue_sources_api($conn);
    $rev = (float)$src['total'];
    $exp = (float)($conn->query('SELECT COALESCE(SUM(amount - discount),0) v FROM expenses')->fetch_assoc()['v'] ?? 0);
    $dist = (float)($conn->query("SELECT COALESCE(SUM(amount),0) v FROM account_movements WHERE kind = 'profit'")
                         ->fetch_assoc()['v'] ?? 0);
    $profit = round($rev - $exp, 2);
    return ['revenue' => round($rev, 2), 'expenses' => round($exp, 2), 'profit' => $profit,
            'distributed' => round($dist, 2), 'remaining' => round($profit - $dist, 2),
            'revenue_orders' => $src['orders'], 'revenue_other' => $src['other'],
            'sources' => $src];
}

/** Per-partner rows on the basis documented at the top of this file. */
function partner_rows(mysqli $conn): array {
    $partners = $conn->query(
        'SELECT p.id, p.name, p.is_active,
           (SELECT COALESCE(SUM(amount),0) FROM expense_payments WHERE partner_id = p.id) paid,
           (SELECT COALESCE(SUM(amount),0) FROM account_movements
              WHERE partner_id = p.id AND direction = "credit") credited,
           (SELECT COALESCE(SUM(amount),0) FROM account_movements
              WHERE partner_id = p.id AND direction = "debit") debited,
           (SELECT COALESCE(SUM(invest_adjust),0) FROM account_movements
              WHERE partner_id = p.id) invest_adj
         FROM partners p ORDER BY p.name'
    )->fetch_all(MYSQLI_ASSOC);

    $rows = [];
    foreach ($partners as $p) {
        $paid = (float)$p['paid']; $cr = (float)$p['credited'];
        $db   = (float)$p['debited']; $adj = (float)$p['invest_adj'];

        // A dormant partner with no money anywhere is noise on a phone
        // screen; an active one always shows, even at zero.
        if ($paid == 0 && $cr == 0 && $db == 0 && $adj == 0 && (int)$p['is_active'] !== 1) continue;

        $rows[] = [
            'id' => (int)$p['id'], 'name' => $p['name'], 'is_active' => (int)$p['is_active'] === 1,
            'paid' => round($paid, 2), 'credited' => round($cr, 2), 'debited' => round($db, 2),
            'adj' => round($adj, 2),
            // investment.php's two columns: Remaining is what a partner
            // has put in that has not come back yet; Net invested adds the
            // settle-up transfers on top.
            'remaining' => round($paid - $cr, 2),
            'contribution' => round($paid - $cr + $adj, 2),
            'balance' => round($cr - $db, 2),
        ];
    }

    $n = count($rows);
    $totalContrib = array_sum(array_column($rows, 'contribution'));
    $totalBal     = array_sum(array_column($rows, 'balance'));
    $totalCred    = array_sum(array_column($rows, 'credited'));

    $fairShare = $n > 0 ? round($totalContrib / $n, 2) : 0.0;
    $fairBal   = $n > 0 ? round($totalBal / $n, 2) : 0.0;
    $fairCred  = $n > 0 ? round($totalCred / $n, 2) : 0.0;

    foreach ($rows as &$r) {
        $r['fair_share'] = $fairShare;
        $r['gap']        = round($r['contribution'] - $fairShare, 2);
        $r['fair_balance'] = $fairBal;
        $r['balance_gap']  = round($r['balance'] - $fairBal, 2);
        // The gap split into where it came from. cred_gap is (fair − credited),
        // not the other way round: a partner who has drawn less than their
        // share is OWED that money, so it counts in their favour. Rounding
        // drift is pushed into inv_gap so the three always sum to gap.
        $r['cred_gap'] = round($fairCred - $r['credited'], 2);
        $r['adj_gap']  = round($r['adj'], 2);
        $r['inv_gap']  = round($r['gap'] - $r['cred_gap'] - $r['adj_gap'], 2);
    }
    unset($r);

    return [$rows, [
        'partners' => $n,
        'paid'      => round(array_sum(array_column($rows, 'paid')), 2),
        'credited'  => round($totalCred, 2),
        'debited'   => round(array_sum(array_column($rows, 'debited')), 2),
        'remaining' => round(array_sum(array_column($rows, 'remaining')), 2),
        'contribution' => round($totalContrib, 2),
        'balance'   => round($totalBal, 2),
        'fair_share' => $fairShare,
        'fair_balance' => $fairBal,
        // Each side's fair share, so the app can show the arithmetic
        // rather than asking a partner to take the gap on trust.
        'fair_paid' => $n > 0 ? round(array_sum(array_column($rows, 'paid')) / $n, 2) : 0.0,
        'fair_credited' => $fairCred,
        'share_pct' => $n > 0 ? round(100 / $n, 1) : 0.0,
    ]];
}

/**
 * Fewest transfers that close a set of gaps. Greedy: the largest debtor
 * pays the largest creditor until one of them is square, repeat.
 * $flip is for account balances, where holding extra means you pay out.
 */
function settle_plan(array $rows, string $gapKey, bool $flip = false): array {
    $debtors = []; $creditors = [];
    foreach ($rows as $r) {
        $g = $flip ? -$r[$gapKey] : $r[$gapKey];
        if ($g < -0.01)    $debtors[]   = ['id' => $r['id'], 'name' => $r['name'], 'amt' => -$g];
        elseif ($g > 0.01) $creditors[] = ['id' => $r['id'], 'name' => $r['name'], 'amt' => $g];
    }
    usort($debtors,   fn($a, $b) => $b['amt'] <=> $a['amt']);
    usort($creditors, fn($a, $b) => $b['amt'] <=> $a['amt']);

    $plan = []; $di = 0; $ci = 0;
    while ($di < count($debtors) && $ci < count($creditors)) {
        $pay = round(min($debtors[$di]['amt'], $creditors[$ci]['amt']), 2);
        if ($pay > 0.01) {
            $plan[] = ['from_id' => $debtors[$di]['id'], 'from' => $debtors[$di]['name'],
                       'to_id' => $creditors[$ci]['id'], 'to' => $creditors[$ci]['name'], 'amount' => $pay];
        }
        $debtors[$di]['amt']   = round($debtors[$di]['amt'] - $pay, 2);
        $creditors[$ci]['amt'] = round($creditors[$ci]['amt'] - $pay, 2);
        if ($debtors[$di]['amt']   <= 0.01) $di++;
        if ($creditors[$ci]['amt'] <= 0.01) $ci++;
    }
    return $plan;
}

/**
 * One box per partner, with every figure that concerns them in it.
 *
 * The four partners hold equal shares, so every total here divides four
 * ways — but the two halves must not be confused, and putting them side
 * by side is the point of this structure:
 *
 *   INVESTMENT is money a partner put IN from their own pocket. The fair
 *   share is the total paid divided equally; the gap says whether they
 *   are owed (they paid more than their quarter) or owe (less).
 *
 *   PROFIT is what the business earned — all revenue less all expenses —
 *   and each partner's quarter of it. It is NOT the same as their
 *   account balance: profit is earned, a balance is what has actually
 *   been taken into their account. A partner can be owed profit and have
 *   drawn nothing, or have drawn money against a loss.
 *
 *   CREDITED / DEBITED / BALANCE is that account: money taken in, money
 *   drawn out, and what is left sitting there.
 *
 *   SETTLEMENT is the transfer that would even the two gaps up.
 */
function partner_detail_api(array $rows, array $business, array $settleInvest,
                            array $settleBalance): array {
    $n = count($rows);
    if ($n === 0) return [];

    $profit       = (float)$business['profit'];
    $profitShare  = round($profit / $n, 2);
    $distributed  = (float)$business['distributed'];
    $distShare    = round($distributed / $n, 2);

    // Split on what was actually PAID OUT OF POCKET, not on net invested.
    // They are different numbers — net invested subtracts what has been
    // credited back — and a row that says "they paid ₹33,483" next to an
    // equal share computed from net invested is comparing two different
    // things. Net invested still drives the settle-up plan, because that
    // is investment.php's basis and the website must agree; it is just
    // not what this row is asking about.
    $totalPaid    = array_sum(array_column($rows, 'paid'));
    $fairPaid     = round($totalPaid / $n, 2);

    // Revenue, every channel, divided equally — what each partner's share
    // of the business taking is before any cost comes off it.
    $revenue      = (float)$business['revenue'];
    $revenueShare = round($revenue / $n, 2);

    // Who owes whom, indexed so each partner's box can state their own
    // side of it rather than making them read a list of everyone's.
    $owes = []; $owed = [];
    foreach ($settleInvest as $step) {
        $owes[$step['from']][] = ['to' => $step['to'], 'amount' => $step['amount']];
        $owed[$step['to']][]   = ['from' => $step['from'], 'amount' => $step['amount']];
    }

    $out = [];
    foreach ($rows as $r) {
        $name = $r['name'];
        $out[] = [
            'id'   => $r['id'],
            'name' => $name,

            // Out of their own pocket, against an equal share of what
            // everyone paid.
            'paid'            => $r['paid'],
            'fair_paid'       => $fairPaid,
            'paid_gap'        => round($r['paid'] - $fairPaid, 2),

            // Their equal share of everything the business took in.
            'revenue_share'   => $revenueShare,

            // Net invested and its gap stay because the settle-up plan is
            // built on them, and the website shows the same basis.
            'invested_net'    => $r['contribution'],
            'fair_invested'   => $r['fair_share'],
            'investment_gap'  => $r['gap'],

            // Their quarter of what the business earned.
            'profit_share'      => $profitShare,
            'profit_distributed'=> $distShare,
            'profit_pending'    => round($profitShare - $distShare, 2),

            // Their account: in, out, and what is left.
            'credited' => $r['credited'],
            'debited'  => $r['debited'],
            'balance'  => $r['balance'],

            // Settle-up already recorded, and what is still outstanding.
            'settled_adjust' => $r['adj'],
            'owes'           => $owes[$name] ?? [],
            'owed'           => $owed[$name] ?? [],

            'position' => abs($r['gap']) < 0.01 ? 'even'
                        : ($r['gap'] > 0 ? 'owed' : 'owes'),
        ];
    }
    return [
        'partners'      => $out,
        'count'         => $n,
        'share_pct'     => round(100 / $n, 1),
        'profit'        => round($profit, 2),
        'profit_share'  => $profitShare,
        'revenue'       => round($revenue, 2),
        'revenue_share' => $revenueShare,
        'fair_paid'     => $fairPaid,
        'total_paid'    => round($totalPaid, 2),
        'total_credited'=> round(array_sum(array_column($rows, 'credited')), 2),
        'total_balance' => round(array_sum(array_column($rows, 'balance')), 2),
    ];
}

api_dispatch([

    // ── GET overview — the whole Money tab in one request ──────────
    'overview' => function () use ($conn) {
        $business = business_profit_api($conn);
        [$rows, $totals] = partner_rows($conn);
        $settleInvest  = settle_plan($rows, 'gap');
        $settleBalance = settle_plan($rows, 'balance_gap', true);

        // Uncredited offline money: sales nobody has taken into an account
        // yet. This is the number that tells a partner there is cash to
        // reconcile, so it belongs on the overview rather than two taps in.
        $pending = $conn->query(
            'SELECT COUNT(*) n, COALESCE(SUM(total),0) amt FROM orders
              WHERE event_id IS NULL AND credited_mov_id IS NULL'
        )->fetch_assoc();

        // "Expenses by category" — the same query and the same basis
        // investment.php uses for its breakdown.
        $categories = [];
        $catTotal = 0.0;
        try {
            $res = $conn->query(
                'SELECT category, COALESCE(SUM(amount - discount),0) t, COUNT(*) n
                   FROM expenses GROUP BY category ORDER BY t DESC'
            );
            while ($res && ($r = $res->fetch_assoc())) {
                $net = round((float)$r['t'], 2);
                $catTotal += $net;
                $categories[] = ['category' => $r['category'], 'count' => (int)$r['n'], 'net' => $net];
            }
        } catch (mysqli_sql_exception $e) { /* no expenses table yet */ }

        api_ok([
            'partners'        => $rows,
            'totals'          => $totals,
            'categories'      => $categories,
            'category_total'  => round($catTotal, 2),
            'business'        => $business,
            'partner_detail'  => partner_detail_api($rows, $business, $settleInvest, $settleBalance),
            'revenue_breakdown' => revenue_breakdown_api($conn),
            // Where the money came from, and what a marketplace still
            // owes. by_channel counts orders; settlement compares those
            // orders against payouts, which are deliberately not revenue.
            'by_channel'      => revenue_by_channel($conn),
            'settlement'      => channel_settlement($conn),
            'accounts'        => account_balances($conn),
            'settle_invest'   => $settleInvest,
            'settle_balance'  => $settleBalance,
            'uncredited_offline' => [
                'orders' => (int)$pending['n'],
                'amount' => round((float)$pending['amt'], 2),
            ],
        ]);
    },


    // ── GET movements — the ledger, newest first ───────────────────
    'movements' => function () use ($conn) {
        $where = []; $types = ''; $params = [];

        $pid = api_int('partner_id');
        if ($pid > 0) { $where[] = 'm.partner_id = ?'; $types .= 'i'; $params[] = $pid; }

        $dir = api_str('direction');
        if (in_array($dir, ['credit', 'debit'], true)) { $where[] = 'm.direction = ?'; $types .= 's'; $params[] = $dir; }

        $from = api_date('from'); $to = api_date('to');
        if ($from !== '') { $where[] = 'm.mov_date >= ?'; $types .= 's'; $params[] = $from; }
        if ($to   !== '') { $where[] = 'm.mov_date <= ?'; $types .= 's'; $params[] = $to; }

        $limit  = max(1, min(api_int('limit', 60), MAX_PAGE_SIZE));
        $offset = max(0, api_int('offset', 0));

        // The account and channel columns arrive with a migration, so the
        // ledger keeps working on a server that has the new API but not
        // yet the new SQL.
        $hasTrade = db_has_column($conn, 'account_movements', 'account_id');
        $sql = 'SELECT m.id, m.mov_date, m.direction, m.kind, m.amount, m.invest_adjust,
                       m.source, m.note, m.event_id, m.partner_id, m.transfer_id,
                       p.name partner, e.name event_name'
             . ($hasTrade ? ', m.account_id, m.channel_id, ac.name account_name, ch.name channel_name' : '')
             . ' FROM account_movements m
                  JOIN partners p ON p.id = m.partner_id
                  LEFT JOIN events e ON e.id = m.event_id'
             . ($hasTrade ? ' LEFT JOIN accounts ac ON ac.id = m.account_id
                             LEFT JOIN channels ch ON ch.id = m.channel_id' : '')
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY m.mov_date DESC, m.id DESC LIMIT ? OFFSET ?';

        $s = $conn->prepare($sql);
        $s->bind_param($types . 'ii', ...array_merge($params, [$limit, $offset]));
        $s->execute();
        $res = $s->get_result();

        $out = [];
        while ($r = $res->fetch_assoc()) {
            $out[] = ['id' => (int)$r['id'], 'date' => $r['mov_date'],
                      'partner' => $r['partner'], 'partner_id' => (int)$r['partner_id'],
                      'direction' => $r['direction'], 'kind' => $r['kind'],
                      'amount' => round((float)$r['amount'], 2),
                      'invest_adjust' => round((float)$r['invest_adjust'], 2),
                      'source' => $r['source'], 'note' => $r['note'],
                      'event_id' => $r['event_id'] !== null ? (int)$r['event_id'] : null,
                      'event_name' => $r['event_name'],
                      'account_id' => isset($r['account_id']) && $r['account_id'] !== null ? (int)$r['account_id'] : null,
                      'account_name' => $r['account_name'] ?? null,
                      'channel_id' => isset($r['channel_id']) && $r['channel_id'] !== null ? (int)$r['channel_id'] : null,
                      'channel_name' => $r['channel_name'] ?? null,
                      // The app greys out Edit on these: a settlement is a
                      // pair, and a credit with orders stamped on it has an
                      // amount that must match them.
                      'editable' => empty($r['transfer_id'])
                                    && !in_array($r['kind'], ['transfer', 'invest'], true)];
        }
        $s->close();

        // Totals for the whole filtered set, not the page — movements.php
        // shows "Credits shown / Debits shown / Net" and a header that
        // only counted the rows currently scrolled into view would be a
        // lie in exactly the place people are adding money up.
        $sumSql = 'SELECT
              COALESCE(SUM(CASE WHEN m.direction = "credit" THEN m.amount ELSE 0 END),0) credits,
              COALESCE(SUM(CASE WHEN m.direction = "debit"  THEN m.amount ELSE 0 END),0) debits,
              COUNT(*) n
            FROM account_movements m'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        if ($types !== '') {
            $s2 = $conn->prepare($sumSql);
            $s2->bind_param($types, ...$params);
        } else {
            $s2 = $conn->prepare($sumSql);
        }
        $s2->execute();
        $sum = $s2->get_result()->fetch_assoc();
        $s2->close();

        $credits = round((float)$sum['credits'], 2);
        $debits  = round((float)$sum['debits'], 2);

        api_ok([
            'movements' => $out,
            'has_more'  => count($out) === $limit,
            'totals'    => ['credits' => $credits, 'debits' => $debits,
                            'net' => round($credits - $debits, 2), 'count' => (int)$sum['n']],
        ]);
    },

    /**
     * POST update_movement {id, mov_date, partner_id, direction, amount,
     *                       source, note}
     *
     * Corrects a movement that was entered wrong. Deliberately refuses
     * the rows that are not free-standing, because editing one half of
     * them silently breaks the other:
     *
     *   a settlement (kind 'transfer' or 'invest') is a PAIR sharing a
     *   transfer_id, and changing one side's amount leaves the two no
     *   longer cancelling — one partner's net investment moves with
     *   nothing moving back. Delete it and record it again instead.
     *
     *   a credit raised from offline sales has orders stamped against it.
     *   Its amount is the sum of those orders, so editing it would make
     *   the credit disagree with the sales it represents.
     *
     * An event credit stays editable: credit_event caps the amount at the
     * event's own order revenue, and nothing is stamped, so a correction
     * there is just a correction.
     */
    'update_movement' => function () use ($conn) {
        $id = api_int('id');
        if ($id < 1) throw new ApiInputError('Invalid movement.');

        $g = $conn->prepare('SELECT kind, transfer_id FROM account_movements WHERE id = ?');
        $g->bind_param('i', $id);
        $g->execute();
        $cur = $g->get_result()->fetch_assoc();
        $g->close();
        if (!$cur) api_fail(404, 'not_found', 'That movement no longer exists.');

        if (!empty($cur['transfer_id']) || in_array($cur['kind'], ['transfer', 'invest'], true)) {
            throw new ApiInputError(
                'This is one side of a settlement between partners. Editing one side would leave '
                . 'the other standing — delete it and record it again.'
            );
        }

        $stamped = $conn->prepare('SELECT COUNT(*) n FROM orders WHERE credited_mov_id = ?');
        $stamped->bind_param('i', $id);
        $stamped->execute();
        $n = (int)$stamped->get_result()->fetch_assoc()['n'];
        $stamped->close();
        if ($n > 0) {
            throw new ApiInputError(
                'This credit covers ' . $n . ' offline order' . ($n === 1 ? '' : 's')
                . ', so its amount has to match them. Delete it to release those orders, then '
                . 'credit them again.'
            );
        }

        $pid    = api_int('partner_id');
        $dir    = api_str('direction');
        $amount = api_float('amount', 0);
        $date   = api_date('mov_date', date('Y-m-d'));

        if ($pid < 1)                                   throw new ApiInputError('Choose a partner.');
        if (!in_array($dir, ['credit', 'debit'], true)) throw new ApiInputError('Choose credit or debit.');
        if ($amount <= 0)                               throw new ApiInputError('Enter an amount greater than zero.');

        $chk = $conn->prepare('SELECT name FROM partners WHERE id = ?');
        $chk->bind_param('i', $pid);
        $chk->execute();
        $partner = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$partner) throw new ApiInputError('That partner no longer exists.');

        // Same NOT NULL DEFAULT '' columns as add_movement: never NULL.
        $source = mb_substr(api_str('source', $dir === 'credit' ? 'Manual credit' : 'Manual debit'), 0, 120);
        $note   = mb_substr(api_str('note'), 0, 500);

        $accId  = valid_account_id($conn, api_in('account_id', null));
        $chanId = valid_channel_id($conn, api_in('channel_id', null));

        // 'kind' is only settable between the two plain kinds. The
        // settlement kinds were already refused above, and letting an
        // edit turn a profit share into a payout would silently move
        // revenue.
        $kind = api_str('kind', (string)$cur['kind']);
        if (!in_array($kind, ['normal', 'payout'], true)) $kind = (string)$cur['kind'];
        if ($kind === 'payout') {
            if ($dir !== 'credit') {
                throw new ApiInputError('A payout is money coming in, so it must be a credit.');
            }
            if ($chanId === null) {
                throw new ApiInputError('A payout needs a channel, so it can be matched '
                    . 'against that channel\'s orders.');
            }
        }

        $hasTrade = db_has_column($conn, 'account_movements', 'account_id');
        $s = $conn->prepare(
            $hasTrade
            ? 'UPDATE account_movements
                  SET mov_date = ?, partner_id = ?, account_id = ?, channel_id = ?,
                      direction = ?, kind = ?, amount = ?, source = ?, note = ?
                WHERE id = ?'
            : 'UPDATE account_movements
                  SET mov_date = ?, partner_id = ?, direction = ?, amount = ?, source = ?, note = ?
                WHERE id = ?'
        );
        if ($hasTrade) {
            $s->bind_param('siiissdssi', $date, $pid, $accId, $chanId, $dir, $kind,
                           $amount, $source, $note, $id);
        } else {
            $s->bind_param('sisdssi', $date, $pid, $dir, $amount, $source, $note, $id);
        }
        $s->execute();
        $s->close();

        api_ok(['message' => 'Movement updated — ' . money($amount) . ' ' . $dir
                             . ' for ' . $partner['name'] . '.']);
    },

    /**
     * POST delete_movement {id}
     *
     * Mirrors movements.php exactly, including the two things that are
     * easy to miss and expensive to get wrong:
     *
     *   a settlement is TWO rows sharing a transfer_id, and deleting one
     *   half would leave the other standing, silently moving one
     *   partner's net investment without the matching opposite;
     *
     *   a credit raised from offline sales stamped credited_mov_id onto
     *   those orders. Deleting it without clearing the stamp leaves the
     *   orders pointing at a movement that no longer exists, and they can
     *   never be credited again.
     */
    'delete_movement' => function () use ($conn) {
        $id = api_int('id');
        if ($id < 1) throw new ApiInputError('Invalid movement.');

        $g = $conn->prepare('SELECT transfer_id FROM account_movements WHERE id = ?');
        $g->bind_param('i', $id);
        $g->execute();
        $row = $g->get_result()->fetch_assoc();
        $g->close();
        if (!$row) api_fail(404, 'not_found', 'That movement no longer exists.');

        $tid = $row['transfer_id'] ?? null;

        $conn->begin_transaction();
        try {
            if (!empty($tid)) {
                $s = $conn->prepare('DELETE FROM account_movements WHERE transfer_id = ?');
                $s->bind_param('i', $tid);
                $s->execute();
                $s->close();
                $message = 'Settlement deleted — both sides.';
            } else {
                $u = $conn->prepare('UPDATE orders SET credited_mov_id = NULL WHERE credited_mov_id = ?');
                $u->bind_param('i', $id);
                $u->execute();
                $freed = $u->affected_rows;
                $u->close();

                $s = $conn->prepare('DELETE FROM account_movements WHERE id = ?');
                $s->bind_param('i', $id);
                $s->execute();
                $s->close();

                $message = $freed > 0
                    ? 'Movement deleted. ' . $freed . ' order' . ($freed === 1 ? '' : 's')
                      . ' can be credited again.'
                    : 'Movement deleted.';
            }
            $conn->commit();
        } catch (Exception $ex) {
            $conn->rollback();
            throw $ex;
        }

        api_ok(['message' => $message]);
    },

    // ── POST add_movement ──────────────────────────────────────────
    'add_movement' => function () use ($conn) {
        $pid    = api_int('partner_id');
        $dir    = api_str('direction');
        $amount = api_float('amount', 0);
        $date   = api_date('mov_date', date('Y-m-d'));

        if ($pid < 1)                                 throw new ApiInputError('Choose a partner.');
        if (!in_array($dir, ['credit', 'debit'], true)) throw new ApiInputError('Choose credit or debit.');
        if ($amount <= 0)                             throw new ApiInputError('Enter an amount greater than zero.');

        $chk = $conn->prepare('SELECT name FROM partners WHERE id = ?');
        $chk->bind_param('i', $pid);
        $chk->execute();
        $p = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$p) throw new ApiInputError('That partner no longer exists.');

        $accId  = valid_account_id($conn, api_in('account_id', null));
        $chanId = valid_channel_id($conn, api_in('channel_id', null));

        $kind   = api_str('kind', 'normal');
        if (!in_array($kind, ['normal', 'profit', 'payout'], true)) $kind = 'normal';

        // A payout is money arriving from a marketplace for orders that
        // are already on the books, so it moves an account balance
        // without being counted as revenue a second time. It has to be a
        // credit, and it has to name the channel it settles, or there is
        // nothing to match it against.
        if ($kind === 'payout') {
            if ($dir !== 'credit') {
                throw new ApiInputError('A payout is money coming in, so it must be a credit.');
            }
            if ($chanId === null) {
                throw new ApiInputError('A payout needs a channel, so it can be matched '
                    . 'against that channel\'s orders.');
            }
        }
        $source = mb_substr(api_str('source', $dir === 'credit' ? 'Manual credit' : 'Manual debit'), 0, 120);
        // source/note are NOT NULL DEFAULT '' in account_movements, so an
        // empty note is stored as '', never NULL — strict mode rejects NULL.
        $note   = mb_substr(api_str('note'), 0, 500);

        $eventId = api_in('event_id', null);
        $eventId = ($eventId === null || $eventId === '' || (int)$eventId < 1) ? null : (int)$eventId;

        $hasTrade = db_has_column($conn, 'account_movements', 'account_id');
        $s = $conn->prepare(
            $hasTrade
            ? 'INSERT INTO account_movements
                 (mov_date, partner_id, account_id, channel_id, event_id, direction, kind, amount, source, note)
               VALUES (?,?,?,?,?,?,?,?,?,?)'
            : 'INSERT INTO account_movements (mov_date, partner_id, event_id, direction, kind, amount, source, note)
               VALUES (?,?,?,?,?,?,?,?)'
        );
        if ($hasTrade) {
            $s->bind_param('siiiissdss', $date, $pid, $accId, $chanId, $eventId, $dir, $kind,
                           $amount, $source, $note);
        } else {
            $s->bind_param('siissdss', $date, $pid, $eventId, $dir, $kind, $amount, $source, $note);
        }
        $s->execute();
        $s->close();

        api_ok(['message' => $kind === 'payout'
            ? money($amount) . ' payout recorded. It moves the account balance without '
              . 'being counted as revenue again — the orders it pays for already are.'
            : money($amount) . ' ' . $dir . 'ed ' . ($dir === 'credit' ? 'to ' : 'from ') . $p['name'] . '.']);
    },

    // ── POST credit_offline {partner_id, from, to} ─────────────────
    // Mirrors save.php's credit_offline, stamping credited_mov_id so the
    // same sales can never be credited twice.
    'credit_offline' => function () use ($conn) {
        $pid  = api_int('partner_id');
        $from = api_date('from');
        $to   = api_date('to');
        if ($pid < 1)                    throw new ApiInputError('Choose a partner to credit.');
        if ($from === '' || $to === '')  throw new ApiInputError('Pick a valid from and to date.');
        if ($to < $from)                 throw new ApiInputError('The end date is before the start date.');

        $chk = $conn->prepare('SELECT name FROM partners WHERE id = ?');
        $chk->bind_param('i', $pid);
        $chk->execute();
        $p = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$p) throw new ApiInputError('That partner no longer exists.');

        $sel = $conn->prepare(
            'SELECT COALESCE(SUM(total),0) amt, COUNT(*) n FROM orders
              WHERE event_id IS NULL AND credited_mov_id IS NULL
                AND DATE(created_at) >= ? AND DATE(created_at) <= ?'
        );
        $sel->bind_param('ss', $from, $to);
        $sel->execute();
        $r = $sel->get_result()->fetch_assoc();
        $sel->close();

        $amount = round((float)$r['amt'], 2);
        $count  = (int)$r['n'];
        if ($count === 0 || $amount <= 0.001) {
            throw new ApiInputError('No uncredited offline orders in that date range.');
        }

        $today  = date('Y-m-d');
        $source = 'Offline sales ' . $from . ' to ' . $to;
        $note   = $count . ' order(s), full payable.';

        $conn->begin_transaction();
        try {
            $ins = $conn->prepare(
                "INSERT INTO account_movements (mov_date, partner_id, direction, kind, amount, source, note)
                 VALUES (?,?,'credit','normal',?,?,?)"
            );
            $ins->bind_param('sidss', $today, $pid, $amount, $source, $note);
            $ins->execute();
            $movId = (int)$conn->insert_id;
            $ins->close();

            $upd = $conn->prepare(
                'UPDATE orders SET credited_mov_id = ?
                  WHERE event_id IS NULL AND credited_mov_id IS NULL
                    AND DATE(created_at) >= ? AND DATE(created_at) <= ?'
            );
            $upd->bind_param('iss', $movId, $from, $to);
            $upd->execute();
            $upd->close();
            $conn->commit();
        } catch (Exception $ex) { $conn->rollback(); throw $ex; }

        api_ok(['message' => 'Credited ' . money($amount) . ' from ' . $count . ' offline order(s) to ' . $p['name'] . '.']);
    },

    // ── POST credit_event {event_id, partner_id} ───────────────────
    // Credits the event's payable NET of anything already credited from
    // it, so crediting twice adds nothing rather than doubling the revenue.
    'credit_event' => function () use ($conn) {
        $eid = api_int('event_id');
        $pid = api_int('partner_id');
        if ($eid < 1) throw new ApiInputError('Invalid event.');
        if ($pid < 1) throw new ApiInputError('Choose a partner to credit.');

        $nm = $conn->prepare('SELECT e.name en, p.name pn FROM events e, partners p WHERE e.id = ? AND p.id = ?');
        $nm->bind_param('ii', $eid, $pid);
        $nm->execute();
        $names = $nm->get_result()->fetch_assoc();
        $nm->close();
        if (!$names) throw new ApiInputError('Event or partner not found.');

        $rev = $conn->prepare('SELECT COALESCE(SUM(total),0) t FROM orders WHERE event_id = ?');
        $rev->bind_param('i', $eid);
        $rev->execute();
        $revenue = (float)$rev->get_result()->fetch_assoc()['t'];
        $rev->close();

        $don = $conn->prepare("SELECT COALESCE(SUM(amount),0) t FROM account_movements
                                WHERE event_id = ? AND direction = 'credit'");
        $don->bind_param('i', $eid);
        $don->execute();
        $already = (float)$don->get_result()->fetch_assoc()['t'];
        $don->close();

        $net = round($revenue - $already, 2);
        if ($net <= 0.001) throw new ApiInputError('Nothing left to credit for this event.');

        $today  = date('Y-m-d');
        $source = 'Event sales: ' . $names['en'];
        $note   = 'Auto-credited full payable for event.';

        $s = $conn->prepare(
            "INSERT INTO account_movements (mov_date, partner_id, event_id, direction, amount, source, note)
             VALUES (?,?,?,'credit',?,?,?)"
        );
        $s->bind_param('siidss', $today, $pid, $eid, $net, $source, $note);
        $s->execute();
        $s->close();

        api_ok(['message' => 'Credited ' . money($net) . ' to ' . $names['pn'] . ' from ' . $names['en'] . '.']);
    },

    /**
     * POST settle {from_partner_id, to_partner_id, amount, mov_date, note}
     *
     * An INVESTMENT settle-up: an underpaid partner reimburses an overpaid
     * one. Written exactly the way movements.php writes it, because
     * investment.php reads both:
     *   - two rows, kind 'invest', grouped by transfer_id (the first row's
     *     own id, which is guaranteed unique without a second sequence);
     *   - amount 0 on both, so account BALANCES do not move — a settle-up
     *     changes who has contributed what, not how much money sits in an
     *     account;
     *   - invest_adjust +amount on the payer and -amount on the receiver,
     *     which is what actually closes the fairness gap. The pair sums to
     *     zero, so the group's total contribution is unchanged.
     */
    'settle' => function () use ($conn) {
        $fromId = api_int('from_partner_id');
        $toId   = api_int('to_partner_id');
        $amount = api_float('amount', 0);
        $date   = api_date('mov_date', date('Y-m-d'));
        $note   = mb_substr(api_str('note'), 0, 500);

        if ($fromId < 1 || $toId < 1) throw new ApiInputError('Choose both partners.');
        if ($fromId === $toId)        throw new ApiInputError('The two partners must be different.');
        if ($amount <= 0)             throw new ApiInputError('Enter an amount greater than zero.');

        $s = $conn->prepare('SELECT id, name FROM partners WHERE id IN (?,?)');
        $s->bind_param('ii', $fromId, $toId);
        $s->execute();
        $res = $s->get_result();
        $names = [];
        while ($r = $res->fetch_assoc()) $names[(int)$r['id']] = $r['name'];
        $s->close();
        if (count($names) !== 2) throw new ApiInputError('One of those partners no longer exists.');

        // Overshooting a settlement does not "even things out" — it flips
        // the payer into being overpaid and creates a fresh gap pointing
        // the other way. Refuse it and say what the right number is.
        [$rows] = partner_rows($conn);
        $gaps = [];
        foreach ($rows as $r) $gaps[$r['id']] = $r['gap'];
        $fromGap = $gaps[$fromId] ?? 0.0;
        $toGap   = $gaps[$toId] ?? 0.0;

        if ($fromGap > 0.01) {
            throw new ApiInputError($names[$fromId] . ' has already paid more than their share, so they receive a settlement rather than paying one.');
        }
        if ($toGap < -0.01) {
            throw new ApiInputError($names[$toId] . ' is also underpaid, so they are not owed a settlement.');
        }
        if ($amount > abs($fromGap) + 0.01) {
            throw new ApiInputError($names[$fromId] . ' only owes ' . money(abs($fromGap)) . '. Paying '
                . money($amount) . ' would flip them into being overpaid — reduce it to ' . money(abs($fromGap)) . ' or less.');
        }
        if ($amount > $toGap + 0.01) {
            throw new ApiInputError($names[$toId] . ' is only owed ' . money($toGap)
                . '. Reduce the amount to ' . money($toGap) . ' or less.');
        }

        $zero   = 0.0;
        $posAdj = $amount;
        $negAdj = -$amount;

        $conn->begin_transaction();
        try {
            // Payer's contribution rises. Stored as a credit-direction row
            // only so it lists sensibly; amount 0 keeps it out of balances.
            $srcOut = 'Investment settlement to ' . $names[$toId];
            $s = $conn->prepare(
                "INSERT INTO account_movements (mov_date, partner_id, direction, kind, counterparty_id, amount, invest_adjust, source, note)
                 VALUES (?,?,'credit','invest',?,?,?,?,?)"
            );
            $s->bind_param('siiddss', $date, $fromId, $toId, $zero, $posAdj, $srcOut, $note);
            $s->execute();
            $tid = (int)$conn->insert_id;
            $s->close();

            // Receiver's contribution falls by the same amount.
            $srcIn = 'Investment settlement from ' . $names[$fromId];
            $s = $conn->prepare(
                "INSERT INTO account_movements (mov_date, partner_id, direction, kind, counterparty_id, transfer_id, amount, invest_adjust, source, note)
                 VALUES (?,?,'debit','invest',?,?,?,?,?,?)"
            );
            $s->bind_param('siiiddss', $date, $toId, $fromId, $tid, $zero, $negAdj, $srcIn, $note);
            $s->execute();
            $s->close();

            // Tag the first row with the same transfer_id so the pair reads
            // as one movement everywhere it is listed.
            $u = $conn->prepare('UPDATE account_movements SET transfer_id = ? WHERE id = ?');
            $u->bind_param('ii', $tid, $tid);
            $u->execute();
            $u->close();
            $conn->commit();
        } catch (Exception $ex) { $conn->rollback(); throw $ex; }

        api_ok(['message' => 'Investment settlement: ' . money($amount) . ' from ' . $names[$fromId]
                           . ' to ' . $names[$toId] . '. Account balances are unchanged.']);
    },

    /**
     * POST distribute_profit {amount}
     * Equal split across every active partner, remainder to the last so
     * the credited total is exactly the amount asked for — the same rule
     * investment.php applies.
     */
    'distribute_profit' => function () use ($conn) {
        $bp     = business_profit_api($conn);
        $amount = api_float('amount', 0);
        if ($amount <= 0) throw new ApiInputError('Enter an amount to distribute.');
        if ($amount - $bp['remaining'] > 0.01) {
            throw new ApiInputError('That is more than the undistributed profit (' . money($bp['remaining']) . ').');
        }

        $elig = $conn->query('SELECT id, name FROM partners WHERE is_active = 1 ORDER BY name')
                     ->fetch_all(MYSQLI_ASSOC);
        $n = count($elig);
        if ($n === 0) throw new ApiInputError('No active partners to distribute to.');

        $each     = floor($amount / $n * 100) / 100;
        $credited = 0.0;
        $today    = date('Y-m-d');

        $conn->begin_transaction();
        try {
            $s = $conn->prepare(
                "INSERT INTO account_movements (mov_date, partner_id, direction, kind, amount, source, note)
                 VALUES (?,?,'credit','profit',?,?,?)"
            );
            foreach ($elig as $i => $p) {
                $share = ($i === $n - 1) ? round($amount - $credited, 2) : $each;
                $credited += $share;
                $pid    = (int)$p['id'];
                $source = 'Profit distribution';
                $note   = 'Equal ' . $n . '-way split';
                $s->bind_param('sidss', $today, $pid, $share, $source, $note);
                $s->execute();
            }
            $s->close();
            $conn->commit();
        } catch (Exception $ex) { $conn->rollback(); throw $ex; }

        api_ok(['message' => 'Distributed ' . money($amount) . ' equally to ' . $n . ' partner(s).']);
    },
]);