<?php
/**
 * ============================================================================
 * FILE: tip-handler.php
 * PATH: /wp-content/themes/astra/xrpl-nft-marketplace/backend/
 * ============================================================================
 *
 * IMCollectibles - Artist Tips (Phase 4).
 *
 * ACTIONS:
 *   GET  ?action=get_tip_currencies&artist=rX[&sender=rY]
 *        -> XRP (always) + the (currency,issuer) pairs the ARTIST can receive
 *           AND the SENDER holds with balance > 0. Direct wallet reads, not a
 *           curated list. Registry (Token Manager) is enrichment only.
 *   POST action=create_tip (+ nonce) artist, amount, currency[, issuer]
 *        -> builds a Payment to the artist, creates an Xaman payload,
 *           records a 'pending' row in wp_imc_tips, returns uuid/qr/deeplink.
 *   GET  ?action=tip_status&uuid=...
 *        -> polls the Xaman payload; on resolution captures the on-ledger txid
 *           and moves the wp_imc_tips row to signed/rejected.
 *
 * AUTH (mirrors the rest of the backend): CORS allow-list, wp nonce
 * 'xrpl_marketplace_nonce' on the POST, sender = wallet cookie 'xrpl_account'.
 * No Check logic (XRP + matched tokens only). Destination is validated to be a
 * known artist so tips can only flow to real recipients.
 *
 * @version 1.0.0 (Phase 4)
 */

require_once dirname(__DIR__, 5) . '/wp-load.php';
if (!defined('ABSPATH')) { exit; }

header('Content-Type: application/json; charset=utf-8');
$tip_allowed_origins = [
    'https://imcollectibles.xyz',
    'https://www.imcollectibles.xyz',
    'https://imcollectibles.io',
    'https://www.imcollectibles.io',
    'https://improtectors.com',
    'https://www.improtectors.com',
];
$tip_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . (in_array($tip_origin, $tip_allowed_origins, true) ? $tip_origin : $tip_allowed_origins[0]));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ----------------------------------------------------------------------------
// Local JSON helpers (scoped names to avoid clashing with other handlers)
// ----------------------------------------------------------------------------
function tip_error($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function tip_success(array $data) {
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}
function tip_valid_acct($a) {
    return is_string($a) && preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $a);
}

/**
 * XRPL JSON-RPC with ordered public-node failover (we run no node of our own).
 * Returns the `result` object on first success, or null if every node fails.
 */
function tip_xrpl_rpc($method, array $params) {
    static $nodes = [
        'https://xrplcluster.com',
        'https://s1.ripple.com:51234',
        'https://s2.ripple.com:51234',
    ];
    // Allow a site-configured node to take precedence if one is ever set.
    $primary = defined('IMU_XRPL_RPC') ? IMU_XRPL_RPC : (defined('XRP_RPC_URL') ? XRP_RPC_URL : '');
    $list = $primary ? array_merge([$primary], $nodes) : $nodes;
    $body = json_encode(['method' => $method, 'params' => [$params]]);
    foreach ($list as $url) {
        $res = wp_remote_post(rtrim($url, '/'), [
            'body'    => $body,
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 10,
        ]);
        if (is_wp_error($res)) { continue; }
        if ((int) wp_remote_retrieve_response_code($res) !== 200) { continue; }
        $j = json_decode(wp_remote_retrieve_body($res), true);
        if (is_array($j) && isset($j['result']) && (($j['result']['status'] ?? '') !== 'error')) {
            return $j['result'];
        }
    }
    return null;
}

/** Ticker -> on-ledger currency code (<=3 chars ASCII as-is; longer = 40-char hex). */
function tip_ticker_to_code($ticker) {
    $t = strtoupper(trim((string) $ticker));
    if ($t === '' || $t === 'XRP') { return $t; }
    if (strlen($t) <= 3) { return $t; }
    return str_pad(strtoupper(bin2hex($t)), 40, '0');
}

