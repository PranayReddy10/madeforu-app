<?php
/**
 * WhatsApp messages: one at a time, or to a list uploaded as Excel/CSV.
 *
 * Two ways to send, both within WhatsApp's rules:
 *
 *   free       Each message opens in WhatsApp (Web, the desktop app or the
 *              phone) with the text already written; the person presses
 *              Send. Costs nothing, needs no setup, cannot get the number
 *              banned. One tap per contact.
 *
 *   automatic  The official WhatsApp Business Cloud API (Meta). Sends by
 *              itself, but only templates Meta has approved, and Meta
 *              charges per message. Set up on the WhatsApp page.
 *
 * Not offered: driving WhatsApp Web with a bot. It breaks WhatsApp's
 * terms, bulk sending that way is what gets numbers banned, and shared
 * hosting has no browser to drive anyway.
 */
declare(strict_types=1);

require_once __DIR__ . '/meesho_xlsx.php';

// ── Storage ────────────────────────────────────────────────────────

function wa_ensure_tables(mysqli $conn): void {
    static $done = false;
    if ($done) return;
    $conn->query(
        'CREATE TABLE IF NOT EXISTS wa_campaigns (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            mode VARCHAR(10) NOT NULL,
            message TEXT NOT NULL,
            template_name VARCHAR(120) NOT NULL DEFAULT \'\',
            template_lang VARCHAR(20) NOT NULL DEFAULT \'\',
            template_params VARCHAR(500) NOT NULL DEFAULT \'\',
            columns_json TEXT NOT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $conn->query(
        'CREATE TABLE IF NOT EXISTS wa_recipients (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            campaign_id INT UNSIGNED NOT NULL,
            phone VARCHAR(20) NOT NULL,
            name VARCHAR(120) NOT NULL DEFAULT \'\',
            vars_json TEXT NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT \'pending\',
            error VARCHAR(255) NOT NULL DEFAULT \'\',
            sent_at DATETIME NULL,
            KEY ix_wa_campaign (campaign_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $done = true;
}

function wa_setting(mysqli $conn, string $key, string $default = ''): string {
    $s = $conn->prepare('SELECT sval FROM app_settings WHERE skey = ?');
    $s->bind_param('s', $key);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    return $row && $row['sval'] !== null ? (string)$row['sval'] : $default;
}

function wa_setting_set(mysqli $conn, string $key, string $value): void {
    $s = $conn->prepare('DELETE FROM app_settings WHERE skey = ?');
    $s->bind_param('s', $key);
    $s->execute();
    $s->close();
    $s = $conn->prepare('INSERT INTO app_settings (skey, sval) VALUES (?, ?)');
    $s->bind_param('ss', $key, $value);
    $s->execute();
    $s->close();
}

// ── Numbers ────────────────────────────────────────────────────────

/**
 * A phone number as WhatsApp wants it: country code and digits, no plus.
 * "98765 43210", "+91-98765-43210", "098765 43210" and "919876543210"
 * all become 919876543210. Null when it cannot be a mobile number.
 */
function wa_phone(string $raw): ?string {
    $d = preg_replace('/\D/', '', $raw);
    if ($d === '') return null;
    $d = ltrim($d, '0');
    $cc = defined('COUNTRY_CODE') ? (string)COUNTRY_CODE : '91';
    if (strlen($d) === 10) $d = $cc . $d;
    if (strlen($d) < 11 || strlen($d) > 15) return null;
    // An Indian mobile starts 6-9 after the 91.
    if ($cc === '91' && strpos($d, '91') === 0 && strlen($d) === 12 && !preg_match('/^91[6-9]/', $d)) return null;
    return $d;
}

/** The numbers that asked not to be messaged, from the WhatsApp page. */
function wa_optouts(mysqli $conn): array {
    $out = [];
    // One number per line (or comma-separated). Not split on spaces: a
    // number is usually written with them, "+91 98765 43210".
    foreach (preg_split('/[\r\n,;]+/', wa_setting($conn, 'wa_optout')) as $raw) {
        $p = wa_phone($raw);
        if ($p !== null) $out[$p] = true;
    }
    return $out;
}

// ── The uploaded list ──────────────────────────────────────────────

/**
 * Rows out of an uploaded .xlsx or .csv: the first row is the column
 * names, every other row a contact. Returns [columns, rows], each row a
 * map of column name to value.
 */
function wa_read_list(string $path, string $filename): array {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($ext === 'xlsx') {
        $sheets = xlsx_sheet_names($path);
        if (!$sheets) throw new Exception('That Excel file has no sheets.');
        $grid = xlsx_read_sheet($path, $sheets[0]);
    } elseif ($ext === 'csv' || $ext === 'txt') {
        $grid = [];
        $fh = fopen($path, 'r');
        $first = fgets($fh);
        rewind($fh);
        // Excel in some regions saves CSV with semicolons.
        $sep = substr_count((string)$first, ';') > substr_count((string)$first, ',') ? ';' : ',';
        while (($r = fgetcsv($fh, 0, $sep)) !== false) $grid[] = $r;
        fclose($fh);
        if ($grid) $grid[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$grid[0][0]);   // Excel's BOM
    } elseif ($ext === 'xls') {
        throw new Exception('That is the old .xls format. In Excel, Save As → Excel Workbook (.xlsx) or CSV, then upload that.');
    } else {
        throw new Exception('Upload an Excel (.xlsx) or CSV file.');
    }

    $grid = array_values(array_filter($grid, fn($r) => implode('', array_map('trim', array_map('strval', $r))) !== ''));
    if (count($grid) < 2) throw new Exception('The file needs a header row (Phone, Name, …) and at least one contact under it.');

    $columns = [];
    foreach ($grid[0] as $i => $h) {
        $h = trim((string)$h);
        $columns[$i] = $h !== '' ? $h : 'Column' . ($i + 1);
    }
    $rows = [];
    foreach (array_slice($grid, 1) as $r) {
        $row = [];
        foreach ($columns as $i => $name) $row[$name] = trim((string)($r[$i] ?? ''));
        $rows[] = $row;
    }
    return [array_values($columns), $rows];
}

/** The column holding phone numbers: called Phone, Mobile, WhatsApp or Number. */
function wa_phone_column(array $columns): ?string {
    foreach ($columns as $c) {
        if (preg_match('/^(phone|mobile|whatsapp|number|contact|phone number|mobile number|ph|mob)\b/i', trim($c))) return $c;
    }
    return null;
}

function wa_name_column(array $columns): ?string {
    foreach ($columns as $c) {
        if (preg_match('/^(name|customer|customer name|full name)$/i', trim($c))) return $c;
    }
    return null;
}

/**
 * The message for one contact: every {Column} replaced by that contact's
 * value, matching the column name without regard to case or spaces.
 * {first_name} is the first word of the name.
 */
function wa_fill(string $template, array $vars): string {
    $norm = [];
    foreach ($vars as $k => $v) $norm[strtolower(preg_replace('/\s+/', '', (string)$k))] = (string)$v;
    if (isset($norm['name']) && !isset($norm['first_name'])) {
        $norm['first_name'] = explode(' ', trim($norm['name']))[0] ?? '';
    }
    return preg_replace_callback('/\{\s*([^{}]+?)\s*\}/', function ($m) use ($norm) {
        $k = strtolower(preg_replace('/\s+/', '', $m[1]));
        return array_key_exists($k, $norm) ? $norm[$k] : $m[0];
    }, $template);
}

// ── Opening a chat (free) ──────────────────────────────────────────

/**
 * A link that opens the chat with the message typed in. `web` is WhatsApp
 * Web in the browser, `app` the installed WhatsApp (desktop or phone).
 */
function wa_link(string $phone, string $message, string $target = 'web'): string {
    $q = 'phone=' . $phone . '&text=' . rawurlencode($message);
    if ($target === 'app') return 'whatsapp://send?' . $q;
    if ($target === 'phone') return 'https://wa.me/' . $phone . '?text=' . rawurlencode($message);
    return 'https://web.whatsapp.com/send?' . $q;
}

// ── Sending by itself (WhatsApp Business Cloud API) ────────────────

function wa_api_ready(mysqli $conn): bool {
    return wa_setting($conn, 'wa_api_token') !== '' && wa_setting($conn, 'wa_api_phone_id') !== '';
}

/**
 * Send one approved template message. $params are the values for {{1}},
 * {{2}}, … in the template's body, in order. Returns null when Meta
 * accepted it, or Meta's reason when it did not.
 */
function wa_api_send_template(mysqli $conn, string $to, string $template, string $lang, array $params): ?string {
    $body = [
        'messaging_product' => 'whatsapp',
        'to'                => $to,
        'type'              => 'template',
        'template'          => ['name' => $template, 'language' => ['code' => $lang !== '' ? $lang : 'en']],
    ];
    if ($params) {
        $body['template']['components'] = [[
            'type'       => 'body',
            'parameters' => array_map(fn($v) => ['type' => 'text', 'text' => $v !== '' ? (string)$v : '-'], array_values($params)),
        ]];
    }
    return wa_api_post($conn, $body);
}

function wa_api_post(mysqli $conn, array $body): ?string {
    // For testing without Meta: define WA_DRY_RUN as a file path (in
    // config.php) and each request is written there instead of sent.
    if (defined('WA_DRY_RUN')) {
        file_put_contents((string)WA_DRY_RUN, json_encode($body, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        return substr((string)$body['to'], -1) === '0' ? 'Recipient phone number not in allowed list (dry run)' : null;
    }
    $version = wa_setting($conn, 'wa_api_version', 'v22.0');
    $url = 'https://graph.facebook.com/' . rawurlencode($version) . '/'
         . rawurlencode(wa_setting($conn, 'wa_api_phone_id')) . '/messages';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . wa_setting($conn, 'wa_api_token'), 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return 'Could not reach WhatsApp (' . $err . ').';
    $reply = json_decode((string)$raw, true);
    if ($code === 200 && !empty($reply['messages'][0]['id'])) return null;
    $e = $reply['error'] ?? [];
    $msg = trim(($e['error_user_msg'] ?? '') !== '' ? $e['error_user_msg'] : ($e['message'] ?? 'HTTP ' . $code));
    if (!empty($e['error_data']['details'])) $msg .= ' — ' . $e['error_data']['details'];
    return mb_substr($msg, 0, 250);
}

/** The template's {{1}}, {{2}}, … values for one contact, from the campaign's column list. */
function wa_template_values(string $paramColumns, array $vars): array {
    $out = [];
    foreach (array_filter(array_map('trim', explode(',', $paramColumns)), 'strlen') as $col) {
        $out[] = wa_fill('{' . $col . '}', $vars) === '{' . $col . '}' ? '' : wa_fill('{' . $col . '}', $vars);
    }
    return $out;
}
