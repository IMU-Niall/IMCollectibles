<?php
/**
 * FILE: allowlist-handler.php
 * PATH: /wp-content/themes/astra/xrpl-nft-marketplace/backend/
 * 
 * PHASE E: Allowlists / Whitelists for NFT Minting
 * 
 * ALLOWLIST TYPES:
 * - early_access: Mint before public launch time
 * - discount: Reduced price for specific wallets
 * - exclusive: ONLY allowlisted wallets can mint
 * - limit_override: Custom per-wallet mint limit
 * 
 * @version 1.1.0 (v727) — A1: quantity-aware add_entries (the /[\n,]+/ split destroyed
 * every "wallet,amount" quantity -> custom_max_mint NULL -> allocation engine blind);
 * ON DUPLICATE KEY UPDATE repair path on both entry actions; required amounts for
 * discount_limited; default_quantity support; input caps; increment_minted removed
 * (no callers; public-nonce-only griefing vector); >0 normalisation in check_wallet.
 */

require_once dirname(__DIR__, 5) . '/wp-load.php';
if (!defined('ABSPATH')) exit;

header('Content-Type: application/json; charset=utf-8');
// CORS whitelist
$imc_allowed_origins = ['https://imcollectibles.xyz','https://www.imcollectibles.xyz','https://imcollectibles.io','https://www.imcollectibles.io','https://improtectors.com','https://www.improtectors.com'];
$imc_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . (in_array($imc_origin, $imc_allowed_origins) ? $imc_origin : $imc_allowed_origins[0]));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function json_success($data = []) { echo json_encode(['success' => true, 'data' => $data]); exit; }
function json_error($message, $code = 400) { http_response_code($code); echo json_encode(['success' => false, 'error' => $message]); exit; }
/**
 * Apply an allowlist's holder ceiling to ONE entry's value.
 * NULL, '' or 0 = no ceiling, so an un-set column is byte-identical to today.
 * ⚠ Duplicated deliberately in mint-on-demand-handler.php: the two files compute
 * allocation independently (v381 and v727 each had to be fixed in both), and a
 * shared include across handler boundaries is a larger change than this build.
 * If you edit one, edit the other.
 */
function imc_al_cap($value, $limit) {
    $lim = ($limit === null || $limit === '') ? 0 : intval($limit);
    return ($lim > 0 && $value > $lim) ? $lim : $value;
}

function validate_xrpl_address($address) { return preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $address); }

global $wpdb;
$allowlists_table = $wpdb->prefix . 'imc_allowlists';
$entries_table = $wpdb->prefix . 'imc_allowlist_entries';
$listings_table = $wpdb->prefix . 'imc_listings';

