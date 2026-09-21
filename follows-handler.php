<?php
/**
 * File: follows-handler.php
 * Path: /wp-content/themes/astra/xrpl-nft-marketplace/backend/follows-handler.php
 *
 * P3 (User Profiles, Aug 2026). The social graph.
 * Table wp_imc_follows (created in P0 / imc-user-profiles.php).
 *
 * Endpoints:
 *   POST action=follow    (target, nonce)               → follow target
 *   POST action=unfollow  (target, nonce)               → unfollow target
 *   GET  action=status&target=...&nonce=...             → { following: bool, followers, following_count }
 *   GET  action=list_followers&account=...&nonce=...    → [{account, display_name, slug, pfp, is_creator}]
 *   GET  action=list_following&account=...&nonce=...    → same shape
 *
 * Identity: writes derive the follower from the session token
 * (imc_session_require_wallet, live since Phase 1). The follower is NEVER
 * taken from client input. Reads are public (counts + lists are public data).
 */

require_once dirname(__DIR__, 5) . '/wp-load.php';

if (!defined('ABSPATH')) { exit; }

header('Content-Type: application/json');

$xrpl_re = '/^r[1-9A-HJ-NP-Za-km-z]{24,34}$/';

function fh_error($msg, $code = 400) {
    status_header($code);
    echo wp_json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function fh_ok($data = []) {
    echo wp_json_encode(['success' => true, 'data' => $data]);
    exit;
}
function fh_log($msg) {
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    @file_put_contents($dir . '/follows-handler.log', date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
}

global $wpdb;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = sanitize_text_field($_REQUEST['action'] ?? '');

if (!function_exists('imc_follows_table')) {
    fh_error('Follows subsystem unavailable', 503);
}
$table = imc_follows_table();
// The table is created by the P0 migration; ensure on first touch as a safety net.
if (function_exists('imc_follows_ensure_table')) { imc_follows_ensure_table(); }

/* Shared: is this wallet blacklisted? (mirrors page-user.php gate) */
function fh_is_blacklisted($account) {
    global $wpdb;
    $xp = $wpdb->prefix . 'xaman_profiles';
    if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $xp)) !== $xp) { return false; }
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(blacklisted,0) FROM {$xp} WHERE xrpl_account = %s", $account
    )) === 1;
}

/* Shared: enrich a set of accounts with profile display data for list views */
function fh_enrich($accounts) {
    global $wpdb;
    $accounts = array_values(array_unique(array_filter($accounts)));
    if (empty($accounts)) { return []; }

    $names = function_exists('imc_artist_display_map') ? imc_artist_display_map($accounts) : [];

    $prof = function_exists('imc_artist_profiles_table') ? imc_artist_profiles_table() : ($wpdb->prefix . 'imc_artist_profiles');
    $list = $wpdb->prefix . 'imc_listings';

    // profile images + slugs in one query
    $placeholders = implode(',', array_fill(0, count($accounts), '%s'));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT artist_account, slug, profile_image_url FROM {$prof} WHERE artist_account IN ($placeholders)",
        $accounts
    ), ARRAY_A) ?: [];
    $pmap = [];
    foreach ($rows as $r) { $pmap[$r['artist_account']] = $r; }

    // creator flag set (has listings) in one query
    $crows = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT artist_account FROM {$list} WHERE artist_account IN ($placeholders)",
        $accounts
    ), ARRAY_A) ?: [];
    $creators = [];
    foreach ($crows as $c) { $creators[$c['artist_account']] = true; }

    $out = [];
    foreach ($accounts as $acct) {
        if (fh_is_blacklisted($acct)) { continue; } // hidden wallets never surface
        $p = $pmap[$acct] ?? [];
        $short = function_exists('imc_wallet_short') ? imc_wallet_short($acct) : (substr($acct, 0, 6) . '...' . substr($acct, -4));
        $name = $names[$acct] ?? '';
        if ($name === '' || $name === null) { $name = $short; }
        $out[] = [
            'account'      => $acct,
            'display_name' => $name,
            'slug'         => $p['slug'] ?? '',
            'pfp'          => $p['profile_image_url'] ?? '',
            'is_creator'   => isset($creators[$acct]),
        ];
    }
    return $out;
}

/* ---- WRITES: follow / unfollow ------------------------------------------- */
if ($method === 'POST' && ($action === 'follow' || $action === 'unfollow')) {

    // CSRF
    $nonce = sanitize_text_field($_POST['nonce'] ?? '');
    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        fh_error('Invalid security token', 403);
    }

    // Identity: follower is the SESSION wallet, never client input.
    $auth = function_exists('imc_session_require_wallet')
        ? imc_session_require_wallet('')
        : ['ok' => false, 'wallet' => '', 'error' => 'auth'];
    if (empty($auth['ok']) || !preg_match($xrpl_re, (string) $auth['wallet'])) {
        fh_error('Not authenticated - please sign in again', 403);
    }
    $follower = $auth['wallet'];

    $target = sanitize_text_field($_POST['target'] ?? '');
    if (!preg_match($xrpl_re, $target)) {
        fh_error('Invalid target account');
    }
    if ($target === $follower) {
        fh_error('You cannot follow yourself');
    }
    if (fh_is_blacklisted($target)) {
        fh_error('This account is not available', 404);
    }

    $now = current_time('mysql');

    if ($action === 'follow') {
        // Idempotent: UNIQUE(follower,followed) makes a re-follow a no-op.
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table} (follower_account, followed_account, created_at)
             VALUES (%s, %s, %s)",
            $follower, $target, $now
        ));
        fh_log("follow: {$follower} -> {$target}");
    } else {
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE follower_account = %s AND followed_account = %s",
            $follower, $target
        ));
        fh_log("unfollow: {$follower} -> {$target}");
    }

    $followers = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE followed_account = %s", $target));
    fh_ok([
        'following'  => ($action === 'follow'),
        'target'     => $target,
        'followers'  => $followers,
    ]);
}

/* ---- READ: status -------------------------------------------------------- */
if ($method === 'GET' && $action === 'status') {
    $target = sanitize_text_field($_GET['target'] ?? '');
    if (!preg_match($xrpl_re, $target)) {
        fh_error('Invalid target account');
    }
    // Viewer (optional): if signed in, report whether they follow the target.
    $viewer = function_exists('imc_session_resolve_wallet') ? imc_session_resolve_wallet() : '';
    $is_following = false;
    if ($viewer !== '' && preg_match($xrpl_re, $viewer)) {
        $is_following = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE follower_account = %s AND followed_account = %s",
            $viewer, $target
        )) > 0;
    }
    $followers = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE followed_account = %s", $target));
    $following = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE follower_account = %s", $target));
    fh_ok([
        'following'       => $is_following,
        'followers'       => $followers,
        'following_count' => $following,
    ]);
}

/* ---- READ: list_followers / list_following ------------------------------- */
if ($method === 'GET' && ($action === 'list_followers' || $action === 'list_following')) {
    $account = sanitize_text_field($_GET['account'] ?? '');
    if (!preg_match($xrpl_re, $account)) {
        fh_error('Invalid account');
    }
    $limit  = min(100, max(1, (int) ($_GET['limit'] ?? 50)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));

    if ($action === 'list_followers') {
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT follower_account FROM {$table} WHERE followed_account = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $account, $limit, $offset
        )) ?: [];
    } else {
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT followed_account FROM {$table} WHERE follower_account = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $account, $limit, $offset
        )) ?: [];
    }
    fh_ok(['list' => fh_enrich($rows)]);
}

fh_error('Unknown action', 400);