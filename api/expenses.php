<?php
/**
 * Expenses and who paid for them.
 *
 * An expense has two halves that must stay consistent: what was spent
 * (expenses.amount − discount) and who put the money in
 * (expense_payments, one row per partner). The second half is what
 * investment.php reads as a partner's `paid`, so an expense recorded
 * without payment rows silently leaves the payer's investment unrecorded.
 * This endpoint therefore always writes at least one payment row — by
 * default the full amount against paid_by.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$me = api_require_auth($conn);

function expense_row(array $r): array {
    $amount = (float)$r['amount'];
    $disc   = (float)$r['discount'];
    return [
        'id'        => (int)$r['id'],
        'date'      => $r['exp_date'],
        'item'      => $r['item'],
        'amount'    => round($amount, 2),
        'discount'  => round($disc, 2),
        'net'       => round($amount - $disc, 2),
        'category'  => $r['category'],
        'paid_to'   => $r['paid_to'],
        'paid_by'   => $r['paid_by_name'] ?? null,
        'paid_by_id'=> (int)$r['paid_by'],
        'details'   => $r['details'],
        'settled'   => round((float)($r['settled'] ?? 0), 2),
        'has_receipt' => !empty($r['receipt_path']),
        'item_count'  => isset($r['item_count']) ? (int)$r['item_count'] : 0,
        'payer_count' => isset($r['payer_count']) ? (int)$r['payer_count'] : 0,
        'created_at'  => $r['created_at'],
    ];
}

/**
 * Read the "who paid from pocket" split. Omitted means the whole net
 * amount against paid_by, which is the common case. When given, it must
 * add up: expense_payments is what investment.php reads as a partner's
 * contribution, so a split that does not total the expense silently
 * distorts every partner's fair share.
 */
function expense_split(float $net, int $paidBy): array {
    $raw = api_in('payments', null);
    if (!is_array($raw) || !$raw) return [[$paidBy, $net]];

    $split = []; $sum = 0.0;
    foreach ($raw as $row) {
        if (!is_array($row)) continue;
        $pid = (int)($row['partner_id'] ?? 0);
        $amt = round((float)($row['amount'] ?? 0), 2);
        if ($pid < 1 || $amt <= 0) continue;
        $split[] = [$pid, $amt];
        $sum += $amt;
    }
    if (!$split) throw new ApiInputError('The payment split has no valid rows.');
    if (abs($sum - $net) > 0.01) {
        throw new ApiInputError('The split adds up to ' . money($sum) . ' but the expense is ' . money($net) . '.');
    }
    return $split;
}