// Create tables
$wpdb->query("CREATE TABLE IF NOT EXISTS $allowlists_table (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    listing_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    type ENUM('early_access','discount','exclusive','limit_override','discount_limited') NOT NULL DEFAULT 'early_access',
    discount_percent DECIMAL(5,2) DEFAULT 0,
    custom_price DECIMAL(20,6) DEFAULT NULL,
    early_access_hours INT UNSIGNED DEFAULT 24,
    max_mint_override INT UNSIGNED DEFAULT NULL,
    allowlist_holder_limit INT UNSIGNED DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_listing (listing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// v253: Safe migration — add custom_price column to existing installs
$col_exists = $wpdb->get_var("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$allowlists_table' AND COLUMN_NAME = 'custom_price'");
if (!$col_exists) {
    $wpdb->query("ALTER TABLE $allowlists_table ADD COLUMN custom_price DECIMAL(20,6) DEFAULT NULL AFTER discount_percent");
}

// Safe migration — allowlist_holder_limit. A CEILING on what any one wallet may
// draw from THIS allowlist. Deliberately NOT named like max_mint_override, which
// is a FALLBACK (used only when an entry carries no amount of its own) - opposite
// operations, and similar names would be a trap for whoever reads this next.
// NULL or 0 = no ceiling, so every existing allowlist behaves exactly as before.
// Same idempotent INFORMATION_SCHEMA pattern as v253's custom_price.
$ahl_exists = $wpdb->get_var("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$allowlists_table' AND COLUMN_NAME = 'allowlist_holder_limit'");
if (!$ahl_exists) {
    $wpdb->query("ALTER TABLE $allowlists_table ADD COLUMN allowlist_holder_limit INT UNSIGNED DEFAULT NULL AFTER max_mint_override");
}

// v381: Safe migration — add 'discount_limited' to type ENUM for existing installs
$current_type = $wpdb->get_var("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$allowlists_table' AND COLUMN_NAME = 'type'");
if ($current_type && strpos($current_type, 'discount_limited') === false) {
    $wpdb->query("ALTER TABLE $allowlists_table MODIFY COLUMN type ENUM('early_access','discount','exclusive','limit_override','discount_limited') NOT NULL DEFAULT 'early_access'");
}

// v731 (A4b): per-token discount matrix — kill-switch + schema.
// currency_benefits: JSON array of per-token rules [{currency, mode: percent|price,
// value, issuer?}] — ONE rule per currency per list; a rule REPLACES the base benefit
// for that token (override, not stack). NO per-token free: free is list-level
// (custom_price = 0 = free in every currency). Dark by default; wp-config wins.
if (!defined('IMC_ALLOWLIST_CURRENCY_MATRIX')) define('IMC_ALLOWLIST_CURRENCY_MATRIX', false);
$al_cols = $wpdb->get_col("DESCRIBE $allowlists_table", 0);
if (is_array($al_cols) && !empty($al_cols) && !in_array('currency_benefits', $al_cols)) {
    @$wpdb->query("ALTER TABLE $allowlists_table ADD COLUMN currency_benefits LONGTEXT DEFAULT NULL AFTER custom_price");
}

/**
 * v731 (A4b): validate a per-token rules payload. Returns [rules_array|null, error|null].
 * percent 1-99 · price > 0 (in that token) — the caps close the stealth-free backdoors.
 */
function imc_validate_currency_benefits($raw) {
    if ($raw === null || $raw === '') return [null, null];
    $arr = json_decode(is_string($raw) ? stripslashes($raw) : '', true);
    if (!is_array($arr)) return [null, 'Invalid per-token rules format'];
    $out = [];
    foreach ($arr as $r) {
        if (!is_array($r) || !isset($r['currency'], $r['mode'], $r['value'])) continue;
        $cur = strtoupper(trim($r['currency']));
        if ($cur === '' || strlen($cur) > 40) continue;
        $mode = $r['mode'] === 'price' ? 'price' : ($r['mode'] === 'percent' ? 'percent' : null);
        if (!$mode) continue;
        $v = floatval($r['value']);
        if ($mode === 'percent' && ($v < 1 || $v > 99)) return [null, 'Per-token discount must be 1-99% — for free, set the list price to 0 instead'];
        if ($mode === 'price' && !($v > 0)) return [null, 'Per-token price must be greater than 0 — for free, set the list price to 0 instead'];
        $entry = ['currency' => $cur, 'mode' => $mode, 'value' => $v];
        if (!empty($r['issuer']) && preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $r['issuer'])) $entry['issuer'] = $r['issuer'];
        $out[$cur] = $entry; // one rule per currency per list — last wins
    }
    return [count($out) ? array_values($out) : null, null];
}

$wpdb->query("CREATE TABLE IF NOT EXISTS $entries_table (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    allowlist_id BIGINT UNSIGNED NOT NULL,
    wallet_address VARCHAR(35) NOT NULL,
    label VARCHAR(100) DEFAULT NULL,
    custom_discount DECIMAL(5,2) DEFAULT NULL,
    custom_max_mint INT UNSIGNED DEFAULT NULL,
    custom_price DECIMAL(20,6) DEFAULT NULL,
    minted_count INT UNSIGNED DEFAULT 0,
    created_at DATETIME NOT NULL,
    UNIQUE KEY unique_wallet_list (allowlist_id, wallet_address),
    INDEX idx_wallet (wallet_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$action = $_REQUEST['action'] ?? '';

// ============================================================================
// IDENTITY. Every ownership check below compares against artist_account. That
// value is resolved ONCE here from the proven wallet session and is never taken
// from the request, so the checks are real authorization rather than a filter.
//
// imc_session_require_wallet() is available because wp-load.php boots the theme
// and functions.php loads inc/imc-session-auth.php.
// ============================================================================
$imc_al_auth = function_exists('imc_session_require_wallet')
    ? imc_session_require_wallet()
    : array('ok' => false, 'wallet' => '', 'error' => 'auth');
$imc_al_wallet = !empty($imc_al_auth['ok']) ? (string) $imc_al_auth['wallet'] : '';

// === GET REQUESTS ===
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    
    if ($action === 'get') {
        $listing_id = intval($_GET['listing_id'] ?? 0);
        $artist_account = $imc_al_wallet;   // session-derived identity
        if ($artist_account === '') json_error('Not authorised', 403);
        if (!$listing_id) json_error('Missing listing_id');
        
        // Ownership is mandatory.
        $listing = $wpdb->get_row($wpdb->prepare("SELECT id FROM $listings_table WHERE id = %d AND artist_account = %s", $listing_id, $artist_account));
        if (!$listing) json_error('Listing not found or not yours', 403);
        
        // v730 (A4): allocation + missing-amounts stats so the dashboard can show
        // used/total on Holder Discount cards and flag legacy NULL-amount entries
        // (legacy rows) with a repair badge.
        $allowlists = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, (SELECT COUNT(*) FROM $entries_table e WHERE e.allowlist_id = a.id) as entry_count,
             (SELECT SUM(e.minted_count) FROM $entries_table e WHERE e.allowlist_id = a.id) as total_minted,
             (SELECT COALESCE(SUM(e.custom_max_mint), 0) FROM $entries_table e WHERE e.allowlist_id = a.id AND e.custom_max_mint > 0) as discount_allocation,
             (SELECT COUNT(*) FROM $entries_table e WHERE e.allowlist_id = a.id AND e.custom_max_mint IS NULL) as missing_amounts
             FROM $allowlists_table a WHERE a.listing_id = %d ORDER BY a.created_at DESC", $listing_id
        ), ARRAY_A);
        json_success(['allowlists' => $allowlists ?: []]);
    }
    
    if ($action === 'get_entries') {
        $allowlist_id = intval($_GET['allowlist_id'] ?? 0);
        $artist_account = $imc_al_wallet;   // session-derived identity
        if ($artist_account === '') json_error('Not authorised', 403);
        $limit = min(500, max(1, intval($_GET['limit'] ?? 100)));
        $offset = max(0, intval($_GET['offset'] ?? 0));
        if (!$allowlist_id) json_error('Missing allowlist_id');
        
        // Ownership is mandatory.
        $al = $wpdb->get_row($wpdb->prepare("SELECT a.id FROM $allowlists_table a JOIN $listings_table l ON a.listing_id = l.id WHERE a.id = %d AND l.artist_account = %s", $allowlist_id, $artist_account));
        if (!$al) json_error('Allowlist not found or not yours', 403);
        
        $entries = $wpdb->get_results($wpdb->prepare("SELECT * FROM $entries_table WHERE allowlist_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d", $allowlist_id, $limit, $offset), ARRAY_A);
        $total = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $entries_table WHERE allowlist_id = %d", $allowlist_id));
        json_success(['entries' => $entries ?: [], 'total' => intval($total)]);
    }
    
    if ($action === 'check_wallet') {
        $listing_id = intval($_GET['listing_id'] ?? 0);
        // A buyer may only check their own wallet.
        if ($imc_al_wallet === '') json_error('Sign in to check allowlist status', 403);
        $wallet = $imc_al_wallet;
        if (!$listing_id || !$wallet) json_error('Missing listing_id or wallet');
        if (!validate_xrpl_address($wallet)) json_error('Invalid wallet address');
        
        $entries = $wpdb->get_results($wpdb->prepare(
            "SELECT e.*, a.type, a.name as allowlist_name, a.discount_percent, a.custom_price as allowlist_custom_price, a.early_access_hours, a.max_mint_override, a.allowlist_holder_limit, a.currency_benefits
             FROM $entries_table e JOIN $allowlists_table a ON e.allowlist_id = a.id
             WHERE a.listing_id = %d AND e.wallet_address = %s AND a.is_active = 1", $listing_id, $wallet
        ), ARRAY_A);
        
        $benefits = ['is_allowlisted' => !empty($entries), 'can_early_access' => false, 'early_access_hours' => 0,
                     'discount_percent' => 0, 'custom_price' => null, 'max_mint' => null, 'minted_count' => 0, 'allowlists' => []];
        
        $has_exclusive = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $allowlists_table WHERE listing_id = %d AND type = 'exclusive' AND is_active = 1", $listing_id));
        $benefits['listing_is_exclusive'] = intval($has_exclusive) > 0;
        $has_unlimited_discount = false; // v382: Track if any regular 'discount' (unlimited) entries exist
        
        // v382: Query listing launch timing for early access context
        $listing_launch = $wpdb->get_row($wpdb->prepare(
            "SELECT launch_type, launch_at FROM $listings_table WHERE id = %d", $listing_id
        ), ARRAY_A);
        
        foreach ($entries as $e) {
            $benefits['allowlists'][] = $e['allowlist_name'];
            $benefits['minted_count'] = max($benefits['minted_count'], intval($e['minted_count']));
            
            if ($e['type'] === 'early_access') { $benefits['can_early_access'] = true; $benefits['early_access_hours'] = max($benefits['early_access_hours'], intval($e['early_access_hours'])); }
            if ($e['type'] === 'discount' || $e['type'] === 'discount_limited') {
                if ($e['type'] === 'discount') $has_unlimited_discount = true; // v382
                // v253: custom_price at allowlist level takes priority over percentage discount
                if ($e['allowlist_custom_price'] !== null) {
                    $p = floatval($e['allowlist_custom_price']);
                    if ($benefits['custom_price'] === null || $p < $benefits['custom_price']) $benefits['custom_price'] = $p;
                } elseif ($e['custom_discount'] !== null || floatval($e['discount_percent']) > 0) {
                    $d = $e['custom_discount'] !== null ? floatval($e['custom_discount']) : floatval($e['discount_percent']);
                    $benefits['discount_percent'] = max($benefits['discount_percent'], $d);
                }
                // v731 (A4b): collect per-token rules from every discount-type list the
                // wallet is on. Keyed per currency; multiple lists' rules for the same
                // token are all kept — the buyer-best applies at price time.
                if (IMC_ALLOWLIST_CURRENCY_MATRIX && !empty($e['currency_benefits'])) {
                    $imc_cw_rules = json_decode($e['currency_benefits'], true);
                    if (is_array($imc_cw_rules)) {
                        foreach ($imc_cw_rules as $imc_cw_r) {
                            if (!isset($imc_cw_r['currency'], $imc_cw_r['mode'], $imc_cw_r['value'])) continue;
                            $benefits['currency_rules'][strtoupper($imc_cw_r['currency'])][] = ['mode' => $imc_cw_r['mode'], 'value' => floatval($imc_cw_r['value'])];
                        }
                    }
                }
                // v381: Only discount_limited tracks allocation (regular discount stays unlimited)
                // v727: > 0 (was !== null) — aligns with reserve_purchase; a 0/NULL amount
                // must never open the allocation path with an empty allocation.
                if ($e['type'] === 'discount_limited' && intval($e['custom_max_mint']) > 0) {
                    // ⚠ CLAMP PER ROW, NEVER ON THE RUNNING TOTAL. This query returns one
                    // row per ENTRY across ALL of the listing's active allowlists, so a
                    // wallet on two allowlists produces two rows. Capping the accumulator
                    // would make allowlist A's ceiling silently constrain allowlist B.
                    // The column is per-allowlist, so each row is clamped by its own.
                    $benefits['discount_allocation'] = ($benefits['discount_allocation'] ?? 0)
                        + imc_al_cap(intval($e['custom_max_mint']), $e['allowlist_holder_limit']);
                }
            }
            if ($e['type'] === 'exclusive') { $benefits['is_exclusive_member'] = true; }
            // v381: Type-isolated SUM for limit_override (was max — now sums across multi-collection allowlists)
            // v381 sums across entries; the ceiling clamps each row's contribution
            // BEFORE that sum, so the v381 semantics are untouched.
            if ($e['type'] === 'limit_override') { $l = $e['custom_max_mint'] !== null ? intval($e['custom_max_mint']) : intval($e['max_mint_override']); $l = imc_al_cap($l, $e['allowlist_holder_limit']); if ($l > 0) $benefits['max_mint'] = ($benefits['max_mint'] ?? 0) + $l; }
            // Per-entry custom_price override (lowest price wins)
            if ($e['custom_price'] !== null) { $p = floatval($e['custom_price']); if ($benefits['custom_price'] === null || $p < $benefits['custom_price']) $benefits['custom_price'] = $p; }
        }
        // v381: Calculate discount remaining (null = unlimited discount, 0 = exhausted)
        if (isset($benefits['discount_allocation'])) {
            $benefits['discount_remaining'] = max(0, $benefits['discount_allocation'] - $benefits['minted_count']);
            // v382: Determine if currently in early access period (before public launch)
            $in_early_access = false;
            if ($listing_launch && $listing_launch['launch_type'] === 'scheduled' && !empty($listing_launch['launch_at'])) {
                $in_early_access = strtotime($listing_launch['launch_at']) > time();
            }
            $benefits['in_early_access'] = $in_early_access;
            // v382: When holder discount allocation is fully exhausted and no unlimited discount exists
            if ($benefits['discount_remaining'] <= 0 && !$has_unlimited_discount) {
                $benefits['custom_price'] = null;
                $benefits['discount_percent'] = 0;
                // v731 (A4b): per-token rules are discount-sourced — graduation clears them too
                unset($benefits['currency_rules']);
                // During early access: flag as blocked (frontend disables mint button)
                // After public launch: graduate silently (can mint at full price)
                $benefits['early_access_blocked'] = $in_early_access;
                if ($in_early_access && !empty($listing_launch['launch_at'])) {
                    $benefits['launch_at_display'] = date('M j, g:i A', strtotime($listing_launch['launch_at']));
                }
            }
        }
        json_success($benefits);
    }
    
    json_error('Unknown GET action');
}

