<?php
/**
 * ============================================================================
 * FILE: listings-handler.php
 * PATH: /wp-content/themes/astra/xrpl-nft-marketplace/backend/
 * ============================================================================
 * 
 * PHASE 2: NFT Listings (Lazy Minting / Mint-on-Demand)
 * 
 * PURPOSE:
 * Store NFT listings with all metadata BEFORE minting. NFTs only get minted
 * on-chain when someone actually purchases them. This is "lazy minting".
 * 
 * FLOW:
 * 1. Artist fills mint wizard (uploads files, enters metadata)
 * 2. Files uploaded to IPFS (cover, media, metadata JSON)
 * 3. Listing created in database with status='draft'
 * 4. Artist pays platform fee → status='active' (live on marketplace)
 * 5. Buyer purchases → NFT minted on-demand (Phase 3)
 * 
 * ENDPOINTS:
 * GET  ?action=get&id=123                     - Get single listing
 * GET  ?action=get_by_artist&account=rXXX    - Artist listings (public set; the owning session also sees drafts + revenue)
 * GET  ?action=get_marketplace               - Get active listings (public)
 * GET  ?action=calculate_fee&editions=5      - Calculate platform fee
 * POST action=create                         - Create new listing (draft)
 * POST action=publish                        - Publish after fee paid
 * POST action=update                         - Update listing (price, status)
 * 
 * @version 1.0.0
 */

// WordPress bootstrap
require_once dirname(__DIR__, 5) . '/wp-load.php';
if (!defined('ABSPATH')) exit;
define('LH_LOADED', true);
require_once __DIR__ . '/imc-trustline-manager.php';

// Headers
header('Content-Type: application/json; charset=utf-8');
$lh_allowed_origins = [
    'https://imcollectibles.xyz',
    'https://www.imcollectibles.xyz',
    'https://imcollectibles.io',
    'https://www.imcollectibles.io',
    'https://improtectors.com',
    'https://www.improtectors.com'
];
$lh_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . (in_array($lh_origin, $lh_allowed_origins) ? $lh_origin : $lh_allowed_origins[0]));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
// v67: Prevent caching to ensure paused listings show immediately
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ============================================================================
// CONFIGURATION
// ============================================================================

// Platform fee wallet (where listing fees go)
if (!defined('PLATFORM_FEE_WALLET')) {
    define('PLATFORM_FEE_WALLET', 'r3wwgY8rsG3Fa7JFj4mJoqxDr9RWwFtWDV');
}

// Fee structure — per content type
// v175/v365: Fee schedule per content type.
// Art:        Tiered flat fee by master count (≤100=2, ≤500=4, 500+=6 XRP) + 0.03 XRP per NFT
// Music:      2.5 XRP per master file + 0.06 XRP per NFT
// MusicVideo: 3.0 XRP per master file + 0.08 XRP per NFT
// Film:       15.0 XRP per master file + 0.10 XRP per NFT
if (!defined('LISTING_FEE_FLAT')) {
    define('LISTING_FEE_FLAT', 1.5);       // Default fallback (music)
}
if (!defined('LISTING_FEE_PER_EDITION')) {
    define('LISTING_FEE_PER_EDITION', 0.06); // Default fallback (music)
}
// v193: Maximum USD price for dynamic pricing — compliance + volatility protection
if (!defined('IMC_MAX_PRICE_USD')) {
    define('IMC_MAX_PRICE_USD', 5000);
}

function get_fee_for_type($nft_type = 'music') {
    $fees = [
        'art'        => ['flat' => 2.0,  'per_nft' => 0.03],  // flat used as base tier (≤100); tiered logic in calculate_platform_fee
        'music'      => ['flat' => 2.5,  'per_nft' => 0.06],
        'musicvideo' => ['flat' => 3.0,  'per_nft' => 0.08],
        'film'       => ['flat' => 15.0, 'per_nft' => 0.10],
        // v24: Album — flat=5.0 base; +1.0×track_count added in calculate_platform_fee
        'album'      => ['flat' => 5.0,  'per_nft' => 0.06],
        'audiobook'  => ['flat' => 2.5,  'per_nft' => 0.06],
        'ebook'      => ['flat' => 2.0,  'per_nft' => 0.03],
    ];
    return $fees[$nft_type] ?? $fees['music'];
}

/**
 * v365: Art tiered flat fee based on master file / tier count.
 * Replaces per-master multiplication for art only.
 */
function get_art_tiered_flat_fee($master_files) {
    if ($master_files <= 100) return 2.0;
    if ($master_files <= 500) return 4.0;
    return 6.0;
}

// Platform wallet (main funded wallet, signed by regular key)
if (!defined('IMC_PLATFORM_WALLET')) {
    define('IMC_PLATFORM_WALLET', 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR');
}

// XRPL RPC endpoint (for on-chain fee verification)
if (!defined('LH_XRPL_RPC')) {
    define('LH_XRPL_RPC', defined('XRPL_RPC') ? XRPL_RPC : (defined('IMU_XRPL_RPC') ? IMU_XRPL_RPC : 'https://xrplcluster.com'));
}

// Logging
$log_file = __DIR__ . '/logs/listings.log';
if (!file_exists(dirname($log_file))) @mkdir(dirname($log_file), 0755, true);

function listings_log($msg) {
    global $log_file;
    @file_put_contents($log_file, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
}

// ============================================================================
// ON-CHAIN FEE VERIFICATION (v179)
// Verifies listing fee payment on XRPL before publishing
// ============================================================================

/**
 * Query the XRPL via JSON-RPC
 */
function lh_xrpl_rpc($method, $params = []) {
    $body = json_encode([
        'method' => $method,
        'params' => [$params]
    ]);

    $ch = curl_init(LH_XRPL_RPC);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30
    ]);

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        listings_log("XRPL RPC curl error: $err");
        return ['error' => "Connection error: $err"];
    }

    $data = json_decode($resp, true);
    if (!$data || !isset($data['result'])) {
        listings_log("XRPL RPC bad response: " . substr($resp ?? '', 0, 500));
        return ['error' => 'Invalid XRPL response'];
    }

    return $data['result'];
}

/**
 * Verify a listing fee payment on the XRP Ledger
 *
 * Checks:
 * 1. Transaction exists and is validated (tesSUCCESS)
 * 2. TransactionType is Payment
 * 3. Destination is PLATFORM_FEE_WALLET
 * 4. Amount >= expected fee (XRP, with 1% tolerance)
 *
 * @param string $tx_hash       Transaction hash from Xaman
 * @param float  $expected_xrp  Expected fee amount in XRP
 * @return array ['verified' => bool, 'error' => string|null]
 */
function lh_verify_fee_payment($tx_hash, $expected_xrp) {
    if (empty($tx_hash) || strlen($tx_hash) < 10) {
        return ['verified' => false, 'error' => 'Invalid transaction hash'];
    }

    // XRPL transactions take 3-5 seconds to validate
    $max_attempts = 6;
    $tx = null;

    for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
        if ($attempt > 1) sleep(2);

        $tx = lh_xrpl_rpc('tx', ['transaction' => $tx_hash, 'binary' => false]);

        if (isset($tx['error'])) {
            listings_log("verify_fee: attempt $attempt/$max_attempts — tx not found: " . ($tx['error'] ?? 'unknown'));
            continue;
        }

        if ($tx['validated'] ?? false) {
            listings_log("verify_fee: tx validated on attempt $attempt");
            break;
        }

        listings_log("verify_fee: attempt $attempt — tx found but not yet validated");
    }

    if (!$tx || isset($tx['error'])) {
        return ['verified' => false, 'error' => 'Transaction not found on ledger'];
    }

    if (!($tx['validated'] ?? false)) {
        return ['verified' => false, 'error' => 'Transaction not validated — please retry'];
    }

    // Check engine result
    $meta_result = $tx['meta']['TransactionResult'] ?? '';
    if ($meta_result !== 'tesSUCCESS') {
        listings_log("verify_fee: tx failed: $meta_result");
        return ['verified' => false, 'error' => "Transaction failed: $meta_result"];
    }

    // Check TransactionType
    if (($tx['TransactionType'] ?? '') !== 'Payment') {
        listings_log("verify_fee: wrong tx type: " . ($tx['TransactionType'] ?? 'unknown'));
        return ['verified' => false, 'error' => 'Not a Payment transaction'];
    }

    // Check Destination = Platform Fee Wallet
    if (($tx['Destination'] ?? '') !== PLATFORM_FEE_WALLET) {
        listings_log("verify_fee: wrong dest: " . ($tx['Destination'] ?? 'none') . " expected: " . PLATFORM_FEE_WALLET);
        return ['verified' => false, 'error' => 'Payment destination mismatch'];
    }

    // Verify XRP amount (use delivered_amount for partial payment protection)
    $amount = $tx['meta']['delivered_amount'] ?? $tx['Amount'] ?? '0';
    if (!is_string($amount)) {
        return ['verified' => false, 'error' => 'Expected XRP payment but received token'];
    }

    $amount_drops = intval($amount);
    $expected_drops = intval(round($expected_xrp * 1000000));

    // 1% tolerance for rounding
    if ($amount_drops < ($expected_drops * 0.99)) {
        listings_log("verify_fee: insufficient: {$amount_drops} drops, expected {$expected_drops}");
        return ['verified' => false, 'error' => "Insufficient payment: received " . ($amount_drops / 1000000) . " XRP, expected $expected_xrp XRP"];
    }

    listings_log("verify_fee: VERIFIED tx=$tx_hash amount=" . ($amount_drops / 1000000) . " XRP (expected $expected_xrp)");
    return ['verified' => true, 'error' => null];
}

/**
 * v590 (additive): Poll a XUMM payload by uuid (mirrors mint-on-demand-handler poll_xumm).
 * Reads the same global XUMM_API_KEY/SECRET constants the rest of the platform uses.
 * Returns the decoded payload array, or null on any failure (caller treats null as skip).
 */
function lh_poll_xumm_payload($uuid) {
    $key    = defined('XUMM_API_KEY')    ? XUMM_API_KEY    : '';
    $secret = defined('XUMM_API_SECRET') ? XUMM_API_SECRET : '';
    if ($key === '' || $secret === '' || $uuid === '') return null;

    $ch = curl_init('https://xumm.app/api/v1/platform/payload/' . rawurlencode($uuid));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['X-API-Key: ' . $key, 'X-API-Secret: ' . $secret],
        CURLOPT_TIMEOUT        => 5,
    ]);
    $response = curl_exec($ch);
    $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200 || !$response) return null;
    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * v590 (additive): Server-side backstop for the listing-fee 'paid-but-draft' bug.
 *
 * Publication is normally triggered ONLY by the front-end (publishListing -> action=publish).
 * On mobile, if the artist's tab is abandoned after paying the fee (multi-tab, Xaman 'return'
 * button, or browser close), publishListing never fires and the listing is stranded in 'draft'
 * despite an on-chain fee payment. This runs when the artist's dashboard loads (get_by_artist)
 * -- exactly where the fee return_url lands -- and finishes publication independent of any
 * front-end tab. It mirrors the security of action=publish (replay-block + on-chain verify via
 * lh_verify_fee_payment) and never touches that frozen path. Strictly best-effort: bounded,
 * fast-failing, and every error is swallowed so it can never break the dashboard fetch.
 */
function lh_recover_paid_draft_listings($account) {
    global $wpdb, $listings_table;
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) return;

    // Bounded: only recent draft listings that actually carry a pending fee payload uuid.
    // Almost every dashboard load matches zero rows here (no XUMM calls, no slowdown).
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, platform_fee_uuid, platform_fee_amount
           FROM $listings_table
          WHERE artist_account = %s
            AND status = 'draft'
            AND platform_fee_paid = 0
            AND platform_fee_uuid IS NOT NULL
            AND platform_fee_uuid != ''
            AND created_at > (NOW() - INTERVAL 2 DAY)
          ORDER BY created_at DESC
          LIMIT 3",
        $account
    ), ARRAY_A);
    if (empty($rows)) return;

    foreach ($rows as $row) {
        $listing_id = intval($row['id']);
        $uuid       = $row['platform_fee_uuid'];

        $payload = lh_poll_xumm_payload($uuid);
        if (!is_array($payload)) continue; // XUMM unreachable -> retry next load

        $meta     = $payload['meta'] ?? [];
        $response = $payload['response'] ?? [];
        $signed   = !empty($meta['signed']);
        $resolved = !empty($meta['resolved']);
        $dead     = !empty($meta['cancelled']) || !empty($meta['expired']);

        // Definitively over without a signature -> stop re-polling a dead payload.
        if ($dead || ($resolved && !$signed)) {
            $wpdb->update($listings_table, ['platform_fee_uuid' => null], ['id' => $listing_id]);
            continue;
        }
        if (!$signed) continue; // still pending -> leave for next load

        $tx_hash    = $response['txid'] ?? '';
        $dispatched = $response['dispatched_result'] ?? '';
        if ($dispatched !== 'tesSUCCESS' || $tx_hash === '') continue;

        // --- Mirror action=publish security: replay-block + on-chain verify ---
        $existing_fee_use = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $listings_table
              WHERE platform_fee_tx = %s AND id != %d AND status != 'failed' LIMIT 1",
            $tx_hash, $listing_id
        ));
        if ($existing_fee_use) {
            listings_log("recover: fee tx=$tx_hash already used by listing=$existing_fee_use; clearing uuid on listing=$listing_id");
            $wpdb->update($listings_table, ['platform_fee_uuid' => null], ['id' => $listing_id]);
            continue;
        }

        $expected_fee = floatval($row['platform_fee_amount'] ?? 0);
        if ($expected_fee <= 0) continue; // cannot verify amount safely -> leave for front-end path

        $verify = lh_verify_fee_payment($tx_hash, $expected_fee);
        if (empty($verify['verified'])) {
            listings_log("recover: verify failed listing=$listing_id tx=$tx_hash err=" . ($verify['error'] ?? '?'));
            continue;
        }

        // Publish -- atomic guard so a concurrent front-end publish cannot double-apply.
        $now = current_time('mysql');
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE $listings_table
                SET platform_fee_paid = 1, platform_fee_tx = %s, status = 'active',
                    published_at = %s, updated_at = %s, platform_fee_uuid = NULL
              WHERE id = %d AND status = 'draft' AND platform_fee_paid = 0",
            $tx_hash, $now, $now, $listing_id
        ));
        if ($updated) { imc_ul_mark_paid($listing_id, $tx_hash); } // U3: stamp slots (recovery site)
        if ($updated) {
            listings_log("recover: PUBLISHED listing=$listing_id tx=$tx_hash (server-side backstop)");
        }
    }
}

/**
 * v609 (additive): server-side backstop for LOCAL-SIGNING (no-uuid) fee payments.
 *
 * The XUMM path stores platform_fee_uuid and is healed by lh_recover_paid_draft_listings().
 * Local-signing wallets (Joey / extensions) carry NO uuid, so that recovery can never see them
 * and the listing stays "paid but draft" forever. This scans the artist's recent ledger for an
 * UNUSED fee Payment matching the draft's expected amount, then publishes through the SAME guards
 * the front-end publish uses: lh_verify_fee_payment + replay-block + atomic UPDATE.
 *
 * Bounded & cheap: only runs when the artist actually has a recent no-uuid paid-but-draft row
 * (almost always zero -> no RPC, no slowdown). Never touches non-draft or already-paid rows.
 */
function lh_recover_paid_draft_onchain($account) {
    global $wpdb, $listings_table;
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) return;

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, platform_fee_amount
           FROM $listings_table
          WHERE artist_account = %s
            AND status = 'draft'
            AND platform_fee_paid = 0
            AND (platform_fee_uuid IS NULL OR platform_fee_uuid = '')
            AND platform_fee_amount > 0
            AND created_at > (NOW() - INTERVAL 2 DAY)
          ORDER BY created_at DESC
          LIMIT 3",
        $account
    ), ARRAY_A);
    if (empty($rows)) return;

    // One ledger fetch for the artist; reused across their (few) candidate drafts.
    $acct = lh_xrpl_rpc('account_tx', [
        'account'          => $account,
        'ledger_index_min' => -1,
        'ledger_index_max' => -1,
        'binary'           => false,
        'forward'          => false,
        'limit'            => 30,
    ]);
    if (!is_array($acct) || isset($acct['error']) || empty($acct['transactions'])) return;

    foreach ($rows as $row) {
        $listing_id     = intval($row['id']);
        $expected_xrp   = floatval($row['platform_fee_amount']);
        if ($expected_xrp <= 0) continue;
        $expected_drops = intval(round($expected_xrp * 1000000));

        foreach ($acct['transactions'] as $entry) {
            $tx   = is_array($entry['tx'] ?? null) ? $entry['tx'] : (is_array($entry['tx_json'] ?? null) ? $entry['tx_json'] : null);
            $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            if (!is_array($tx)) continue;
            if (($tx['TransactionType'] ?? '') !== 'Payment') continue;
            if (($tx['Account'] ?? '') !== $account) continue;                 // sent BY the artist
            if (($tx['Destination'] ?? '') !== PLATFORM_FEE_WALLET) continue;  // to the fee wallet
            if (($entry['validated'] ?? false) !== true) continue;
            if (($meta['TransactionResult'] ?? '') !== 'tesSUCCESS') continue;

            $delivered = $meta['delivered_amount'] ?? ($tx['Amount'] ?? null);
            if (!is_string($delivered)) continue;                              // string => XRP drops (token = object)
            if (intval($delivered) !== $expected_drops) continue;              // exact fee match (fees are fixed)

            $tx_hash = $tx['hash'] ?? ($entry['hash'] ?? '');
            if ($tx_hash === '' || strlen($tx_hash) < 10) continue;

            // Replay-block: this fee tx must not already be tied to another listing.
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $listings_table
                  WHERE platform_fee_tx = %s AND id != %d AND status != 'failed' LIMIT 1",
                $tx_hash, $listing_id
            ));
            if ($existing) continue;

            // Re-verify on-chain with the exact same checks as action=publish.
            $verify = lh_verify_fee_payment($tx_hash, $expected_xrp);
            if (empty($verify['verified'])) continue;

            // Atomic publish: the WHERE guard makes a concurrent front-end publish a no-op.
            $now = current_time('mysql');
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE $listings_table
                    SET platform_fee_paid = 1, platform_fee_tx = %s, status = 'active',
                        published_at = %s, updated_at = %s, platform_fee_uuid = NULL
                  WHERE id = %d AND status = 'draft' AND platform_fee_paid = 0",
                $tx_hash, $now, $now, $listing_id
            ));
            if ($updated) { imc_ul_mark_paid($listing_id, $tx_hash); } // U3: stamp slots (recovery site)
            if ($updated) {
                listings_log("recover-onchain: PUBLISHED listing=$listing_id tx=$tx_hash amount={$expected_xrp}XRP (local-signing backstop)");
                break; // this draft is done; move to the next candidate
            }
        }
    }
}

/**
 * v610 (additive): internal cron sweep. The inline recovery in get_by_artist only fires on
 * the artist's OWN dashboard load; this heals listings for artists who paid but never came
 * back. Reuses BOTH proven per-artist recovery paths -- no new publish logic. Bounded:
 * distinct artists with a recent paid-but-draft row, hard-capped per run. Returns the count.
 */
function lh_cron_recover_all_paid_drafts() {
    global $wpdb, $listings_table;

    $artists = $wpdb->get_col(
        "SELECT DISTINCT artist_account
           FROM $listings_table
          WHERE status = 'draft'
            AND platform_fee_paid = 0
            AND platform_fee_amount > 0
            AND created_at > (NOW() - INTERVAL 2 DAY)
          ORDER BY artist_account
          LIMIT 50"
    );
    if (empty($artists)) return 0;

    $n = 0;
    foreach ($artists as $account) {
        if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) continue;
        lh_recover_paid_draft_listings($account);  // XUMM-uuid path
        lh_recover_paid_draft_onchain($account);   // local-signing on-chain path
        if (++$n >= 50) break;                      // hard cap per run
    }
    listings_log("cron-recover: swept $n artist(s) with recent paid-but-draft listings");
    return $n;
}



// ============================================================================
// DATABASE TABLES
// ============================================================================

global $wpdb;
$listings_table = $wpdb->prefix . 'imc_listings';
$auth_table = $wpdb->prefix . 'imc_authorized_minters';

