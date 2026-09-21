<?php
/**
 * File: watchlist-handler.php (Production Ready)
 * Path: /wp-content/themes/astra/xrpl-nft-marketplace/backend/watchlist-handler.php
 *
 * Purpose:
 * - Manage per-account NFT watchlists.
 * - Lightweight metadata enrichment (via global get_nft_metadata if available).
 *
 * Endpoints:
 *   GET  ?action=get&nonce=...                            → Return list (session wallet)
 *   POST action=add   (account, nft_id, nonce)             → Add NFT to watchlist
 *   POST action=remove(account, nft_id, nonce)             → Remove NFT from watchlist
 */

require_once dirname(__DIR__, 5) . '/wp-load.php';
if (!defined('ABSPATH')) exit;

header('Content-Type: application/json');

global $wpdb;

// --- Tables ---
$watchlist_table = $wpdb->prefix . 'xaman_watchlist';
$profile_table   = $wpdb->prefix . 'xaman_profiles'; // optional, for notifications

// --- Logging ---
$log_dir  = __DIR__ . '/logs';
$log_file = $log_dir . '/watchlist-handler.log';
if (!is_dir($log_dir)) { @mkdir($log_dir, 0755, true); }
function wl_log($msg) {
    global $log_file;
    @file_put_contents($log_file, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
}

// --- Ensure table exists ---
$wpdb->query("
CREATE TABLE IF NOT EXISTS $watchlist_table (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  account VARCHAR(35) NOT NULL,
  nft_id  VARCHAR(64) NOT NULL,
  nft_name VARCHAR(255) DEFAULT '',
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_acc_nft (account, nft_id),
  KEY idx_account (account)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// --- Helpers ---
function is_xrpl_account($a) {
    return is_string($a) && preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $a);
}

function nft_id_valid($n) {
    return is_string($n) && preg_match('/^[0-9A-Fa-f]{64}$/', $n);
}

/**
 * Prefer global get_nft_metadata() (from xumm-proxy.php).
 * Fallback: safe placeholder.
 */
function wl_get_min_meta($nft_id) {
    if (function_exists('get_nft_metadata')) {
        $m = get_nft_metadata($nft_id) ?: [];
        return [
            'nft_name' => $m['nft_name'] ?? ($m['metadata']['name'] ?? 'NFT'),
            'image'    => $m['image'] ?? ($m['ipfs_link'] ?? ($m['metadata']['image'] ?? '/wp-content/uploads/fallback-nft.svg')),
            'issuer'   => $m['issuer'] ?? ''
        ];
    }
    return ['nft_name' => 'NFT', 'image' => '/wp-content/uploads/fallback-nft.svg', 'issuer' => ''];
}

/** Optional broadcaster (internal WS hub). No-op if endpoint unavailable. */
function wl_broadcast(array $payload, $endpoint = '') {
    $url = $endpoint ?: (defined('IMU_WS_BROADCAST') ? IMU_WS_BROADCAST : '');
    if (!$url) return;
    $res = wp_remote_post($url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => wp_json_encode($payload),
        'timeout' => 5,
    ]);
    if (is_wp_error($res)) wl_log('broadcast failed: '.$res->get_error_message());
}

/** Optional OneSignal email notification (if keys present). */
function wl_notify_email($email, $subject, $message, $cta_url = '') {
    if (!$email || !defined('ONESIGNAL_APP_ID') || !defined('ONESIGNAL_API_KEY')) return false;
    $payload = [
        'app_id'        => ONESIGNAL_APP_ID,
        'include_email_tokens' => [$email],
        'email_subject' => $subject,
        'email_body'    => $message . ($cta_url ? "<br><a href=\"$cta_url\">Open</a>" : '')
    ];
    $res = wp_remote_post('https://onesignal.com/api/v1/notifications', [
        'headers' => ['Authorization' => 'Basic ' . ONESIGNAL_API_KEY, 'Content-Type' => 'application/json'],
        'body'    => wp_json_encode($payload),
        'timeout' => 10
    ]);
    return !is_wp_error($res) && (int)wp_remote_retrieve_response_code($res) < 300;
}

// ---------- Route ----------
$method = $_SERVER['REQUEST_METHOD'];
$action = sanitize_text_field($_REQUEST['action'] ?? '');
$nonce  = sanitize_text_field($_REQUEST['nonce'] ?? '');

if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
    wl_log('invalid nonce');
    wp_send_json_error(['error' => 'Invalid nonce'], 403);
}

// ---------- GET: list watchlist ----------
if ($method === 'GET' && $action === 'get') {
    // Phase 1 (Aug 2026), extended to the read: identity comes from the session
    // token, never from ?account=. An XRPL address is public, so the parameter
    // proves nothing -- supplying someone else's returned their whole watchlist,
    // which is a record of what a collector is tracking and has not yet bought.
    //
    // DERIVE, NOT REJECT (unlike add/remove below). A read has no account to
    // write to, so with no session this returns an EMPTY watchlist rather than a
    // 403 -- the correct view for a logged-out visitor. It also keeps the
    // trading.js caller working: that one sources userAccount from
    // document.cookie xrpl_account, a value imc_session_resolve_wallet() ignores
    // while IMC_SESSION_TOKEN_ONLY is true, so it now sees its own empty list
    // instead of another wallet's contents.
    $wl_get_auth = function_exists('imc_session_require_wallet')
        ? imc_session_require_wallet('')
        : array('ok' => false, 'wallet' => '', 'error' => 'auth');
    $account = !empty($wl_get_auth['ok']) ? (string) $wl_get_auth['wallet'] : '';

    if (!is_xrpl_account($account)) {
        wp_send_json_success(['watchlist' => []]);
        exit;
    }

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT nft_id, nft_name, created_at FROM $watchlist_table WHERE account=%s ORDER BY created_at DESC",
        $account
    ), ARRAY_A) ?: [];

    // Refresh names from metadata (lightweight)
    foreach ($rows as &$r) {
        $m = wl_get_min_meta($r['nft_id']);
        $r['nft_name'] = $m['nft_name'];
    }

    wp_send_json_success(['watchlist' => $rows]);
    exit;
}