// === POST REQUESTS ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Nonce verification for write operations
    $nonce = $_POST['nonce'] ?? $_SERVER['HTTP_X_WP_NONCE'] ?? '';
    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        json_error('Invalid security token', 403);
    }
    // Every write below derives artist_account from the session.
    if ($imc_al_wallet === '') {
        json_error('Session expired -- please sign in again', 403);
    }

    if ($action === 'create') {
        $listing_id = intval($_POST['listing_id'] ?? 0);
        $artist_account = $imc_al_wallet;   // session-derived identity
        $name = sanitize_text_field($_POST['name'] ?? '');
        $type = sanitize_text_field($_POST['type'] ?? 'early_access');
        if (!$listing_id || !$artist_account || !$name) json_error('Missing required fields');
        if (!in_array($type, ['early_access', 'discount', 'exclusive', 'limit_override', 'discount_limited'])) json_error('Invalid type');
        
        $listing = $wpdb->get_row($wpdb->prepare("SELECT id FROM $listings_table WHERE id = %d AND artist_account = %s", $listing_id, $artist_account));
        if (!$listing) json_error('Listing not found or not yours');
        
        $now = current_time('mysql');
        $wpdb->insert($allowlists_table, [
            'listing_id' => $listing_id, 'name' => $name, 'description' => sanitize_textarea_field($_POST['description'] ?? ''),
            'type' => $type,
            // v679: was hardcoded to 0, so the percentage-discount path the benefits engine
            // reads (see check_wallet) could never be reached from the dashboard. Clamped 0-100.
            'discount_percent' => (isset($_POST['discount_percent']) && $_POST['discount_percent'] !== '')
                ? max(0, min(100, floatval($_POST['discount_percent'])))
                : 0,
            'custom_price' => (isset($_POST['custom_price']) && $_POST['custom_price'] !== '') ? floatval($_POST['custom_price']) : null,
            // v731 (A4b): per-token rules — validated below, rejected on free-claim lists
            'currency_benefits' => null,
            'early_access_hours' => intval($_POST['early_access_hours'] ?? 24),
            'max_mint_override' => isset($_POST['max_mint_override']) && $_POST['max_mint_override'] !== '' ? intval($_POST['max_mint_override']) : null,
            'allowlist_holder_limit' => isset($_POST['allowlist_holder_limit']) && $_POST['allowlist_holder_limit'] !== '' ? max(0, intval($_POST['allowlist_holder_limit'])) : null,
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now
        ]);
        $id = $wpdb->insert_id;
        if (!$id) json_error('Failed to create allowlist');

        // v731 (A4b): per-token rules land after the row exists (keeps the insert simple).
        // FREE lists never carry rules — a free claim is free in EVERY currency.
        if (in_array($type, ['discount', 'discount_limited']) && !empty($_POST['currency_benefits'])) {
            $imc_new_cp = (isset($_POST['custom_price']) && $_POST['custom_price'] !== '') ? floatval($_POST['custom_price']) : null;
            if ($imc_new_cp !== null && $imc_new_cp == 0) {
                json_error('Free claim lists are free in every currency — per-token rules are not needed');
            }
            list($imc_cb_rules, $imc_cb_err) = imc_validate_currency_benefits($_POST['currency_benefits']);
            if ($imc_cb_err) json_error($imc_cb_err);
            if ($imc_cb_rules) {
                $wpdb->update($allowlists_table, ['currency_benefits' => wp_json_encode($imc_cb_rules)], ['id' => $id]);
            }
        }
        json_success(['allowlist_id' => $id]);
    }
    
    if ($action === 'add_entries') {
        $allowlist_id = intval($_POST['allowlist_id'] ?? 0);
        $artist_account = $imc_al_wallet;   // session-derived identity
        $wallets_raw = $_POST['wallets'] ?? '';
        if (!$allowlist_id || !$artist_account) json_error('Missing required fields');
        
        $al = $wpdb->get_row($wpdb->prepare("SELECT a.* FROM $allowlists_table a JOIN $listings_table l ON a.listing_id = l.id WHERE a.id = %d AND l.artist_account = %s", $allowlist_id, $artist_account), ARRAY_A);
        if (!$al) json_error('Allowlist not found or not yours');
        
        // v727: Lines split on NEWLINES ONLY so "wallet,amount" survives. The previous
        // /[\n,]+/ split turned every amount into an "invalid" token and inserted the
        // wallet with custom_max_mint NULL — the allocation engine (check_wallet +
        // reserve_purchase v382) then saw no allocation at all, and free allowlists
        // fell through to the v412 guard, locking users out. Root cause of
        // a holder-discount report.
        $uses_quantity = in_array($al['type'], ['limit_override', 'discount_limited']);

        // v727: optional shared default — any wallet without its own amount gets this.
        $default_quantity = null;
        if (isset($_POST['default_quantity']) && $_POST['default_quantity'] !== '') {
            $dq = intval($_POST['default_quantity']);
            if ($dq < 1 || $dq > 99999) json_error('Default amount must be between 1 and 99999');
            $default_quantity = $dq;
        }

        $lines = array_filter(array_map('trim', preg_split('/[\r\n]+/', $wallets_raw)));
        if (empty($lines)) json_error('No wallets provided');
        if (count($lines) > 1000) json_error('Too many wallets in one request (max 1000). Please split into batches.');

        $rows = []; $invalid = 0;
        foreach ($lines as $line) {
            $parts = str_getcsv($line);
            if (empty($parts)) continue;

            // Every r-address on the line is a wallet (legacy comma-separated lists still work)
            $wallet_idxs = [];
            foreach ($parts as $idx => $val) {
                if (validate_xrpl_address(trim($val))) $wallet_idxs[] = $idx;
            }
            if (empty($wallet_idxs)) { $invalid++; continue; }

            if ($uses_quantity && count($wallet_idxs) === 1) {
                // Single wallet on the line: pair it with the nearest positive number.
                // Same expand-outward rule as upload_csv (v383) — tolerates empty columns.
                $wallet_idx = $wallet_idxs[0];
                $quantity = null;
                $max_dist = max($wallet_idx, count($parts) - 1 - $wallet_idx);
                for ($dist = 1; $dist <= $max_dist && $quantity === null; $dist++) {
                    foreach ([$wallet_idx - $dist, $wallet_idx + $dist] as $check_idx) {
                        if ($check_idx >= 0 && $check_idx < count($parts)) {
                            $candidate = trim($parts[$check_idx]);
                            if ($candidate !== '' && ctype_digit($candidate) && intval($candidate) > 0) {
                                $quantity = min(99999, intval($candidate));
                                break;
                            }
                        }
                    }
                }
                $rows[] = [trim($parts[$wallet_idx]), $quantity !== null ? $quantity : $default_quantity];
            } else {
                // Multiple wallets on one line = amount-less list; each takes the default.
                foreach ($wallet_idxs as $wi) {
                    $rows[] = [trim($parts[$wi]), $uses_quantity ? $default_quantity : null];
                }
            }
        }
        if (empty($rows)) json_error('No valid wallets provided');

        // v727: Holder Discount amounts are REQUIRED — a NULL amount is invisible to the
        // allocation engine (the exact silent corruption this version fixes), so reject
        // loudly with instructions instead of storing it.
        if ($al['type'] === 'discount_limited') {
            $missing = 0;
            foreach ($rows as $r) { if ($r[1] === null) $missing++; }
            if ($missing > 0) {
                json_error("Holder Discount needs an amount for every wallet ({$missing} missing). Put the number after each address (rWallet...,5) or set a default amount.");
            }
        }

        $now = current_time('mysql'); $added = 0; $updated = 0; $skipped = 0;
        foreach ($rows as $r) {
            list($w, $q) = $r;
            if ($q !== null) {
                // v727: repair path — re-adding a wallet UPDATES its amount. INSERT IGNORE
                // silently kept the old/NULL value and made broken lists unfixable.
                $res = $wpdb->query($wpdb->prepare(
                    "INSERT INTO $entries_table (allowlist_id, wallet_address, custom_max_mint, minted_count, created_at) VALUES (%d, %s, %d, 0, %s)
                     ON DUPLICATE KEY UPDATE custom_max_mint = VALUES(custom_max_mint)",
                    $allowlist_id, $w, $q, $now
                ));
                if ($res === 1) $added++; elseif ($res === 2) $updated++; else $skipped++;
            } else {
                $res = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $entries_table (allowlist_id, wallet_address, created_at) VALUES (%d, %s, %s)", $allowlist_id, $w, $now));
                if ($res > 0) $added++; else $skipped++;
            }
        }
        json_success(['added' => $added, 'updated' => $updated, 'skipped' => $skipped, 'invalid' => $invalid]);
    }
    
    if ($action === 'upload_csv') {
        $allowlist_id = intval($_POST['allowlist_id'] ?? 0);
        $artist_account = $imc_al_wallet;   // session-derived identity
        if (!$allowlist_id || !$artist_account) json_error('Missing required fields');
        
        $al = $wpdb->get_row($wpdb->prepare("SELECT a.* FROM $allowlists_table a JOIN $listings_table l ON a.listing_id = l.id WHERE a.id = %d AND l.artist_account = %s", $allowlist_id, $artist_account), ARRAY_A);
        if (!$al) json_error('Allowlist not found or not yours');
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) json_error('No CSV file uploaded');
        
        $content = file_get_contents($_FILES['csv_file']['tmp_name']);
        // v381: Split on NEWLINES only — preserves CSV column structure for quantity detection
        $lines = preg_split('/[\r\n]+/', $content);
        $lines = array_filter(array_map('trim', $lines));
        if (empty($lines)) json_error('File is empty');
        if (count($lines) > 5000) json_error('CSV too large (max 5000 rows). Please split into batches.');

        // v727: optional shared default — any row without its own amount gets this.
        $csv_default_quantity = null;
        if (isset($_POST['default_quantity']) && $_POST['default_quantity'] !== '') {
            $dq = intval($_POST['default_quantity']);
            if ($dq < 1 || $dq > 99999) json_error('Default amount must be between 1 and 99999');
            $csv_default_quantity = $dq;
        }
        
        // Skip header row: if no column contains an r-address, it's a header
        $first_parts = str_getcsv(reset($lines));
        $has_wallet_in_first = false;
        foreach ($first_parts as $fp) {
            if (validate_xrpl_address(trim($fp))) { $has_wallet_in_first = true; break; }
        }
        if (!$has_wallet_in_first) array_shift($lines);
        
        // v727: two-pass — parse every row first, enforce Holder Discount amounts, then
        // write. Quantity detection unchanged from v381/v383 (expand-outward from the
        // wallet column); default_quantity fills rows the CSV leaves amount-less.
        $uses_quantity = in_array($al['type'], ['limit_override', 'discount_limited']);
        $csv_rows = []; $invalid = 0; $any_quantity = false;
        foreach ($lines as $line) {
            $parts = str_getcsv($line);
            if (empty($parts)) continue;

            $wallet = null;
            $wallet_idx = null;
            $quantity = null;

            // Pass 1: Find the wallet column
            foreach ($parts as $idx => $val) {
                $val = trim($val);
                if (validate_xrpl_address($val)) {
                    $wallet = $val;
                    $wallet_idx = $idx;
                    break;
                }
            }

            if (!$wallet) { $invalid++; continue; }

            // Pass 2: Find quantity — only for types that use per-wallet limits
            if ($uses_quantity && count($parts) > 1) {
                // v383: Expand outward from wallet position — finds nearest numeric column
                // Handles CSVs with empty columns between wallet and quantity (e.g. Owner,,,,Total)
                $max_dist = max($wallet_idx, count($parts) - 1 - $wallet_idx);
                for ($dist = 1; $dist <= $max_dist && $quantity === null; $dist++) {
                    foreach ([$wallet_idx - $dist, $wallet_idx + $dist] as $check_idx) {
                        if ($check_idx >= 0 && $check_idx < count($parts)) {
                            $candidate = trim($parts[$check_idx]);
                            if ($candidate !== '' && ctype_digit($candidate) && intval($candidate) > 0) {
                                $quantity = min(99999, intval($candidate));
                                break;
                            }
                        }
                    }
                }
            }
            if ($quantity !== null) $any_quantity = true;
            if ($quantity === null && $uses_quantity) $quantity = $csv_default_quantity;
            $csv_rows[] = [$wallet, $quantity];
        }
        if (empty($csv_rows)) json_error('No valid wallets found in file');

        // v727: Holder Discount amounts are REQUIRED (see add_entries rationale).
        if ($al['type'] === 'discount_limited') {
            $missing = 0;
            foreach ($csv_rows as $r) { if ($r[1] === null) $missing++; }
            if ($missing > 0) {
                json_error("Holder Discount needs an amount for every wallet — {$missing} row(s) have none. Add a quantity column, or set a default amount.");
            }
        }

        $now = current_time('mysql'); $added = 0; $updated = 0; $skipped = 0;
        foreach ($csv_rows as $r) {
            list($wallet, $quantity) = $r;
            if ($quantity !== null) {
                // v727: repair path — re-uploading a snapshot UPDATES amounts (was INSERT
                // IGNORE, which kept old/NULL values and made broken lists unfixable).
                $result = $wpdb->query($wpdb->prepare(
                    "INSERT INTO $entries_table (allowlist_id, wallet_address, custom_max_mint, minted_count, created_at) VALUES (%d, %s, %d, 0, %s)
                     ON DUPLICATE KEY UPDATE custom_max_mint = VALUES(custom_max_mint)",
                    $allowlist_id, $wallet, $quantity, $now
                ));
                if ($result === 1) $added++; elseif ($result === 2) $updated++; else $skipped++;
            } else {
                $result = $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO $entries_table (allowlist_id, wallet_address, minted_count, created_at) VALUES (%d, %s, 0, %s)",
                    $allowlist_id, $wallet, $now
                ));
                if ($result > 0) $added++; else $skipped++;
            }
        }
        json_success(['added' => $added, 'updated' => $updated, 'skipped' => $skipped, 'invalid' => $invalid, 'has_quantities' => $any_quantity]);
    }
    
    if ($action === 'remove_entry') {
        $entry_id = intval($_POST['entry_id'] ?? 0);
        $artist_account = $imc_al_wallet;   // session-derived identity
        if (!$entry_id || !$artist_account) json_error('Missing required fields');
        
        $e = $wpdb->get_row($wpdb->prepare("SELECT e.id FROM $entries_table e JOIN $allowlists_table a ON e.allowlist_id = a.id JOIN $listings_table l ON a.listing_id = l.id WHERE e.id = %d AND l.artist_account = %s", $entry_id, $artist_account));
        if (!$e) json_error('Entry not found or not yours');
        
        $wpdb->delete($entries_table, ['id' => $entry_id]);
        json_success(['message' => 'Entry removed']);
    }
    
    if ($action === 'update') {
        $allowlist_id = intval($_POST['allowlist_id'] ?? 0);
        $artist_account = $imc_al_wallet;   // session-derived identity
        if (!$allowlist_id || !$artist_account) json_error('Missing required fields');
        
        $al = $wpdb->get_row($wpdb->prepare("SELECT a.* FROM $allowlists_table a JOIN $listings_table l ON a.listing_id = l.id WHERE a.id = %d AND l.artist_account = %s", $allowlist_id, $artist_account), ARRAY_A);
        if (!$al) json_error('Allowlist not found or not yours');
        
        $u = ['updated_at' => current_time('mysql')];
        if (isset($_POST['name'])) $u['name'] = sanitize_text_field($_POST['name']);
        if (isset($_POST['description'])) $u['description'] = sanitize_textarea_field($_POST['description']);
        if (isset($_POST['is_active'])) $u['is_active'] = intval($_POST['is_active']) ? 1 : 0;
        if (isset($_POST['custom_price'])) $u['custom_price'] = $_POST['custom_price'] !== '' ? floatval($_POST['custom_price']) : null;
        // v679: keep update in step with create.
        if (isset($_POST['discount_percent'])) $u['discount_percent'] = $_POST['discount_percent'] !== '' ? max(0, min(100, floatval($_POST['discount_percent']))) : 0;
        if (isset($_POST['early_access_hours'])) $u['early_access_hours'] = intval($_POST['early_access_hours']);
        if (isset($_POST['max_mint_override'])) $u['max_mint_override'] = $_POST['max_mint_override'] !== '' ? intval($_POST['max_mint_override']) : null;
        if (isset($_POST['allowlist_holder_limit'])) $u['allowlist_holder_limit'] = $_POST['allowlist_holder_limit'] !== '' ? max(0, intval($_POST['allowlist_holder_limit'])) : null;

        // v731 (A4b): per-token rules on update — with the free-claim guards in BOTH
        // directions: no rules on a free list, and no going free while rules exist.
        $imc_final_cp = array_key_exists('custom_price', $u) ? $u['custom_price'] : ($al['custom_price'] !== null ? floatval($al['custom_price']) : null);
        if (isset($_POST['currency_benefits'])) {
            if ($_POST['currency_benefits'] === '' || $_POST['currency_benefits'] === '[]') {
                $u['currency_benefits'] = null; // explicit clear
            } else {
                if ($imc_final_cp !== null && $imc_final_cp == 0) {
                    json_error('Free claim lists are free in every currency — remove per-token rules');
                }
                list($imc_cb_rules, $imc_cb_err) = imc_validate_currency_benefits($_POST['currency_benefits']);
                if ($imc_cb_err) json_error($imc_cb_err);
                $u['currency_benefits'] = $imc_cb_rules ? wp_json_encode($imc_cb_rules) : null;
            }
        } elseif ($imc_final_cp !== null && $imc_final_cp == 0 && !empty($al['currency_benefits'])) {
            $u['currency_benefits'] = null; // list going free — existing rules auto-clear
        }

        $wpdb->update($allowlists_table, $u, ['id' => $allowlist_id]);
        json_success(['message' => 'Updated']);
    }
    
    if ($action === 'delete') {
        $allowlist_id = intval($_POST['allowlist_id'] ?? 0);
        $artist_account = $imc_al_wallet;   // session-derived identity
        if (!$allowlist_id || !$artist_account) json_error('Missing required fields');
        
        $al = $wpdb->get_row($wpdb->prepare("SELECT a.id FROM $allowlists_table a JOIN $listings_table l ON a.listing_id = l.id WHERE a.id = %d AND l.artist_account = %s", $allowlist_id, $artist_account));
        if (!$al) json_error('Allowlist not found or not yours');
        
        $wpdb->delete($entries_table, ['allowlist_id' => $allowlist_id]);
        $wpdb->delete($allowlists_table, ['id' => $allowlist_id]);
        json_success(['message' => 'Deleted']);
    }
    
    // v727: 'increment_minted' REMOVED. It had zero callers (increments happen inside
    // mint-on-demand-handler.php at mint time) and was reachable with only the public
    // marketplace nonce — letting anyone inflate any wallet's minted_count and burn
    // other users' discount allocations.

    json_error('Unknown POST action');
}

json_error('Invalid request method', 405);