api_dispatch([

    // ── GET list&from&to&category&partner_id ───────────────────────
    'list' => function () use ($conn) {
        $where = []; $types = ''; $params = [];

        $from = api_date('from', date('Y-m-01'));
        $to   = api_date('to',   date('Y-m-d'));
        $where[] = 'e.exp_date BETWEEN ? AND ?';
        $types  .= 'ss'; array_push($params, $from, $to);

        $cat = api_str('category');
        if ($cat !== '' && $cat !== 'all') { $where[] = 'e.category = ?'; $types .= 's'; $params[] = $cat; }

        $pid = api_int('partner_id');
        if ($pid > 0) { $where[] = 'e.paid_by = ?'; $types .= 'i'; $params[] = $pid; }

        $q = api_str('q');
        if ($q !== '') {
            $where[] = '(e.item LIKE ? OR e.paid_to LIKE ? OR e.details LIKE ?)';
            $like = '%' . $q . '%';
            $types .= 'sss'; array_push($params, $like, $like, $like);
        }

        $limit  = max(1, min(api_int('limit', 60), MAX_PAGE_SIZE));
        $offset = max(0, api_int('offset', 0));

        $sql = 'SELECT e.*, p.name paid_by_name,
                       (SELECT COALESCE(SUM(amount),0) FROM expense_payments WHERE expense_id = e.id) settled,
                       (SELECT COUNT(*) FROM expense_items WHERE expense_id = e.id) item_count,
                       (SELECT COUNT(*) FROM expense_payments WHERE expense_id = e.id) payer_count
                  FROM expenses e
                  LEFT JOIN partners p ON p.id = e.paid_by
                 WHERE ' . implode(' AND ', $where)
             . ' ORDER BY e.exp_date DESC, e.id DESC LIMIT ? OFFSET ?';

        $s = $conn->prepare($sql);
        $s->bind_param($types . 'ii', ...array_merge($params, [$limit, $offset]));
        $s->execute();
        $res = $s->get_result();

        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = expense_row($r);
        $s->close();

        // Totals for the filter, not the page — same reasoning as the
        // order list: a header that only counts visible rows misleads.
        $sumSql = 'SELECT COUNT(*) n, COALESCE(SUM(e.amount),0) gross,
                          COALESCE(SUM(e.discount),0) disc
                     FROM expenses e WHERE ' . implode(' AND ', $where);
        $s = $conn->prepare($sumSql);
        if ($types !== '') $s->bind_param($types, ...$params);
        $s->execute();
        $sum = $s->get_result()->fetch_assoc();
        $s->close();

        // Split by category for the donut on the expenses screen.
        $s = $conn->prepare(
            'SELECT e.category, COUNT(*) n, COALESCE(SUM(e.amount - e.discount),0) net
               FROM expenses e WHERE e.exp_date BETWEEN ? AND ?
              GROUP BY e.category ORDER BY net DESC'
        );
        $s->bind_param('ss', $from, $to);
        $s->execute();
        $res = $s->get_result();
        $byCat = [];
        while ($r = $res->fetch_assoc()) {
            $byCat[] = ['category' => $r['category'], 'count' => (int)$r['n'], 'net' => round((float)$r['net'], 2)];
        }
        $s->close();

        $gross = (float)$sum['gross']; $disc = (float)$sum['disc'];
        api_ok([
            'expenses' => $rows,
            'has_more' => count($rows) === $limit,
            'range'    => ['from' => $from, 'to' => $to],
            'summary'  => ['count' => (int)$sum['n'], 'gross' => round($gross, 2),
                           'discount' => round($disc, 2), 'net' => round($gross - $disc, 2)],
            'by_category' => $byCat,
        ]);
    },

    // ── GET get&id= — one expense with its line items and payers ───
    'get' => function () use ($conn) {
        $id = api_int('id');
        if ($id < 1) throw new ApiInputError('Invalid expense.');

        $s = $conn->prepare(
            'SELECT e.*, p.name paid_by_name,
                    (SELECT COALESCE(SUM(amount),0) FROM expense_payments WHERE expense_id = e.id) settled,
                    (SELECT COUNT(*) FROM expense_items WHERE expense_id = e.id) item_count,
                    (SELECT COUNT(*) FROM expense_payments WHERE expense_id = e.id) payer_count
               FROM expenses e LEFT JOIN partners p ON p.id = e.paid_by WHERE e.id = ?'
        );
        $s->bind_param('i', $id);
        $s->execute();
        $row = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$row) api_fail(404, 'not_found', 'That expense no longer exists.');

        $items = [];
        try {
            $s = $conn->prepare('SELECT descr, qty, unit_cost, line_total FROM expense_items WHERE expense_id = ? ORDER BY sort_order, id');
            $s->bind_param('i', $id);
            $s->execute();
            $res = $s->get_result();
            while ($r = $res->fetch_assoc()) {
                $items[] = ['descr' => $r['descr'], 'qty' => (float)$r['qty'],
                            'unit_cost' => (float)$r['unit_cost'], 'line_total' => (float)$r['line_total']];
            }
            $s->close();
        } catch (mysqli_sql_exception $e) { /* optional table */ }

        $payers = [];
        $s = $conn->prepare(
            'SELECT ep.id, ep.amount, ep.pay_date, ep.note, p.id pid, p.name
               FROM expense_payments ep JOIN partners p ON p.id = ep.partner_id
              WHERE ep.expense_id = ? ORDER BY ep.id'
        );
        $s->bind_param('i', $id);
        $s->execute();
        $res = $s->get_result();
        while ($r = $res->fetch_assoc()) {
            $payers[] = ['id' => (int)$r['id'], 'partner_id' => (int)$r['pid'], 'partner' => $r['name'],
                         'amount' => round((float)$r['amount'], 2), 'pay_date' => $r['pay_date'], 'note' => $r['note']];
        }
        $s->close();

        api_ok(['expense' => expense_row($row) + ['items' => $items, 'payers' => $payers]]);
    },

    /**
     * POST create
     * {exp_date, item, amount, discount, category, paid_to, details,
     *  paid_by, payments:[{partner_id, amount}]}
     *
     * `payments` is optional: leaving it out records the whole net amount
     * against paid_by, which is the common case (one partner buys the
     * vinyl roll). Passing it splits the cost between partners.
     */
    'create' => function () use ($conn) {
        $date   = api_date('exp_date', date('Y-m-d'));
        $item   = api_str('item');
        $amount = api_float('amount', 0);
        $disc   = api_float('discount', 0);
        $paidBy = api_int('paid_by');

        if ($item === '')        throw new ApiInputError('What was the money spent on?');
        if ($amount <= 0)        throw new ApiInputError('Enter an amount greater than zero.');
        if ($disc < 0)           throw new ApiInputError('Discount cannot be negative.');
        if ($disc > $amount)     throw new ApiInputError('The discount is more than the amount.');
        if ($paidBy < 1)         throw new ApiInputError('Choose who paid.');

        $chk = $conn->prepare('SELECT name FROM partners WHERE id = ?');
        $chk->bind_param('i', $paidBy);
        $chk->execute();
        $p = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$p) throw new ApiInputError('That partner no longer exists.');

        // These columns are NOT NULL DEFAULT '' — bind '' rather than NULL.
        $category = mb_substr(api_str('category', 'Other') ?: 'Other', 0, 80);
        $paidTo   = mb_substr(api_str('paid_to'), 0, 200);
        $details  = mb_substr(api_str('details'), 0, 500);
        $item     = mb_substr($item, 0, 200);
        $net      = round($amount - $disc, 2);

        // Worked out before writing anything, so a bad split cannot leave
        // an expense behind with nobody recorded as paying it.
        $split = expense_split($net, $paidBy);

        $conn->begin_transaction();
        try {
            $s = $conn->prepare(
                'INSERT INTO expenses (exp_date, item, amount, discount, paid_by, paid_to, category, details)
                 VALUES (?,?,?,?,?,?,?,?)'
            );
            $s->bind_param('ssddisss', $date, $item, $amount, $disc, $paidBy, $paidTo, $category, $details);
            $s->execute();
            $eid = (int)$conn->insert_id;
            $s->close();

            $s = $conn->prepare(
                'INSERT INTO expense_payments (expense_id, partner_id, amount, pay_date) VALUES (?,?,?,?)'
            );
            foreach ($split as [$pid, $amt]) {
                $s->bind_param('iids', $eid, $pid, $amt, $date);
                $s->execute();
            }
            $s->close();
            $conn->commit();
        } catch (Exception $ex) { $conn->rollback(); throw $ex; }

        api_ok(['expense_id' => $eid, 'message' => money($net) . ' expense recorded.']);
    },

    /**
     * POST update — correct an expense that was entered wrong.
     *
     * The payment split is rewritten wholesale rather than patched: a
     * partial edit could leave expense_payments summing to something other
     * than the expense, and that table is what investment.php reads as a
     * partner's contribution.
     */
    'update' => function () use ($conn) {
        $id = api_int('id');
        if ($id < 1) throw new ApiInputError('Invalid expense.');

        $chk = $conn->prepare('SELECT id FROM expenses WHERE id = ?');
        $chk->bind_param('i', $id);
        $chk->execute();
        if (!$chk->get_result()->fetch_assoc()) api_fail(404, 'not_found', 'That expense no longer exists.');
        $chk->close();

        $date   = api_date('exp_date', date('Y-m-d'));
        $item   = mb_substr(api_str('item'), 0, 200);
        $amount = api_float('amount', 0);
        $disc   = api_float('discount', 0);
        $paidBy = api_int('paid_by');

        if ($item === '')    throw new ApiInputError('What was the money spent on?');
        if ($amount <= 0)    throw new ApiInputError('Enter an amount greater than zero.');
        if ($disc < 0)       throw new ApiInputError('Discount cannot be negative.');
        if ($disc > $amount) throw new ApiInputError('The discount is more than the amount.');
        if ($paidBy < 1)     throw new ApiInputError('Choose who paid.');

        $category = mb_substr(api_str('category', 'Other') ?: 'Other', 0, 80);
        $paidTo   = mb_substr(api_str('paid_to'), 0, 200);
        $details  = mb_substr(api_str('details'), 0, 500);
        $net      = round($amount - $disc, 2);

        $split = expense_split($net, $paidBy);

        $conn->begin_transaction();
        try {
            $s = $conn->prepare(
                'UPDATE expenses SET exp_date = ?, item = ?, amount = ?, discount = ?,
                                     paid_by = ?, paid_to = ?, category = ?, details = ?
                  WHERE id = ?'
            );
            $s->bind_param('ssddisssi', $date, $item, $amount, $disc, $paidBy, $paidTo, $category, $details, $id);
            $s->execute();
            $s->close();

            $s = $conn->prepare('DELETE FROM expense_payments WHERE expense_id = ?');
            $s->bind_param('i', $id);
            $s->execute();
            $s->close();

            $s = $conn->prepare(
                'INSERT INTO expense_payments (expense_id, partner_id, amount, pay_date) VALUES (?,?,?,?)'
            );
            foreach ($split as [$pid, $amt]) {
                $s->bind_param('iids', $id, $pid, $amt, $date);
                $s->execute();
            }
            $s->close();
            $conn->commit();
        } catch (Exception $ex) { $conn->rollback(); throw $ex; }

        api_ok(['message' => 'Expense updated.']);
    },

    // ── POST delete ────────────────────────────────────────────────
    'delete' => function () use ($conn) {
        $id = api_int('id');
        if ($id < 1) throw new ApiInputError('Invalid expense.');

        $conn->begin_transaction();
        try {
            // expense_payments has no FK cascade in the live schema, so
            // clear the children first or they are orphaned and keep
            // counting towards a partner's investment forever.
            $s = $conn->prepare('DELETE FROM expense_payments WHERE expense_id = ?');
            $s->bind_param('i', $id);
            $s->execute();
            $s->close();

            $s = $conn->prepare('DELETE FROM expense_items WHERE expense_id = ?');
            $s->bind_param('i', $id);
            $s->execute();
            $s->close();

            $s = $conn->prepare('DELETE FROM expenses WHERE id = ?');
            $s->bind_param('i', $id);
            $s->execute();
            $gone = $s->affected_rows;
            $s->close();
            if ($gone < 1) { $conn->rollback(); api_fail(404, 'not_found', 'That expense was already removed.'); }
            $conn->commit();
        } catch (mysqli_sql_exception $ex) {
            $conn->rollback();
            // expense_items may not exist on older installs; retry without it.
            $s = $conn->prepare('DELETE FROM expenses WHERE id = ?');
            $s->bind_param('i', $id);
            $s->execute();
            $s->close();
        }

        api_ok(['message' => 'Expense deleted.']);
    },

    'categories' => function () use ($conn) {
        $out = [];
        try {
            $res = $conn->query('SELECT id, name FROM expense_categories ORDER BY sort_order, name');
            while ($res && ($r = $res->fetch_assoc())) $out[] = ['id' => (int)$r['id'], 'name' => $r['name']];
        } catch (mysqli_sql_exception $e) { /* optional table */ }
        api_ok(['categories' => $out]);
    },
]);