// ---------- POST: add ----------
if ($method === 'POST' && $action === 'add') {
    // Phase 1 (Aug 2026): identity comes from the session token, never from
    // client POST. A posted account that differs from the session wallet is
    // rejected outright so tampering shows in the log.
    $wl_auth = function_exists('imc_session_require_wallet')
        ? imc_session_require_wallet(sanitize_text_field($_POST['account'] ?? ''))
        : array('ok' => false, 'wallet' => '', 'error' => 'auth');
    if (empty($wl_auth['ok'])) {
        wl_log('add rejected: ' . (($wl_auth['error'] ?? '') === 'mismatch' ? 'account mismatch (posted != session)' : 'not authenticated'));
        wp_send_json_error(['error' => (($wl_auth['error'] ?? '') === 'mismatch') ? 'Account mismatch' : 'Not authenticated'], 403);
    }
    $account = $wl_auth['wallet'];
    $nft_id  = sanitize_text_field($_POST['nft_id'] ?? '');
    if (!is_xrpl_account($account) || !nft_id_valid($nft_id)) {
        wp_send_json_error(['error' => 'Invalid parameters'], 400);
    }

    $exists = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $watchlist_table WHERE account=%s AND nft_id=%s",
        $account, $nft_id
    ));
    if ($exists) {
        wp_send_json_error(['error' => 'NFT already in watchlist'], 409);
    }

    $meta = wl_get_min_meta($nft_id);
    $ok = $wpdb->insert($watchlist_table, [
        'account'    => $account,
        'nft_id'     => $nft_id,
        'nft_name'   => $meta['nft_name'],
        'created_at' => current_time('mysql')
    ], ['%s','%s','%s','%s']);

    if ($ok === false) {
        wl_log('insert failed: ' . $wpdb->last_error);
        wp_send_json_error(['error' => 'Failed to add'], 500);
    }

    // Optional broadcast + email
    wl_broadcast(['type' => 'watchlist_added', 'account' => $account, 'nft_id' => $nft_id]);
    $prof = $wpdb->get_row($wpdb->prepare("SELECT email FROM $profile_table WHERE xrpl_account=%s", $account));
    if ($prof && !empty($prof->email)) {
        wl_notify_email($prof->email, 'NFT Added to Watchlist', "You added {$meta['nft_name']} to your watchlist.", home_url('/trading-hub/'));
    }

    wp_send_json_success(['added' => true]);
    exit;
}

// ---------- POST: remove ----------
if ($method === 'POST' && $action === 'remove') {
    // Phase 1 (Aug 2026): identity comes from the session token, never from
    // client POST. A posted account that differs from the session wallet is
    // rejected outright so tampering shows in the log.
    $wl_auth = function_exists('imc_session_require_wallet')
        ? imc_session_require_wallet(sanitize_text_field($_POST['account'] ?? ''))
        : array('ok' => false, 'wallet' => '', 'error' => 'auth');
    if (empty($wl_auth['ok'])) {
        wl_log('remove rejected: ' . (($wl_auth['error'] ?? '') === 'mismatch' ? 'account mismatch (posted != session)' : 'not authenticated'));
        wp_send_json_error(['error' => (($wl_auth['error'] ?? '') === 'mismatch') ? 'Account mismatch' : 'Not authenticated'], 403);
    }
    $account = $wl_auth['wallet'];
    $nft_id  = sanitize_text_field($_POST['nft_id'] ?? '');
    if (!is_xrpl_account($account) || !nft_id_valid($nft_id)) {
        wp_send_json_error(['error' => 'Invalid parameters'], 400);
    }

    $deleted = $wpdb->delete($watchlist_table, ['account' => $account, 'nft_id' => $nft_id], ['%s','%s']);
    if ($deleted === false) {
        wl_log('delete failed: ' . $wpdb->last_error);
        wp_send_json_error(['error' => 'Failed to remove'], 500);
    }
    if ($deleted === 0) {
        wp_send_json_error(['error' => 'NFT not in watchlist'], 404);
    }

    wl_broadcast(['type' => 'watchlist_removed', 'account' => $account, 'nft_id' => $nft_id]);

    $meta = wl_get_min_meta($nft_id);
    $prof = $wpdb->get_row($wpdb->prepare("SELECT email FROM $profile_table WHERE xrpl_account=%s", $account));
    if ($prof && !empty($prof->email)) {
        wl_notify_email($prof->email, 'NFT Removed from Watchlist', "You removed {$meta['nft_name']} from your watchlist.", home_url('/trading-hub/'));
    }

    wp_send_json_success(['removed' => true]);
    exit;
}

// Fallback
wp_send_json_error(['error' => 'Unknown action'], 400);