// Create listings table
$wpdb->query("
CREATE TABLE IF NOT EXISTS $listings_table (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    -- Artist info
    artist_account VARCHAR(35) NOT NULL,
    artist_name VARCHAR(200) DEFAULT NULL,
    
    -- NFT type and basic info
    nft_type ENUM('music','musicvideo','art','film','album','ebook','audiobook') NOT NULL DEFAULT 'music',
    nft_name VARCHAR(200) NOT NULL,
    description TEXT,
    
    -- IPFS URIs (pinned when listing created)
    cover_ipfs VARCHAR(128) NOT NULL,
    media_ipfs VARCHAR(128) NOT NULL,       -- Preview CID (public, ≤29s)
    preview_ipfs VARCHAR(128) DEFAULT NULL,  -- Explicit preview field
    metadata_ipfs VARCHAR(128) NOT NULL,
    
    -- Master audio
    master_content_hash VARCHAR(80) DEFAULT NULL,  -- sha256:hex
    cover_content_hash VARCHAR(80) DEFAULT NULL,   -- v377: sha256:hex of original cover (Music/MV/Film watermarked)
    
    -- Full metadata JSON (XLS-24d compliant)
    metadata_json LONGTEXT,
    
    -- Collection settings
    collection_name VARCHAR(128) DEFAULT '',
    collection_taxon INT UNSIGNED DEFAULT 0,
    
    -- Pricing (in XRP)
    price_xrp DECIMAL(20,6) NOT NULL DEFAULT 0,
    
    -- Editions
    total_editions INT UNSIGNED NOT NULL DEFAULT 1,
    minted_count INT UNSIGNED NOT NULL DEFAULT 0,
    
    -- Royalties (basis points: 5000 = 5%)
    transfer_fee INT UNSIGNED NOT NULL DEFAULT 5000,
    
    -- Flags
    is_transferable TINYINT(1) NOT NULL DEFAULT 1,
    is_burnable TINYINT(1) NOT NULL DEFAULT 0,
    downloadable TINYINT(1) NOT NULL DEFAULT 0,
    
    -- Status: draft (not paid), active (live), paused, sold_out, cancelled
    status ENUM('draft','active','paused','sold_out','cancelled') NOT NULL DEFAULT 'draft',
    
    -- Launch scheduling
    launch_type ENUM('immediate','scheduled') NOT NULL DEFAULT 'immediate',
    launch_at DATETIME DEFAULT NULL,
    launch_timezone VARCHAR(50) DEFAULT 'UTC',
    
    -- Platform fee tracking
    platform_fee_amount DECIMAL(20,6) NOT NULL DEFAULT 0,
    platform_fee_paid TINYINT(1) NOT NULL DEFAULT 0,
    platform_fee_tx VARCHAR(64) DEFAULT NULL,
    
    -- Timestamps
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    published_at DATETIME DEFAULT NULL,
    
    -- Indexes
    INDEX idx_artist (artist_account),
    INDEX idx_status (status),
    INDEX idx_type (nft_type),
    INDEX idx_published (published_at),
    INDEX idx_collection (collection_taxon)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ============================================================================
// LISTING TIERS TABLE (Rarity / Multi-Cover System)
// ============================================================================
$tiers_table = $wpdb->prefix . 'imc_listing_tiers';
$wpdb->query("
CREATE TABLE IF NOT EXISTS $tiers_table (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    listing_id      BIGINT UNSIGNED NOT NULL,
    
    -- Tier identity
    tier_name       VARCHAR(100) NOT NULL,
    tier_order      TINYINT UNSIGNED DEFAULT 0,
    
    -- Artwork (required per tier)
    cover_ipfs      VARCHAR(128) NOT NULL,
    
    -- Media overrides (NULL = inherit from listing defaults)
    preview_ipfs    VARCHAR(128) DEFAULT NULL,
    master_content_hash VARCHAR(80) DEFAULT NULL,
    cover_content_hash VARCHAR(80) DEFAULT NULL,  -- v377: sha256:hex of original cover (watermarked Music/MV/Film)
    
    -- Editions for THIS tier
    total_editions  INT UNSIGNED NOT NULL DEFAULT 1,
    minted_count    INT UNSIGNED NOT NULL DEFAULT 0,
    
    -- Tier-specific traits (JSON array of {trait_type, value})
    tier_traits     JSON DEFAULT NULL,
    
    created_at      DATETIME NOT NULL,
    
    INDEX idx_listing    (listing_id),
    INDEX idx_available  (listing_id, minted_count, total_editions)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ============================================================================
// SCHEMA MIGRATION — Add columns missing from older installations.
// CREATE TABLE IF NOT EXISTS won't alter existing tables, so we do it here.
// Each ALTER is guarded by a column-existence check (safe to run repeatedly).
// ============================================================================
$existing_cols = $wpdb->get_col("DESCRIBE {$listings_table}", 0);
if (is_array($existing_cols) && count($existing_cols) > 0) {
    $migrations = [
        'preview_ipfs'        => "ADD COLUMN preview_ipfs VARCHAR(128) DEFAULT NULL AFTER media_ipfs",
        'master_content_hash' => "ADD COLUMN master_content_hash VARCHAR(80) DEFAULT NULL AFTER metadata_ipfs",
        // v377: Cover art watermark protection hash (Music/MV/Film flows)
        'cover_content_hash'  => "ADD COLUMN cover_content_hash VARCHAR(80) DEFAULT NULL AFTER master_content_hash",
        'progressive_json'    => "ADD COLUMN progressive_json TEXT DEFAULT NULL AFTER cover_content_hash", // PP-0: progressive pricing config (inert until PP-1)
        'collection_taxon'    => "ADD COLUMN collection_taxon INT UNSIGNED DEFAULT 0 AFTER collection_name",
        'launch_type'         => "ADD COLUMN launch_type ENUM('immediate','scheduled') NOT NULL DEFAULT 'immediate' AFTER status",
        'launch_at'           => "ADD COLUMN launch_at DATETIME DEFAULT NULL AFTER launch_type",
        'launch_timezone'     => "ADD COLUMN launch_timezone VARCHAR(50) DEFAULT 'UTC' AFTER launch_at",
        'platform_fee_tx'     => "ADD COLUMN platform_fee_tx VARCHAR(64) DEFAULT NULL AFTER platform_fee_paid",
        'platform_fee_uuid'   => "ADD COLUMN platform_fee_uuid VARCHAR(64) DEFAULT NULL AFTER platform_fee_tx", // v590: fee payload uuid for server-side publish recovery
        'has_tiers'           => "ADD COLUMN has_tiers TINYINT(1) NOT NULL DEFAULT 0 AFTER downloadable",
        // v71: Multi-currency support (LONGTEXT for MariaDB compatibility)
        'accepted_currencies' => "ADD COLUMN accepted_currencies LONGTEXT DEFAULT NULL AFTER price_xrp",
        // v73: Dynamic pricing support
        'pricing_mode'        => "ADD COLUMN pricing_mode ENUM('static','dynamic','pwyw','free') NOT NULL DEFAULT 'static' AFTER price_xrp",
        'price_usd'           => "ADD COLUMN price_usd DECIMAL(10,2) DEFAULT NULL AFTER pricing_mode",
        // v73: Global mint limit per wallet
        'mint_limit_enabled'     => "ADD COLUMN mint_limit_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER downloadable",
        'mint_limit_per_wallet'  => "ADD COLUMN mint_limit_per_wallet INT UNSIGNED DEFAULT 0 AFTER mint_limit_enabled",
        // OE-v1: Open Edition columns (exact spec names)
        'edition_type'              => "ADD COLUMN edition_type ENUM('fixed','open') NOT NULL DEFAULT 'fixed' AFTER mint_limit_per_wallet",
        'open_edition_ends_at'      => "ADD COLUMN open_edition_ends_at DATETIME DEFAULT NULL AFTER edition_type",
        'open_edition_duration_days'=> "ADD COLUMN open_edition_duration_days DECIMAL(5,2) DEFAULT NULL AFTER open_edition_ends_at",
        'open_edition_closed_at'    => "ADD COLUMN open_edition_closed_at DATETIME DEFAULT NULL AFTER open_edition_duration_days",
        'primary_mint_fee_pct'      => "ADD COLUMN primary_mint_fee_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER platform_fee_tx",
        // v24: Album Access NFTs — per-track master/preview hash array stored as JSON
        'album_tracks_json'         => "ADD COLUMN album_tracks_json LONGTEXT DEFAULT NULL AFTER metadata_json",
        // eBook / AudioBook mint paths (M1-a)
        'ebook_format'              => "ADD COLUMN ebook_format ENUM('comic','short','novel','magazine') DEFAULT NULL AFTER album_tracks_json",
        'audiobook_format'          => "ADD COLUMN audiobook_format ENUM('single','chaptered') DEFAULT NULL AFTER album_tracks_json",
        'ebook_pages_json'          => "ADD COLUMN ebook_pages_json LONGTEXT DEFAULT NULL AFTER album_tracks_json",
        'audiobook_chapters_json'   => "ADD COLUMN audiobook_chapters_json LONGTEXT DEFAULT NULL AFTER album_tracks_json",
        'back_cover_ipfs'           => "ADD COLUMN back_cover_ipfs VARCHAR(128) DEFAULT NULL AFTER cover_ipfs",
        'back_cover_content_hash'   => "ADD COLUMN back_cover_content_hash VARCHAR(80) DEFAULT NULL AFTER cover_content_hash",
        'blurb'                     => "ADD COLUMN blurb TEXT DEFAULT NULL AFTER description",
        // v623: AI Art tagging — queryable, indexed flags promoted out of metadata_json.
        // ai_generated drives carousel routing; ai_mode/ai_platform drive the badge popup copy.
        'ai_generated'              => "ADD COLUMN ai_generated TINYINT(1) NOT NULL DEFAULT 0 AFTER album_tracks_json",
        'ai_mode'                   => "ADD COLUMN ai_mode ENUM('full','assisted') DEFAULT NULL AFTER ai_generated",
        'ai_platform'               => "ADD COLUMN ai_platform VARCHAR(120) DEFAULT NULL AFTER ai_mode",
        // v680: MODERATION / TAKEDOWN VISIBILITY.
        // Deliberately separate from `status`, which is load-bearing for mint logic
        // (active/sold_out/paused/draft/cancelled all drive purchase behaviour). Overloading it
        // for moderation would couple "can this be minted" to "should this be shown". A single
        // flag gives every public query one predicate to add, and is reusable for the next
        // takedown rather than a one-off patch.
        //   is_hidden     0 = normal (default -> nothing changes on deploy), 1 = hidden from public surfaces
        //   hidden_reason short code, e.g. 'ip_takedown', 'dmca', 'policy'
        //   hidden_at     when it was hidden (enforcement trail for DMCA safe harbour)
        'is_hidden'                 => "ADD COLUMN is_hidden TINYINT(1) NOT NULL DEFAULT 0 AFTER status",
        'hidden_reason'             => "ADD COLUMN hidden_reason VARCHAR(64) DEFAULT NULL AFTER is_hidden",
        'hidden_at'                 => "ADD COLUMN hidden_at DATETIME DEFAULT NULL AFTER hidden_reason",
        // v684: LEADERBOARD OPT-OUT (creator request).
        // Separate from is_hidden on purpose: is_hidden means "moderation removed this", and
        // pulls the listing from browse, mint and direct URLs. This one only removes it from
        // the public RANKINGS — the listing stays live, mintable and browsable, and it still
        // counts toward platform totals (those are factual history, not a ranking).
        // Set manually on request:
        //   one listing    -> WHERE id = %d
        //   one collection -> WHERE artist_account = %s AND collection_taxon = %d
        'hide_from_leaderboard'     => "ADD COLUMN hide_from_leaderboard TINYINT(1) NOT NULL DEFAULT 0 AFTER hidden_at",
    ];
    $ran_migration = false;
    foreach ($migrations as $col => $alter_sql) {
        if (!in_array($col, $existing_cols)) {
            $wpdb->query("ALTER TABLE {$listings_table} {$alter_sql}");
            $ran_migration = true;
            listings_log("Migration: added column {$col} to {$listings_table}");
        }
    }
    // Flush WordPress internal column metadata cache so wpdb->insert() sees new columns
    if ($ran_migration) {
        $wpdb->flush();
        listings_log("Migration complete — flushed wpdb column cache");
    }
    
    // M1-a: per-wallet reading progress (session-bound; read-path only)
    $progress_table = $wpdb->prefix . 'imc_reading_progress';
    $wpdb->query("CREATE TABLE IF NOT EXISTS {$progress_table} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        wallet VARCHAR(64) NOT NULL,
        nftoken_id VARCHAR(64) NOT NULL,
        position_json TEXT NOT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY wallet_nft (wallet, nftoken_id),
        KEY wallet (wallet)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci");
    
    // v135: Expand nft_type ENUM to include 'art' and 'film'
    // v24:  Expand further to include 'album'
    $current_type = $wpdb->get_row("SHOW COLUMNS FROM {$listings_table} LIKE 'nft_type'");
    if ($current_type && strpos($current_type->Type, 'album') === false) {
        $wpdb->query("ALTER TABLE {$listings_table} MODIFY COLUMN nft_type ENUM('music','musicvideo','art','film','album') NOT NULL DEFAULT 'music'");
        listings_log("Migration: expanded nft_type ENUM to include album");
    }
    // eBook/AudioBook: expand nft_type ENUM. Idempotent.
    $current_type_eb = $wpdb->get_row("SHOW COLUMNS FROM {$listings_table} LIKE 'nft_type'");
    if ($current_type_eb && strpos($current_type_eb->Type, 'ebook') === false) {
        $wpdb->query("ALTER TABLE {$listings_table} MODIFY COLUMN nft_type ENUM('music','musicvideo','art','film','album','ebook','audiobook') NOT NULL DEFAULT 'music'");
        listings_log("Migration: expanded nft_type ENUM to include ebook + audiobook");
    }

    // v660: Expand pricing_mode ENUM to include 'pwyw' (Pay What You Want). Idempotent —
    // only MODIFYs when 'pwyw' is not already present. Mirrors the nft_type expansion above.
    $current_pm = $wpdb->get_row("SHOW COLUMNS FROM {$listings_table} LIKE 'pricing_mode'");
    if ($current_pm && strpos($current_pm->Type, 'pwyw') === false) {
        $wpdb->query("ALTER TABLE {$listings_table} MODIFY COLUMN pricing_mode ENUM('static','dynamic','pwyw') NOT NULL DEFAULT 'static'");
        listings_log("v660 Migration: expanded pricing_mode ENUM to include pwyw");
    }

    // v664: Expand pricing_mode ENUM to include 'free' (explicit Free Mint). Idempotent —
    // only MODIFYs when 'free' is not already present, and lists all four values so it also
    // covers a database that never received the v660 pwyw expansion.
    $current_pm_free = $wpdb->get_row("SHOW COLUMNS FROM {$listings_table} LIKE 'pricing_mode'");
    if ($current_pm_free && strpos($current_pm_free->Type, "'free'") === false) {
        $wpdb->query("ALTER TABLE {$listings_table} MODIFY COLUMN pricing_mode ENUM('static','dynamic','pwyw','free') NOT NULL DEFAULT 'static'");
        listings_log("v664 Migration: expanded pricing_mode ENUM to include free");
    }

    // OE-v1: Index for efficient cron expiry + activation queries
    $indexes = $wpdb->get_results("SHOW INDEX FROM {$listings_table}", ARRAY_A);
    $index_names = array_column($indexes, 'Key_name');
    if (!in_array('idx_oe_ends', $index_names)) {
        $wpdb->query("ALTER TABLE {$listings_table} ADD INDEX idx_oe_ends (edition_type, open_edition_ends_at)");
        listings_log("Migration: added idx_oe_ends index");
    }
    // v623: composite index for AI Art carousel filtering (WHERE nft_type=? AND ai_generated=?)
    if (!in_array('idx_ai', $index_names)) {
        $wpdb->query("ALTER TABLE {$listings_table} ADD INDEX idx_ai (nft_type, ai_generated)");
        listings_log("v623 Migration: added idx_ai index");
    }
    // v680: every public listing query becomes "... AND is_hidden = 0" alongside its existing
    // status filter, so index the pair.
    if (!in_array('idx_hidden', $index_names)) {
        $wpdb->query("ALTER TABLE {$listings_table} ADD INDEX idx_hidden (is_hidden, status)");
        listings_log("v680 Migration: added idx_hidden index");
    }
    // v684: the leaderboard joins listings and filters on this flag alone.
    if (!in_array('idx_leaderboard', $index_names)) {
        $wpdb->query("ALTER TABLE {$listings_table} ADD INDEX idx_leaderboard (hide_from_leaderboard)");
        listings_log("v684 Migration: added idx_leaderboard index");
    }
}

// v377: TIERS TABLE MIGRATION — Add cover_content_hash for watermarked Music/MV/Film covers
$tiers_cols = $wpdb->get_col("DESCRIBE {$tiers_table}", 0);
if (is_array($tiers_cols) && count($tiers_cols) > 0) {
    if (!in_array('cover_content_hash', $tiers_cols)) {
        $wpdb->query("ALTER TABLE {$tiers_table} ADD COLUMN cover_content_hash VARCHAR(80) DEFAULT NULL AFTER master_content_hash");
        $wpdb->flush();
        listings_log("v377 Migration: added cover_content_hash to tiers table");
    }
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

function json_response($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error($msg, $code = 400) {
    json_response(['success' => false, 'error' => $msg], $code);
}

function json_success($data) {
    json_response(['success' => true, 'data' => $data]);
}

/**
 * v624: Derive AI-Art flags from a listing's metadata_json.
 * Single detection rule shared by create-time tagging and the ai_backfill_art action.
 * Returns [int ai_generated, ?string ai_mode ('full'|'assisted'), ?string ai_platform].
 * Pure/read-only: no DB, no side effects. Mirrors the backfill logic exactly.
 */
if (!function_exists('imc_derive_ai_flags')) {
    function imc_derive_ai_flags($metadata_json) {
        $gen = 0; $mode = null; $platform = null;
        $md = json_decode((string)$metadata_json, true);
        $disc = null;
        if (is_array($md)) {
            if (isset($md['ai_disclosure']) && is_array($md['ai_disclosure'])) {
                $disc = $md['ai_disclosure'];
            } elseif (isset($md['art']['ai_disclosure']) && is_array($md['art']['ai_disclosure'])) {
                $disc = $md['art']['ai_disclosure'];
            }
        }
        if (is_array($disc) && !empty($disc['ai_used'])) {
            $gen = 1;
            $plat = isset($disc['platform']) ? trim((string)$disc['platform']) : '';
            if ($plat !== '') {
                $platform = function_exists('mb_substr') ? mb_substr($plat, 0, 120) : substr($plat, 0, 120);
            }
            $ai_el = (isset($disc['ai_created_elements']) && is_array($disc['ai_created_elements'])) ? $disc['ai_created_elements'] : [];
            $hu_el = (isset($disc['human_created_elements']) && is_array($disc['human_created_elements'])) ? $disc['human_created_elements'] : [];
            $mode = (in_array('full_artwork', $ai_el, true) || empty($hu_el)) ? 'full' : 'assisted';
        }
        return [$gen, $mode, $platform];
    }
}

/**
 * Check if artist is authorized with IMCollectibles
 */
function is_artist_authorized($account) {
    global $wpdb, $auth_table;
    
    $status = $wpdb->get_var($wpdb->prepare(
        "SELECT status FROM $auth_table WHERE artist_account = %s",
        $account
    ));
    
    return $status === 'active';
}

/**
 * Generate deterministic taxon from artist + collection name (fallback only)
 * Unified formula: crc32(account|lowercase_name) & 0xFFFFFFFF
 * Prefer client-provided taxon from wp_imc_collections table.
 */
function generate_collection_taxon($artist, $collection_name) {
    $collection = strtolower(trim($collection_name ?: 'default'));
    $hash = crc32($artist . '|' . $collection);
    return $hash & 0xFFFFFFFF; // Unsigned 32-bit
}

/**
 * Calculate platform fee for listing.
 * OE-v1: Open editions use 3× flat master file fee — no per-NFT fee (unlimited supply).
 *         primary_mint_fee_pct is stored as 0.00 now; activates post Batch TX.
 * v365:  Art uses tiered flat fee (≤100=2, ≤500=4, 500+=6 XRP) instead of per-master multiplication.
 */
/**
 * PP-0 (Progressive Pricing, phase 0): STRUCTURAL validation of the creator's progressive
 * config. Stored inert on the listing; NOTHING reads it until PP-1's resolver ships.
 * Shape: {enabled, scope:'together'|'individual', increments:{CUR:float>0}, cap?:{CUR:float>0}}
 * Rules: static pricing only (R6/R7 exclude pwyw/free; R5 extends to dynamic in PP-5);
 * increment currencies must be ones the listing accepts; scope required when multi-currency.
 * Economic sanity (cap vs base) is enforced at PP-3 (UI) + PP-1 (runtime min()) — not here.
 */
/**
 * P5: MIRRORS of the two tiny PP-1 helpers from mint-on-demand-handler.php (separate
 * endpoint -- not includable). The step source law: verified-paid GROUPS, never
 * minted_count (the edition allocator). Keep in lockstep with the originals.
 */
function lh_pp_step($listing_id, $currency = null) {
    global $wpdb;
    $g = $wpdb->prefix . 'imc_purchase_groups';
    if ($currency === null) {
        return intval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(quantity),0) FROM $g WHERE listing_id = %d AND payment_verified = 1",
            $listing_id
        )));
    }
    // R1-scope fix (Aug 2026): per-currency step for 'individual' scope — mirrors the
    // engine's imc_pp_step() exactly. Without this, the P5 rebase anchored every
    // currency at the LISTING-WIDE step, so an individually-scoped multi-currency
    // listing with cross-currency mints could rebase BELOW current (invariant break).
    return intval($wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(quantity),0) FROM $g WHERE listing_id = %d AND payment_verified = 1 AND payment_currency = %s",
        $listing_id, strtoupper($currency)
    )));
}
function lh_pp_unit($base, $inc, $step, $cap) {
    $p = floatval($base) + floatval($inc) * intval($step);
    if ($cap !== null && $p > floatval($cap)) $p = floatval($cap);
    return $p;
}

function imc_validate_progressive_config($raw, $accepted_currencies_json, $pricing_mode) {
    if ($raw === null || $raw === '') return ['ok' => true, 'json' => null];
    $cfg = json_decode(wp_unslash($raw), true);
    if (!is_array($cfg)) return ['ok' => false, 'error' => 'Invalid progressive pricing configuration'];
    if (empty($cfg['enabled'])) return ['ok' => true, 'json' => null]; // disabled = stored as absent
    if ($pricing_mode === 'dynamic') {
        // PP-5 (R5): dynamic carries exactly ONE increment -- USD -- stepped BEFORE the
        // oracle converts. Scope is 'together' by construction (one USD figure).
        $inc_n = [];
        foreach (($cfg['increments'] ?? []) as $k => $v) { $inc_n[strtoupper(sanitize_text_field($k))] = floatval($v); }
        if (count($inc_n) !== 1 || !isset($inc_n['USD'])) return ['ok' => false, 'error' => 'Dynamic progressive pricing uses a single USD increment'];
        if ($inc_n['USD'] <= 0) return ['ok' => false, 'error' => 'Progressive increments must be greater than zero'];
        $clean = ['enabled' => true, 'scope' => 'together', 'increments' => ['USD' => $inc_n['USD']]];
        if (!empty($cfg['cap']) && is_array($cfg['cap'])) {
            $cap_n = [];
            foreach ($cfg['cap'] as $k => $v) { $cap_n[strtoupper(sanitize_text_field($k))] = floatval($v); }
            if (count(array_diff_key($cap_n, ['USD' => 1])) > 0) return ['ok' => false, 'error' => 'Dynamic progressive caps are USD only'];
            if (isset($cap_n['USD'])) {
                if ($cap_n['USD'] <= 0) return ['ok' => false, 'error' => 'Price caps must be greater than zero'];
                $clean['cap'] = ['USD' => $cap_n['USD']];
            }
        }
        return ['ok' => true, 'json' => wp_json_encode($clean)];
    }
    if ($pricing_mode !== 'static') return ['ok' => false, 'error' => 'Progressive pricing is currently available for standard pricing only'];
    $allowed = ['XRP'];
    $acc = (!empty($accepted_currencies_json)) ? json_decode($accepted_currencies_json, true) : [];
    if (is_array($acc)) foreach ($acc as $c) {
        if (!empty($c['currency']))     $allowed[] = strtoupper($c['currency']);
        if (!empty($c['currency_hex'])) $allowed[] = strtoupper($c['currency_hex']);
    }
    $inc = $cfg['increments'] ?? null;
    if (!is_array($inc) || count($inc) === 0) return ['ok' => false, 'error' => 'Progressive pricing needs at least one increment'];
    $clean_inc = [];
    foreach ($inc as $cur => $v) {
        $cur = strtoupper(sanitize_text_field($cur)); $v = floatval($v);
        if (!in_array($cur, $allowed, true)) return ['ok' => false, 'error' => "Progressive increment set for a currency this listing does not accept ($cur)"];
        if ($v <= 0) return ['ok' => false, 'error' => 'Progressive increments must be greater than zero'];
        $clean_inc[$cur] = $v;
    }
    $scope = $cfg['scope'] ?? (count($clean_inc) > 1 ? null : 'together');
    if (!in_array($scope, ['together', 'individual'], true)) return ['ok' => false, 'error' => 'Choose how prices progress: together or individually'];
    $clean = ['enabled' => true, 'scope' => $scope, 'increments' => $clean_inc];
    if (!empty($cfg['cap']) && is_array($cfg['cap'])) {
        $cc = [];
        foreach ($cfg['cap'] as $cur => $v) {
            $cur = strtoupper(sanitize_text_field($cur)); $v = floatval($v);
            if (!isset($clean_inc[$cur])) return ['ok' => false, 'error' => "Price cap set for a currency without an increment ($cur)"];
            if ($v <= 0) return ['ok' => false, 'error' => 'Price caps must be greater than zero'];
            $cc[$cur] = $v;
        }
        if ($cc) $clean['cap'] = $cc;
    }
    return ['ok' => true, 'json' => wp_json_encode($clean)];
}

