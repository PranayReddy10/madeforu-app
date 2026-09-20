<?php
/**
 * Accounts — the places money sits.
 *
 * Shared by accounts.php, movements.php and the API so there is one
 * definition rather than three. Include after config.php.
 *
 * Everything degrades to "no accounts" if the migration has not been run
 * yet, rather than taking a page down with an undefined table: the site
 * is uploaded a file at a time, so there is always a window where the
 * PHP is new and the database is not.
 */
declare(strict_types=1);

if (!function_exists('accounts_ready')) {
    /** Has the accounts migration been applied? Cached per request. */
    function accounts_ready(mysqli $conn): bool {
        static $ok = null;
        if ($ok !== null) return $ok;
        try { $conn->query('SELECT 1 FROM accounts LIMIT 1'); $ok = true; }
        catch (Throwable $e) { $ok = false; }
        return $ok;
    }
}

if (!function_exists('movements_have_account')) {
    /** Does account_movements have the account_id column yet? */
    function movements_have_account(mysqli $conn): bool {
        static $ok = null;
        if ($ok !== null) return $ok;
        try { $conn->query('SELECT account_id FROM account_movements LIMIT 1'); $ok = true; }
        catch (Throwable $e) { $ok = false; }
        return $ok;
    }
}

if (!function_exists('accounts_all')) {
    /**
     * The accounts, with the partner who holds one if any.
     *
     * Joined on partner_id rather than on a name, which keeps it clear of
     * the collation trouble name joins hit on this database (products is
     * utf8mb4_uca1400_ai_ci, most other tables are utf8mb4_unicode_ci).
     */
    function accounts_all(mysqli $conn, bool $activeOnly = true): array {
        if (!accounts_ready($conn)) return [];
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
    /** id => account, retired ones included, so history still has a label. */
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
     * Every kind counts, unlike revenue. A transfer between partners is
     * not revenue but it genuinely moves money out of one account and
     * into another, and a balance that ignored it would not match the
     * bank.
     *
     * Movements recorded before this existed are gathered under a null id
     * rather than dropped, so the total still reconciles and the backlog
     * is visible.
     */
    function account_balances(mysqli $conn): array {
        if (!accounts_ready($conn) || !movements_have_account($conn)) return [];
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
               FROM account_movements GROUP BY aid, direction"
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
        if ($rows[0]['movements'] === 0) unset($rows[0]);
        return array_values($rows);
    }
}