/** On-ledger currency code -> human display (decode 40-char hex; else as-is). */
function tip_code_to_display($code) {
    $c = strtoupper((string) $code);
    if (strlen($c) === 40 && ctype_xdigit($c)) {
        $ascii = preg_replace('/[^\x20-\x7E]/', '', rtrim(hex2bin($c), "\0"));
        return $ascii !== '' ? $ascii : $c;
    }
    return $c;
}

/** Format an IOU value string (up to 15 sig figs, no sci-notation/trailing zeros). */
function tip_fmt_value($amount) {
    $s = rtrim(rtrim(sprintf('%.15F', (float) $amount), '0'), '.');
    return $s === '' ? '0' : $s;
}

/** Recipient must be a real artist (has a profile or has issued listings). */
function tip_is_known_artist($account) {
    global $wpdb;
    $prof = $wpdb->prefix . 'imc_artist_profiles';
    $list = $wpdb->prefix . 'imc_listings';
    if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $prof)) === $prof) {
        if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prof} WHERE artist_account = %s", $account)) > 0) {
            return true;
        }
    }
    if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $list)) === $list) {
        if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$list} WHERE artist_account = %s", $account)) > 0) {
            return true;
        }
    }
    return false;
}

/** Read an account's trustlines (paginated). Returns ['CUR|ISSUER' => line]. */
function tip_read_lines($account) {
    $out = [];
    $marker = null;
    $guard = 0;
    do {
        $params = ['account' => $account, 'ledger_index' => 'validated', 'limit' => 200];
        if ($marker) { $params['marker'] = $marker; }
        $r = tip_xrpl_rpc('account_lines', $params);
        if (!$r) { break; }
        foreach (($r['lines'] ?? []) as $ln) {
            $cur = strtoupper($ln['currency'] ?? '');
            $iss = $ln['account'] ?? '';
            if ($cur === '' || $iss === '') { continue; }
            $out[$cur . '|' . $iss] = $ln;
        }
        $marker = $r['marker'] ?? null;
        $guard++;
    } while ($marker && $guard < 12);
    return $out;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ============================================================================
// get_tip_currencies — direct-wallet match (artist receivable ∩ sender held)
// ============================================================================
if ($action === 'get_tip_currencies') {
    $artist = sanitize_text_field($_GET['artist'] ?? $_POST['artist'] ?? '');
    $sender = sanitize_text_field($_GET['sender'] ?? $_POST['sender'] ?? '');
    if (!tip_valid_acct($artist)) { tip_error('Invalid artist account'); }

    // Artist receivable set (short cache; trustlines rarely change).
    $ck = 'imc_tip_recv_' . md5($artist);
    $receivable = get_transient($ck);
    if ($receivable === false) {
        $receivable = [];
        foreach (tip_read_lines($artist) as $key => $ln) {
            if ((float) ($ln['limit'] ?? 0) <= 0) { continue; }   // no room / no trust
            if (!empty($ln['freeze'])) { continue; }              // frozen line can't receive
            $receivable[$key] = true;
        }
        set_transient($ck, $receivable, 60);
    }

    // XRP is always available (no trustline). available filled in if sender known.
    $currencies = [[
        'ticker' => 'XRP', 'currency' => 'XRP', 'issuer' => '',
        'name' => 'XRP', 'icon' => 'XRP', 'decimals' => 6,
        'available' => null, 'verified' => true,
    ]];

    if (tip_valid_acct($sender)) {
        // Spendable XRP = balance - reserve (base + owner reserve, fetched live).
        $ai = tip_xrpl_rpc('account_info', ['account' => $sender, 'ledger_index' => 'validated']);
        if ($ai && isset($ai['account_data']['Balance'])) {
            $bal     = (float) $ai['account_data']['Balance'] / 1000000.0;
            $owner   = (int) ($ai['account_data']['OwnerCount'] ?? 0);
            $reserve = 1.0 + (0.2 * $owner); // current mainnet reserves (approx)
            $currencies[0]['available'] = max(0, round($bal - $reserve, 6));
        }

        // Registry, keyed by on-ledger (code|issuer) for enrichment only.
        $reg_map = [];
        if (function_exists('imc_get_supported_tokens')) {
            foreach (imc_get_supported_tokens('all') as $t) {
                if (($t['ticker'] ?? '') === 'XRP') { continue; }
                $code = tip_ticker_to_code($t['ticker'] ?? '');
                if ($code === '' || empty($t['issuer'])) { continue; }
                $reg_map[$code . '|' . $t['issuer']] = $t;
            }
        }

        // Sender holdings (balance > 0) intersected with artist receivable.
        foreach (tip_read_lines($sender) as $key => $ln) {
            $bal = (float) ($ln['balance'] ?? 0);
            if ($bal <= 0) { continue; }            // must hold a positive balance to send
            if (!isset($receivable[$key])) { continue; } // artist cannot receive this
            list($code, $issuer) = array_pad(explode('|', $key, 2), 2, '');
            $meta = $reg_map[$key] ?? null;
            $currencies[] = [
                'ticker'    => $meta['ticker'] ?? tip_code_to_display($code),
                'currency'  => $code,
                'issuer'    => $issuer,
                'name'      => $meta['display_name'] ?? tip_code_to_display($code),
                'icon'      => $meta['icon_emoji'] ?? '',
                'decimals'  => (int) ($meta['decimals'] ?? (strlen($code) === 40 ? 15 : 6)),
                'available' => round($bal, 6),
                'verified'  => $meta ? true : false,
            ];
        }
    }

    tip_success(['artist' => $artist, 'currencies' => $currencies]);
}

// ============================================================================
// create_tip — build Payment, create Xaman payload, record pending tip
// ============================================================================
if ($action === 'create_tip' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $nonce = $_POST['nonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) { tip_error('Security check failed', 403); }

    $tip_auth = function_exists('imc_session_require_wallet')
        ? imc_session_require_wallet('')
        : array('ok' => false, 'wallet' => '');
    $sender   = !empty($tip_auth['ok']) ? $tip_auth['wallet'] : '';
    $artist   = sanitize_text_field($_POST['artist'] ?? '');
    $amount   = (float) ($_POST['amount'] ?? 0);
    $currency = strtoupper(sanitize_text_field($_POST['currency'] ?? 'XRP'));
    $issuer   = sanitize_text_field($_POST['issuer'] ?? '');

    if (!tip_valid_acct($sender)) { tip_error('Connect your wallet to tip', 401); }
    if (!tip_valid_acct($artist)) { tip_error('Invalid recipient'); }
    if ($sender === $artist)      { tip_error('You cannot tip yourself'); }
    if ($amount < 0.000001)       { tip_error('Enter a tip amount'); }
    if (!tip_is_known_artist($artist)) { tip_error('Recipient is not a known artist'); }

    if ($currency === 'XRP') {
        $amt = (string) ((int) round($amount * 1000000)); // drops
    } else {
        if (!tip_valid_acct($issuer)) { tip_error('Invalid token issuer'); }
        $amt = ['currency' => $currency, 'issuer' => $issuer, 'value' => tip_fmt_value($amount)];
    }

    $txjson = ['TransactionType' => 'Payment', 'Destination' => $artist, 'Amount' => $amt, 'SourceTag' => 2606240013];

    // --- Joey branch (v553, additive): return the Payment txjson for local signing.
    // joey_verify_tip verifies on-chain + records the tip. Xaman path below untouched.
    // v686: the SERVER decides the wallet. The client flag depends on an async boot
    // (bundle load -> 150ms poll -> reconnect round-trip); a Joey user who acted before it
    // finished silently received a XUMM payload for a wallet they don't use -- and the
    // account-binding guard, which only runs when the client flag is set, was skipped with
    // it. The xrpl_wallet_type cookie is set at login and available on the first request,
    // so it is authoritative. Xaman users are unaffected (their cookie never says joey).
    if (($_POST['wallet'] ?? '') === 'joey' || ($_COOKIE['xrpl_wallet_type'] ?? '') === 'joey') {
        $txjson['Account'] = $sender; // v556: Joey/WalletConnect signs the literal txjson -- sender Account is required
        tip_success(['wallet' => 'joey', 'txjson' => $txjson, 'artist' => $artist]);
    }

    $api_key    = defined('XUMM_API_KEY') ? XUMM_API_KEY : '';
    $api_secret = defined('XUMM_API_SECRET') ? XUMM_API_SECRET : '';
    if (!$api_key || !$api_secret) { tip_error('Signing service unavailable', 500); }

    $payload = [
        'txjson'      => $txjson,
        'custom_meta' => [
            'identifier'  => uniqid('imctip_', true),
            'instruction' => 'Open in Xaman to send your tip',
            'blob'        => ['type' => 'artist_tip', 'recipient' => $artist],
            'type'        => 'artist_tip',
        ],
        'options'     => [
            'expire'     => 180,
            'return_url' => ['web' => home_url('/?tip_sent=1'), 'app' => home_url('/?tip_sent=1')],
        ],
    ];

    $ch = curl_init('https://xumm.app/api/v1/platform/payload');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'X-API-Key: ' . $api_key,
            'X-API-Secret: ' . $api_secret,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($resp, true);

    if ($status !== 200 || empty($data['uuid']) || empty($data['refs']['qr_png'])) {
        tip_error('Could not create the signing request', 502);
    }

    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'imc_tips', [
        'payload_uuid'      => $data['uuid'],
        'sender_account'    => $sender,
        'recipient_account' => $artist,
        'amount'            => $amount,
        'currency'          => $currency,
        'issuer'            => ($currency === 'XRP') ? null : $issuer,
        'status'            => 'pending',
        'created_at'        => current_time('mysql', 1),
    ]);

    tip_success([
        'uuid'     => $data['uuid'],
        'qr'       => $data['refs']['qr_png'],
        'deeplink' => $data['next']['always'] ?? '#',
    ]);
}


// ============================================================================
// joey_verify_tip (v553) -- verify the signed tip Payment on-chain, record it.
// ============================================================================
if ($action === 'joey_verify_tip' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $nonce = $_POST['nonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) { tip_error('Security check failed', 403); }
    $tipv_auth = function_exists('imc_session_require_wallet')
        ? imc_session_require_wallet('')
        : array('ok' => false, 'wallet' => '');
    $sender  = !empty($tipv_auth['ok']) ? $tipv_auth['wallet'] : '';
    $artist  = sanitize_text_field($_POST['artist']  ?? '');
    $tx_hash = sanitize_text_field($_POST['tx_hash'] ?? '');
    if (!tip_valid_acct($sender)) { tip_error('Connect your wallet to tip', 401); }
    if (!tip_valid_acct($artist)) { tip_error('Invalid recipient'); }
    if (!preg_match('/^[A-Fa-f0-9]{64}$/', $tx_hash)) { tip_error('Invalid transaction hash'); }

    $r = null;
    for ($i = 0; $i < 6; $i++) { $r = tip_xrpl_rpc('tx', ['transaction' => $tx_hash, 'binary' => false]); if (!empty($r['validated'])) break; sleep(2); }
    if (empty($r) || empty($r['validated'])) { tip_error('Transaction not validated -- please retry'); }
    if (($r['meta']['TransactionResult'] ?? '') !== 'tesSUCCESS') { tip_error('Tip did not succeed on-chain'); }
    if (($r['TransactionType'] ?? '') !== 'Payment') { tip_error('Unexpected transaction type'); }
    if (($r['Account'] ?? '') !== $sender) { tip_error('Transaction account mismatch'); }
    if (($r['Destination'] ?? '') !== $artist) { tip_error('Tip destination mismatch'); }

    $delivered = $r['meta']['delivered_amount'] ?? ($r['Amount'] ?? null);
    $cur = is_array($delivered) ? strtoupper($delivered['currency'] ?? '') : 'XRP';
    $val = is_array($delivered) ? (float) ($delivered['value'] ?? 0) : ((float) $delivered / 1000000);
    $iss = is_array($delivered) ? ($delivered['issuer'] ?? null) : null;
    global $wpdb;
    // v554: idempotency -- a given on-chain tx is recorded once (replay-safe, keeps DB/logs aligned).
    if ($wpdb->get_var($wpdb->prepare("SELECT txid FROM {$wpdb->prefix}imc_tips WHERE txid = %s LIMIT 1", $tx_hash))) { tip_success(['recorded' => true, 'txid' => $tx_hash, 'duplicate' => true]); }
    $wpdb->insert($wpdb->prefix . 'imc_tips', [
        'payload_uuid'      => 'joey',
        'sender_account'    => $sender,
        'recipient_account' => $artist,
        'amount'            => $val,
        'currency'          => $cur,
        'issuer'            => $iss,
        'status'            => 'signed',
        'txid'              => $tx_hash,
        'resolved_at'       => current_time('mysql', 1),
        'created_at'        => current_time('mysql', 1),
    ]);
    tip_success(['recorded' => true, 'txid' => $tx_hash]);
}

// ============================================================================
// tip_status — poll the payload; capture txid; resolve the ledger row
// ============================================================================
if ($action === 'tip_status') {
    $uuid = sanitize_text_field($_GET['uuid'] ?? $_POST['uuid'] ?? '');
    if ($uuid === '') { tip_error('Missing payload id'); }

    // ========================================================================
    // AUTHORISATION. This endpoint calls the XUMM platform API with the
    // marketplace's own API key and secret. Without the checks below it will
    // resolve ANY payload uuid for ANY caller -- an open oracle running on our
    // credentials and our quota.
    //
    // Two checks, in order of strictness:
    //
    //   1. The uuid MUST already exist in wp_imc_tips. Payload uuids are v4,
    //      so this alone limits the endpoint to payloads this site created.
    //      Mandatory -- a caller cannot reach XUMM without it.
    //
    //   2. If the caller has a proven wallet session, it must be the sender
    //      recorded on that tip. Deliberately NOT mandatory: the payload
    //      expires in 180s and the poll runs while the sender is signing in
    //      Xaman, so a session that lapses mid-flow must not break a live
    //      tip. Check 1 already bounds the exposure to our own payloads.
    // ========================================================================
    global $wpdb;
    $ts_row = $wpdb->get_row($wpdb->prepare(
        "SELECT sender_account FROM {$wpdb->prefix}imc_tips WHERE payload_uuid = %s",
        $uuid
    ), ARRAY_A);

    if (!$ts_row) {
        tip_error('Unknown payload', 404);
    }

    $ts_auth = function_exists('imc_session_require_wallet')
        ? imc_session_require_wallet('')
        : array('ok' => false, 'wallet' => '');
    $ts_wallet = !empty($ts_auth['ok']) ? (string) $ts_auth['wallet'] : '';

    if ($ts_wallet !== '' && $ts_wallet !== (string) $ts_row['sender_account']) {
        tip_error('This tip does not belong to your wallet', 403);
    }

    $api_key    = defined('XUMM_API_KEY') ? XUMM_API_KEY : '';
    $api_secret = defined('XUMM_API_SECRET') ? XUMM_API_SECRET : '';

    $ch = curl_init('https://xumm.app/api/v1/platform/payload/' . rawurlencode($uuid));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['X-API-Key: ' . $api_key, 'X-API-Secret: ' . $api_secret],
        CURLOPT_TIMEOUT        => 12,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);

    $resolved = (bool) ($data['meta']['resolved'] ?? false);
    $signed   = (bool) ($data['meta']['signed'] ?? false);
    $txid     = $data['response']['txid'] ?? null;

    if ($resolved) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'imc_tips',
            [
                'status'      => $signed ? 'signed' : 'rejected',
                'txid'        => $txid,
                'resolved_at' => current_time('mysql', 1),
            ],
            ['payload_uuid' => $uuid]
        );
    }

    tip_success(['resolved' => $resolved, 'signed' => $signed, 'txid' => $txid]);
}

tip_error('Invalid action', 404);