function calculate_platform_fee($editions = 1, $nft_type = 'music', $master_files = 1, $edition_type = 'fixed') {
    $editions     = max(1, intval($editions));
    $master_files = max(1, intval($master_files));
    $fee = get_fee_for_type($nft_type);

    // v365: Art uses tiered flat fee — not per-master multiplication
    if ($nft_type === 'art') {
        $flat = get_art_tiered_flat_fee($master_files);
        if ($edition_type === 'open') {
            return get_art_tiered_flat_fee(1) * 1.25; // OE (v637): single-master (tiers disabled for OE) → base tier × 1.25, zero per-NFT
        }
        return $flat + ($fee['per_nft'] * $editions);
    }

    // v24: Album — base fee = flat(10) + 1.0 × master_files(=track_count)
    // master_files is set to track_count in action=create (#40) and calculate_fee (#36).
    if ($nft_type === 'album') {
        $base = $fee['flat'] + (1.0 * $master_files);
        if ($edition_type === 'open') {
            return ($fee['flat'] * 1.25) + (1.0 * $master_files); // OE (v637): 1.25× base fee only; per-track ×1 not multiplied; zero per-NFT
        }
        return $base + ($fee['per_nft'] * $editions);
    }

    // Music / MusicVideo / Film — unchanged
    if ($edition_type === 'open') {
        // OE (v637): single-master (tiers disabled for OE) → 1.25× flat, no per-master multiplication, zero per-NFT
        return $fee['flat'] * 1.25;
    }
    // Fixed Edition: flat per master file + per-NFT × edition count
    return ($fee['flat'] * $master_files) + ($fee['per_nft'] * $editions);
}

// ============================================================================
// FREE MINT PROMO (Aug + Sep 2026) -- per-creator, per-type monthly quotas.
// Eligible creates are FEE-WAIVED and AUTO-PUBLISHED at create (status active,
// platform_fee_paid=1, platform_fee_tx='PROMO-{id}' -- unique, so the v248
// replay guard, the mint gate, the recovery crons and the publish action all
// work UNCHANGED; waived listings never enter any of those lanes).
// Quota source of truth = COUNT of this month's PROMO-marked rows (derived,
// no counter to drift). Server-authoritative: the client only displays.
// Config: in-file defaults below; wp-config overrides win (edit quotas/months
// with no code deploy). Kill switch: define('IMC_FREE_MINT_PROMO', false).
// ============================================================================
if (!defined('IMC_FREE_MINT_PROMO')) define('IMC_FREE_MINT_PROMO', true);

function imc_fm_months() {
    return defined('IMC_FREE_MINT_MONTHS') ? (array) IMC_FREE_MINT_MONTHS : ['2026-08', '2026-09'];
}
function imc_fm_quotas() {
    return defined('IMC_FREE_MINT_QUOTAS') ? (array) IMC_FREE_MINT_QUOTAS
        : ['art' => 4, 'music' => 2, 'musicvideo' => 2, 'film' => 1, 'album' => 1, 'ebook' => 1, 'audiobook' => 1];
}
function imc_fm_active($month = null) {
    if (!IMC_FREE_MINT_PROMO) return false;
    $m = $month ?: current_time('Y-m');
    return in_array($m, imc_fm_months(), true);
}
function imc_fm_used($artist, $nft_type, $month = null) {
    global $wpdb;
    $t = $wpdb->prefix . 'imc_listings';
    $m = $month ?: current_time('Y-m');
    return intval($wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $t WHERE artist_account = %s AND nft_type = %s
           AND platform_fee_tx LIKE 'PROMO-%%' AND DATE_FORMAT(created_at, '%%Y-%%m') = %s",
        $artist, $nft_type, $m
    )));
}
function imc_fm_remaining($artist, $nft_type, $month = null) {
    if (!imc_fm_active($month)) return 0;
    $q = imc_fm_quotas();
    if (empty($q[$nft_type])) return 0; // type not in the promo
    return max(0, intval($q[$nft_type]) - imc_fm_used($artist, $nft_type, $month));
}
// ============================================================================
// M1-e2a: BOOK MANIFEST VALIDATION (AudioBook chapters / eBook pages)
// The manifest is the creator's book: a pool of stored files plus one or more
// versions that order them. Stored as JSON in audiobook_chapters_json /
// ebook_pages_json. Anything malformed is rejected rather than stored, so the
// reader never has to defend against junk. Version keys exist from day one so
// tiers (T-phase) need no migration.
// ============================================================================
function imc_validate_book_manifest($arr) {
    if (!is_array($arr)) return null;
    $MAX_POOL = 1100; $MAX_PAGES = 1000; $MAX_CHAPTERS = 500; $MAX_VERSIONS = 10;
    if (intval($arr['v'] ?? 0) !== 1) return null;
    $pool = $arr['pool'] ?? [];
    if (!is_array($pool) || count($pool) > $MAX_POOL) return null;
    $out_pool = []; $refs = [];
    foreach ($pool as $item) {
        if (!is_array($item)) return null;
        $ref  = sanitize_text_field($item['ref'] ?? '');
        $hash = sanitize_text_field($item['hash'] ?? '');
        $kind = sanitize_text_field($item['kind'] ?? '');
        if ($ref === '' || strlen($ref) > 64) return null;
        if (!preg_match('/^sha256:[a-f0-9]{64}$/', $hash)) return null;
        if (!in_array($kind, ['page', 'audio', 'pdf'], true)) return null;
        $entry = ['ref' => $ref, 'hash' => $hash, 'kind' => $kind];
        if (isset($item['label']))   $entry['label']   = mb_substr(sanitize_text_field($item['label']), 0, 120);
        if (isset($item['seconds'])) $entry['seconds'] = max(0, intval($item['seconds']));
        if (isset($item['pages']))   $entry['pages']   = max(0, min($MAX_PAGES, intval($item['pages'])));
        $out_pool[] = $entry; $refs[$ref] = true;
    }
    $versions = $arr['versions'] ?? [];
    if (!is_array($versions) || count($versions) < 1 || count($versions) > $MAX_VERSIONS) return null;
    $out_versions = [];
    foreach ($versions as $vid => $ver) {
        $vid = intval($vid);
        if ($vid < 1 || $vid > $MAX_VERSIONS || !is_array($ver)) return null;
        $v_out = [];
        if (isset($ver['label'])) $v_out['label'] = mb_substr(sanitize_text_field($ver['label']), 0, 80);
        $chapters = $ver['chapters'] ?? [];
        if (!is_array($chapters) || count($chapters) > $MAX_CHAPTERS) return null;
        $c_out = [];
        foreach ($chapters as $ch) {
            if (!is_array($ch)) return null;
            $cref = sanitize_text_field($ch['ref'] ?? '');
            if (!isset($refs[$cref])) return null; // must point at a pool item
            $c = ['n' => max(1, intval($ch['n'] ?? 0)), 'ref' => $cref,
                  'title' => mb_substr(sanitize_text_field($ch['title'] ?? ''), 0, 200)];
            if (isset($ch['seconds']))    $c['seconds']    = max(0, intval($ch['seconds']));
            if (isset($ch['start_page'])) $c['start_page'] = max(0, intval($ch['start_page']));
            if (isset($ch['start_ms']))   $c['start_ms']   = max(0, intval($ch['start_ms']));
            $c_out[] = $c;
        }
        if ($c_out) $v_out['chapters'] = $c_out;
        $pages = $ver['pages'] ?? [];
        if (!is_array($pages) || count($pages) > $MAX_PAGES) return null;
        $p_out = [];
        foreach ($pages as $pg) {
            if (!is_array($pg)) return null;
            $pref = sanitize_text_field($pg['ref'] ?? '');
            $src  = sanitize_text_field($pg['src'] ?? '');
            if (!isset($refs[$pref])) return null;
            if (!in_array($src, ['image', 'pdf'], true)) return null;
            $p = ['n' => max(1, intval($pg['n'] ?? 0)), 'ref' => $pref, 'src' => $src];
            if ($src === 'pdf') $p['page'] = max(1, intval($pg['page'] ?? 1));
            $role = sanitize_text_field($pg['role'] ?? '');
            if (in_array($role, ['front_cover', 'interior', 'back_cover'], true)) $p['role'] = $role;
            $p_out[] = $p;
        }
        if ($p_out) $v_out['pages'] = $p_out;
        if (!$c_out && !$p_out) return null; // a version must contain something
        $out_versions[(string) $vid] = $v_out;
    }
    $out = ['v' => 1, 'pool' => $out_pool, 'versions' => $out_versions];
    $tv = $arr['tier_versions'] ?? [];
    if (is_array($tv) && $tv) {
        $tv_out = [];
        foreach ($tv as $tier_order => $vid) {
            $to = intval($tier_order); $vid = intval($vid);
            if ($to < 0 || $to > 50 || !isset($out_versions[(string) $vid])) return null;
            $tv_out[(string) $to] = $vid;
        }
        $out['tier_versions'] = $tv_out;
    }
    return $out;
}

// ============================================================================
// M1-b2: NEW MINT TYPE SOFT-LAUNCH GATE (eBook / AudioBook)
// Fail-closed: with no wp-config entries nobody can create these types.
//   define('IMC_NEWTYPE_PREVIEW_WALLETS', ['r...']);  -> these wallets may create any new type
//   define('IMC_NEWTYPES_LIVE', ['audiobook']);          -> type is open to every creator
// Config-only: open a type at launch by adding it to IMC_NEWTYPES_LIVE, no code deploy.
// ============================================================================
function imc_newtype_allowed($wallet, $nft_type) {
    $live = defined('IMC_NEWTYPES_LIVE') ? (array) IMC_NEWTYPES_LIVE : [];
    if (in_array($nft_type, $live, true)) return true;
    $preview = defined('IMC_NEWTYPE_PREVIEW_WALLETS') ? (array) IMC_NEWTYPE_PREVIEW_WALLETS : [];
    return $wallet !== '' && in_array($wallet, $preview, true);
}

// ============================================================================
// U3 (Unlockables Master) -- fee grid + claim helpers.
// Grid is ONE config array (wp-config override IMC_UNLOCKABLE_FEE_GRID) walked
// identically by the JS mirror in page-mint's MINT_CONFIG (pairing documented).
// ============================================================================
function imc_ul_fee_grid() {
    return defined('IMC_UNLOCKABLE_FEE_GRID') ? (array) IMC_UNLOCKABLE_FEE_GRID : [
        // [max bytes (inclusive), fee XRP] -- ruled 25 Aug 2026; 1GB hard cap
        [26214400, 1.0], [52428800, 1.5], [104857600, 2.0],
        [262144000, 2.5], [524288000, 5.0], [1073741824, 10.0],
    ];
}
function imc_ul_fee_for_size($bytes) {
    $bytes = intval($bytes);
    if ($bytes <= 0) return false;
    foreach (imc_ul_fee_grid() as $rung) {
        if ($bytes <= intval($rung[0])) return floatval($rung[1]);
    }
    return false; // over the hard cap
}
// Parse + validate the create payload's unlockable claims against the POOL
// (rows this artist uploaded via the ticket-authenticated U2 lane). Server-
// authoritative: sizes/fees come from the POOL ROWS, never the client.
// Returns ['claims'=>[], 'total'=>float] or ['error'=>string].
function imc_ul_parse_claims($artist, $json) {
    $out = ['claims' => [], 'total' => 0.0];
    $raw = trim((string) $json);
    if ($raw === '' || $raw === '[]') return $out;
    $items = json_decode($raw, true);
    // U3-r8: WordPress magic-quotes slash-escapes $_POST, so quoted JSON arrives as
    // [{\"content_hash\":...}] and direct decode fails. Dual-decode = the house
    // pattern (see the :1274 precedent and the CRITICAL note above validate_accepted_currencies).
    // One seat heals BOTH callers: the wizard's create/publish claims and the
    // dashboard's vault_add quote/claim -- their serializations are identical.
    if (!is_array($items)) { $items = json_decode(stripslashes($raw), true); }
    if (!is_array($items)) return ['error' => 'Invalid unlockable content data'];
    if (count($items) > 10) return ['error' => 'Maximum 10 unlockable files per listing'];
    global $wpdb;
    $t = $wpdb->prefix . 'imc_listing_unlockables';
    $seen = [];
    foreach ($items as $it) {
        $hash = sanitize_text_field($it['content_hash'] ?? '');
        if (!preg_match('/^sha256:[a-f0-9]{64}$/', $hash)) {
            return ['error' => 'Invalid unlockable file reference'];
        }
        $pool = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $t WHERE artist_account = %s AND listing_id = 0 AND tier_id = 0
               AND content_hash = %s AND status = 'active' LIMIT 1",
            $artist, $hash
        ), ARRAY_A);
        if (!$pool) return ['error' => 'Unlockable file not found in your uploads -- please re-upload it'];
        $fee = imc_ul_fee_for_size($pool['file_size']);
        if ($fee === false) return ['error' => 'Unlockable file exceeds the 1GB limit'];
        $tier_order = isset($it['tier_order']) && $it['tier_order'] !== null && $it['tier_order'] !== ''
            ? intval($it['tier_order']) : null;
        $scope_key = ($tier_order === null ? 'L' : 'T' . $tier_order) . '|' . $hash;
        if (isset($seen[$scope_key])) return ['error' => 'Duplicate unlockable file in the same scope'];
        $seen[$scope_key] = true;
        $out['claims'][] = [
            'pool'       => $pool,
            'label'      => sanitize_text_field(mb_substr((string) ($it['label'] ?? $pool['original_filename']), 0, 120)),
            'tier_order' => $tier_order,
            'fee'        => $fee,
        ];
        $out['total'] += $fee;
    }
    return $out;
}
function imc_ul_slots_total($listing_id) {
    global $wpdb;
    $t = $wpdb->prefix . 'imc_listing_unlockables';
    return floatval($wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(fee_xrp),0) FROM $t WHERE listing_id = %d", intval($listing_id)
    )));
}
// ONE stamping helper, FOUR call sites (main publish, promo auto-publish, both
// recovery-lane promotions) -- the U3 build law. No-op when a listing has no slots.
function imc_ul_mark_paid($listing_id, $tx) {
    global $wpdb;
    $t = $wpdb->prefix . 'imc_listing_unlockables';
    $wpdb->query($wpdb->prepare(
        "UPDATE $t SET fee_paid = 1, fee_tx = %s WHERE listing_id = %d AND fee_paid = 0",
        sanitize_text_field($tx), intval($listing_id)
    ));
}

/**
 * Validate IPFS hash format
 */
function is_valid_ipfs($hash) {
    // Remove ipfs:// prefix if present
    $clean = str_replace('ipfs://', '', $hash);
    // CIDv0 (Qm...) or CIDv1 (baf...)
    return preg_match('/^(Qm[1-9A-HJ-NP-Za-km-z]{44}|baf[a-z2-7]{56,})$/', $clean);
}

/**
 * v699 (Phase 4): pin a TIERED listing card-cover image to IPFS server-side. Self-contained
 * mirror of media-upload-handler's pinToPinata — avoids cross-including that file (which would
 * re-register its hooks). The Pinata key stays on the server (Option B). Display-only: the
 * result only ever populates cover_ipfs for a tiered listing; it never touches minted metadata.
 * Returns ['success' => bool, 'hash' => CID] or ['success' => false, 'error' => string].
 */
function imc_pin_card_cover_to_ipfs($filePath, $fileName) {
    $jwt = defined('IMC_PINATA_JWT') ? IMC_PINATA_JWT : (defined('PINATA_JWT') ? PINATA_JWT : '');
    if (empty($jwt))            { return ['success' => false, 'error' => 'Pinata JWT not configured']; }
    if (!is_readable($filePath)) { return ['success' => false, 'error' => 'uploaded file not readable']; }

    $boundary = wp_generate_password(24, false);
    $body  = "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$fileName}\"\r\n";
    $body .= "Content-Type: application/octet-stream\r\n\r\n";
    $body .= file_get_contents($filePath) . "\r\n";
    $body .= "--{$boundary}--\r\n";

    $ch = curl_init('https://api.pinata.cloud/pinning/pinFileToIPFS');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $jwt,
            'Content-Type: multipart/form-data; boundary=' . $boundary,
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT    => 120,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if ($httpCode === 200 && !empty($data['IpfsHash'])) {
        return ['success' => true, 'hash' => $data['IpfsHash']];
    }
    return ['success' => false, 'error' => $data['error'] ?? $data['message'] ?? "HTTP {$httpCode}"];
}

/**
 * v71: Well-known XRPL tokens (should match currency-handler.php)
 */
function get_known_tokens() {
    // v604: single source of truth = Token Manager (imc_supported_tokens option).
    // Non-native tokens are only treated as 'known' when they carry an issuer.
    if (function_exists('imc_get_supported_tokens')) {
        $out = [];
        foreach (imc_get_supported_tokens('all') as $t) {
            $code = strtoupper(trim($t['ticker'] ?? ''));
            if ($code === '') continue;
            $is_native = !empty($t['is_native']);
            if (!$is_native && empty($t['issuer'])) continue;
            $entry = [
                'currency' => $code,
                'name'     => $t['display_name'] ?? ($t['ticker'] ?? $code),
                'icon'     => $t['icon_emoji'] ?? '',
                'decimals' => intval($t['decimals'] ?? ($is_native ? 6 : 8)),
            ];
            if (!$is_native) {
                $entry['issuer'] = $t['issuer'];
                if (strlen($code) > 3) {
                    $entry['currency_hex'] = strtoupper(str_pad(bin2hex((string)$t['ticker']), 40, '0'));
                }
            }
            $out[$code] = $entry;
        }
        if (!empty($out)) return $out;
    }
    // Fallback if Token Manager unavailable — original built-in set:
    return [
        'XRP' => ['currency' => 'XRP', 'name' => 'XRP', 'icon' => '💧', 'decimals' => 6],
        'XFT' => ['currency' => 'XFT', 'issuer' => 'rGpnoqYLzWytxwQhhz715nRbqyCHM7zhxt', 'name' => 'XFT', 'icon' => '🎵', 'decimals' => 8],
        'SCHMECKLES' => ['currency' => 'SCHMECKLES', 'issuer' => 'rPxw83ZP6thv7KmG5DpAW4cDW55DZRZ9wu', 'name' => 'Schmeckles', 'icon' => '🪙', 'decimals' => 8],
        'XMEME' => ['currency' => 'XMEME', 'issuer' => 'r4UPddYeGeZgDhSGPkooURsQtmGda4oYQW', 'name' => 'XMEME', 'icon' => '🐸', 'decimals' => 8]
    ];
}

/**
 * v71: Validate accepted_currencies structure
 * Format: [{"currency":"XRP","price":10},{"currency":"XFT","issuer":"r...","price":500,"discount_percent":10}]
 */
function validate_accepted_currencies($input) {
    if (empty($input)) return null;
    
    // CRITICAL: WordPress/PHP adds backslashes to POST data - must stripslashes before json_decode!
    $input_clean = is_string($input) ? stripslashes($input) : $input;
    $currencies_raw = is_string($input_clean) ? json_decode($input_clean, true) : $input_clean;
    
    // Debug: Log what we received
    if ($currencies_raw === null && is_string($input)) {
        listings_log("validate_accepted_currencies: json_decode failed. Input (first 200 chars): " . substr($input, 0, 200));
        listings_log("validate_accepted_currencies: After stripslashes (first 200 chars): " . substr($input_clean, 0, 200));
        listings_log("validate_accepted_currencies: json_last_error: " . json_last_error_msg());
    }
    
    if (!is_array($currencies_raw) || count($currencies_raw) === 0) return null;
    
    $known_tokens = get_known_tokens();
    $validated = [];
    
    foreach ($currencies_raw as $curr) {
        // v81: For dynamic mode, price may be 0 or missing (calculated at checkout)
        if (!isset($curr['currency'])) continue;
        
        $currency_code = strtoupper(trim($curr['currency']));
        if (strlen($currency_code) < 1 || strlen($currency_code) > 40) continue;
        
        $entry = [
            'currency' => $currency_code,
            'price' => isset($curr['price']) ? max(0, floatval($curr['price'])) : 0,
            'enabled' => isset($curr['enabled']) ? (bool)$curr['enabled'] : true
        ];
        
        // For non-XRP tokens, require issuer
        if ($currency_code !== 'XRP') {
            // Check if known token (use stored issuer)
            if (isset($known_tokens[$currency_code])) {
                $entry['issuer'] = $known_tokens[$currency_code]['issuer'];
                $entry['name'] = $known_tokens[$currency_code]['name'];
                $entry['icon'] = $known_tokens[$currency_code]['icon'];
            } else {
                // Custom token - require issuer
                if (empty($curr['issuer']) || !preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $curr['issuer'])) {
                    continue; // Skip invalid token entries
                }
                $entry['issuer'] = $curr['issuer'];
            }
            
            // For hex currency codes (40-char non-standard)
            if (!empty($curr['currency_hex']) && preg_match('/^[A-Fa-f0-9]{40}$/', $curr['currency_hex'])) {
                $entry['currency_hex'] = strtoupper($curr['currency_hex']);
            }
        } else {
            $entry['name'] = 'XRP';
            $entry['icon'] = '💧';
        }
        
        // Optional discount percentage for this currency (v81: accept both discount_pct and discount_percent)
        $discount = isset($curr['discount_pct']) ? floatval($curr['discount_pct']) : 
                   (isset($curr['discount_percent']) ? floatval($curr['discount_percent']) : 0);
        if ($discount > 0) {
            $entry['discount_pct'] = max(0, min(100, $discount));
            $entry['discount_percent'] = $entry['discount_pct']; // Keep both for compatibility
        }
        
        // Optional display name
        if (!empty($curr['display_name'])) {
            $entry['display_name'] = sanitize_text_field(substr($curr['display_name'], 0, 20));
        }
        
        $validated[] = $entry;
    }
    
    return count($validated) > 0 ? $validated : null;
}

/**
 * Format listing for API response
 */
function format_listing($listing) {
    if (!$listing) return null;
    
    // Calculate available editions (accounts for reserved editions)
    // OE-v1: Open editions have total_editions=0 (unlimited) — return large sentinel so buyer UI never shows "sold out"
    // v393: Only for ACTIVE open editions — closed OEs have total_editions locked to minted_count
    $is_oe_here = (($listing['edition_type'] ?? 'fixed') === 'open') && empty($listing['open_edition_closed_at']);
    if ($is_oe_here) {
        $listing['available_editions'] = 999999; // Unlimited signal
    } else {
        $listing['available_editions'] = max(0,
            $listing['total_editions'] - $listing['minted_count'] - intval($listing['reserved_count'] ?? 0)
        );
    }
    
    // Parse metadata JSON if present
    // v390: Try direct decode first (clean data), then stripslashes fallback (legacy escaped data)
    if (!empty($listing['metadata_json'])) {
        $listing['metadata'] = json_decode($listing['metadata_json'], true)
                            ?: json_decode(stripslashes($listing['metadata_json']), true);
    }
    
    // v71: Parse accepted_currencies JSON
    if (!empty($listing['accepted_currencies'])) {
        $currencies = json_decode($listing['accepted_currencies'], true);
        if (is_array($currencies)) {
            // Enhance with known token info
            $known_tokens = get_known_tokens();
            foreach ($currencies as &$curr) {
                $code = strtoupper($curr['currency'] ?? '');
                if (isset($known_tokens[$code]) && !isset($curr['name'])) {
                    $curr['name'] = $known_tokens[$code]['name'];
                    $curr['icon'] = $known_tokens[$code]['icon'];
                    if (!isset($curr['issuer']) && isset($known_tokens[$code]['issuer'])) {
                        $curr['issuer'] = $known_tokens[$code]['issuer'];
                    }
                }
            }
            $listing['accepted_currencies'] = $currencies;
        }
    } else {
        // Default to XRP only with listing price
        $listing['accepted_currencies'] = [
            ['currency' => 'XRP', 'price' => floatval($listing['price_xrp']), 'enabled' => true, 'name' => 'XRP', 'icon' => '💧']
        ];
    }
    
    // Convert numeric strings
    $listing['price_xrp'] = floatval($listing['price_xrp']);
    $listing['total_editions'] = intval($listing['total_editions']);
    $listing['minted_count'] = intval($listing['minted_count']);
    $listing['transfer_fee'] = intval($listing['transfer_fee']);
    $listing['platform_fee_amount'] = floatval($listing['platform_fee_amount']);
    $listing['platform_fee_paid'] = (bool)$listing['platform_fee_paid'];
    $listing['is_transferable'] = (bool)$listing['is_transferable'];
    $listing['downloadable'] = (bool)$listing['downloadable'];
    $listing['has_tiers'] = (bool)($listing['has_tiers'] ?? false);

    // OE-v1: Open Edition fields
    // v393: is_open_edition means ACTIVE open edition (not closed) — display consumers use this
    $listing['edition_type']    = $listing['edition_type'] ?? 'fixed';
    $listing['is_open_edition'] = ($listing['edition_type'] === 'open') && empty($listing['open_edition_closed_at']);
    $listing['primary_mint_fee_pct'] = floatval($listing['primary_mint_fee_pct'] ?? 0.00);
    
    // Attach tier data if listing has tiers
    if ($listing['has_tiers']) {
        global $wpdb;
        $tiers_table = $wpdb->prefix . 'imc_listing_tiers';
        $tiers = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $tiers_table WHERE listing_id = %d ORDER BY tier_order ASC",
            $listing['id']
        ), ARRAY_A);
        if ($tiers) {
            foreach ($tiers as &$tier) {
                $tier['total_editions'] = intval($tier['total_editions']);
                $tier['minted_count'] = intval($tier['minted_count']);
                $tier['tier_traits'] = $tier['tier_traits'] ? json_decode($tier['tier_traits'], true) : [];
            }
            $listing['tiers'] = $tiers;
        }
    }
    
    // v493 Phase 3: resolve displayed artist name (dashboard name -> listing
    // name -> short wallet). Honours the IMC_ARTIST_NAME_DISABLED kill-switch.
    if (function_exists('imc_resolve_artist_name')) {
        $listing['artist_name'] = imc_resolve_artist_name(
            $listing['artist_account'] ?? '',
            $listing['artist_name'] ?? '',
            ''
        );
    }
    return $listing;
}

// ============================================================================
// ROUTE HANDLING
// ============================================================================

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    
    // ====================================================================
    // INTERNAL CRON (v610): recover paid-but-draft listings across recent
    // artists who paid but never returned to their dashboard. Key-gated
    // (IMC_RECOVER_CRON_KEY), no nonce; handled before the GET/POST branch so
    // it is fully isolated from every user-facing endpoint. Reuses the proven
    // per-artist recovery paths -- adds no new publish logic. Fail-closed.
    // ====================================================================
    if ($action === 'cron_recover_drafts') {
        $cron_key = $_GET['key'] ?? $_POST['key'] ?? '';
        $cron_exp = defined('IMC_RECOVER_CRON_KEY') ? IMC_RECOVER_CRON_KEY : '';
        if (empty($cron_exp) || !hash_equals((string)$cron_exp, (string)$cron_key)) {
            json_error('Unauthorized', 403);
        }
        $swept = lh_cron_recover_all_paid_drafts();
        json_success(['ok' => true, 'artists_swept' => $swept]);
    }

    // ====================================================================
    // AI BACKFILL (v623): re-runnable tagging of existing ART listings from
    // their stored metadata_json ai_disclosure. Key-gated (IMC_AI_BACKFILL_KEY),
    // no nonce, handled before the GET/POST branch so it is fully isolated from
    // every user-facing endpoint. READ-ONLY except the three new AI columns;
    // never touches mint / buy / create logic. Idempotent + batched.
    // ====================================================================
    if ($action === 'ai_backfill_art') {
        $bf_key = $_GET['key'] ?? $_POST['key'] ?? '';
        $bf_exp = defined('IMC_AI_BACKFILL_KEY') ? IMC_AI_BACKFILL_KEY : '';
        if (empty($bf_exp) || !hash_equals((string)$bf_exp, (string)$bf_key)) {
            json_error('Unauthorized', 403);
        }
        global $wpdb, $listings_table;
        $limit  = min(500, max(1, intval($_GET['limit'] ?? 200)));
        $offset = max(0, intval($_GET['offset'] ?? 0));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, metadata_json FROM {$listings_table}
             WHERE nft_type = 'art' ORDER BY id ASC LIMIT %d OFFSET %d",
            $limit, $offset
        ), ARRAY_A);
        $scanned = 0; $ai_tagged = 0; $cleared = 0;
        foreach (($rows ?: []) as $r) {
            $scanned++;
            $ai_generated = 0; $ai_mode = null; $ai_platform = null;
            $md = json_decode((string)($r['metadata_json'] ?? ''), true);
            $disc = null;
            if (is_array($md)) {
                if (isset($md['ai_disclosure']) && is_array($md['ai_disclosure'])) {
                    $disc = $md['ai_disclosure'];
                } elseif (isset($md['art']['ai_disclosure']) && is_array($md['art']['ai_disclosure'])) {
                    $disc = $md['art']['ai_disclosure'];
                }
            }
            if (is_array($disc) && !empty($disc['ai_used'])) {
                $ai_generated = 1;
                $plat = isset($disc['platform']) ? trim((string)$disc['platform']) : '';
                if ($plat !== '') {
                    $ai_platform = function_exists('mb_substr') ? mb_substr($plat, 0, 120) : substr($plat, 0, 120);
                }
                $ai_el = (isset($disc['ai_created_elements']) && is_array($disc['ai_created_elements'])) ? $disc['ai_created_elements'] : [];
                $hu_el = (isset($disc['human_created_elements']) && is_array($disc['human_created_elements'])) ? $disc['human_created_elements'] : [];
                $ai_mode = (in_array('full_artwork', $ai_el, true) || empty($hu_el)) ? 'full' : 'assisted';
                $ai_tagged++;
            } else {
                $cleared++;
            }
            $wpdb->update(
                $listings_table,
                ['ai_generated' => $ai_generated, 'ai_mode' => $ai_mode, 'ai_platform' => $ai_platform],
                ['id' => intval($r['id'])],
                ['%d', '%s', '%s'],
                ['%d']
            );
        }
        $next = (count($rows ?: []) === $limit) ? ($offset + $limit) : null;
        json_success(['ok' => true, 'scanned' => $scanned, 'ai_tagged' => $ai_tagged, 'cleared' => $cleared, 'next_offset' => $next]);
    }

    // ========================================================================
    // GET ENDPOINTS
    // ========================================================================
    
    if ($method === 'GET') {
        
        // --------------------------------------------------------------------
        // Get single listing by ID
        // --------------------------------------------------------------------
        if ($action === 'get') {
            $id = intval($_GET['id'] ?? 0);
            if (!$id) json_error('Missing listing ID');
            
            global $wpdb, $listings_table;
            $listing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $listings_table WHERE id = %d",
                $id
            ), ARRAY_A);
            
            if (!$listing) {
                json_error('Listing not found', 404);
            }
            
            json_success(format_listing($listing));
        }
        
        // --------------------------------------------------------------------
        // Get listings by artist
        // --------------------------------------------------------------------
        if ($action === 'get_by_artist') {
            $account = sanitize_text_field($_GET['account'] ?? '');
            $status = sanitize_text_field($_GET['status'] ?? '');
            $limit = min(100, max(1, intval($_GET['limit'] ?? 50)));
            $offset = max(0, intval($_GET['offset'] ?? 0));
            
            if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
                json_error('Invalid XRPL account');
            }
            // ================================================================
            // OWNER CHECK. This action serves TWO surfaces:
            //
            //   PUBLIC  page-artist.php, page-collection-preview.php -- they pass
            //           the artist being VIEWED, so this cannot be session-gated.
            //   PRIVATE page-creator-dashboard.php -- passes the creator's own
            //           wallet and needs the full row.
            //
            // So the session decides the ROW SET, not access. A non-owner loses
            // draft and cancelled listings (unpublished work) and the revenue
            // aggregate. No COLUMN is removed, so every public caller keeps the
            // fields it renders -- page-artist.php still gets `status` for its
            // sold-out logic.
            // ================================================================
            $lba_auth = function_exists('imc_session_require_wallet')
                ? imc_session_require_wallet('')
                : array('ok' => false, 'wallet' => '');
            $lba_wallet = !empty($lba_auth['ok']) ? (string) $lba_auth['wallet'] : '';
            $lba_is_owner = ($lba_wallet !== '' && $lba_wallet === $account);

            
            global $wpdb, $listings_table;

            // v590 (additive): self-heal any "paid-but-draft" listings for this artist before
            // listing them, so a fee paid on an abandoned mobile tab still goes live. Best-effort.
            lh_recover_paid_draft_listings($account);
            lh_recover_paid_draft_onchain($account); // v609: recover local-signing (no-uuid) fee payments
            
            $where = "artist_account = %s";
            $args = [$account];
            
            // Filter by status if provided
            $valid_statuses = ['draft', 'active', 'paused', 'sold_out', 'cancelled'];
            if ($status && in_array($status, $valid_statuses)) {
                $where .= " AND status = %s";
                $args[] = $status;
            }

            // Non-owner: unpublished work is not public.
            if (!$lba_is_owner) {
                $where .= " AND status NOT IN ('draft','cancelled')";
            }
            
            // Get listings
            $sql = "SELECT * FROM $listings_table WHERE $where ORDER BY created_at DESC LIMIT %d OFFSET %d";
            $listings = $wpdb->get_results(
                $wpdb->prepare($sql, array_merge($args, [$limit, $offset])),
                ARRAY_A
            );
            
            // Get total count
            $total = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $listings_table WHERE $where",
                $args
            ));
            
            // Format listings
            $listings = array_map('format_listing', $listings);

            // v488: TRUE revenue per listing - actual XRP received, not list price x count.
            // Mirrors imc-stats-handler.php (leaderboard + stats page): SUM(price_xrp) for XRP
            // purchases with mint_status IN ('minted','claimed'). Whitelist/discount sales and
            // free mints (price_xrp = 0) therefore reflect real revenue. Single batched query.
            if (!empty($listings) && $lba_is_owner) {   // revenue is the creator's own figure
                $rev_ids = array_values(array_filter(array_map(function ($lr) {
                    return intval($lr['id'] ?? 0);
                }, $listings)));
                if (!empty($rev_ids)) {
                    $imc_purchases_table = $wpdb->prefix . 'imc_purchases';
                    $rev_ph = implode(',', array_fill(0, count($rev_ids), '%d'));
                    $rev_rows = $wpdb->get_results($wpdb->prepare(
                        "SELECT listing_id,
                                SUM(CASE WHEN UPPER(price_currency) = 'XRP' THEN price_xrp ELSE 0 END) AS revenue_xrp
                         FROM $imc_purchases_table
                         WHERE listing_id IN ($rev_ph)
                           AND mint_status IN ('minted','claimed')
                         GROUP BY listing_id",
                        ...$rev_ids
                    ), ARRAY_A);
                    $rev_map = [];
                    foreach ($rev_rows as $rev_row) {
                        $rev_map[intval($rev_row['listing_id'])] = (float) $rev_row['revenue_xrp'];
                    }
                    foreach ($listings as &$rev_l) {
                        $rev_l['revenue_xrp'] = $rev_map[intval($rev_l['id'] ?? 0)] ?? 0.0;
                    }
                    unset($rev_l);
                }
            }
            
            // v494 Phase 3 fix: get_by_artist returns raw rows (not via
            // format_listing), so resolve the displayed artist name here too.
            // Honours the IMC_ARTIST_NAME_DISABLED kill-switch.
            if (function_exists('imc_resolve_artist_name')) {
                foreach ($listings as $i => $row) {
                    $listings[$i]['artist_name'] = imc_resolve_artist_name(
                        $row['artist_account'] ?? '',
                        $row['artist_name'] ?? '',
                        ''
                    );
                }
            }
            
            json_success([
                'listings' => $listings,
                'total' => intval($total),
                'limit' => $limit,
                'offset' => $offset
            ]);
        }
        
        // --------------------------------------------------------------------
        // Get active listings for marketplace (public)
        // --------------------------------------------------------------------
        if ($action === 'get_marketplace') {
            $type = sanitize_text_field($_GET['type'] ?? '');
            $sort = sanitize_text_field($_GET['sort'] ?? 'newest');
            $collection = sanitize_text_field($_GET['collection'] ?? '');
            $artist = sanitize_text_field($_GET['artist'] ?? '');
            $min_price = floatval($_GET['min_price'] ?? 0);
            $max_price = floatval($_GET['max_price'] ?? 0);
            $limit = min(100, max(1, intval($_GET['limit'] ?? 24)));
            $offset = max(0, intval($_GET['offset'] ?? 0));
            // v63 FIX: Optional param to include sold_out listings
            // v65 FIX: Also include paused listings (they should show but with disabled mint)
            $include_sold_out = isset($_GET['include_sold_out']) && $_GET['include_sold_out'] === '1';
            $include_paused = isset($_GET['include_paused']) && $_GET['include_paused'] === '1';
            // v628: AI Art routing filter — '' (no filter), 'only' (AI art), 'exclude' (non-AI). Backward-compatible.
            $ai_filter = sanitize_text_field($_GET['ai'] ?? '');
            if (!in_array($ai_filter, ['', 'only', 'exclude'], true)) { $ai_filter = ''; }

            // v575: Server-side cache for this PUBLIC read. Result is fully determined
            // by the 11 params above and carries no user/wallet/session data, so it is
            // safe to share across visitors. Backed by transients (DB store while object
            // cache is off) - collapses the homepage's ~8 get_marketplace calls plus the
            // per-collection cover lookups into one cached payload. SAFETY: get_transient
            // returns false on miss/disabled, falling straight through to the live query
            // below - identical to pre-v575 behaviour. Write/sign paths are untouched.
            $imc_gm_key = 'imc_gm_v3_' . md5(implode('|', [ // v681: bumped -- v2 payloads pre-date the is_hidden filter
                $type, $sort, $collection, $artist,
                (string) $min_price, (string) $max_price, (string) $limit, (string) $offset,
                $include_sold_out ? '1' : '0',
                $include_paused ? '1' : '0',
                $ai_filter
            ]));
            if (($imc_gm_cached = get_transient($imc_gm_key)) !== false) {
                json_success($imc_gm_cached);
            }
            
            global $wpdb, $listings_table;
            
            // Base condition: active (and optionally sold_out/paused), past launch time
            if ($include_sold_out || $include_paused) {
                // v65: Build status list dynamically
                $statuses = ["'active'"];
                if ($include_sold_out) $statuses[] = "'sold_out'";
                if ($include_paused) $statuses[] = "'paused'";
                $where = "status IN (" . implode(',', $statuses) . ")";
            } else {
                // Default: active listings with available editions.
                // OE-v1: Open editions have total_editions=0 (unlimited) — include them unless closed.
                $where = "status = 'active' AND (edition_type = 'open' OR total_editions > minted_count) AND open_edition_closed_at IS NULL";
            }
            // v681: moderation filter. Applied once here so BOTH status branches above inherit
            // it -- a hidden listing never reaches any public marketplace grid.
            $where .= " AND is_hidden = 0";
            // v233: Include scheduled future mints so artists can promote pre-launch drops.
            // The frontend renders a "🚀 Mint Launch: [date]" badge for upcoming scheduled mints.
            // Purchase guards in reserve_purchase already block early buys — no security issue.
            $args = [];
            
            // Filter by type
            if ($type && in_array($type, ['music', 'musicvideo', 'art', 'film', 'album', 'ebook', 'audiobook'])) {
                $where .= " AND nft_type = %s";
                $args[] = $type;
            }
            
            // v628: AI Art routing — literal 0/1, no prepared args (keeps $args ordering intact)
            if ($ai_filter === 'only') {
                $where .= " AND ai_generated = 1";
            } elseif ($ai_filter === 'exclude') {
                $where .= " AND ai_generated = 0";
            }
            
            // Filter by collection
            if ($collection) {
                $where .= " AND collection_name LIKE %s";
                $args[] = '%' . $wpdb->esc_like($collection) . '%';
            }
            
            // Filter by artist
            if ($artist && preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $artist)) {
                $where .= " AND artist_account = %s";
                $args[] = $artist;
            }
            
            // Price filters
            if ($min_price > 0) {
                $where .= " AND price_xrp >= %f";
                $args[] = $min_price;
            }
            if ($max_price > 0) {
                $where .= " AND price_xrp <= %f";
                $args[] = $max_price;
            }
            
            // Sort order
            $order = match($sort) {
                'price_low' => 'price_xrp ASC',
                'price_high' => 'price_xrp DESC',
                'oldest' => 'published_at ASC',
                'popular' => 'minted_count DESC',
                default => 'published_at DESC'  // newest
            };
            
            // Select only necessary fields for marketplace view
            // v63: Added status field for sold_out display
            // v393: Added edition_type + OE fields for open edition display
            $sql = "SELECT id, nft_type, nft_name, description, artist_account, artist_name,
                           cover_ipfs, price_xrp, pricing_mode, price_usd, accepted_currencies, total_editions, minted_count, reserved_count,
                           -- B5: featured drop cards need the public preview clip and the
                           -- optional back cover (books). Read-only additions to a public
                           -- SELECT; no filter, join or ordering changes.
                           media_ipfs, back_cover_ipfs,
                           collection_name, collection_taxon, transfer_fee, published_at, status,
                           launch_type, launch_at, launch_timezone,
                           edition_type, open_edition_ends_at, open_edition_closed_at,
                           ai_generated, ai_mode, ai_platform
                    FROM $listings_table 
                    WHERE $where 
                    ORDER BY $order 
                    LIMIT %d OFFSET %d";
            
            $listings = $wpdb->get_results(
                $wpdb->prepare($sql, array_merge($args, [$limit, $offset])),
                ARRAY_A
            );
            
            // Get total count
            //
            // v903: $where here can contain NO PLACEHOLDER. Unlike get_by_artist (whose
            // $where always begins "artist_account = %s"), get_marketplace builds $where
            // from literals — "status = 'active' AND (...)" plus " AND is_hidden = 0" —
            // and starts $args EMPTY. On the DEFAULT browse with no filters applied nothing
            // is ever appended, so wpdb::prepare() receives a query with nothing to bind and
            // WordPress emits: "Function wpdb::prepare was called incorrectly. The query
            // argument of wpdb::prepare() must have a placeholder."
            //
            // The homepage fires several get_marketplace calls per render, so this ONE site
            // produced 44 Notices in 90 minutes of debug.log (30 Aug 2026) — noise that
            // buries real errors in a log that is otherwise clean.
            //
            // Only prepare when there IS something to bind. Same guard already proven in
            // offer-handler.php L1952 for the identical situation.
            //
            // SAFE: $where is assembled exclusively from literals and validated enum values.
            // EVERY user-supplied value goes through $args and therefore through prepare() —
            // nft_type (%s), collection_name (%s, esc_like'd), artist_account (%s), price
            // bounds (%f). The AI filter writes literal 0/1 with no arg by design (v628 note
            // above). When $args is empty there is, by construction, no user input in $where.
            //
            // get_by_artist L1507 has the same SHAPE but is NOT affected and is left alone:
            // its $where always begins "artist_account = %s", so a placeholder is guaranteed.
            $total = empty($args)
                ? $wpdb->get_var("SELECT COUNT(*) FROM $listings_table WHERE $where")
                : $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $listings_table WHERE $where",
                    $args
                ));
            
            // Add available count to each listing (subtract both minted and reserved)
            foreach ($listings as &$listing) {
                // OE-v1: Open editions have total_editions=0 — return unlimited sentinel
                // v393: Only for ACTIVE open editions — closed OEs have total_editions locked to minted_count
                if (($listing['edition_type'] ?? 'fixed') === 'open' && empty($listing['open_edition_closed_at'])) {
                    $listing['available_editions'] = 999999;
                } else {
                    $listing['available_editions'] = max(0, intval($listing['total_editions']) - intval($listing['minted_count']) - intval($listing['reserved_count'] ?? 0));
                }
                $listing['price_xrp'] = floatval($listing['price_xrp']);
                // v459: When price_xrp=0, resolve from accepted_currencies (matches v448 pattern)
                if ($listing['price_xrp'] == 0 && !empty($listing['accepted_currencies'])) {
                    $ac = is_string($listing['accepted_currencies']) ? json_decode($listing['accepted_currencies'], true) : $listing['accepted_currencies'];
                    if (is_array($ac)) {
                        foreach ($ac as $c) {
                            if (($c['currency'] ?? '') === 'XRP' && ($c['enabled'] ?? false) && floatval($c['price'] ?? 0) > 0) {
                                $listing['price_xrp'] = floatval($c['price']);
                                break;
                            }
                        }
                    }
                }
                $listing['price_usd'] = floatval($listing['price_usd'] ?? 0);
                $listing['transfer_fee'] = intval($listing['transfer_fee']);
                $listing['ai_generated'] = intval($listing['ai_generated'] ?? 0); // v628: int for clean JSON; ai_mode/ai_platform pass through (string|null)
            }
            unset($listing); // break reference

            // v235: Override cover_ipfs with the artist-uploaded collection cover where available.
            // Safer than a JOIN — a single batch lookup, falls back gracefully if table missing.
            if (!empty($listings)) {
                $coll_table = $wpdb->prefix . 'imc_collections';
                if ($wpdb->get_var("SHOW TABLES LIKE '$coll_table'") === $coll_table) {
                    // Build unique (artist_account, collection_taxon) pairs
                    $pairs = [];
                    foreach ($listings as $l) {
                        $pairs[$l['artist_account'] . '_' . $l['collection_taxon']] = [
                            'account' => $l['artist_account'],
                            'taxon'   => intval($l['collection_taxon']),
                        ];
                    }
                    // Fetch covers for all unique pairs in one query
                    $cover_map = [];
                    foreach (array_values($pairs) as $p) {
                        $row = $wpdb->get_row($wpdb->prepare(
                            "SELECT artist_account, collection_taxon, cover_image_ipfs
                             FROM $coll_table
                             WHERE artist_account = %s AND collection_taxon = %d
                             AND cover_image_ipfs IS NOT NULL AND cover_image_ipfs != ''
                             LIMIT 1",
                            $p['account'], $p['taxon']
                        ), ARRAY_A);
                        if ($row && !empty($row['cover_image_ipfs'])) {
                            $cover_map[$row['artist_account'] . '_' . $row['collection_taxon']] = $row['cover_image_ipfs'];
                        }
                    }
                    // Apply overrides
                    foreach ($listings as &$listing) {
                        // v570: preserve the listing's OWN cover before the collection-cover override
                        // so per-listing carousel cards can show the listing image.
                        $listing['listing_cover_ipfs'] = $listing['cover_ipfs'];
                        $key = $listing['artist_account'] . '_' . $listing['collection_taxon'];
                        if (isset($cover_map[$key])) {
                            $listing['cover_ipfs'] = $cover_map[$key];
                        }
                    }
                    unset($listing);
                }
            }
            
            // v494 Phase 3 fix: get_marketplace returns raw rows (not via
            // format_listing), so resolve the displayed artist name here too.
            // Honours the IMC_ARTIST_NAME_DISABLED kill-switch.
            if (function_exists('imc_resolve_artist_name')) {
                foreach ($listings as $i => $row) {
                    $listings[$i]['artist_name'] = imc_resolve_artist_name(
                        $row['artist_account'] ?? '',
                        $row['artist_name'] ?? '',
                        ''
                    );
                }
            }
            
            $imc_gm_payload = [
                'listings' => $listings,
                'total' => intval($total),
                'limit' => $limit,
                'offset' => $offset
            ];
            // v575: cache the public payload (30s) before returning. Stored in
            // wp_options while object cache is off. Self-heals after TTL; no write
            // handler touched. A set_transient failure is harmless (next call re-queries).
            set_transient($imc_gm_key, $imc_gm_payload, 30);
            json_success($imc_gm_payload);
        }
        
        // --------------------------------------------------------------------
        // Calculate platform fee
        // --------------------------------------------------------------------
        if ($action === 'calculate_fee') {
            $editions = max(1, intval($_GET['editions'] ?? 1));
            $nft_type = sanitize_text_field($_GET['nft_type'] ?? 'music');
            if (!in_array($nft_type, ['music', 'musicvideo', 'art', 'film', 'album', 'ebook', 'audiobook'])) $nft_type = 'music';
            // v24: For album, master_files carries track_count from JS.
            // Pool masters: caller should pass count of distinct pool items
            // (not tier count). CREATE endpoint counts distinct hashes server-side.
            $master_files = max(1, intval($_GET['master_files'] ?? 1));
            // A3: the display endpoint must price OE as OE -- it previously omitted
            // edition_type and always quoted 'fixed' math while create charged 1.25x.
            $edition_type = (($_GET['edition_type'] ?? 'fixed') === 'open') ? 'open' : 'fixed';
            $fee_config = get_fee_for_type($nft_type);
            $fee = calculate_platform_fee($editions, $nft_type, $master_files, $edition_type);

            $response = [
                'editions'           => $editions,
                'nft_type'           => $nft_type,
                'master_files'       => $master_files,
                'edition_type'       => $edition_type,
                'flat_fee_per_master'=> $fee_config['flat'],
                'per_edition_fee'    => $fee_config['per_nft'],
                'total_fee_xrp'      => round($fee, 6),
                'total_fee_drops'    => intval($fee * 1000000),
                'fee_wallet'         => PLATFORM_FEE_WALLET
            ];

            // v365: Include tiered flat fee info for art
            if ($nft_type === 'art') {
                $response['art_tiered_flat'] = get_art_tiered_flat_fee($master_files);
                $response['art_fee_model']   = 'tiered';
            }

            // v24: Include album fee breakdown for JS display
            if ($nft_type === 'album') {
                $response['album_base_fee']   = 5.0 + (1.0 * $master_files);
                $response['album_track_count']= $master_files;
                $response['album_fee_model']  = 'base_plus_tracks';
            }

            json_success($response);
        }
        
        // --------------------------------------------------------------------
        // Get tiers for a listing
        // --------------------------------------------------------------------
        if ($action === 'get_tiers') {
            $listing_id = intval($_GET['listing_id'] ?? 0);
            if (!$listing_id) json_error('Missing listing_id');
            
            global $wpdb;
            $tiers_table = $wpdb->prefix . 'imc_listing_tiers';
            
            $tiers = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $tiers_table WHERE listing_id = %d ORDER BY tier_order ASC",
                $listing_id
            ), ARRAY_A);
            
            foreach ($tiers as &$tier) {
                $tier['total_editions'] = intval($tier['total_editions']);
                $tier['minted_count'] = intval($tier['minted_count']);
                $tier['tier_traits'] = $tier['tier_traits'] ? json_decode($tier['tier_traits'], true) : [];
            }
            
            json_success([
                'listing_id' => $listing_id,
                'tiers' => $tiers ?: [],
                'count' => count($tiers ?: [])
            ]);
        }
        
        // v210: Nonce refresh for long mint sessions (100-tier mints can take hours)
        if ($action === 'refresh_nonce') {
            // v275: IMC users auth via wallet cookie, not WP login.
            // wp_create_nonce() works for non-logged-in sessions (user_id=0),
            // so the is_user_logged_in() gate was blocking all wallet users.
            // Replaced with wallet cookie check — same pattern as all other endpoints.
            // Fix 1b (Aug 2026): token-first; a bare xrpl_account cookie no longer mints nonces.
            $ln_auth = function_exists('imc_session_require_wallet')
                ? imc_session_require_wallet('')
                : array('ok' => false, 'wallet' => '');
            if (empty($ln_auth['ok'])) {
                json_error('Not authenticated — please connect your wallet', 403);
            }
            wp_send_json_success([
                'nonce' => wp_create_nonce('xrpl_marketplace_nonce')
            ]);
        }

        json_error('Unknown action');
    }
    
    // ========================================================================
    // POST ENDPOINTS
    // ========================================================================
    
    if ($method === 'POST') {
        
        // Verify nonce for all POST requests
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
            json_error('Invalid security token', 403);
        }
        
        // --------------------------------------------------------------------
        // Create new listing
        // --------------------------------------------------------------------
        if ($action === 'create') {
            // S1-a: authenticate caller as the listing owner (was: trusted POSTed artist_account)
            $auth = function_exists('imc_session_require_wallet')
                ? imc_session_require_wallet(sanitize_text_field($_POST['artist_account'] ?? ''))
                : ['ok' => false, 'wallet' => '', 'error' => 'auth'];
            if (empty($auth['ok'])) {
                listings_log("create: BLOCKED (" . ($auth['error'] ?? 'auth') . ") posted=" . ($_POST['artist_account'] ?? '') . " session=" . ($auth['wallet'] ?? ''));
                json_error('Not authenticated as the listing owner', 403);
            }
            $artist = $auth['wallet']; // SESSION authoritative
            
            // Validate account format
            if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $artist)) {
                json_error('Invalid XRPL account');
            }
            
            // Check authorization
            if (!is_artist_authorized($artist)) {
                json_error('You must authorize IMCollectibles as your minter first. Complete the Authorization step.');
            }
            // M1-b2: eBook/AudioBook soft-launch gate. Keyed on the exact posted strings the
            // type whitelist below accepts, so no other value can reach these types.
            $gate_type = $_POST['nft_type'] ?? '';
            if (in_array($gate_type, ['ebook', 'audiobook'], true) && !imc_newtype_allowed($artist, $gate_type)) {
                listings_log("create: BLOCKED new type {$gate_type} (not launched, not a preview wallet) artist={$artist}");
                json_error('This NFT type is not available yet', 403);
            }
            
            // Required fields
            // v392: stripslashes on user-text fields — WordPress magic quotes escape
            // quotes/apostrophes in POST data. Without this, names like
            // Baseball Diamond "Trading Card Addition" get stored as \"Trading Card Addition\".
            // Same pattern as metadata_json (v390) and tiers_json.
            $nft_name = sanitize_text_field(stripslashes($_POST['nft_name'] ?? ''));
            $cover_ipfs = sanitize_text_field($_POST['cover_ipfs'] ?? '');
            $media_ipfs = sanitize_text_field($_POST['media_ipfs'] ?? '');
            $metadata_ipfs = sanitize_text_field($_POST['metadata_ipfs'] ?? '');
            
            if (!$nft_name) json_error('NFT name is required');
            if (!$cover_ipfs) json_error('Cover image IPFS hash is required');
            // v595 backstop: tiered media carries previews per-tier (since v557), not at the
            // listing level. If media_ipfs arrives empty, derive a representative one from
            // tiers_json (first tier preview, else first tier cover) so create never hard-fails
            // for tiered media regardless of client version. No-op when media_ipfs is already
            // set or when there is no tiers_json (non-tiered listings keep the original check).
            if (!$media_ipfs && !empty($_POST['tiers_json'])) {
                $bt = json_decode(stripslashes($_POST['tiers_json']), true);
                if (!is_array($bt)) { $bt = json_decode($_POST['tiers_json'], true); }
                if (is_array($bt)) {
                    foreach ($bt as $bt_tier) {
                        if (!empty($bt_tier['preview_ipfs'])) { $media_ipfs = sanitize_text_field($bt_tier['preview_ipfs']); break; }
                    }
                    if (!$media_ipfs && !empty($bt[0]['cover_ipfs'])) {
                        $media_ipfs = sanitize_text_field($bt[0]['cover_ipfs']);
                    }
                    if ($media_ipfs) { error_log("IMC listings create: media_ipfs empty — derived from tiers_json for tiered media ($media_ipfs)"); }
                }
            }

            if (!$media_ipfs) json_error('Media IPFS hash is required');
            if (!$metadata_ipfs) json_error('Metadata IPFS hash is required');
            
            // Validate IPFS hashes
            if (!is_valid_ipfs($cover_ipfs)) json_error('Invalid cover IPFS hash format');
            if (!is_valid_ipfs($media_ipfs)) json_error('Invalid media IPFS hash format');
            if (!is_valid_ipfs($metadata_ipfs)) json_error('Invalid metadata IPFS hash format');
            
            // Collect all fields
            $nft_type = in_array($_POST['nft_type'] ?? '', ['music', 'musicvideo', 'art', 'film', 'album', 'ebook', 'audiobook'])
                       ? $_POST['nft_type'] : 'music';

            // v24: Album track data — parse with double-decode defence (F1).
            // JS always sends JSON.stringify(array); WordPress magic-quotes may add a layer of slashes.
            $album_tracks_json = null;
            $audiobook_chapters_json = null;
            if ($nft_type === 'album' && !empty($_POST['album_tracks_json'])) {
                $raw_atj = stripslashes($_POST['album_tracks_json']);
                $atj_decoded = json_decode($raw_atj, true);
                if (!is_array($atj_decoded)) {
                    // Double-decode defence: strip a second time and retry
                    $atj_decoded = json_decode(stripslashes($raw_atj), true);
                }
                $album_tracks_json = is_array($atj_decoded) ? json_encode($atj_decoded) : null;
            }
            // M1-b: AudioBook chaptered manifest (dual-decode magic-quotes defence)
            if ($nft_type === 'audiobook' && !empty($_POST['audiobook_chapters_json'])) {
                $raw_acj = stripslashes($_POST['audiobook_chapters_json']);
                $acj_decoded = json_decode($raw_acj, true);
                if (!is_array($acj_decoded)) { $acj_decoded = json_decode(stripslashes($raw_acj), true); }
                // M1-e2a: validate the manifest shape; reject junk rather than storing it.
                $acj_clean = imc_validate_book_manifest($acj_decoded);
                if ($acj_decoded !== null && $acj_clean === null) {
                    json_error('Invalid audiobook manifest - please re-add your chapters and pages');
                }
                $audiobook_chapters_json = $acj_clean ? json_encode($acj_clean) : null;
            }
            // M2-a: eBook page manifest (same dual-decode + validation as chapters).
            $ebook_pages_json = null;
            if ($nft_type === 'ebook' && !empty($_POST['ebook_pages_json'])) {
                $raw_epj = stripslashes($_POST['ebook_pages_json']);
                $epj_decoded = json_decode($raw_epj, true);
                if (!is_array($epj_decoded)) { $epj_decoded = json_decode(stripslashes($raw_epj), true); }
                $epj_clean = imc_validate_book_manifest($epj_decoded);
                if ($epj_decoded !== null && $epj_clean === null) {
                    json_error('Invalid eBook manifest - please re-add your pages');
                }
                $ebook_pages_json = $epj_clean ? json_encode($epj_clean) : null;
            }
            $description = sanitize_textarea_field(stripslashes($_POST['description'] ?? ''));
            $artist_name = sanitize_text_field(stripslashes($_POST['artist_name'] ?? ''));
            $collection_name = sanitize_text_field(stripslashes($_POST['collection_name'] ?? ''));
            $price_xrp = max(0, floatval($_POST['price_xrp'] ?? 0));
            $total_editions = max(1, min(10000, intval($_POST['total_editions'] ?? 1)));
            $transfer_fee = max(0, min(50000, intval($_POST['transfer_fee'] ?? 5000)));
            $is_transferable = ($_POST['is_transferable'] ?? '1') !== '0';
            // v608: XRPL guard - TransferFee>0 is only legal when tfTransferable is set.
            // A royalty on a non-transferable NFT is meaningless (no resale = no royalty
            // event) and produces temMALFORMED at mint (an affected listing). Coerce to 0.
            if (!$is_transferable) { $transfer_fee = 0; }
            $downloadable = ($_POST['downloadable'] ?? '0') === '1';
            // v390: stripslashes required — WordPress magic quotes escape POST JSON.
            // Without this, json_decode fails at mint time and base attributes are lost.
            // Same pattern as tiers_json (line 1342) and accepted_currencies (line 549).
            $metadata_json = stripslashes($_POST['metadata_json'] ?? '');
            
            // New v27 fields: preview + master storage
            $preview_ipfs = sanitize_text_field($_POST['preview_ipfs'] ?? '');
            $master_content_hash = sanitize_text_field($_POST['master_content_hash'] ?? '');
            
            // Validate preview IPFS if provided
            if ($preview_ipfs && !is_valid_ipfs($preview_ipfs)) {
                json_error('Invalid preview IPFS hash format');
            }
            
            // Validate master content hash format (sha256:hex)
            if ($master_content_hash && !preg_match('/^sha256:[a-f0-9]{64}$/', $master_content_hash)) {
                json_error('Invalid master content hash format (expected sha256:hex)');
            }
            
            // v377: Cover content hash (watermarked Music/MV/Film covers)
            $cover_content_hash = sanitize_text_field($_POST['cover_content_hash'] ?? '');

            // 8e (ENFORCED — the FLIP): post-8c every legitimate licensed path stores its cover
            // hash; post-8d watermark failures BLOCK the publish; and the flag-mode log proved
            // quiet over live traffic. Licensed + cover + NO hash is therefore ALWAYS a broken
            // creation flow — rejected before any row is written. Artist keeps a clear message.
            // NOTE: server vocabulary — 'musicvideo' is LOWERCASE here (:1726 whitelist).
            // NOTE: the reject wording deliberately avoids the phrase 'Cover protection failed'
            // (the client's RETRY-modal matcher) — a missing hash is not retryable in-session.
            $licensed_8e = in_array($nft_type, ['music', 'musicvideo', 'film', 'album', 'ebook', 'audiobook'], true);
            if ($licensed_8e && $cover_ipfs !== '' && $cover_content_hash === '') {
                listings_log("8E-REJECT broken-flow signature: licensed type '$nft_type' create REJECTED — cover present, NO cover_content_hash — artist=$artist");
                json_error('Listing rejected: cover protection data is missing from this submission. This means the creation flow did not complete correctly — please refresh the page and re-create your listing. Your files are safe and nothing was published. If this happens again, contact IMU support.', 400);
            }

            if ($cover_content_hash && !preg_match('/^sha256:[a-f0-9]{64}$/', $cover_content_hash)) {
                json_error('Invalid cover content hash format (expected sha256:hex)');
            }

            // M1-e1: AudioBook sub-type + optional back cover. Front-cover rules above are unchanged.
            $audiobook_format = '';
            if ($nft_type === 'audiobook') {
                $af_in = sanitize_text_field($_POST['audiobook_format'] ?? '');
                $audiobook_format = in_array($af_in, ['single', 'chaptered'], true) ? $af_in : '';
            }
            // FIX: the book type was posted by the wizard but never read, so the column
            // stayed NULL and the marketplace badge could never render. Mirrors the
            // audiobook_format handling directly above.
            $ebook_format = '';
            if ($nft_type === 'ebook') {
                $ef_in = sanitize_text_field($_POST['ebook_format'] ?? '');
                $ebook_format = in_array($ef_in, ['comic', 'short', 'novel', 'magazine'], true) ? $ef_in : '';
            }
            $back_cover_ipfs         = sanitize_text_field($_POST['back_cover_ipfs'] ?? '');
            $back_cover_content_hash = sanitize_text_field($_POST['back_cover_content_hash'] ?? '');
            if ($back_cover_ipfs !== '' && !is_valid_ipfs($back_cover_ipfs)) {
                json_error('Invalid back cover IPFS hash format');
            }
            if ($back_cover_content_hash !== '' && !preg_match('/^sha256:[a-f0-9]{64}$/', $back_cover_content_hash)) {
                json_error('Invalid back cover content hash format (expected sha256:hex)');
            }
            // Protect-or-block, mirroring the 8e front-cover rule: a licensed type may not ship a
            // public back cover without its protection hash (the original stays holder-only).
            if ($licensed_8e && $back_cover_ipfs !== '' && $back_cover_content_hash === '') {
                listings_log("8E-REJECT back cover: licensed type '$nft_type' create REJECTED - back cover present, NO back_cover_content_hash - artist=$artist");
                json_error('Listing rejected: back cover protection data is missing from this submission. Please re-upload the back cover.');
            }
            
            // Launch scheduling fields
            $launch_type = in_array($_POST['launch_type'] ?? '', ['immediate', 'scheduled']) 
                          ? $_POST['launch_type'] : 'immediate';
            $launch_at = null;
            $launch_timezone = sanitize_text_field($_POST['launch_timezone'] ?? 'UTC');
            
            if ($launch_type === 'scheduled' && !empty($_POST['launch_at'])) {
                $launch_at = sanitize_text_field($_POST['launch_at']);
                // Validate datetime format
                if (!strtotime($launch_at)) {
                    json_error('Invalid launch date/time format');
                }
            }
            
            // v450: Scheduled listings MUST have a valid launch date.
            // Without this guard, a missing launch_at stores '0000-00-00 00:00:00'
            // in the DATETIME column, which bypasses the purchase-time scheduling
            // check (strtotime returns a past date, allowing immediate purchase).
            if ($launch_type === 'scheduled' && empty($launch_at)) {
                json_error('Scheduled listings require a launch date and time. Please set a release date or choose "Publish Immediately".');
            }
            
            // OE-v1: Open Edition fields
            $edition_type              = (($_POST['edition_type'] ?? 'fixed') === 'open') ? 'open' : 'fixed';
            $open_edition_ends_at      = null;
            $open_edition_duration_days= null;
            $open_edition_closed_at    = null;
            $primary_mint_fee_pct      = 0.00;

            if ($edition_type === 'open') {
                // OE: tiers are not supported
                if (!empty($_POST['tiers_json'])) {
                    $tiers_check = json_decode(stripslashes($_POST['tiers_json']), true);
                    if (is_array($tiers_check) && count($tiers_check) >= 2) {
                        json_error('Open Editions are not available for tiered collections.');
                    }
                }

                // Parse end datetime (required)
                $raw_end = sanitize_text_field($_POST['open_edition_ends_at'] ?? '');
                if (empty($raw_end)) {
                    json_error('Open Edition requires a mint end date/time (open_edition_ends_at).');
                }
                $end_ts = strtotime($raw_end);
                if (!$end_ts || $end_ts <= 0) {
                    json_error('Invalid Open Edition end date/time format.');
                }
                if ($end_ts <= time()) {
                    json_error('Open Edition end date/time must be in the future.');
                }

                // Effective start = launch_at (if scheduled) or now
                $effective_start_ts = ($launch_type === 'scheduled' && $launch_at)
                    ? strtotime($launch_at)
                    : time();

                $duration_secs = $end_ts - $effective_start_ts;
                if ($duration_secs < 3600) {
                    json_error('Open Edition minimum duration is 1 hour.');
                }
                if ($duration_secs > 365 * 24 * 3600) {
                    json_error('Open Edition maximum duration is 1 year.');
                }

                $open_edition_ends_at       = date('Y-m-d H:i:s', $end_ts);
                $open_edition_duration_days = round($duration_secs / 86400, 2);
                $total_editions             = 0; // unlimited
                $primary_mint_fee_pct       = 0.00; // reserved for Batch TX activation

                listings_log("OE listing: ends=$open_edition_ends_at duration={$open_edition_duration_days}d");
            }

            // Use client-provided collection taxon if available, otherwise generate
            $client_taxon = isset($_POST['collection_taxon']) ? sanitize_text_field($_POST['collection_taxon']) : '';
            if ($client_taxon !== '' && preg_match('/^\d+$/', $client_taxon)) {
                $collection_taxon = intval($client_taxon) & 0xFFFFFFFF;
            } else {
                $collection_taxon = generate_collection_taxon($artist, $collection_name);
            }
            
            // v71: Multi-currency support
            $accepted_currencies = null;
            if (!empty($_POST['accepted_currencies'])) {
                $validated = validate_accepted_currencies($_POST['accepted_currencies']);
                if ($validated) {
                    $accepted_currencies = json_encode($validated);
                    listings_log("Created listing with " . count($validated) . " accepted currencies");
                    
                    // v190: Ensure fee wallet has trustlines for all accepted tokens
                    $trustline_result = ensure_fee_wallet_trustlines_for_listing($validated);
                    if (!$trustline_result['success']) {
                        listings_log("WARNING: Fee wallet trustline setup had errors: " . implode(', ', $trustline_result['errors']));
                        // Non-blocking: listing continues — trustline retried at fee collection
                    }
                }
            }
            // Default to XRP if no currencies specified.
            // v930 (4 Sep 2026): the `&& $price_xrp > 0` guard was the bug. A FREE listing (and a PWYW
            // listing with a 0 floor) posts no currencies AND has price_xrp = 0, so this was skipped,
            // $accepted_currencies stayed null, and the bind below wrote '' -- which the live column's
            // json_valid() CHECK rejects, killing the whole INSERT ('Failed to create listing').
            // Writing the XRP entry at price 0 is EXACTLY what the read path already synthesises when
            // the column is empty (listings-handler L1305-1309), so nothing downstream changes -- it is
            // simply valid JSON instead of an empty string.
            if (!$accepted_currencies) {
                $accepted_currencies = json_encode([
                    ['currency' => 'XRP', 'price' => $price_xrp, 'enabled' => true, 'name' => 'XRP', 'icon' => '💧']
                ]);
            }
            
            // v75: Pricing mode support
            $pricing_mode = 'static';
            if (!empty($_POST['pricing_mode']) && in_array($_POST['pricing_mode'], ['static', 'dynamic', 'pwyw', 'free'])) {
                $pricing_mode = $_POST['pricing_mode'];
                listings_log("Listing pricing_mode: $pricing_mode");
            }

            // PP-0: progressive pricing config — validated + stored INERT (read by nothing until PP-1)
            $progressive_json = null;
            // PP-4 (R8 final): tiered listings carry progressive configs like any other --
            // the ladder is listing-level; tiers stay random rarity pools assigned at mint.
            if (!empty($_POST['progressive'])) {
                $pp = imc_validate_progressive_config($_POST['progressive'], $accepted_currencies, $pricing_mode);
                if (!$pp['ok']) json_error($pp['error']);
                $progressive_json = $pp['json'];
                if ($progressive_json !== null) listings_log("PP-0: progressive config stored: " . substr($progressive_json, 0, 200));
            }
            
            // v75: USD base price for dynamic mode
            $price_usd = null;
            if (!empty($_POST['price_usd']) && $pricing_mode === 'dynamic') {
                $price_usd = max(0, floatval($_POST['price_usd']));
                // v193: Enforce max USD price for compliance + volatility protection
                if ($price_usd > IMC_MAX_PRICE_USD) {
                    json_error('Maximum dynamic price is $' . number_format(IMC_MAX_PRICE_USD) . ' USD for compliance reasons');
                }
                listings_log("Listing price_usd: $price_usd");
            }
            
            // v75: Mint limit per wallet
            $mint_limit_enabled = !empty($_POST['mint_limit_enabled']) ? 1 : 0;
            $mint_limit_per_wallet = 0;
            if ($mint_limit_enabled && !empty($_POST['mint_limit_per_wallet'])) {
                $mint_limit_per_wallet = max(1, intval($_POST['mint_limit_per_wallet']));
                listings_log("Listing mint_limit: $mint_limit_per_wallet per wallet");
            }
            
            // Calculate platform fee based on content type
            // v143: Count actual master files for fee calculation
            // Art: every tier = unique master; Music/MV/Film: 1 default + overrides
            $master_files = 1;

            // v24: Album — master_files = track count (each track has its own protected master)
            // Set BEFORE tiers block so calculate_platform_fee() receives the correct value.
            if ($nft_type === 'album' && !empty($album_tracks_json)) {
                $atj_for_count = json_decode($album_tracks_json, true);
                if (is_array($atj_for_count)) {
                    $master_files = max(1, count($atj_for_count));
                }
            }

            if (!empty($_POST['tiers_json'])) {
                $tiers_preview = json_decode(stripslashes($_POST['tiers_json']), true);
                if (is_array($tiers_preview) && count($tiers_preview) >= 2) {
                    if ($nft_type === 'art') {
                        // Art: each tier's artwork IS the master — charge per tier
                        $master_files = count($tiers_preview);
                    } else {
                        // Music/MV/Film: count DISTINCT master_content_hash values.
                        // Pool masters are shared across tiers — artist pays once per
                        // unique file, not once per tier that references it.
                        $seen_hashes = [];
                        foreach ($tiers_preview as $t) {
                            if (!empty($t['master_content_hash'])) {
                                $seen_hashes[$t['master_content_hash']] = true;
                            }
                        }
                        $master_files = max(1, count($seen_hashes));
                    }

                    // v591 Slice 6: block publish if any tier box lacks a cover image.
                    // Imageless tiers cannot be persisted (cover_ipfs is NOT NULL) and would
                    // otherwise be silently dropped, shrinking the collection. Abort BEFORE the
                    // listing INSERT so nothing is created; name the gaps so the artist can finish.
                    $missing_covers = [];
                    foreach ($tiers_preview as $ti => $tp) {
                        if (trim((string)($tp['cover_ipfs'] ?? '')) === '') { $missing_covers[] = ($ti + 1); }
                    }
                    if (!empty($missing_covers)) {
                        $shown = array_slice($missing_covers, 0, 10);
                        $more  = count($missing_covers) > 10 ? ' …(' . count($missing_covers) . ' total)' : '';
                        json_error('Cannot publish yet — tier box(es) missing a cover image: ' . implode(', ', $shown) . $more . '. Please upload them and try again.', 422);
                    }
                }
            }
            $platform_fee = calculate_platform_fee($total_editions, $nft_type, $master_files, $edition_type);

            // FREE MINT PROMO: quota available -> waive the whole fee (product ruling:
            // edition type / tiers / edition count do NOT matter). Decision here so the
            // stored platform_fee_amount is 0; activation happens just before the response.
            $imc_fm_waive = false;
            if (imc_fm_remaining($artist, $nft_type) > 0) {
                $imc_fm_waive = true;
                $platform_fee = 0;
                listings_log("FREE MINT PROMO: fee waived at create for artist=$artist type=$nft_type (" . current_time('Y-m') . ")");
            }

            // U3: unlockable content claims -- validated against the artist's POOL,
            // priced server-side from pool sizes via the ruled grid. The fee ADDS to
            // the (possibly promo-waived) listing component; the promo NEVER waives
            // unlockable fees (ruling R3). Slots are inserted after the tiers block
            // (tier ids needed); pricing must happen HERE so the stored
            // platform_fee_amount is the true total at insert.
            $imc_ul = imc_ul_parse_claims($artist, $_POST['unlockables'] ?? '');
            if (!empty($imc_ul['error'])) json_error($imc_ul['error']);
            $imc_ul_claims    = $imc_ul['claims'];
            $imc_ul_fee_total = floatval($imc_ul['total']);
            if ($imc_ul_fee_total > 0) {
                $platform_fee += $imc_ul_fee_total;
                listings_log("U3: +{$imc_ul_fee_total} XRP unlockable fees (" . count($imc_ul_claims) . " files) for artist=$artist");
            }
            
            // v611 (additive): content-aware draft de-duplication for SIMPLE listings only.
            // A retry, lost-response, or resumed-form re-submit carries identical content; without
            // this, each re-submit inserts another draft (an observed pile-up). If an unpaid,
            // non-tiered, non-allowlisted draft with identical content + core config already exists
            // for this artist (last 2 days), resume it instead of inserting a duplicate. Tiered and
            // allowlisted creates skip this block entirely and fall through to the unchanged path.
            if (empty($_POST['tiers_json']) && empty($_POST['allowlist_data'])) {
                $dedup_allowlists = $wpdb->prefix . 'imc_allowlists';
                if ($master_content_hash) {
                    $dedup_content_sql  = "AND l.master_content_hash = %s";
                    $dedup_content_args = [$master_content_hash];
                } else {
                    $dedup_content_sql  = "AND (l.master_content_hash IS NULL OR l.master_content_hash = '') AND l.cover_ipfs = %s AND l.metadata_ipfs = %s";
                    $dedup_content_args = [$cover_ipfs, $metadata_ipfs];
                }
                $dedup_sql = "SELECT l.id, l.platform_fee_amount
                                FROM {$listings_table} l
                               WHERE l.artist_account = %s
                                 AND l.status = 'draft'
                                 AND l.platform_fee_paid = 0
                                 AND l.has_tiers = 0
                                 AND l.nft_type = %s
                                 AND l.edition_type = %s
                                 AND l.total_editions = %d
                                 AND l.price_xrp = %f
                                 AND l.collection_taxon = %d
                                 {$dedup_content_sql}
                                 AND l.created_at > (NOW() - INTERVAL 2 DAY)
                                 AND NOT EXISTS (SELECT 1 FROM {$dedup_allowlists} a WHERE a.listing_id = l.id)
                               ORDER BY l.created_at DESC
                               LIMIT 1";
                $dedup_args = array_merge(
                    [$artist, $nft_type, $edition_type, intval($total_editions), floatval($price_xrp), intval($collection_taxon)],
                    $dedup_content_args
                );
                $dedup_existing = $wpdb->get_row($wpdb->prepare($dedup_sql, $dedup_args), ARRAY_A);
                if ($dedup_existing) {
                    $dedup_id  = intval($dedup_existing['id']);
                    $dedup_fee = floatval($dedup_existing['platform_fee_amount']);
                    listings_log("create: resumed existing unpaid draft ID=$dedup_id for artist=$artist (content match) - no duplicate inserted");
                    json_success([
                        'listing_id'         => $dedup_id,
                        'status'             => 'draft',
                        'has_tiers'          => false,
                        'tier_count'         => 0,
                        'has_allowlist'      => false,
                        'platform_fee_xrp'   => $dedup_fee,
                        'platform_fee_drops' => intval($dedup_fee * 1000000),
                        'fee_wallet'         => PLATFORM_FEE_WALLET,
                        'resumed'            => true,
                        'message'            => 'Resuming your pending listing - pay the platform fee to publish.'
                    ]);
                }
            }

            // v23 FIX: Duplicate listing detection — same artist + same name within 60 seconds.
            // Prevents double-submissions from double-clicks, form re-submissions, and tab duplicates.
            // Only blocks true duplicates — different artists or different names always pass through.
            $recent_duplicate = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $listings_table
                 WHERE artist_account = %s
                   AND nft_name = %s
                   AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)
                   AND status != 'cancelled'
                 LIMIT 1",
                $artist, $nft_name
            ));
            if ($recent_duplicate) {
                json_error('Duplicate listing detected — this collection was just created (ID: ' . intval($recent_duplicate) . '). Please wait before submitting again.', 409);
            }
            
            // Build listing data
            $now = current_time('mysql');
            // ⚠ DEAD CODE — this $data array is NOT executed. The real INSERT is the raw
            // $wpdb->prepare($sql) below (built ~40 lines down). This array is never read
            // (no $data reference exists between here and the query). It once caused a
            // false-positive audit: it lists 'progressive_json' => $progressive_json, which
            // fooled a reviewer into believing create persisted the column — it did not,
            // until R1 added progressive_json to the executed INSERT. Kept only as a legible
            // column reference; DO NOT wire behaviour to it. If it drifts from the real
            // INSERT again, delete it outright.
            $data = [
                'artist_account'      => $artist,
                'artist_name'         => $artist_name,
                'nft_type'            => $nft_type,
                'nft_name'            => $nft_name,
                'description'         => $description,
                'cover_ipfs'          => $cover_ipfs,
                'progressive_json'    => $progressive_json, // PP-0: inert config
                'media_ipfs'          => $media_ipfs,
                'preview_ipfs'        => $preview_ipfs ?: null,
                'metadata_ipfs'       => $metadata_ipfs,
                'metadata_json'       => $metadata_json,
                'master_content_hash' => $master_content_hash ?: null,
                'collection_name'     => $collection_name,
                'collection_taxon'    => $collection_taxon,
                'price_xrp'           => $price_xrp,
                'accepted_currencies' => $accepted_currencies,
                'total_editions'      => $total_editions,
                'minted_count'        => 0,
                'transfer_fee'        => $transfer_fee,
                'is_transferable'     => $is_transferable ? 1 : 0,
                'downloadable'        => $downloadable ? 1 : 0,
                'status'              => 'draft',
                'platform_fee_amount' => $platform_fee,
                'platform_fee_paid'   => 0,
                'launch_type'         => $launch_type,
                'launch_at'           => $launch_at,
                'launch_timezone'     => $launch_timezone,
                'created_at'          => $now,
                'updated_at'          => $now,
                // v24: Album track data — null for non-album types (ATOMIC: must match INSERT below)
                'album_tracks_json'   => $album_tracks_json,
                'audiobook_chapters_json' => $audiobook_chapters_json,
                'audiobook_format'    => $audiobook_format ?: null,
                'ebook_format'        => $ebook_format ?: null,
                'back_cover_ipfs'     => $back_cover_ipfs ?: null,
                'back_cover_content_hash' => $back_cover_content_hash ?: null,
                'ebook_pages_json'    => null,
            ];
            
            global $wpdb, $listings_table;
            
            // Use raw prepared INSERT to bypass WordPress 6.1+ strict
            // column type detection which fails when col_meta cache is stale
            // after ALTER TABLE migration in the same request.
            $sql = $wpdb->prepare(
                "INSERT INTO {$listings_table} 
                (artist_account, artist_name, nft_type, nft_name, description,
                 cover_ipfs, media_ipfs, preview_ipfs, metadata_ipfs, metadata_json,
                 master_content_hash, cover_content_hash, progressive_json, collection_name, collection_taxon, price_xrp,
                 accepted_currencies, total_editions, minted_count, transfer_fee, 
                 is_transferable, downloadable, status, platform_fee_amount, 
                 platform_fee_paid, launch_type, launch_at, launch_timezone,
                 pricing_mode, price_usd, mint_limit_enabled, mint_limit_per_wallet,
                 edition_type, open_edition_ends_at, open_edition_duration_days,
                 primary_mint_fee_pct,
                 album_tracks_json, audiobook_chapters_json, ebook_pages_json,
                 audiobook_format, ebook_format, back_cover_ipfs, back_cover_content_hash,
                 created_at, updated_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, %f, %s, %d, %d, %d, %d, %d, %s, %f, %d, %s, %s, %s, %s, %f, %d, %d, %s, %s, %f, %f, %s, %s, %s, %s, %s, %s, %s, %s, %s)",
                $artist,
                $artist_name,
                $nft_type,
                $nft_name,
                $description,
                $cover_ipfs,
                $media_ipfs,
                $preview_ipfs ?: '',
                $metadata_ipfs,
                $metadata_json,
                $master_content_hash ?: '',
                $cover_content_hash ?: '',   // v377: Original cover hash (watermarked Music/MV/Film)
                $progressive_json,           // R1 (PP-6 P7): persist progressive config AT CREATE — TEXT, null when absent (was silently dropped: built into the dead $data array below but never into this executed INSERT)
                $collection_name,
                $collection_taxon,
                $price_xrp,
                $accepted_currencies ?: '',  // v71: Multi-currency JSON
                $total_editions,
                0,               // minted_count
                $transfer_fee,
                $is_transferable ? 1 : 0,
                $downloadable ? 1 : 0,
                'draft',         // status
                $platform_fee,
                0,               // platform_fee_paid
                $launch_type,
                $launch_at ?: '',
                $launch_timezone,
                $pricing_mode,   // v75: Pricing mode
                $price_usd ?: 0, // v75: USD base price (0 if not set)
                $mint_limit_enabled,
                $mint_limit_per_wallet,
                $edition_type,                        // OE-v1
                $open_edition_ends_at ?: '',          // OE-v1
                $open_edition_duration_days ?: null,  // OE-v1
                $primary_mint_fee_pct,                // OE-v1 (0.00 now, activates post Batch TX)
                $album_tracks_json ?: '',             // v24: Album track data (empty for non-album)
                $audiobook_chapters_json ?: '',       // M1-b: AudioBook chapter manifest
                $ebook_pages_json ?: '',              // M2-a: eBook page manifest
                $audiobook_format ?: null,            // M1-e1: single | chaptered (NULL for other types)
                $ebook_format ?: null,                // FIX: comic | short | novel (NULL for other types)
                $back_cover_ipfs ?: null,             // M1-e1: optional public back cover
                $back_cover_content_hash ?: null,     // M1-e1: back cover protection hash
                $now,
                $now
            );
            $result = $wpdb->query($sql);
            
            if ($result === false) {
                listings_log("Failed to create listing: " . $wpdb->last_error);
                json_error('Failed to create listing');
            }
            
            $listing_id = $wpdb->insert_id;
            listings_log("Listing created: ID=$listing_id, artist=$artist, name=$nft_name");

            // v624: AI Art create-time tagging — ART ONLY (Music/MV/Album/Film handled later).
            // Derives flags from the SAME metadata_json just stored, identical logic to
            // ai_backfill_art. Additive UPDATE that fires only for AI art and touches only the
            // three AI columns. The listing (draft) is already committed above, so this can
            // never affect creation success; the backfill remains the safety net.
            if ($listing_id && $nft_type === 'art') {
                list($ai_gen, $ai_md, $ai_pf) = imc_derive_ai_flags($metadata_json);
                if ($ai_gen) {
                    $wpdb->update(
                        $listings_table,
                        ['ai_generated' => 1, 'ai_mode' => $ai_md, 'ai_platform' => $ai_pf],
                        ['id' => intval($listing_id)],
                        ['%d', '%s', '%s'],
                        ['%d']
                    );
                }
            }
            
            // ================================================================
            // Save rarity tiers (if provided)
            // ================================================================
            $tier_count = 0;
            $tier_editions_sum = 0; // v447: Accumulate actual edition sum from saved tiers
            if ($listing_id && !empty($_POST['tiers_json'])) {
                $tiers_data = json_decode(stripslashes($_POST['tiers_json']), true);
                if (is_array($tiers_data) && count($tiers_data) >= 2) {
                    $tiers_table = $wpdb->prefix . 'imc_listing_tiers';
                    foreach ($tiers_data as $idx => $tier) {
                        $tier_cover = sanitize_text_field($tier['cover_ipfs'] ?? '');
                        if (!$tier_cover) continue; // Skip tiers without covers
                        
                        $tier_preview = !empty($tier['preview_ipfs']) 
                            ? sanitize_text_field($tier['preview_ipfs']) : null;
                        $tier_master = !empty($tier['master_content_hash']) 
                            ? sanitize_text_field($tier['master_content_hash']) : null;
                        // v377: Cover content hash for watermarked Music/MV/Film tier covers
                        $tier_cover_hash = !empty($tier['cover_content_hash']) 
                            ? sanitize_text_field($tier['cover_content_hash']) : null;
                        $tier_traits = !empty($tier['traits']) && is_array($tier['traits']) 
                            ? wp_json_encode($tier['traits']) : null;
                        
                        $wpdb->insert($tiers_table, [
                            'listing_id'          => $listing_id,
                            'tier_name'           => sanitize_text_field($tier['name'] ?? ('Tier ' . ($idx + 1))),
                            'tier_order'          => intval($idx),
                            'cover_ipfs'          => $tier_cover,
                            'preview_ipfs'        => $tier_preview,
                            'master_content_hash' => $tier_master,
                            'cover_content_hash'  => $tier_cover_hash,  // v377
                            'total_editions'      => max(1, intval($tier['editions'] ?? 1)),
                            'minted_count'        => 0,
                            'tier_traits'         => $tier_traits,
                            'created_at'          => $now
                        ]);
                        // U3: order -> id map for per-tier unlockable claims
                        $imc_tier_id_by_order[intval($idx)] = intval($wpdb->insert_id);
                        $tier_count++;
                        $tier_editions_sum += max(1, intval($tier['editions'] ?? 1)); // v447: Track actual sum
                    }
                    
                    if ($tier_count >= 2) {
                        // Mark listing as tiered + set cover to rarest tier's cover
                        $first_tier_cover = sanitize_text_field($tiers_data[0]['cover_ipfs'] ?? '');
                        // v699 (tiered card cover): OPTIONAL creator-uploaded card image for the
                        // LISTING CARD only. Tiered listings have no single "cover NFT", so the card
                        // otherwise falls back to the first tier's image. Display-only — tiered mints
                        // always build metadata from the PER-TIER cover (see mint-on-demand-handler),
                        // so this never touches minted NFTs. Falls back to $first_tier_cover when
                        // absent or invalid, so existing tiered listings and older clients are
                        // completely unchanged (backward compatible).
                        $tier_card_cover = sanitize_text_field($_POST['tier_card_cover'] ?? '');
                        if ($tier_card_cover && !is_valid_ipfs($tier_card_cover)) { $tier_card_cover = ''; }
                        $effective_cover = $tier_card_cover ?: $first_tier_cover;
                        $updates = ['has_tiers' => 1, 'updated_at' => $now];
                        if ($effective_cover) {
                            $updates['cover_ipfs'] = $effective_cover;
                        }
                        
                        // v447 FIX: Recalculate total_editions from ACTUAL tier sum.
                        // The frontend's syncEditionsFromTiers() is unreliable for large
                        // tier counts — the backend must be the authoritative source.
                        // This prevents total_editions=1 on tiered collections.
                        if ($tier_editions_sum > 0) {
                            $updates['total_editions'] = $tier_editions_sum;
                            if (empty($imc_fm_waive)) {
                                // Recalculate platform fee with correct edition count
                                // U3: the unlockable component rides on top of the corrected listing fee.
                                $corrected_fee = calculate_platform_fee($tier_editions_sum, $nft_type, $master_files, $edition_type)
                                               + floatval($imc_ul_fee_total ?? 0);
                                $updates['platform_fee_amount'] = $corrected_fee;
                                $platform_fee = $corrected_fee; // v447: Update response variable too
                                listings_log("v447 tier fix: total_editions corrected from {$total_editions} to {$tier_editions_sum}, fee corrected to {$corrected_fee} XRP");
                            } else {
                                // FREE MINT PROMO: editions still corrected; the waived LISTING fee stays 0 --
                                // U3: the stored amount stays the unlockable component (never waived).
                                $updates['platform_fee_amount'] = floatval($imc_ul_fee_total ?? 0);
                                $platform_fee = floatval($imc_ul_fee_total ?? 0);
                                listings_log("v447 tier fix (promo): total_editions corrected from {$total_editions} to {$tier_editions_sum}; listing fee stays WAIVED (amount=" . floatval($imc_ul_fee_total ?? 0) . " unlockables)");
                            }
                        }
                        
                        $wpdb->update($listings_table, $updates, ['id' => $listing_id]);
                        listings_log("Tiers saved: {$tier_count} tiers for listing ID=$listing_id");
                    }
                }
            }
            
            // ================================================================
            // v71: Create allowlist if provided
            // ================================================================
            $allowlist_created = false;
            if ($listing_id && !empty($_POST['allowlist_data'])) {
                $allowlist_raw = stripslashes($_POST['allowlist_data']);
                $allowlist_data = json_decode($allowlist_raw, true);
                
                if (is_array($allowlist_data) && !empty($allowlist_data['enabled']) && !empty($allowlist_data['wallets'])) {
                    $allowlists_table = $wpdb->prefix . 'imc_allowlists';
                    $entries_table = $wpdb->prefix . 'imc_allowlist_entries';
                    
                    // Create allowlist
                    $allowlist_type = sanitize_text_field($allowlist_data['type'] ?? 'early_access');
                    // v381: Validate against known types (must match ENUM in wp_imc_allowlists)
                    if (!in_array($allowlist_type, ['early_access', 'discount', 'exclusive', 'limit_override', 'discount_limited'])) {
                        $allowlist_type = 'early_access';
                    }
                    $discount_percent = floatval($allowlist_data['discount_percent'] ?? 0);
                    $early_hours = intval($allowlist_data['early_access_hours'] ?? 24);
                    $max_mint = intval($allowlist_data['max_mint'] ?? 5);
                    if ($max_mint < 1) $max_mint = 5;

                    // v729 (A3): fixed-price / free-claim parity with the dashboard. Only the
                    // discount types may carry a price — B3 left creation-flow Holder
                    // Discounts with NO pricing at all (pct 0, price NULL).
                    $custom_price = null;
                    if (in_array($allowlist_type, ['discount', 'discount_limited'])
                        && isset($allowlist_data['custom_price'])
                        && $allowlist_data['custom_price'] !== '' && $allowlist_data['custom_price'] !== null) {
                        $custom_price = floatval($allowlist_data['custom_price']);
                        if ($custom_price < 0) $custom_price = 0;
                    }

                    $wpdb->insert($allowlists_table, [
                        'listing_id' => $listing_id,
                        'name' => 'Launch Allowlist',
                        'type' => $allowlist_type,
                        'discount_percent' => $discount_percent,
                        'custom_price' => $custom_price,
                        'early_access_hours' => $early_hours,
                        'max_mint_override' => $max_mint, // v381: Fixed column name (was 'max_mint' — non-existent)
                        'created_at' => $now,
                        'updated_at' => $now
                    ]);
                    $allowlist_id = $wpdb->insert_id;

                    // v731 (A4b): per-token rules from the creation flow. Same law as the
                    // dashboard: discount types only, never on a free claim, percent 1-99,
                    // price > 0 in that token. One rule per currency; invalid rows dropped.
                    if ($allowlist_id && in_array($allowlist_type, ['discount', 'discount_limited'])
                        && !empty($allowlist_data['currency_benefits']) && is_array($allowlist_data['currency_benefits'])
                        && !($custom_price !== null && $custom_price == 0)) {
                        $imc_cb_out = [];
                        foreach ($allowlist_data['currency_benefits'] as $imc_cb_r) {
                            if (!is_array($imc_cb_r) || !isset($imc_cb_r['currency'], $imc_cb_r['mode'], $imc_cb_r['value'])) continue;
                            $imc_cb_cur = strtoupper(trim(sanitize_text_field($imc_cb_r['currency'])));
                            if ($imc_cb_cur === '' || strlen($imc_cb_cur) > 40) continue;
                            $imc_cb_mode = $imc_cb_r['mode'] === 'price' ? 'price' : ($imc_cb_r['mode'] === 'percent' ? 'percent' : null);
                            if (!$imc_cb_mode) continue;
                            $imc_cb_v = floatval($imc_cb_r['value']);
                            if ($imc_cb_mode === 'percent' && ($imc_cb_v < 1 || $imc_cb_v > 99)) continue;
                            if ($imc_cb_mode === 'price' && !($imc_cb_v > 0)) continue;
                            $imc_cb_e = ['currency' => $imc_cb_cur, 'mode' => $imc_cb_mode, 'value' => $imc_cb_v];
                            if (!empty($imc_cb_r['issuer']) && preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $imc_cb_r['issuer'])) $imc_cb_e['issuer'] = $imc_cb_r['issuer'];
                            $imc_cb_out[$imc_cb_cur] = $imc_cb_e;
                        }
                        if (count($imc_cb_out)) {
                            $wpdb->update($allowlists_table, ['currency_benefits' => wp_json_encode(array_values($imc_cb_out))], ['id' => $allowlist_id]);
                        }
                    }

                    if ($allowlist_id) {
                        // Add wallet entries
                        // v729 (A3): each item may be a bare address (legacy payloads —
                        // unchanged behaviour) or a raw "wallet,amount" line from the new
                        // frontend. For the quantity-aware types the amount is kept, with
                        // $max_mint as the default — so a Holder Discount entry can never
                        // land without an amount. Other types no longer stamp
                        // custom_max_mint at all (G5: verified reader-free — only
                        // discount_limited and limit_override ever read it).
                        $uses_quantity = in_array($allowlist_type, ['limit_override', 'discount_limited']);
                        $wallets = array_slice($allowlist_data['wallets'], 0, 1000); // Limit to 1000 wallets
                        $added = 0; $with_amounts = 0;
                        foreach ($wallets as $wallet_line) {
                            $wallet_line = sanitize_text_field($wallet_line);
                            $parts = str_getcsv($wallet_line);
                            if (empty($parts)) continue;
                            $wallet_idxs = [];
                            foreach ($parts as $idx => $val) {
                                if (preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', trim($val))) $wallet_idxs[] = $idx;
                            }
                            if (empty($wallet_idxs)) continue;
                            foreach ($wallet_idxs as $wi) {
                                $quantity = null;
                                if ($uses_quantity) {
                                    if (count($wallet_idxs) === 1) {
                                        // Nearest positive number to the wallet column (v383 rule)
                                        $max_dist = max($wi, count($parts) - 1 - $wi);
                                        for ($dist = 1; $dist <= $max_dist && $quantity === null; $dist++) {
                                            foreach ([$wi - $dist, $wi + $dist] as $check_idx) {
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
                                    if ($quantity !== null) $with_amounts++;
                                    if ($quantity === null) $quantity = $max_mint; // default — never NULL
                                }
                                $entry_row = [
                                    'allowlist_id' => $allowlist_id,
                                    'wallet_address' => trim($parts[$wi]),
                                    'minted_count' => 0,
                                    'created_at' => $now
                                ];
                                if ($uses_quantity) $entry_row['custom_max_mint'] = $quantity;
                                $wpdb->insert($entries_table, $entry_row);
                                $added++;
                            }
                        }
                        $allowlist_created = $added > 0;
                        listings_log("Allowlist created: ID=$allowlist_id, type=$allowlist_type, wallets=$added, with_amounts=$with_amounts, custom_price=" . var_export($custom_price, true) . " for listing ID=$listing_id");
                    }
                }
            }
            
            // U3: claim the unlockable slots (copy pool rows -> claimed rows). Runs after
            // the tiers block so per-tier claims resolve tier_order -> real tier id.
            if (!empty($imc_ul_claims)) {
                $imc_ul_t = $wpdb->prefix . 'imc_listing_unlockables';
                foreach ($imc_ul_claims as $imc_ul_c) {
                    $imc_ul_tid = 0;
                    if ($imc_ul_c['tier_order'] !== null) {
                        if (!isset($imc_tier_id_by_order[$imc_ul_c['tier_order']])) {
                            listings_log("U3 WARN: unlockable tier_order {$imc_ul_c['tier_order']} has no tier -- claimed listing-wide (listing=$listing_id)");
                        } else {
                            $imc_ul_tid = $imc_tier_id_by_order[$imc_ul_c['tier_order']];
                        }
                    }
                    $imc_ul_ok = $wpdb->insert($imc_ul_t, [
                        'artist_account'    => $artist,
                        'listing_id'        => intval($listing_id),
                        'tier_id'           => $imc_ul_tid,
                        'content_hash'      => $imc_ul_c['pool']['content_hash'],
                        'storage_path'      => $imc_ul_c['pool']['storage_path'],
                        'original_filename' => $imc_ul_c['pool']['original_filename'],
                        'file_size'         => intval($imc_ul_c['pool']['file_size']),
                        'mime_type'         => $imc_ul_c['pool']['mime_type'],
                        'label'             => $imc_ul_c['label'],
                        'fee_xrp'           => $imc_ul_c['fee'],
                        'fee_paid'          => 0,
                        'sort_order'        => 0,
                        'created_at'        => $now,
                    ]);
                    if (!$imc_ul_ok) {
                        listings_log("U3 ERROR: slot insert failed for listing=$listing_id hash={$imc_ul_c['pool']['content_hash']}: " . $wpdb->last_error);
                        json_error('Failed to attach unlockable content -- please try again');
                    }
                }
                listings_log("U3: " . count($imc_ul_claims) . " unlockable slot(s) claimed for listing=$listing_id (fees {$imc_ul_fee_total} XRP)");
            }

            // FREE MINT PROMO -- auto-publish AT THE END of create (tiers + allowlist are
            // already saved above, so the listing goes live complete). The PROMO-{id} tx
            // marker is unique per listing (v248 replay guard shape) and is what the quota
            // count keys on. platform_fee_amount re-stamped 0 as belt-and-braces vs v447.
            if (!empty($imc_fm_waive) && empty($imc_ul_fee_total)) {
                $wpdb->update($listings_table, [
                    'status'              => 'active',
                    'platform_fee_paid'   => 1,
                    'platform_fee_amount' => 0,
                    'platform_fee_tx'     => 'PROMO-' . $listing_id,
                    'published_at'        => $now,
                    'updated_at'          => $now
                ], ['id' => $listing_id]);
                imc_ul_mark_paid($listing_id, 'PROMO-' . $listing_id); // no-op without slots
                listings_log("FREE MINT PROMO: listing=$listing_id AUTO-PUBLISHED fee-free (type=$nft_type artist=$artist)");
            }

            json_success([
                'listing_id' => $listing_id,
                'status' => !empty($imc_fm_waive) ? 'active' : 'draft',
                'has_tiers' => $tier_count >= 2,
                'tier_count' => $tier_count,
                'has_allowlist' => $allowlist_created,
                'platform_fee_xrp' => $platform_fee,
                'platform_fee_drops' => intval($platform_fee * 1000000),
                'fee_wallet' => PLATFORM_FEE_WALLET,
                'free_mint' => !empty($imc_fm_waive) && empty($imc_ul_fee_total),
                'listing_fee_waived' => !empty($imc_fm_waive),
                'unlockable_fee_xrp' => floatval($imc_ul_fee_total ?? 0),
                'free_mints_remaining' => !empty($imc_fm_waive) ? imc_fm_remaining($artist, $nft_type) : null,
                'message' => (!empty($imc_fm_waive) && empty($imc_ul_fee_total))
                    ? 'Free mint used -- your listing is LIVE!'
                    : (!empty($imc_fm_waive)
                        ? 'Listing fee waived -- pay the unlockable content fee to publish.'
                        : 'Listing created! Pay the platform fee to publish.')
            ]);
        }
        
        // --------------------------------------------------------------------
        // Publish listing (after fee payment)
        // --------------------------------------------------------------------
        if ($action === 'publish') {
            $listing_id = intval($_POST['listing_id'] ?? 0);
            $tx_hash = sanitize_text_field($_POST['tx_hash'] ?? '');
            // S1-a: authenticate caller as the listing owner (was: trusted POSTed artist_account)
            $auth = function_exists('imc_session_require_wallet')
                ? imc_session_require_wallet(sanitize_text_field($_POST['artist_account'] ?? ''))
                : ['ok' => false, 'wallet' => '', 'error' => 'auth'];
            if (empty($auth['ok'])) {
                listings_log("publish: BLOCKED (" . ($auth['error'] ?? 'auth') . ") posted=" . ($_POST['artist_account'] ?? '') . " session=" . ($auth['wallet'] ?? ''));
                json_error('Not authenticated as the listing owner', 403);
            }
            $artist = $auth['wallet']; // SESSION authoritative
            
            if (!$listing_id) json_error('Missing listing ID');
            if (!$tx_hash) json_error('Missing transaction hash');
            if (!preg_match('/^[A-Fa-f0-9]{64}$/', $tx_hash)) json_error('Invalid transaction hash format');
            
            global $wpdb, $listings_table;
            
            // Verify listing belongs to artist and is in draft status
            $listing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $listings_table WHERE id = %d AND artist_account = %s",
                $listing_id, $artist
            ), ARRAY_A);
            
            if (!$listing) {
                json_error('Listing not found or not yours');
            }
            
            if ($listing['status'] !== 'draft') {
                json_error('Listing is not in draft status');
            }
            
            // v179: Verify fee payment on-chain before publishing
            $expected_fee = floatval($listing['platform_fee_amount'] ?? 0);
            if ($expected_fee <= 0) {
                // Recalculate if missing (shouldn't happen — fee set at listing creation)
                $editions     = intval($listing['total_editions'] ?? 1);
                $master_files = intval($listing['master_file_count'] ?? 1);
                $ed_type      = $listing['edition_type'] ?? 'fixed';
                $expected_fee = calculate_platform_fee($editions, $listing['nft_type'] ?? 'music', $master_files, $ed_type)
                              + imc_ul_slots_total($listing_id); // U3: unlockable component
            }
            
            // v248: Fee TX replay prevention — same tx_hash cannot publish multiple listings.
            // Without this, a single platform fee payment could publish unlimited listings.
            $existing_fee_use = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $listings_table 
                 WHERE platform_fee_tx = %s AND id != %d AND status != 'failed'
                 LIMIT 1",
                $tx_hash, $listing_id
            ));
            if ($existing_fee_use) {
                listings_log("Publish BLOCKED: fee tx_hash=$tx_hash already used by listing=$existing_fee_use");
                json_error('This payment transaction has already been used to publish another listing.');
            }
            
            $verify = lh_verify_fee_payment($tx_hash, $expected_fee);
            if (!$verify['verified']) {
                listings_log("Publish BLOCKED: listing=$listing_id error=" . $verify['error']);
                json_error('Fee payment verification failed: ' . $verify['error']);
            }
            
            // Update listing to active
            $now = current_time('mysql');
            $wpdb->update($listings_table, [
                'platform_fee_paid' => 1,
                'platform_fee_tx' => $tx_hash,
                'status' => 'active',
                'published_at' => $now,
                'updated_at' => $now
            ], ['id' => $listing_id]);
            imc_ul_mark_paid($listing_id, $tx_hash); // U3: stamp slots (site 1/4)
            
            listings_log("Listing published: ID=$listing_id, tx=$tx_hash");
            
            json_success([
                'listing_id' => $listing_id,
                'status' => 'active',
                'published_at' => $now,
                'message' => 'Your listing is now live on the marketplace!'
            ]);
        }

        // --------------------------------------------------------------------
        // OE-v1: close_open_edition — artist closes a live Open Edition early
        // 0 mints → full cancel (status=cancelled). ≥1 mint → close only (status=sold_out).
        // No refunds for completed mints per T&Cs.
        // --------------------------------------------------------------------
        if ($action === 'close_open_edition') {
            $listing_id = intval($_POST['listing_id'] ?? 0);
            // S1-a: authenticate caller as the listing owner (was: trusted POSTed artist_account)
            $auth = function_exists('imc_session_require_wallet')
                ? imc_session_require_wallet(sanitize_text_field($_POST['artist_account'] ?? ''))
                : ['ok' => false, 'wallet' => '', 'error' => 'auth'];
            if (empty($auth['ok'])) {
                listings_log("close_open_edition: BLOCKED (" . ($auth['error'] ?? 'auth') . ") posted=" . ($_POST['artist_account'] ?? '') . " session=" . ($auth['wallet'] ?? ''));
                json_error('Not authenticated as the listing owner', 403);
            }
            $artist = $auth['wallet']; // SESSION authoritative
            if (!$listing_id) json_error('Missing listing ID');

            global $wpdb, $listings_table;
            $listing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $listings_table WHERE id = %d AND artist_account = %s",
                $listing_id, $artist
            ), ARRAY_A);

            if (!$listing) json_error('Listing not found or not yours');
            if (($listing['edition_type'] ?? 'fixed') !== 'open') json_error('close_open_edition is only valid for Open Edition listings');
            if ($listing['status'] !== 'active') json_error('Listing is not active');
            if (!empty($listing['open_edition_closed_at'])) json_error('This Open Edition is already closed');

            $now    = current_time('mysql');
            $minted = intval($listing['minted_count'] ?? 0);

            if ($minted === 0) {
                // No mints — full cancel
                $wpdb->update($listings_table, [
                    'status'                  => 'cancelled',
                    'open_edition_closed_at'  => $now,
                    'updated_at'              => $now
                ], ['id' => $listing_id]);
                listings_log("OE close_open_edition: listing=$listing_id cancelled (0 mints)");
                json_success(['status' => 'cancelled', 'minted_count' => 0, 'message' => 'Open Edition cancelled — no mints had occurred.']);
            } else {
                // Mints exist — close, lock final supply
                // v393: Set total_editions = minted_count so display logic shows "X / X minted" correctly
                $wpdb->update($listings_table, [
                    'status'                  => 'sold_out',
                    'total_editions'          => $minted,
                    'open_edition_closed_at'  => $now,
                    'updated_at'              => $now
                ], ['id' => $listing_id]);
                listings_log("OE close_open_edition: listing=$listing_id closed ($minted mints, total_editions locked)");
                json_success(['status' => 'sold_out', 'minted_count' => $minted, 'message' => "Open Edition closed with $minted mint(s). Final supply locked."]);
            }
        }

        // --------------------------------------------------------------------
        // OE-v1: extend_open_edition — extend the mint window end time
        // New end must be > current end and <= original start + 30 days.
        // --------------------------------------------------------------------
        if ($action === 'extend_open_edition') {
            $listing_id  = intval($_POST['listing_id'] ?? 0);
            // S1-a: authenticate caller as the listing owner (was: trusted POSTed artist_account)
            $auth = function_exists('imc_session_require_wallet')
                ? imc_session_require_wallet(sanitize_text_field($_POST['artist_account'] ?? ''))
                : ['ok' => false, 'wallet' => '', 'error' => 'auth'];
            if (empty($auth['ok'])) {
                listings_log("extend_open_edition: BLOCKED (" . ($auth['error'] ?? 'auth') . ") posted=" . ($_POST['artist_account'] ?? '') . " session=" . ($auth['wallet'] ?? ''));
                json_error('Not authenticated as the listing owner', 403);
            }
            $artist = $auth['wallet']; // SESSION authoritative
            $new_ends_at = sanitize_text_field($_POST['new_ends_at'] ?? '');

            if (!$listing_id) json_error('Missing listing ID');
            if (!$new_ends_at) json_error('Missing new_ends_at');
            $new_end_ts = strtotime($new_ends_at);
            if (!$new_end_ts) json_error('Invalid new_ends_at format');

            global $wpdb, $listings_table;
            $listing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $listings_table WHERE id = %d AND artist_account = %s",
                $listing_id, $artist
            ), ARRAY_A);

            if (!$listing) json_error('Listing not found or not yours');
            if (($listing['edition_type'] ?? 'fixed') !== 'open') json_error('extend_open_edition is only valid for Open Edition listings');
            if ($listing['status'] !== 'active') json_error('Listing is not active');
            if (!empty($listing['open_edition_closed_at'])) json_error('This Open Edition is already closed');

            $current_end_ts = strtotime($listing['open_edition_ends_at'] ?? '');
            if ($current_end_ts && $new_end_ts <= $current_end_ts) {
                json_error('New end time must be later than the current end time');
            }

            // Enforce 30-day cap from original start
            $start_ts = ($listing['launch_type'] === 'scheduled' && !empty($listing['launch_at']))
                ? strtotime($listing['launch_at'])
                : strtotime($listing['published_at'] ?? $listing['created_at']);
            if ($start_ts && ($new_end_ts - $start_ts) > 365 * 24 * 3600) {
                json_error('Extended end time exceeds the 1-year maximum from original mint start');
            }

            $new_end_sql      = date('Y-m-d H:i:s', $new_end_ts);
            $new_duration     = $start_ts ? round(($new_end_ts - $start_ts) / 86400, 2) : null;
            $now              = current_time('mysql');
            $wpdb->update($listings_table, [
                'open_edition_ends_at'       => $new_end_sql,
                'open_edition_duration_days' => $new_duration,
                'updated_at'                 => $now
            ], ['id' => $listing_id]);

            listings_log("OE extend: listing=$listing_id new_ends_at=$new_end_sql duration={$new_duration}d");
            json_success(['open_edition_ends_at' => $new_end_sql, 'message' => "Mint window extended to $new_end_sql"]);
        }
        
        // --------------------------------------------------------------------
        // Update listing (price, status, currencies)
        // --------------------------------------------------------------------
        if ($action === 'update') {
            $listing_id = intval($_POST['listing_id'] ?? 0);
            // S1-a: authenticate caller as the listing owner (was: trusted POSTed artist_account)
            $auth = function_exists('imc_session_require_wallet')
                ? imc_session_require_wallet(sanitize_text_field($_POST['artist_account'] ?? ''))
                : ['ok' => false, 'wallet' => '', 'error' => 'auth'];
            if (empty($auth['ok'])) {
                listings_log("update: BLOCKED (" . ($auth['error'] ?? 'auth') . ") posted=" . ($_POST['artist_account'] ?? '') . " session=" . ($auth['wallet'] ?? ''));
                json_error('Not authenticated as the listing owner', 403);
            }
            $artist = $auth['wallet']; // SESSION authoritative
            
            if (!$listing_id) json_error('Missing listing ID');
            
            global $wpdb, $listings_table;
            
            // Debug: Log incoming request
            listings_log("UPDATE REQUEST: listing_id=$listing_id, artist=$artist");
            listings_log("POST data keys: " . implode(', ', array_keys($_POST)));
            
            // Verify listing belongs to artist
            $listing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $listings_table WHERE id = %d AND artist_account = %s",
                $listing_id, $artist
            ), ARRAY_A);
            
            if (!$listing) {
                json_error('Listing not found or not yours');
            }
            
            $updates = ['updated_at' => current_time('mysql')];

            // ── PP-3 (R12): progressive config lock ─────────────────────────────
            // Pre-mint: full change allowed through PP-0's validator. Post-mint: LOCKED,
            // with exactly one exception (R4) -- existing caps may be RAISED (never
            // lowered, added, or removed; increments and scope byte-identical), so a
            // creator can lift a ceiling mid-run but never yank the ladder out from
            // under holders. Uses the same targeted-isset pattern as every field here.
            if (isset($_POST['progressive'])) {
                $pp_minted = intval($listing['minted_count'] ?? 0);
                if ($pp_minted === 0) {
                    $pp_acc  = $_POST['accepted_currencies'] ?? ($listing['accepted_currencies'] ?? '');
                    $pp_mode = $_POST['pricing_mode'] ?? ($listing['pricing_mode'] ?? 'static');
                    $pp_v = imc_validate_progressive_config($_POST['progressive'], is_string($pp_acc) ? $pp_acc : wp_json_encode($pp_acc), $pp_mode);
                    if (!$pp_v['ok']) json_error($pp_v['error']);
                    $updates['progressive_json'] = $pp_v['json'];
                    listings_log("PP-3: progressive config updated pre-mint for listing=$listing_id");
                } else {
                    // ── P5: FREEZE-AND-REBASE (supersedes the R12 hard lock, per ruling) ──
                    // Post-mint, every setting is manageable under ONE invariant, enforced by
                    // construction: THE PRICE CAN ONLY EVER INCREASE over a listing's life.
                    // Every edit re-anchors at the CURRENT price and applies forward:
                    //   base' = current - increment x step   (pure algebra -- the resolver,
                    //   materializer and ladder formulas need ZERO changes; unit(base',inc,step)
                    //   = current at this step and climbs by the NEW increment from here).
                    // Toggle OFF = freeze: live prices re-stamped to current, config kept
                    // (disabled) so ON resumes from the frozen price. Caps must be >= current.
                    // Buyer safety is the confirm gate + poll, untouched -- rebases land
                    // BETWEEN mints, never inside one.
                    $pp_mode_e = $listing['pricing_mode'] ?? 'static';
                    $pp_acc_e  = $listing['accepted_currencies'] ?? '';
                    $pp_v = imc_validate_progressive_config($_POST['progressive'], is_string($pp_acc_e) ? $pp_acc_e : wp_json_encode($pp_acc_e), $pp_mode_e);
                    // the toggle: an explicit enabled:false skips shape validation of increments
                    $pp_req = json_decode(wp_unslash($_POST['progressive']), true);
                    $pp_disable = (is_array($pp_req) && isset($pp_req['enabled']) && !$pp_req['enabled']);
                    if (!$pp_disable && !$pp_v['ok']) json_error($pp_v['error']);
                    $pp_step_e = lh_pp_step($listing_id);
                    $pp_old_e = json_decode($listing['progressive_json'] ?? 'null', true);
                    $pp_entries = json_decode(is_string($pp_acc_e) ? $pp_acc_e : wp_json_encode($pp_acc_e), true);
                    if (!is_array($pp_entries)) $pp_entries = [];
                    $pp_currents = [];
                    foreach ($pp_entries as $pe) {
                        if (!is_array($pe) || empty($pe['currency'])) continue;
                        $pp_currents[strtoupper($pe['currency'])] = floatval($pe['price'] ?? 0);
                    }
                    if (!isset($pp_currents['XRP']) && floatval($listing['price_xrp'] ?? 0) > 0) {
                        $pp_currents['XRP'] = floatval($listing['price_xrp']);
                    }
                    $pp_cur_usd = floatval($listing['price_usd'] ?? 0);
                    if ($pp_disable) {
                        // FREEZE: keep the old config (bases re-anchored to current), disabled.
                        $pp_frozen = is_array($pp_old_e) ? $pp_old_e : ['scope' => 'together', 'increments' => []];
                        $pp_frozen['enabled'] = false;
                        foreach ($pp_entries as $pi => $pe) {
                            $pc = strtoupper($pe['currency'] ?? '');
                            if ($pc !== '' && isset($pp_currents[$pc])) $pp_entries[$pi]['base_price'] = $pp_currents[$pc];
                        }
                        if ($pp_cur_usd > 0) $pp_frozen['base']['USD'] = $pp_cur_usd;
                        $updates['progressive_json'] = wp_json_encode($pp_frozen);
                        $updates['accepted_currencies'] = wp_json_encode($pp_entries);
                        listings_log("P5: progressive FROZEN at current for listing=$listing_id (step=$pp_step_e)");
                    } else {
                        $pp_clean = json_decode($pp_v['json'], true);
                        // cap floor: every cap must be >= the current price of its currency
                        foreach (($pp_clean['cap'] ?? []) as $cc => $cv) {
                            $cc_cur = ($cc === 'USD') ? $pp_cur_usd : ($pp_currents[$cc] ?? 0);
                            if (floatval($cv) < $cc_cur - 1e-9) {
                                json_error("The $cc cap cannot be below the current price ($cc_cur) — prices only ever climb.");
                            }
                        }
                        // REBASE: base' = current - inc x step, per currency (algebra keeps
                        // every downstream formula unchanged and the climb forward-only).
                        foreach ($pp_entries as $pi => $pe) {
                            $pc = strtoupper($pe['currency'] ?? '');
                            if ($pc === '' || !isset($pp_clean['increments'][$pc])) continue;
                            // R1-scope fix: 'individual' scope prices each currency on its own
                            // verified-paid count (the engine's semantics); 'together' keeps the
                            // listing-wide step. unit(base', inc, step_currency) = current, exactly.
                            $pp_step_c = (($pp_clean['scope'] ?? 'together') === 'individual')
                                ? lh_pp_step($listing_id, $pc)
                                : $pp_step_e;
                            $pp_entries[$pi]['base_price'] = ($pp_currents[$pc] ?? 0) - floatval($pp_clean['increments'][$pc]) * $pp_step_c;
                        }
                        if (isset($pp_clean['increments']['USD']) && $pp_cur_usd > 0) {
                            $pp_clean['base']['USD'] = $pp_cur_usd - floatval($pp_clean['increments']['USD']) * $pp_step_e;
                        }
                        $updates['progressive_json'] = wp_json_encode($pp_clean);
                        $updates['accepted_currencies'] = wp_json_encode($pp_entries);
                        listings_log("P5: progressive REBASED for listing=$listing_id (step=$pp_step_e)");
                    }
                }
            }

            // Update price (only if provided)
            if (isset($_POST['price_xrp'])) {
                $updates['price_xrp'] = max(0, floatval($_POST['price_xrp']));
                listings_log("UPDATE: price_xrp = " . $updates['price_xrp']);
            }
            
            // v71: Update accepted_currencies (full replacement)
            if (isset($_POST['accepted_currencies'])) {
                $raw_input = $_POST['accepted_currencies'];
                listings_log("UPDATE: accepted_currencies raw input (first 500 chars): " . substr($raw_input, 0, 500));
                
                $validated = validate_accepted_currencies($raw_input);
                
                if ($validated !== null) {
                    $json_result = json_encode($validated, JSON_UNESCAPED_UNICODE);
                    $updates['accepted_currencies'] = $json_result;
                    listings_log("UPDATE: Validated " . count($validated) . " currencies. JSON length: " . strlen($json_result));
                } else if ($raw_input === '' || $raw_input === '[]' || $raw_input === 'null') {
                    // Clear currencies - revert to XRP only
                    $price = isset($updates['price_xrp']) ? $updates['price_xrp'] : floatval($listing['price_xrp']);
                    $updates['accepted_currencies'] = json_encode([
                        ['currency' => 'XRP', 'price' => $price, 'enabled' => true, 'name' => 'XRP', 'icon' => '💧']
                    ]);
                    listings_log("UPDATE: Reset to XRP only");
                } else {
                    listings_log("UPDATE WARNING: validate_accepted_currencies returned null for input");
                }
            }
            
            // v73: Update pricing_mode if provided
            if (isset($_POST['pricing_mode'])) {
                $mode = sanitize_text_field($_POST['pricing_mode']);
                // v670: accept every mode the creation flow can produce. Previously limited to
                // static/dynamic, so a PWYW or Free listing edited from the dashboard silently
                // kept its old mode while the other posted fields were applied.
                if (in_array($mode, ['static', 'dynamic', 'pwyw', 'free'])) {
                    $updates['pricing_mode'] = $mode;
                    listings_log("UPDATE: pricing_mode = $mode");
                }
            }

            // v670: keep the two XRP price sources in step. The authoritative XRP price lives in
            // the price_xrp column, but accepted_currencies carries its own XRP entry -- if only
            // one is edited they drift, and the listing then displays one price while the mint
            // modal offers another. Normalise whenever either side is being written.
            // Skipped for dynamic (priced from price_usd via the oracle, so its XRP entry
            // deliberately carries no price) and when there is no currency list to sync.
            $imc_mode_now = $updates['pricing_mode'] ?? ($listing['pricing_mode'] ?? 'static');
            if ($imc_mode_now !== 'dynamic' && (isset($updates['price_xrp']) || isset($updates['accepted_currencies']))) {
                $imc_eff_xrp = isset($updates['price_xrp'])
                    ? floatval($updates['price_xrp'])
                    : floatval($listing['price_xrp']);
                $imc_ac_raw = isset($updates['accepted_currencies'])
                    ? $updates['accepted_currencies']
                    : ($listing['accepted_currencies'] ?? '');
                $imc_ac = $imc_ac_raw !== '' ? json_decode($imc_ac_raw, true) : [];
                if (is_array($imc_ac) && !empty($imc_ac)) {
                    $imc_sync = false;
                    foreach ($imc_ac as &$imc_c) {
                        if (strtoupper($imc_c['currency'] ?? '') === 'XRP'
                            && floatval($imc_c['price'] ?? 0) !== $imc_eff_xrp) {
                            $imc_c['price'] = $imc_eff_xrp;
                            $imc_sync = true;
                        }
                    }
                    unset($imc_c);
                    if ($imc_sync) {
                        $updates['accepted_currencies'] = json_encode($imc_ac, JSON_UNESCAPED_UNICODE);
                        listings_log("UPDATE v670: normalised accepted_currencies XRP price to {$imc_eff_xrp}");
                    }
                }
            }
            
            // v73: Update price_usd if provided
            if (isset($_POST['price_usd'])) {
                $updates['price_usd'] = max(0, floatval($_POST['price_usd']));
                // v193: Enforce max USD price for compliance + volatility protection
                $max_price = defined('IMC_MAX_PRICE_USD') ? IMC_MAX_PRICE_USD : 5000;
                if ($updates['price_usd'] > $max_price) {
                    json_error('Maximum dynamic price is $' . number_format($max_price) . ' USD for compliance reasons');
                }
                listings_log("UPDATE: price_usd = " . $updates['price_usd']);
            }
            
            // v73: Update mint_limit settings
            if (isset($_POST['mint_limit_enabled'])) {
                $updates['mint_limit_enabled'] = !empty($_POST['mint_limit_enabled']) ? 1 : 0;
            }
            if (isset($_POST['mint_limit_per_wallet'])) {
                $updates['mint_limit_per_wallet'] = max(0, intval($_POST['mint_limit_per_wallet']));
            }
            
            // Update status (with valid transitions only)
            if (isset($_POST['status'])) {
                $new_status = $_POST['status'];
                $current_status = $listing['status'];
                
                $allowed_transitions = [
                    'draft' => ['cancelled'],
                    'active' => ['paused', 'cancelled'],
                    'paused' => ['active', 'cancelled'],
                    'sold_out' => [],
                    'cancelled' => []
                ];
                
                if (in_array($new_status, $allowed_transitions[$current_status] ?? [])) {
                    $updates['status'] = $new_status;
                    if ($new_status === 'cancelled') {
                        listings_log("Listing cancelled: ID=$listing_id by $artist");
                    }
                }
            }
            
            // v233: Allow artist to go live immediately (change scheduled → immediate)
            if (isset($_POST['launch_type'])) {
                $new_launch_type = sanitize_text_field($_POST['launch_type']);
                if (in_array($new_launch_type, ['immediate', 'scheduled'])) {
                    $updates['launch_type'] = $new_launch_type;
                    if ($new_launch_type === 'immediate') {
                        $updates['launch_at'] = null; // Clear scheduled date — live now
                        listings_log("UPDATE: Going live immediately (launch_type=immediate, launch_at cleared)");
                    } elseif (!empty($_POST['launch_at'])) {
                        $new_launch_at = sanitize_text_field($_POST['launch_at']);
                        if (strtotime($new_launch_at)) {
                            $updates['launch_at'] = $new_launch_at;
                        }
                    }
                }
            }
            
            // v699 (tiered card cover): allow the creator to change the LISTING CARD cover after
            // creation — TIERED listings ONLY. Gated on has_tiers so a non-tiered listing's
            // cover_ipfs (which IS its minted image) can never be edited here. Display-only: tiered
            // mints build metadata from the per-tier cover, so this never affects minted NFTs.
            // v699 Phase 4 (Option B): accept an uploaded image (pinned server-side, key stays on
            // the server) OR a pre-pinned CID string.
            if (!empty($listing['has_tiers'])) {
                if (!empty($_FILES['tier_card_cover_file']) && (($_FILES['tier_card_cover_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK)) {
                    $ccf = $_FILES['tier_card_cover_file'];
                    $cc_allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
                    if (($ccf['size'] ?? 0) > 8 * 1024 * 1024) { json_error('Cover image too large (max 8MB)'); }
                    $cc_info = @getimagesize($ccf['tmp_name']);
                    if (!$cc_info || !in_array($cc_info['mime'], $cc_allowed, true)) { json_error('Invalid image format (use JPG, PNG, WEBP or GIF)'); }
                    $cc_pin = imc_pin_card_cover_to_ipfs($ccf['tmp_name'], basename($ccf['name'] ?? 'cover.img'));
                    if (empty($cc_pin['success']) || empty($cc_pin['hash'])) { json_error('Cover upload failed: ' . ($cc_pin['error'] ?? 'pin failed')); }
                    $updates['cover_ipfs'] = 'ipfs://' . $cc_pin['hash'];
                    listings_log("UPDATE: tier card cover pinned + set for tiered listing $listing_id ({$cc_pin['hash']})");
                } elseif (isset($_POST['tier_card_cover'])) {
                    $tcc_new = sanitize_text_field($_POST['tier_card_cover']);
                    if ($tcc_new && is_valid_ipfs($tcc_new)) {
                        $updates['cover_ipfs'] = $tcc_new;
                        listings_log("UPDATE: tier_card_cover updated for tiered listing $listing_id");
                    }
                }
            }

            // Log what we're about to update
            listings_log("UPDATE: Updating listing $listing_id with fields: " . implode(', ', array_keys($updates)));
            
            // Perform the update with error checking
            $result = $wpdb->update($listings_table, $updates, ['id' => $listing_id]);
            
            // Check for errors
            if ($result === false) {
                $db_error = $wpdb->last_error;
                listings_log("UPDATE ERROR: $db_error");
                json_error("Database update failed: $db_error");
            }
            
            listings_log("UPDATE SUCCESS: $result row(s) affected for listing $listing_id");
            
            // Return updated listing
            $updated_listing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $listings_table WHERE id = %d",
                $listing_id
            ), ARRAY_A);
            
            // Debug: Log what's actually in the database
            listings_log("UPDATE VERIFY: accepted_currencies in DB = " . substr($updated_listing['accepted_currencies'] ?? 'NULL', 0, 300));
            
            json_success([
                'listing_id' => $listing_id,
                'updated' => true,
                'rows_affected' => $result,
                'updates' => array_keys($updates),
                'listing' => format_listing($updated_listing)
            ]);
        }
        
        // --------------------------------------------------------------------
        // Increment minted count (called by mint-on-demand handler)
        // This is an internal endpoint, not for public use
        // --------------------------------------------------------------------
        // ====================================================================
        // M1-f3a: LISTENING / READING PROGRESS (per wallet, per NFT)
        //
        // Session-bound by the S1-a rule: the wallet comes from the httponly
        // session token, never from the POST body. A forged wallet in the body
        // writes under the caller's own row and nothing else.
        //
        // Read-path convenience only - never touches money, minting or
        // entitlement. Ownership is deliberately NOT re-checked: saving your own
        // position is low-stakes and the content is gated by the master grant,
        // so a spoofed nftoken_id only creates a useless row.
        //
        // The POST-section nonce check above already ran.
        // ====================================================================
        if ($action === 'save_reading_progress' || $action === 'get_reading_progress') {
            global $wpdb;
            $rp_auth = function_exists('imc_session_require_wallet')
                ? imc_session_require_wallet('')
                : array('ok' => false, 'wallet' => '');
            if (empty($rp_auth['ok']) || empty($rp_auth['wallet'])) {
                json_error('Not authenticated - please connect your wallet', 403);
            }
            $rp_wallet = $rp_auth['wallet'];
            $rp_nft    = sanitize_text_field($_POST['nftoken_id'] ?? '');
            if (strlen($rp_nft) !== 64 || !ctype_xdigit($rp_nft)) {
                json_error('Invalid nftoken_id');
            }
            $rp_table = $wpdb->prefix . 'imc_reading_progress';

            if ($action === 'get_reading_progress') {
                $rp_row = $wpdb->get_var($wpdb->prepare(
                    "SELECT position_json FROM $rp_table WHERE wallet = %s AND nftoken_id = %s LIMIT 1",
                    $rp_wallet, $rp_nft
                ));
                json_success(['position' => $rp_row ? json_decode($rp_row, true) : null]);
            }

            $rp_pos = stripslashes($_POST['position_json'] ?? '');
            if (strlen($rp_pos) > 2048) {
                json_error('Position data too large');
            }
            $rp_decoded = json_decode($rp_pos, true);
            if (!is_array($rp_decoded)) {
                $rp_decoded = json_decode(stripslashes($rp_pos), true);
            }
            if (!is_array($rp_decoded)) {
                json_error('Invalid position data');
            }
            // Keep only the fields the players use, as non-negative integers.
            $rp_clean = [];
            foreach (['chapter', 'page', 'ms'] as $rp_k) {
                if (isset($rp_decoded[$rp_k])) {
                    $rp_clean[$rp_k] = max(0, intval($rp_decoded[$rp_k]));
                }
            }
            if (empty($rp_clean)) {
                json_error('Invalid position data');
            }
            $wpdb->query($wpdb->prepare(
                "INSERT INTO $rp_table (wallet, nftoken_id, position_json, updated_at)
                 VALUES (%s, %s, %s, NOW())
                 ON DUPLICATE KEY UPDATE position_json = VALUES(position_json), updated_at = NOW()",
                $rp_wallet, $rp_nft, wp_json_encode($rp_clean)
            ));
            json_success(['saved' => true]);
        }

        if ($action === 'increment_minted') {
            // This should be called by mint-on-demand-handler.php after successful mint
            // Verify internal API key
            $internal_key = sanitize_text_field($_POST['internal_key'] ?? '');
            $expected_key = defined('IMC_INTERNAL_KEY') ? IMC_INTERNAL_KEY : '';
            
            if (!$expected_key || $internal_key !== $expected_key) {
                json_error('Unauthorized', 403);
            }
            
            $listing_id = intval($_POST['listing_id'] ?? 0);
            if (!$listing_id) json_error('Missing listing ID');
            
            global $wpdb, $listings_table;
            
            // Increment minted_count and check if sold out
            $wpdb->query($wpdb->prepare(
                "UPDATE $listings_table 
                 SET minted_count = minted_count + 1,
                     updated_at = %s,
                     status = CASE 
                         WHEN minted_count + 1 >= total_editions THEN 'sold_out'
                         ELSE status 
                     END
                 WHERE id = %d AND minted_count < total_editions",
                current_time('mysql'),
                $listing_id
            ));
            
            $affected = $wpdb->rows_affected;
            
            if ($affected === 0) {
                json_error('Listing not found or already sold out');
            }
            
            // Get updated listing
            $listing = $wpdb->get_row($wpdb->prepare(
                "SELECT minted_count, total_editions, status FROM $listings_table WHERE id = %d",
                $listing_id
            ), ARRAY_A);
            
            json_success([
                'listing_id' => $listing_id,
                'minted_count' => intval($listing['minted_count']),
                'total_editions' => intval($listing['total_editions']),
                'status' => $listing['status']
            ]);
        }

        // NOT IN THIS REPOSITORY. Issuing signed upload tickets for the protected-media
        // service is part of that service's trust boundary, which is maintained as
        // separate infrastructure and is not included here.
        if ($action === 'unlockable_upload_ticket') {
            json_error('Not available in this build', 501);
        }
        
        // ── U7 (Unlockables Master): post-mint vault additions ──────────────
        // Sequence (ruling honored as upload-to-STAGING, pay-BEFORE-LIVE): files
        // land in the artist's POOL via the U2 ticket lane (holder-invisible;
        // sweep reaps abandons) -> vault_add_quote prices server-side from POOL
        // rows -> create_vault_fee_payment (engine) builds the QR -> the creator
        // pays -> vault_add_claim verifies the tx ON-CHAIN (destination +
        // delivered_amount armor via lh_verify_fee_payment) and only then claims
        // slots fee_paid=1. Enforcement lives HERE, not in the payload builder.
        if ($action === 'vault_add_quote' || $action === 'vault_add_claim') {
            $va_nonce = sanitize_text_field($_POST['nonce'] ?? '');
            if (!wp_verify_nonce($va_nonce, 'xrpl_marketplace_nonce')) {
                json_error('Session expired -- please refresh and try again', 403);
            }
            $va_auth = function_exists('imc_session_require_wallet')
                ? imc_session_require_wallet('')
                : array('ok' => false, 'wallet' => '');
            if (empty($va_auth['ok']) || empty($va_auth['wallet'])) {
                json_error('Not authenticated -- please connect your wallet', 403);
            }
            $va_wallet = $va_auth['wallet'];
            $va_listing_id = intval($_POST['listing_id'] ?? 0);
            if (!$va_listing_id) json_error('Missing listing ID');

            $va_listing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, artist_account, status FROM $listings_table WHERE id = %d",
                $va_listing_id
            ), ARRAY_A);
            if (!$va_listing) json_error('Listing not found');
            if ($va_listing['artist_account'] !== $va_wallet) {
                json_error('Only the listing creator can manage its vault', 403);
            }
            if (!in_array($va_listing['status'], ['active', 'paused', 'sold_out'], true)) {
                json_error('Vault additions are only available on published listings');
            }

            $va_t = $wpdb->prefix . 'imc_listing_unlockables';
            $va_existing = $wpdb->get_results($wpdb->prepare(
                "SELECT content_hash, label, file_size, tier_id, fee_paid FROM $va_t
                  WHERE listing_id = %d AND status = 'active' ORDER BY tier_id, sort_order, id",
                $va_listing_id
            ), ARRAY_A) ?: [];
            $va_existing_count = count(array_unique(array_column($va_existing, 'content_hash')));

            $va_parsed = imc_ul_parse_claims($va_wallet, $_POST['unlockables'] ?? '');
            if (!empty($va_parsed['error'])) json_error($va_parsed['error']);
            $va_claims = $va_parsed['claims'];
            $va_total  = floatval($va_parsed['total']);

            if ($va_existing_count + count($va_claims) > 10) {
                json_error('Vault limit: a listing can hold at most 10 unlockable files (' . $va_existing_count . ' already attached)');
            }

            if ($action === 'vault_add_quote') {
                json_success([
                    'listing_id' => $va_listing_id,
                    'existing'   => array_map(function($e) {
                        return ['label' => $e['label'], 'file_size' => intval($e['file_size']), 'tier_id' => intval($e['tier_id'])];
                    }, $va_existing),
                    'existing_count' => $va_existing_count,
                    'new_count'  => count($va_claims),
                    'total_xrp'  => $va_total,
                    'items'      => array_map(function($c) {
                        return ['content_hash' => $c['pool']['content_hash'], 'label' => $c['label'], 'fee' => $c['fee']];
                    }, $va_claims),
                    'slots_remaining' => max(0, 10 - $va_existing_count),
                ]);
            }

            if (empty($va_claims)) json_error('No files to attach');
            $va_tx = sanitize_text_field($_POST['tx_hash'] ?? '');
            if (!preg_match('/^[A-Fa-f0-9]{64}$/', $va_tx)) json_error('Invalid transaction hash');

            $va_dup = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $va_t WHERE fee_tx = %s LIMIT 1", $va_tx
            ));
            if ($va_dup) json_error('This payment has already been used for a vault addition');

            $va_verify = lh_verify_fee_payment($va_tx, $va_total);
            if (empty($va_verify['verified'])) {
                json_error('Payment verification failed: ' . ($va_verify['error'] ?? 'unknown'));
            }

            $va_tiers = $wpdb->get_results($wpdb->prepare(
                "SELECT id, tier_order FROM {$wpdb->prefix}imc_listing_tiers WHERE listing_id = %d",
                $va_listing_id
            ), ARRAY_A) ?: [];
            $va_tid_by_order = [];
            foreach ($va_tiers as $va_tr) { $va_tid_by_order[intval($va_tr['tier_order'])] = intval($va_tr['id']); }

            $va_now = current_time('mysql');
            $va_inserted = 0;
            foreach ($va_claims as $va_c) {
                $va_tid = 0;
                if ($va_c['tier_order'] !== null && isset($va_tid_by_order[$va_c['tier_order']])) {
                    $va_tid = $va_tid_by_order[$va_c['tier_order']];
                }
                $va_ok = $wpdb->insert($va_t, [
                    'artist_account'    => $va_wallet,
                    'listing_id'        => $va_listing_id,
                    'tier_id'           => $va_tid,
                    'content_hash'      => $va_c['pool']['content_hash'],
                    'storage_path'      => $va_c['pool']['storage_path'],
                    'original_filename' => $va_c['pool']['original_filename'],
                    'file_size'         => intval($va_c['pool']['file_size']),
                    'mime_type'         => $va_c['pool']['mime_type'],
                    'label'             => $va_c['label'],
                    'fee_xrp'           => $va_c['fee'],
                    'fee_paid'          => 1,
                    'fee_tx'            => $va_tx,
                    'sort_order'        => 0,
                    'created_at'        => $va_now,
                ]);
                if ($va_ok) $va_inserted++;
            }
            if ($va_inserted === 0) json_error('Failed to attach files -- please contact support (payment tx: ' . $va_tx . ')');
            listings_log("U7 vault_add: listing=$va_listing_id +$va_inserted slot(s) fee={$va_total} XRP tx=$va_tx");
            json_success(['attached' => $va_inserted, 'listing_id' => $va_listing_id, 'message' => $va_inserted . ' file(s) added to the vault -- holders can download immediately']);
        }

        json_error('Unknown action');
    }
    
    json_error('Invalid request method', 405);
    
} catch (Exception $e) {
    listings_log("Error: " . $e->getMessage());
    json_error('Server error', 500);
}