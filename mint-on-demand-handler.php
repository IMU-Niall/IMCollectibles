<?php
/**
 * ============================================================================
 * FILE: mint-on-demand-handler.php
 * PATH: /wp-content/themes/astra/xrpl-nft-marketplace/backend/
 * ============================================================================
 *
 * Mint-on-Demand System v3.3 — Phase 4: Server-Side Minting Pipeline (v242 security hardening)
 *
 * ENDPOINTS:
 * ── GET ──────────────────────────────────────────────────────────────────────
 * poll_payment         uuid              Poll XUMM payment/fee status
 * poll_claim           uuid              Poll XUMM claim status (dispatched_result ✅)
 * get_purchase         id                Get single purchase details
 * get_buyer_purchases  account           Get buyer's purchase history
 * get_artist_listings  account           Get artist's listings
 * get_listing          listing_id        Get single listing (public)
 * get_collection_listings  issuer,taxon  Get listings by collection
 * get_group_status     group_id          Poll mint-group progress (NEW)
 * check_unclaimed      account,taxon,artist  Check unclaimed NFTs (NEW)
 *
 * ── POST ─────────────────────────────────────────────────────────────────────
 * create_fee_payment   listing_id,artist_account    Create fee payment XUMM payload
 * reserve_purchase     listing_id,buyer_account,qty Reserve editions + create payment (NEW)
 * process_group        group_id,tx_hash             Verify payment → mint all NFTs (NEW)
 * claim_nft            purchase_id,buyer_account    Create claim XUMM payload
 * confirm_delivery     purchase_id,buyer_account,tx_hash  Mark NFT delivered (NEW)
 *
 * @version 3.3.0 — Phase 4 (v242 security hardening — C1/C2/C3/H1/H2/H3/M1/M3/L1/L2)
 */

// ============================================================================
// v728 (A2): ALLOWLIST SCOPED CONSUMPTION — kill-switch.
// false (default) = byte-equivalent to v727 behaviour. When true:
//   - mint groups record allowlist_qty (>0 = allowlist pricing was applied)
//   - entries.minted_count increments ONLY for allowlist-priced groups
//   - the v412 free-mint guard counts ONLY allowlist-priced purchases
//   - the v382 allocation check also counts in-flight tagged purchases (R1 race)
//   - custom_price applies proportionally to token payments; 0 = free in ANY currency
//   - graduation nulls only DISCOUNT-sourced prices
// Defined with !defined() so a wp-config.php override wins (2b pattern).
// FLIP CHECKLIST: confirm allowlist_qty column exists (migration runs on load),
// then set true in wp-config; clear opcache. Reversible instantly.
// ============================================================================
// (F7, 2 Sep 2026) fallback define MOVED below the WordPress bootstrap -- see 'F7' after require_once $wp_load. Defining here ran BEFORE wp-config and made the handler's false win over wp-config's true.

// v731 (A4b): per-token discount matrix — its own kill-switch, same pattern. When true,
// an allowlist's per-token rule (percent 1-99 or fixed price in that token) REPLACES the
// base benefit for that payment currency. Free claims (custom_price=0) outrank the
// matrix and stay free in every currency. Dark by default; wp-config wins.
// (F7, 2 Sep 2026) fallback define MOVED below the WordPress bootstrap -- see 'F7' after require_once $wp_load. Defining here ran BEFORE wp-config and made the handler's false win over wp-config's true.

// Task H re-land (Aug 2026): allowlist x PWYW. When true, a wallet's allowlist benefit
// becomes the PWYW MINIMUM (custom price / per-token rule / percent discount lower the
// floor; custom_price=0 = free claim with optional tip via the FREE-MINT rail). The
// original v819 build was lost to a stale handler base during the item-1/PP arc (the
// same mechanism as the documented mint-on-demand.js Task H loss). Dark by default;
// wp-config wins — the client's 'Free for you' note keys off get_listing's flag echo,
// so client and server flip together.
// (F7, 2 Sep 2026) fallback define MOVED below the WordPress bootstrap -- see 'F7' after require_once $wp_load. Defining here ran BEFORE wp-config and made the handler's false win over wp-config's true.

// ============================================================================
// LOGGING
// ============================================================================
$log_dir = __DIR__ . '/logs';
$log_file = $log_dir . '/mint-on-demand.log';

if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0755, true);
}

function mod_log($msg) {
    global $log_file;
    // v41: Use current_time for WordPress timezone consistency
    $ts = function_exists('current_time') ? current_time('Y-m-d H:i:s') : date('Y-m-d H:i:s');
    @file_put_contents($log_file, "[$ts] " . $msg . "\n", FILE_APPEND);
}

/**
 * v473: Parse a UTC-stored launch_at datetime string to a Unix timestamp,
 * regardless of the PHP server's date.timezone setting.
 *
 * BACKGROUND: All write paths (mint.js localTzToUTC since v473, dashboard
 * Edit Schedule via toISOString since launch) store launch_at as UTC. The
 * launch gate compares against time() which is always Unix-epoch UTC.
 *
 * Plain strtotime() interprets the string in the PHP server's tz, which
 * produces a wrong timestamp if the server happens not to be UTC. Today
 * the web host's server tz is UTC so plain strtotime() works, but this helper
 * makes the gate bulletproof against any future server config drift.
 *
 * Returns false on parse failure (caller should treat as "no scheduled
 * launch" — i.e. allow purchase, matching strtotime's prior behavior).
 */
function launch_at_to_unix($launch_at_str) {
    if (empty($launch_at_str)) return false;
    try {
        $dt = new DateTime($launch_at_str, new DateTimeZone('UTC'));
        return $dt->getTimestamp();
    } catch (Exception $e) {
        return false;
    }
}

mod_log("=== REQUEST: {$_SERVER['REQUEST_METHOD']} action=" . ($_GET['action'] ?? $_POST['action'] ?? 'none') . " ===");

// ============================================================================
// HEADERS
// ============================================================================
header('Content-Type: application/json; charset=utf-8');
$mod_allowed_origins = [
    'https://imcollectibles.xyz',
    'https://www.imcollectibles.xyz',
    'https://imcollectibles.io',
    'https://www.imcollectibles.io',
    'https://improtectors.com',
    'https://www.improtectors.com'
];
$mod_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . (in_array($mod_origin, $mod_allowed_origins) ? $mod_origin : $mod_allowed_origins[0]));
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ============================================================================
// WORDPRESS BOOTSTRAP
// ============================================================================
$wp_load = dirname(__DIR__, 5) . '/wp-load.php';
if (!file_exists($wp_load)) {
    mod_log("FATAL: wp-load.php not found at $wp_load");
    echo json_encode(['success' => false, 'error' => 'WordPress not found']);
    exit;
}

require_once $wp_load;
mod_log("WordPress loaded");

// ============================================================================
// F7 (2 Sep 2026): the three allowlist feature flags. These MUST be defined AFTER wp-config has
// loaded (wp-load.php above). They previously sat at the top of this file, ran before the
// bootstrap, and defined FALSE -- so wp-config's `define(..., true)` was refused as a redefinition
// and every one of these features had been OFF in the handler since it shipped, while the rest
// of the theme (loaded under WordPress) saw them ON. wp-config now genuinely wins.
// ============================================================================
if (!defined('IMC_ALLOWLIST_SCOPED_CONSUME')) define('IMC_ALLOWLIST_SCOPED_CONSUME', false);
if (!defined('IMC_ALLOWLIST_CURRENCY_MATRIX')) define('IMC_ALLOWLIST_CURRENCY_MATRIX', false);
if (!defined('IMC_ALLOWLIST_PWYW')) define('IMC_ALLOWLIST_PWYW', false);

// ============================================================================
// CONFIGURATION
// ============================================================================
// v892 (Defect AG): FAIL CLOSED. This used to fall back to a hardcoded address if
// wp-config did not define IMC_PLATFORM_WALLET. That address -- rPSHTgpjS1BU... -- is a
// wallet IMU owns but does NOT use for the marketplace: it is the legacy issuer for
// Guardians (taxon 0), Las Vegas (777) and Firepit (666), which is why it appears
// legitimately ~80 times elsewhere in this codebase and must NOT be search-replaced.
//
// A silent fallback here is the worst possible failure: mints would be SIGNED for an
// account nobody monitors, on-ledger and irreversible, while every log line and every
// UI still read normally. Latent today only because wp-config defines it correctly
// (verified live 28 Aug: plat=riMCiWg8QBej6Y3osDVt3u8FJWo2ephuN).
//
// Empty rather than fatal ON PURPOSE: this file also serves read-only actions
// (get_listing, get_buyer_purchases, poll_payment...) that have no business failing
// because a signing constant is absent. The refusal happens at the ONE choke point every
// platform-signed transaction passes through -- sign_and_submit_xrpl() -- so reads keep
// working and nothing can be signed for the wrong account.
if (!defined('IMC_PLATFORM_WALLET')) {
    mod_log('CRITICAL: IMC_PLATFORM_WALLET is not defined in wp-config — all platform signing will be REFUSED until it is set.');
    define('IMC_PLATFORM_WALLET', '');
}
if (!defined('IMC_FEE_WALLET')) {
    define('IMC_FEE_WALLET', 'r3wwgY8rsG3Fa7JFj4mJoqxDr9RWwFtWDV');
}
if (!defined('IMC_PLATFORM_SECRET')) {
    // v49: Prefer IMC_MINTER_SECRET (Regular Key seed) over IMC_PLATFORM_WALLET_SECRET
    $minter_secret = defined('IMC_MINTER_SECRET') ? IMC_MINTER_SECRET 
        : (getenv('IMC_MINTER_SECRET') ?: null);
    if ($minter_secret) {
        define('IMC_PLATFORM_SECRET', $minter_secret);
    } else {
        define('IMC_PLATFORM_SECRET', defined('IMC_PLATFORM_WALLET_SECRET')
            ? IMC_PLATFORM_WALLET_SECRET
            : getenv('IMC_PLATFORM_WALLET_SECRET'));
    }
}
if (!defined('XRPL_RPC')) {
    define('XRPL_RPC', defined('IMU_XRPL_RPC') ? IMU_XRPL_RPC : 'https://xrplcluster.com');
}
if (!defined('IMC_PINATA_JWT')) {
    define('IMC_PINATA_JWT', defined('PINATA_JWT') ? PINATA_JWT : getenv('PINATA_JWT'));
}

// Reservation TTL (seconds) — 30 minutes (aligned with Xaman payload expiry)
// v452: Previously 600s (10 min) while Xaman gave users 30 min to sign,
// causing payment orphaning when users signed between 10-30 minutes.
if (!defined('IMC_RESERVATION_TTL')) {
    define('IMC_RESERVATION_TTL', 1800);
}

// Max editions per single purchase
if (!defined('IMC_MAX_PER_MINT')) {
    define('IMC_MAX_PER_MINT', 10);
}

// PP-1 (Progressive Pricing): master switch. 0 = every progressive config is ignored and
// base prices rule -- byte-equivalent pricing to v850. Defined with !defined() so a
// wp-config.php override wins (2b pattern, same as the allowlist switches).
if (!defined('IMC_PROGRESSIVE_PRICING')) {
    define('IMC_PROGRESSIVE_PRICING', 1);
}
// M-ED-R (2 Sep 2026): retain a consumed edition on a NON-TOPMOST, NOT-ON-CHAIN failure and
// reuse it on retry instead of clearing it and burning a fresh number (an observed numbering gap).
// Explicit flag column purchases.edition_retained carries the fact across the retry reset.
// 0 = byte-identical to pre-M-ED-R behaviour. wp-config override wins.
if (!defined('IMC_MED_REUSE')) {
    define('IMC_MED_REUSE', 1);
}
// C-FIX (2 Sep 2026): when the CLI reports a mint that VALIDATED on-chain but whose
// vps_save_nftoken never landed (Defect C), bind that id to the row -- unless it collides
// with another purchase -- so the retry takes the v277 OFFER-RECOVERY branch instead of
// minting a second NFT and stranding the first. 0 = byte-identical to pre-C-FIX behaviour.
if (!defined('IMC_DEFECT_C_PERSIST')) {
    define('IMC_DEFECT_C_PERSIST', 1);
}

// XRPL mint retry limit
if (!defined('IMC_MINT_RETRIES')) {
    define('IMC_MINT_RETRIES', 3);
}

$xumm_api_key = defined('XUMM_API_KEY') ? XUMM_API_KEY : '';
$xumm_api_secret = defined('XUMM_API_SECRET') ? XUMM_API_SECRET : '';

// VPS Signing Proxy (preferred for managed hosting like the web host)
// Set these in wp-config.php to use a remote signing service:
//   define('IMC_SIGNER_URL', '<your signing service URL>');
//   define('IMC_SIGNER_API_KEY', 'your-shared-api-key');
$vps_signer_url = defined('IMC_SIGNER_URL') ? IMC_SIGNER_URL : '';
$vps_signer_key = defined('IMC_SIGNER_API_KEY') ? IMC_SIGNER_API_KEY : '';

// Hardcastle XRPL PHP library — local fallback (only needed if VPS signer not configured)
// Set IMC_HARDCASTLE_PATH in wp-config.php if library is installed locally
$hardcastle_autoload = defined('IMC_HARDCASTLE_PATH') ? IMC_HARDCASTLE_PATH : '';
$xrpl_lib_loaded = false;
if (!empty($hardcastle_autoload) && file_exists($hardcastle_autoload)) {
    require_once $hardcastle_autoload;
    $xrpl_lib_loaded = true;
    mod_log("Hardcastle XRPL library loaded from: $hardcastle_autoload");
}

// Log signing method availability
if (!empty($vps_signer_url)) {
    mod_log("XRPL signing: VPS proxy configured at $vps_signer_url");
} elseif ($xrpl_lib_loaded) {
    mod_log("XRPL signing: Local Hardcastle library loaded");
} else {
    mod_log("WARNING: No XRPL signing method available!");
    mod_log("  → Option A (recommended): Set IMC_SIGNER_URL + IMC_SIGNER_API_KEY in wp-config.php");
    mod_log("  → Option B: Set IMC_HARDCASTLE_PATH in wp-config.php to a local vendor/autoload.php");
}

// ============================================================================
// DATABASE TABLES
// ============================================================================
global $wpdb;
$listings_table  = $wpdb->prefix . 'imc_listings';
$purchases_table = $wpdb->prefix . 'imc_purchases';
$groups_table    = $wpdb->prefix . 'imc_purchase_groups';
$tiers_table     = $wpdb->prefix . 'imc_listing_tiers';

// ============================================================================
// SCHEMA MIGRATIONS
// ============================================================================

/**
 * Task #1 — Create purchase_groups table
 */
function ensure_purchase_groups_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'imc_purchase_groups';
    $charset = $wpdb->get_charset_collate();

    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
        mod_log("Creating purchase_groups table");
        $wpdb->query("CREATE TABLE $table (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            listing_id BIGINT UNSIGNED NOT NULL,
            buyer_account VARCHAR(35) NOT NULL,
            quantity INT UNSIGNED NOT NULL DEFAULT 1,
            allowlist_qty INT UNSIGNED NOT NULL DEFAULT 0,
            total_price_xrp DECIMAL(20,6) NOT NULL,
            payment_currency VARCHAR(40) DEFAULT 'XRP',
            payment_currency_issuer VARCHAR(35) DEFAULT NULL,
            payment_amount DECIMAL(38,8) NOT NULL DEFAULT 0,
            payment_xumm_uuid VARCHAR(64),
            payment_tx_hash VARCHAR(64),
            payment_verified TINYINT(1) DEFAULT 0,
            payment_verified_at DATETIME DEFAULT NULL,
            reservation_expires_at DATETIME NOT NULL,
            mint_progress INT UNSIGNED DEFAULT 0,
            claim_progress INT UNSIGNED DEFAULT 0,
            status ENUM('reserved','paid','minting','minted','partial','claimed','expired','failed') DEFAULT 'reserved',
            error_message TEXT,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_listing (listing_id),
            INDEX idx_buyer (buyer_account),
            INDEX idx_status (status),
            INDEX idx_expiry (status, reservation_expires_at)
        ) $charset;");
        mod_log("purchase_groups table created");
    } else {
        // v71: Add currency columns if missing
        $existing_cols = $wpdb->get_col("DESCRIBE $table", 0);
        if (!in_array('payment_currency', $existing_cols)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN payment_currency VARCHAR(40) DEFAULT 'XRP' AFTER total_price_xrp");
            $wpdb->query("ALTER TABLE $table ADD COLUMN payment_currency_issuer VARCHAR(35) DEFAULT NULL AFTER payment_currency");
            $wpdb->query("ALTER TABLE $table ADD COLUMN payment_amount DECIMAL(38,8) NOT NULL DEFAULT 0 AFTER payment_currency_issuer");
            mod_log("v71: Added currency columns to purchase_groups table");
        }
        // v242: Expand status ENUM to include 'partial' (partial mint success)
        $col_info = $wpdb->get_row("SHOW COLUMNS FROM $table LIKE 'status'", ARRAY_A);
        if ($col_info && strpos($col_info['Type'], "'partial'") === false) {
            $wpdb->query("ALTER TABLE $table MODIFY COLUMN status ENUM('reserved','paid','minting','minted','partial','claimed','expired','failed') DEFAULT 'reserved'");
            mod_log("v242: Added 'partial' to purchase_groups status ENUM");
        }
    }
}
ensure_purchase_groups_table();

/**
 * Task #1 — Ensure purchases table exists + all required columns
 */
function ensure_imc_purchases_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'imc_purchases';
    $charset = $wpdb->get_charset_collate();

    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
        mod_log("Creating purchases table");
        $wpdb->query("CREATE TABLE $table (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            group_id BIGINT UNSIGNED DEFAULT NULL,
            listing_id BIGINT UNSIGNED NOT NULL,
            buyer_account VARCHAR(35) NOT NULL,
            artist_account VARCHAR(35) NOT NULL,
            edition_number INT UNSIGNED DEFAULT 0,
            tier_id BIGINT UNSIGNED DEFAULT NULL,
            tier_name VARCHAR(100) DEFAULT NULL,
            metadata_ipfs VARCHAR(128) DEFAULT NULL,
            price_xrp DECIMAL(20,6) NOT NULL DEFAULT 0,
            price_currency VARCHAR(40) DEFAULT 'XRP',
            payment_xumm_uuid VARCHAR(64),
            payment_tx_hash VARCHAR(64),
            payment_verified TINYINT(1) DEFAULT 0,
            payment_verified_at DATETIME DEFAULT NULL,
            mint_status ENUM('pending','paid','minting','minted','claimed','failed','cancelled') DEFAULT 'pending',
            nftoken_id VARCHAR(64),
            mint_tx_hash VARCHAR(64),
            minted_at DATETIME DEFAULT NULL,
            sell_offer_id VARCHAR(64),
            sell_offer_tx_hash VARCHAR(64),
            claim_xumm_uuid VARCHAR(64),
            claim_tx_hash VARCHAR(64),
            delivered TINYINT(1) DEFAULT 0,
            delivered_at DATETIME DEFAULT NULL,
            error_message TEXT,
            retry_count INT DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_buyer (buyer_account),
            INDEX idx_artist (artist_account),
            INDEX idx_status (mint_status),
            INDEX idx_listing (listing_id),
            INDEX idx_group (group_id)
        ) $charset;");
        mod_log("Purchases table created");
    }
}
ensure_imc_purchases_table();

/**
 * Task #1 — Migrate existing tables: add ALL missing columns
 * v41 fix: Previous migration only added 5 columns. Tables created by older code
 * were missing updated_at, created_at, payment_verified_at, error_message, retry_count.
 * This caused silent INSERT/UPDATE failures — the root cause of "no paid purchases".
 */
function run_phase4_migrations() {
    global $wpdb;
    $purchases_table = $wpdb->prefix . 'imc_purchases';
    $listings_table  = $wpdb->prefix . 'imc_listings';
    $tiers_table     = $wpdb->prefix . 'imc_listing_tiers';

    // --- Purchases table migrations (COMPREHENSIVE) ---
    $pcols = $wpdb->get_col("DESCRIBE $purchases_table", 0);

    $p_migrations = [
        'group_id'            => "ADD COLUMN group_id BIGINT UNSIGNED DEFAULT NULL AFTER id, ADD INDEX idx_group (group_id)",
        'tier_id'             => "ADD COLUMN tier_id BIGINT UNSIGNED DEFAULT NULL AFTER edition_number",
        'tier_name'           => "ADD COLUMN tier_name VARCHAR(100) DEFAULT NULL AFTER tier_id",
        'metadata_ipfs'       => "ADD COLUMN metadata_ipfs VARCHAR(128) DEFAULT NULL AFTER tier_name",
        'sell_offer_tx_hash'  => "ADD COLUMN sell_offer_tx_hash VARCHAR(64) DEFAULT NULL AFTER sell_offer_id",
        // v41: These were missing from the original migration — caused silent INSERT/UPDATE failures
        'payment_verified'    => "ADD COLUMN payment_verified TINYINT(1) DEFAULT 0",
        'payment_verified_at' => "ADD COLUMN payment_verified_at DATETIME DEFAULT NULL",
        'updated_at'          => "ADD COLUMN updated_at DATETIME DEFAULT NULL",
        'created_at'          => "ADD COLUMN created_at DATETIME DEFAULT NULL",
        'error_message'       => "ADD COLUMN error_message TEXT DEFAULT NULL",
        'retry_count'         => "ADD COLUMN retry_count INT DEFAULT 0",
        'minted_at'           => "ADD COLUMN minted_at DATETIME DEFAULT NULL",
        'delivered'           => "ADD COLUMN delivered TINYINT(1) DEFAULT 0",
        'delivered_at'        => "ADD COLUMN delivered_at DATETIME DEFAULT NULL",
        // M-ED-R (2 Sep 2026): set ONLY by vps_mint_failed's gap branch (not-on-chain AND not rolled back).
        // The on-chain (Defect C) branch never sets it, so a retry can never reuse an edition whose NFT exists.
        'edition_retained'    => "ADD COLUMN edition_retained TINYINT(1) NOT NULL DEFAULT 0 AFTER edition_number",
        // B (Phase 3): 1 = a historical duplicate-edition row (the recorded baseline pairs) that stays but leaves
        // the unique space. The generated edition_key + uniq_listing_edition are NOT in this map --
        // that DDL can fail on violators and must be run by hand after the violator pre-check.
        'legacy_dup'          => "ADD COLUMN legacy_dup TINYINT(1) NOT NULL DEFAULT 0 AFTER edition_retained",
    ];
    foreach ($p_migrations as $col => $sql) {
        if (!in_array($col, $pcols)) {
            $result = $wpdb->query("ALTER TABLE $purchases_table $sql");
            if ($result === false) {
                mod_log("Migration ERROR: failed to add $col to purchases: " . $wpdb->last_error);
            } else {
                mod_log("Migration: added $col to purchases");
            }
        }
    }

    // v43 FIX: Drop legacy unique_edition constraint from purchases table.
    // Older code created UNIQUE(listing_id, edition_number), but edition_number starts as 0
    // and is assigned at MINT time — so qty>1 inserts both have edition_number=0, causing
    // "Duplicate entry '13-0' for key 'unique_edition'" on the second INSERT.
    $idx_check = $wpdb->get_results("SHOW INDEX FROM $purchases_table WHERE Key_name = 'unique_edition'", ARRAY_A);
    if (!empty($idx_check)) {
        $drop_result = $wpdb->query("ALTER TABLE $purchases_table DROP INDEX unique_edition");
        if ($drop_result === false) {
            mod_log("Migration ERROR: failed to drop unique_edition: " . $wpdb->last_error);
        } else {
            mod_log("Migration v43: dropped legacy unique_edition index (was blocking qty>1 inserts)");
        }
    }

    // Fix mint_status ENUM if it doesn't include 'paid' and 'claimed'
    $col_info = $wpdb->get_row("SHOW COLUMNS FROM $purchases_table LIKE 'mint_status'", ARRAY_A);
    if ($col_info && strpos($col_info['Type'], 'paid') === false) {
        $wpdb->query("ALTER TABLE $purchases_table MODIFY COLUMN mint_status 
            ENUM('pending','paid','minting','minted','claimed','failed','cancelled','burned') DEFAULT 'pending'");
        mod_log("Migration: updated mint_status ENUM on purchases");
    }
    // B (Phase 3): 'burned' -- applied to a row only once its NFT is confirmed burned on-ledger.
    // Extends the ENUM in place; existing values untouched (MODIFY with a superset is metadata-only).
    if ($col_info && strpos($col_info['Type'], 'burned') === false) {
        $wpdb->query("ALTER TABLE $purchases_table MODIFY COLUMN mint_status 
            ENUM('pending','paid','minting','minted','claimed','failed','cancelled','burned') DEFAULT 'pending'");
        mod_log("Migration: added 'burned' to mint_status ENUM on purchases (B)");
    }

    // --- Purchase-groups table migrations (v728 A2) ---
    // allowlist_qty: >0 marks a group whose price came from an allowlist benefit
    // (custom/proportional/percent/free). 0 = full price. Written only when
    // IMC_ALLOWLIST_SCOPED_CONSUME is on; column added unconditionally so the
    // flip is a wp-config change, never a schema race.
    $groups_table_mig = $wpdb->prefix . 'imc_purchase_groups';
    $gcols = $wpdb->get_col("DESCRIBE $groups_table_mig", 0);
    if (is_array($gcols) && !empty($gcols) && !in_array('allowlist_qty', $gcols)) {
        $gres = $wpdb->query("ALTER TABLE $groups_table_mig ADD COLUMN allowlist_qty INT UNSIGNED NOT NULL DEFAULT 0 AFTER quantity");
        if ($gres === false) {
            mod_log("Migration ERROR: failed to add allowlist_qty to purchase_groups: " . $wpdb->last_error);
        } else {
            mod_log("Migration v728: added allowlist_qty to purchase_groups");
        }
    }
    // PP-1: composite index so the step SUM (listing_id + payment_verified [+ currency])
    // is an index range scan. Guarded by SHOW INDEX -- runs once, then never again.
    if ($wpdb->get_var("SHOW TABLES LIKE '$groups_table_mig'") === $groups_table_mig) {
        $pp_idx = $wpdb->get_results("SHOW INDEX FROM $groups_table_mig WHERE Key_name = 'idx_pp'", ARRAY_A);
        if (empty($pp_idx)) {
            $pp_ires = $wpdb->query("ALTER TABLE $groups_table_mig ADD INDEX idx_pp (listing_id, payment_verified, payment_currency)");
            mod_log($pp_ires === false ? "Migration ERROR: failed to add idx_pp: " . $wpdb->last_error : "Migration PP-1: added idx_pp to purchase_groups");
        }
    }

    // --- Listings table migrations ---
    $lcols = $wpdb->get_col("DESCRIBE $listings_table", 0);

    if (!in_array('reserved_count', $lcols)) {
        $wpdb->query("ALTER TABLE $listings_table ADD COLUMN reserved_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER minted_count");
        mod_log("Migration: added reserved_count to listings");
    }
    if (!in_array('has_tiers', $lcols)) {
        $wpdb->query("ALTER TABLE $listings_table ADD COLUMN has_tiers TINYINT(1) NOT NULL DEFAULT 0 AFTER downloadable");
        mod_log("Migration: added has_tiers to listings");
    }

    // --- Tiers table migrations (v41: was completely missing) ---
    if ($wpdb->get_var("SHOW TABLES LIKE '$tiers_table'") === $tiers_table) {
        $tcols = $wpdb->get_col("DESCRIBE $tiers_table", 0);
        $t_migrations = [
            'updated_at' => "ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
            'created_at' => "ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        ];
        foreach ($t_migrations as $col => $sql) {
            if (!in_array($col, $tcols)) {
                $result = $wpdb->query("ALTER TABLE $tiers_table $sql");
                if ($result === false) {
                    mod_log("Migration ERROR: failed to add $col to tiers: " . $wpdb->last_error);
                } else {
                    mod_log("Migration: added $col to tiers");
                }
            }
        }
    }
}
run_phase4_migrations();


// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

function json_success($data) {
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

function json_error($msg, $code = 400, $extra = []) {
    http_response_code($code);
    $resp = array_merge(['success' => false, 'error' => $msg], $extra);
    echo json_encode($resp);
    exit;
}

/**
 * Create XUMM/Xaman payload
 * Updated: Added return_url for mobile Xaman redirect-back
 */
function create_xumm_payload($txjson, $custom_meta = []) {
    global $xumm_api_key, $xumm_api_secret;

    if (empty($xumm_api_key) || empty($xumm_api_secret)) {
        return ['error' => 'XUMM API not configured'];
    }

    // Determine return URL based on transaction type
    $type = $custom_meta['type'] ?? '';
    switch ($type) {
        case 'listing_fee':
            // v43: Redirect to creator dashboard so artist can see their listing is live
            $listing_id_meta = intval($custom_meta['listing_id'] ?? 0);
            $return_url = home_url('/creator-dashboard/?listing_published=1'
                . ($listing_id_meta ? '&listing_id=' . $listing_id_meta : ''));
            break;
        case 'nft_purchase':
            // v295 FIX: Return to trading hub dashboard, NOT /collections/.
            //
            // PREVIOUS BEHAVIOUR (v284): Return to /collections/?purchase_pending=1&group_id=X
            // The resumePaymentPollIfNeeded() function in mint-on-demand.js was intended
            // to detect purchase_pending=1 and re-open the purchase modal to resume polling.
            //
            // PROBLEM: On iOS, Xaman fires the return_url which NAVIGATES the browser tab
            // to a completely fresh page load. The /collections/ page on a fresh load has
            // no open modal — createPurchaseModal() has not been called yet, so
            // document.getElementById('mod-purchase-modal') returns null. The resume code
            // has `if (modal)` — if null, it starts polling silently with zero UI.
            // Even when polling does detect tesSUCCESS and calls processGroup → loadClaimData,
            // the claim step tries to populate #mod-step-3 which also doesn't exist.
            // The user sees a blank /collections/ page with no indication of what to do.
            //
            // FIX: Redirect to /trading-hub-dashboard/?payment_pending=1&group_id=X
            // The dashboard has a dedicated payment_pending handler (added in v295) that:
            //   1. If group already paid/minted → calls window.mintOnDemand._retryFromHistory()
            //   2. If group still reserved → polls Xaman uuid, then calls processGroup
            // The purchase modal is created on-demand by _retryFromHistory / the handler,
            // and the dashboard is a more appropriate landing page post-purchase anyway.
            $listing_id = intval($custom_meta['listing_id'] ?? 0);
            $group_id   = intval($custom_meta['group_id']   ?? 0);
            $return_url = home_url('/trading-hub-dashboard/?payment_pending=1' .
                ($group_id   ? '&group_id='   . $group_id   : '') .
                ($listing_id ? '&listing_id=' . $listing_id : '') .
                '#pending-claims');
            break;
        case 'nft_claim':
            // v294 FIX: All claim return URLs go to trading hub dashboard with claim_complete param.
            // PREVIOUS: qty=1 -> /nft/TOKEN_ID/?claimed=1, qty>1 -> /trading-hub-dashboard/?pending=1
            // PROBLEM: On mobile, Xaman fires the return_url which navigates to a fresh page.
            // The JS poll (startClaimPolling) was running in the now-suspended/replaced modal.
            // confirmDelivery() never fires -> delivered=0 stays in DB -> Pending Claims still shows
            // the NFT as claimable -> buyer sees permanent spinner or stale Claim button.
            // FIX: Always redirect to /trading-hub-dashboard/?claim_complete=1&purchase_id=X
            // Dashboard detects this param on load, calls confirmDelivery() server-side, then
            // reloads Pending Claims. Mobile claiming now always completes correctly regardless
            // of whether the JS poll loop was still alive.
            $claimed_nft_id = $custom_meta['nftoken_id'] ?? '';
            $purchase_id_cm = intval($custom_meta['purchase_id'] ?? 0);
            $group_qty      = intval($custom_meta['group_quantity'] ?? 1);
            $group_id_cm    = intval($custom_meta['group_id'] ?? 0);
            // v304: always pass group_id so the dashboard can show the Recently Minted section.
            // qty=1 → ?recently_minted=1&group_id=X  (shows all NFTs from that purchase)
            // qty>1 → ?claim_complete=1&...&group_id=X  (remaining handled by pending claims)
            $return_url = home_url('/trading-hub-dashboard/?claim_complete=1'
                . ($purchase_id_cm ? '&purchase_id=' . $purchase_id_cm : '')
                . ($claimed_nft_id ? '&nftoken_id=' . preg_replace('/[^A-Fa-f0-9]/', '', $claimed_nft_id) : '')
                . ($group_id_cm    ? '&group_id='   . $group_id_cm    : ''));
            break;
        default:
            $return_url = home_url('/?tx_complete=1');
    }

    // M7a: XUMM's custom_meta schema is {identifier, blob, instruction}; any other key is silently
    // dropped (proven: every mint-payment webhook to date arrived with all-null custom_meta and was
    // classified as a 'tip'). Carry the routing keys INSIDE `blob`, which XUMM echoes verbatim to the
    // webhook and which xumm-proxy.php's imu_resolve_payload_type() already reads (blob.type). No
    // `identifier`: XUMM requires those unique per app, so a retried group would collide and 400.
    // The function's own use of $custom_meta above (return URLs) is unchanged.
    $xumm_meta = ['blob' => array_filter([
        'type'        => $type,
        'group_id'    => intval($custom_meta['group_id']    ?? 0) ?: null,
        'listing_id'  => intval($custom_meta['listing_id']  ?? 0) ?: null,
        'purchase_id' => intval($custom_meta['purchase_id'] ?? 0) ?: null,
    ])];
    if (!empty($custom_meta['instruction'])) { $xumm_meta['instruction'] = substr((string) $custom_meta['instruction'], 0, 280); }

    $payload = [
        'txjson'      => $txjson,
        'options'     => [
            'submit' => true,
            // v515 FIX: Xumm 'expire' is denominated in MINUTES. 1800 = 30 HOURS,
            // so payment requests outlived the 30-minute reservation by ~29.5h --
            // the orphan-payment window (tx 26258AEE class). Derive from
            // IMC_RESERVATION_TTL (seconds) so the payload dies with the reservation.
            'expire' => (int) max(1, ceil(IMC_RESERVATION_TTL / 60)),
            'return_url' => [
                'web' => $return_url,
                'app' => $return_url
            ]
        ],
        'custom_meta' => $xumm_meta   // M7a (was $custom_meta -- top-level keys were dropped by XUMM)
    ];

    $ch = curl_init('https://xumm.app/api/v1/platform/payload');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-API-Key: ' . $xumm_api_key,
            'X-API-Secret: ' . $xumm_api_secret
        ],
        CURLOPT_TIMEOUT => 30
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        mod_log("XUMM error HTTP $http_code: $response");
        return ['error' => 'XUMM API error'];
    }

    return json_decode($response, true);
}

/**
 * Poll XUMM payload status (existing, unchanged)
 */
function poll_xumm($uuid) {
    global $xumm_api_key, $xumm_api_secret;

    $ch = curl_init("https://xumm.app/api/v1/platform/payload/$uuid");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'X-API-Key: ' . $xumm_api_key,
            'X-API-Secret: ' . $xumm_api_secret
        ],
        CURLOPT_TIMEOUT => 10
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

/**
 * M7a-2: budget-aware XUMM payload lookup for the RECOVERY NETS. Same return shape as poll_xumm()
 * (every caller reads only meta.signed + response.txid, then verifies ON-CHAIN), plus a 'source' key.
 *   1. wp_xumm_status row with signed=1 + tx_hash (written by xumm-proxy.php's webhook branch AFTER
 *      it re-verified the payload with XUMM) -> answer from the DB. Zero XUMM calls.
 *   2. A cached TERMINAL-UNSIGNED verdict (expired / cancelled / resolved-but-unsigned) -> replay it.
 *      Those states cannot become 'signed' later, so re-polling them is pure budget burn: pre-M7a-2
 *      reconcile_expired_paid re-polled every recent abandoned reservation EVERY 3 MINUTES for 72h
 *      (a test batch measured ~19 XUMM calls/min). Cached for 72h = reconcile's window.
 *   3. Otherwise poll XUMM as before, and cache the verdict if it came back terminal-unsigned.
 * XUMM enforces a per-minute call budget for the whole app. Money truth is untouched: the
 * on-chain verify_payment_multicurrency() downstream runs exactly as it did.
 */
function imc_xumm_lookup($uuid) {
    global $wpdb;
    $uuid = (string) $uuid;
    if ($uuid === '') return null;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT signed, tx_hash, account FROM {$wpdb->prefix}xumm_status WHERE uuid = %s AND signed = 1 AND tx_hash IS NOT NULL AND tx_hash <> ''",
        $uuid
    ), ARRAY_A);
    if ($row) {
        return ['meta' => ['signed' => true, 'resolved' => true, 'expired' => false, 'cancelled' => false],
                'response' => ['txid' => $row['tx_hash'], 'dispatched_result' => 'tesSUCCESS', 'account' => $row['account']], 'source' => 'db'];
    }
    $tkey = 'imc_m7a_term_' . substr(preg_replace('/[^a-f0-9]/', '', strtolower($uuid)), 0, 32);
    $term = get_transient($tkey);
    if (is_array($term) && isset($term['meta'])) { $term['source'] = 'term-cache'; return $term; }
    $pl = poll_xumm($uuid);
    if (is_array($pl)) {
        $m = $pl['meta'] ?? [];
        if (empty($m['signed']) && (!empty($m['expired']) || !empty($m['cancelled']) || !empty($m['resolved']))) {
            set_transient($tkey, ['meta' => ['signed' => false, 'resolved' => !empty($m['resolved']), 'expired' => !empty($m['expired']), 'cancelled' => !empty($m['cancelled'])], 'response' => []], 72 * 3600);
        }
        $pl['source'] = 'xumm';
    }
    return $pl;
}

/**
 * Cancel a XUMM/Xaman payload (reject it so it can no longer be signed)
 * v292: Called when a buyer creates a new reservation, voiding any stale
 *       pending payloads so the old Xaman request cannot be signed and
 *       produce an orphaned on-chain payment.
 */
function cancel_xumm_payload($uuid) {
    global $xumm_api_key, $xumm_api_secret;

    if (empty($uuid)) return false;

    $ch = curl_init("https://xumm.app/api/v1/platform/payload/$uuid");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-API-Key: ' . $xumm_api_key,
            'X-API-Secret: ' . $xumm_api_secret
        ],
        CURLOPT_TIMEOUT => 10
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 200 = cancelled, 404 = already resolved/expired (both are fine)
    return in_array($http_code, [200, 404]);
}

// ============================================================================
// XRPL HELPERS (Task #4)
// ============================================================================

/**
 * Send JSON-RPC call to XRPL node
 */
function xrpl_rpc($method, $params = []) {
    $body = json_encode([
        'method' => $method,
        'params' => [$params]
    ]);

    $ch = curl_init(XRPL_RPC);
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
        mod_log("XRPL RPC curl error: $err");
        return ['error' => "Connection error: $err"];
    }

    $data = json_decode($resp, true);
    if (!$data || !isset($data['result'])) {
        mod_log("XRPL RPC bad response: " . substr($resp, 0, 500));
        return ['error' => 'Invalid XRPL response'];
    }

    return $data['result'];
}

/**
 * Get current account sequence number
 */
function get_account_sequence($account) {
    $result = xrpl_rpc('account_info', [
        'account'     => $account,
        'ledger_index' => 'current'
    ]);

    if (isset($result['error'])) {
        mod_log("get_account_sequence error: " . json_encode($result));
        return false;
    }

    return intval($result['account_data']['Sequence'] ?? 0);
}

/**
 * Verify a payment transaction on the XRP Ledger (Task M3 — anti-fraud)
 *
 * Checks that:
 * 1. Transaction exists and is validated (tesSUCCESS)
 * 2. TransactionType is Payment
 * 3. Destination matches expected artist wallet
 * 4. Amount is >= expected total (in drops)
 *
 * @param string $tx_hash          Transaction hash from XUMM
 * @param string $expected_dest    Expected destination (artist wallet)
 * @param float  $expected_xrp     Expected XRP amount
 * @return array ['verified' => bool, 'error' => string|null]
 */
/**
 * v713: Reduce a token amount to something the XRPL can carry EXACTLY, always rounding DOWN.
 *
 * TWO REAL HAZARDS THIS CLOSES:
 *  1. XRPL IOU amounts hold at most 15 significant digits. Hand the ledger more and it
 *     rounds them -- possibly UPWARDS, which can push a payment past its own SendMax and
 *     fail tecPATH_PARTIAL: exactly the bug this whole change exists to fix.
 *  2. PHP's `strval()` on a float honours precision=14 and ALSO rounds up. Proven on this
 *     runtime: a 380,000,000 spend at a 0.33% fee floors to 378750124.58885670, but
 *     strval() emits "378750124.58886" -- which exceeds SendMax. Small values happen to be
 *     safe, so a low-value test alone would never surface it. Never use strval() here.
 *
 * Returns BOTH the float and its canonical string, derived from the same rounded-down
 * value, so the number stored in the DB and the number signed on-chain cannot drift.
 *
 * @param  float $v        Amount to reduce.
 * @param  int   $max_dec  Cap on decimal places (8 = our DECIMAL(38,8) storage precision).
 * @return array           ['value' => float, 'str' => string]
 */
if (!function_exists('imc_xrpl_amount_floor')) {
    function imc_xrpl_amount_floor($v, $max_dec = 8) {
        $v = (float) $v;
        if (!is_finite($v) || $v <= 0) {
            return ['value' => 0.0, 'str' => '0'];
        }
        // Spend the 15-digit budget on the integer part first, then whatever is left on
        // decimals -- so a large amount simply carries fewer decimals rather than
        // overflowing the ledger's precision.
        $int_digits = ($v >= 1) ? ((int) floor(log10($v)) + 1) : 1;
        $dec = max(0, min((int) $max_dec, 15 - $int_digits));
        $p = pow(10, $dec);
        $floored = floor($v * $p) / $p;
        // Format with EXACTLY $dec places. Using more (or strval) reintroduces the
        // rounding described above.
        $str = ($dec > 0)
            ? rtrim(rtrim(sprintf('%.' . $dec . 'F', $floored), '0'), '.')
            : sprintf('%.0F', $floored);
        if ($str === '' || $str === '-' || $str === '-0') {
            $str = '0';
        }
        // Re-read the float FROM the string so the two can never disagree.
        return ['value' => (float) $str, 'str' => $str];
    }
}

/**
 * v713: The companion to imc_xrpl_amount_floor() -- identical magnitude logic, rounds UP.
 *
 * USED ONLY FOR SendMax, and the direction matters. Flooring SendMax breaks the payment:
 * a float like 1234567.89 is stored slightly below its decimal value, so flooring emits
 * "1234567.88999999" -- a hair UNDER what is needed to deliver, and the payment fails.
 * Measured on this runtime: flooring SendMax broke 13 of 50 spend/rate combinations,
 * including the entirely ordinary price 1,234,567.89. Ceiling holds all 50.
 *
 * Rounding SendMax up costs the buyer NOTHING. SendMax is a CAP, not a charge -- the debit
 * is always `Amount x TransferRate`, and because Amount was floored that product is always
 * at or below the quoted price. The cap merely has to be large enough to permit it.
 *
 * @param  float $v        Amount to raise.
 * @param  int   $max_dec  Cap on decimal places.
 * @return array           ['value' => float, 'str' => string]
 */
if (!function_exists('imc_xrpl_amount_ceil')) {
    function imc_xrpl_amount_ceil($v, $max_dec = 8) {
        $v = (float) $v;
        if (!is_finite($v) || $v <= 0) {
            return ['value' => 0.0, 'str' => '0'];
        }
        $int_digits = ($v >= 1) ? ((int) floor(log10($v)) + 1) : 1;
        $dec = max(0, min((int) $max_dec, 15 - $int_digits));
        $p = pow(10, $dec);
        $ceiled = ceil($v * $p) / $p;
        $str = ($dec > 0)
            ? rtrim(rtrim(sprintf('%.' . $dec . 'F', $ceiled), '0'), '.')
            : sprintf('%.0F', $ceiled);
        if ($str === '' || $str === '-' || $str === '-0') {
            $str = '0';
        }
        return ['value' => (float) $str, 'str' => $str];
    }
}

// -- M8-a helpers ---------------------------------------------------------------
// Persist what the verifier measured (it always computed this; callers discarded it),
// and flag groups that today only whisper into mod_log. Both are pure visibility:
// no behaviour, no return values consumed, failures swallowed (best-effort columns).
function imc_record_received($group_id, $verify) {
    global $wpdb; $groups_table = $wpdb->prefix . 'imc_purchase_groups';
    $rcv = $verify['received'] ?? null; if (!$group_id || !is_array($rcv)) return;
    $wpdb->update($groups_table, ['received_amount' => floatval($rcv['value'] ?? 0),
        'received_currency' => substr(strval($rcv['currency'] ?? ''), 0, 43)], ['id' => intval($group_id)]);
}
// ==========================================================================================
// B (Phase 3, 2 Sep 2026) -- the UNIQUE backstop, code side.
// The DB now carries uniq_listing_edition on (listing_id, edition_key) where edition_key is
// NULL for placeholders (edition 0), non-live rows and legacy_dup rows. A refused write here
// is MySQL saying "that edition is already live on this listing" -- something every code guard
// (allocator, M-ED, M-ED-R, v277/v593/v885, LW) exists to prevent. If it ever fires:
//   PRE-mint  (vps_prepare_nft 2b)  -> nothing on-chain yet. Do NOT roll the counter back (it is
//              BEHIND the live editions, not ahead); roll back the tier, clear edition_retained,
//              flag, error. The retry allocates fresh. A duplicate that never reached the ledger.
//   POST-mint (6 sites)             -> the NFT exists with edition N in its metadata. Cannot be
//              undone -- but the row MUST still get its nftoken/offer/status or the retry mints
//              AGAIN. Re-write the same data with edition_number = 0 and a B-DUP marker: buyer
//              whole, no second mint, DB never claims N twice, human reconciles the metadata.
// Never silent. Only the constraint name is treated as B; any other DB error keeps prior behaviour.
// ==========================================================================================
function imc_b_refused() {
    global $wpdb;
    return ($wpdb->last_error !== '' && stripos((string) $wpdb->last_error, 'uniq_listing_edition') !== false);
}
function imc_b_dup($phase, $purchase_id, $group_id, $listing_id, $edition, $nftoken = '') {
    mod_log("B-DUP CRITICAL [$phase]: edition $edition ALREADY LIVE on listing=$listing_id -- purchase=$purchase_id group=$group_id nftoken=" . ($nftoken ?: '-') . " -- constraint uniq_listing_edition refused the write");
    if (intval($group_id) > 0) imc_flag_attention(intval($group_id), "B-DUP $phase: edition $edition already live on listing $listing_id (purchase $purchase_id" . ($nftoken ? ", nftoken $nftoken" : '') . ')');
}

function imc_flag_attention($group_id, $reason) {
    global $wpdb; $groups_table = $wpdb->prefix . 'imc_purchase_groups';
    if (!$group_id) return;
    $wpdb->update($groups_table, ['needs_attention' => 1, 'attention_reason' => substr($reason, 0, 120)], ['id' => intval($group_id)]);
    mod_log("ATTENTION: group=$group_id flagged ($reason)");
}

function verify_payment_onchain($tx_hash, $expected_dest, $expected_xrp) {
    // v71: Use multi-currency function with XRP
    return verify_payment_multicurrency($tx_hash, $expected_dest, [
        'currency' => 'XRP',
        'value' => $expected_xrp
    ]);
}

/**
 * v71: Verify a payment transaction supporting multiple currencies
 *
 * @param string $tx_hash          Transaction hash from XUMM
 * @param string $expected_dest    Expected destination (artist wallet)
 * @param array  $expected_amount  Expected amount: ['currency'=>'XRP|TOKEN','value'=>float,'issuer'=>string|null]
 * @return array ['verified' => bool, 'error' => string|null, 'received' => array|null]
 */
function verify_payment_multicurrency($tx_hash, $expected_dest, $expected_amount) {
    if (empty($tx_hash) || strlen($tx_hash) < 10) {
        return ['verified' => false, 'error' => 'Invalid transaction hash'];
    }

    $currency = strtoupper($expected_amount['currency'] ?? 'XRP');
    $expected_value = floatval($expected_amount['value'] ?? 0);
    $expected_issuer = $expected_amount['issuer'] ?? null;

    // v42 FIX: XRPL transactions take 3-5 seconds to be included in a validated ledger.
    $max_attempts = 8;
    $tx = null;

    for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
        if ($attempt > 1) {
            sleep(2);
        }

        $tx = xrpl_rpc('tx', ['transaction' => $tx_hash, 'binary' => false]);

        if (isset($tx['error'])) {
            mod_log("verify_payment: attempt $attempt/$max_attempts — tx not found: " . ($tx['error'] ?? 'unknown'));
            continue;
        }

        if ($tx['validated'] ?? false) {
            mod_log("verify_payment: tx validated on attempt $attempt/$max_attempts");
            break;
        }

        mod_log("verify_payment: attempt $attempt/$max_attempts — tx found but not yet validated");
    }

    if (!$tx || isset($tx['error'])) {
        mod_log("verify_payment: tx lookup failed after $max_attempts attempts: " . json_encode($tx));
        return ['verified' => false, 'error' => 'Transaction not found on ledger after ' . $max_attempts . ' attempts'];
    }

    if (!($tx['validated'] ?? false)) {
        mod_log("verify_payment: tx NOT validated after $max_attempts attempts (~" . ($max_attempts * 2) . "s): $tx_hash");
        return ['verified' => false, 'error' => 'Transaction not validated after waiting — please retry'];
    }

    // Check engine result
    $meta_result = $tx['meta']['TransactionResult'] ?? '';
    if ($meta_result !== 'tesSUCCESS') {
        mod_log("verify_payment: tx failed on-ledger: $meta_result");
        return ['verified' => false, 'error' => "Transaction failed: $meta_result"];
    }

    // Check TransactionType
    if (($tx['TransactionType'] ?? '') !== 'Payment') {
        mod_log("verify_payment: wrong tx type: " . ($tx['TransactionType'] ?? 'unknown'));
        return ['verified' => false, 'error' => 'Not a Payment transaction'];
    }

    // Check Destination
    if (($tx['Destination'] ?? '') !== $expected_dest) {
        mod_log("verify_payment: wrong destination: " . ($tx['Destination'] ?? 'none') . " expected: $expected_dest");
        return ['verified' => false, 'error' => 'Payment destination mismatch'];
    }

    // Get delivered amount (use meta.delivered_amount for partial payments)
    $amount = $tx['meta']['delivered_amount'] ?? $tx['Amount'] ?? '0';
    
    // v71: Handle both XRP and token payments
    if ($currency === 'XRP') {
        // XRP payment - amount should be string (drops)
        if (!is_string($amount)) {
            mod_log("verify_payment: expected XRP but received token payment");
            return ['verified' => false, 'error' => 'Expected XRP payment but received token'];
        }
        
        $amount_drops = intval($amount);
        $expected_drops = intval(round($expected_value * 1000000));
        
        // Allow 1% tolerance for rounding
        if ($amount_drops < ($expected_drops * 0.99)) {
            mod_log("verify_payment: insufficient XRP: {$amount_drops} drops, expected {$expected_drops}");
            return ['verified' => false, 'error' => "Insufficient payment: received " . ($amount_drops / 1000000) . " XRP, expected " . $expected_value . " XRP"];
        }
        
        mod_log("verify_payment: VERIFIED XRP tx=$tx_hash dest=$expected_dest amount=" . ($amount_drops / 1000000) . " XRP");
        return [
            'verified' => true, 
            'error' => null,
            'received' => ['currency' => 'XRP', 'value' => $amount_drops / 1000000]
        ];
    } else {
        // Token payment - amount should be object {currency, issuer, value}
        if (is_string($amount)) {
            mod_log("verify_payment: expected token but received XRP payment");
            return ['verified' => false, 'error' => "Expected $currency payment but received XRP"];
        }
        
        if (!is_array($amount)) {
            mod_log("verify_payment: invalid amount format");
            return ['verified' => false, 'error' => 'Invalid payment amount format'];
        }
        
        // Check currency matches
        $received_currency = strtoupper($amount['currency'] ?? '');
        // v606: on-chain a >3-char code is a 40-char hex; decode it back to its ASCII ticker
        // so it matches the expected ticker (3-char codes like XFT are untouched).
        $received_decoded = (strlen($received_currency) === 40 && ctype_xdigit($received_currency))
            ? strtoupper(rtrim((string) @hex2bin($received_currency), "\0"))
            : $received_currency;
        $expected_currency_normalized = strtoupper($currency);
        // v607: also accept the token's authoritative on-chain hex (mixed-case/special tickers).
        $imc_vc = function_exists('imc_get_token_by_ticker') ? imc_get_token_by_ticker($currency) : null;
        $expected_hex = !empty($imc_vc['currency_hex']) ? strtoupper($imc_vc['currency_hex'])
            : ((strlen($currency) > 3) ? strtoupper(str_pad(bin2hex($currency), 40, '0')) : '');
        
        if ($received_currency !== $expected_currency_normalized
            && $received_decoded !== $expected_currency_normalized
            && $received_currency !== $currency
            && ($expected_hex === '' || $received_currency !== $expected_hex)) {
            mod_log("verify_payment: wrong currency: $received_currency, expected: $currency");
            return ['verified' => false, 'error' => "Wrong currency: received $received_currency, expected $currency"];
        }
        
        // Check issuer matches (if specified)
        if ($expected_issuer && ($amount['issuer'] ?? '') !== $expected_issuer) {
            mod_log("verify_payment: wrong issuer: " . ($amount['issuer'] ?? 'none') . ", expected: $expected_issuer");
            return ['verified' => false, 'error' => 'Payment from wrong token issuer'];
        }
        
        // Check value (allow 1% tolerance)
        $received_value = floatval($amount['value'] ?? 0);
        if ($received_value < ($expected_value * 0.99)) {
            mod_log("verify_payment: insufficient token amount: $received_value, expected: $expected_value $currency");
            return ['verified' => false, 'error' => "Insufficient payment: received $received_value $currency, expected $expected_value $currency"];
        }
        
        mod_log("verify_payment: VERIFIED token tx=$tx_hash dest=$expected_dest amount=$received_value $currency");
        return [
            'verified' => true, 
            'error' => null,
            'received' => [
                'currency' => $received_currency,
                'issuer' => $amount['issuer'] ?? null,
                'value' => $received_value
            ]
        ];
    }
}

/**
 * Proven pattern from the read API
 */
function acquire_xrpl_lock($operation, $timeout = 600) {
    $lock_file = __DIR__ . "/logs/xrpl_lock_{$operation}.lock";
    $start = time();

    while (time() - $start < $timeout) {
        if (file_exists($lock_file)) {
            $lock_time = @filemtime($lock_file);
            // v242: Raised stale threshold to 300s (was 120s).
            // A qty=10 batch takes up to ~60s; slow XRPL nodes can push
            // a legitimate run past the old 120s limit, causing two
            // processes to hold overlapping sequences → tefPAST_SEQ.
            if ($lock_time && (time() - $lock_time > 300)) {
                $stale_age = time() - $lock_time;
                @unlink($lock_file);
                mod_log("Cleared stale XRPL lock: $operation (age={$stale_age}s)");
            } else {
                usleep(500000); // Wait 500ms
                continue;
            }
        }

        $fp = @fopen($lock_file, 'x'); // Atomic create
        if ($fp) {
            fwrite($fp, time() . "\n" . getmypid());
            fclose($fp);
            mod_log("Acquired XRPL lock: $operation");
            return true;
        }
        usleep(500000);
    }

    mod_log("FAILED to acquire XRPL lock: $operation (timeout {$timeout}s)");
    return false;
}

/**
 * v896 (Defect O) — KEEP A LIVE LOCK LOOKING ALIVE.
 *
 * THE BUG: acquire_xrpl_lock() decides a lock is stale from its FILE MTIME —
 * `time() - filemtime > 300` (L1003) — but that mtime is set ONCE at creation
 * (L1013-1016) and NEVER refreshed. So the threshold does not measure "how long
 * since the holder last did something"; it measures "how long since it STARTED".
 *
 * A run that legitimately takes longer than 300s therefore has its lock DELETED
 * out from under it by the next caller, and TWO processes then mint against the
 * same XRPL account sequence — tefPAST_SEQ at best, duplicate submissions at worst.
 *
 * Measured ~15.5s per edition (IPFS pin + NFTokenMint + NFTokenCreateOffer, each
 * awaiting ledger validation):
 *   admin_compensate, qty up to 20 .... ~310s  <- ALREADY OVER THE THRESHOLD
 *   creator_mint, qty up to 10 ........ ~155s  <- over it on a slow RPC/IPFS day
 *
 * The v242 comment at L999-1002 shows this was hit before and answered by raising
 * the threshold 120s -> 300s. That buys time; it does not fix the mechanism. A
 * heartbeat does: touching the lock once per edition keeps the mtime within ~16s
 * of now while work is actually happening, so a LIVE holder can never look stale —
 * while a genuinely DEAD holder still ages out and is cleared exactly as before.
 *
 * Deliberately cheap and silent: one touch() per edition, no logging (a 20-edition
 * compensate would otherwise add 20 log lines saying nothing). Failure is ignored:
 * if the lock file has already vanished the run has bigger problems, and this is
 * not the place to discover them.
 */
function touch_xrpl_lock($operation) {
    $lock_file = __DIR__ . "/logs/xrpl_lock_{$operation}.lock";
    if (file_exists($lock_file)) { @touch($lock_file); }
}

function release_xrpl_lock($operation) {
    $lock_file = __DIR__ . "/logs/xrpl_lock_{$operation}.lock";
    @unlink($lock_file);
    mod_log("Released XRPL lock: $operation");
}

/**
 * Send JSON response and close HTTP connection, allowing PHP to continue in background.
 * Used by process_group (M4) to return immediately while minting continues async.
 *
 * @param array $data  Data to send as JSON success response
 */
function send_early_response($data) {
    $response = json_encode(['success' => true, 'data' => $data]);

    // Prevent further output from WordPress
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Length: ' . strlen($response));
    header('Connection: close');
    http_response_code(200);

    echo $response;

    // Close HTTP connection to client
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        ignore_user_abort(true);
        flush();
    }

    // v452: Allow background minting up to 30 minutes — aligned with reservation TTL.
    // Previously 600s (10 min) which could be exceeded by large batches (10+ NFTs)
    // if VPS delegation fails and the web host handles minting inline.
    set_time_limit(1800);
}

/**
 * Sign and submit a transaction to XRPL using Hardcastle library
 * Falls back to sign-and-submit JSON-RPC if Hardcastle not available
 *
 * @param array $txjson  Transaction fields (TransactionType, Account, etc.)
 * @param int   $sequence  Account sequence number
 * @return array  ['success' => bool, 'hash' => tx_hash, 'result' => engine_result, ...]
 */
function sign_and_submit_xrpl($txjson, $sequence) {
    global $xrpl_lib_loaded, $vps_signer_url, $vps_signer_key;

    // Ensure required tx fields
    $txjson['Account']  = $txjson['Account'] ?? IMC_PLATFORM_WALLET;

    // v892 (Defect AG): every platform-signed transaction in this file passes through
    // here. If the wallet constant is missing there is no safe default -- refuse rather
    // than sign for whatever the fallback happened to be.
    if (empty($txjson['Account'])) {
        mod_log('CRITICAL: refusing to sign — platform wallet is not configured (IMC_PLATFORM_WALLET missing from wp-config).');
        return ['success' => false, 'error' => 'Platform wallet not configured — signing refused. Contact support.'];
    }

    $txjson['Sequence'] = $sequence;
    $txjson['Fee']      = $txjson['Fee'] ?? '12';

    // -------------------------------------------------------------------
    // Method 1: VPS Signing Proxy (preferred for managed hosting)
    // Signs on your VPS where Hardcastle is installed — secret never on the web host
    // -------------------------------------------------------------------
    if (!empty($vps_signer_url) && !empty($vps_signer_key)) {
        mod_log("Signing via VPS proxy: $vps_signer_url");

        $payload = json_encode([
            'action'  => 'sign_and_submit',
            'api_key' => $vps_signer_key,
            'tx_json' => $txjson
        ]);

        $ch = curl_init($vps_signer_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 60,  // v50: allow for validation polling on VPS
            CURLOPT_CONNECTTIMEOUT => 10
        ]);

        $resp = curl_exec($ch);
        $curl_err = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curl_err) {
            mod_log("VPS signer curl error: $curl_err");
            // Fall through to local Hardcastle if available
        } elseif ($http_code !== 200) {
            $error_data = json_decode($resp, true);
            $error_msg = $error_data['error'] ?? "HTTP $http_code";
            // v43: Log raw response when JSON decode fails (catches PHP fatal error HTML)
            if (!$error_data) {
                mod_log("VPS signer raw response ($http_code): " . substr($resp, 0, 500));
            }
            mod_log("VPS signer error ($http_code): $error_msg");
            // Auth/config errors won't fix themselves — fail immediately
            if ($http_code === 401 || $http_code === 500) {
                return ['success' => false, 'error' => "VPS signer: $error_msg"];
            }
            // Rate limit or network issues — fall through to local
        } else {
            $result = json_decode($resp, true);
            if ($result && isset($result['success']) && $result['success'] && isset($result['data'])) {
                $data = $result['data'];
                mod_log("XRPL submit (VPS proxy): result=" . ($data['result'] ?? '?') . " hash=" . ($data['hash'] ?? '?'));
                // v50: Log whether validated meta was returned
                $has_meta = !empty($data['raw']['meta']['AffectedNodes']);
                mod_log("XRPL submit (VPS proxy): meta " . ($has_meta ? "present (" . count($data['raw']['meta']['AffectedNodes']) . " nodes)" : "MISSING"));
                return [
                    'success' => (bool)($data['success'] ?? false),
                    'hash'    => $data['hash'] ?? null,
                    'result'  => $data['result'] ?? 'unknown',
                    'raw'     => $data['raw'] ?? []
                ];
            } else {
                $err = $result['error'] ?? 'Invalid response from VPS signer';
                mod_log("VPS signer returned error: $err");
                return ['success' => false, 'error' => "VPS signer: $err"];
            }
        }

        mod_log("VPS signer unavailable, checking local Hardcastle fallback...");
    }

    // -------------------------------------------------------------------
    // Method 2: Local Hardcastle signing (if library installed on this server)
    // -------------------------------------------------------------------
    if ($xrpl_lib_loaded) {
        $secret = IMC_PLATFORM_SECRET;
        if (empty($secret)) {
            return ['success' => false, 'error' => 'IMC_PLATFORM_SECRET not configured'];
        }

        try {
            // Auto-fill LastLedgerSequence
            if (!isset($txjson['LastLedgerSequence'])) {
                $ledger = xrpl_rpc('ledger', ['ledger_index' => 'validated']);
                $current_ledger = intval($ledger['ledger_index'] ?? 0);
                if ($current_ledger > 0) {
                    $txjson['LastLedgerSequence'] = $current_ledger + 20;
                }
            }

            $wallet   = \Hardcastle\XRPL\ValueObject\Wallet::fromSeed($secret);
            $prepared = new \Hardcastle\XRPL\Transaction\Transaction($txjson);
            $signed   = $wallet->sign($prepared);
            $blob     = $signed->getSignedBlob();

            $submit_result = xrpl_rpc('submit', ['tx_blob' => $blob]);

            $engine_result = $submit_result['engine_result'] ?? 'unknown';
            $tx_hash       = $submit_result['tx_json']['hash'] ?? $signed->getHash() ?? null;

            mod_log("XRPL submit (local Hardcastle): result=$engine_result hash=$tx_hash");

            return [
                'success' => ($engine_result === 'tesSUCCESS' || $engine_result === 'terQUEUED'),
                'hash'    => $tx_hash,
                'result'  => $engine_result,
                'raw'     => $submit_result
            ];

        } catch (\Exception $e) {
            mod_log("Local Hardcastle signing error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Local signing failed: ' . $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------
    // Method 3: JSON-RPC sign (PERMANENTLY DISABLED — security risk)
    // Sending secrets to public XRPL nodes is dangerous.
    // -------------------------------------------------------------------
    mod_log("FATAL: No XRPL signing method available!");
    mod_log("  → Set IMC_SIGNER_URL + IMC_SIGNER_API_KEY in wp-config.php (recommended)");
    mod_log("  → Or set IMC_HARDCASTLE_PATH to a local vendor/autoload.php");

    return ['success' => false, 'error' => 'No XRPL signing method configured. Contact admin.'];
}


// ============================================================================
// MINTING PIPELINE FUNCTIONS (Tasks #5–9)
// ============================================================================

/**
 * Task #5 — Weighted random tier selection with row-level locking
 *
 * @param int $listing_id
 * @return array|null  Tier row or null (non-tiered)
 */
function pick_random_tier($listing_id) {
    global $wpdb, $tiers_table;

    // Lock tiers for this listing (prevents concurrent race on minted_count)
    $tiers = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $tiers_table WHERE listing_id = %d FOR UPDATE",
        $listing_id
    ), ARRAY_A);

    if (empty($tiers)) {
        return null; // Non-tiered listing
    }

    // Build weighted pool by remaining editions
    $pool = [];
    foreach ($tiers as $tier) {
        $remaining = max(0, intval($tier['total_editions']) - intval($tier['minted_count']));
        if ($remaining > 0) {
            for ($i = 0; $i < $remaining; $i++) {
                $pool[] = $tier;
            }
        }
    }

    if (empty($pool)) {
        mod_log("pick_random_tier: all tiers exhausted for listing_id=$listing_id");
        return null;
    }

    $selected = $pool[array_rand($pool)];
    mod_log("pick_random_tier: listing=$listing_id selected tier={$selected['tier_name']} (id={$selected['id']})");

    return $selected;
}

/**
 * Task #6 — Build per-edition metadata JSON
 *
 * Clones base metadata_json from listing, applies tier overrides, stamps edition number.
 *
 * @param array $listing   Full listing row
 * @param array|null $tier Tier row (null if non-tiered)
 * @param int   $edition   Edition number
 * @return array  Metadata ready for IPFS pinning
 */
function build_edition_metadata($listing, $tier, $edition) {
    // Parse base metadata
    // v390: Try direct decode first (clean data), then stripslashes fallback.
    // Pre-v390 listings stored metadata_json with WordPress magic quotes (escaped \" chars)
    // which causes json_decode to return null, losing all base attributes like
    // "Streaming Access: Granted" and "Art Type: Digital Art" from tiered mints.
    $raw = $listing['metadata_json'] ?? '{}';
    $base = json_decode($raw, true) ?: json_decode(stripslashes($raw), true);
    if (!is_array($base)) {
        $base = [];
    }

    // Start from base
    $meta = $base;

    // Override name with edition stamp
    // v392: stripslashes handles pre-v392 listings stored with WordPress magic quotes
    // (e.g. Baseball Diamond \"Trading Card Addition\" → Baseball Diamond "Trading Card Addition")
    $base_name = stripslashes($listing['nft_name'] ?? 'NFT');
    $meta['name'] = "{$base_name} #{$edition}";

    // Override description if not set
    if (empty($meta['description'])) {
        $meta['description'] = stripslashes($listing['description'] ?? '');
    }

    // Tier-specific overrides
    if ($tier) {
        // Override cover image
        $meta['image'] = 'ipfs://' . ltrim($tier['cover_ipfs'], 'ipfs://');

        // Override audio/video if tier has own preview
        // v226: Art has no audio/video preview — image IS the display media
        // M2-a: eBook joins art here - it is a read format with no audio, so no
        // root audio/animation_url is stamped and it classifies as an image NFT.
        $is_art = in_array($listing['nft_type'] ?? '', ['art', 'ebook'], true);
        if (!$is_art) {
            if (!empty($tier['preview_ipfs'])) {
                $preview_uri = 'ipfs://' . ltrim($tier['preview_ipfs'], 'ipfs://');
                $meta['audio'] = $preview_uri;
                $meta['animation_url'] = $preview_uri; // v50: standard field for Bithomp/explorers
            } else {
                // v60 FIX: Fallback to listing preview when tier has no preview_ipfs
                if (!empty($listing['preview_ipfs'])) {
                    $preview_uri = 'ipfs://' . ltrim($listing['preview_ipfs'], 'ipfs://');
                    $meta['audio'] = $preview_uri;
                    $meta['animation_url'] = $preview_uri;
                } elseif (!empty($listing['media_ipfs'])) {
                    $media_uri = 'ipfs://' . ltrim($listing['media_ipfs'], 'ipfs://');
                    $meta['audio'] = $media_uri;
                    $meta['animation_url'] = $media_uri;
                }
            }
        }

        // Merge tier traits into attributes
        $tier_traits = json_decode($tier['tier_traits'] ?? '[]', true);
        if (!empty($tier_traits) && is_array($tier_traits)) {
            // Remove any existing attributes that tier overrides
            $tier_trait_types = array_column($tier_traits, 'trait_type');
            $existing_attrs = $meta['attributes'] ?? [];
            $filtered = array_filter($existing_attrs, function($attr) use ($tier_trait_types) {
                return !in_array($attr['trait_type'] ?? '', $tier_trait_types);
            });
            $meta['attributes'] = array_values(array_merge($filtered, $tier_traits));
        }

        // Add rarity tier attribute
        $meta['attributes'][] = [
            'trait_type' => 'Rarity',
            'value'      => $tier['tier_name']
        ];
    } else {
        // Non-tiered: use listing cover
        $meta['image'] = 'ipfs://' . ltrim($listing['cover_ipfs'], 'ipfs://');

        // Use listing preview audio — v226: skip for art (image is the display)
        // M2-a: eBook joins art here - it is a read format with no audio, so no
        // root audio/animation_url is stamped and it classifies as an image NFT.
        $is_art = in_array($listing['nft_type'] ?? '', ['art', 'ebook'], true);
        if (!$is_art) {
            if (!empty($listing['preview_ipfs'])) {
                $preview_uri = 'ipfs://' . ltrim($listing['preview_ipfs'], 'ipfs://');
                $meta['audio'] = $preview_uri;
                $meta['animation_url'] = $preview_uri; // v50: standard field
            } elseif (!empty($listing['media_ipfs'])) {
                $media_uri = 'ipfs://' . ltrim($listing['media_ipfs'], 'ipfs://');
                $meta['audio'] = $media_uri;
                $meta['animation_url'] = $media_uri; // v50: standard field
            }
        }
    }

    // Stamp edition number in attributes
    // v392: Filter out the Edition #{n} template placeholder from base metadata.
    // buildAttributes() in mint.js adds Edition: #{n} as a template — now that v390
    // correctly decodes base metadata, this placeholder survives into the attributes.
    // Remove it (and any other Edition trait) before adding the real edition value.
    // v393: Open editions (total_editions=0) use "#N (Open Edition)" since the final
    // supply is unknown at mint time. This is permanent IPFS metadata — must be correct.
    $meta['attributes'] = $meta['attributes'] ?? [];
    $meta['attributes'] = array_values(array_filter($meta['attributes'], function($attr) {
        return ($attr['trait_type'] ?? '') !== 'Edition';
    }));
    $oe_active = (($listing['edition_type'] ?? 'fixed') === 'open' && intval($listing['total_editions']) === 0);
    $meta['attributes'][] = [
        'trait_type' => 'Edition',
        'value'      => $oe_active ? "#{$edition} (Open Edition)" : "{$edition} of {$listing['total_editions']}"
    ];

    // Ensure collection info
    if (!empty($listing['collection_name'])) {
        $meta['collection'] = [
            'name'   => stripslashes($listing['collection_name']),
            'family' => 'IMCollectibles'
        ];
    }

    // Master access reference (content hash for entitlement API)
    $master_hash = $tier['master_content_hash'] ?? $listing['master_content_hash'] ?? '';
    if ($master_hash) {
        $meta['properties'] = $meta['properties'] ?? [];
        $meta['properties']['master_access'] = [
            'content_hash'    => $master_hash,
            'entitlement_api' => 'https://imcollectibles.io/wp-json/imu-master/v1/access'
        ];
        // v226: Also update root-level master_access so external tools see correct hash
        if (isset($meta['master_access'])) {
            $meta['master_access']['content_hash'] = $master_hash;
        }
    }

    // v377: Cover access reference (watermarked Music/MV/Film cover art)
    // Allows NFT holders to request the original unprotected cover via entitlement API
    $cover_hash = $tier['cover_content_hash'] ?? $listing['cover_content_hash'] ?? '';
    if ($cover_hash) {
        $meta['cover_access'] = [
            'protected'       => true,
            'provider'        => 'imcollectibles',
            'entitlement_api' => 'https://imcollectibles.io/wp-json/imu-master/v1/access',
            'content_hash'    => $cover_hash
        ];
    }

    // U4 (Unlockables Master): hash-only vault mirror (R8) -- on-chain proof of the
    // edition's unlockable entitlements. Claimed slots for this listing: listing-wide
    // (tier_id=0) plus the minted edition's tier. status='active' keeps DMCA-removed
    // slots out of NEW mints; fee_paid=1 is belt-and-braces (all four mint lanes run
    // post-publish, where the U3 four-site stamping law guarantees it). Hash-only --
    // no storage paths ever. Same-hash claims across scopes dedupe (listing-wide
    // first via ORDER BY tier_id). A listing with no vault files produces metadata
    // byte-identical to before this block existed.
    global $wpdb;
    $imc_ul_table   = $wpdb->prefix . 'imc_listing_unlockables';
    $imc_ul_tier_id = intval($tier['id'] ?? 0);
    $imc_ul_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT content_hash, label, file_size, mime_type FROM $imc_ul_table
          WHERE listing_id = %d AND status = 'active' AND fee_paid = 1
            AND (tier_id = 0 OR tier_id = %d)
          ORDER BY tier_id, sort_order, id",
        intval($listing['id'] ?? 0), $imc_ul_tier_id
    ), ARRAY_A);
    if ($imc_ul_rows) {
        $imc_ul_items = [];
        $imc_ul_seen  = [];
        foreach ($imc_ul_rows as $imc_ul_r) {
            if (isset($imc_ul_seen[$imc_ul_r['content_hash']])) continue;
            $imc_ul_seen[$imc_ul_r['content_hash']] = true;
            $imc_ul_items[] = [
                'content_hash' => $imc_ul_r['content_hash'],
                'label'        => stripslashes($imc_ul_r['label'] ?? ''),
                'size'         => intval($imc_ul_r['file_size']),
                'type'         => $imc_ul_r['mime_type'] ?? 'application/octet-stream',
            ];
        }
        $meta['properties'] = $meta['properties'] ?? [];
        $meta['properties']['unlockable_content'] = [
            'count'           => count($imc_ul_items),
            'entitlement_api' => 'https://imcollectibles.io/wp-json/imu-master/v1/access',
            'items'           => $imc_ul_items,
        ];
    }

    // IMC extensions
    $meta['properties'] = $meta['properties'] ?? [];
    $meta['properties']['platform'] = 'IMCollectibles';
    $meta['properties']['schema']   = 'xls-24d';


    // v598: per-edition preview alignment. Base template seeds preview/files at the
    // listing level (preview.audio=PENDING for tiered; files=cover only) since previews
    // are per-pool (v557). Make preview.* + properties.files per-tier, mirroring the
    // base-template convention (preview key + files type follow content type).
    $imc_pv_type = strtolower($listing['nft_type'] ?? '');

    // (a) Point the properties.files cover entry at the per-tier image (idempotent).
    if (!empty($meta['image']) && isset($meta['properties']['files']) && is_array($meta['properties']['files'])) {
        foreach ($meta['properties']['files'] as $imc_fi => $imc_f) {
            if (isset($imc_f['type']) && strncmp($imc_f['type'], 'image/', 6) === 0) {
                $meta['properties']['files'][$imc_fi]['uri'] = $meta['image'];
                break;
            }
        }
    }

    // Item-1 P7 (2026-08-23): ART files[] sanitiser. v226 doctrine: art has no audio/video
    // preview — the image IS the display. A legacy mint.js buildFiles() bug pushed a phantom
    // {uri: cover, type: 'audio/mpeg'} entry into every art listing template (confirmed
    // uniform across the art templates). Drop any audio/* or video/* entry for art at mint time so every
    // future edition is born clean, regardless of template state. Strictly art-gated: music,
    // musicvideo, film and album files[] are untouched. Types are NOT rewritten here — the
    // pinned artifact's mime is only knowable at create time (Phase 8b).
    if ($imc_pv_type === 'art' && isset($meta['properties']['files']) && is_array($meta['properties']['files'])) {
        $meta['properties']['files'] = array_values(array_filter($meta['properties']['files'], function ($imc_f) {
            $imc_t = $imc_f['type'] ?? '';
            return !(strncmp($imc_t, 'audio/', 6) === 0 || strncmp($imc_t, 'video/', 6) === 0);
        }));
    }

    // (b) Per-tier preview object + preview file entry (music/MV/film only).
    if (in_array($imc_pv_type, ['music', 'musicvideo', 'film', 'audiobook'], true)) {
        $imc_pv = $meta['animation_url'] ?? '';
        if ($imc_pv !== '') {
            $imc_is_video = ($imc_pv_type === 'musicvideo' || $imc_pv_type === 'film');
            $meta['preview'] = is_array($meta['preview'] ?? null) ? $meta['preview'] : [];
            if ($imc_is_video) { $meta['preview']['video'] = $imc_pv; unset($meta['preview']['audio']); }
            else               { $meta['preview']['audio'] = $imc_pv; unset($meta['preview']['video']); }
            if (!isset($meta['preview']['duration_seconds'])) {
                $meta['preview']['duration_seconds'] = ($imc_pv_type === 'film') ? 120
                    : (($imc_pv_type === 'audiobook') ? 60 : 30); // M1-d: audiobook public sample is 60s
            }
            if (!isset($meta['properties']['files']) || !is_array($meta['properties']['files'])) { $meta['properties']['files'] = []; }
            $imc_pv_dup = false;
            foreach ($meta['properties']['files'] as $imc_f) { if (($imc_f['uri'] ?? '') === $imc_pv) { $imc_pv_dup = true; break; } }
            if (!$imc_pv_dup) { $meta['properties']['files'][] = ['uri' => $imc_pv, 'type' => $imc_is_video ? 'video/mp4' : 'audio/mpeg']; }
            if ($imc_pv_type === 'musicvideo') { $meta['video'] = $imc_pv; }
            if ($imc_pv_type === 'film')       { unset($meta['audio']); }
        }
    }

    // v391: Strip extended industry metadata before IPFS pinning.
    // Steps 3-7 (registered/licensed) capture detailed track info, credits, publishing,
    // rights, identifiers, and AI disclosure into music{}, film{}, art{}, video_metadata{}.
    // This data is preserved in wp_imc_listings.metadata_json for internal DB access,
    // reporting, and future PRO/DSP integration — but does NOT need to be on-chain.
    // Essential display fields (name, image, audio, attributes, master_access, cover_access,
    // collection, properties, preview, license, nftType, schema) are all retained.
    // M1-d: audiobook_details{} (ISBN/ASIN, language, release date, rights holder) is DB-only;
    // the lean audiobook{} block stays on-chain, like album{}.
    $extended_fields = ['music', 'film', 'art', 'video_metadata', 'audiobook_details', 'ebook_details'];
    foreach ($extended_fields as $field) {
        unset($meta[$field]);
    }

    mod_log("build_edition_metadata: listing={$listing['id']} edition=$edition tier=" . ($tier['tier_name'] ?? 'none'));

    return $meta;
}

/**
 * Task #7 — Pin JSON metadata to IPFS via Pinata
 *
 * Reuses the established Pinata pattern
 *
 * @param array  $metadata  JSON-serializable metadata
 * @param string $name      Pin name for Pinata dashboard
 * @return array ['success' => bool, 'hash' => CID, 'error' => ...]
 */
function pin_metadata_to_ipfs($metadata, $name = 'nft-metadata.json') {
    $jwt = IMC_PINATA_JWT;
    if (empty($jwt)) {
        mod_log("pin_metadata_to_ipfs: PINATA_JWT not configured");
        return ['success' => false, 'error' => 'Pinata JWT not configured'];
    }

    // Write metadata to temp file
    $tmp = tempnam(sys_get_temp_dir(), 'imc_meta_');
    file_put_contents($tmp, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // Build multipart body
    $boundary = bin2hex(random_bytes(12));
    $body  = "--$boundary\r\n";
    $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"$name\"\r\n";
    $body .= "Content-Type: application/json\r\n\r\n";
    $body .= file_get_contents($tmp);
    $body .= "\r\n--$boundary--\r\n";

    @unlink($tmp);

    $ch = curl_init('https://api.pinata.cloud/pinning/pinFileToIPFS');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $jwt,
            "Content-Type: multipart/form-data; boundary=$boundary"
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT    => 60,
    ]);

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        mod_log("pin_metadata_to_ipfs: curl error: $err");
        return ['success' => false, 'error' => "Pinata connection error: $err"];
    }

    $data = json_decode($resp, true);

    if ($code === 200 && !empty($data['IpfsHash'])) {
        mod_log("pin_metadata_to_ipfs: SUCCESS hash={$data['IpfsHash']}");
        return ['success' => true, 'hash' => $data['IpfsHash']];
    }

    $error_msg = $data['error']['message'] ?? $data['error'] ?? "HTTP $code";
    mod_log("pin_metadata_to_ipfs: FAILED — $error_msg");
    return ['success' => false, 'error' => $error_msg];
}

/**
 * Convert string to hex (for XRPL URI field)
 */
function string_to_hex($str) {
    return strtoupper(bin2hex($str));
}

/**
 * v413 FIX: Extract newly minted NFTokenID from transaction meta.
 *
 * CRITICAL BUG FIX: The previous per-node approach (v50) failed during
 * NFTokenPage splits. When a page reached capacity (~32 tokens), the XRPL
 * splits it into two pages. The CreatedNode for the new page contains MOVED
 * tokens (from the old page) PLUS the newly minted token, sorted by ID.
 * The old code used end($tokens) which picked the LAST token in sort order —
 * but during a split, a token from a DIFFERENT collection (taxon 777) could
 * sort after the new mint (taxon 778), causing the wrong NFTokenID to be
 * returned. This resulted in a sell offer being created for a taxon 777 NFT
 * instead of the intended taxon 778, giving away a paid NFT for free.
 *
 * Fix: Global diff across ALL NFTokenPages in the transaction. Collect every
 * token from all "after" states (FinalFields + NewFields), diff against all
 * "before" states (PreviousFields). The single token in (after - before) is
 * the newly minted NFT. This correctly handles normal mints, page splits,
 * and page deletions.
 *
 * @param array $affected_nodes  The AffectedNodes array from transaction meta
 * @return string|null  The NFTokenID of the newly minted token, or null
 */
function extract_nftoken_from_affected_nodes(array $affected_nodes, ?string $expected_uri_hex = null): ?string {
    $before_ids = [];  // All token IDs that existed BEFORE the transaction
    $after_ids  = [];  // All token IDs that exist AFTER the transaction
    $uri_of     = [];  // v594: NFTokenID => URI (hex, upper) for deterministic URI matching

    foreach ($affected_nodes as $node) {
        // CreatedNode: entirely new page — all tokens are in "after" state.
        // During a split, includes MOVED tokens + the new mint.
        $created = $node['CreatedNode'] ?? null;
        if ($created && ($created['LedgerEntryType'] ?? '') === 'NFTokenPage') {
            foreach ($created['NewFields']['NFTokens'] ?? [] as $t) {
                $id = $t['NFToken']['NFTokenID'] ?? '';
                if ($id) { $after_ids[$id] = true; $uri_of[$id] = strtoupper($t['NFToken']['URI'] ?? ''); }
            }
        }

        // ModifiedNode: existing page changed — has before AND after states.
        // v594 page-split fix: a split-neighbour page reports FinalFields.NFTokens
        // with NO PreviousFields.NFTokens. Those tokens already existed, so when
        // PreviousFields is absent we treat FinalFields as "before" too — otherwise
        // the page's pre-existing tokens look "new" and corrupt the diff (the swap bug).
        $modified = $node['ModifiedNode'] ?? null;
        if ($modified && ($modified['LedgerEntryType'] ?? '') === 'NFTokenPage') {
            $has_prev_tokens = isset($modified['PreviousFields']['NFTokens']);
            foreach ($modified['FinalFields']['NFTokens'] ?? [] as $t) {
                $id = $t['NFToken']['NFTokenID'] ?? '';
                if ($id) { $after_ids[$id] = true; $uri_of[$id] = strtoupper($t['NFToken']['URI'] ?? ''); }
            }
            if ($has_prev_tokens) {
                foreach ($modified['PreviousFields']['NFTokens'] as $t) {
                    $id = $t['NFToken']['NFTokenID'] ?? '';
                    if ($id) $before_ids[$id] = true;
                }
            } else {
                foreach ($modified['FinalFields']['NFTokens'] ?? [] as $t) {
                    $id = $t['NFToken']['NFTokenID'] ?? '';
                    if ($id) $before_ids[$id] = true;
                }
            }
        }

        // DeletedNode: page entirely removed (rare — consumed during merge/split).
        $deleted = $node['DeletedNode'] ?? null;
        if ($deleted && ($deleted['LedgerEntryType'] ?? '') === 'NFTokenPage') {
            foreach ($deleted['FinalFields']['NFTokens'] ?? [] as $t) {
                $id = $t['NFToken']['NFTokenID'] ?? '';
                if ($id) $before_ids[$id] = true;
            }
        }
    }

    // The newly minted token = exists in "after" but NOT in "before"
    $new_tokens = array_diff_key($after_ids, $before_ids);

    // v594 PRIMARY: deterministic match by this edition's metadata URI (immune to splits).
    if ($expected_uri_hex) {
        $want = strtoupper($expected_uri_hex);
        $by_uri = [];
        foreach ($new_tokens as $id => $_) {
            if (($uri_of[$id] ?? '') === $want) $by_uri[$id] = true;
        }
        if (count($by_uri) === 1) {
            return array_key_first($by_uri);
        }
    }

    // FAIL-CLOSED: only return when exactly one candidate remains. NEVER guess on
    // ambiguity (the page-split swap bug). Caller treats null as "mint validated but
    // NFTokenID unresolved" -> purchase fails, no wrong NFT delivered.
    if (count($new_tokens) === 1) {
        return array_key_first($new_tokens);
    }

    mod_log("extract_nftoken: ambiguous/none — new=" . count($new_tokens) . " before=" . count($before_ids) . " after=" . count($after_ids) . " uri_match=" . ($expected_uri_hex ? 'attempted' : 'n/a'));
    return null;
}

/**
 * v594 token-uniqueness backstop (shared). An NFTokenID belongs to exactly ONE purchase.
 * Returns the id of any OTHER purchase already holding $nftoken_id, or null if free.
 * Pass purchase_id = 0 for a not-yet-inserted row (creator mint). Mirrors the
 * vps_save_nftoken guard so every nftoken_id write path is protected identically.
 */
function imc_nftoken_collision_owner($nftoken_id, $purchase_id) {
    global $wpdb;
    if (!$nftoken_id) return null;
    $t = $wpdb->prefix . 'imc_purchases';
    return $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $t WHERE nftoken_id = %s AND id <> %d LIMIT 1",
        $nftoken_id, intval($purchase_id)
    ));
}

// ============================================================================
// PP-1: PROGRESSIVE PRICING HELPERS
//
// THE MODEL (Progressive-Pricing-Master v1): each completed commitment raises the
// price by a creator-set increment. THE STEP SOURCE is SUM(quantity) of
// payment_verified=1 groups -- monotonic by construction (verified never
// un-verifies), one semantic for both scopes, honest bump timing (a PAID buyer
// has locked their step). NOT minted_count: that is the edition-number ALLOCATOR
// (LAST_INSERT_ID lanes) with multi-site semantics unsafe to price on.
//
// AUTHORITY: reserve_purchase computes the price LIVE under the FOR UPDATE lock
// (SQL only -- the v292 law). The materializer below only refreshes what display
// surfaces read; it is never consulted at charge time.
// ============================================================================

/**
 * Decode + gate a listing's progressive config. Returns the config array or null.
 * null whenever: switch off, column absent/empty, disabled, non-static pricing.
 */
/**
 * Apply an allowlist's holder ceiling to ONE entry's value.
 * NULL, '' or 0 = no ceiling, so an un-set column is byte-identical to today.
 * ⚠ IDENTICAL COPY of imc_al_cap() in allowlist-handler.php. The two files compute
 * allocation independently - allowlist-handler drives what the UI SHOWS,
 * reserve_purchase below drives what actually CHARGES under the FOR UPDATE lock.
 * v381 and v727 each had to be fixed in both. If you edit one, edit the other,
 * or the dashboard will promise a ceiling the charge path does not enforce.
 */
function imc_al_holder_cap($value, $limit) {
    $lim = ($limit === null || $limit === '') ? 0 : intval($limit);
    return ($lim > 0 && $value > $lim) ? $lim : $value;
}

function imc_pp_config($listing) {
    if (!IMC_PROGRESSIVE_PRICING) return null;
    if (empty($listing['progressive_json'])) return null;
    $pp_mode_l = ($listing['pricing_mode'] ?? 'static');
    if ($pp_mode_l !== 'static' && $pp_mode_l !== 'dynamic') return null;
    $cfg = json_decode($listing['progressive_json'], true);
    if (!is_array($cfg) || empty($cfg['enabled']) || empty($cfg['increments']) || !is_array($cfg['increments'])) return null;
    // PP-5: a dynamic listing's config must be the single-USD form (and vice versa).
    if ($pp_mode_l === 'dynamic' && !isset($cfg['increments']['USD'])) return null;
    if ($pp_mode_l === 'static' && isset($cfg['increments']['USD'])) return null;
    $cfg['scope'] = (($cfg['scope'] ?? 'together') === 'individual') ? 'individual' : 'together';
    return $cfg;
}

/**
 * The step: verified-paid quantity for this listing (all currencies for 'together',
 * one currency for 'individual'). Plain prepared SELECT -- safe under the lock (v292).
 */
function imc_pp_step($listing_id, $currency = null) {
    global $wpdb, $groups_table;
    $gt = !empty($groups_table) ? $groups_table : $wpdb->prefix . 'imc_purchase_groups';
    if ($currency === null) {
        return intval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(quantity),0) FROM $gt WHERE listing_id = %d AND payment_verified = 1",
            $listing_id
        )));
    }
    return intval($wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(quantity),0) FROM $gt WHERE listing_id = %d AND payment_verified = 1 AND payment_currency = %s",
        $listing_id, strtoupper($currency)
    )));
}

/**
 * Price of the Nth unit: listed base + step*increment, capped. Pure arithmetic,
 * full precision -- rounding happens ONCE, at the delivered figure (the v713 law).
 */
function imc_pp_unit($base, $inc, $step, $cap = null) {
    $p = (float)$base + ((float)$inc * max(0, (int)$step));
    if ($cap !== null && (float)$cap > 0 && $p > (float)$cap) $p = (float)$cap;
    return $p;
}

/**
 * Ladder total for a qty>1 purchase: each unit in the batch pays ITS OWN step
 * (mints #s..#s+q-1), every term capped. Returns [total, first_unit, last_unit, capped].
 */
function imc_pp_ladder($base, $inc, $step, $qty, $cap = null) {
    $total = 0.0; $first = null; $last = null; $capped = false;
    for ($k = 0; $k < max(1, (int)$qty); $k++) {
        $u = imc_pp_unit($base, $inc, (int)$step + $k, $cap);
        if ($cap !== null && (float)$cap > 0 && $u >= (float)$cap) $capped = true;
        if ($first === null) $first = $u;
        $last = $u; $total += $u;
    }
    return ['total' => $total, 'first' => $first, 'last' => $last, 'capped' => $capped];
}

/**
 * R13 MATERIALIZER (display only): refresh price_xrp / accepted_currencies[].price to the
 * CURRENT step prices so every read surface shows the live price with zero surface edits.
 * Reserve never reads these for progressive listings -- it recomputes from the immutable
 * bases in progressive_json (config LOCKS once minted, R12), so this can never feed back.
 * Best-effort by design: a failure only leaves display briefly stale.
 */
function imc_pp_materialize($listing_id) {
    global $wpdb, $listings_table;
    $lt = !empty($listings_table) ? $listings_table : $wpdb->prefix . 'imc_listings';
    $l = $wpdb->get_row($wpdb->prepare(
        "SELECT id, pricing_mode, progressive_json, price_xrp, accepted_currencies FROM $lt WHERE id = %d",
        $listing_id
    ), ARRAY_A);
    if (!$l) return;
    $cfg = imc_pp_config($l);
    if (!$cfg) return;
    $upd = [];
    $step_together = ($cfg['scope'] === 'together') ? imc_pp_step($listing_id) : null;
    foreach ($cfg['increments'] as $pp_cur => $pp_inc) {
        $pp_cur = strtoupper($pp_cur);
        // PP-5: the dynamic (USD) config materializes price_usd -- the base is stamped
        // immutably into the config on first run, mirroring the static base_price stamp.
        if ($pp_cur === 'USD') {
            if (floatval($l['price_usd'] ?? 0) <= 0 && !isset($cfg['base']['USD'])) continue;
            if (!isset($cfg['base']['USD'])) {
                $cfg['base']['USD'] = floatval($l['price_usd']);
                $upd['progressive_json'] = wp_json_encode($cfg);
            }
            $pp_cap_u = isset($cfg['cap']['USD']) ? (float)$cfg['cap']['USD'] : null;
            $pp_step_u = imc_pp_step($listing_id);
            $pp_new_usd = imc_pp_unit((float)$cfg['base']['USD'], (float)$pp_inc, (int)$pp_step_u, $pp_cap_u);
            if (floatval($l['price_usd'] ?? 0) !== $pp_new_usd) { $upd['price_usd'] = $pp_new_usd; }
            continue;
        }
        $pp_cap = isset($cfg['cap'][$pp_cur]) ? (float)$cfg['cap'][$pp_cur] : null;
        $pp_step = ($cfg['scope'] === 'individual') ? imc_pp_step($listing_id, $pp_cur) : $step_together;
        // accepted_currencies entries: update the matching entry's price from ITS base
        // (stamped as base_price on first materialize -- immutable thereafter).
        if (!empty($l['accepted_currencies'])) {
            $acc = json_decode($l['accepted_currencies'], true);
            if (is_array($acc)) {
                $dirty = false;
                foreach ($acc as $i => $c) {
                    $code = strtoupper($c['currency'] ?? '');
                    $hex  = strtoupper($c['currency_hex'] ?? '');
                    if ($code !== $pp_cur && $hex !== $pp_cur) continue;
                    // the immutable base: stored once as base_price on the entry (first
                    // materialize stamps it from the then-current price -- step 0 pre-sales).
                    $b = isset($c['base_price']) ? (float)$c['base_price'] : (float)($c['price'] ?? 0);
                    if ($b <= 0) continue;
                    if (!isset($c['base_price'])) { $acc[$i]['base_price'] = $b; $dirty = true; }
                    $newp = imc_pp_unit($b, (float)$pp_inc, (int)$pp_step, $pp_cap);
                    if ((float)($c['price'] ?? 0) !== $newp) { $acc[$i]['price'] = $newp; $dirty = true; }
                    if (($code === 'XRP' || $hex === 'XRP') && abs((float)$l['price_xrp'] - $newp) > 1e-9) { $upd['price_xrp'] = $newp; }   // M1: write only on real change (mirrors the price_usd guard above)
                }
                if ($dirty) { $upd['accepted_currencies'] = wp_json_encode($acc); $l['accepted_currencies'] = $upd['accepted_currencies']; }
            }
        } elseif ($pp_cur === 'XRP' && floatval($l['price_xrp']) > 0) {
            // XRP-only listing with no accepted_currencies JSON: use progressive_json's own
            // stamped base (config 'base' map, stamped by the same first-run rule below).
            if (!isset($cfg['base']['XRP'])) {
                $cfg['base']['XRP'] = floatval($l['price_xrp']);
                $upd['progressive_json'] = wp_json_encode($cfg);
            }
            $pp_new_xrp = imc_pp_unit((float)$cfg['base']['XRP'], (float)$pp_inc, (int)$pp_step, $pp_cap);
            if (abs((float)$l['price_xrp'] - $pp_new_xrp) > 1e-9) { $upd['price_xrp'] = $pp_new_xrp; }   // M1: write only on real change
        }
    }
    if (!empty($upd)) {
        $upd['updated_at'] = current_time('mysql');
        $wpdb->update($lt, $upd, ['id' => $listing_id]);
        mod_log("pp_materialize: listing=$listing_id refreshed display prices (" . implode(',', array_keys($upd)) . ")");
    }
}

/**
 * Task #8 — Mint NFT on XRPL
 *
 * @param string $artist_account  Issuer wallet
 * @param string $metadata_uri    IPFS URI (ipfs://Qm...)
 * @param int    $taxon           Collection taxon
 * @param int    $transfer_fee    Royalty basis points (0–50000)
 * @param bool   $transferable    Is NFT transferable
 * @param int    $sequence        Account sequence
 * @return array ['success' => bool, 'nftoken_id' => ..., 'hash' => ..., ...]
 */
function mint_nft_onchain($artist_account, $metadata_uri, $taxon, $transfer_fee, $transferable, $sequence) {
    // Build flags: tfMutable (16) always set — Dynamic NFT (Phase 17): URI updatable
    // via NFTokenModify if metadata endpoint or IPFS CID ever needs updating.
    // tfTransferable (8) added conditionally per artist preference.
    // Result: transferable=true → Flags=24, transferable=false → Flags=16
    $flags = 16; // tfMutable (0x00000010) — always on
    if ($transferable) {
        $flags |= 8; // tfTransferable
    }

    // v471: Bithomp minter identification memo (append-only, non-functional)
    // Same pattern as vps-mint-cli.php — this path also signs via VPS Hardcastle proxy.
    $txjson = [
        'TransactionType' => 'NFTokenMint',
        'Account'         => IMC_PLATFORM_WALLET,
        'Issuer'          => $artist_account,
        'URI'             => string_to_hex($metadata_uri),
        'NFTokenTaxon'    => $taxon,
        'TransferFee'     => min(50000, max(0, intval($transfer_fee))),
        'Flags'           => $flags,
        'SourceTag'       => 2606240013,
        'Memos'           => [[ 'Memo' => [
            'MemoType' => strtoupper(bin2hex('IMCollectibles')),
            'MemoData' => strtoupper(bin2hex('NFT Mint by IMCollectibles.io'))
        ]]],
    ];

    $result = sign_and_submit_xrpl($txjson, $sequence);

    if (!$result['success']) {
        return $result;
    }

    // Extract NFTokenID from affected nodes (or wait for tx confirmation)
    $nftoken_id = null;
    $id_source = 'none';

    // v413 FIX: Global diff extraction (replaces per-node v50 logic)
    // The v50 per-node approach failed during NFTokenPage splits — CreatedNode's
    // end($tokens) picked a MOVED token from a different collection instead of
    // the newly minted one. See extract_nftoken_from_affected_nodes() for details.
    $affected = $result['raw']['meta']['AffectedNodes'] ?? [];
    mod_log("mint_nft_onchain: AffectedNodes=" . count($affected));

    $nftoken_id = extract_nftoken_from_affected_nodes($affected, strtoupper(string_to_hex($metadata_uri)));
    $id_source = $nftoken_id ? 'meta_global_diff' : 'none';

    // Fallback: If meta was empty, poll tx from the web host
    if (!$nftoken_id && $result['hash']) {
        mod_log("mint_nft_onchain: no NFTokenID in meta, polling tx fallback...");
        usleep(2000000); // 2s wait
        $tx = xrpl_rpc('tx', ['transaction' => $result['hash']]);
        if (isset($tx['meta']['AffectedNodes'])) {
            $nftoken_id = extract_nftoken_from_affected_nodes($tx['meta']['AffectedNodes'], strtoupper(string_to_hex($metadata_uri)));
            $id_source = $nftoken_id ? 'poll_global_diff' : 'none';
        }
        // Last resort (WARNING: unreliable for batch mints)
        if (!$nftoken_id) {
            mod_log("mint_nft_onchain: WARNING — account_nfts fallback (unreliable in batch!)");
            $nfts = xrpl_rpc('account_nfts', ['account' => IMC_PLATFORM_WALLET, 'limit' => 5]);
            $account_nfts = $nfts['account_nfts'] ?? [];
            if (!empty($account_nfts)) {
                $nftoken_id = end($account_nfts)['NFTokenID'] ?? null;
                $id_source = 'account_nfts_UNRELIABLE';
            }
        }
    }

    $result['nftoken_id'] = $nftoken_id;
    mod_log("mint_nft_onchain: artist=$artist_account nftoken_id=$nftoken_id hash={$result['hash']} source=$id_source");

    return $result;
}

/**
 * Task #9 — Create zero-amount sell offer (Platform → Buyer)
 *
 * @param string $nftoken_id  NFTokenID to offer
 * @param string $buyer       Destination buyer account
 * @param int    $sequence    Account sequence
 * @return array ['success' => bool, 'offer_id' => ..., 'hash' => ..., ...]
 */
function create_sell_offer($nftoken_id, $buyer, $sequence) {
    // v885 (Defect AJ): the XRPL rejects NFTokenCreateOffer where Destination == Account with
    // temMALFORMED -- the mint succeeds and the NFT is then stranded in the platform wallet with
    // no owner. Refuse before submitting. One guard here covers BOTH callers: the inline
    // fallback path and creator_mint.
    if ($buyer === IMC_PLATFORM_WALLET) {
        mod_log("create_sell_offer: BLOCKED self-offer (Destination == Account) nftoken=$nftoken_id");
        return ['success' => false, 'error' => 'Self-offer blocked (Destination == Account)'];
    }
    $txjson = [
        'TransactionType' => 'NFTokenCreateOffer',
        'Account'         => IMC_PLATFORM_WALLET,
        'NFTokenID'       => $nftoken_id,
        'Amount'          => '0',
        'Destination'     => $buyer,
        'Flags'           => 1, // tfSellNFToken
        'SourceTag'       => 2606240013,
    ];

    $result = sign_and_submit_xrpl($txjson, $sequence);

    if (!$result['success']) {
        return $result;
    }

    // v50: Extract offer ID from meta (VPS now returns validated meta)
    $offer_id = null;
    $offer_source = 'none';
    $affected = $result['raw']['meta']['AffectedNodes'] ?? [];
    mod_log("create_sell_offer: AffectedNodes=" . count($affected));
    foreach ($affected as $node) {
        $created = $node['CreatedNode'] ?? null;
        if ($created && ($created['LedgerEntryType'] ?? '') === 'NFTokenOffer') {
            $offer_id = $created['LedgerIndex'] ?? null;
            $offer_source = 'meta';
            break;
        }
    }

    // Fallback: poll tx for offer ID
    if (!$offer_id && $result['hash']) {
        mod_log("create_sell_offer: no offer_id in meta, polling tx...");
        usleep(2000000); // v50: 2s wait
        $tx = xrpl_rpc('tx', ['transaction' => $result['hash']]);
        foreach (($tx['meta']['AffectedNodes'] ?? []) as $node) {
            $created = $node['CreatedNode'] ?? null;
            if ($created && ($created['LedgerEntryType'] ?? '') === 'NFTokenOffer') {
                $offer_id = $created['LedgerIndex'] ?? null;
                $offer_source = 'poll';
                break;
            }
        }
    }

    $result['offer_id'] = $offer_id;
    mod_log("create_sell_offer: nftoken=$nftoken_id buyer=$buyer offer_id=$offer_id source=$offer_source");

    return $result;
}


// ============================================================================
// MINT PIPELINE - Layer 2 batch helpers (v600)
//
// Additive helpers that submit MANY platform-wallet txs in one pipelined call
// via the signer's sign_and_submit_batch action, instead of submit-wait per tx.
// They build txjsons IDENTICAL to mint_nft_onchain()/create_sell_offer() and
// REUSE extract_nftoken_from_affected_nodes() (v413) - nothing is reimplemented.
//
// They perform NO tier/edition/duplication/DB logic - that stays in the prep
// and rollback paths. The single-tx primitives mint_nft_onchain(),
// create_sell_offer() and sign_and_submit_xrpl() are left UNTOUCHED (frozen).
// A per-tx failure is mapped to a per-item failure result - it never throws
// across the batch. These helpers are not yet wired into any mint loop (Layer 3).
// ============================================================================

/**
 * HTTP transport to the signer's sign_and_submit_batch action.
 * Mirrors sign_and_submit_xrpl()'s curl, but sends an ARRAY of tx_jsons (each
 * carrying its own Sequence) and returns the per-tx results array.
 * @param array $txjsons  list of fully-built tx_json arrays (Sequence set)
 * @return array ['success'=>bool, 'results'=>array, 'count'=>int, 'succeeded'=>int, 'error'=>string]
 */
function sign_and_submit_xrpl_batch($txjsons) {
    global $vps_signer_url, $vps_signer_key;

    if (empty($vps_signer_url) || empty($vps_signer_key)) {
        return ['success' => false, 'error' => 'VPS signer not configured', 'results' => []];
    }
    if (!is_array($txjsons) || count($txjsons) === 0) {
        return ['success' => false, 'error' => 'No transactions to submit', 'results' => []];
    }

    $payload = json_encode([
        'action'   => 'sign_and_submit_batch',
        'api_key'  => $vps_signer_key,
        'tx_jsons' => array_values($txjsons),
    ]);

    $ch = curl_init($vps_signer_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 75,  // signer validation budget is ~35s; allow headroom
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $resp      = curl_exec($ch);
    $curl_err  = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $curl_err) {
        mod_log("sign_and_submit_xrpl_batch curl error: $curl_err");
        return ['success' => false, 'error' => "VPS signer curl: $curl_err", 'results' => []];
    }
    if ($http_code !== 200) {
        $error_data = json_decode($resp, true);
        $error_msg  = $error_data['error'] ?? "HTTP $http_code";
        mod_log("sign_and_submit_xrpl_batch error ($http_code): $error_msg");
        return ['success' => false, 'error' => "VPS signer: $error_msg", 'results' => []];
    }

    $decoded = json_decode($resp, true);
    if (empty($decoded['success']) || !isset($decoded['data']['results'])) {
        $err = $decoded['error'] ?? 'Invalid response from VPS batch signer';
        mod_log("sign_and_submit_xrpl_batch returned error: $err");
        return ['success' => false, 'error' => "VPS signer: $err", 'results' => []];
    }

    $data = $decoded['data'];
    mod_log("sign_and_submit_xrpl_batch: count=" . ($data['count'] ?? 0) . " succeeded=" . ($data['succeeded'] ?? 0));
    return [
        'success'   => true,
        'results'   => $data['results'],
        'count'     => $data['count'] ?? count($data['results']),
        'succeeded' => $data['succeeded'] ?? 0,
    ];
}

/**
 * Build + submit a pipelined batch of NFTokenMint txs.
 * Each $item: ['purchase_id','artist_account','metadata_uri','taxon','transfer_fee','transferable','sequence'].
 * txjson construction MIRRORS mint_nft_onchain() exactly (flags 16|8, Issuer,
 * hex URI, taxon, clamped TransferFee, Bithomp memo). nftoken_id extraction
 * REUSES extract_nftoken_from_affected_nodes() (v413), with a tx-poll fallback.
 * @return array per-item: ['purchase_id','success','nftoken_id','hash','engine_result','error'] (input order)
 */
function mint_nfts_batch($items) {
    $txjsons = [];
    foreach ($items as $item) {
        $flags = 16; // tfMutable - always on (mirror mint_nft_onchain)
        if (!empty($item['transferable'])) { $flags |= 8; } // tfTransferable
        $txjsons[] = [
            'TransactionType' => 'NFTokenMint',
            'Account'         => IMC_PLATFORM_WALLET,
            'Issuer'          => $item['artist_account'],
            'URI'             => string_to_hex($item['metadata_uri']),
            'NFTokenTaxon'    => intval($item['taxon']),
            'TransferFee'     => min(50000, max(0, intval($item['transfer_fee']))),
            'Flags'           => $flags,
            'Sequence'        => intval($item['sequence']),
            'SourceTag'       => 2606240013,
            'Memos'           => [[ 'Memo' => [
                'MemoType' => strtoupper(bin2hex('IMCollectibles')),
                'MemoData' => strtoupper(bin2hex('NFT Mint by IMCollectibles.io'))
            ]]],
        ];
    }

    $batch = sign_and_submit_xrpl_batch($txjsons);
    $out = [];
    if (empty($batch['success'])) {
        foreach ($items as $item) {
            $out[] = ['purchase_id' => $item['purchase_id'], 'success' => false, 'nftoken_id' => null,
                      'hash' => null, 'engine_result' => null, 'error' => $batch['error'] ?? 'batch transport failed'];
        }
        return $out;
    }

    // Map results by their reported index (robust against any reordering).
    $by_index = [];
    foreach ($batch['results'] as $r) { if (isset($r['index'])) { $by_index[$r['index']] = $r; } }

    foreach ($items as $idx => $item) {
        $entry = ['purchase_id' => $item['purchase_id'], 'success' => false, 'nftoken_id' => null,
                  'hash' => null, 'engine_result' => null, 'error' => null];
        $res = $by_index[$idx] ?? null;
        if (!$res) { $entry['error'] = 'no batch result for index ' . $idx; $out[] = $entry; continue; }
        $entry['hash']          = $res['hash'] ?? null;
        $entry['engine_result'] = $res['engine_result'] ?? null;
        if (empty($res['success'])) { $entry['error'] = $res['error'] ?? 'mint not validated'; $out[] = $entry; continue; }

        // v413 global-diff extraction (REUSED) + tx-poll fallback (mirror mint_nft_onchain)
        $affected   = $res['meta']['AffectedNodes'] ?? [];
        $__exp_uri  = strtoupper(string_to_hex($item['metadata_uri']));
        $nftoken_id = extract_nftoken_from_affected_nodes($affected, $__exp_uri);
        if (!$nftoken_id && !empty($res['hash'])) {
            $tx = xrpl_rpc('tx', ['transaction' => $res['hash']]);
            if (!empty($tx['meta']['AffectedNodes'])) {
                $nftoken_id = extract_nftoken_from_affected_nodes($tx['meta']['AffectedNodes'], $__exp_uri);
            }
        }
        if (!$nftoken_id) { $entry['error'] = 'mint validated but NFTokenID not found'; $out[] = $entry; continue; }

        $entry['success']    = true;
        $entry['nftoken_id'] = $nftoken_id;
        $out[] = $entry;
    }
    return $out;
}

/**
 * Build + submit a pipelined batch of NFTokenCreateOffer txs.
 * Each $item: ['purchase_id','nftoken_id','buyer','sequence'].
 * txjson construction MIRRORS create_sell_offer() exactly (Amount 0, Destination
 * buyer, Flags 1 tfSellNFToken). offer_id extraction mirrors create_sell_offer.
 * @return array per-item: ['purchase_id','success','offer_id','hash','engine_result','error'] (input order)
 */
function create_sell_offers_batch($items) {
    // v885 (Defect AJ): same guard as create_sell_offer. Checked BEFORE building any txjson and
    // applied to the whole batch, because $by_index below maps results by POSITION -- skipping a
    // single item mid-loop would shift every subsequent index and hand buyers the wrong NFT.
    // buyer is constant per group in practice, so an all-or-nothing refusal is correct here.
    foreach ($items as $item) {
        if (($item['buyer'] ?? '') === IMC_PLATFORM_WALLET) {
            mod_log("create_sell_offers_batch: BLOCKED self-offer (Destination == Account) purchase=" . ($item['purchase_id'] ?? '?'));
            $out = [];
            foreach ($items as $it) {
                $out[] = ['purchase_id' => $it['purchase_id'], 'success' => false, 'offer_id' => null,
                          'hash' => null, 'engine_result' => null, 'error' => 'Self-offer blocked (Destination == Account)'];
            }
            return $out;
        }
    }
    $txjsons = [];
    foreach ($items as $item) {
        $txjsons[] = [
            'TransactionType' => 'NFTokenCreateOffer',
            'Account'         => IMC_PLATFORM_WALLET,
            'NFTokenID'       => $item['nftoken_id'],
            'Amount'          => '0',
            'Destination'     => $item['buyer'],
            'Flags'           => 1, // tfSellNFToken
            'Sequence'        => intval($item['sequence']),
            'SourceTag'       => 2606240013,
        ];
    }

    $batch = sign_and_submit_xrpl_batch($txjsons);
    $out = [];
    if (empty($batch['success'])) {
        foreach ($items as $item) {
            $out[] = ['purchase_id' => $item['purchase_id'], 'success' => false, 'offer_id' => null,
                      'hash' => null, 'engine_result' => null, 'error' => $batch['error'] ?? 'batch transport failed'];
        }
        return $out;
    }

    $by_index = [];
    foreach ($batch['results'] as $r) { if (isset($r['index'])) { $by_index[$r['index']] = $r; } }

    foreach ($items as $idx => $item) {
        $entry = ['purchase_id' => $item['purchase_id'], 'success' => false, 'offer_id' => null,
                  'hash' => null, 'engine_result' => null, 'error' => null];
        $res = $by_index[$idx] ?? null;
        if (!$res) { $entry['error'] = 'no batch result for index ' . $idx; $out[] = $entry; continue; }
        $entry['hash']          = $res['hash'] ?? null;
        $entry['engine_result'] = $res['engine_result'] ?? null;
        if (empty($res['success'])) { $entry['error'] = $res['error'] ?? 'offer not validated'; $out[] = $entry; continue; }

        // offer_id from CreatedNode NFTokenOffer (mirror create_sell_offer) + tx-poll fallback
        $offer_id = null;
        foreach (($res['meta']['AffectedNodes'] ?? []) as $node) {
            $created = $node['CreatedNode'] ?? null;
            if ($created && ($created['LedgerEntryType'] ?? '') === 'NFTokenOffer') { $offer_id = $created['LedgerIndex'] ?? null; break; }
        }
        if (!$offer_id && !empty($res['hash'])) {
            $tx = xrpl_rpc('tx', ['transaction' => $res['hash']]);
            foreach (($tx['meta']['AffectedNodes'] ?? []) as $node) {
                $created = $node['CreatedNode'] ?? null;
                if ($created && ($created['LedgerEntryType'] ?? '') === 'NFTokenOffer') { $offer_id = $created['LedgerIndex'] ?? null; break; }
            }
        }
        if (!$offer_id) { $entry['error'] = 'offer validated but offer_id not found'; $out[] = $entry; continue; }

        $entry['success']  = true;
        $entry['offer_id'] = $offer_id;
        $out[] = $entry;
    }
    return $out;
}


// ============================================================================
// RESERVATION CLEANUP (Task #2 + #17)
// ============================================================================

/**
 * Task #2 — Clean up expired reservations
 * Runs on every POST request. Releases reserved_count, marks groups/purchases expired.
 */
/**
 * v515: Unified claim bookkeeping -- single source of truth for marking a
 * purchase claimed. Mirrors reconcile_delivery/confirm_delivery in full so
 * EVERY claim path (site flow, dashboard auto-reconcile, reserve-time
 * reconcile, check_unclaimed) advances the group rollup identically.
 * Idempotent: claim_progress is RECOUNTED from delivered flags, so repeated
 * calls cannot double-increment.
 */
function imc_mark_purchase_claimed($purchase_id, $claim_tx_hash = null) {
    global $wpdb, $purchases_table, $groups_table;
    $now = current_time('mysql');

    $purchase = $wpdb->get_row($wpdb->prepare(
        "SELECT id, group_id FROM $purchases_table WHERE id = %d", $purchase_id
    ), ARRAY_A);
    if (!$purchase) return false;

    $p_updates = [
        'delivered'    => 1,
        'delivered_at' => $now,
        'mint_status'  => 'claimed',
        'updated_at'   => $now
    ];
    if (!empty($claim_tx_hash)) {
        $p_updates['claim_tx_hash'] = $claim_tx_hash;
    }
    $wpdb->update($purchases_table, $p_updates, ['id' => $purchase_id]);

    if (!empty($purchase['group_id'])) {
        $claimed_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $purchases_table WHERE group_id = %d AND delivered = 1",
            $purchase['group_id']
        ));
        $group = $wpdb->get_row($wpdb->prepare(
            "SELECT quantity FROM $groups_table WHERE id = %d",
            $purchase['group_id']
        ), ARRAY_A);
        $g_updates = ['claim_progress' => $claimed_count, 'updated_at' => $now];
        if ($group && intval($claimed_count) >= intval($group['quantity'])) {
            $g_updates['status'] = 'claimed';
        }
        $wpdb->update($groups_table, $g_updates, ['id' => $purchase['group_id']]);
    }
    return true;
}

/**
 * v891 (Defects V + U) — the ONE definition of "how many editions does this wallet hold
 * against this listing".
 *
 * THE BUG THIS CLOSES
 * Three places asked that question and each answered it differently:
 *   get_listing        paid, minting, minted, claimed                    (4) — what the buyer SEES
 *   reserve_purchase   + pending, failed                                 (6) — what BLOCKS them
 *   allowlist max_mint minted, paid, pending, failed                     (4) — the allowlist cap
 *
 * TWO REAL DEFECTS FELL OUT OF THAT:
 *
 * V — the UI under-reported. A buyer holding one 'failed' row was shown "1 of 3 used" and then
 *     refused at 3, with no explanation. That is exactly what affected buyers saw:
 *     told they had allocation left, told they had none.
 *
 * U — the allowlist cap omitted 'claimed' AND 'minting'. Omitting 'claimed' is a deterministic,
 *     repeatable gap: the cap could be re-entered after a claim. Both states are now counted.
 *     Omitting 'minting' lets two concurrent tabs both pass the check. The v277 comment claims
 *     this query used the "same logic as the general wallet limit" -- it never did.
 *
 * THE SET: pending, paid, minting, minted, claimed, failed — the strictest of the three, and
 * the one reserve_purchase already enforced. 'cancelled' and 'expired' are correctly excluded:
 * those are released reservations that hold nothing. 'failed' counts deliberately (v277) — a
 * failed purchase still holds its slot and should be RETRIED, not replaced by a new purchase.
 *
 * SCOPE — deliberately NOT applied to three other per-wallet counts that look similar but are
 * a different question entirely, and would break if unified:
 *   L~4876  allowlist discount ALLOCATION (joins groups on allowlist_qty, scoped-consume)
 *   L~5541  free-mint duplicate guard (excludes the just-created group)
 *   L~5551  free-mint duplicate guard, legacy branch
 * Those count "benefit consumed", not "editions held".
 *
 * Plain prepared SELECT, no transaction of its own — safe to call from inside
 * reserve_purchase's FOR UPDATE block, which is where the allowlist caller sits.
 */
function imc_wallet_holdings($listing_id, $buyer) {
    global $wpdb, $purchases_table, $groups_table;
    $listing_id = intval($listing_id);
    $buyer      = (string) $buyer;
    if ($listing_id <= 0 || $buyer === '') { return 0; }
    // 4k (5 Sep 2026): count the MINTER, not the current owner.
    // wp_imc_purchases.buyer_account is an OWNERSHIP POINTER -- the ownership listener
    // rewrites it to the new owner on every sale/transfer ("enables Step A3 + correct Access
    // tab"), reinforced by the reconciliation jobs. Correct for its purpose -- but asking it
    // "has this wallet already minted?" is bypassable: mint -> claim -> transfer out -> the row
    // stops matching, so the cap could be re-entered. Seen repeatedly for one wallet, the
    // tightest just 8s between the transfer landing and the next reservation. Platform-wide this
    // cost excess mints across several listings.
    // This is the SECOND time this idea has bitten: v891/Defect U fixed the same exploit shape by
    // correcting the STATUS filter (see the max_mint caller); the COLUMN stayed mutable, so it
    // returned through transfer instead of status change.
    // wp_imc_purchase_groups.buyer_account is IMMUTABLE: 9 write sites in this file, all INSERT;
    // the listener's only UPDATE on that table stamps payment_tx_hash alone.
    //
    // LEFT JOIN, not INNER: purchases.group_id is nullable and admin_compensate deletes groups on
    // its bare-row path -- a small number of such rows exist. INNER would silently DROP them (undercount =
    // MORE permissive). The IS NULL arm counts them, failing toward OVER-counting.
    // COLLATION: purchases.buyer_account is utf8mb4_general_ci, groups.buyer_account is
    // utf8mb4_unicode_520_ci. The two columns must NEVER meet in one expression --
    // COALESCE(g.x, p.x) = %s raises ERROR 1271, get_var() returns NULL, intval(NULL) = 0, and
    // this would count zero FOREVER on every listing: strictly worse than the bug. Both
    // comparisons below are column-vs-literal and evaluated separately.
    return intval($wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $purchases_table p
         LEFT JOIN $groups_table g ON g.id = p.group_id
          WHERE p.listing_id = %d
            AND ( g.buyer_account = %s OR (g.id IS NULL AND p.buyer_account = %s) )
            AND p.mint_status IN ('pending', 'paid', 'minting', 'minted', 'claimed', 'failed')",
        $listing_id, $buyer, $buyer
    )));
}

/**
 * v890 (Defect M) — the ONLY sanctioned way to release reserved_count.
 *
 * THE BUG THIS CLOSES
 * Thirteen separate sites decremented listings.reserved_count and NONE of them recorded
 * that a given group's slots had already been given back. A group that travels
 *     stuck -> recover_stuck_mints (releases) -> re-driven -> vps_batch_finish (releases)
 * was therefore released TWICE for a single reservation. GREATEST(0, ...) stops the column
 * going negative but not from drifting LOW, and a low reserved_count INFLATES
 * available_editions (total - minted - reserved) -- i.e. it silently oversells.
 * Seen in practice: reserved_count fell while only ONE group held a
 * reservation, and a passer-by then minted through the gap.
 *
 * Phase 2's server-side trigger made this MORE reachable: it advances a group to 'minting'
 * on dispatch, which is exactly the state recover_stuck_mints resets and releases.
 *
 * HOW IT WORKS
 * purchase_groups.reserved_released counts how many of this group's `quantity` slots have
 * already been returned. Each call releases at most (quantity - reserved_released), so a
 * second release for the same group is clamped to zero. A COUNTER, not a boolean, because
 * three callers release PARTIAL amounts (recover_stuck_mints releases the failed portion;
 * process_group and vps_batch_finish release only what actually minted) -- a group of 5 can
 * legitimately give back 2 now and 3 later.
 *
 * Wrapped in its own transaction with SELECT ... FOR UPDATE. Verified safe: all thirteen
 * call sites are OUTSIDE any open transaction (reserve_purchase's own START TRANSACTION is
 * at L4621 and COMMIT at L4651; the two release sites in that action are after the COMMIT,
 * and the stale-cleanup release runs before the transaction opens), so there is no nested-
 * transaction/implicit-commit hazard.
 *
 * @return int slots actually released (0 when already fully released).
 */
function imc_release_reservation($group_id, $listing_id, $requested_qty, $reason = '') {
    global $wpdb, $groups_table, $listings_table;

    $group_id      = intval($group_id);
    $listing_id    = intval($listing_id);
    $requested_qty = intval($requested_qty);
    if ($group_id <= 0 || $listing_id <= 0 || $requested_qty <= 0) { return 0; }

    $now = current_time('mysql');
    $wpdb->query('START TRANSACTION');

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT quantity, reserved_released FROM $groups_table WHERE id = %d FOR UPDATE",
        $group_id
    ), ARRAY_A);

    if (!$row) {
        $wpdb->query('ROLLBACK');
        mod_log("release_reservation: group=$group_id NOT FOUND — nothing released (reason=$reason)");
        return 0;
    }

    $quantity  = max(1, intval($row['quantity']));
    $released  = max(0, intval($row['reserved_released']));
    $remaining = max(0, $quantity - $released);
    $actual    = min($requested_qty, $remaining);

    if ($actual <= 0) {
        $wpdb->query('ROLLBACK');
        // THIS LINE IS THE POINT OF THE FIX: every appearance is a double-release that would
        // previously have silently drifted the counter and oversold the listing.
        mod_log("release_reservation: BLOCKED double-release group=$group_id listing=$listing_id "
              . "requested=$requested_qty already_released=$released quantity=$quantity (reason=$reason)");
        return 0;
    }

    $wpdb->query($wpdb->prepare(
        "UPDATE $groups_table SET reserved_released = reserved_released + %d, updated_at = %s WHERE id = %d",
        $actual, $now, $group_id
    ));
    $wpdb->query($wpdb->prepare(
        "UPDATE $listings_table SET reserved_count = GREATEST(0, reserved_count - %d), updated_at = %s WHERE id = %d",
        $actual, $now, $listing_id
    ));
    $wpdb->query('COMMIT');

    if ($actual < $requested_qty) {
        mod_log("release_reservation: CLAMPED group=$group_id listing=$listing_id "
              . "requested=$requested_qty released=$actual (already_released=$released quantity=$quantity, reason=$reason)");
    } else {
        mod_log("release_reservation: group=$group_id listing=$listing_id released=$actual (reason=$reason)");
    }
    return $actual;
}

function cleanup_expired_reservations() {
    global $wpdb, $groups_table, $purchases_table, $listings_table;

    $now = current_time('mysql');

    // ── PASS 1: Expired 'reserved' groups (never paid — standard path) ──
    // M1: bounded and oldest-first. Every sibling net already carries a LIMIT (reconcile 100,
    // redrive 25, the PP sweep 50); this one was unbounded, and each row below costs up to two
    // outbound HTTPS calls (poll_xumm + cancel_xumm_payload). A backlog drains across runs.
    $expired = $wpdb->get_results($wpdb->prepare(
        "SELECT id, listing_id, quantity, payment_xumm_uuid, buyer_account, total_price_xrp, payment_currency, payment_amount, payment_currency_issuer, payment_tx_hash, payment_verified FROM $groups_table
         WHERE status = 'reserved' AND reservation_expires_at < %s
         ORDER BY reservation_expires_at ASC
         LIMIT 50",
        $now
    ), ARRAY_A);

    foreach ($expired as $group) {
        // v605: SELF-HEAL BEFORE CANCEL -- never cancel a hold the buyer actually paid.
        // The Xaman uuid is stored at reservation time, so poll it; if signed, verify the tx
        // ON-CHAIN against the ARTIST (payments go direct to artists) for the expected amount.
        // If it checks out, promote the group to the canonical 'paid' state -- byte-identical to
        // process_group -- and SKIP the cancel. No mint/loopback here: delivery is out-of-band
        // (retry cron + dashboard retry), keeping this per-POST path fast and recursion-free.
        $sh_amount = floatval($group['payment_amount'] ?? 0);
        if ($sh_amount <= 0) { $sh_amount = floatval($group['total_price_xrp']); }
        if (!empty($group['payment_xumm_uuid']) && $sh_amount > 0) {
            $sh_pl = imc_xumm_lookup($group['payment_xumm_uuid']);   // M7a-2: DB/terminal-cache first, XUMM only if needed
            if (is_array($sh_pl) && !empty($sh_pl['meta']['signed']) && !empty($sh_pl['response']['txid'])) {
                $sh_txid   = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $sh_pl['response']['txid']));
                $sh_artist = $wpdb->get_var($wpdb->prepare(
                    "SELECT artist_account FROM $listings_table WHERE id = %d", $group['listing_id']
                ));
                if (strlen($sh_txid) === 64 && $sh_artist) {
                    // v606: verify against the ACTUAL payment currency/amount (mirrors process_group).
                    $sh_expected = ['currency' => ($group['payment_currency'] ?? 'XRP'), 'value' => $sh_amount];
                    if (!empty($group['payment_currency_issuer'])) { $sh_expected['issuer'] = $group['payment_currency_issuer']; }
                    $sh_vr = verify_payment_multicurrency($sh_txid, $sh_artist, $sh_expected);
                    if (!empty($sh_vr['verified'])) {
                        imc_record_received(intval($group['id']), $sh_vr);   // M8-a
                        // Promote to canonical 'paid' (identical to process_group) -- do NOT cancel.
                        $wpdb->update($groups_table, [
                            'payment_tx_hash'     => $sh_txid,
                            'payment_verified'    => 1,
                            'payment_verified_at' => $now,
                            'status'              => 'paid',
                            'updated_at'          => $now,
                        ], ['id' => $group['id']]);
                        $wpdb->query($wpdb->prepare(
                            "UPDATE $purchases_table SET mint_status = 'paid', payment_tx_hash = %s,
                             payment_verified = 1, payment_verified_at = %s, updated_at = %s
                             WHERE group_id = %d AND mint_status = 'pending'",
                            $sh_txid, $now, $now, $group['id']
                        ));
                        mod_log("cleanup SELF-HEAL: group={$group['id']} was PAID on-chain (tx=$sh_txid -> artist=$sh_artist) -- promoted to 'paid', NOT cancelled (delivery via retry cron / dashboard)");
                        continue; // never cancel a paid hold
                    } else {
                        mod_log("cleanup: group={$group['id']} payload signed but payment NOT verified (" . ($sh_vr['error'] ?? 'unknown') . ") -- proceeding to cancel");
                    }
                }
            }
        }

        // v689 (Layer 2b): the same protection for Joey -- additive and self-contained.
        //
        // The v605 guard above can only ask XUMM whether a payload was signed, so a Joey
        // reservation -- which never has a payload -- falls straight through to the cancel
        // below even when the buyer HAS paid. reconcile_expired_paid() does recover it three
        // minutes later, but only by RE-HOLDING an edition, and that re-hold FAILS if the
        // listing sold out in the interim, turning a delivery into a refund. Promoting here
        // instead means the slot is never released at all, so the buyer is guaranteed the NFT.
        //
        // This is CHEAPER than the Xaman guard above, not more expensive: that one must poll
        // XUMM for EVERY expiring reservation just to find out whether it was paid. This one
        // reads a column first -- no recorded hash means the buyer never signed, skip
        // instantly -- so only groups that actually signed ever reach the on-chain verify.
        //
        // Deliberately a separate block rather than a shared fork, so the proven v605 Xaman
        // path above stays byte-identical.
        if (empty($group['payment_xumm_uuid'])
            && !empty($group['payment_tx_hash'])
            && intval($group['payment_verified']) === 0
            && $sh_amount > 0) {

            $j_txid = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $group['payment_tx_hash']));
            if (strlen($j_txid) === 64) {

                // One-hash-one-claim -- mirrors the v242 replay predicate, self-exclusion included.
                $j_taken = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $groups_table
                      WHERE payment_tx_hash = %s
                        AND id != %d
                        AND status NOT IN ('expired', 'cancelled', 'failed')
                      LIMIT 1",
                    $j_txid, $group['id']
                ));

                if ($j_taken) {
                    mod_log("cleanup (joey): group={$group['id']} CLAIM BLOCKED -- tx=$j_txid already used by group=" . intval($j_taken) . " -- proceeding to cancel");
                    imc_flag_attention(intval($group['id']), 'claim-tx-reuse');   // M8-a
                } else {
                    $j_artist = $wpdb->get_var($wpdb->prepare(
                        "SELECT artist_account FROM $listings_table WHERE id = %d", $group['listing_id']
                    ));
                    if ($j_artist) {
                        // Verify against the ACTUAL payment currency/amount (mirrors v606 above).
                        $j_expected = ['currency' => ($group['payment_currency'] ?? 'XRP'), 'value' => $sh_amount];
                        if (!empty($group['payment_currency_issuer'])) { $j_expected['issuer'] = $group['payment_currency_issuer']; }
                        $j_vr = verify_payment_multicurrency($j_txid, $j_artist, $j_expected);
                        if (!empty($j_vr['verified'])) {
                            imc_record_received(intval($group['id']), $j_vr);   // M8-a
                            // Promote to canonical 'paid' -- identical writes to the v605 guard.
                            //
                            // NOTE the purchases WHERE clause is mint_status = 'pending', NOT
                            // 'cancelled'. At THIS point the cancel below has not run yet, so the
                            // rows are still 'pending'. reconcile_expired_paid() uses 'cancelled'
                            // because by the time it sees the group the cancel has already happened.
                            // Copying that clause here would match zero rows and leave the group
                            // 'paid' with its purchases stranded at 'pending'.
                            $wpdb->update($groups_table, [
                                'payment_tx_hash'     => $j_txid,
                                'payment_verified'    => 1,
                                'payment_verified_at' => $now,
                                'status'              => 'paid',
                                'updated_at'          => $now,
                            ], ['id' => $group['id']]);
                            $wpdb->query($wpdb->prepare(
                                "UPDATE $purchases_table SET mint_status = 'paid', payment_tx_hash = %s,
                                 payment_verified = 1, payment_verified_at = %s, updated_at = %s
                                 WHERE group_id = %d AND mint_status = 'pending'",
                                $j_txid, $now, $now, $group['id']
                            ));
                            mod_log("cleanup SELF-HEAL (joey): group={$group['id']} was PAID on-chain (tx=$j_txid -> artist=$j_artist) -- promoted to 'paid', slot NEVER released, NOT cancelled");
                            continue; // never cancel a paid hold
                        } else {
                            mod_log("cleanup (joey): group={$group['id']} has recorded tx=$j_txid but payment NOT verified (" . ($j_vr['error'] ?? 'unknown') . ") -- proceeding to cancel; reconcile_expired_paid will retry within 3 minutes");
                        }
                    }
                }
            }
        }
        // M1 (hunk G): CLAIM the cancel before acting on it. Two overlapping runs (a request-
        // triggered run and the WP-Cron, or two POSTs in the same instant) can both select this
        // group; if the buyer signs between their polls, one run promotes it to 'paid' while the
        // other -- still holding a stale 'unsigned' verdict -- would release the hold and overwrite
        // 'paid' with 'expired', stranding a verified buyer that no net selects. The WHERE clause
        // makes that structurally impossible: exactly one run wins, and it is the one that acts.
        // Same shape as the v285 free-mint claim (UPDATE ... WHERE id AND status = 'paid').
        // The release moves BEHIND the gate so a losing run releases nothing.
        $imc_cas = $wpdb->query($wpdb->prepare(
            "UPDATE $groups_table SET status = 'expired', updated_at = %s WHERE id = %d AND status = 'reserved'",
            $now, $group['id']
        ));
        if ((int)$imc_cas !== 1) {
            mod_log("cleanup: group={$group['id']} left 'reserved' concurrently (promoted or cancelled by another run) -- NOT cancelling, NOT releasing");
            continue;
        }
        imc_release_reservation($group['id'], $group['listing_id'], $group['quantity'], 'cleanup-pass1-expired');
        $wpdb->query($wpdb->prepare(
            "UPDATE $purchases_table SET mint_status = 'cancelled', updated_at = %s
             WHERE group_id = %d AND mint_status IN ('pending', 'reserved')",
            $now, $group['id']
        ));
        mod_log("cleanup: expired group_id={$group['id']} listing={$group['listing_id']} qty={$group['quantity']}");

        // v515: cancel the Xaman payload so the payment request dies with the
        // reservation. Previously payloads stayed signable for ~30h after expiry
        // (the orphan-payment window). HTTP call is safe here: cleanup runs outside
        // any DB transaction (same rationale as the v293 stale-payload placement).
        if (!empty($group['payment_xumm_uuid'])) {
            $pl_cancelled = cancel_xumm_payload($group['payment_xumm_uuid']);
            mod_log("cleanup: payload {$group['payment_xumm_uuid']} for expired group {$group['id']} -- " .
                    ($pl_cancelled ? 'cancelled' : 'already resolved/expired'));
        }
    }

    // ── PASS 2 (v277): Stale 'paid' groups — buyer paid but never triggered minting ──
    // These hold reserved_count indefinitely, blocking new buyers.
    // A paid group older than 2 hours with no minting progress is safe to release.
    //
    // * M2 (1 Sep 2026) -- THE SWEEP RULE. A VERIFIED PAYMENT IS NEVER RELEASED BY A CLOCK.
    // This pass used to sweep buyers whose payments had already succeeded
    // on-chain, purely because their queue wait exceeded two hours. Since payments go
    // direct to the artist, the predicate below now
    // excludes payment_verified = 1 (written as `= 0`, so a NULL is HELD, never released).
    //
    // READ THIS BEFORE "SIMPLIFYING": every writer of status='paid' also sets
    // payment_verified=1 (eight sites, verified), and nothing ever writes payment_verified=0
    // (monotonic by construction). So this pass is now UNREACHABLE for live data -- BOTH the
    // 2h leg AND the v641 7-day leg (re-held groups are verified too). It is deliberately
    // kept, not deleted: it is the guard for any future writer that sets 'paid' without
    // verifying, and its body is the proven release+fail+v889 sequence that M2b's
    // evidence-gated operator release reuses. A genuinely dead paid group (issuer gone,
    // listing closed) is released by M2b on EVIDENCE, never by age. Pre-flight on live data
    // before this landed: zero rows matched the new predicate. See the M2 pre-audit.
    $stale_cutoff  = date('Y-m-d H:i:s', strtotime(current_time('mysql') . ' -2 hours'));
    // v641: reconcile re-held groups (paid-after-expiry, buyer absent) get a longer
    // protected window so a genuine buyer can still retry; everything else keeps 2h.
    // IF(NULL,..) returns the ELSE branch, so non-reconcile paid groups are unaffected.
    $rehold_cutoff = date('Y-m-d H:i:s', strtotime(current_time('mysql') . ' -7 days'));
    $stale_paid = $wpdb->get_results($wpdb->prepare(
        "SELECT id, listing_id, quantity FROM $groups_table
         WHERE status = 'paid' AND mint_progress = 0
           AND payment_verified = 0
           AND updated_at < IF(error_message LIKE %s, %s, %s)",
        '%v641 reconcile re-held%', $rehold_cutoff, $stale_cutoff
    ), ARRAY_A);

    foreach ($stale_paid as $group) {
        imc_release_reservation($group['id'], $group['listing_id'], $group['quantity'], 'cleanup-pass2-stale-paid');
        $wpdb->update($groups_table, [
            'status'        => 'failed',
            'error_message' => 'Stale paid group — minting never triggered (slot released after 2h)',
            'updated_at'    => $now
        ], ['id' => $group['id']]);

        // v889 (Defect F): Pass 1 updates the purchase rows when it expires a group
        // (mint_status -> 'cancelled', see above); Pass 2 never did. The group was left
        // 'failed' while its purchases stayed 'paid', and 'paid' is counted by the
        // per-wallet limit in reserve_purchase -- so a buyer swept here was told
        // "you've reached the mint limit" for an NFT they never received, with no retry
        // button (the dashboard only renders one for 'failed'). Buyers swept from
        // an affected listing were left in exactly that state.
        //
        // 'failed' rather than 'cancelled' on purpose: 'cancelled' implies the buyer backed
        // out, and it hides the row from the retry affordance. 'failed' is accurate, matches
        // the group status set above, and (with the v889 dashboard change) surfaces a Retry
        // button that re-drives THIS group rather than starting a new purchase.
        //
        // The nftoken_id guard is defensive: Pass 2 only selects groups with
        // mint_progress = 0, so nothing should have minted -- but a row that somehow holds
        // an on-chain NFT must never be relabelled 'failed' and lose its recovery path.
        $imc_pf = $wpdb->query($wpdb->prepare(
            "UPDATE $purchases_table
                SET mint_status = 'failed',
                    error_message = 'Reservation released after 2h — minting was never triggered. You can retry from your purchase history.',
                    updated_at = %s
              WHERE group_id = %d
                AND mint_status IN ('pending', 'paid', 'minting')
                AND (nftoken_id IS NULL OR nftoken_id = '')",
            $now, $group['id']
        ));
        mod_log("cleanup: released stale paid group_id={$group['id']} listing={$group['listing_id']} qty={$group['quantity']} purchases_marked_failed=" . intval($imc_pf));
    }

    // ── PASS 3 (v277): Failed groups still holding reserved_count ──
    // ⚠ v897 (Defect P): the ORIGINAL comment here said "recover_stuck_mints() marks
    // groups 'failed'". IT DOES NOT — it sets 'paid' (L2758), so those groups are
    // caught by PASS 2, not this one. Verifying that false premise could easily lead a
    // future reader to conclude Pass 3 is dead code and delete it. IT IS NOT.
    //
    // Pass 3 is the ONLY reclaimer for groups that reach 'failed' while STILL HOLDING a
    // reservation. Eight sites do that: reserve_purchase L5921 (XUMM payload failure),
    // process_group L6077/L6184/L6233, vps_batch_start L7411/L7430, and the terminal
    // states written by admin_compensate and creator_mint. Pass 1 selects 'reserved'
    // and Pass 2 selects 'paid' — so without this, those slots lock FOREVER.
    //
    // Defect N made this MORE load-bearing, not less: recover_stuck_mints now KEEPS the
    // hold on total failure, so the reclaimers of last resort matter more than before.
    //
    // mint_progress = 0 keeps anything partially minted out of scope; the EXISTS guard
    // on reserved_count > 0 means it only fires where there is something to release.
    // F3 (Phase 1, 2 Sep 2026): `payment_verified = 0` added below, mirroring Pass 2 (M2a). A VERIFIED
    // buyer's hold is never released by a clock -- only by M2b on evidence. Pass 3 lacked the guard, so a
    // verified group that failed and was not re-driven within 24h lost its slot to a passer-by.
    $failed_cutoff = date('Y-m-d H:i:s', strtotime(current_time('mysql') . ' -24 hours'));
    $stale_failed = $wpdb->get_results($wpdb->prepare(
        "SELECT g.id, g.listing_id, g.quantity
         FROM $groups_table g
         WHERE g.status = 'failed'
           AND g.mint_progress = 0
           AND g.payment_verified = 0
           AND g.updated_at < %s
           AND EXISTS (
               SELECT 1 FROM $listings_table l
               WHERE l.id = g.listing_id AND l.reserved_count > 0
           )",
        $failed_cutoff
    ), ARRAY_A);

    foreach ($stale_failed as $group) {
        imc_release_reservation($group['id'], $group['listing_id'], $group['quantity'], 'cleanup-pass3-stale-failed');
        mod_log("cleanup: released stale failed group_id={$group['id']} reserved_count slot released");
    }

    // PP-1 (R13): refresh materialized display prices for progressive listings. ONE
    // choke point instead of a call after each of the ~7 scattered payment_verified=1
    // writes (self-heal, joey guard, reconcile x2, process_group, free mint, compensate)
    // -- this runs on every POST and on the 5-minute cron, so display lag is at most one
    // request, and the confirm gate in reserve_purchase covers the residue. Bounded:
    // progressive listings are the only rows selected.
    if (IMC_PROGRESSIVE_PRICING) {
        $pp_active = $wpdb->get_col(
            "SELECT id FROM $listings_table
              WHERE status = 'active' AND progressive_json IS NOT NULL AND progressive_json != ''
              LIMIT 50"
        );
        foreach ($pp_active as $pp_lid) { imc_pp_materialize(intval($pp_lid)); }
    }
}

/**
 * Task #17 — Recovery job for stuck 'minting' status
 * If a group has been in 'minting' for >10 minutes, something went wrong.
 */
function recover_stuck_mints() {
    global $wpdb, $groups_table, $purchases_table, $listings_table;

    $now = current_time('mysql');

    // v389: Quantity-aware timeout — large batches get proportionally more time.
    // Base: 10 minutes for qty 1-5. Scale: 2 minutes per NFT beyond that.
    // Examples: qty=1 → 10min, qty=5 → 10min, qty=10 → 20min, qty=20 → 40min.
    // Fetch quantity alongside id so we can compute per-group cutoffs.
    $stuck = $wpdb->get_results(
        "SELECT id, quantity, listing_id, updated_at, payment_tx_hash FROM $groups_table
         WHERE status = 'minting'",
        ARRAY_A
    );

    // Filter to truly stuck groups (updated_at older than their quantity-scaled timeout)
    $timed_out = [];
    foreach ($stuck as $group) {
        $qty = max(1, intval($group['quantity']));
        $timeout_minutes = max(10, $qty * 2);
        $cutoff = date('Y-m-d H:i:s', strtotime($now . " -$timeout_minutes minutes"));
        if ($group['updated_at'] < $cutoff) {
            $timed_out[] = $group;
        }
    }

    foreach ($timed_out as $group) {
        // v277: Count how many purchases actually completed minting before the timeout
        $minted_in_group = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $purchases_table WHERE group_id = %d AND mint_status = 'minted'",
            $group['id']
        )));

        // v429: Set to 'paid' (retryable) instead of 'failed' (dead end).
        // Users can retry without manual intervention.
        $wpdb->update($groups_table, [
            'status'        => 'paid',
            'needs_attention'  => 1,                    // M8-a: the healer records that a heal happened
            'attention_reason' => 'auto-recovered',
            'error_message' => 'Auto-recovered from stuck minting — please retry',
            'updated_at'    => $now
        ], ['id' => $group['id']]);

        $wpdb->query($wpdb->prepare(
            "UPDATE $purchases_table SET mint_status = 'paid', error_message = 'Auto-recovered — retry available', updated_at = %s
             WHERE group_id = %d AND mint_status IN ('pending', 'paid', 'minting')",
            $now, $group['id']
        ));

        // v277: Release reserved_count for the portions that failed (not for minted ones —
        // those are already released in the process_group post-mint section).
        $failed_qty = intval($group['quantity']) - $minted_in_group;
        if ($failed_qty > 0) {
            // v891 (Defect N): process_group already states the policy for this exact situation
            // — "Total failure: keep reserved_count so these editions aren't sold to someone
            // else". recover_stuck_mints contradicted it by handing the slots back after ten
            // minutes, which re-opens the window a stranger walked through to take an edition.
            // Since Phase 2 the group is re-driven within MINUTES (free mints by
            // redrive-free-mints, paid mints by retry-failed-mints Pass B), so releasing at ten
            // minutes is now clearly wrong: the buyer who paid should outrank a passer-by.
            //
            // ⚠ TOTAL FAILURE ONLY. Both cleanup passes require mint_progress = 0 (Pass 2 also
            // needs status='paid', Pass 3 status='failed'), and recover_stuck_mints does NOT
            // reset mint_progress. So a PARTIAL group recovered to 'paid' with mint_progress > 0
            // matches NEITHER pass — if this site stopped releasing for it, those slots would be
            // held forever with nothing left to reclaim them. Partial keeps today's behaviour.
            if ($minted_in_group === 0) {
                mod_log("recovery: KEEPING reserved_count=$failed_qty for group={$group['id']} "
                      . "(total failure, buyer paid — re-driver runs within minutes; held until delivered or released on evidence by M2b, never by age, per M2)");
            } else {
                // Phase 2 (2 Sep 2026): a FREE partial group is now re-driven by redrive-free-mints within
                // minutes (vps_redrive_free), so releasing its failed portion here is premature -- keep the
                // hold, exactly as the total-failure branch above does. PAID partials keep v891's release:
                // retry-failed-mints excludes partial groups (HAVING minted_count=0), so nothing else
                // would reclaim those slots.
                if (($group['payment_tx_hash'] ?? '') === 'FREE_MINT') {
                    mod_log("recovery: KEEPING reserved_count=$failed_qty for FREE partial group={$group['id']} (re-driven by redrive-free-mints)");
                } else {
                    imc_release_reservation($group['id'], $group['listing_id'], $failed_qty, 'recover-stuck-mints-partial');
                }
            }
        }

        mod_log("recovery: reset stuck group_id={$group['id']} to paid (minted_in_group=$minted_in_group)");
    }

    // v429: Clear stale lock file if any groups were recovered
    if (!empty($timed_out)) {
        $lock_file = __DIR__ . "/logs/xrpl_lock_imc_mint.lock";
        if (file_exists($lock_file)) {
            $lock_age = time() - @filemtime($lock_file);
            if ($lock_age > 300) {
                @unlink($lock_file);
                mod_log("recovery: cleared stale lock file (age={$lock_age}s)");
            }
        }
    }
}

// ── WP Cron: run cleanup every 5 minutes regardless of user traffic (v23 FIX) ──────
// Prevents reservations staying stuck on sold-out/low-traffic listings where
// nobody is minting (and therefore no POST requests trigger the cleanup below).
if (!wp_next_scheduled('imc_cleanup_expired_reservations')) {
    wp_schedule_event(time(), 'imc_five_minutes', 'imc_cleanup_expired_reservations');
}
if (!has_action('imc_cleanup_expired_reservations', 'cleanup_expired_reservations')) {
    add_action('imc_cleanup_expired_reservations', 'cleanup_expired_reservations');
    add_action('imc_cleanup_expired_reservations', 'recover_stuck_mints');
    add_action('imc_cleanup_expired_reservations', 'reconcile_expired_paid'); // v640 additive backstop
}

/**
 * v640 RECONCILE (additive safety net): catch expired groups that were actually PAID.
 *
 * The reserve_purchase signed-guard (v640) prevents voiding a signed payload at source;
 * this is the backstop for anything that still slips through (e.g. a poll that momentarily
 * missed a signature). For recently-expired, not-yet-verified groups it re-checks the
 * payment ON-CHAIN using the SAME proven poll -> verify_payment_multicurrency path as the
 * self-heal, and when a payment is confirmed it FLAGS the group for delivery/refund.
 *
 * Deliberately does NOT mint and does NOT move funds: minting is request-coupled and
 * lock-guarded, so delivery/refund stays on the existing proven path. Purely additive,
 * idempotent (payment_verified=1 excludes it next run), bounded (recent window + LIMIT).
 * Touches ONLY status='expired' groups, which no other pass reads.
 */
function reconcile_expired_paid() {
    global $wpdb;
    $groups_table    = $wpdb->prefix . 'imc_purchase_groups';
    $listings_table  = $wpdb->prefix . 'imc_listings';
    $purchases_table = $wpdb->prefix . 'imc_purchases';
    $now    = current_time('mysql');
    $cutoff = date('Y-m-d H:i:s', strtotime($now . ' -72 hours'));

    $candidates = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $groups_table
          WHERE status = 'expired' AND payment_verified = 0
            AND (
                  (payment_xumm_uuid IS NOT NULL AND payment_xumm_uuid != '')
               OR (payment_tx_hash   IS NOT NULL AND payment_tx_hash   != '' AND payment_tx_hash != 'FREE_MINT')
                )
            AND updated_at > %s
          ORDER BY updated_at DESC LIMIT 100",
        $cutoff
    ), ARRAY_A);
    if (empty($candidates)) { return; }

    foreach ($candidates as $group) {
        $amount = floatval($group['payment_amount'] ?? 0);
        if ($amount <= 0) { $amount = floatval($group['total_price_xrp']); }
        if ($amount <= 0) { continue; }

        // v688 (Layer 2): the payment reference now comes from one of two sources, and
        // both converge on $txid before the IDENTICAL on-chain verification below.
        //
        //   XAMAN -- unchanged: poll the stored payload for the signed txid.
        //   JOEY  -- no XUMM payload exists, so use the hash recorded by record_joey_tx
        //            (v687) at signing time. That hash is client-supplied and UNVERIFIED,
        //            so it is never trusted on its own: verify_payment_multicurrency()
        //            below still checks destination, currency and amount on-chain exactly
        //            as it does for Xaman.
        //
        // Everything after this fork is wallet-agnostic and byte-identical to v687.
        if (!empty($group['payment_xumm_uuid'])) {
            // Same proven detection as the v605 self-heal (poll the stored payload).
            $pl = imc_xumm_lookup($group['payment_xumm_uuid']);   // M7a-2: DB/terminal-cache first, XUMM only if needed
            if (is_array($pl) && !empty($pl['meta']['signed']) && !empty($pl['response']['txid'])) {
                $txid = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $pl['response']['txid']));
            } elseif (!empty($group['payment_tx_hash'])) {
                // v690: the payload was never signed, but a hash IS recorded against this
                // group -- the buyer paid by some other route. Before this, such a group was
                // invisible to EVERY layer: this branch skipped on the unsigned poll and never
                // looked at the hash, while Layer 3 excluded the group twice over (for having
                // a uuid AND for having a hash). Buyers could sit stranded that way --
                // long-abandoned groups from earlier in the year.
                //
                // The hash is NOT trusted here: verify_payment_multicurrency() below still
                // proves destination, currency and amount on-chain, exactly as for a signed
                // payload.
                $txid = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $group['payment_tx_hash']));

                // One-hash-one-claim, matching the Joey branch. Scoped to this new path only
                // so the proven Xaman-signed route above stays byte-identical.
                if (strlen($txid) === 64) {
                    $x_taken = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM $groups_table
                          WHERE payment_tx_hash = %s
                            AND id != %d
                            AND status NOT IN ('expired', 'cancelled', 'failed')
                          LIMIT 1",
                        $txid, $group['id']
                    ));
                    if ($x_taken) {
                        mod_log("reconcile: XAMAN-FALLBACK CLAIM BLOCKED -- tx=$txid already used by group=" . intval($x_taken) . " (requested by group={$group['id']})");
                    imc_flag_attention(intval($group['id']), 'claim-tx-reuse');   // M8-a
                        continue;
                    }
                }
                mod_log("reconcile: xaman payload unsigned for group={$group['id']} but recorded tx=$txid present -- verifying that on-chain instead");
            } else {
                continue;
            }
        } else {
            // JOEY: hash recorded at signing time by record_joey_tx (v687).
            $txid = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $group['payment_tx_hash'] ?? ''));

            // One-hash-one-claim -- the third independent guard, after record_joey_tx's
            // write-time uniqueness check and process_group's v242 replay check. A single
            // payment must never satisfy two purchases. Predicate mirrors v242 exactly,
            // including the self-exclusion.
            if (strlen($txid) === 64) {
                $rc_taken = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $groups_table
                      WHERE payment_tx_hash = %s
                        AND id != %d
                        AND status NOT IN ('expired', 'cancelled', 'failed')
                      LIMIT 1",
                    $txid, $group['id']
                ));
                if ($rc_taken) {
                    mod_log("reconcile: JOEY CLAIM BLOCKED -- tx=$txid already used by group=" . intval($rc_taken) . " (requested by group={$group['id']})");
                    imc_flag_attention(intval($group['id']), 'claim-tx-reuse');   // M8-a
                    continue;
                }
            }
        }
        if (strlen($txid) !== 64) { continue; }

        $artist = $wpdb->get_var($wpdb->prepare(
            "SELECT artist_account FROM $listings_table WHERE id = %d", $group['listing_id']
        ));
        if (!$artist) { continue; }

        // Verify the actual payment currency/amount ON-CHAIN (mirrors self-heal / process_group).
        $expected = ['currency' => ($group['payment_currency'] ?? 'XRP'), 'value' => $amount];
        if (!empty($group['payment_currency_issuer'])) { $expected['issuer'] = $group['payment_currency_issuer']; }
        $vr = verify_payment_multicurrency($txid, $artist, $expected);
        if (empty($vr['verified'])) { continue; }
        imc_record_received(intval($group['id']), $vr);   // M8-a

        // Confirmed paid after expiry. Try to RE-HOLD an edition so the buyer can retry the
        // mint via the proven dashboard/process_group path; if none is free (sold out / mint
        // closed / listing inactive), fall back to flagging for refund. This NEVER mints and
        // NEVER moves funds -- the re-hold write is identical to reserve_purchase's.
        $qty = max(1, intval($group['quantity']));

        // Short lock-scoped re-hold. All slow work (poll/verify) is already done ABOVE, outside
        // this transaction, so the row lock is held only for the tiny inventory write.
        $rehold_ok = false;
        $wpdb->query('START TRANSACTION');
        $lrow = $wpdb->get_row($wpdb->prepare(
            "SELECT id, minted_count, reserved_count, total_editions, edition_type, open_edition_closed_at
             FROM $listings_table WHERE id = %d AND status = 'active' FOR UPDATE",
            $group['listing_id']
        ), ARRAY_A);
        if ($lrow) {
            if ((($lrow['edition_type'] ?? 'fixed') === 'open')) {
                $rehold_ok = empty($lrow['open_edition_closed_at']);            // open: ok unless window closed
            } else {
                $avail = intval($lrow['total_editions']) - intval($lrow['minted_count']) - intval($lrow['reserved_count']);
                $rehold_ok = ($avail >= $qty);                                  // fixed: hard supply cap
            }
        }

        if ($rehold_ok) {
            // Re-hold the slot (identical write to reserve_purchase) and promote to 'paid' so the
            // dashboard retry-mint flow delivers it via process_group (mint path unchanged).
            $wpdb->query($wpdb->prepare(
                "UPDATE $listings_table SET reserved_count = reserved_count + %d, updated_at = %s WHERE id = %d",
                $qty, $now, $group['listing_id']
            ));
            // v890 (Defect M): this RE-HOLDS a slot that was previously released, so the
            // group's release budget must be given back too — otherwise the eventual
            // legitimate release is clamped to zero and reserved_count never comes down.
            // GREATEST(0, ...) so a double re-hold can never drive the counter negative.
            $wpdb->query($wpdb->prepare(
                "UPDATE $groups_table SET reserved_released = GREATEST(0, reserved_released - %d) WHERE id = %d",
                $qty, $group['id']
            ));
            $held_note = 'v641 reconcile re-held -- paid on-chain after expiry (tx=' . $txid . '), slot re-held, awaiting buyer retry';
            $wpdb->update($groups_table, array(
                'payment_tx_hash'     => $txid,
                'payment_verified'    => 1,
                'payment_verified_at' => $now,
                'status'              => 'paid',
                'error_message'       => $held_note,
                'updated_at'          => $now,
            ), array('id' => $group['id']));
            $wpdb->query($wpdb->prepare(
                "UPDATE $purchases_table SET mint_status = 'paid', payment_tx_hash = %s,
                 payment_verified = 1, payment_verified_at = %s, error_message = %s, updated_at = %s
                  WHERE group_id = %d AND mint_status = 'cancelled'",
                $txid, $now, $held_note, $now, $group['id']
            ));
            $wpdb->query('COMMIT');
            mod_log("reconcile: RE-HELD + PAID group={$group['id']} listing={$group['listing_id']} qty=$qty tx=$txid -- promoted to 'paid', slot re-held, buyer can retry mint (7-day hold)");
        } else {
            $wpdb->query('ROLLBACK');
            // Sold out / mint closed / listing inactive -> cannot deliver: flag for refund.
            // status stays 'expired'; payment_verified=1 makes this idempotent next run.
            $refund_note = 'v640 reconcile: paid on-chain after expiry (tx=' . $txid . ') -- sold out/closed, needs refund';
            $wpdb->update($groups_table, array(
                'payment_tx_hash'     => $txid,
                'payment_verified'    => 1,
                'payment_verified_at' => $now,
                'error_message'       => $refund_note,
                'updated_at'          => $now,
            ), array('id' => $group['id']));
            $wpdb->query($wpdb->prepare(
                "UPDATE $purchases_table SET error_message = %s, updated_at = %s
                  WHERE group_id = %d AND mint_status = 'cancelled'",
                $refund_note, $now, $group['id']
            ));
            mod_log("reconcile: EXPIRED-BUT-PAID group={$group['id']} listing={$group['listing_id']} tx=$txid amount=$amount -- SOLD OUT/CLOSED, flagged for refund (NOT minted)");
        }
    }
}


// M1: request-time recovery trigger -- THROTTLED and POST-ONLY.
//   * The */3 promote cron (run_reconcile) and the 5-minute WP-Cron call these functions
//     DIRECTLY and are not affected here, so the self-heal floor is unchanged at 3 minutes.
//   * Pre-M1 this ran on EVERY POST and ~10% of GETs, so its cost scaled with poll traffic
//     (Audit 6.1). Now it runs at most once per IMC_M1_TRIGGER_SEC across ALL requests.
//   * run_reconcile is excluded: it calls the same functions itself (the log showed the
//     pp_materialize lines twice per cron tick), and it touches the marker when done.
//   * $action is not assigned until later in this file -- read the request directly here,
//     in the same precedence order that assignment uses.
//   * If the marker cannot be written the trigger degrades to the pre-M1 POST cadence --
//     never worse than today.
if (!defined('IMC_M1_TRIGGER_SEC')) define('IMC_M1_TRIGGER_SEC', 20);   // wp-config.php may override; 0 = every POST (legacy cadence); GET path is retired
$imc_m1_marker = __DIR__ . '/logs/recovery_last_run.marker';
$imc_m1_due    = (IMC_M1_TRIGGER_SEC <= 0) || !file_exists($imc_m1_marker)
              || (time() - (int)@filemtime($imc_m1_marker)) >= IMC_M1_TRIGGER_SEC;
$imc_m1_action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $imc_m1_due && $imc_m1_action !== 'run_reconcile') {
    @touch($imc_m1_marker);            // claim the window BEFORE the work so concurrent POSTs see it fresh
    cleanup_expired_reservations();
    recover_stuck_mints();
}


// ============================================================================
// GET ENDPOINTS
// ============================================================================

// Item-1 P7: library mode — when a CLI harness defines IMC_MOD_LIB_ONLY before including
// this file, stop here (functions defined, router skipped). Web requests are unaffected.
if (defined('IMC_MOD_LIB_ONLY')) { return; }

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ============================================================================
// IDENTITY for the private GET actions. Resolved once here from the proven
// wallet session and never taken from the request.
//
// Computed unconditionally - it only reads a cookie - but enforced per-action:
// the admin_* and vps_* actions authenticate with IMC_COMP_ADMIN_KEY, and the
// public drop and collection reads must keep working for logged-out visitors.
// ============================================================================
$imc_mod_auth = function_exists('imc_session_require_wallet')
    ? imc_session_require_wallet()
    : array('ok' => false, 'wallet' => '', 'error' => 'auth');
$imc_mod_wallet = !empty($imc_mod_auth['ok']) ? (string) $imc_mod_auth['wallet'] : '';

if ($method === 'GET') {

    // ────────────────────────────────────────────────────────────────────
    // Poll payment/claim status
    // CRITICAL: Includes dispatched_result for proper tx verification
    // ────────────────────────────────────────────────────────────────────
    /**
     * 4j (4 Sep 2026): will a healer pick this row up?
     * Mirrors vps_redrive_free's OWN reset predicate (the three permanent classes it excludes),
     * so a buyer-facing message can never promise a rescue the backend will not perform -- and
     * the front end never duplicates a backend rule that could drift. A 'paid'/'minting' row is
     * still in flight; a 'failed' row is re-driven unless its error is permanent.
     *   free groups -> redrive-free-mints lane 2
     *   paid groups -> retry-failed-mints Pass A -> admin_compensate (4a-safe)
     * Declared HERE, above every action check: the wrapper below is a CONDITIONAL declaration,
     * which PHP does not hoist, and get_buyer_purchases sits earlier in the if-chain than
     * get_group_status -- defining it lower would fatal on that endpoint.
     */
    if (!function_exists('imc_row_recoverable')) {
    function imc_row_recoverable($mint_status, $error_message) {
        $ms = (string) $mint_status;
        if ($ms === 'paid' || $ms === 'minting' || $ms === 'pending') return true;
        if ($ms !== 'failed' && $ms !== 'partial') return false;
        $em = (string) $error_message;
        foreach (['temMALFORMED', 'tecNO_PERMISSION', 'malformed'] as $imc_perm) {
            if (stripos($em, $imc_perm) !== false) return false;   // permanent -- a human is needed
        }
        return true;
    }
    }
    if ($action === 'poll_payment' || $action === 'poll_claim') {
        $uuid = sanitize_text_field($_GET['uuid'] ?? '');
        if (!$uuid) json_error('Missing UUID');

        // M7a (1): DB FIRST. The XUMM webhook (verified against XUMM's API by xumm-proxy.php) writes a
        // wp_xumm_status row with tx_hash when a routed payload dispatched tesSUCCESS. A row with a tx_hash
        // therefore MEANS success -- answer from it and make no XUMM call at all. Money truth is still
        // process_group's on-chain verify_payment; this only tells the browser it is time to POST.
        // (uuid is the table's PRIMARY KEY; legacy 'tip'-typed rows carry no tx_hash and never match.)
        $pp_row = $wpdb->get_row($wpdb->prepare(
            "SELECT signed, tx_hash, account FROM {$wpdb->prefix}xumm_status WHERE uuid = %s AND signed = 1 AND tx_hash IS NOT NULL AND tx_hash <> ''",
            $uuid
        ), ARRAY_A);
        if ($pp_row) {
            $result = ['uuid' => $uuid, 'signed' => true, 'resolved' => true, 'expired' => false, 'rejected' => false,
                       'tx_hash' => $pp_row['tx_hash'], 'dispatched_result' => 'tesSUCCESS', 'dispatched_to' => null, 'source' => 'db'];
            mod_log("poll uuid=$uuid signed=1 result=tesSUCCESS source=db");
            json_success($result);
        }
        // M7a (2): the XUMM call is now the SAFETY NET (a webhook can be lost). Throttle it per uuid so a
        // buyer polling every 3s costs at most one XUMM call per IMC_M7A_POLL_FLOOR_SEC; in between, the
        // last real answer is replayed. XUMM enforces a per-minute call budget for the WHOLE app.
        if (!defined('IMC_M7A_POLL_FLOOR_SEC')) define('IMC_M7A_POLL_FLOOR_SEC', 15);   // wp-config.php may override; 0 = every tick (legacy)
        $pp_tkey = 'imc_m7a_poll_' . substr(preg_replace('/[^a-f0-9]/', '', $uuid), 0, 32);
        $pp_cached = (IMC_M7A_POLL_FLOOR_SEC > 0) ? get_transient($pp_tkey) : false;
        if (is_array($pp_cached) && isset($pp_cached['at'], $pp_cached['result']) && (time() - (int) $pp_cached['at']) < IMC_M7A_POLL_FLOOR_SEC) {
            $result = $pp_cached['result']; $result['source'] = 'cache';
            mod_log("poll uuid=$uuid signed={$result['signed']} result=" . ($result['dispatched_result'] ?? 'pending') . " source=cache");
            json_success($result);
        }

        $payload = poll_xumm($uuid);
        if (!$payload) json_error('Failed to poll XUMM');

        $meta     = $payload['meta'] ?? [];
        $response = $payload['response'] ?? [];

        $result = [
            'uuid'     => $uuid,
            'signed'   => $meta['signed'] ?? false,
            'resolved' => $meta['resolved'] ?? false,
            'expired'  => $meta['expired'] ?? false,
            'rejected' => $meta['cancelled'] ?? false
        ];

        if ($result['signed']) {
            $result['tx_hash']           = $response['txid'] ?? null;
            $result['dispatched_result'] = $response['dispatched_result'] ?? null;
            $result['dispatched_to']     = $response['dispatched_to'] ?? null;
        }

        if (IMC_M7A_POLL_FLOOR_SEC > 0) { set_transient($pp_tkey, ['at' => time(), 'result' => $result], 120); }   // M7a: replayed until the floor elapses
        $result['source'] = 'xumm';
        mod_log("poll uuid=$uuid signed={$result['signed']} result=" . ($result['dispatched_result'] ?? 'pending') . " source=xumm");
        json_success($result);
    }

    // ────────────────────────────────────────────────────────────────────
    // Get purchase details
    // ────────────────────────────────────────────────────────────────────
    if ($action === 'get_purchase') {
        $id    = intval($_GET['id'] ?? 0);
        $buyer = $imc_mod_wallet;   // session-derived identity

        if (!$id) json_error('Missing purchase ID');

        // The wallet comes from the session, so the WHERE clause below is a real
        // ownership check rather than a filter on a client-supplied value.
        if ($buyer === '') {
            json_error('Sign in to view this purchase', 403);
        }

        $purchase = $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, l.nft_name, l.cover_ipfs, l.has_tiers
             FROM $purchases_table p
             LEFT JOIN $listings_table l ON p.listing_id = l.id
             WHERE p.id = %d AND p.buyer_account = %s",
            $id, $buyer
        ), ARRAY_A);

        if (!$purchase) json_error('Purchase not found');
        json_success($purchase);
    }

    // ────────────────────────────────────────────────────────────────────
    // Get buyer's purchases (includes tier info)
    // ────────────────────────────────────────────────────────────────────
    // ── v304: Get all purchases for a specific group (for Recently Minted section) ──
    if ($action === 'get_group_purchases') {
        $group_id = intval($_GET['group_id'] ?? 0);
        $buyer    = $imc_mod_wallet;   // session-derived identity
        if ($buyer === '') json_error('Sign in to view your purchases', 403);
        if (!$group_id || !$buyer) json_error('Missing group_id or account');

        $purchases = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, l.nft_name, l.cover_ipfs AS listing_cover, l.artist_name,
                     t.cover_ipfs AS tier_cover
             FROM $purchases_table p
             LEFT JOIN $listings_table l ON p.listing_id = l.id
             LEFT JOIN $tiers_table    t ON p.tier_id    = t.id
             WHERE p.group_id = %d AND p.buyer_account = %s
             ORDER BY p.id ASC",
            $group_id, $buyer
        ), ARRAY_A);

        if ($purchases) {
            foreach ($purchases as &$p) {
                $p['cover_ipfs'] = $p['tier_cover'] ?? $p['listing_cover'] ?? '';
            }
            unset($p);
        }

        json_success(['purchases' => $purchases ?: []]);
    }

    if ($action === 'get_buyer_purchases') {
        $buyer = $imc_mod_wallet;   // session-derived identity
        if ($buyer === '') json_error('Sign in to view your purchases', 403);
        if (!$buyer) json_error('Missing account');

        // v60 FIX: Added LEFT JOIN to tiers table for tier-specific cover images
        // v284: ORDER BY minted_at DESC NULLS LAST — puts recently-minted NFTs first
        // so the 5-XRPL-call reconcile cap prioritises freshly minted items.
        // This means a direct Xaman claim on a new NFT is detected on the NEXT
        // dashboard load instead of potentially being skipped if the user has many older items.
        $purchases = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, l.nft_name, l.cover_ipfs AS listing_cover, l.artist_name, l.collection_taxon, l.artist_account,
                    t.cover_ipfs AS tier_cover
             FROM $purchases_table p
             LEFT JOIN $listings_table l ON p.listing_id = l.id
             LEFT JOIN $tiers_table t ON p.tier_id = t.id
             WHERE p.buyer_account = %s
             ORDER BY COALESCE(p.minted_at, p.created_at) DESC",
            $buyer
        ), ARRAY_A);

        // 4j (additive): per-row `recoverable`, from columns p.* already carries -- no JOIN and
        // no second query, which matters on an endpoint hit thousands of times during a
        // large drop. The dashboard card uses it to say "Finishing up" rather than
        // "Mint Failed" for something the system is already re-driving.
        if ($purchases) {
            foreach ($purchases as &$__bp) {
                $__bp['recoverable'] = imc_row_recoverable($__bp['mint_status'] ?? '', $__bp['error_message'] ?? '');
            }
            unset($__bp);
        }

        // v60 FIX: Use tier cover if available, else listing cover
        if ($purchases) {
            // v889 (Defect E): stale_minutes is computed HERE, server-side, against
            // current_time('mysql') -- the exact clock that wrote updated_at. The dashboard
            // needs to know whether a 'paid'/'minting' row is genuinely stuck before it offers
            // a Retry button, and parsing a MySQL datetime in the browser would compare it to
            // the VISITOR's clock: any site/browser timezone difference would make the button
            // appear immediately on a healthy in-flight mint (inviting mid-mint retries) or
            // never appear at all. Read-only, additive field; no existing consumer is affected.
            $imc_now_ts = strtotime(current_time('mysql'));
            foreach ($purchases as &$p) {
                $p['cover_ipfs'] = $p['tier_cover'] ?? $p['listing_cover'] ?? '';
                $imc_ref = !empty($p['updated_at']) ? $p['updated_at'] : ($p['created_at'] ?? '');
                $imc_ref_ts = $imc_ref ? strtotime($imc_ref) : 0;
                $p['stale_minutes'] = ($imc_ref_ts && $imc_now_ts >= $imc_ref_ts)
                    ? intval(floor(($imc_now_ts - $imc_ref_ts) / 60))
                    : 0;
            }
            unset($p);
        }

        // Auto-reconcile: if minted + has sell_offer_id but delivered=0,
        // check if the sell offer still exists on ledger. If gone, mark delivered.
        // Also reverse-verify: if delivered=1 but sell offer STILL EXISTS, reset to unclaimed.
        //
        // v242: Cap at 5 XRPL calls per page load.
        // v283: Reduced cutoff from 5 minutes to 30 seconds.
        //   Previously: 5 min cutoff was meant to skip purchases mid-claim-flow.
        //   Problem: users who claim directly in Xaman (not our JS flow) return to
        //   the site within seconds. The 5-min guard prevented reconciliation, so the
        //   dashboard still showed the Claim button even though the NFT was already taken.
        //   30 seconds is sufficient to avoid interfering with the active JS polling loop.
        if ($purchases) {
            $reconcile_limit = 5;
            $reconciled      = 0;
            $reconcile_cutoff = date('Y-m-d H:i:s', strtotime(current_time('mysql') . ' -30 seconds'));

            foreach ($purchases as &$p) {
                if ($p['mint_status'] === 'minted' && !empty($p['sell_offer_id'])) {

                    // Skip very recent purchases — they're mid-claim flow
                    if (!empty($p['created_at']) && $p['created_at'] > $reconcile_cutoff) {
                        continue;
                    }

                    // v242: Stop reconciling once we hit the per-request cap
                    if ($reconciled >= $reconcile_limit) {
                        continue;
                    }
                    $reconciled++;

                    $check = xrpl_rpc('ledger_entry', [
                        'nft_offer' => $p['sell_offer_id'],
                        'ledger_index' => 'validated'
                    ]);
                    
                    // xrpl_rpc returns the result object directly.
                    // entryNotFound = offer was consumed (claimed) or cancelled
                    $is_entry_not_found = (
                        isset($check['error']) 
                        && $check['error'] === 'entryNotFound'
                    );
                    // Offer still exists on-chain
                    $offer_exists = isset($check['node']);
                    
                    // Forward: not delivered but offer consumed → mark delivered
                    if (empty($p['delivered']) && $is_entry_not_found) {
                        $now = current_time('mysql');
                        // v515: unified claim bookkeeping (purchase + group rollup)
                        imc_mark_purchase_claimed($p['id']);
                        $p['delivered'] = '1';
                        $p['delivered_at'] = $now;
                        $p['mint_status'] = 'claimed';
                        mod_log("get_buyer_purchases: Auto-reconciled purchase {$p['id']} as delivered (sell offer entryNotFound)");
                    }
                    
                    // Reverse: marked delivered but offer still exists → reset to unclaimed
                    // (fixes v52 bug that incorrectly marked all as delivered)
                    if (!empty($p['delivered']) && $offer_exists) {
                        $wpdb->update($purchases_table, 
                            ['delivered' => 0, 'delivered_at' => null],
                            ['id' => $p['id']]
                        );
                        $p['delivered'] = '0';
                        $p['delivered_at'] = null;
                        mod_log("get_buyer_purchases: Reverse-reconciled purchase {$p['id']} — sell offer still exists, resetting delivered=0");
                    }
                }
            }
            unset($p); // break reference
        }

        json_success(['purchases' => $purchases ?: []]);
    }

    // ────────────────────────────────────────────────────────────────────
    // Get artist's listings (dashboard)
    // ────────────────────────────────────────────────────────────────────
    if ($action === 'get_artist_listings') {
        $artist = sanitize_text_field($_GET['account'] ?? '');
        if (!$artist) json_error('Missing account');

        $listings = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $listings_table WHERE artist_account = %s ORDER BY created_at DESC",
            $artist
        ), ARRAY_A);

        json_success($listings ?: []);
    }

    // ────────────────────────────────────────────────────────────────────
    // v64: Get purchases for artist's listings (Creator Dashboard)
    // ────────────────────────────────────────────────────────────────────
    if ($action === 'get_artist_purchases') {
        $artist = $imc_mod_wallet;   // session-derived identity
        if ($artist === '') json_error('Sign in to view your sales', 403);
        $listing_ids_raw = sanitize_text_field($_GET['listing_ids'] ?? '');
        $limit = min(100, max(1, intval($_GET['limit'] ?? 50)));
        $offset = max(0, intval($_GET['offset'] ?? 0));
        
        if (!$artist) json_error('Missing account');
        
        // Build WHERE clause
        $where_parts = ["p.artist_account = %s"];
        $args = [$artist];
        
        // Optional: filter by specific listing IDs
        if ($listing_ids_raw) {
            $listing_ids = array_filter(array_map('intval', explode(',', $listing_ids_raw)));
            if (!empty($listing_ids)) {
                $placeholders = implode(',', array_fill(0, count($listing_ids), '%d'));
                $where_parts[] = "p.listing_id IN ($placeholders)";
                $args = array_merge($args, $listing_ids);
            }
        }
        
        $where = implode(' AND ', $where_parts);
        
        // Get purchases with listing info
        $purchases = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, l.nft_name, l.cover_ipfs
             FROM $purchases_table p
             LEFT JOIN $listings_table l ON p.listing_id = l.id
             WHERE $where
               AND p.mint_status IN ('minted', 'claimed', 'paid', 'minting', 'failed')
             ORDER BY p.created_at DESC
             LIMIT %d OFFSET %d",
            array_merge($args, [$limit, $offset])
        ), ARRAY_A);
        
        // Get total count
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $purchases_table p
             WHERE $where
               AND p.mint_status IN ('minted', 'claimed', 'paid', 'minting', 'failed')",
            $args
        ));
        
        json_success([
            'purchases' => $purchases ?: [],
            'total' => intval($total),
            'limit' => $limit,
            'offset' => $offset
        ]);
    }

    // ────────────────────────────────────────────────────────────────────
    // Get single listing by ID (public)
    // ────────────────────────────────────────────────────────────────────
    if ($action === 'get_listing') {
        $listing_id = intval($_GET['listing_id'] ?? 0);
        $buyer = sanitize_text_field($_GET['buyer'] ?? '');
        if (!$listing_id) json_error('Missing listing_id');

        $listing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $listings_table WHERE id = %d AND status = 'active'",
            $listing_id
        ), ARRAY_A);

        if (!$listing) json_error('Listing not found or not active');

        // Calculate available editions
        // OE-v1: Open editions have total_editions=0 — return large sentinel; buyer UI never shows "sold out"
        if (($listing['edition_type'] ?? 'fixed') === 'open') {
            $listing['available_editions'] = 999999;
            $listing['is_open_edition']    = true;
        } else {
            $listing['available_editions'] = max(0,
                intval($listing['total_editions']) - intval($listing['minted_count']) - intval($listing['reserved_count'] ?? 0)
            );
            $listing['is_open_edition'] = false;
        }

        // U6 (Unlockables Master): vault-file count for the mint-modal teaser
        // (distinct active hashes -- mirrors the collections.php card aggregate).
        $listing['ul_count'] = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT content_hash) FROM {$wpdb->prefix}imc_listing_unlockables
              WHERE listing_id = %d AND status = 'active'",
            intval($listing['id'])
        )));

        // v74 FIX: Parse accepted_currencies JSON so frontend receives an array
        if (!empty($listing['accepted_currencies'])) {
            $currencies = json_decode($listing['accepted_currencies'], true);
            if (is_array($currencies)) {
                $listing['accepted_currencies'] = $currencies;
            } else {
                // Fallback to XRP only if parse fails
                $listing['accepted_currencies'] = [
                    ['currency' => 'XRP', 'price' => floatval($listing['price_xrp']), 'enabled' => true, 'name' => 'XRP', 'icon' => '💧']
                ];
            }
        } else {
            // Default to XRP only
            $listing['accepted_currencies'] = [
                ['currency' => 'XRP', 'price' => floatval($listing['price_xrp']), 'enabled' => true, 'name' => 'XRP', 'icon' => '💧']
            ];
        }

        // v76: Add pricing mode info
        $listing['pricing_mode'] = $listing['pricing_mode'] ?? 'static';
        $listing['price_usd'] = !empty($listing['price_usd']) ? floatval($listing['price_usd']) : null;
        
        // v728 (A2): tell the frontend whether proportional allowlist pricing is live,
        // so the currency picker only shows token discounts the server will honour.
        $listing['allowlist_proportional_pricing'] = (bool) IMC_ALLOWLIST_SCOPED_CONSUME;
        // Task H: tells the buyer client the allowlist-x-PWYW floor is live server-side
        // (m-o-d.js reads listing.allowlist_pwyw for the free-claim note).
        $listing['allowlist_pwyw'] = (bool) IMC_ALLOWLIST_PWYW;

        // v76: Add mint limit info for buyer
        $listing['mint_limit_enabled'] = !empty($listing['mint_limit_enabled']);
        $listing['mint_limit_per_wallet'] = intval($listing['mint_limit_per_wallet'] ?? 0);
        
        if ($buyer && $listing['mint_limit_enabled'] && $listing['mint_limit_per_wallet'] > 0) {
            // v891 (Defect V): was its own 4-status query, so the number shown to the buyer
            // was LOWER than the number reserve_purchase blocks on. Now the same helper both
            // use, so "N of M used" is the figure that will actually refuse them.
            // (The old query also carried a redundant `AND mint_status != 'failed'` alongside
            // an IN list that never contained 'failed'.)
            $already_minted = imc_wallet_holdings($listing_id, $buyer);
            
            $listing['buyer_minted_count'] = intval($already_minted);
            $listing['buyer_remaining_limit'] = max(0, $listing['mint_limit_per_wallet'] - intval($already_minted));
        }

        // v715: attach each token's issuer transfer rate so the popup can show what the
        // creator actually receives. The popup already has every entry's issuer (this endpoint
        // decodes accepted_currencies wholesale), so this is the only missing piece, and it
        // rides on a request the popup already makes -- no extra round-trip.
        //
        // SAFE HERE: get_listing holds no transaction and takes no row lock, so unlike
        // reserve_purchase there is no risk of serialising buyers behind network I/O. A null
        // rate simply means "unknown" and the UI stays silent.
        if (!empty($listing['accepted_currencies']) && is_array($listing['accepted_currencies'])
            && class_exists('IMC_Price_Oracle')) {
            foreach ($listing['accepted_currencies'] as $imc_ci => $imc_cc) {
                if (!is_array($imc_cc)) continue;
                $imc_ct = strtoupper($imc_cc['currency'] ?? '');
                $imc_cs = $imc_cc['issuer'] ?? '';
                $listing['accepted_currencies'][$imc_ci]['transfer_rate'] =
                    ($imc_ct !== '' && $imc_ct !== 'XRP' && !empty($imc_cs))
                        ? IMC_Price_Oracle::get_transfer_rate($imc_cs)
                        : null;
            }
        }

        json_success(['listing' => $listing]);
    }

    // ────────────────────────────────────────────────────────────────────
    // Get listings by collection
    // ────────────────────────────────────────────────────────────────────
    if ($action === 'get_collection_listings') {
        $issuer = sanitize_text_field($_GET['issuer'] ?? '');
        $taxon  = intval($_GET['taxon'] ?? 0);

        if (!$issuer || !$taxon) json_error('Missing issuer or taxon');

        $listings = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $listings_table
             WHERE artist_account = %s AND collection_taxon = %d AND status = 'active'
             ORDER BY created_at DESC",
            $issuer, $taxon
        ), ARRAY_A);

        json_success($listings ?: []);
    }

    // ────────────────────────────────────────────────────────────────────
    // Get minted NFTs for a collection (for collection page grid)
    // ────────────────────────────────────────────────────────────────────
    if ($action === 'get_collection_nfts') {
        $issuer = sanitize_text_field($_GET['issuer'] ?? '');
        $taxon  = intval($_GET['taxon'] ?? 0);

        if (!$issuer) json_error('Missing issuer');

        $nfts = $wpdb->get_results($wpdb->prepare(
            "SELECT p.nftoken_id, p.edition_number, p.tier_name, p.buyer_account,
                    p.delivered, p.mint_status,
                    l.nft_name, l.cover_ipfs, l.artist_account, l.collection_taxon
             FROM $purchases_table p
             LEFT JOIN $listings_table l ON p.listing_id = l.id
             WHERE l.artist_account = %s 
               AND l.collection_taxon = %d
               AND p.mint_status = 'minted'
               AND p.nftoken_id IS NOT NULL
               AND p.nftoken_id != ''
             ORDER BY p.edition_number ASC",
            $issuer, $taxon
        ), ARRAY_A);

        json_success(['nfts' => $nfts ?: []]);
    }

    // ────────────────────────────────────────────────────────────────────
    // Get group status — poll mint progress (Task #10)
    // ────────────────────────────────────────────────────────────────────
    if ($action === 'get_group_status') {
        $group_id = intval($_GET['group_id'] ?? 0);
        if (!$group_id) json_error('Missing group_id');

        // v243: Require buyer_account to prevent enumeration of other users'
        // purchase data. group_id is a sequential integer — without this check,
        // any visitor could iterate IDs and expose buyer wallets, payment hashes,
        // and XUMM UUIDs for all purchases.
        $caller_account = sanitize_text_field($_GET['buyer_account'] ?? '');

        $group = $wpdb->get_row($wpdb->prepare(
            "SELECT id, listing_id, buyer_account, quantity, total_price_xrp,
                    payment_currency, payment_amount, mint_progress, claim_progress,
                    status, error_message, payment_tx_hash, payment_xumm_uuid,
                    created_at, updated_at
             FROM $groups_table WHERE id = %d",
            $group_id
        ), ARRAY_A);

        if (!$group) json_error('Group not found');

        // Verify caller is the buyer (or provide no account for background service calls)
        // SEC-1 (M8): absent OR mismatched buyer_account -> COARSE mode. No 403 (a 403
        // confirms the group exists -- an oracle); no group row, purchases, hashes or
        // amounts leave the server. All legitimate callers send the account (JS L1872/1999/2796).
        $sec1_owner = ($caller_account !== '' && $group['buyer_account'] === $caller_account);

        // Get individual purchase statuses
        $purchases = $wpdb->get_results($wpdb->prepare(
            "SELECT id, edition_number, tier_id, tier_name, nftoken_id, sell_offer_id,
                    mint_status, delivered, metadata_ipfs, error_message
             FROM $purchases_table WHERE group_id = %d ORDER BY id ASC",
            $group_id
        ), ARRAY_A);

        // M7b: queue position + server-driven poll cadence (ADDITIVE fields; existing keys untouched).
        // Position is ordered by created_at (the key M5's leader will use -- never id, the compensation
        // lane re-creates rows) with id as the tie-break; today's actual service order is lock
        // contention, so the UI says 'approximately'. poll_after_ms lets the page slow its own polling
        // as the queue lengthens: every handler request costs ~0.6s of one of 300 PHP workers (M4 4a).
        if (!defined('IMC_M7B_SEC_PER_GROUP')) define('IMC_M7B_SEC_PER_GROUP', 20);   // M4: 18-24s per single-NFT batch
        if (!defined('IMC_M7B_POLL_CAP_MS'))   define('IMC_M7B_POLL_CAP_MS', 15000);
        $q_position = null; $q_ahead = null; $q_eta = null; $q_poll = 1500;
        if (in_array($group['status'], ['paid', 'minting'], true)) {
            $q_ahead = intval($wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $groups_table
                  WHERE status IN ('paid', 'minting') AND mint_progress < quantity
                    AND (created_at < %s OR (created_at = %s AND id < %d))",
                $group['created_at'], $group['created_at'], $group_id
            )));
            $q_position = $q_ahead + 1;
            $q_eta      = ($group['status'] === 'minting') ? 0 : $q_ahead * IMC_M7B_SEC_PER_GROUP;
            $q_poll     = ($group['status'] === 'minting') ? 1500 : min(IMC_M7B_POLL_CAP_MS, 3000 + 100 * $q_ahead);
        }
        // v275: Expose payment_tx_hash at top level (also in group) for _retryFromHistory
        $m8_stalled = ($group['status'] === 'minting') ? max(0, time() - strtotime($group['updated_at'])) : 0;   // M8-b
        if (!$sec1_owner) {
            json_success(['status' => $group['status'], 'queue_position' => $q_position,
                'queue_ahead' => $q_ahead, 'eta_seconds' => $q_eta, 'poll_after_ms' => $q_poll]);   // SEC-1 coarse
        }
        json_success([
            'group'            => $group,
            'purchases'        => $purchases,
            'payment_tx_hash'  => $group['payment_tx_hash'] ?? null,
            'queue_position'   => $q_position,   // M7b (additive)
            'queue_ahead'      => $q_ahead,
            'eta_seconds'      => $q_eta,
            'poll_after_ms'    => $q_poll,
            'stalled_seconds'  => $m8_stalled,   // M8-b (additive)
            // 4j (additive): lets the UI tell a self-healing hiccup from a real dead end, and stop
            // it claiming "your payment was received" on a free mint (payment_amount is 0 there).
            'recoverable'      => (bool) count(array_filter($purchases, function ($__r) {
                                      return imc_row_recoverable($__r['mint_status'] ?? '', $__r['error_message'] ?? '');
                                  })),
            'is_free'          => (floatval($group['payment_amount'] ?? 0) == 0),
        ]);
    }

    // ────────────────────────────────────────────────────────────────────
    // Check unclaimed NFTs for buyer in collection (Task #12)
    // ────────────────────────────────────────────────────────────────────
    if ($action === 'check_unclaimed') {
        $buyer  = $imc_mod_wallet;   // session-derived identity
        if ($buyer === '') json_error('Sign in to view your purchases', 403);
        $taxon  = intval($_GET['taxon'] ?? 0);
        $artist = sanitize_text_field($_GET['artist'] ?? '');

        if (!$buyer) json_error('Missing account');

        $where = "p.buyer_account = %s AND p.mint_status = 'minted' AND p.delivered = 0";
        $args  = [$buyer];

        if ($taxon && $artist) {
            $where .= " AND l.collection_taxon = %d AND l.artist_account = %s";
            $args[] = $taxon;
            $args[] = $artist;
        }

        // v294 FIX: Reconcile against the ledger before counting.
        // If the buyer claimed their NFT in Xaman or another marketplace,
        // their sell offer is gone but delivered=0 in our DB — causing a
        // false "you have unclaimed NFTs" error that blocks new mints.
        $apparent = $wpdb->get_results($wpdb->prepare(
            "SELECT p.id, p.sell_offer_id FROM $purchases_table p
             LEFT JOIN $listings_table l ON p.listing_id = l.id
             WHERE $where",
            ...$args
        ), ARRAY_A);

        $truly_unclaimed = 0;
        if ($apparent) {
            $now_rec = current_time('mysql');
            foreach ($apparent as $ap) {
                if (empty($ap['sell_offer_id'])) { $truly_unclaimed++; continue; }
                $lc = xrpl_rpc('ledger_entry', ['nft_offer' => $ap['sell_offer_id'], 'ledger_index' => 'validated']);
                if (isset($lc['error']) && $lc['error'] === 'entryNotFound') {
                    // v515: unified claim bookkeeping (purchase + group rollup)
                    imc_mark_purchase_claimed($ap['id']);
                    mod_log("check_unclaimed: Reconciled purchase {$ap['id']} as delivered (offer gone — claimed externally)");
                } else {
                    $truly_unclaimed++;
                }
            }
        }

        json_success(['unclaimed_count' => $truly_unclaimed]);
    }

    // ── v309: warm_image_cache — pre-warms VPS img.php disk cache for a listing ──
    // Called after a successful mint batch so all edition images are cached on the
    // VPS before any user loads the collection page. Without this, the first visitor
    // to each NFT card triggers a live 15-30s Pinata fetch. With this, every visitor
    // after minting gets an instant disk-cache hit.
    // Fire-and-forget safe: uses non-blocking HTTP to img.php so no timeout risk.
    if ($action === 'warm_image_cache') {
        $listing_id = intval($_GET['listing_id'] ?? 0);
        if (!$listing_id) json_error('Missing listing_id');

        $listing = $wpdb->get_row($wpdb->prepare(
            "SELECT l.id, l.cover_ipfs AS listing_cover, l.nft_name,
                    l.artist_account, l.collection_taxon
             FROM $listings_table l WHERE l.id = %d",
            $listing_id
        ), ARRAY_A);
        if (!$listing) json_error('Listing not found');

        // Get all unique cover CIDs for this listing (listing cover + any tier covers)
        $cids = [];

        if (!empty($listing['listing_cover'])) {
            $cids[] = preg_replace('#^ipfs://#', '', $listing['listing_cover']);
        }

        // Tier covers
        $tier_covers = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT cover_ipfs FROM {$wpdb->prefix}imc_listing_tiers
             WHERE listing_id = %d AND cover_ipfs IS NOT NULL AND cover_ipfs != ''",
            $listing_id
        ));
        foreach ($tier_covers as $tc) {
            $cids[] = preg_replace('#^ipfs://#', '', $tc);
        }

        $cids = array_unique(array_filter($cids));

        $img_proxy_base = 'https://metadata.imcollectibles.io/img.php';
        $warmed = 0;
        $errors = [];

        foreach ($cids as $cid) {
            $url = $img_proxy_base . '?url=' . urlencode('ipfs://' . $cid) . '&nocache=1';
            $resp = wp_remote_get($url, [
                'timeout'   => 45,   // Allow full Pinata fetch time
                'sslverify' => true,
            ]);
            if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
                $warmed++;
            } else {
                $errors[] = $cid;
            }
        }

        json_success([
            'warmed' => $warmed,
            'total'  => count($cids),
            'errors' => $errors,
        ]);
    }

    json_error('Unknown GET action');
}


// ============================================================================
// POST ENDPOINTS
// ============================================================================

if ($method === 'POST') {

    // ── v295: admin_compensate must be checked BEFORE nonce verification. ──────
    // The VPS CLI tool (imc-compensate-vps.php) authenticates via IMC_COMP_ADMIN_KEY
    // rather than a WordPress nonce. Without this early-exit, the nonce check below
    // would reject every VPS CLI request with "Session expired".
    if (($action ?? '') === 'admin_compensate') {
        $comp_key = defined('IMC_COMP_ADMIN_KEY') ? IMC_COMP_ADMIN_KEY : '';
        $provided  = trim($_POST['admin_key'] ?? '');
        if (empty($comp_key) || !hash_equals($comp_key, $provided)) {
            http_response_code(403);
            json_error('Unauthorized — invalid admin_key');
        }
        // Auth passed — fall through to the admin_compensate handler below.
        // The nonce block is skipped for this action.
        goto admin_compensate_handler;
    }

    // v43b: admin_fix_creator_mint also uses admin_key auth (VPS script)
    if (($action ?? '') === 'admin_fix_creator_mint') {
        $comp_key = defined('IMC_COMP_ADMIN_KEY') ? IMC_COMP_ADMIN_KEY : '';
        $provided  = trim($_POST['admin_key'] ?? '');
        if (empty($comp_key) || !hash_equals($comp_key, $provided)) {
            http_response_code(403);
            json_error('Unauthorized — invalid admin_key');
        }
        goto admin_fix_handler;
    }

    // v43b: admin_fix_tier_mapping also uses admin_key auth (VPS script)
    if (($action ?? '') === 'admin_fix_tier_mapping') {
        $comp_key = defined('IMC_COMP_ADMIN_KEY') ? IMC_COMP_ADMIN_KEY : '';
        $provided  = trim($_POST['admin_key'] ?? '');
        if (empty($comp_key) || !hash_equals($comp_key, $provided)) {
            http_response_code(403);
            json_error('Unauthorized — invalid admin_key');
        }
        goto admin_fix_tier_handler;
    }

    // v397: VPS mint worker endpoints — admin_key auth (same as admin_compensate)
    // These lightweight REST endpoints are called by the VPS mint worker to perform
    // DB operations during VPS-delegated minting. Each completes in <200ms.
    if (in_array($action ?? '', ['vps_batch_start','vps_prepare_nft','vps_save_nftoken','vps_complete_nft','vps_mint_failed','vps_batch_finish','vps_batch_next','vps_batch_claim','vps_redrive_free'])) {
        $comp_key = defined('IMC_COMP_ADMIN_KEY') ? IMC_COMP_ADMIN_KEY : '';
        $provided  = trim($_POST['admin_key'] ?? '');
        if (empty($comp_key) || !hash_equals($comp_key, $provided)) {
            http_response_code(403);
            json_error('Unauthorized — invalid admin_key');
        }
        goto vps_mint_endpoints;
    }

    // ── v643: run_reconcile — admin-key auth BEFORE nonce (server-to-server heartbeat) ──
    // Mirrors admin_compensate's pre-nonce pattern: the VPS cron authenticates via
    // IMC_COMP_ADMIN_KEY, not a WP nonce. Self-contained (auth + run + json_* exit), so no
    // goto/label is needed. dry=1 is READ-ONLY (counts only). Live calls the deployed,
    // idempotent recovery functions verbatim — no new mint logic.
    if (($action ?? '') === 'run_reconcile') {
        $rc_key      = defined('IMC_COMP_ADMIN_KEY') ? IMC_COMP_ADMIN_KEY : '';
        $rc_provided = trim($_POST['admin_key'] ?? $_GET['admin_key'] ?? '');
        if (empty($rc_key) || !hash_equals($rc_key, $rc_provided)) {
            http_response_code(403);
            json_error('Unauthorized — invalid admin_key');
        }
        $rc_dry = (($_POST['dry'] ?? $_GET['dry'] ?? '0') === '1');
        $rc_now = current_time('mysql');

        if ($rc_dry) {
            // READ-ONLY preview — mirrors each function's own WHERE clause. No writes, no calls.
            $rc_reserved_expired = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $groups_table WHERE status = 'reserved' AND reservation_expires_at < %s",
                $rc_now
            ));
            $rc_minting_stuck = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM $groups_table WHERE status = 'minting'"
            );
            $rc_expired_unverified = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM $groups_table
                  WHERE status = 'expired' AND payment_verified = 0
                    AND payment_xumm_uuid IS NOT NULL AND payment_xumm_uuid <> ''"
            );
            $rc_sample = $wpdb->get_col(
                "SELECT id FROM $groups_table
                  WHERE status = 'expired' AND payment_verified = 0
                    AND payment_xumm_uuid IS NOT NULL AND payment_xumm_uuid <> ''
                  ORDER BY updated_at DESC LIMIT 20"
            );
            mod_log("run_reconcile DRY: reserved_past_expiry=$rc_reserved_expired minting_stuck=$rc_minting_stuck expired_unverified_uuid=$rc_expired_unverified");
            json_success([
                'mode' => 'dry',
                'candidates' => [
                    'reserved_past_expiry'         => $rc_reserved_expired,
                    'minting_stuck'                => $rc_minting_stuck,
                    'expired_unverified_with_uuid' => $rc_expired_unverified,
                ],
                'expired_sample_ids' => array_map('intval', $rc_sample),
                'note' => 'Preview only — no functions called, nothing written. Actual promotions require a signed + on-chain-verified payment per group.'
            ]);
        }

        // LIVE — run the deployed, idempotent recovery functions in the WP-Cron hook order.
        $rc_t0 = microtime(true);
        cleanup_expired_reservations();
        recover_stuck_mints();
        if (function_exists('reconcile_expired_paid')) { reconcile_expired_paid(); }
        $rc_elapsed = round(microtime(true) - $rc_t0, 2);
        mod_log("run_reconcile LIVE: ran cleanup+recover+reconcile in {$rc_elapsed}s");
        @touch(__DIR__ . '/logs/recovery_last_run.marker');   // M1: the cron just did this work -- hold the request trigger off for one window
        json_success([
            'mode'      => 'live',
            'ran'       => ['cleanup_expired_reservations', 'recover_stuck_mints', 'reconcile_expired_paid'],
            'elapsed_s' => $rc_elapsed,
            'note'      => 'Recovery pass complete. Per-group promote/re-hold/refund detail is in mod_log.'
        ]);
    }

    // ── M2b: admin_release_dead_group — EVIDENCE-GATED release of a verified-paid group's hold ──
    // M2a stopped Pass 2 releasing paid groups by AGE (a verified payment is never released by a
    // clock). A genuinely DEAD paid group -- issuer walked away for good, listing closed -- would
    // therefore hold its slot forever unless an operator releases it ON EVIDENCE. This is that tool.
    // Same shape as run_reconcile (admin-key, pre-nonce, self-contained, no goto), same write
    // sequence as Pass 2 (clamped release -> 'failed' -> v889 purchase sync), DRY BY DEFAULT, and
    // every response -- success or refusal -- returns the full evaluation so the operator always
    // sees exactly what the system sees before acting. See the M2b pre-audit.
    if (($action ?? '') === 'admin_release_dead_group') {
        $rl_key      = defined('IMC_COMP_ADMIN_KEY') ? IMC_COMP_ADMIN_KEY : '';
        $rl_provided = trim($_POST['admin_key'] ?? '');
        if (empty($rl_key) || !hash_equals($rl_key, $rl_provided)) {
            http_response_code(403);
            json_error('Unauthorized — invalid admin_key');
        }
        if (!defined('IMC_M2B_MIN_AGE_HOURS')) define('IMC_M2B_MIN_AGE_HOURS', 24);   // wp-config.php may override
        $rl_gid = intval($_POST['group_id'] ?? 0);
        $rl_dry = (($_POST['dry'] ?? '1') !== '0');                 // DRY BY DEFAULT: only dry=0 writes
        $rl_att = trim((string) ($_POST['attest'] ?? ''));
        $rl_now = current_time('mysql');
        if (!$rl_gid) json_error('Missing group_id');

        $rl_g = $wpdb->get_row($wpdb->prepare("SELECT * FROM $groups_table WHERE id = %d", $rl_gid), ARRAY_A);
        if (!$rl_g) json_error('Group not found', 404);
        $rl_onchain = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $purchases_table WHERE group_id = %d AND nftoken_id IS NOT NULL AND nftoken_id <> ''", $rl_gid
        )));
        $rl_age_h = (time() - strtotime($rl_g['updated_at'] ?? $rl_now)) / 3600;

        // HARD PRECONDITIONS -- all must hold. A queue in progress is structurally impossible to release.
        $rl_pre = [
            'status_paid'      => ($rl_g['status'] === 'paid'),
            'verified'         => (intval($rl_g['payment_verified']) === 1),
            'no_progress'      => (intval($rl_g['mint_progress']) === 0),
            'nothing_on_chain' => ($rl_onchain === 0),
            'aged'             => ($rl_age_h >= IMC_M2B_MIN_AGE_HOURS),
        ];

        // EVIDENCE -- at least one must hold.
        $rl_l = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status, artist_account, edition_type, open_edition_closed_at FROM $listings_table WHERE id = %d",
            intval($rl_g['listing_id'])
        ), ARRAY_A);
        $rl_ev = [
            'listing_closed'      => ($rl_l !== null && (($rl_l['status'] ?? '') !== 'active' || !empty($rl_l['open_edition_closed_at']))),
            'issuer_deauthorised' => false,   // FAIL-CLOSED: only a SUCCESSFUL account_info showing a different NFTokenMinter counts
            'attested'            => (strlen($rl_att) >= 20),
        ];
        $rl_issuer_note = 'not checked';
        $rl_art = (string) ($rl_l['artist_account'] ?? '');
        if ($rl_art === '') {
            $rl_issuer_note = 'no artist on listing';
        } elseif (IMC_PLATFORM_WALLET === '') {
            $rl_issuer_note = 'IMC_PLATFORM_WALLET not defined -- signal unknown (fail-closed)';
        } elseif ($rl_art === IMC_PLATFORM_WALLET) {
            $rl_issuer_note = 'self-issued -- no delegation to lose';
        } else {
            // Mirrors reserve_purchase's Defect-AK check (L4729) but WITHOUT its fail-open: that gate lets a
            // mint proceed on RPC error; this one takes a buyer's hold away, so 'unknown' is NOT evidence.
            $rl_ai = xrpl_rpc('account_info', ['account' => $rl_art, 'ledger_index' => 'validated']);
            if (!empty($rl_ai['error'])) {
                $rl_issuer_note = 'RPC unavailable (' . $rl_ai['error'] . ') -- signal unknown (fail-closed)';
            } else {
                $rl_minter = (string) ($rl_ai['account_data']['NFTokenMinter'] ?? '');
                $rl_ev['issuer_deauthorised'] = ($rl_minter !== IMC_PLATFORM_WALLET);
                $rl_issuer_note = 'NFTokenMinter=' . ($rl_minter === '' ? 'NONE' : $rl_minter);
            }
        }
        $rl_eval = [
            'group_id' => $rl_gid, 'status' => $rl_g['status'], 'listing_id' => intval($rl_g['listing_id']),
            'quantity' => intval($rl_g['quantity']), 'age_hours' => round($rl_age_h, 1), 'min_age_hours' => IMC_M2B_MIN_AGE_HOURS,
            'preconditions' => $rl_pre, 'evidence' => $rl_ev, 'issuer_check' => $rl_issuer_note, 'dry' => $rl_dry,
        ];
        if (in_array(false, $rl_pre, true))  json_error('Preconditions not met -- refusing to release', 409, ['evaluation' => $rl_eval]);
        if (!in_array(true, $rl_ev, true))   json_error('No evidence (listing active, issuer authorised or unknown, no attestation) -- refusing to release', 409, ['evaluation' => $rl_eval]);
        if ($rl_dry) {
            mod_log("admin_release_dead_group: DRY group=$rl_gid would release qty={$rl_g['quantity']} (evidence: " . json_encode($rl_ev) . ")");
            json_success(['dry' => true, 'would_release' => intval($rl_g['quantity']), 'evaluation' => $rl_eval]);
        }

        // ACT. CLAIM first (the same discipline as M1 hunk G / M1-VPS / M1c): the group must still be
        // 'paid' with nothing minted at the instant we take it, or we do nothing.
        $rl_ledger = 'M2b released ' . $rl_now . ' | evidence: listing_closed=' . ($rl_ev['listing_closed'] ? '1' : '0')
                   . ' issuer_deauthorised=' . ($rl_ev['issuer_deauthorised'] ? '1' : '0') . ' (' . $rl_issuer_note . ')'
                   . ($rl_ev['attested'] ? ' attested="' . substr($rl_att, 0, 300) . '"' : '') . ' | by admin_key';
        $rl_cas = $wpdb->query($wpdb->prepare(
            "UPDATE $groups_table SET status = 'failed', error_message = %s, updated_at = %s WHERE id = %d AND status = 'paid' AND mint_progress = 0",
            $rl_ledger, $rl_now, $rl_gid
        ));
        if ((int) $rl_cas !== 1) json_error('Group changed concurrently -- NOT released', 409, ['evaluation' => $rl_eval]);
        $rl_rel = imc_release_reservation($rl_gid, intval($rl_g['listing_id']), intval($rl_g['quantity']), 'm2b-operator-release');
        $rl_pf  = $wpdb->query($wpdb->prepare(
            "UPDATE $purchases_table
                SET mint_status = 'failed',
                    error_message = 'Released by operator on evidence (M2b) — this edition could not be delivered. See your purchase history.',
                    updated_at = %s
              WHERE group_id = %d
                AND mint_status IN ('pending', 'paid', 'minting')
                AND (nftoken_id IS NULL OR nftoken_id = '')",
            $rl_now, $rl_gid
        ));
        mod_log("admin_release_dead_group: RELEASED group=$rl_gid listing={$rl_g['listing_id']} qty={$rl_g['quantity']} released=$rl_rel purchases_marked_failed=" . intval($rl_pf) . " | $rl_ledger");
        json_success(['released' => intval($rl_rel), 'purchases_marked_failed' => intval($rl_pf), 'evaluation' => $rl_eval]);
    }

    // Nonce verification
    $nonce = sanitize_text_field($_POST['nonce'] ?? '');
    mod_log("Nonce check: " . ($nonce ? substr($nonce, 0, 10) . '...' : 'EMPTY'));

    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        mod_log("Nonce verification FAILED — action=" . sanitize_text_field($_POST['action'] ?? 'unknown'));
        // v275: Return nonce_expired flag so JS can auto-refresh and retry
        json_error('Session expired — please refresh the page and try again', 403, ['nonce_expired' => true]);
    }
    mod_log("Nonce verified");

    // --------------------------------------------------------------------
    // v687: RECORD JOEY TX HASH  (Layer 1 of the Joey payment self-heal)
    //
    // Xaman writes its recovery key (payment_xumm_uuid) server-side at payload
    // creation, BEFORE the buyer signs, so reconcile_expired_paid() can always find
    // the payment afterwards. Joey/WalletConnect has no pre-signature key: the tx
    // hash only exists once the wallet has signed locally. If the tab dies between
    // signing and process_group, the payment sits on-ledger and the DB has no record
    // of it at all.
    //
    // This endpoint captures that hash the instant it exists. It is deliberately
    // write-only and UNVERIFIED: it sets payment_tx_hash and NOTHING else -- no status
    // change, no payment_verified, no edition, no inventory, no funds. Storing an
    // unverified client-supplied hash is safe precisely because nothing downstream
    // trusts it; verification stays with verify_payment_multicurrency() exactly as it
    // is today.
    //
    // Mirrors the proven tip-handler.php record pattern (strict regex + idempotent
    // duplicate check). Self-contained: modifies no existing function or flow.
    // --------------------------------------------------------------------
    if ($action === 'record_joey_tx') {
        $rjt_buyer = sanitize_text_field($_POST['account'] ?? ($_POST['buyer_account'] ?? ''));
        $rjt_gid   = intval($_POST['group_id'] ?? 0);
        $rjt_hash  = strtoupper(sanitize_text_field($_POST['tx_hash'] ?? ''));

        if (!$rjt_buyer || !$rjt_gid) json_error('Missing group_id or account');
        if (!preg_match('/^[A-F0-9]{64}$/', $rjt_hash)) json_error('Invalid transaction hash');

        $rjt_group = $wpdb->get_row($wpdb->prepare(
            "SELECT id, buyer_account, payment_tx_hash, payment_verified
               FROM $groups_table WHERE id = %d",
            $rjt_gid
        ), ARRAY_A);

        if (!$rjt_group) json_error('Reservation not found');

        // Ownership: only the owning wallet may attach a hash to its own group.
        if ($rjt_group['buyer_account'] !== $rjt_buyer) {
            json_error('This reservation does not belong to your wallet', 403);
        }

        // Already verified, or a hash is already present -> idempotent no-op. Never
        // overwrite: process_group or reconcile may already have written the
        // authoritative hash, and the first hash recorded wins.
        if (intval($rjt_group['payment_verified']) === 1 || !empty($rjt_group['payment_tx_hash'])) {
            mod_log("record_joey_tx: no-op group=$rjt_gid (hash already present or verified)");
            json_success(['group_id' => $rjt_gid, 'recorded' => false, 'already_present' => true]);
        }

        // One-hash-one-claim: refuse a hash already bound to a different group, so a
        // confused or malicious client cannot attach someone else's valid payment to
        // its own reservation. 'FREE_MINT' is a repeated sentinel -- excluded.
        $rjt_taken = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $groups_table
              WHERE payment_tx_hash = %s AND payment_tx_hash <> 'FREE_MINT' AND id <> %d
              LIMIT 1",
            $rjt_hash, $rjt_gid
        ));
        if ($rjt_taken) {
            mod_log("record_joey_tx: REJECTED group=$rjt_gid hash=$rjt_hash -- already claimed by group=" . intval($rjt_taken));
            json_error('This transaction is already recorded against another purchase', 409);
        }

        // Write the hash and nothing else. The WHERE clause makes this safe under
        // concurrent calls: only a row with an empty hash can ever be written.
        $rjt_rows = $wpdb->query($wpdb->prepare(
            "UPDATE $groups_table
                SET payment_tx_hash = %s, updated_at = %s
              WHERE id = %d AND (payment_tx_hash IS NULL OR payment_tx_hash = '')",
            $rjt_hash, current_time('mysql'), $rjt_gid
        ));

        mod_log("record_joey_tx: group=$rjt_gid hash=$rjt_hash rows=" . intval($rjt_rows));
        json_success(['group_id' => $rjt_gid, 'recorded' => (intval($rjt_rows) === 1)]);
    }

    // --------------------------------------------------------------------
    // v562: CANCEL RESERVATION (buyer self-service)
    //
    // Releases a buyer's OWN unpaid reservation immediately, instead of making
    // them wait up to IMC_RESERVATION_TTL for cleanup_expired_reservations().
    // Mirrors the cleanup()/stale-cancel DB release verbatim, with one added guard:
    //
    //   ORPHAN-PAYMENT PROTECTION: process_group refuses to mint a cancelled group
    //   (the 'Group in unexpected status' check). So if a buyer signed in Xaman and
    //   THEN cancelled, we would take their money with no NFT. Before releasing we
    //   void the payload and poll Xaman; if it is already signed we REFUSE to cancel
    //   and route them to completion instead.
    //
    // Self-contained: does NOT modify reserve_purchase or process_group.
    // --------------------------------------------------------------------
    if ($action === 'cancel_reservation') {
        $buyer    = sanitize_text_field($_POST['buyer_account'] ?? '');
        $group_id = intval($_POST['group_id'] ?? 0);

        if (!$buyer || !$group_id) json_error('Missing buyer_account or group_id');

        $group = $wpdb->get_row($wpdb->prepare(
            "SELECT id, listing_id, buyer_account, quantity, status, payment_xumm_uuid
             FROM $groups_table WHERE id = %d",
            $group_id
        ), ARRAY_A);

        if (!$group) json_error('Reservation not found');

        // Ownership: only the owning wallet may cancel its own reservation.
        if ($group['buyer_account'] !== $buyer) {
            json_error('This reservation does not belong to your wallet', 403);
        }

        // Already released (expired/failed) -> idempotent success so the UI just refreshes.
        if (in_array($group['status'], ['expired', 'failed'], true)) {
            json_success(['group_id' => $group_id, 'status' => $group['status'], 'already_released' => true]);
        }

        // Anything past 'reserved' means payment is in flight or done -- never cancel.
        if ($group['status'] !== 'reserved') {
            json_error('This purchase has already been paid and cannot be cancelled.');
        }

        // -- Orphan-payment guard ------------------------------------------
        // Void the Xaman payload first (so it can no longer be signed), then poll.
        // If it was ALREADY signed, the payment is on its way: refuse to cancel.
        $uuid = $group['payment_xumm_uuid'] ?? '';
        if (!empty($uuid)) {
            cancel_xumm_payload($uuid);
            $pl = poll_xumm($uuid);
            if (is_array($pl) && !empty($pl['meta']['signed'])) {
                mod_log("cancel_reservation: REFUSED -- group=$group_id already signed by buyer=$buyer");
                json_error('Your payment was already received - your mint is completing. Please wait a moment.');
            }
        }

        // -- Safe to release. Identical bookkeeping to cleanup_expired_reservations(). --
        $now = current_time('mysql');
        $qty = intval($group['quantity']);

        imc_release_reservation($group_id, $group['listing_id'], $qty, 'cancel-reservation');
        $wpdb->update($groups_table, ['status' => 'expired', 'updated_at' => $now], ['id' => $group_id]);
        $wpdb->query($wpdb->prepare(
            "UPDATE $purchases_table SET mint_status = 'cancelled', updated_at = %s WHERE group_id = %d AND mint_status = 'pending'",
            $now, $group_id
        ));

        mod_log("cancel_reservation: buyer=$buyer released group=$group_id listing={$group['listing_id']} qty=$qty");

        json_success(['group_id' => $group_id, 'status' => 'expired', 'released' => true, 'quantity' => $qty]);
    }


    // ────────────────────────────────────────────────────────────────────
    // Create fee payment (artist pays platform fee) — EXISTING, UNCHANGED
    // ────────────────────────────────────────────────────────────────────
    if ($action === 'create_fee_payment') {
        mod_log("create_fee_payment: Starting");

        $listing_id = intval($_POST['listing_id'] ?? 0);
        $artist     = sanitize_text_field($_POST['artist_account'] ?? '');

        if (!$listing_id || !$artist) json_error('Missing listing_id or artist_account');

        $listing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $listings_table WHERE id = %d AND artist_account = %s",
            $listing_id, $artist
        ), ARRAY_A);

        if (!$listing)                    json_error('Listing not found');
        if ($listing['status'] !== 'draft') json_error('Listing is not in draft status');
        if ($listing['platform_fee_paid'])  json_error('Fee already paid');

        $fee_xrp = floatval($listing['platform_fee_amount'] ?? 0);
        if ($fee_xrp <= 0) {
            // Fallback fee calculation (should not normally be reached — fee set at listing creation)
            $editions = intval($listing['total_editions'] ?? 1);
            $nft_type = $listing['nft_type'] ?? 'music';
            $type_fees = [
                'art'        => ['flat' => 2.0,  'per' => 0.03],
                'music'      => ['flat' => 2.5,  'per' => 0.06],
                'musicvideo' => ['flat' => 3.0,  'per' => 0.08],
                'film'       => ['flat' => 15.0, 'per' => 0.10],
                // v24: Album base=5 XRP + 1 XRP×track_count (track count read below)
                'album'      => ['flat' => 5.0,  'per' => 0.06],
                // M1-d: AudioBook uses the standard music base fee (no per-chapter charge)
                'audiobook'  => ['flat' => 2.5,  'per' => 0.06],
            ];
            $f = $type_fees[$nft_type] ?? $type_fees['music'];
            // v365: Art uses tiered flat fee, not per-master
            if ($nft_type === 'art') {
                $master_count = 1;
                if (!empty($listing['has_tiers'])) {
                    $tiers_table = $wpdb->prefix . 'imc_listing_tiers';
                    $master_count = max(1, intval($wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM $tiers_table WHERE listing_id = %d", $listing_id
                    ))));
                }
                $art_flat = ($master_count <= 100) ? 2.0 : (($master_count <= 500) ? 4.0 : 6.0);
                $fee_xrp = $art_flat + ($editions * $f['per']);
            // v24: Album fee = 10 + 1×track_count + 0.06×editions
            } elseif ($nft_type === 'album') {
                $track_count = 1;
                if (!empty($listing['album_tracks_json'])) {
                    $atj = json_decode($listing['album_tracks_json'], true);
                    if (is_array($atj)) $track_count = max(1, count($atj));
                }
                $album_base = $f['flat'] + (1.0 * $track_count);
                $fee_xrp = $album_base + ($editions * $f['per']);
            } else {
                $fee_xrp = $f['flat'] + ($editions * $f['per']);
            }
        }
        $fee_drops = strval(round($fee_xrp * 1000000));

        $txjson = [
            'TransactionType' => 'Payment',
            'Account'         => $artist,
            'Destination'     => IMC_FEE_WALLET,
            'Amount'          => $fee_drops,
            'SourceTag'       => 2606240013
        ];

        // --- Joey branch (v552, additive): return the listing-fee Payment txjson for
        // local signing. Front-end then calls the EXISTING publish action (tx_hash-verified,
        // replay-protected) -- no new verify needed. Xaman path below untouched.
        // v686: the SERVER decides the wallet. The client flag depends on an async boot
        // (bundle load -> 150ms poll -> reconnect round-trip); a Joey user who acted before it
        // finished silently received a XUMM payload for a wallet they don't use -- and the
        // account-binding guard, which only runs when the client flag is set, was skipped with
        // it. The xrpl_wallet_type cookie is set at login and available on the first request,
        // so it is authoritative. Xaman users are unaffected (their cookie never says joey).
        if (($_POST['wallet'] ?? '') === 'joey' || ($_COOKIE['xrpl_wallet_type'] ?? '') === 'joey') {
            json_success(['listing_id' => $listing_id, 'fee_xrp' => $fee_xrp, 'wallet' => 'joey', 'txjson' => $txjson]);
        }

        $payload = create_xumm_payload($txjson, [
            'type'       => 'listing_fee',
            'listing_id' => $listing_id
        ]);

        if (isset($payload['error']))  json_error('Failed to create payment: ' . $payload['error']);
        if (!isset($payload['uuid']))  json_error('No UUID in XUMM response');

        mod_log("create_fee_payment: SUCCESS uuid=" . $payload['uuid']);

        // v590 (additive): persist the fee payload uuid on the draft listing so the
        // server-side recovery in listings-handler (get_by_artist) can finish publication
        // even when the front-end tab is abandoned after payment -- fixes the mobile
        // "fee paid but listing stays draft" bug. Best-effort; does not block payload return.
        $wpdb->update($listings_table, ['platform_fee_uuid' => $payload['uuid']], ['id' => $listing_id]);

        json_success([
            'listing_id' => $listing_id,
            'fee_xrp'    => $fee_xrp,
            'uuid'       => $payload['uuid'],
            'qr_png'     => $payload['refs']['qr_png'] ?? null,
            'deeplink'   => $payload['next']['always'] ?? null,
            'websocket'  => $payload['refs']['websocket_status'] ?? null
        ]);
    }

    // ── v294/v295: ADMIN COMPENSATION (VPS CLI tool + imc-compensate-vps.php) ─
    // Auth happens BEFORE the nonce block above (goto admin_compensate_handler).
    // The goto skips nonce verification for VPS CLI calls authenticated by
    // IMC_COMP_ADMIN_KEY. The auth check here is a second guard for any call
    // that arrives at this point without going through the goto (should not happen,
    // but defence-in-depth).
    // ─────────────────────────────────────────────────────────────────────────
    admin_compensate_handler:
    // ── U7 (Unlockables Master): payment payload for POST-MINT vault additions ──
    // Sibling of create_fee_payment, minus the draft/fee-paid gates (the listing is
    // LIVE). This action only BUILDS the payment (QR/txjson to OUR fee wallet) --
    // enforcement lives in listings-handler's vault_add_claim, which re-prices
    // server-side from pool rows and verifies the tx on-chain (destination +
    // delivered_amount armor) before any slot inserts. Amount here comes from the
    // vault_add_quote response the dashboard passes through.
    if ($action === 'create_vault_fee_payment') {
        $listing_id = intval($_POST['listing_id'] ?? 0);
        $artist     = sanitize_text_field($_POST['artist_account'] ?? '');
        $amount_xrp = floatval($_POST['amount_xrp'] ?? 0);
        if (!$listing_id || !$artist) json_error('Missing listing_id or artist_account');
        if ($amount_xrp <= 0 || $amount_xrp > 200) json_error('Invalid vault fee amount');

        $listing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, artist_account, status FROM $listings_table WHERE id = %d AND artist_account = %s",
            $listing_id, $artist
        ), ARRAY_A);
        if (!$listing) json_error('Listing not found or not yours');

        $fee_drops = strval(round($amount_xrp * 1000000));
        $txjson = [
            'TransactionType' => 'Payment',
            'Account'         => $artist,
            'Destination'     => IMC_FEE_WALLET,
            'Amount'          => $fee_drops,
            'SourceTag'       => 2606240013
        ];

        if (($_POST['wallet'] ?? '') === 'joey' || ($_COOKIE['xrpl_wallet_type'] ?? '') === 'joey') {
            json_success(['listing_id' => $listing_id, 'fee_xrp' => $amount_xrp, 'wallet' => 'joey', 'txjson' => $txjson]);
        }

        $payload = create_xumm_payload($txjson, [
            'type'       => 'vault_fee',
            'listing_id' => $listing_id
        ]);
        if (isset($payload['error']))  json_error('Failed to create payment: ' . $payload['error']);
        if (!isset($payload['uuid']))  json_error('No UUID in XUMM response');
        mod_log("create_vault_fee_payment: SUCCESS uuid=" . $payload['uuid'] . " listing=$listing_id amount=$amount_xrp");
        json_success([
            'listing_id' => $listing_id,
            'fee_xrp'    => $amount_xrp,
            'uuid'       => $payload['uuid'],
            'qr_png'     => $payload['refs']['qr_png'] ?? null,
            'deeplink'   => $payload['next']['always'] ?? null,
            'websocket'  => $payload['refs']['websocket_status'] ?? null
        ]);
    }

    if ($action === 'admin_compensate') {
        // Auth guard (primary auth is above, before nonce — this is defence-in-depth)
        $comp_key = defined('IMC_COMP_ADMIN_KEY') ? IMC_COMP_ADMIN_KEY : '';
        $provided = trim($_POST['admin_key'] ?? '');
        if (empty($comp_key) || !hash_equals($comp_key, $provided)) {
            http_response_code(403);
            json_error('Unauthorized — invalid admin_key');
        }

        $listing_id      = intval($_POST['listing_id']      ?? 0);
        $buyer_account   = sanitize_text_field($_POST['buyer_account']   ?? '');
        $qty             = max(1, min(20, intval($_POST['qty'] ?? 1)));
        $source_tx_hash  = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $_POST['source_tx_hash'] ?? ''));
        $reason          = sanitize_text_field($_POST['reason'] ?? 'Admin compensation');
        // v710 (Step F4): OPTIONAL, additive. Layer 3 (match-orphan-payments.php) already
        // matched this payment to a stranded group by EXACT drops comparison against the
        // ledger, so it knows the true amount -- but the stranded group has no tx hash, so
        // the DB capture below can never find it. These let that caller hand the verified
        // figure over. Absent => resolution falls back exactly as before. Never required.
        $posted_paid_amount   = isset($_POST['paid_amount']) ? (float) $_POST['paid_amount'] : 0.0;
        $posted_paid_currency = strtoupper(sanitize_text_field($_POST['paid_currency'] ?? ''));
        $dry_run         = ($_POST['dry_run'] ?? '1') !== '0';

        if (!$listing_id || !$buyer_account || strlen($source_tx_hash) !== 64) {
            json_error('Missing or invalid parameters');
        }

        // ── Idempotency ───────────────────────────────────────────────────────
        // Check if this source_tx was already used AND the purchases were fully minted.
        // If purchases exist but were never minted (e.g. a previous run crashed after the
        // DB commit but before XRPL minting), we clean up the orphaned records and retry.
        $existing_group = $wpdb->get_row($wpdb->prepare(
            "SELECT g.id as group_id,
                    g.quantity,
                    SUM(CASE WHEN p.mint_status IN ('minted','claimed') THEN 1 ELSE 0 END) as minted_ok
             FROM $groups_table g
             JOIN $purchases_table p ON p.group_id = g.id
             WHERE g.payment_tx_hash = %s
             GROUP BY g.id
             LIMIT 1",
            $source_tx_hash
        ), ARRAY_A);

        $ac_reuse_gid = 0;   // F1: set when the existing group is reused in place
        if ($existing_group) {
            $minted_ok   = intval($existing_group['minted_ok']);
            $existing_gid = intval($existing_group['group_id']);

            if ($minted_ok >= $qty) {
                // Already fully minted — true idempotency block.
                mod_log("admin_compensate: idempotency block — source_tx=$source_tx_hash already minted group=$existing_gid");
                json_success(['already_applied' => true, 'existing_purchase_id' => $existing_gid]);
            }

            // v710 (Step F4): CAPTURE THE TRUE PAYMENT BEFORE THE CLEANUP DELETES IT.
            //
            // The dominant compensation path is: buyer pays late -> run_reconcile (every 3
            // min) backfills payment_tx_hash + promotes the group to 'paid' WITHOUT touching
            // payment_amount -> retry-failed-mints (every 10 min) re-drives it here. So the
            // group about to be deleted below still holds the correct amount and currency.
            // Read it now; two lines further down it is gone forever.
            //
            // Separate SELECT on purpose -- the idempotency query above is protection logic
            // and stays byte-identical. This path runs only on compensations, so the extra
            // query costs nothing. Read-only; failure here can never block a compensation.
            $src_paid = $wpdb->get_row($wpdb->prepare(
                "SELECT payment_amount, payment_currency, payment_currency_issuer, quantity
                 FROM $groups_table WHERE id = %d LIMIT 1",
                $existing_gid
            ), ARRAY_A);
            if (is_array($src_paid) && (float) $src_paid['payment_amount'] > 0) {
                $src_qty = max(1, (int) $src_paid['quantity']);
                // Store a PER-UNIT rate. Automated callers always pass the source group's own
                // quantity so this is a straight carry; the manual CLI can pass a different
                // --qty, and a rate scales correctly where a raw total would not.
                $captured_rate     = (float) $src_paid['payment_amount'] / $src_qty;
                $captured_currency = strtoupper(trim((string) $src_paid['payment_currency']));
                $captured_issuer   = $src_paid['payment_currency_issuer'] ?? null;
                mod_log("admin_compensate: captured true payment from group=$existing_gid -- "
                    . "rate={$captured_rate} {$captured_currency} (was {$src_paid['payment_amount']} over {$src_qty})");
            }

            // F1 (Phase 4a, 2 Sep 2026): DELETE-and-recreate destroyed real state on the rows it dropped --
            // an edition M-ED-R had RETAINED (permanent gap), and, since C-FIX started binding on-chain ids
            // onto failed rows, the only reference to a minted NFT (a second mint = an orphan). Both are
            // invisible to retry-failed-mints' HAVING minted_count=0 because the row is 'failed'.
            // So: if ANY row carries state worth keeping, REUSE THE GROUP IN PLACE -- keep the hold, reset
            // failed rows exactly as Retry does, mint into the existing rows (the loop below now honours
            // a bound nftoken (v277) and a retained edition (M-ED-R)). Bare rows keep today's path unchanged.
            $ac_keep = intval($wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $purchases_table WHERE group_id = %d
                   AND ((edition_retained = 1 AND edition_number > 0) OR (nftoken_id IS NOT NULL AND nftoken_id <> ''))",
                $existing_gid
            )));
            if ($ac_keep > 0) {
                $ac_reuse_gid = $existing_gid;
                $ac_reset = $wpdb->query($wpdb->prepare(
                    "UPDATE $purchases_table SET mint_status = 'paid', error_message = NULL, updated_at = %s
                      WHERE group_id = %d AND mint_status = 'failed'
                        AND COALESCE(error_message,'') NOT LIKE '%%temMALFORMED%%'
                        AND COALESCE(error_message,'') NOT LIKE '%%tecNO_PERMISSION%%'
                        AND COALESCE(error_message,'') NOT LIKE '%%malformed%%'",
                    current_time('mysql'), $existing_gid
                ));
                mod_log("admin_compensate: REUSING group=$existing_gid in place (minted_ok=$minted_ok/$qty, $ac_keep row(s) carry a retained edition or a bound nftoken) -- reset " . intval($ac_reset) . " failed row(s); hold KEPT; nothing deleted (F1)");
            } else {
            // Orphaned records from a previous crashed run. Clean them up so we can retry.
            mod_log("admin_compensate: orphaned group=$existing_gid found (minted_ok=$minted_ok/$qty) — cleaning up for retry");
            $orphan_qty = intval($existing_group['quantity']);
            imc_release_reservation($existing_gid, $listing_id, $orphan_qty, 'admin-compensate-orphan-cleanup');
            $wpdb->query($wpdb->prepare("DELETE FROM $purchases_table WHERE group_id = %d", $existing_gid));
            $wpdb->query($wpdb->prepare("DELETE FROM $groups_table WHERE id = %d", $existing_gid));
            mod_log("admin_compensate: orphan cleanup done — released reserved_count=$orphan_qty, deleted group=$existing_gid");
            }
        }

        // ── Fetch listing ─────────────────────────────────────────────────────
        $listing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $listings_table WHERE id = %d", $listing_id
        ), ARRAY_A);
        if (!$listing) json_error("Listing $listing_id not found");

        // v400: Open editions have total_editions=0 (unlimited) — skip supply cap check
        $is_oe = (($listing['edition_type'] ?? 'fixed') === 'open');
        if ($is_oe) {
            $available = 999999;
        } else {
            $available = max(0,
                intval($listing['total_editions']) - intval($listing['minted_count']) - intval($listing['reserved_count'])
            );
        }

        if ($qty > $available) {
            json_success([
                'listing_name'   => $listing['nft_name'],
                'total_editions' => intval($listing['total_editions']),
                'minted_count'   => intval($listing['minted_count']),
                'available'      => $available,
                'supply_error'   => "qty=$qty exceeds available=$available"
            ]);
        }

        if ($dry_run) {
            json_success([
                'listing_name'   => $listing['nft_name'],
                'total_editions' => intval($listing['total_editions']),
                'minted_count'   => intval($listing['minted_count']),
                'available'      => $available,
                'dry_run'        => true,
            ]);
        }

        // ── LIVE: Reserve + create group + mint ───────────────────────────────
        $now        = current_time('mysql');
        $expires_at = date('Y-m-d H:i:s', strtotime($now . ' +87600 hours'));

        // ── v710 (Step F4): resolve what this buyer ACTUALLY paid ─────────────
        // Order: (1) the group we captured above (the self-heal path -- always correct),
        // (2) an amount posted by Layer 3 (orphan payments that were never a group),
        // (3) zero, exactly as this endpoint behaved before v710.
        //
        // THIS CAN NEVER BLOCK A COMPENSATION. An unresolved amount records 0 and the mint
        // proceeds untouched -- the make-whole guarantee does not depend on knowing the price.
        $comp_rate     = 0.0;
        $comp_currency = 'XRP';
        $comp_issuer   = null;
        if (isset($captured_rate) && $captured_rate > 0) {
            $comp_rate     = $captured_rate;
            $comp_currency = ($captured_currency !== '') ? $captured_currency : 'XRP';
            $comp_issuer   = $captured_issuer;
        } elseif ($posted_paid_amount > 0) {
            // Layer 3 posts the matched group's TOTAL for that same quantity.
            $comp_rate     = $posted_paid_amount / max(1, $qty);
            $comp_currency = ($posted_paid_currency !== '') ? $posted_paid_currency : 'XRP';
        }
        $comp_total_paid = round($comp_rate * $qty, 8);
        // total_price_xrp is the XRP-denominated column; a token payment has no XRP value here.
        $comp_total_xrp  = ($comp_currency === 'XRP') ? $comp_total_paid : 0;
        mod_log("admin_compensate: recording rate={$comp_rate} {$comp_currency} x qty={$qty} "
            . "(total={$comp_total_paid})" . ($comp_rate > 0 ? '' : ' -- UNRESOLVED, recording 0 as before'));

        // Atomic reserve
        $wpdb->query('START TRANSACTION');
        $listing_locked = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $listings_table WHERE id = %d FOR UPDATE", $listing_id
        ), ARRAY_A);
        // v400: Open editions have total_editions=0 (unlimited) — skip supply cap check
        $is_oe_locked = (($listing_locked['edition_type'] ?? 'fixed') === 'open');
        if ($is_oe_locked) {
            $available_locked = 999999;
        } else {
            $available_locked = max(0,
                intval($listing_locked['total_editions']) - intval($listing_locked['minted_count']) - intval($listing_locked['reserved_count'])
            );
        }
        if ($available_locked < $qty) {
            $wpdb->query('ROLLBACK');
            json_error("Supply check failed inside transaction: available=$available_locked < qty=$qty");
        }
        $wpdb->query($wpdb->prepare(
            "UPDATE $listings_table SET reserved_count = reserved_count + %d, updated_at = %s WHERE id = %d",
            $qty, $now, $listing_id
        ));
        if (!$ac_reuse_gid) {   // F1: fresh group + rows (unchanged path)
        $wpdb->insert($groups_table, [
            'listing_id'             => $listing_id,
            'buyer_account'          => $buyer_account,
            'quantity'               => $qty,
            'total_price_xrp'        => $comp_total_xrp,
            'payment_currency'       => $comp_currency,
            'payment_currency_issuer'=> $comp_issuer,
            'payment_amount'         => $comp_total_paid,
            'payment_tx_hash'        => $source_tx_hash,
            'payment_verified'       => 1,
            'payment_verified_at'    => $now,
            'reservation_expires_at' => $expires_at,
            'status'                 => 'paid',
            'created_at'             => $now,
            'updated_at'             => $now
        ]);
        $group_id = $wpdb->insert_id;
        $purchase_ids = [];
        $insert_ok = true;
        for ($i = 0; $i < $qty; $i++) {
            $r = $wpdb->insert($purchases_table, [
                'group_id'        => $group_id,
                'listing_id'      => $listing_id,
                'buyer_account'   => $buyer_account,
                'artist_account'  => $listing['artist_account'],
                'edition_number'  => 0,
                'price_xrp'       => ($comp_currency === 'XRP') ? $comp_rate : 0,
                'price_currency'  => $comp_currency,
                'payment_tx_hash' => $source_tx_hash,
                'payment_verified'=> 1,
                'mint_status'     => 'paid',
                'error_message'   => 'COMPENSATION: ' . $reason,
                'created_at'      => $now,
                'updated_at'      => $now
            ]);
            if (!$r) { $insert_ok = false; break; }
            $purchase_ids[] = $wpdb->insert_id;
        }
        if (!$insert_ok) {
            $wpdb->query('ROLLBACK');
            json_error('Failed to insert purchase records: ' . $wpdb->last_error);
        }
        $wpdb->query('COMMIT');

        mod_log("admin_compensate: group=$group_id purchases=[" . implode(',', $purchase_ids) . "] buyer=$buyer_account qty=$qty source_tx=$source_tx_hash");
        } else {
            // F1: mint into the EXISTING group's rows. Every row not already complete is a unit of work.
            $group_id = $ac_reuse_gid;
            $purchase_ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM $purchases_table WHERE group_id = %d AND mint_status NOT IN ('minted','claimed','cancelled') ORDER BY id ASC", $group_id
            )));
            mod_log("admin_compensate: REUSE group=$group_id purchases=[" . implode(',', $purchase_ids) . "] buyer=$buyer_account qty=$qty source_tx=$source_tx_hash (F1)");
        }

        // ── Mint ──────────────────────────────────────────────────────────────
        $wpdb->update($groups_table, ['status' => 'minting', 'updated_at' => $now], ['id' => $group_id]);
        if (!acquire_xrpl_lock('imc_mint', 300)) {
            json_error("Could not acquire XRPL lock — server busy. Records committed as group=$group_id. Re-run to retry.");
        }

        // Get XRPL account sequence (required by mint_nft_onchain + create_sell_offer)
        $seq = get_account_sequence(IMC_PLATFORM_WALLET);
        if (!$seq) {
            release_xrpl_lock('imc_mint');
            json_error("Could not get XRPL account sequence — server busy. Records committed as group=$group_id. Re-run to retry.");
        }

        $is_tiered    = (bool)($listing['has_tiers'] ?? false);
        $minted_count = 0;
        if ($ac_reuse_gid) {   // F1: a partial group's complete rows count toward final_status
            $minted_count = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $purchases_table WHERE group_id = %d AND mint_status IN ('minted','claimed')", $group_id)));
        }
        $mint_errors  = [];
        $minted_data  = [];

        foreach ($purchase_ids as $purchase_id) {
            touch_xrpl_lock('imc_mint');   // v896 (O): heartbeat — qty up to 20 exceeds the 300s stale threshold
            $tier_incremented = false;
            $edition_assigned = false;
            $tier = null;
            $edition_reused = false;
            $ac_row = $ac_reuse_gid ? $wpdb->get_row($wpdb->prepare(
                "SELECT edition_number, edition_retained, nftoken_id, mint_tx_hash, tier_id, tier_name, metadata_ipfs FROM $purchases_table WHERE id = %d", $purchase_id
            ), ARRAY_A) : null;
            try {
                // F1 / v277 mirror: the NFT already exists (C-FIX bound it, or the offer failed after a good
                // mint). Never mint again -- create the sell offer and complete the row.
                if ($ac_row && !empty($ac_row['nftoken_id'])) {
                    $offer = create_sell_offer($ac_row['nftoken_id'], $buyer_account, $seq);
                    $seq++;
                    if (!$offer['success']) throw new \Exception('Sell offer recovery failed: ' . ($offer['error'] ?? $offer['result'] ?? 'unknown'));
                    $wpdb->update($purchases_table, [
                        'sell_offer_id'      => $offer['offer_id'],
                        'sell_offer_tx_hash' => $offer['hash'],
                        'mint_status'        => 'minted',
                        'minted_at'          => current_time('mysql'),
                        'updated_at'         => current_time('mysql')
                    ], ['id' => $purchase_id]);
                    $minted_count++;
                    $minted_data[] = ['id' => $purchase_id, 'edition' => intval($ac_row['edition_number']), 'tier' => $ac_row['tier_name'] ?: 'none', 'nftoken_id' => $ac_row['nftoken_id'], 'sell_offer_id' => $offer['offer_id']];
                    mod_log("admin_compensate: purchase=$purchase_id already on-chain nftoken={$ac_row['nftoken_id']} -- offer recovered, NOT re-minted (F1/v277)");
                    continue;
                }
                // ── 1. Pick random tier (inside DB transaction for row lock) ──
                if ($is_tiered) {
                    $wpdb->query('START TRANSACTION');
                    $tier = pick_random_tier($listing['id']);
                    if ($tier) {
                        $wpdb->query($wpdb->prepare("UPDATE $tiers_table SET minted_count = minted_count + 1 WHERE id = %d", $tier['id']));
                        $tier_incremented = true;
                    }
                    $wpdb->query('COMMIT');
                    if (!$tier) throw new \Exception('All tier slots exhausted');
                }

                // ── 2. Assign edition number (atomic via LAST_INSERT_ID) ──
                // v384: LAST_INSERT_ID(expr) stores the value per-connection, immune to
                // concurrent rollbacks or other connections modifying minted_count between
                // the UPDATE and the read. This prevents duplicate edition numbers.
                if ($ac_row && intval($ac_row['edition_retained']) === 1 && intval($ac_row['edition_number']) > 0) {
                    // F1 / M-ED-R mirror: this row's number was retained on a non-topmost failure. Reuse it;
                    // the allocator is skipped, so the counter is untouched (and must not be rolled back).
                    $edition = intval($ac_row['edition_number']);
                    $edition_assigned = true; $edition_reused = true;
                    mod_log("admin_compensate: REUSING retained edition $edition for purchase=$purchase_id (F1/M-ED-R)");
                } else {
                $is_oe_mint = (($listing['edition_type'] ?? 'fixed') === 'open');
                if ($is_oe_mint) {
                    $edition_rows = $wpdb->query($wpdb->prepare(
                        "UPDATE $listings_table SET minted_count = LAST_INSERT_ID(minted_count + 1) WHERE id = %d",
                        $listing_id
                    ));
                } else {
                    $edition_rows = $wpdb->query($wpdb->prepare(
                        "UPDATE $listings_table SET minted_count = LAST_INSERT_ID(minted_count + 1) WHERE id = %d AND minted_count < total_editions",
                        $listing_id
                    ));
                }
                if ($edition_rows === 0 || $edition_rows === false) throw new \Exception('Edition cap reached');
                $edition_assigned = true;
                $edition = intval($wpdb->get_var("SELECT LAST_INSERT_ID()"));
                }   // F1: end fresh-allocation branch

                // ── 3. Build metadata + pin to IPFS ──
                $metadata = build_edition_metadata($listing, $tier, $edition);
                $pin_name = sanitize_title($listing['nft_name']) . "-edition-{$edition}.json";
                $pin      = pin_metadata_to_ipfs($metadata, $pin_name);
                if (!$pin['success']) throw new \Exception('IPFS pin failed: ' . ($pin['error'] ?? 'unknown'));

                $metadata_uri  = 'ipfs://' . $pin['hash'];
                $metadata_ipfs = $pin['hash'];

                // ── 4. Mint NFT on XRPL ──
                $mint = mint_nft_onchain(
                    $listing['artist_account'],
                    $metadata_uri,
                    intval($listing['collection_taxon']),
                    intval($listing['transfer_fee']),
                    (bool)$listing['is_transferable'],
                    $seq
                );
                $seq++;
                if (!$mint['success']) throw new \Exception('XRPL mint failed: ' . ($mint['error'] ?? $mint['result'] ?? 'unknown'));
                if (!$mint['nftoken_id']) throw new \Exception('Mint succeeded but NFTokenID not found');

                // v594 token-uniqueness backstop: refuse a mis-extracted token already on another purchase.
                $__dup = imc_nftoken_collision_owner($mint['nftoken_id'], $purchase_id);
                if ($__dup) throw new \Exception("NFTokenID {$mint['nftoken_id']} already assigned to purchase $__dup — refusing to save (extraction collision)");

                // Save nftoken_id immediately (double-mint guard)
                $wpdb->update($purchases_table, [
                    'nftoken_id'   => $mint['nftoken_id'],
                    'mint_tx_hash' => $mint['hash'],
                    'updated_at'   => current_time('mysql')
                ], ['id' => $purchase_id]);

                // ── 5. Create sell offer (0 XRP, destination = buyer) ──
                $offer = create_sell_offer($mint['nftoken_id'], $buyer_account, $seq);
                $seq++;
                if (!$offer['success']) throw new \Exception('Sell offer failed: ' . ($offer['error'] ?? $offer['result'] ?? 'unknown'));

                // ── 6. Update purchase record ──
                $__bd = [
                    'edition_number'     => $edition,
                    'tier_id'            => $tier['id'] ?? null,
                    'tier_name'          => $tier['tier_name'] ?? null,
                    'metadata_ipfs'      => $metadata_ipfs,
                    'nftoken_id'         => $mint['nftoken_id'],
                    'mint_tx_hash'       => $mint['hash'],
                    'sell_offer_id'      => $offer['offer_id'],
                    'sell_offer_tx_hash' => $offer['hash'],
                    'mint_status'        => 'minted',
                    'minted_at'          => current_time('mysql'),
                    'updated_at'         => current_time('mysql')
                ];
                $__bw = $wpdb->update($purchases_table, $__bd, ['id' => $purchase_id]);
                if ($__bw === false && imc_b_refused()) {   // B POST-mint: NFT exists; bind it, never claim the edition twice
                    imc_b_dup('admin_compensate', $purchase_id, $group_id, $listing_id, $__bd['edition_number'], $mint['nftoken_id']);
                    $__bd['error_message'] = 'B-DUP: minted on-chain as edition ' . $__bd['edition_number'] . ' but that edition was already live -- edition recorded as 0, reconcile metadata';
                    $__bd['edition_number'] = 0;
                    $wpdb->update($purchases_table, $__bd, ['id' => $purchase_id]);
                }

                $minted_count++;
                $minted_data[] = [
                    'id'            => $purchase_id,
                    'edition'       => $edition,
                    'tier'          => $tier['tier_name'] ?? 'none',
                    'nftoken_id'    => $mint['nftoken_id'],
                    'sell_offer_id' => $offer['offer_id'],
                ];
                mod_log("admin_compensate: minted purchase=$purchase_id edition=$edition nftoken={$mint['nftoken_id']} offer={$offer['offer_id']}");

            } catch (\Exception $e) {
                $mint_errors[] = "purchase_id=$purchase_id: " . $e->getMessage();
                mod_log("admin_compensate: ERROR purchase=$purchase_id: " . $e->getMessage());
                // v384: Only rollback counts if the NFT was NOT already minted on-chain.
                // After mint_nft_onchain succeeds (Step 4), the nftoken_id is saved to DB (Step 5b).
                // If the sell offer (Step 5) fails, the NFT still exists on-chain permanently —
                // rolling back minted_count/tier_count would free an edition+tier that are already consumed,
                // causing duplicate edition numbers and duplicate tier images on future mints.
                $nft_on_chain = !empty($wpdb->get_var($wpdb->prepare(
                    "SELECT nftoken_id FROM $purchases_table WHERE id = %d", $purchase_id
                )));
                if ($edition_assigned && $edition_reused && !$nft_on_chain) {
                    // F1: a reused (retained) number never touched the counter -- keep it retained for the
                    // next attempt, exactly as vps_mint_failed's gap branch does. Never roll the counter back.
                    $wpdb->update($purchases_table, ['edition_retained' => 1, 'updated_at' => current_time('mysql')], ['id' => $purchase_id]);
                    mod_log("admin_compensate: edition $edition RETAINED on purchase=$purchase_id (reused, not on-chain) -- counter untouched (F1)");
                } elseif ($edition_assigned && !$nft_on_chain) {
                    $wpdb->query($wpdb->prepare(
                        "UPDATE $listings_table SET minted_count = GREATEST(0, minted_count - 1) WHERE id = %d", $listing_id
                    ));
                    mod_log("admin_compensate: Rolled back minted_count (NFT not on-chain)");
                } elseif ($nft_on_chain) {
                    mod_log("admin_compensate: NOT rolling back minted_count — NFT exists on-chain for purchase=$purchase_id");
                }
                if ($tier_incremented && $tier && !$nft_on_chain) {
                    $wpdb->query($wpdb->prepare(
                        "UPDATE $tiers_table SET minted_count = GREATEST(0, minted_count - 1) WHERE id = %d", $tier['id']
                    ));
                    mod_log("admin_compensate: Rolled back tier minted_count (NFT not on-chain)");
                } elseif ($nft_on_chain) {
                    mod_log("admin_compensate: NOT rolling back tier minted_count — NFT exists on-chain for purchase=$purchase_id");
                }
                $wpdb->update($purchases_table, [
                    'mint_status'   => 'failed',
                    'error_message' => 'COMP FAILED: ' . substr($e->getMessage(), 0, 480),
                    'updated_at'    => current_time('mysql')
                ], ['id' => $purchase_id]);
            }
        }

        release_xrpl_lock('imc_mint');

        $final_status = $minted_count === $qty ? 'minted' : ($minted_count > 0 ? 'partial' : 'failed');
        $wpdb->update($groups_table, ['status' => $final_status, 'mint_progress' => $minted_count, 'updated_at' => current_time('mysql')], ['id' => $group_id]);
        imc_release_reservation($group_id, $listing_id, $qty, 'admin-compensate-finish');

        json_success([
            'group_id'     => $group_id,
            'minted'       => $minted_count,
            'failed'       => count($mint_errors),
            'group_status' => $final_status,
            'purchases'    => $minted_data,
            'errors'       => $mint_errors
        ]);
    }

    // ────────────────────────────────────────────────────────────────────
    // RESERVE PURCHASE (Task #2 + #3 + #12 + #16)
    // Atomic reservation: lock → check availability → reserve → create
    // XUMM payment payload → return QR code
    // ────────────────────────────────────────────────────────────────────
    if ($action === 'reserve_purchase') {
        mod_log("reserve_purchase: Starting");

        $listing_id = intval($_POST['listing_id'] ?? 0);
        $buyer      = sanitize_text_field($_POST['buyer_account'] ?? '');
        $qty        = max(1, min(IMC_MAX_PER_MINT, intval($_POST['quantity'] ?? 1)));
        
        // v71: Accept payment currency (default to XRP)
        $payment_currency = strtoupper(sanitize_text_field($_POST['payment_currency'] ?? 'XRP'));

        if (!$listing_id || !$buyer) json_error('Missing listing_id or buyer_account');

        // v242: Validate buyer is a well-formed XRPL address. A malformed address
        // would pass sanitize_text_field but cause NFTokenCreateOffer to fail
        // on-chain after the NFT is already minted — unrecoverable.
        if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $buyer)) {
            json_error('Invalid buyer wallet address');
        }

        // --- Task #16: Self-purchase prevention ---
        $listing_check = $wpdb->get_var($wpdb->prepare(
            "SELECT artist_account FROM $listings_table WHERE id = %d",
            $listing_id
        ));
        if ($listing_check === $buyer) {
            json_error('You cannot purchase your own listing');
        }

        // --- Task #12: Forced claim check (same collection) ---
        // v294 FIX: Before blocking the buyer, reconcile each "unclaimed" purchase
        // against the XRPL ledger. A user who claimed their NFT directly in Xaman
        // (or via another marketplace) will have delivered=0 in our DB even though
        // the sell offer is already gone from the ledger. Without this reconciliation
        // the buyer is permanently blocked from minting again even though they
        // correctly own their NFT. We make at most one ledger_entry call per
        // apparent unclaimed item to keep latency low.
        $collection_info = $wpdb->get_row($wpdb->prepare(
            "SELECT collection_taxon, artist_account FROM $listings_table WHERE id = %d",
            $listing_id
        ), ARRAY_A);

        if ($collection_info) {
            $apparent_unclaimed = $wpdb->get_results($wpdb->prepare(
                "SELECT p.id, p.sell_offer_id FROM $purchases_table p
                 JOIN $listings_table l ON p.listing_id = l.id
                 WHERE p.buyer_account = %s
                 AND l.collection_taxon = %d
                 AND l.artist_account = %s
                 AND p.mint_status = 'minted'
                 AND p.delivered = 0",
                $buyer,
                $collection_info['collection_taxon'],
                $collection_info['artist_account']
            ), ARRAY_A);

            $truly_unclaimed = 0;
            if ($apparent_unclaimed) {
                $reconcile_now = current_time('mysql');
                foreach ($apparent_unclaimed as $ap) {
                    if (empty($ap['sell_offer_id'])) {
                        // No offer ID stored — treat as genuinely unclaimed
                        $truly_unclaimed++;
                        continue;
                    }
                    // Check if the sell offer still exists on the ledger
                    $ledger_check = xrpl_rpc('ledger_entry', [
                        'nft_offer'    => $ap['sell_offer_id'],
                        'ledger_index' => 'validated'
                    ]);
                    $offer_gone = isset($ledger_check['error']) && $ledger_check['error'] === 'entryNotFound';
                    if ($offer_gone) {
                        // Offer consumed — buyer already claimed externally. Mark delivered.
                        // v515: unified claim bookkeeping (purchase + group rollup)
                        imc_mark_purchase_claimed($ap['id']);
                        mod_log("reserve_purchase: Reconciled purchase {$ap['id']} as delivered (offer gone from ledger — claimed externally by buyer=$buyer)");
                    } else {
                        $truly_unclaimed++;
                    }
                }
            }

            if ($truly_unclaimed > 0) {
                json_error("You have {$truly_unclaimed} unclaimed NFT(s) from this collection. Please claim them before minting more.");
            }
        }

        // v76: Mint limit per wallet enforcement
        $listing_for_limit = $wpdb->get_row($wpdb->prepare(
            "SELECT mint_limit_enabled, mint_limit_per_wallet FROM $listings_table WHERE id = %d",
            $listing_id
        ), ARRAY_A);
        
        if (!empty($listing_for_limit['mint_limit_enabled']) && $listing_for_limit['mint_limit_per_wallet'] > 0) {
            $max_per_wallet = intval($listing_for_limit['mint_limit_per_wallet']);
            
            // Count in-flight + completed purchases by this wallet for this listing.
            // v244: Added 'pending' (reservation created but not yet paid) to prevent
            // a concurrent two-tab race where both tabs pass the limit check before
            // either commits its reservation. Aligns with the allowlist limit query (line ~2135).
            // v277: Include 'failed' in the count — a user whose mint failed still holds
            // a reserved slot and should retry that group rather than create a new purchase.
            // Excluding 'failed' allowed wallets to bypass per-wallet limits by retrying.
            // v891: unchanged semantics — this query WAS the strictest of the three and is now
            // the shared definition. Behaviour here is byte-equivalent to before.
            $already_minted = imc_wallet_holdings($listing_id, $buyer);
            
            $already_minted = intval($already_minted);
            $remaining = $max_per_wallet - $already_minted;
            
            if ($remaining <= 0) {
                json_error("You've reached the mint limit of {$max_per_wallet} per wallet for this listing.");
            }
            
            // Cap qty to remaining allowance
            if ($qty > $remaining) {
                mod_log("reserve_purchase: Capped qty from $qty to $remaining (mint limit reached)");
                $qty = $remaining;
            }
        }

        // ══════════════════════════════════════════════════════════════════════════
        // v885 PRE-MINT GATES (Defects AL / B / AK)
        //
        // PLACEMENT IS LOAD-BEARING: these make network calls, and the v292 law (see the
        // stale-payload comment immediately below) forbids network I/O inside the FOR UPDATE
        // transaction -- holding a row lock across HTTP serialises every concurrent buyer.
        // START TRANSACTION is still ~130 lines further down, so nothing here can strand a
        // reservation: no slot has been taken and no rollback is required.
        //
        // ALL THREE FAIL OPEN on RPC error. A flaky node must never block a live drop -- only a
        // POSITIVE bad signal refuses.
        // ══════════════════════════════════════════════════════════════════════════

        // ── AL (SOFT): reject a positive identity MISMATCH; allow when no session resolves. ──
        // Deliberately soft. IMC_SESSION_TOKEN_ONLY is true in production, so require_wallet no
        // longer falls back to the soft xrpl_account cookie; a HARD check would lock out exactly
        // the users affected by the open session-persistence work (stuck token, live soft cookie).
        // A mismatch, by contrast, cannot be a legitimate buyer -- that is a spoofed account.
        if (function_exists('imc_session_require_wallet')) {
            $imc_id = imc_session_require_wallet($buyer);
            if (empty($imc_id['ok']) && ($imc_id['error'] ?? '') === 'mismatch') {
                mod_log("reserve_purchase: BLOCKED identity mismatch -- session=" . ($imc_id['wallet'] ?? '?') . " posted=$buyer");
                json_error('Wallet session does not match the requested account', 403);
            }
            if (empty($imc_id['ok'])) {
                mod_log("reserve_purchase: no session resolved for posted buyer=$buyer (ALLOWED, logged)");
            }
        }

        // ── B: the buyer's wallet must be able to RECEIVE an NFT offer. ──
        // lsfDisallowIncomingNFTokenOffer (0x04000000) makes the ledger reject the sell offer
        // with tecNO_PERMISSION AFTER the NFT is minted -- edition consumed, NFT stranded in the
        // platform wallet, buyer with nothing. This was the root cause of a minority of the failures on
        // an affected listing. An unactivated account fails the same way. Refuse here, before allocation.
        $imc_bi   = xrpl_rpc('account_info', ['account' => $buyer, 'ledger_index' => 'validated']);
        $imc_berr = $imc_bi['error'] ?? '';
        if ($imc_berr === 'actNotFound') {
            mod_log("reserve_purchase: BLOCKED buyer=$buyer -- account not activated on the XRPL");
            json_error('Your wallet is not activated on the XRP Ledger yet. Fund it with the minimum reserve, then try again.');
        }
        if ($imc_berr === '') {
            $imc_bflags = intval($imc_bi['account_data']['Flags'] ?? 0);
            if ($imc_bflags & 0x04000000) {
                mod_log("reserve_purchase: BLOCKED buyer=$buyer -- lsfDisallowIncomingNFTokenOffer set (flags=$imc_bflags)");
                json_error('Your wallet has "Disallow incoming NFT offers" enabled. Please turn that setting off in your wallet, then try again.');
            }
        } else {
            // v886: log the FAIL-OPEN branch. Without this a silent gate is indistinguishable
            // from a gate that ran and passed -- so a persistently unreachable node would look
            // exactly like a clean bill of health for every buyer.
            mod_log("reserve_purchase: buyer flag-check UNAVAILABLE for $buyer (rpc error: $imc_berr) -- failing OPEN, mint allowed");
        }

        // ── AK: the issuer must STILL have us as authorised minter. ──
        // Same error code as B, one step EARLIER and with the opposite blast radius: if the
        // artist clears NFTokenMinter, the NFTokenMint ITSELF fails tecNO_PERMISSION (no NFT is
        // created). Nothing checked this before -- not at publish, not at reserve, not mid-drop --
        // which is how an affected listing stranded buyers a week earlier.
        // Cached 60s per issuer so a 200-edition rush makes ~1 call/min, not 200.
        $imc_art = $wpdb->get_var($wpdb->prepare(
            "SELECT artist_account FROM $listings_table WHERE id = %d", $listing_id
        ));
        if (!empty($imc_art) && $imc_art !== IMC_PLATFORM_WALLET) {
            $imc_ck_key = 'imc_minter_ok_' . md5($imc_art);
            $imc_ck     = get_transient($imc_ck_key);
            if ($imc_ck === false) {
                $imc_ai = xrpl_rpc('account_info', ['account' => $imc_art, 'ledger_index' => 'validated']);
                if (empty($imc_ai['error'])) {
                    $imc_ck = (($imc_ai['account_data']['NFTokenMinter'] ?? '') === IMC_PLATFORM_WALLET) ? '1' : '0';
                    set_transient($imc_ck_key, $imc_ck, 60);
                } else {
                    // v886: fail-open, but say so. NOT cached -- a transient RPC failure must not
                    // suppress the check for the following 60 seconds.
                    mod_log("reserve_purchase: issuer minter-check UNAVAILABLE for $imc_art (rpc error: " . $imc_ai['error'] . ") -- failing OPEN, mint allowed");
                }
            }
            if ($imc_ck === '0') {
                mod_log("reserve_purchase: BLOCKED listing=$listing_id -- issuer $imc_art no longer authorises " . IMC_PLATFORM_WALLET . " as NFTokenMinter");
                json_error("This collection's minting authorisation has changed. Minting is paused while the creator re-authorises.", 409);
            }
        }

        // --- v293 FIX: STALE PAYLOAD CANCELLATION (outside transaction) ---
        // CRITICAL: This block was previously inside the FOR UPDATE transaction (v292).
        // cancel_xumm_payload() is an external HTTP call with a 10s timeout. Holding
        // a row-level FOR UPDATE lock during an HTTP call serialises ALL concurrent
        // buyers behind this one request — killing throughput on busy launches.
        // Moved here, before START TRANSACTION, so the lock is never held during I/O.
        //
        // Why this block exists: when a buyer abandons a Xaman request and creates
        // a new reservation, the old Xaman payload stays live in their app. If they
        // later sign it, the payment lands on-chain with no active poll loop watching
        // → orphaned payment (orphaned by the same race).
        $now = current_time('mysql');

        $stale_groups_pre = $wpdb->get_results($wpdb->prepare(
            "SELECT id, quantity, payment_xumm_uuid FROM $groups_table
             WHERE buyer_account = %s
               AND listing_id    = %d
               AND status        = 'reserved'
               AND payment_xumm_uuid IS NOT NULL
               AND payment_xumm_uuid != ''
             ORDER BY id DESC
             LIMIT 5",   // M7a: was unbounded -- a wallet holding N open reservations cost N XUMM polls PER CLICK (M4 4e: high concurrency produced 429s)
            $buyer, $listing_id
        ), ARRAY_A);

        foreach ($stale_groups_pre as $stale) {
            $stale_id   = $stale['id'];
            $stale_uuid = $stale['payment_xumm_uuid'];
            $stale_qty  = intval($stale['quantity']);

            // v640 GUARD (additive): never void a stale reservation whose payload was
            // already signed -- the buyer paid it; voiding here strands the payment
            // (root cause of the expired-yet-paid cases). Mirrors cancel_reservation's
            // signed-guard. Fail-safe: skip ONLY on a clear signed poll; any other
            // result falls through to the unchanged void path below, and cleanup()
            // self-heal then promotes and delivers it.
            if (!empty($stale_uuid)) {
                // M7a: DB first -- if the webhook already recorded this payload as signed+dispatched, honour the
                // v640 guard without a XUMM call. Same semantics (skip the cancel), zero budget.
                if ($wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->prefix}xumm_status WHERE uuid = %s AND signed = 1 AND tx_hash IS NOT NULL AND tx_hash <> ''", $stale_uuid))) {
                    mod_log("reserve_purchase: SKIP stale-cancel group=$stale_id uuid=$stale_uuid -- payload signed per webhook record (M7a, no XUMM call)");
                    continue;
                }
                $stale_signed_pl = poll_xumm($stale_uuid);
                if (is_array($stale_signed_pl) && !empty($stale_signed_pl['meta']['signed'])) {
                    mod_log("reserve_purchase: SKIP stale-cancel group=$stale_id uuid=$stale_uuid -- payload already signed (paid); left reserved for cleanup self-heal to promote and deliver");
                    continue;
                }
            }

            // Cancel Xaman payload (HTTP call — safe here, outside transaction)
            $cancelled = cancel_xumm_payload($stale_uuid);

            // Expire the group
            $wpdb->update($groups_table,
                ['status' => 'expired', 'updated_at' => $now],
                ['id' => $stale_id]
            );

            // v293 FIX: Release the reserved_count slots held by this stale group.
            // Previously omitted, causing available_editions to read lower than
            // actual until the next cleanup() run — could trigger spurious
            // "Only N available" errors on high-traffic launches.
            // NOTE: releases a DIFFERENT group ($stale_id), not the one being reserved.
            imc_release_reservation($stale_id, $listing_id, $stale_qty, 'reserve-stale-cleanup');

            $wpdb->query($wpdb->prepare(
                "UPDATE $purchases_table SET mint_status = 'cancelled', updated_at = %s WHERE group_id = %d AND mint_status = 'pending'",
                $now, $stale_id
            ));

            mod_log("reserve_purchase: Cancelled stale group=$stale_id uuid=$stale_uuid qty=$stale_qty for buyer=$buyer — " .
                    ($cancelled ? 'Xaman payload voided' : 'Xaman payload already resolved') .
                    ' — reserved_count released');
        }

        // ── v713 (transfer fee): resolve the issuer's TransferRate BEFORE the transaction ──
        //
        // WHY IT IS HERE AND NOT LOWER DOWN: this makes an XRPL call. The v293 block a few
        // lines above exists because an HTTP call was once made INSIDE the FOR UPDATE
        // transaction (v292) and serialised every concurrent buyer behind one request. The
        // same rule applies here -- the lock must never be held during network I/O, so the
        // read happens now and only arithmetic runs inside the transaction.
        //
        // WHY IT MATTERS AT ALL: on the XRPL, `Amount` is what the DESTINATION receives and
        // the sender is debited `Amount x TransferRate`. A token whose issuer charges a
        // transfer fee therefore cannot be paid unless SendMax covers the fee, which is why
        // PAX (0.33% burn) failed every attempt with tecPATH_PARTIAL regardless of balance.
        //
        // FAIL FAST, BEFORE ANY SLOT IS TAKEN: nothing above increments reserved_count (the
        // only earlier reference is the v293 RELEASE of a stale group), and the transaction
        // has not opened, so json_error() here costs the buyer nothing -- no reservation to
        // wait out before retrying, and no ROLLBACK required.
        $imc_fee_rate = 1.0;
        if ($payment_currency !== 'XRP') {
            // Non-locking read purely to find the issuer for the chosen currency. Mirrors the
            // authoritative parse inside the transaction (skip disabled entries, match on
            // currency or currency_hex). Plain SELECTs in this pre-transaction region are
            // already the established pattern -- see the artist/taxon/mint-limit reads above.
            $imc_tf_issuer = null;
            $imc_tf_row = $wpdb->get_var($wpdb->prepare(
                "SELECT accepted_currencies FROM $listings_table WHERE id = %d", $listing_id
            ));
            if (!empty($imc_tf_row)) {
                $imc_tf_acc = json_decode($imc_tf_row, true);
                if (is_array($imc_tf_acc)) {
                    foreach ($imc_tf_acc as $imc_tf_c) {
                        if (isset($imc_tf_c['enabled']) && !$imc_tf_c['enabled']) continue;
                        $imc_tf_code = strtoupper($imc_tf_c['currency'] ?? '');
                        if ($imc_tf_code === $payment_currency
                            || (isset($imc_tf_c['currency_hex']) && strtoupper($imc_tf_c['currency_hex']) === $payment_currency)) {
                            $imc_tf_issuer = $imc_tf_c['issuer'] ?? null;
                            break;
                        }
                    }
                }
            }

            // DELIBERATELY SILENT when the currency or issuer is not found here. The
            // in-transaction validation owns those messages -- "Currency X not accepted for
            // this listing" and "X is not available for this listing" -- and pre-empting them
            // would change error semantics buyers and support already recognise. We only act
            // when we actually have an issuer to ask about.
            if (!empty($imc_tf_issuer) && class_exists('IMC_Price_Oracle')) {
                $imc_fee_rate = IMC_Price_Oracle::get_transfer_rate($imc_tf_issuer);
                if ($imc_fee_rate === null) {
                    // Unreadable or out-of-spec. Never guess: assuming "no fee" is exactly what
                    // builds the unsignable payment this change exists to prevent.
                    mod_log("reserve_purchase: transfer rate unreadable for $payment_currency issuer=$imc_tf_issuer listing=$listing_id — refusing before reservation");
                    json_error('Issuer Fee Misconfigured, Please Try Again');
                }
                if ($imc_fee_rate > 1.0) {
                    mod_log("reserve_purchase: $payment_currency issuer transfer fee " . round(($imc_fee_rate - 1) * 100, 4) . "% (rate {$imc_fee_rate})");
                }
            }
        }

        // --- Atomic reservation with FOR UPDATE lock ---
        // v41 fix: Use WordPress timezone consistently (was using PHP date() — timezone mismatch with cleanup)
        $expires_at = date('Y-m-d H:i:s', strtotime($now . ' +' . IMC_RESERVATION_TTL . ' seconds'));

        $wpdb->query('START TRANSACTION');

        $listing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $listings_table WHERE id = %d AND status = 'active' FOR UPDATE",
            $listing_id
        ), ARRAY_A);

        if (!$listing) {
            $wpdb->query('ROLLBACK');
            json_error('Listing not found or not active');
        }

        // OE-v1: Open Edition window check (inside FOR UPDATE transaction — race-safe)
        if (($listing['edition_type'] ?? 'fixed') === 'open') {
            // Reject if already explicitly closed (by cron, early close, or prior auto-close)
            if (!empty($listing['open_edition_closed_at'])) {
                $wpdb->query('ROLLBACK');
                json_error('This mint has closed. No new purchases are available.', 403);
            }
            // Check if mint window has expired
            if (!empty($listing['open_edition_ends_at'])) {
                $ends_ts = strtotime($listing['open_edition_ends_at']);
                if (time() > $ends_ts) {
                    // Atomic auto-close: set open_edition_closed_at and status=sold_out,
                    // then COMMIT so display is immediately correct, then return error.
                    // v393: Also lock total_editions = minted_count for correct post-close display
                    $wpdb->query($wpdb->prepare(
                        "UPDATE $listings_table SET open_edition_closed_at = %s, total_editions = minted_count, status = 'sold_out', updated_at = %s WHERE id = %d",
                        current_time('mysql'), current_time('mysql'), $listing['id']
                    ));
                    $wpdb->query('COMMIT');
                    json_error('Mint window has closed. No new purchases are available.', 403);
                }
            }
        }

        // Check launch date
        if ($listing['launch_type'] === 'scheduled' && !empty($listing['launch_at'])) {
            // v473: explicit-UTC parsing — see launch_at_to_unix()
            if (launch_at_to_unix($listing['launch_at']) > time()) {
                // v67: Check if buyer has early access via allowlist
                $allowlists_table = $wpdb->prefix . 'imc_allowlists';
                $entries_table = $wpdb->prefix . 'imc_allowlist_entries';
                
                $early_access = $wpdb->get_var($wpdb->prepare(
                    "SELECT MAX(a.early_access_hours) FROM $allowlists_table a
                     JOIN $entries_table e ON e.allowlist_id = a.id
                     WHERE a.listing_id = %d AND e.wallet_address = %s 
                     AND a.type = 'early_access' AND a.is_active = 1",
                    $listing_id, $buyer
                ));
                
                if ($early_access) {
                    // Check if within early access window
                    // v473: explicit-UTC parsing
                    $launch_time = launch_at_to_unix($listing['launch_at']);
                    $early_window_start = $launch_time - ($early_access * 3600);
                    
                    if (time() < $early_window_start) {
                        $wpdb->query('ROLLBACK');
                        json_error('This listing has not launched yet (early access starts ' . date('M j, g:i A', $early_window_start) . ')');
                    }
                    mod_log("reserve_purchase: Buyer $buyer has early access ($early_access hours)");
                } else {
                    $wpdb->query('ROLLBACK');
                    json_error('This listing has not launched yet');
                }
            }
        }
        
        // v67: Check exclusive allowlist
        $allowlists_table = $wpdb->prefix . 'imc_allowlists';
        $entries_table = $wpdb->prefix . 'imc_allowlist_entries';
        
        $has_exclusive = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $allowlists_table 
             WHERE listing_id = %d AND type = 'exclusive' AND is_active = 1",
            $listing_id
        ));
        
        if (intval($has_exclusive) > 0) {
            // Check if buyer is on any exclusive allowlist
            $is_on_exclusive = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $allowlists_table a
                 JOIN $entries_table e ON e.allowlist_id = a.id
                 WHERE a.listing_id = %d AND e.wallet_address = %s 
                 AND a.type = 'exclusive' AND a.is_active = 1",
                $listing_id, $buyer
            ));
            
            if (intval($is_on_exclusive) === 0) {
                $wpdb->query('ROLLBACK');
                json_error('This listing is exclusive to allowlisted wallets only');
            }
            mod_log("reserve_purchase: Buyer $buyer verified on exclusive allowlist");
        }
        
        // v67: Get allowlist benefits (discount, custom price, wallet limit)
        $allowlist_benefits = [
            'discount_percent' => 0,
            'custom_price' => null,
            'max_mint' => null,
            'minted_count' => 0,
            'discount_allocation' => 0  // v381: SUM of per-wallet quantities from discount entries
        ];
        $imc_allowlist_price_applied = false; // v728: did the FINAL price use an allowlist benefit?
        $imc_non_discount_price = null;       // v728: best per-entry custom_price from NON-discount list types
        $imc_currency_rules = [];             // v731: per-token rules, keyed by currency (buyer-best applies)
        
        $al_entries = $wpdb->get_results($wpdb->prepare(
            "SELECT e.*, a.type, a.discount_percent, a.custom_price as allowlist_custom_price, a.max_mint_override, a.allowlist_holder_limit, a.currency_benefits FROM $allowlists_table a
             JOIN $entries_table e ON e.allowlist_id = a.id
             WHERE a.listing_id = %d AND e.wallet_address = %s AND a.is_active = 1",
            $listing_id, $buyer
        ), ARRAY_A);
        
        $has_unlimited_discount = false; // v382: Track if any regular 'discount' (unlimited) entries exist
        foreach ($al_entries as $ae) {
            $allowlist_benefits['minted_count'] = max($allowlist_benefits['minted_count'], intval($ae['minted_count']));
            
            // v381: Both 'discount' and 'discount_limited' provide pricing benefits
            if ($ae['type'] === 'discount' || $ae['type'] === 'discount_limited') {
                if ($ae['type'] === 'discount') $has_unlimited_discount = true; // v382
                // v253: allowlist-level custom_price takes priority over percentage discount
                if ($ae['allowlist_custom_price'] !== null) {
                    $cp = floatval($ae['allowlist_custom_price']);
                    if ($allowlist_benefits['custom_price'] === null || $cp < $allowlist_benefits['custom_price']) {
                        $allowlist_benefits['custom_price'] = $cp;
                    }
                } else {
                    $disc = $ae['custom_discount'] !== null ? floatval($ae['custom_discount']) : floatval($ae['discount_percent']);
                    $allowlist_benefits['discount_percent'] = max($allowlist_benefits['discount_percent'], $disc);
                }
                // v731 (A4b): collect per-token rules from every discount-type list
                if (IMC_ALLOWLIST_CURRENCY_MATRIX && !empty($ae['currency_benefits'])) {
                    $imc_rr = json_decode($ae['currency_benefits'], true);
                    if (is_array($imc_rr)) {
                        foreach ($imc_rr as $imc_r) {
                            if (!isset($imc_r['currency'], $imc_r['mode'], $imc_r['value'])) continue;
                            $imc_currency_rules[strtoupper($imc_r['currency'])][] = ['mode' => $imc_r['mode'], 'value' => floatval($imc_r['value'])];
                        }
                    }
                }
                // v381: Only discount_limited tracks allocation (regular discount stays unlimited)
                if ($ae['type'] === 'discount_limited' && $ae['custom_max_mint'] !== null && intval($ae['custom_max_mint']) > 0) {
                    // ⚠ CLAMP PER ROW, NEVER ON THE RUNNING TOTAL. One row per ENTRY
                    // across ALL of the listing's active allowlists, so a wallet on two
                    // allowlists yields two rows. Capping the accumulator would let one
                    // allowlist's ceiling silently constrain another's.
                    $allowlist_benefits['discount_allocation'] += imc_al_holder_cap(intval($ae['custom_max_mint']), $ae['allowlist_holder_limit']);
                }
            }
            
            // v381: Type-isolated SUM for limit_override (was max — now sums across multi-collection allowlists)
            if ($ae['type'] === 'limit_override') {
                // v381 sums across entries; the ceiling clamps each row BEFORE that
                // sum, leaving the v381 semantics untouched.
                $lim = $ae['custom_max_mint'] !== null ? intval($ae['custom_max_mint']) : intval($ae['max_mint_override']);
                $lim = imc_al_holder_cap($lim, $ae['allowlist_holder_limit']);
                if ($lim > 0) {
                    $allowlist_benefits['max_mint'] = ($allowlist_benefits['max_mint'] ?? 0) + $lim;
                }
            }
            
            if ($ae['custom_price'] !== null) {
                $cp = floatval($ae['custom_price']);
                if ($allowlist_benefits['custom_price'] === null || $cp < $allowlist_benefits['custom_price']) {
                    $allowlist_benefits['custom_price'] = $cp;
                }
                // v728: remember the best price granted by a NON-discount list type, so
                // discount exhaustion can't wipe a price the wallet holds independently.
                if (!in_array($ae['type'], ['discount', 'discount_limited'])) {
                    if ($imc_non_discount_price === null || $cp < $imc_non_discount_price) $imc_non_discount_price = $cp;
                }
            }
        }
        
        // v382: Time-aware discount graduation — handles exhaustion, batch qty, and early access blocking
        if ($allowlist_benefits['discount_allocation'] > 0) {
            // v728 (A2/R1): entries.minted_count is only incremented at MINT time, so two
            // concurrent PAID discounted reservations could both pass this check (the v412
            // guard closed this for free mints only). With the flag on, in-flight + settled
            // purchases from allowlist-priced groups also count against the allocation.
            $imc_allocation_used = intval($allowlist_benefits['minted_count']);
            if (IMC_ALLOWLIST_SCOPED_CONSUME) {
                $imc_tagged_used = intval($wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $purchases_table p
                     JOIN $groups_table g ON p.group_id = g.id
                     WHERE p.listing_id = %d AND g.buyer_account = %s   -- 4k: MINTER, not current owner
                       AND g.allowlist_qty > 0
                       AND p.mint_status NOT IN ('cancelled')",
                    $listing_id, $buyer
                )));
                if ($imc_tagged_used > $imc_allocation_used) $imc_allocation_used = $imc_tagged_used;
            }
            $discount_remaining = $allowlist_benefits['discount_allocation'] - $imc_allocation_used;
            
            if ($discount_remaining <= 0 && !$has_unlimited_discount) {
                // Allocation fully exhausted — check if we're still in early access period
                // v473: explicit-UTC parsing
                $is_before_launch = ($listing['launch_type'] === 'scheduled' && !empty($listing['launch_at']) && launch_at_to_unix($listing['launch_at']) > time());
                
                if ($is_before_launch) {
                    // v382: During early access, BLOCK — holder must wait for public launch
                    // v473: explicit-UTC parsing
                    $launch_display = date('M j, g:i A', launch_at_to_unix($listing['launch_at']));
                    $wpdb->query('ROLLBACK');
                    mod_log("reserve_purchase: Buyer $buyer discount allocation exhausted during early access ({$allowlist_benefits['minted_count']}/{$allowlist_benefits['discount_allocation']}) — blocked until public launch at {$listing['launch_at']}");
                    json_error("You've used all your early access discounted mints. Public minting opens at {$launch_display}.");
                } else {
                    // After public launch — graduate to full price (not blocked)
                    mod_log("reserve_purchase: Buyer $buyer discount allocation exhausted ({$imc_allocation_used}/{$allowlist_benefits['discount_allocation']}) — reverting to full price");
                    // v728: with the flag on, only DISCOUNT-sourced prices are removed — a
                    // per-entry price granted by another list type on this wallet survives.
                    $allowlist_benefits['custom_price'] = IMC_ALLOWLIST_SCOPED_CONSUME ? $imc_non_discount_price : null;
                    $allowlist_benefits['discount_percent'] = 0;
                    $imc_currency_rules = []; // v731: per-token rules are discount-sourced — cleared on graduation
                }
            } elseif ($discount_remaining > 0 && $qty > $discount_remaining) {
                // v382: Batch qty exceeds remaining allocation — reject so frontend can cap correctly
                $wpdb->query('ROLLBACK');
                json_error("You have {$discount_remaining} discounted mint(s) remaining. Please select {$discount_remaining} or fewer.");
            }
        }
        
        // v67: Check wallet limit if set (limit_override = hard cap)
        if ($allowlist_benefits['max_mint'] !== null) {
            // v891 (Defect U): this claimed to use the "same logic as the general wallet limit"
            // but omitted BOTH 'claimed' and 'minting'. Omitting 'claimed' made the allowlist cap
            // trivially bypassable — mint, claim, and the row stops counting, repeat without
            // limit. Omitting 'minting' let two concurrent tabs both pass. Now genuinely the
            // same logic, because it is literally the same function.
            $already_minted_by_wallet = imc_wallet_holdings($listing_id, $buyer);
            
            $will_exceed = (intval($already_minted_by_wallet) + $qty) > $allowlist_benefits['max_mint'];
            if ($will_exceed) {
                $remaining = max(0, $allowlist_benefits['max_mint'] - intval($already_minted_by_wallet));
                $wpdb->query('ROLLBACK');
                json_error("You can only mint {$allowlist_benefits['max_mint']} edition(s) total. You have {$remaining} remaining.");
            }
        }

        $minted   = intval($listing['minted_count']);
        $reserved = intval($listing['reserved_count'] ?? 0);
        $total    = intval($listing['total_editions']);
        $is_open_edition = (($listing['edition_type'] ?? 'fixed') === 'open');

        if (!$is_open_edition) {
            // Fixed Edition: enforce hard supply cap
            $available = $total - $minted - $reserved;
            if ($available <= 0) {
                $wpdb->query('ROLLBACK');
                json_error('All editions sold out');
            }
            if ($qty > $available) {
                $wpdb->query('ROLLBACK');
                json_error("Only {$available} edition(s) available");
            }
        }
        // Open Edition: no supply cap — reservation proceeds regardless of minted_count

        // v71: Multi-currency pricing
        $price_xrp = floatval($listing['price_xrp']);
        $selected_currency_config = null;
        $payment_issuer = null;
        $payment_amount = 0;
        $currency_discount = 0;
        
        // v663 SAFETY NET (see block below for the full rationale). First: is this listing
        // priced in ANYTHING? A listing with no price anywhere is a genuine free mint and must
        // keep working untouched; a listing priced in something must never resolve to 0.
        $imc_guard_mode = $listing['pricing_mode'] ?? 'static';
        $imc_listing_is_priced = ($price_xrp > 0);
        if (!$imc_listing_is_priced && !empty($listing['accepted_currencies'])) {
            $imc_ac_probe = json_decode($listing['accepted_currencies'], true);
            if (is_array($imc_ac_probe)) {
                foreach ($imc_ac_probe as $imc_c) {
                    if (isset($imc_c['enabled']) && !$imc_c['enabled']) continue;
                    if (floatval($imc_c['price'] ?? 0) > 0) { $imc_listing_is_priced = true; break; }
                }
            }
        }
        // Scope: the block below applies ONLY where the STATIC branch decides the price (that is
        // the only branch that reads accepted_currencies / price_xrp). Mirrors the branch
        // conditions at the pricing switch further down:
        //   - PWYW      -> excluded; a 0 floor legitimately means "pay any amount", and that
        //                  branch already enforces amount > 0, so it cannot reach the free path.
        //   - DYNAMIC   -> excluded when it has a price_usd, because the price is computed from
        //                  the USD oracle, not from these columns. (A 'dynamic' row WITHOUT a
        //                  price_usd falls through to the static branch, so it stays in scope.)
        //   - FREE      -> excluded; an explicit Free Mint is creator-authorised to cost nothing,
        //                  so it must never be blocked for resolving to 0.
        $imc_is_dynamic_priced = ($imc_guard_mode === 'dynamic' && !empty($listing['price_usd']));
        $imc_enforce_priced = ($imc_guard_mode !== 'pwyw' && $imc_guard_mode !== 'free'
                               && !$imc_is_dynamic_priced && $imc_listing_is_priced);

        // Parse accepted currencies from listing
        if (!empty($listing['accepted_currencies'])) {
            $accepted = json_decode($listing['accepted_currencies'], true);
            if (is_array($accepted)) {
                foreach ($accepted as $curr) {
                    if (isset($curr['enabled']) && !$curr['enabled']) continue;
                    $curr_code = strtoupper($curr['currency'] ?? '');
                    if ($curr_code === $payment_currency || 
                        (isset($curr['currency_hex']) && strtoupper($curr['currency_hex']) === $payment_currency)) {
                        $selected_currency_config = $curr;
                        break;
                    }
                }
            }
        }
        
        // Default to XRP if no match
        if (!$selected_currency_config) {
            if ($payment_currency !== 'XRP') {
                $wpdb->query('ROLLBACK');
                json_error("Currency $payment_currency not accepted for this listing");
            }
            $selected_currency_config = ['currency' => 'XRP', 'price' => $price_xrp];
        }

        // v663 SAFETY NET: resolve the EFFECTIVE price of the selected currency, then refuse
        // to price a paid listing at zero.
        //
        // Two real defects this closes:
        //  1. STALE ZERO: PHP's '??' does not fire on 0, so a legacy accepted_currencies entry
        //     {"currency":"XRP","price":0} silently overrode a real price_xrp column -> base_price
        //     0 -> free-mint path. For XRP we now fall back to the authoritative price_xrp column.
        //  2. TOKEN-ONLY: a listing priced only in tokens (XRP 0 in BOTH sources) let a buyer pick
        //     XRP and mint for nothing. Now refused cleanly with a currency-choice message.
        //
        // Untouched by design: genuine free listings (nothing priced -> $imc_enforce_priced is
        // false), PWYW, and allowlist custom_price = 0 (applied further below, AFTER this guard).
        $imc_eff_price = isset($selected_currency_config['price']) ? floatval($selected_currency_config['price']) : 0;
        if ($imc_eff_price <= 0 && strtoupper($selected_currency_config['currency'] ?? '') === 'XRP') {
            $imc_eff_price = $price_xrp;
        }
        if ($imc_enforce_priced && $imc_eff_price <= 0) {
            $wpdb->query('ROLLBACK');
            mod_log("reserve_purchase: BLOCKED unpriced currency $payment_currency on priced listing=$listing_id");
            json_error("$payment_currency is not available for this listing. Please choose another currency.");
        }
        // Normalize so every downstream consumer (base_price, discounts, totals) sees the
        // resolved price rather than a stale zero.
        $selected_currency_config['price'] = $imc_eff_price;
        
        // v78: Handle dynamic vs static pricing mode
        $pricing_mode = $listing['pricing_mode'] ?? 'static';
        $base_price = 0;
        $pp_ladder_total = null;  // PP-1: set only by the progressive block in the static branch
        $pp_meta = null;          // PP-1: response payload for the client
        $pp_usd_ladder_sum = null; // PP-5: dynamic USD ladder sum (converted via ratio at totals)
        $pp_usd_unit = 0;          // PP-5: dynamic USD unit (the ratio's denominator)
        
        if ($pricing_mode === 'pwyw') {
            // v660: PAY WHAT YOU WANT — the buyer names their price. Validate it against the
            // per-currency floor (accepted_currencies price; XRP falls back to price_xrp). The
            // floor check here + the on-chain payment verification are the security guards.
            $pwyw_floor  = floatval($selected_currency_config['price'] ?? $price_xrp);
            $pwyw_amount = floatval($_POST['pwyw_amount'] ?? 0);

            // ── Task H (flag-gated): the wallet's allowlist benefit becomes the PWYW floor. ──
            // Precedence mirrors the static chain exactly (matrix rule unless cp=0; custom
            // XRP; proportional non-XRP under SCOPED_CONSUME; percent) so the floor the
            // client displays (its v385 mirror) is the floor the server enforces.
            $pwyw_floor_public = $pwyw_floor;
            $pwyw_free_claim   = false;
            if (IMC_ALLOWLIST_PWYW) {
                $al_cp = $allowlist_benefits['custom_price'];
                if ($al_cp !== null && floatval($al_cp) == 0) {
                    // FREE claim: floor 0 in ANY currency (outranks the matrix, as in v731).
                    $pwyw_floor = 0; $pwyw_free_claim = true;
                } elseif (IMC_ALLOWLIST_CURRENCY_MATRIX && !empty($imc_currency_rules[$payment_currency]) && $pwyw_floor > 0) {
                    $al_cand = null;
                    foreach ($imc_currency_rules[$payment_currency] as $al_rule) {
                        $al_p = $al_rule['mode'] === 'price'
                            ? floatval($al_rule['value'])
                            : round($pwyw_floor * (1 - floatval($al_rule['value']) / 100), 8);
                        if ($al_cand === null || $al_p < $al_cand) $al_cand = $al_p;
                    }
                    if ($al_cand !== null && $al_cand < $pwyw_floor) $pwyw_floor = max(0, $al_cand);
                } elseif ($al_cp !== null && $payment_currency === 'XRP') {
                    if (floatval($al_cp) < $pwyw_floor) $pwyw_floor = floatval($al_cp);
                } elseif (IMC_ALLOWLIST_SCOPED_CONSUME && $al_cp !== null && $payment_currency !== 'XRP'
                          && floatval($price_xrp) > 0 && $pwyw_floor > 0) {
                    $al_prop = round($pwyw_floor * (floatval($al_cp) / floatval($price_xrp)), 8);
                    if ($al_prop < $pwyw_floor) $pwyw_floor = max(0, $al_prop);
                } elseif ($allowlist_benefits['discount_percent'] > 0 && $pwyw_floor > 0) {
                    $pwyw_floor = round($pwyw_floor * (1 - $allowlist_benefits['discount_percent'] / 100), 8);
                }
                if ($pwyw_floor < $pwyw_floor_public) {
                    mod_log("reserve_purchase: PWYW allowlist floor {$pwyw_floor_public} -> {$pwyw_floor} {$payment_currency} for $buyer (Task H)");
                }
            }

            if ($pwyw_free_claim && $pwyw_amount == 0) {
                // Free claim with optional tip: 0 rides the FREE-MINT rail downstream
                // ($payment_amount==0 && $base_price==0 — the v412 allocation guard applies).
                $base_price = 0;
                $imc_allowlist_price_applied = true;
                mod_log("reserve_purchase: PWYW FREE claim (allowlist custom_price=0) by $buyer — free-mint rail");
            } else {
                if ($pwyw_amount <= 0) {
                    $wpdb->query('ROLLBACK');
                    json_error('Please enter an amount to pay for this listing');
                }
                if ($pwyw_amount < $pwyw_floor) {
                    $wpdb->query('ROLLBACK');
                    json_error("Amount is below the minimum of {$pwyw_floor} {$payment_currency} for this listing");
                }
                // Consumption truth: only a payment BELOW the public floor used the benefit
                // (allowlist_qty / discount_limited allocation must not burn on ordinary pays).
                if ($pwyw_amount < $pwyw_floor_public) $imc_allowlist_price_applied = true;
                $base_price = $pwyw_amount;
                mod_log("reserve_purchase: PWYW -- buyer chose {$pwyw_amount} {$payment_currency} (floor {$pwyw_floor})");
            }
        } elseif ($pricing_mode === 'dynamic' && !empty($listing['price_usd'])) {
            // ═══════════════════════════════════════════════════════════════
            // DYNAMIC PRICING: Calculate from USD using live price oracle
            // ═══════════════════════════════════════════════════════════════
            mod_log("reserve_purchase: Using DYNAMIC pricing mode, base USD = {$listing['price_usd']}");

            // ── PP-5 (R5): progressive on dynamic -- ONE USD increment, stepped BEFORE the
            // oracle converts. Together by construction (one USD figure -> the un-filtered
            // verified-paid SUM). Conversion is LINEAR, so the qty ladder is the sum of
            // capped USD terms carried to the totals block via a ratio on the converted
            // unit -- ONE oracle call total. THE CONFIRM GATE COMPARES USD-TO-USD: rate
            // drift is invisible to it (as it always was for dynamic); only a genuine STEP
            // move pauses the buyer. R14: allowlist custom_price bypasses entirely.
            $imc_pp_usd = floatval($listing['price_usd']);
            $pp_cfg_dyn = imc_pp_config($listing);
            if ($pp_cfg_dyn && isset($pp_cfg_dyn['increments']['USD']) && $allowlist_benefits['custom_price'] === null) {
                $pp_inc_usd  = floatval($pp_cfg_dyn['increments']['USD']);
                $pp_cap_usd  = isset($pp_cfg_dyn['cap']['USD']) ? floatval($pp_cfg_dyn['cap']['USD']) : null;
                $pp_base_usd = isset($pp_cfg_dyn['base']['USD']) ? floatval($pp_cfg_dyn['base']['USD']) : $imc_pp_usd;
                $pp_step     = imc_pp_step($listing_id);
                $pp_usd_lad  = imc_pp_ladder($pp_base_usd, $pp_inc_usd, $pp_step, $qty, $pp_cap_usd);
                $pp_usd_cur  = $pp_usd_lad['first'];
                if (isset($_POST['pp_expected_price']) && $_POST['pp_expected_price'] !== '') {
                    $pp_exp_usd = floatval($_POST['pp_expected_price']);
                    if (abs($pp_exp_usd - $pp_usd_cur) > 0.00000001) {
                        $wpdb->query('ROLLBACK');
                        mod_log("reserve_purchase: PP(USD) price moved -- expected=$pp_exp_usd current=$pp_usd_cur step=$pp_step listing=$listing_id");
                        json_error("The price just moved to \$$pp_usd_cur -- mints happened while you were deciding.", 409, [
                            'price_moved' => true, 'current_price' => $pp_usd_cur,
                            'expected_price' => $pp_exp_usd, 'currency' => 'USD', 'step' => $pp_step
                        ]);
                    }
                }
                $imc_pp_usd        = $pp_usd_cur;              // the oracle converts the STEPPED USD
                $pp_usd_ladder_sum = $pp_usd_lad['total'];
                $pp_usd_unit       = $pp_usd_cur;
                $pp_meta = ['enabled' => true, 'scope' => 'together', 'step' => $pp_step,
                    'increment' => $pp_inc_usd, 'currency' => 'USD', 'current' => $pp_usd_cur,
                    'last_in_batch' => $pp_usd_lad['last'],
                    'next' => imc_pp_unit($pp_base_usd, $pp_inc_usd, $pp_step + $qty, $pp_cap_usd),
                    'capped' => $pp_usd_lad['capped'], 'cap' => $pp_cap_usd];
                mod_log("reserve_purchase: PP(USD) step=$pp_step base=$pp_base_usd inc=$pp_inc_usd -> \$$pp_usd_cur ladder(q=$qty)=\${$pp_usd_lad['total']}" . ($pp_usd_lad['capped'] ? ' CAPPED' : ''));
            }
            
            // Include price oracle
            require_once __DIR__ . '/price-oracle.php';
            
            // Get discount for selected currency
            $discount_pct = floatval($selected_currency_config['discount_pct'] ?? 
                                     $selected_currency_config['discount_percent'] ?? 0);
            
            // Get token issuer
            $token_issuer = ($payment_currency !== 'XRP') 
                ? ($selected_currency_config['issuer'] ?? $payment_issuer ?? '') 
                : '';
            
            // Calculate token amount from USD price
            $calc = IMC_Price_Oracle::calculate_token_amount(
                $imc_pp_usd, // PP-5: the stepped USD (identical to price_usd when progressive inactive)
                $payment_currency,
                $token_issuer,
                $discount_pct
            );
            
            if ($calc && isset($calc['amount']) && $calc['amount'] > 0) {
                $base_price = $calc['amount'];
                $currency_discount = $discount_pct; // For logging
                mod_log("reserve_purchase: Dynamic calc - {$payment_currency} = {$base_price} @ \${$calc['token_usd_price']}/token (USD {$listing['price_usd']} - {$discount_pct}% = \${$calc['effective_usd']})");
            } else {
                // Fallback: Try to get XRP price and fail gracefully
                $xrp_price = IMC_Price_Oracle::get_xrp_usd_price();
                if ($xrp_price && $payment_currency === 'XRP') {
                    $effective_usd = $imc_pp_usd * (1 - $discount_pct / 100); // PP-5: stepped USD
                    $base_price = round($effective_usd / $xrp_price, 6);
                    mod_log("reserve_purchase: Dynamic fallback - XRP = {$base_price} (USD {$effective_usd} / \${$xrp_price})");
                } else {
                    $wpdb->query('ROLLBACK');
                    json_error("Unable to calculate dynamic price for $payment_currency. Please try again or use XRP.");
                }
            }
            
            // For tokens, ensure we have the issuer
            if ($payment_currency !== 'XRP') {
                $payment_issuer = $token_issuer ?: ($selected_currency_config['issuer'] ?? null);
                if (!$payment_issuer) {
                    $wpdb->query('ROLLBACK');
                    json_error("Invalid token configuration for $payment_currency");
                }
            }
        } elseif ($pricing_mode === 'free') {
            // ═══════════════════════════════════════════════════════════════
            // v664: FREE MINT — explicit, creator-authorised. Collectors pay nothing
            // (network fee only). Priced at 0 here rather than relying on the row's
            // stored prices, so an explicit Free Mint is always free by intent.
            // Downstream: the existing free-mint handler below skips XUMM payment and
            // applies its per-wallet duplicate protection, exactly as for legacy free
            // listings and allowlist custom_price = 0 giveaways.
            // ═══════════════════════════════════════════════════════════════
            $base_price = 0;
            // F5 (Phase 1, 2 Sep 2026): tag the group. With IMC_ALLOWLIST_SCOPED_CONSUME the v412 guard and
            // the completion-time consumption both key on allowlist_qty > 0; an explicit-free listing never
            // set it, so the guard counted NOTHING and the v76 per-wallet limit was the only control (
            // every group had allowlist_qty=0). A free mint IS the benefit -- record it as one.
            $imc_allowlist_price_applied = true;
            mod_log("reserve_purchase: FREE MINT mode — listing=$listing_id, no payment taken");
        } else {
            // ═══════════════════════════════════════════════════════════════
            // STATIC PRICING: Use stored price from accepted_currencies
            // ═══════════════════════════════════════════════════════════════
            mod_log("reserve_purchase: Using STATIC pricing mode");
            
            // v606: never silently charge the XRP listing price in token units -- a non-XRP
            // currency must carry its own configured price, else reject cleanly.
            if ($payment_currency !== 'XRP' && empty($selected_currency_config['price'])) {
                $wpdb->query('ROLLBACK');
                json_error("No $payment_currency price is configured for this listing. Please choose another currency.");
            }
            $base_price = floatval($selected_currency_config['price'] ?? $price_xrp);

            // ── PP-1: PROGRESSIVE PRICING (static branch only -- the scope comment above the
            // v663 guard explains why this branch is the only one that reads these columns).
            // PLACEMENT IS THE DESIGN: this sits BETWEEN the raw price read and the
            // currency-discount line, so the existing discount math runs on the CURRENT
            // (stepped) price unchanged -- R3 for free. The allowlist chain further down
            // OVERWRITES base_price for custom/matrix/proportional prices, which is exactly
            // R14's absolute bypass -- so those paths are excluded here and the ladder dies
            // with them (their flat price x qty math is correct for them).
            // Step SUM is a plain prepared SELECT -- SQL only under the lock (v292 law).
            if ($pp_cfg = imc_pp_config($listing)) {
                $pp_has_custom = ($allowlist_benefits['custom_price'] !== null);
                $pp_has_matrix = (IMC_ALLOWLIST_CURRENCY_MATRIX && !empty($imc_currency_rules[$payment_currency]));
                $pp_key = null;
                foreach ($pp_cfg['increments'] as $pp_k => $pp_v) {
                    $pp_ku = strtoupper($pp_k);
                    if ($pp_ku === $payment_currency
                        || (isset($selected_currency_config['currency_hex']) && strtoupper($selected_currency_config['currency_hex']) === $pp_ku)
                        || (strtoupper($selected_currency_config['currency'] ?? '') === $pp_ku)) {
                        $pp_key = $pp_k; break;
                    }
                }
                if ($pp_key !== null && !$pp_has_custom && !$pp_has_matrix && $base_price > 0) {
                    // The IMMUTABLE base: prefer the entry's stamped base_price (written by the
                    // materializer/PP-3); fall back to the live price on a never-materialized
                    // listing (step 0 -- identical by definition).
                    $pp_base = isset($selected_currency_config['base_price'])
                        ? floatval($selected_currency_config['base_price']) : $base_price;
                    $pp_inc  = floatval($pp_cfg['increments'][$pp_key]);
                    $pp_cap  = isset($pp_cfg['cap'][strtoupper($pp_key)]) ? floatval($pp_cfg['cap'][strtoupper($pp_key)]) : (isset($pp_cfg['cap'][$pp_key]) ? floatval($pp_cfg['cap'][$pp_key]) : null);
                    $pp_step = ($pp_cfg['scope'] === 'individual') ? imc_pp_step($listing_id, $payment_currency) : imc_pp_step($listing_id);
                    $pp_lad  = imc_pp_ladder($pp_base, $pp_inc, $pp_step, $qty, $pp_cap);
                    $pp_current = $pp_lad['first'];

                    // R11 CONFIRM GATE: the client sends the price it showed the buyer. If the
                    // step moved while they were deciding, pause BEFORE any reservation or QR.
                    // Tolerance 1e-8 -- full-precision compare of like-for-like unit prices.
                    if (isset($_POST['pp_expected_price']) && $_POST['pp_expected_price'] !== '') {
                        $pp_exp = floatval($_POST['pp_expected_price']);
                        if (abs($pp_exp - $pp_current) > 0.00000001) {
                            $wpdb->query('ROLLBACK');
                            $pp_moved_by = max(0, $pp_step - ($pp_inc > 0 ? (int)round(($pp_exp - $pp_base) / $pp_inc) : 0));
                            mod_log("reserve_purchase: PP price moved -- expected=$pp_exp current=$pp_current step=$pp_step listing=$listing_id");
                            json_error("The price just moved to $pp_current $payment_currency -- $pp_moved_by mint(s) happened while you were deciding.", 409, [
                                'price_moved'   => true,
                                'current_price' => $pp_current,
                                'expected_price'=> $pp_exp,
                                'currency'      => $payment_currency,
                                'step'          => $pp_step
                            ]);
                        }
                    }

                    $base_price      = $pp_current;      // discounts below now apply to CURRENT (R3)
                    $pp_ladder_total = $pp_lad['total']; // pre-discount; scaled at the totals block
                    $pp_meta = [
                        'enabled' => true, 'scope' => $pp_cfg['scope'], 'step' => $pp_step,
                        'increment' => $pp_inc, 'current' => $pp_current, 'last_in_batch' => $pp_lad['last'],
                        'next' => imc_pp_unit($pp_base, $pp_inc, $pp_step + $qty, $pp_cap),
                        'capped' => $pp_lad['capped'], 'cap' => $pp_cap
                    ];
                    mod_log("reserve_purchase: PP step=$pp_step {$pp_cfg['scope']} $payment_currency base=$pp_base inc=$pp_inc -> unit=$pp_current ladder(q=$qty)={$pp_lad['total']}" . ($pp_lad['capped'] ? ' CAPPED' : ''));
                } elseif ($pp_key !== null && ($pp_has_custom || $pp_has_matrix)) {
                    mod_log("reserve_purchase: PP bypassed for buyer=$buyer (allowlist custom/matrix price -- R14 absolute)");
                }
            }

            // Apply currency-specific discount (for static mode)
            if (isset($selected_currency_config['discount_percent']) && $selected_currency_config['discount_percent'] > 0) {
                $currency_discount = floatval($selected_currency_config['discount_percent']);
                $base_price = round($base_price * (1 - $currency_discount / 100), 8);
                mod_log("reserve_purchase: Applied {$currency_discount}% currency discount for $payment_currency");
            }
            
            // For tokens, get issuer
            if ($payment_currency !== 'XRP') {
                $payment_issuer = $selected_currency_config['issuer'] ?? null;
                if (!$payment_issuer) {
                    $wpdb->query('ROLLBACK');
                    json_error("Invalid token configuration for $payment_currency");
                }
            }
        }
        
        // v697 FIX: resolve the token issuer for EVERY pricing mode before the Amount is built.
        // The PWYW branch never set $payment_issuer (only static/dynamic did), so a PWYW token
        // payment produced an Amount with issuer=null -> XUMM "Cannot construct AccountID" (and a
        // broken Joey txjson). Backstop from the already-resolved currency config; fail closed if
        // still missing so we never build an unsignable payload. Static/dynamic already set it,
        // so empty() is false there and this is a no-op for them.
        if ($payment_currency !== 'XRP' && empty($payment_issuer)) {
            $payment_issuer = $selected_currency_config['issuer'] ?? null;
            if (empty($payment_issuer)) {
                $wpdb->query('ROLLBACK');
                json_error("Invalid token configuration for $payment_currency (missing issuer)");
            }
        }

        // Apply allowlist benefits (on top of currency price)
        // v731 (A4b): per-token rule for the SELECTED currency — the most specific rule
        // wins, REPLACING the base benefit for that route (override, not stack; a rule
        // applies even when smaller than the base — the creator's explicit choice for
        // that token). FREE claims (custom_price=0) outrank the matrix entirely.
        // Multiple lists' rules for one token: the buyer-best candidate applies.
        $imc_matrix_applied = false;
        if (IMC_ALLOWLIST_CURRENCY_MATRIX && $pricing_mode !== 'pwyw'
            && !empty($imc_currency_rules[$payment_currency])
            && !($allowlist_benefits['custom_price'] !== null && floatval($allowlist_benefits['custom_price']) == 0)
            && $base_price > 0) {
            $imc_candidate = null;
            foreach ($imc_currency_rules[$payment_currency] as $imc_rule) {
                $imc_p = $imc_rule['mode'] === 'price'
                    ? floatval($imc_rule['value'])
                    : round($base_price * (1 - floatval($imc_rule['value']) / 100), 8);
                if ($imc_candidate === null || $imc_p < $imc_candidate) $imc_candidate = $imc_p;
            }
            if ($imc_candidate !== null) {
                $imc_before_matrix = $base_price;
                $base_price = $imc_candidate;
                $imc_allowlist_price_applied = true;
                $imc_matrix_applied = true;
                mod_log("reserve_purchase: Per-token allowlist rule for $payment_currency: {$imc_before_matrix} => {$base_price}");
            }
        }
        if ($imc_matrix_applied) {
            // handled above — base chain skipped for this currency
        } elseif ($pricing_mode !== 'pwyw' && $allowlist_benefits['custom_price'] !== null && $payment_currency === 'XRP') {
            $base_price = $allowlist_benefits['custom_price'];
            $imc_allowlist_price_applied = true; // v728
            mod_log("reserve_purchase: Using custom allowlist price: {$base_price} XRP");
        } elseif (IMC_ALLOWLIST_SCOPED_CONSUME && $pricing_mode !== 'pwyw'
                  && $allowlist_benefits['custom_price'] !== null && $payment_currency !== 'XRP') {
            // v728 (A2/G6, product ruling): hard per-currency prices are stored, so the
            // XRP custom price converts to an effective ratio and applies to the selected
            // token's price. custom_price = 0 is FREE IN ANY CURRENCY (previously a free
            // claim silently charged full token price). Stacks on the listing-level
            // per-currency discount, mirroring the percent path's established semantics.
            $imc_cp = floatval($allowlist_benefits['custom_price']);
            if ($imc_cp == 0) {
                $base_price = 0;
                $imc_allowlist_price_applied = true;
                mod_log("reserve_purchase: Allowlist FREE claim honoured in $payment_currency (custom_price=0)");
            } else {
                // Base XRP price: price_xrp column, else the enabled XRP entry (v459 pattern)
                $imc_xrp_base = floatval($price_xrp);
                if (!($imc_xrp_base > 0) && !empty($listing['accepted_currencies'])) {
                    $imc_ac2 = json_decode($listing['accepted_currencies'], true);
                    if (is_array($imc_ac2)) {
                        foreach ($imc_ac2 as $imc_c2) {
                            if (isset($imc_c2['enabled']) && !$imc_c2['enabled']) continue;
                            if (strtoupper($imc_c2['currency'] ?? '') === 'XRP' && floatval($imc_c2['price'] ?? 0) > 0) {
                                $imc_xrp_base = floatval($imc_c2['price']); break;
                            }
                        }
                    }
                }
                if ($imc_xrp_base > 0 && $base_price > 0) {
                    $imc_ratio = $imc_cp / $imc_xrp_base;
                    $imc_before = $base_price;
                    $base_price = round($base_price * $imc_ratio, 8);
                    $imc_allowlist_price_applied = true;
                    mod_log("reserve_purchase: Proportional allowlist price for $payment_currency: ratio {$imc_cp}/{$imc_xrp_base} -> {$imc_before} => {$base_price}");
                } else {
                    // Token-only listing (no XRP base): an XRP-denominated fixed price has no
                    // ratio to apply — buyer pays the token price; creators are steered to
                    // percent mode for cross-currency discounts (A4 UI hint).
                    mod_log("reserve_purchase: custom_price set but no XRP base on listing $listing_id — no token benefit applied (use percent mode)");
                }
            }
        } elseif ($pricing_mode !== 'pwyw' && $allowlist_benefits['discount_percent'] > 0) {
            $original_price = $base_price;
            $base_price = round($base_price * (1 - $allowlist_benefits['discount_percent'] / 100), 8);
            $imc_allowlist_price_applied = true; // v728
            mod_log("reserve_purchase: Applied {$allowlist_benefits['discount_percent']}% allowlist discount: {$original_price} -> {$base_price} $payment_currency");
        }
        
        // Calculate totals
        //
        // v713 (transfer fee): $imc_buyer_spend is what the BUYER is quoted and actually
        // spends -- the listed price. $payment_amount is what the ARTIST RECEIVES, which is
        // lower whenever the issuer charges a transfer fee (the fee is DEDUCTED from the
        // total rather than added on top, so the buyer always pays the price on the label).
        //
        // WHY payment_amount HOLDS THE DELIVERED FIGURE: all four payment-verification call
        // sites -- including BOTH recovery paths in cleanup_expired_reservations() -- compare
        // meta.delivered_amount against this column. Storing the delivered amount keeps that
        // comparison EXACT and leaves every verification and recovery path untouched. Storing
        // the spend instead would leave them relying on the 1% tolerance, and a token with a
        // fee of 1% or more would then fail verification after the buyer had already paid.
        //
        // Rounded DOWN, always: delivered x rate must never exceed SendMax.
        //
        // PP-1: when a progressive ladder priced this batch, the total is the LADDER SUM,
        // not unit x qty. The ladder was computed PRE-discount, and the only base_price
        // mutations that survive to here with a live ladder are the two PERCENT discounts
        // (currency %, allowlist %) -- custom/matrix/proportional prices bypassed the ladder
        // at source (R14). Scale the ladder by those same factors so every unit in the batch
        // gets the identical treatment the single-unit price received. Full precision
        // throughout; the delivered figure below floors ONCE (v713/G10).
        // PP-5: the dynamic ladder converts here by RATIO on the oracle-converted unit --
        // conversion is linear (rate x USD, discount multiplicative), so
        // token_ladder = token_unit x (usd_ladder_sum / usd_unit), exactly one oracle call.
        if ($pp_usd_ladder_sum !== null && $pp_usd_unit > 0 && $base_price > 0) {
            $pp_ladder_total = $base_price * ($pp_usd_ladder_sum / $pp_usd_unit);
        }
        if ($pp_ladder_total !== null) {
            if ($currency_discount > 0) {
                $pp_ladder_total = $pp_ladder_total * (1 - $currency_discount / 100);
            }
            if ($allowlist_benefits['custom_price'] === null && $allowlist_benefits['discount_percent'] > 0) {
                $pp_ladder_total = $pp_ladder_total * (1 - $allowlist_benefits['discount_percent'] / 100);
            }
        }
        $imc_buyer_spend   = round(($pp_ladder_total !== null ? $pp_ladder_total : $base_price * $qty), 8);
        $imc_delivered_str = null;
        if ($payment_currency !== 'XRP') {
            $imc_amt = imc_xrpl_amount_floor(
                ($imc_fee_rate > 1.0) ? ($imc_buyer_spend / $imc_fee_rate) : $imc_buyer_spend
            );
            $payment_amount    = $imc_amt['value'];
            $imc_delivered_str = $imc_amt['str'];
        } else {
            // XRP has no issuer and no transfer fee -- byte-for-byte the previous behaviour.
            $payment_amount = $imc_buyer_spend;
        }
        $total_price_xrp = $payment_currency === 'XRP' ? $payment_amount : round($price_xrp * $qty, 6);

        if ($base_price < 0) {
            $wpdb->query('ROLLBACK');
            json_error('Invalid listing price');
        }

        // Reserve editions
        $wpdb->query($wpdb->prepare(
            "UPDATE $listings_table SET reserved_count = reserved_count + %d, updated_at = %s WHERE id = %d",
            $qty, $now, $listing_id
        ));

        // Create purchase group with currency info
        // v728: allowlist_qty tags groups whose price used an allowlist benefit — the
        // scoped increments, the scoped v412 guard and the R1 check all key off it.
        // Written only with the flag on so the OFF state is byte-identical.
        $imc_group_row = [
            'listing_id'             => $listing_id,
            'buyer_account'          => $buyer,
            'quantity'               => $qty,
            'total_price_xrp'        => $total_price_xrp,
            'payment_currency'       => $payment_currency,
            'payment_currency_issuer'=> $payment_issuer,
            'payment_amount'         => $payment_amount,
            'reservation_expires_at' => $expires_at,
            'status'                 => 'reserved',
            'created_at'             => $now,
            'updated_at'             => $now
        ];
        if (IMC_ALLOWLIST_SCOPED_CONSUME) {
            $imc_group_row['allowlist_qty'] = $imc_allowlist_price_applied ? $qty : 0;
        }
        $wpdb->insert($groups_table, $imc_group_row);
        $group_id = $wpdb->insert_id;

        // Create individual purchase records
        $insert_failures = 0;
        for ($i = 0; $i < $qty; $i++) {
            $insert_result = $wpdb->insert($purchases_table, [
                'group_id'       => $group_id,
                'listing_id'     => $listing_id,
                'buyer_account'  => $buyer,
                'artist_account' => $listing['artist_account'],
                'edition_number' => 0,
                'price_xrp'      => $payment_currency === 'XRP' ? $base_price : floatval($listing['price_xrp']),
                // v707 (Step C): record the ACTUAL payment currency. Previously omitted, so the
                // column DEFAULT 'XRP' applied to every token purchase and the stats layer read
                // token sales as XRP sales. The group row already stores the truth
                // (payment_currency / payment_amount); this puts it on the purchase row too so
                // per-currency reporting works without a JOIN. Write-only -- no read path changes.
                'price_currency' => $payment_currency,
                'mint_status'    => 'pending',
                'created_at'     => $now,
                'updated_at'     => $now
            ]);

            // v41: Check insert result — silent failures here caused "no paid purchases"
            if ($insert_result === false) {
                $insert_failures++;
                mod_log("reserve_purchase: FAILED to insert purchase #" . ($i + 1) . " for group=$group_id: " . $wpdb->last_error);
            } else {
                mod_log("reserve_purchase: Inserted purchase id=" . $wpdb->insert_id . " for group=$group_id (" . ($i + 1) . "/$qty)");
            }
        }

        // v41: If ANY inserts failed, rollback everything
        if ($insert_failures > 0) {
            $wpdb->query('ROLLBACK');
            mod_log("reserve_purchase: ROLLED BACK — $insert_failures of $qty purchase inserts failed");
            json_error("Failed to create purchase records. Please contact support. (DB error: $insert_failures inserts failed)");
        }

        $wpdb->query('COMMIT');

        mod_log("reserve_purchase: group_id=$group_id listing=$listing_id qty=$qty buyer=$buyer currency=$payment_currency expires=$expires_at");

        // ── v412 FIX: FREE MINT DUPLICATE PREVENTION ─────────────────
        // CRITICAL: For free mints, reserve_purchase returns instantly (no XUMM
        // payment delay), so a user who refreshes or opens a new tab can call
        // reserve_purchase again before the first group finishes minting.
        // The existing discount_allocation check uses entries.minted_count which
        // is only incremented AFTER on-chain minting — too late to catch the race.
        //
        // This guard uses the PURCHASES TABLE (source of truth) to count all
        // non-cancelled purchases by this buyer for this listing, EXCLUDING the
        // current group (just committed above). If existing purchases already
        // meet or exceed the allowlist allocation, we expire the new group
        // immediately and block.
        if ($payment_amount == 0 && $base_price == 0) {
            // Count purchases from EARLIER groups (v897, Defect W).
            //
            // THE RACE THIS CLOSES: this guard runs OUTSIDE the reservation transaction
            // (START TRANSACTION L4811 .. COMMIT L4841) and AFTER the purchases are
            // inserted (L5597). Two tabs firing at once therefore both commit, and then
            // both count — with `group_id != $group_id` each one SEES THE OTHER and
            // blocks itself:
            //     tab A (group 100): counts != 100 -> sees 101 -> blocks
            //     tab B (group 101): counts != 101 -> sees 100 -> blocks
            // Both expire, and a user entitled to a free mint receives NOTHING.
            //
            // `group_id < $group_id` makes the comparison ORDERED instead of mutual.
            // Group ids are auto-increment, so exactly one request has the lower id and
            // exactly one wins. Normal behaviour is unchanged: a genuine earlier purchase
            // always has a lower group_id and still blocks the later attempt.
            //
            // NOT solved by moving this inside the transaction: that would hold FOR UPDATE
            // on the listing row across more work, on the hottest path in the system,
            // during precisely the high-demand moment this exists to protect.
            //
            // Residual (accepted): if MySQL has not yet committed A's purchase when B
            // counts, both proceed and the buyer gets one extra free mint — still bounded
            // by reserved_count and the per-wallet limit. Failing OPEN on a free mint is
            // strictly better than denying an entitled user, which is what happens today.
            // v728: with the flag on, ONLY allowlist-priced purchases count against the
            // free/discount allocation — a full-price purchase never consumes it (product
            // decision 3). OFF keeps the original all-purchases count.
            if (IMC_ALLOWLIST_SCOPED_CONSUME) {
                $prior_purchase_count = intval($wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $purchases_table p
                     JOIN $groups_table g ON p.group_id = g.id
                     WHERE p.listing_id = %d
                       AND g.buyer_account = %s   -- 4k: the MINTER (immutable), not p.buyer_account (the current owner)
                       AND p.group_id < %d
                       AND g.allowlist_qty > 0
                       AND p.mint_status NOT IN ('cancelled', 'expired')",
                    $listing_id, $buyer, $group_id
                )));
            } else {
            $prior_purchase_count = intval($wpdb->get_var($wpdb->prepare(
                // 4k: same flaw as the scoped branch above -- and no join at all. Form B.
                "SELECT COUNT(*) FROM $purchases_table p
                 LEFT JOIN $groups_table g ON g.id = p.group_id
                 WHERE p.listing_id = %d
                   AND ( g.buyer_account = %s OR (g.id IS NULL AND p.buyer_account = %s) )
                   AND p.group_id < %d
                   AND p.mint_status NOT IN ('cancelled', 'expired')",
                $listing_id, $buyer, $buyer, $group_id
            )));
            }

            // Determine the effective per-wallet limit for this free mint:
            // Use allowlist discount_allocation if set, otherwise default to qty
            // (meaning: only the current reservation is allowed, no extras).
            // F5b (Phase 1): the fallback was `$qty` OF THE CURRENT RESERVATION compared against ALL prior
            // rows -- escalatable (1, then 2, then 4, then 8...). The comment's intent was "only the current
            // reservation, no extras". Defer to the creator's per-wallet cap when set; otherwise a wallet gets
            // exactly one free reservation (any qty) and any prior row blocks the next.
            $effective_free_limit = ($allowlist_benefits['discount_allocation'] > 0)
                ? $allowlist_benefits['discount_allocation']
                : ((!empty($listing['mint_limit_enabled']) && intval($listing['mint_limit_per_wallet']) > 0)
                    ? intval($listing['mint_limit_per_wallet']) : 1);

            // Also respect limit_override if set (hard cap)
            if ($allowlist_benefits['max_mint'] !== null) {
                $effective_free_limit = min($effective_free_limit, $allowlist_benefits['max_mint']);
            }

            if ($prior_purchase_count >= $effective_free_limit) {
                // Expire the group we just created and release reserved slots
                $wpdb->update($groups_table,
                    ['status' => 'expired', 'updated_at' => $now],
                    ['id' => $group_id]
                );
                $wpdb->query($wpdb->prepare(
                    "UPDATE $purchases_table SET mint_status = 'cancelled', updated_at = %s WHERE group_id = %d",
                    $now, $group_id
                ));
                imc_release_reservation($group_id, $listing_id, $qty, 'reserve-free-mint-duplicate-blocked');

                mod_log("reserve_purchase: FREE MINT BLOCKED — buyer=$buyer already has $prior_purchase_count purchase(s) for listing=$listing_id (limit=$effective_free_limit). Expired group=$group_id");

                $remaining = max(0, $effective_free_limit - $prior_purchase_count);
                json_error("You've already used your free mint allocation for this collection. ($prior_purchase_count of $effective_free_limit used)");
            }

            mod_log("reserve_purchase: FREE MINT — prior purchases=$prior_purchase_count, limit=$effective_free_limit, proceeding with group=$group_id");
        }

        // ── FREE MINT HANDLER (allowlist custom_price = 0) ────────────
        // When an allowlist sets custom_price to 0, the purchase is free.
        // Skip XUMM payment entirely — mark as paid and return immediately.
        // The frontend will call process_group to trigger minting.
        if ($payment_amount == 0 && $base_price == 0) {
            // 4i (4 Sep 2026): write the FREE_MINT sentinel HERE, where the group is created as
            // free -- not later, from a browser callback that may never arrive.
            // Until now the sentinel was written ONLY by process_group (L6631). But the free
            // dispatch below advances the group to 'minting', so process_group returns early at
            // its 'minting_in_progress' guard and never reaches that write. Result on a large drop:
            // most groups held payment_tx_hash = NULL. Both redrive-free-mints lanes require
            // payment_tx_hash = 'FREE_MINT', and NULL = 'FREE_MINT' is NULL -- so F2 has been
            // structurally blind to most free groups since it shipped. That is F2's circular
            // dependency: it exists to rescue groups whose browser never posted process_group,
            // yet identified them by a marker only process_group writes -- so it could only
            // ever see the ones that did not need it. Writing the sentinel here fixes it.
            // Every other consumer is unaffected -- each either excludes the sentinel explicitly
            // (retry-failed-mints A+B, export-payment-hashes, record_joey_tx's reuse guard,
            // reconcile_expired_paid), is unreachable for a free group on other predicates
            // (cleanup_expired_reservations, the L3 scripts, admin_compensate, process_group's
            // v242 replay guard -- which sits in the non-free branch), or already tests for it
            // (recover_stuck_mints). Forward-only: existing NULL rows are deliberately NOT backfilled.
            $wpdb->update($groups_table, [
                'payment_verified'    => 1,
                'payment_verified_at' => $now,
                'payment_tx_hash'     => 'FREE_MINT',   // 4i
                'status'              => 'paid',
                'updated_at'          => $now
            ], ['id' => $group_id]);

            $wpdb->query($wpdb->prepare(
                "UPDATE $purchases_table SET mint_status = 'paid', payment_verified = 1, payment_verified_at = %s, payment_tx_hash = 'FREE_MINT', updated_at = %s WHERE group_id = %d AND mint_status = 'pending'",   // 4i: mirrors the group. Write-only column (4 writes, 0 reads on either host); kept consistent with what process_group would have written.
                $now, $now, $group_id
            ));

            mod_log("reserve_purchase: FREE MINT — group=$group_id qty=$qty buyer=$buyer (allowlist custom_price=0, payment skipped)");

            // ══════════════════════════════════════════════════════════════════════
            // v886 (Defect A) — SERVER-SIDE MINT TRIGGER
            //
            // Until now the mint only began when the BROWSER posted process_group after this
            // response landed. A closed tab, a dropped connection or a locked phone in that
            // window meant the mint never started at all: the group sat at paid/mint_progress=0
            // and, with free mints excluded from every payment-keyed recovery net, was swept at
            // the 2h mark and its edition handed to someone else. That is most of the buyers
            // affected. Xaman has a server-side recovery key and Joey has a
            // keepalive backstop; free mints had neither, because both hang off a payment
            // artifact a free mint does not have.
            //
            // The group is ALREADY paid + payment_verified above -- exactly the state
            // process_group reaches after verification -- so nothing needs replicating except
            // the dispatch itself (process_group L5826-5852), which authenticates with
            // IMC_COMP_ADMIN_KEY and needs no nonce.
            //
            // STRICTLY ADDITIVE: the client still calls process_group. Whichever arrives first
            // wins; the loser hits the existing already_minted / minting_in_progress guards.
            // If the VPS is unreachable we fall through silently and behaviour is byte-identical
            // to before.
            //
            // ORDERING IS LOAD-BEARING: status is advanced to 'minting' ONLY after a 2xx. Setting
            // it before a failed dispatch would strand the group in 'minting' with nothing
            // running -- recover_stuck_mints would reset it to 'paid' ten minutes later and feed
            // it straight back to the 2h sweep (Defect N, still open).
            //
            // TIMEOUT: 3s, not process_group's 10s. process_group has already called
            // send_early_response() and is running detached; here the BUYER is still waiting on
            // this HTTP response. The worker returns 202 as soon as it spawns the CLI, so a
            // healthy dispatch costs a few hundred ms.
            // ══════════════════════════════════════════════════════════════════════
            if (defined('IMC_VPS_MINT_URL') && !empty(IMC_VPS_MINT_URL) && defined('IMC_COMP_ADMIN_KEY')) {
                $fm_ch = curl_init(IMC_VPS_MINT_URL);
                curl_setopt_array($fm_ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode([
                        'action'    => 'mint_batch',
                        'group_id'  => $group_id,
                        'admin_key' => IMC_COMP_ADMIN_KEY,
                    ]),
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 3,
                    CURLOPT_CONNECTTIMEOUT => 2,
                ]);
                $fm_body = curl_exec($fm_ch);   // 4h: the worker now reports whether it SPAWNED
                $fm_code = curl_getinfo($fm_ch, CURLINFO_HTTP_CODE);
                $fm_err  = curl_error($fm_ch);
                curl_close($fm_ch);

                if ($fm_code >= 200 && $fm_code < 300) {
                    // Dispatch accepted -- claim the group so the client's process_group returns
                    // minting_in_progress instead of spawning a second worker for the same group.
                    //
                    // GUARDED ON status='paid'. The worker returns 202 the moment it spawns the
                    // detached CLI, so in principle that CLI could reach vps_batch_finish and set
                    // 'minted' before this line runs. An unconditional write would then stamp a
                    // COMPLETED group back to 'minting' -- which recover_stuck_mints would pick up
                    // ten minutes later and reset to 'paid', feeding the 2h sweep (Defect N).
                    // A full mint needs 10-40s of XRPL validation and this runs within 3s, so it
                    // is not a realistic race -- but on the mint path "unlikely" is not a guarantee,
                    // and the WHERE clause makes it structurally impossible for zero cost.
                    // 4h (4 Sep 2026): CLAIM ONLY IF THE WORKER ACTUALLY SPAWNED A CLI.
                    // The worker suppresses the spawn while a leader chain is alive, expecting the
                    // chain to pick the group up. It cannot: vps_batch_next selects status='paid',
                    // and this UPDATE makes the group 'minting'. Redrive lane 1, redrive lane 2 and
                    // retry-failed-mints Pass B all query 'paid' too -- so a suppressed+claimed group
                    // is invisible to FOUR recovery paths, leaving only recover_stuck_mints at 10
                    // minutes. Observed live on a large drop: suppressions were rescued, but buyers waited
                    // for a ~20s mint. Leaving it at 'paid' when nothing was spawned restores
                    // visibility to all four, and the live chain takes it on its next hop (~20s).
                    // Missing 'spawned' (older worker) is treated as TRUE = today's behaviour, so
                    // this is inert until the worker half is deployed and either order is safe.
                    $fm_json    = json_decode((string) $fm_body, true);
                    $fm_spawned = !is_array($fm_json) || !array_key_exists('spawned', $fm_json) || !empty($fm_json['spawned']);
                    if (!$fm_spawned) {
                        mod_log("reserve_purchase: FREE MINT dispatched for group=$group_id but the worker did NOT spawn (" . ($fm_json['reason'] ?? '?') . ") — LEAVING status='paid' so the leader chain can pick it up (4h)");
                        $fm_claimed = 0;
                    } else {
                    $fm_claimed = $wpdb->query($wpdb->prepare(
                        "UPDATE $groups_table SET status = 'minting', updated_at = %s WHERE id = %d AND status = 'paid'",
                        $now, $group_id
                    ));
                    }
                    if ($fm_claimed) {
                        mod_log("reserve_purchase: FREE MINT auto-dispatched to VPS for group=$group_id (HTTP $fm_code) — status advanced to 'minting'");
                    } else {
                        mod_log("reserve_purchase: FREE MINT auto-dispatched to VPS for group=$group_id (HTTP $fm_code) — status NOT advanced (group already moved past 'paid'; left as-is)");
                    }
                } else {
                    // Left at 'paid' on purpose: the client's process_group call is the fallback,
                    // exactly as before this change.
                    mod_log("reserve_purchase: FREE MINT auto-dispatch FAILED for group=$group_id (HTTP $fm_code, err=$fm_err) — leaving status='paid' for the client trigger");
                }
            } else {
                mod_log("reserve_purchase: FREE MINT auto-dispatch SKIPPED for group=$group_id (IMC_VPS_MINT_URL / IMC_COMP_ADMIN_KEY not defined) — client trigger only");
            }

            json_success([
                'group_id'    => $group_id,
                'listing_id'  => $listing_id,
                'quantity'    => $qty,
                'price_each'  => 0,
                'total_price' => 0,
                'currency'    => 'XRP',
                'currency_display' => 'XRP',
                'artist'      => $listing['artist_account'],
                'available'   => $is_open_edition ? 999999 : ($total - $minted - $reserved - $qty),
                'free_mint'   => true
            ]);
        }

        // --- Create XUMM payment (buyer pays artist) ---
        // v71: Build Amount based on currency type
        if ($payment_currency === 'XRP') {
            $amount_obj = strval(round($payment_amount * 1000000)); // XRP in drops
        } else {
            // Token: amount object with currency, issuer, value
            // v606: a >3-char ticker is invalid as an on-chain Amount currency -- derive the
            // 40-char hex (ASCII-hex, zero-padded), matching the manager's stored currency_hex.
            // 3-char codes (e.g. XFT) pass through unchanged.
            // v607: prefer the token's authoritative currency_hex (handles mixed-case/special
            // tickers like Toeken / Keda's Brew Coin that don't ASCII-derive); derive as fallback.
            $imc_tok_cfg = function_exists('imc_get_token_by_ticker') ? imc_get_token_by_ticker($payment_currency) : null;
            $currency_code = $selected_currency_config['currency_hex']
                ?? (!empty($imc_tok_cfg['currency_hex']) ? $imc_tok_cfg['currency_hex'] : null)
                ?? (strlen($payment_currency) > 3
                    ? strtoupper(str_pad(bin2hex($payment_currency), 40, '0'))
                    : $payment_currency);
            $amount_obj = [
                'currency' => $currency_code,
                'issuer'   => $payment_issuer,
                // v713: NEVER strval() here. PHP's precision=14 rounds UP, which on a large
                // amount pushes the value past SendMax and fails tecPATH_PARTIAL -- the exact
                // bug this change exists to fix. $imc_delivered_str was produced by
                // imc_xrpl_amount_floor() and is the canonical, ledger-safe form.
                'value'    => ($imc_delivered_str !== null) ? $imc_delivered_str : strval($payment_amount)
            ];
        }
        
        $txjson = [
            'TransactionType' => 'Payment',
            'Account'         => $buyer,
            'Destination'     => $listing['artist_account'],
            'Amount'          => $amount_obj
        ];

        // ── v713: SendMax, so a token with an issuer transfer fee can actually be paid ──
        //
        // On the XRPL `Amount` is what the DESTINATION receives and the sender is debited
        // `Amount x TransferRate`. Omitting SendMax makes it default to Amount, so any token
        // whose issuer charges a fee could never deliver in full -- it failed tecPATH_PARTIAL
        // every time regardless of the buyer's balance. PAX (0.33% burn) is the token that
        // surfaced this; XMEME/XFT/$HORDE and the rest have no fee, which is why it went
        // unnoticed until now.
        //
        // Set ONLY when a fee actually applies, so every existing token and every XRP
        // purchase produces a byte-for-byte identical payload to before.
        //
        // NOT tfPartialPayment: that would let the ledger under-deliver and quietly short the
        // artist, which is the opposite of what we want.
        if ($payment_currency !== 'XRP' && $imc_fee_rate > 1.0 && isset($currency_code)) {
            $imc_sendmax = imc_xrpl_amount_ceil($imc_buyer_spend);
            $txjson['SendMax'] = [
                'currency' => $currency_code,
                'issuer'   => $payment_issuer,
                'value'    => $imc_sendmax['str']
            ];
            mod_log("reserve_purchase: SendMax {$imc_sendmax['str']} $payment_currency covers a "
                . round(($imc_fee_rate - 1) * 100, 4) . "% issuer fee; artist receives $imc_delivered_str");
        }

        // v661+: Make Waves hackathon attribution. Tag the BUYER's on-chain payment
        // with our assigned Source Tag (2606240013) for the Challenge metric. Applies to
        // ALL pricing modes (PWYW, fixed, dynamic) — covers both the Joey and Xaman
        // branches below (both submit this $txjson). SourceTag is a UInt32 (integer).
        $txjson['SourceTag'] = 2606240013;

        // --- Joey branch (v547, additive): the edition is already reserved above.
        // Return the vetted Payment txjson for local signing; the front-end then
        // calls process_group(group_id, tx_hash) -- the SAME on-chain verify+mint
        // path Xaman uses. No XUMM payload or uuid is created in this branch.
        if (($_POST['wallet'] ?? '') === 'joey' || ($_COOKIE['xrpl_wallet_type'] ?? '') === 'joey') {
            json_success([
                'group_id'    => $group_id,
                'listing_id'  => $listing_id,
                'quantity'    => $qty,
                'total_price' => $payment_amount,
                'currency'    => $payment_currency,
                'artist'      => $listing['artist_account'],
                'wallet'      => 'joey',
                'txjson'      => $txjson,
                'pp'          => $pp_meta
            ]);
        }

        $payload = create_xumm_payload($txjson, [
            'type'       => 'nft_purchase',
            'group_id'   => $group_id,
            'listing_id' => $listing_id,
            'quantity'   => $qty,
            'buyer'      => $buyer,
            'currency'   => $payment_currency
        ]);

        if (isset($payload['error']) || empty($payload['uuid'])) {
            // Release reservation on XUMM failure.
            // v562: also catch a malformed 200 response with no usable uuid -- without
            // this guard the reservation would commit but the buyer receives no QR,
            // orphaning the slot until expiry. Routes through the SAME rollback path
            // as a genuine XUMM API error -- no new logic, additive condition only.
            imc_release_reservation($group_id, $listing_id, $qty, 'reserve-xumm-payload-failed');
            $wpdb->update($groups_table, ['status' => 'failed', 'error_message' => 'XUMM payload creation failed'], ['id' => $group_id]);
            json_error('Failed to create payment: ' . ($payload['error'] ?? 'no payment reference returned'));
        }

        // Store payment UUID on group
        $wpdb->update($groups_table, [
            'payment_xumm_uuid' => $payload['uuid'],
            'updated_at'        => $now
        ], ['id' => $group_id]);

        // Build display name
        $currency_display = $selected_currency_config['display_name'] ?? $selected_currency_config['name'] ?? $payment_currency;

        json_success([
            'group_id'    => $group_id,
            'listing_id'  => $listing_id,
            'quantity'    => $qty,
            'price_each'  => $base_price,
            'total_price' => $payment_amount,
            'currency'    => $payment_currency,
            'currency_display' => $currency_display,
            'artist'      => $listing['artist_account'],
            'available'   => $available - $qty,
            'uuid'        => $payload['uuid'],
            'qr_png'      => $payload['refs']['qr_png'] ?? null,
            'deeplink'    => $payload['next']['always'] ?? null,
            'websocket'  => $payload['refs']['websocket_status'] ?? null,
            'pp'          => $pp_meta
        ]);
    }

    // ────────────────────────────────────────────────────────────────────
    // PROCESS GROUP — Verify payment → Return fast → Mint in background
    // (Task #10 + M4 async queue — wires up #5–#9)
    //
    // Flow:
    //   1. Validate input, verify payment on-chain (M3)
    //   2. Mark group as 'paid', send immediate JSON response
    //   3. Close HTTP connection (client starts polling get_group_status)
    //   4. Background: acquire XRPL lock → mint → create offers → update DB
    //
    // This lets multiple buyers pay simultaneously while minting is queued.
    // The XRPL lock serializes the actual minting (one wallet = one sequence).
    // ────────────────────────────────────────────────────────────────────
    if ($action === 'process_group') {
        mod_log("process_group: Starting");

        $group_id = intval($_POST['group_id'] ?? 0);
        $tx_hash  = sanitize_text_field($_POST['tx_hash'] ?? '');

        if (!$group_id || !$tx_hash) json_error('Missing group_id or tx_hash');

        // Get group
        $group = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $groups_table WHERE id = %d", $group_id
        ), ARRAY_A);

        if (!$group)                        json_error('Group not found');
        if ($group['status'] === 'minted')  json_success(['status' => 'already_minted', 'group_id' => $group_id]);
        if ($group['status'] === 'minting') json_success(['status' => 'minting_in_progress', 'group_id' => $group_id]);
        // v285: Added 'partial' to allowed retry statuses.
        // A 'partial' group had some NFTs mint successfully and some fail
        // (e.g. user mints qty=5, 1 succeeds, 4 fail → group status='partial').
        // Previously 'partial' was rejected here, making the 4 failed NFTs unretryable.
        if (!in_array($group['status'], ['reserved', 'paid', 'failed', 'partial'])) {
            json_error('Group in unexpected status: ' . $group['status']);
        }

        $now = current_time('mysql');

        // v291 FIX: Reject late payments on expired reservations.
        // cleanup_expired_reservations() runs on POST requests, but it's possible
        // for a buyer to sign a Xaman payload hours after the 10-minute reservation
        // window — the group status is still 'reserved' if no POST has run cleanup yet.
        // We only enforce this for 'reserved' groups: 'paid', 'failed', and 'partial'
        // groups have already had their payment verified, so retrying them is always safe.
        // This is what allowed expired groups to be processed hours
        // after their expiry — the bug was benign in that case (legitimate late payment),
        // but could in theory allow a buyer to pay against a slot that has since been
        // released and re-reserved by another buyer.
        if ($group['status'] === 'reserved' && $group['reservation_expires_at'] < $now) {
            // M1c: (a) CLAIM the expiry before acting -- cleanup Pass 1's self-heal selects this same
            // population and can promote it to 'paid' in this very second; an unconditional write here
            // would overwrite that and strand a verified buyer (the M1 hunk-G race, same shape).
            // (b) On a win, RELEASE the hold. Pre-M1c it stayed held; reconcile_expired_paid then
            // re-held on top of it, over-counting listing.reserved_count by qty forever (F4).
            $imc_fx = $wpdb->query($wpdb->prepare(
                "UPDATE $groups_table SET status = 'expired', updated_at = %s WHERE id = %d AND status = 'reserved'",
                $now, $group_id
            ));
            if ((int)$imc_fx === 1) {
                imc_release_reservation($group_id, intval($group['listing_id']), intval($group['quantity']), 'process-group-late-payment');
                mod_log("process_group: LATE PAYMENT REJECTED — group=$group_id expired at {$group['reservation_expires_at']}, now=$now buyer={$group['buyer_account']} -- hold RELEASED; reconcile_expired_paid re-holds within ~3 min if the payment verifies and supply remains");
                json_error('Your reservation window had expired before your payment was received. Your payment has been recorded and will be reconciled automatically within a few minutes — you will receive the NFT if editions remain, otherwise a refund. Please check your purchase history before buying again.');
            }
            // Lost the claim: another run moved this group off 'reserved' while we were reading it.
            $group = $wpdb->get_row($wpdb->prepare("SELECT * FROM $groups_table WHERE id = %d", $group_id), ARRAY_A);
            if (!$group) json_error('Group not found');
            if ($group['status'] === 'expired') json_error('Your reservation window has expired. Please start a new purchase.');
            if (!in_array($group['status'], ['paid', 'failed', 'partial'])) json_error('Group in unexpected status: ' . $group['status']);
            mod_log("process_group: group=$group_id left 'reserved' concurrently (now '{$group['status']}') -- continuing as a retry of the promoted group");
        }

        // M10 fix: If retrying a failed/partial group, reset failed purchases to 'paid'.
        // v285: Extended to cover 'partial' groups (mix of minted + failed purchases).
        // Only resets purchases with mint_status='failed' — already-minted ones are untouched.
        if (in_array($group['status'], ['failed', 'partial'])) {
            $reset_count = $wpdb->query($wpdb->prepare(
                "UPDATE $purchases_table SET mint_status = 'paid', error_message = NULL, updated_at = %s
                 WHERE group_id = %d AND mint_status = 'failed'
                   AND COALESCE(error_message,'') NOT LIKE '%%temMALFORMED%%'
                   AND COALESCE(error_message,'') NOT LIKE '%%tecNO_PERMISSION%%'
                   AND COALESCE(error_message,'') NOT LIKE '%%malformed%%'",
                $now, $group_id
            ));
            mod_log("process_group: Retrying {$group['status']} group=$group_id — reset $reset_count failed purchases to paid");
        }

        // ── FREE MINT CHECK ───────────────────────────────────────────
        // Free mints (allowlist custom_price=0) are pre-verified in reserve_purchase.
        // Skip on-chain verification and tx replay check — no payment was made.
        $is_free_mint = (floatval($group['payment_amount']) == 0 && intval($group['payment_verified']) === 1);

        if ($is_free_mint) {
            mod_log("process_group: FREE MINT — skipping payment verification for group=$group_id (payment_amount=0, already verified)");
        } else {
        // ── v242: TX HASH REPLAY PREVENTION ───────────────────────────
        // Ensure this tx_hash hasn't already been used to process a DIFFERENT
        // group. Two groups at the same price/destination would both pass
        // on-chain verification, allowing one payment to mint multiple groups.
        $existing_tx_use = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $groups_table
             WHERE payment_tx_hash = %s
               AND id != %d
               AND status NOT IN ('expired', 'cancelled', 'failed')
             LIMIT 1",
            $tx_hash, $group_id
        ));
        if ($existing_tx_use) {
            mod_log("process_group: REPLAY BLOCKED — tx_hash=$tx_hash already used by group=$existing_tx_use (requested by group=$group_id buyer={$group['buyer_account']})");
            imc_flag_attention($group_id, 'tx-replay (tx also claimed by group=' . intval($existing_tx_use) . ')');   // M8-a
            json_error('This transaction has already been used to process another purchase.');
        }

        // ── VERIFY PAYMENT ON-CHAIN (M3 — anti-fraud) ─────────────────
        $listing_for_verify = $wpdb->get_row($wpdb->prepare(
            "SELECT artist_account FROM $listings_table WHERE id = %d",
            $group['listing_id']
        ), ARRAY_A);

        if ($listing_for_verify) {
            // v71: Use multi-currency verification
            $payment_currency = $group['payment_currency'] ?? 'XRP';
            $payment_amount = floatval($group['payment_amount'] ?? $group['total_price_xrp']);
            $payment_issuer = $group['payment_currency_issuer'] ?? null;
            
            $expected_amount = [
                'currency' => $payment_currency,
                'value' => $payment_amount
            ];
            if ($payment_issuer) {
                $expected_amount['issuer'] = $payment_issuer;
            }
            
            $verify = verify_payment_multicurrency(
                $tx_hash,
                $listing_for_verify['artist_account'],
                $expected_amount
            );

            if (!$verify['verified']) {
                mod_log("process_group: Payment verification FAILED for group=$group_id: " . $verify['error']);
                $wpdb->update($groups_table, [
                    'status' => 'failed',
                    'error_message' => 'Payment verification failed: ' . $verify['error'],
                    'updated_at' => $now
                ], ['id' => $group_id]);
                json_error('Payment verification failed: ' . $verify['error']);
            }
            mod_log("process_group: Payment verified on-chain for group=$group_id ($payment_amount $payment_currency)");
            imc_record_received($group_id, $verify);   // M8-a
        }
        } // end !$is_free_mint

        // Mark payment verified + status → 'paid'
        $wpdb->update($groups_table, [
            'payment_tx_hash'    => $tx_hash,
            'payment_verified'   => 1,
            'payment_verified_at' => $now,
            'status'             => 'paid',
            'updated_at'         => $now
        ], ['id' => $group_id]);

        // Update individual purchases to 'paid'
        $update_result = $wpdb->query($wpdb->prepare(
            "UPDATE $purchases_table SET mint_status = 'paid', payment_tx_hash = %s,
             payment_verified = 1, payment_verified_at = %s, updated_at = %s
             WHERE group_id = %d AND mint_status = 'pending'",
            $tx_hash, $now, $now, $group_id
        ));

        // v41: Diagnostic logging for the UPDATE that was silently failing
        if ($update_result === false) {
            mod_log("process_group: UPDATE purchases to 'paid' FAILED for group=$group_id: " . $wpdb->last_error);
        } else {
            mod_log("process_group: Updated $update_result purchase(s) to 'paid' for group=$group_id");
        }

        // PP-1 (R13, hot path): this verify-success just advanced the step -- refresh the
        // materialized display prices NOW so the next page load shows the bump instantly.
        // (The cleanup choke point covers every other verified-write path within a request.)
        if (IMC_PROGRESSIVE_PRICING) { imc_pp_materialize(intval($group['listing_id'])); }

        // v41: Diagnostic — count ALL purchases for this group regardless of status
        $total_purchases = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $purchases_table WHERE group_id = %d", $group_id
        ));
        $pending_purchases = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $purchases_table WHERE group_id = %d AND mint_status = 'pending'", $group_id
        ));
        mod_log("process_group: Diagnostic — group=$group_id total_purchases=$total_purchases still_pending=$pending_purchases");

        // ── SEND EARLY RESPONSE & CLOSE CONNECTION ────────────────────
        // Client will now poll get_group_status for real-time progress
        send_early_response([
            'status'   => 'queued',
            'group_id' => $group_id,
            'quantity' => intval($group['quantity']),
            'message'  => 'Payment verified — minting your NFTs...'
        ]);

        mod_log("process_group: HTTP response sent, starting background minting for group=$group_id");

        // ── v397: VPS-DELEGATED MINTING ──────────────────────────────────
        // If IMC_VPS_MINT_URL is defined, delegate the mint loop to the VPS
        // worker which has no execution time limits. The existing inline loop
        // below is kept as fallback if VPS is unreachable.
        if (defined('IMC_VPS_MINT_URL') && !empty(IMC_VPS_MINT_URL)) {
            mod_log("process_group: Delegating to VPS mint worker at " . IMC_VPS_MINT_URL);
            $ch = curl_init(IMC_VPS_MINT_URL);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode([
                    'action'    => 'mint_batch',
                    'group_id'  => $group_id,
                    'admin_key' => defined('IMC_COMP_ADMIN_KEY') ? IMC_COMP_ADMIN_KEY : ''
                ]),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 10,  // v452: was 5 — more margin for VPS load
                CURLOPT_CONNECTTIMEOUT => 5,   // v452: was 3
                CURLOPT_RETURNTRANSFER => true
            ]);
            $vps_resp = curl_exec($ch);
            $vps_err  = curl_error($ch);
            $vps_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($vps_code >= 200 && $vps_code < 300) {
                mod_log("process_group: VPS accepted mint job for group=$group_id (HTTP $vps_code)");
                exit; // VPS takes over — the web host process ends cleanly
            }

            // VPS unreachable — fall through to inline minting (existing behavior)
            mod_log("process_group: VPS delegation failed (HTTP $vps_code, err=$vps_err) — falling back to inline minting");
        }

        // ──────────────────────────────────────────────────────────────
        // BACKGROUND MINTING (no HTTP output after this point)
        // ──────────────────────────────────────────────────────────────

        // Re-fetch current time for background timestamps
        $now = current_time('mysql');

        // Get listing data
        $listing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $listings_table WHERE id = %d",
            $group['listing_id']
        ), ARRAY_A);

        if (!$listing) {
            $wpdb->update($groups_table, [
                'status' => 'failed', 'error_message' => 'Listing not found', 'updated_at' => $now
            ], ['id' => $group_id]);
            mod_log("process_group: ABORT — listing not found for group=$group_id");
            exit;
        }

        $is_tiered = (bool)($listing['has_tiers'] ?? false);

        // Get individual purchases for this group (paid = new, minting = stuck recovery)
        $purchases = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $purchases_table WHERE group_id = %d AND mint_status IN ('paid', 'minting') ORDER BY id ASC",
            $group_id
        ), ARRAY_A);

        // Count already-minted purchases (for partial retry progress)
        $already_minted = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $purchases_table WHERE group_id = %d AND mint_status = 'minted'",
            $group_id
        )));

        if (empty($purchases)) {
            // v41: Enhanced diagnostics — what status ARE the purchases in?
            $status_breakdown = $wpdb->get_results($wpdb->prepare(
                "SELECT mint_status, COUNT(*) as cnt FROM $purchases_table WHERE group_id = %d GROUP BY mint_status",
                $group_id
            ), ARRAY_A);
            $status_str = empty($status_breakdown) ? 'NO ROWS EXIST' : json_encode($status_breakdown);
            mod_log("process_group: ABORT — no paid purchases for group=$group_id — status breakdown: $status_str");

            // v452: Check if all purchases are already minted/claimed by a concurrent call.
            // Previously this unconditionally set group='failed', overwriting 'minted' status
            // when a retry fired after the first call had already completed successfully.
            $all_done = !empty($status_breakdown);
            foreach ($status_breakdown as $sb) {
                if (!in_array($sb['mint_status'], ['minted', 'claimed'])) {
                    $all_done = false;
                    break;
                }
            }
            if ($all_done) {
                // Concurrent call already minted everything — restore correct status
                $wpdb->update($groups_table, [
                    'status' => 'minted', 'updated_at' => $now
                ], ['id' => $group_id]);
                mod_log("process_group: All purchases already minted by concurrent call — group=$group_id status restored to 'minted'");
                exit;
            }

            $wpdb->update($groups_table, [
                'status' => 'failed', 'error_message' => 'No purchases to mint (status: ' . $status_str . ')', 'updated_at' => $now
            ], ['id' => $group_id]);
            exit;
        }

        $purchase_ids = array_column($purchases, 'id');
        mod_log("process_group: Found " . count($purchases) . " purchases to mint: [" . implode(',', $purchase_ids) . "]");

        // Update status to 'minting' now that we're about to begin
        $wpdb->update($groups_table, [
            'status' => 'minting', 'updated_at' => $now
        ], ['id' => $group_id]);

        // ── ACQUIRE XRPL LOCK (blocks until available — fine in background) ──
        if (!acquire_xrpl_lock('imc_mint', 600)) {
            // Could not acquire after timeout — mark for retry
            $wpdb->update($groups_table, [
                'status' => 'paid',
                'error_message' => 'Server busy (lock timeout). Will retry automatically.',
                'updated_at' => $now
            ], ['id' => $group_id]);
            mod_log("process_group: Lock timeout for group=$group_id — reverted to paid for retry");
            exit;
        }

        // ── v242: RE-CHECK GROUP STATUS POST-LOCK ─────────────────────
        // A concurrent retry of this same group may have won the lock race
        // and already completed minting while we were waiting. Re-fetch the
        // group status now that we own the lock before doing any work.
        $group_post_lock = $wpdb->get_row($wpdb->prepare(
            "SELECT status FROM $groups_table WHERE id = %d", $group_id
        ), ARRAY_A);

        if ($group_post_lock && $group_post_lock['status'] === 'minted') {
            release_xrpl_lock('imc_mint');
            mod_log("process_group: Post-lock check — group=$group_id already minted by concurrent call. Exiting cleanly.");
            exit;
        }
        if ($group_post_lock && $group_post_lock['status'] === 'claimed') {
            release_xrpl_lock('imc_mint');
            mod_log("process_group: Post-lock check — group=$group_id already claimed. Exiting cleanly.");
            exit;
        }

        $minted_count = $already_minted; // Start from already-minted count for partial retries
        $errors       = [];

        try {
            $seq = get_account_sequence(IMC_PLATFORM_WALLET);
            if (!$seq) {
                throw new \Exception('Failed to get XRPL account sequence');
            }

            mod_log("process_group: group=$group_id qty={$group['quantity']} seq=$seq tiered=" . ($is_tiered ? 'yes' : 'no'));

            foreach ($purchases as $purchase) {
            touch_xrpl_lock('imc_mint');   // v896 (O): heartbeat while the inline path mints
                $purchase_id = $purchase['id'];

                // Mark this purchase as minting
                $wpdb->update($purchases_table, [
                    'mint_status' => 'minting',
                    'updated_at'  => $now
                ], ['id' => $purchase_id]);

                try {
                    // v277: Double-mint guard — if nftoken_id already saved from a previous
                    // attempt, skip re-minting entirely and go straight to sell offer recovery.
                    // This covers: XRPL mint succeeded → sell offer failed → DB marked 'failed'.
                    if (!empty($purchase['nftoken_id'])) {
                        mod_log("process_group: purchase=$purchase_id already has nftoken_id={$purchase['nftoken_id']} — skipping re-mint, recovering sell offer");

                        // If sell_offer_id already exists too, both steps done — just update status
                        if (!empty($purchase['sell_offer_id'])) {
                            mod_log("process_group: purchase=$purchase_id already fully minted — marking as minted");
                            $wpdb->update($purchases_table, [
                                'mint_status' => 'minted',
                                'updated_at'  => current_time('mysql')
                            ], ['id' => $purchase_id]);
                            $minted_count++;
                            continue;
                        }

                        // Re-create sell offer only
                        $offer = create_sell_offer($purchase['nftoken_id'], $group['buyer_account'], $seq);
                        $seq++;
                        if (!$offer['success']) {
                            throw new \Exception('Sell offer recovery failed: ' . ($offer['error'] ?? $offer['result'] ?? 'unknown'));
                        }
                        $wpdb->update($purchases_table, [
                            'sell_offer_id'      => $offer['offer_id'],
                            'sell_offer_tx_hash' => $offer['hash'],
                            'mint_status'        => 'minted',
                            'minted_at'          => current_time('mysql'),
                            'updated_at'         => current_time('mysql')
                        ], ['id' => $purchase_id]);
                        $minted_count++;
                        mod_log("process_group: Recovered sell offer for purchase=$purchase_id offer={$offer['offer_id']}");
                        continue;
                    }

                    // Track increments so we can rollback on failure (M8/M9 fix)
                    $tier_incremented  = false;
                    $edition_assigned  = false;

                    // ── 1. Pick random tier (inside DB transaction for row lock) ──
                    $tier = null;
                    if ($is_tiered) {
                        $wpdb->query('START TRANSACTION');
                        $tier = pick_random_tier($listing['id']);
                        if ($tier) {
                            // Increment tier minted_count
                            $wpdb->query($wpdb->prepare(
                                "UPDATE $tiers_table SET minted_count = minted_count + 1 WHERE id = %d",
                                $tier['id']
                            ));
                            $tier_incremented = true;
                        }
                        $wpdb->query('COMMIT');
                        // v245: If this is a tiered listing but pick_random_tier returned null,
                        // all tier slots are exhausted. Proceeding would mint an untiered NFT
                        // from a tiered collection — wrong rarity, wrong metadata structure.
                        // Throw so the per-NFT catch marks this purchase 'failed' and rolls back.
                        if (!$tier) {
                            throw new \Exception('All tier slots exhausted — cannot assign tier for this edition (tiered listing)');
                        }
                    }

                    // ── 2. Assign edition number (atomic via LAST_INSERT_ID) ──
                    // v245: Check rows_affected — if 0, the listing hit its cap between
                    // reservation and minting (e.g. stale-lock recovery ran another group first).
                    // v384: LAST_INSERT_ID(expr) stores the value per-connection, immune to
                    // concurrent rollbacks or other connections modifying minted_count.
                    // OE-v1: Open editions have total_editions=0 (unlimited) — use unconditional UPDATE.
                    // Fixed editions keep the minted_count < total_editions guard to prevent overcapping.
                    $is_oe_here = (($listing['edition_type'] ?? 'fixed') === 'open');
                    if ($is_oe_here) {
                        $edition_rows = $wpdb->query($wpdb->prepare(
                            "UPDATE $listings_table SET minted_count = LAST_INSERT_ID(minted_count + 1) WHERE id = %d",
                            $listing['id']
                        ));
                    } else {
                        $edition_rows = $wpdb->query($wpdb->prepare(
                            "UPDATE $listings_table SET minted_count = LAST_INSERT_ID(minted_count + 1) WHERE id = %d AND minted_count < total_editions",
                            $listing['id']
                        ));
                    }
                    if ($edition_rows === 0 || $edition_rows === false) {
                        throw new \Exception('Listing edition cap reached — cannot assign edition number (concurrent mint or cap mismatch)');
                    }
                    $edition_assigned = true;
                    $edition = intval($wpdb->get_var("SELECT LAST_INSERT_ID()"));

                    // ── 3. Build per-edition metadata ──
                    $metadata = build_edition_metadata($listing, $tier, $edition);

                    // ── 4. Pin metadata to IPFS ──
                    $pin_name = sanitize_title($listing['nft_name']) . "-edition-{$edition}.json";
                    $pin      = pin_metadata_to_ipfs($metadata, $pin_name);

                    if (!$pin['success']) {
                        throw new \Exception('IPFS pin failed: ' . ($pin['error'] ?? 'unknown'));
                    }

                    $metadata_uri  = 'ipfs://' . $pin['hash'];
                    $metadata_ipfs = $pin['hash'];

                    // ── 5. Mint NFT on XRPL ──
                    $mint = mint_nft_onchain(
                        $listing['artist_account'],
                        $metadata_uri,
                        intval($listing['collection_taxon']),
                        intval($listing['transfer_fee']),
                        (bool)$listing['is_transferable'],
                        $seq
                    );
                    $seq++;

                    if (!$mint['success']) {
                        // v275: Enhanced error logging — log full response for debugging
                        $mint_err = $mint['error'] ?? null;
                        $mint_res = $mint['result'] ?? null;
                        mod_log("mint_nft_onchain FAILURE: error=" . json_encode($mint_err) . " result=" . json_encode($mint_res));
                        // 'unknown' result means XRPL node returned no engine_result
                        // (can occur under node load or timeout). Mark as failed with retry hint.
                        if ($mint_res === 'unknown' || ($mint_err === null && $mint_res === null)) {
                            throw new \Exception('XRPL mint failed: XRPL node did not return a result (possible timeout or node overload) — please retry');
                        }
                        throw new \Exception('XRPL mint failed: ' . ($mint_err ?? $mint_res ?? 'unknown'));
                    }

                    if (!$mint['nftoken_id']) {
                        throw new \Exception('Mint succeeded but NFTokenID not found');
                    }

                    // v594 token-uniqueness backstop: refuse a mis-extracted token already on another purchase.
                    $__dup = imc_nftoken_collision_owner($mint['nftoken_id'], $purchase_id);
                    if ($__dup) throw new \Exception("NFTokenID {$mint['nftoken_id']} already assigned to purchase $__dup — refusing to save (extraction collision)");

                    // ── 5b. Save nftoken_id immediately after mint (v277 double-mint guard) ──
                    // Saving before the sell offer ensures that if the sell offer TX fails,
                    // a subsequent retry can detect the already-minted NFT and skip re-minting.
                    $wpdb->update($purchases_table, [
                        'nftoken_id'   => $mint['nftoken_id'],
                        'mint_tx_hash' => $mint['hash'],
                        'updated_at'   => current_time('mysql')
                    ], ['id' => $purchase_id]);

                    // ── 6. Create sell offer (0 XRP, destination = buyer) ──
                    $offer = create_sell_offer(
                        $mint['nftoken_id'],
                        $group['buyer_account'],
                        $seq
                    );
                    $seq++;

                    if (!$offer['success']) {
                        throw new \Exception('Sell offer failed: ' . ($offer['error'] ?? $offer['result'] ?? 'unknown'));
                    }

                    // ── 7. Update purchase record ──
                    $__bd = [
                        'edition_number'     => $edition,
                        'tier_id'            => $tier ? $tier['id'] : null,
                        'tier_name'          => $tier ? $tier['tier_name'] : null,
                        'metadata_ipfs'      => $metadata_ipfs,
                        'nftoken_id'         => $mint['nftoken_id'],
                        'mint_tx_hash'       => $mint['hash'],
                        'sell_offer_id'      => $offer['offer_id'],
                        'sell_offer_tx_hash' => $offer['hash'],
                        'mint_status'        => 'minted',
                        'minted_at'          => current_time('mysql'),
                        'updated_at'         => current_time('mysql')
                    ];
                    $__bw = $wpdb->update($purchases_table, $__bd, ['id' => $purchase_id]);
                    if ($__bw === false && imc_b_refused()) {   // B POST-mint: NFT exists; bind it, never claim the edition twice
                        imc_b_dup('process_group', $purchase_id, $group_id, $listing_id, $__bd['edition_number'], $mint['nftoken_id']);
                        $__bd['error_message'] = 'B-DUP: minted on-chain as edition ' . $__bd['edition_number'] . ' but that edition was already live -- edition recorded as 0, reconcile metadata';
                        $__bd['edition_number'] = 0;
                        $wpdb->update($purchases_table, $__bd, ['id' => $purchase_id]);
                    }

                    $minted_count++;
                    
                    // v70: Update allowlist minted_count for this wallet
                    // v728: with the flag on, only allowlist-priced groups consume allocation —
                    // a full-price mint leaves minted_count untouched (product decision 3).
                    $imc_do_increment = true;
                    if (IMC_ALLOWLIST_SCOPED_CONSUME) {
                        $imc_do_increment = intval($group['allowlist_qty'] ?? 0) > 0;
                    }
                    if ($imc_do_increment) {
                    $al_entries_table = $wpdb->prefix . 'imc_allowlist_entries';
                    $al_table = $wpdb->prefix . 'imc_allowlists';
                    $wpdb->query($wpdb->prepare(
                        "UPDATE $al_entries_table e 
                         JOIN $al_table a ON e.allowlist_id = a.id 
                         SET e.minted_count = e.minted_count + 1 
                         WHERE a.listing_id = %d AND e.wallet_address = %s",
                        $listing['id'], $group['buyer_account']
                    ));
                    }

                    // Update group progress (frontend polls this via get_group_status)
                    $wpdb->update($groups_table, [
                        'mint_progress' => $minted_count,
                        'updated_at'    => current_time('mysql')
                    ], ['id' => $group_id]);

                    mod_log("process_group: minted purchase=$purchase_id edition=$edition tier=" . ($tier['tier_name'] ?? 'none') . " nftoken={$mint['nftoken_id']}");

                } catch (\Exception $e) {
                    mod_log("process_group: ERROR minting purchase=$purchase_id: " . $e->getMessage());

                    // v384: Only rollback counts if the NFT was NOT already minted on-chain.
                    // Step 5b saves nftoken_id to DB before the sell offer attempt.
                    // If the NFT exists on-chain, rolling back would free an edition+tier
                    // that are permanently consumed, causing duplicates on future mints.
                    $nft_on_chain = !empty($wpdb->get_var($wpdb->prepare(
                        "SELECT nftoken_id FROM $purchases_table WHERE id = %d", $purchase_id
                    )));

                    // M8 fix (v384 revised): Rollback listing minted_count only if NFT not on-chain
                    if ($edition_assigned && !$nft_on_chain) {
                        $wpdb->query($wpdb->prepare(
                            "UPDATE $listings_table SET minted_count = GREATEST(0, minted_count - 1) WHERE id = %d",
                            $listing['id']
                        ));
                        mod_log("process_group: Rolled back minted_count for listing={$listing['id']} (NFT not on-chain)");
                    } elseif ($edition_assigned && $nft_on_chain) {
                        mod_log("process_group: NOT rolling back minted_count — NFT exists on-chain for purchase=$purchase_id");
                    }

                    // M9 fix (v384 revised): Rollback tier minted_count only if NFT not on-chain
                    if ($tier_incremented && !empty($tier) && !$nft_on_chain) {
                        $wpdb->query($wpdb->prepare(
                            "UPDATE $tiers_table SET minted_count = GREATEST(0, minted_count - 1) WHERE id = %d",
                            $tier['id']
                        ));
                        mod_log("process_group: Rolled back tier minted_count for tier={$tier['id']} (NFT not on-chain)");
                    } elseif ($tier_incremented && !empty($tier) && $nft_on_chain) {
                        mod_log("process_group: NOT rolling back tier minted_count — NFT exists on-chain for purchase=$purchase_id tier={$tier['id']}");
                    }

                    $wpdb->update($purchases_table, [
                        'mint_status'   => 'failed',
                        'error_message' => substr($e->getMessage(), 0, 500),
                        'retry_count'   => intval($purchase['retry_count'] ?? 0) + 1,
                        'updated_at'    => current_time('mysql')
                    ], ['id' => $purchase_id]);

                    $errors[] = "Purchase #{$purchase_id}: " . $e->getMessage();

                    // v389: Re-sync XRPL account sequence after any per-NFT failure.
                    // A failed TX may or may not consume a sequence on-chain. If we
                    // keep incrementing $seq optimistically, every subsequent NFT in
                    // the batch uses the wrong sequence and fails with tefPAST_SEQ.
                    // Re-fetching the live sequence ensures the next iteration starts clean.
                    $fresh_seq = get_account_sequence(IMC_PLATFORM_WALLET);
                    if ($fresh_seq) {
                        mod_log("process_group: Re-synced XRPL sequence after failure: was=$seq now=$fresh_seq");
                        $seq = $fresh_seq;
                    }
                }
            }

        } catch (\Exception $e) {
            mod_log("process_group: FATAL error: " . $e->getMessage());
            $errors[] = 'Fatal: ' . $e->getMessage();
        } finally {
            release_xrpl_lock('imc_mint');
        }

        // Release reserved_count — only for editions that were actually minted.
        // v242: Previously released ALL reserved_count even on partial failure,
        // leaving buyers who paid for e.g. 5 but got 3 with no retry path for
        // the 2 failed editions. Now we only release what succeeded.
        $total_qty = intval($group['quantity']);
        if ($minted_count > 0) {
            // Release only the minted count — failed editions keep their reservation
            // so the buyer can retry and those slots aren't re-sold under them.
            // PARTIAL: only what actually minted; failed editions keep their reservation.
            imc_release_reservation($group_id, $listing['id'], $minted_count, 'process-group-inline-minted');
        } else {
            // Total failure: keep reserved_count so these editions aren't sold to someone else
            mod_log("process_group: Keeping reserved_count for group=$group_id (total failure, buyer paid — awaiting retry)");
        }

        // v242: Three-state final status: full success / partial / total failure.
        // Previously partial was silently reported as 'minted', masking the issue.
        if ($minted_count === $total_qty) {
            $final_status = 'minted';
        } elseif ($minted_count > 0) {
            $final_status = 'partial'; // some minted, some failed — retryable
        } else {
            $final_status = 'failed';  // none minted — retryable
        }
        $error_msg = !empty($errors) ? implode('; ', $errors) : null;

        // Update sold_out status if applicable — Fixed Edition only
        // OE: no supply cap; close is handled by cron/close_open_edition, not sold_out logic
        if (($listing['edition_type'] ?? 'fixed') === 'fixed') {
            $remaining = $wpdb->get_var($wpdb->prepare(
                "SELECT GREATEST(0, CAST(total_editions AS SIGNED) - CAST(minted_count AS SIGNED)) FROM $listings_table WHERE id = %d",
                $listing['id']
            ));
            if (intval($remaining) <= 0) {
                $wpdb->update($listings_table, ['status' => 'sold_out', 'updated_at' => current_time('mysql')], ['id' => $listing['id']]);
                mod_log("process_group: listing {$listing['id']} is now sold_out");
            }
        }

        $wpdb->update($groups_table, [
            'status'        => $final_status,
            'error_message' => $error_msg,
            'updated_at'    => current_time('mysql')
        ], ['id' => $group_id]);

        mod_log("process_group: DONE group=$group_id minted=$minted_count/$total_qty errors=" . count($errors));

        // v59 FIX: Invalidate collection cache so new NFTs appear immediately
        if ($minted_count > 0 && function_exists('imc_invalidate_collection_cache')) {
            $issuer = $listing['artist_account'];
            $taxon = $listing['collection_taxon'];
            imc_invalidate_collection_cache($issuer, $taxon);
            mod_log("process_group: Cache invalidated for collection issuer=$issuer taxon=$taxon");
        }

        // Background work complete — exit cleanly (no HTTP response to send)
        exit;
    }

    // ────────────────────────────────────────────────────────────────────
    // CLAIM NFT (buyer accepts sell offer) — IMPROVED with dispatched_result
    // ────────────────────────────────────────────────────────────────────
    if ($action === 'claim_nft') {
        mod_log("claim_nft: Starting");

        $purchase_id = intval($_POST['purchase_id'] ?? 0);
        $buyer       = sanitize_text_field($_POST['buyer_account'] ?? '');

        if (!$purchase_id || !$buyer) json_error('Missing purchase_id or buyer_account');

        $purchase = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $purchases_table WHERE id = %d AND buyer_account = %s",
            $purchase_id, $buyer
        ), ARRAY_A);

        if (!$purchase)                json_error('Purchase not found');
        if (!$purchase['sell_offer_id']) json_error('NFT not ready for claim yet — minting may still be in progress');
        if ($purchase['delivered'])     json_error('NFT already claimed');

        $txjson = [
            'TransactionType'  => 'NFTokenAcceptOffer',
            'Account'          => $buyer,
            'NFTokenSellOffer' => $purchase['sell_offer_id'],
            'SourceTag'        => 2606240013
        ];

        // --- Joey branch (v547, additive): purchase validated + sell offer exists.
        // Return the vetted AcceptOffer txjson for local signing; the front-end then
        // calls confirm_delivery(purchase_id, buyer, tx_hash) -- the SAME finaliser
        // Xaman uses. No XUMM payload or uuid is created in this branch.
        //
        // J1-c (Sep 2026): the wallet decision was POST-flag OR xrpl_wallet_type cookie.
        // Both can be absent for a genuine Joey user, which is why free-mint claims were
        // failing: imu-wallet.bundle.js DELETES xrpl_wallet_type from its session_delete
        // handler, so a Joey user whose WalletConnect session ended was handed a Xaman
        // XUMM payload he could not sign (the "Xaman wallet not connected" report), and
        // the claim poll then span silently for 30 minutes. wp_imc_sessions.wallet_type
        // is the proof-backed answer (written by imc_session_mint AFTER joey_login_verify
        // checks the AccountSet on-ledger); it simply had no reader until now.
        //
        // ORDER MATTERS, and is deliberate:
        //   1. POST flag  -- the client has a LIVE WalletConnect session right now, which
        //                    is something the session row cannot know.
        //   2. Session row -- proof-backed and un-spoofable; survives cookie deletion.
        //   3. Cookie      -- ONLY when no session resolves at all, so behaviour for that
        //                    case is byte-for-byte what it was before.
        // A stale xrpl_wallet_type=joey on a Xaman user is now correctly OVERRIDDEN by (2).
        $imc_wt = function_exists('imc_session_wallet_type') ? imc_session_wallet_type() : '';
        $imc_is_joey = (($_POST['wallet'] ?? '') === 'joey')
                    || ($imc_wt === 'joey')
                    || ($imc_wt === '' && ($_COOKIE['xrpl_wallet_type'] ?? '') === 'joey');
        mod_log("claim_nft: wallet routing -- post=" . ($_POST['wallet'] ?? '-')
              . " session_type=" . ($imc_wt !== '' ? $imc_wt : '-')
              . " cookie=" . ($_COOKIE['xrpl_wallet_type'] ?? '-')
              . " => " . ($imc_is_joey ? 'joey' : 'xaman'));
        if ($imc_is_joey) {
            json_success([
                'purchase_id'    => $purchase_id,
                'nftoken_id'     => $purchase['nftoken_id'],
                'tier_name'      => $purchase['tier_name'],
                'edition_number' => $purchase['edition_number'],
                'wallet'         => 'joey',
                'txjson'         => $txjson
            ]);
        }

        // v283: Fetch group quantity so create_xumm_payload can choose the right return URL
        $group_quantity = 1;
        if (!empty($purchase['group_id'])) {
            $gq = $wpdb->get_var($wpdb->prepare(
                "SELECT quantity FROM $groups_table WHERE id = %d",
                $purchase['group_id']
            ));
            if ($gq) $group_quantity = intval($gq);
        }

        $payload = create_xumm_payload($txjson, [
            'type'           => 'nft_claim',
            'purchase_id'    => $purchase_id,
            'nftoken_id'     => $purchase['nftoken_id'], // v275: used to build return_url
            'group_quantity' => $group_quantity,          // v283: for multi-mint return URL routing
            'group_id'       => intval($purchase['group_id'] ?? 0), // v304: for recently-minted redirect
        ]);

        if (isset($payload['error'])) json_error('Failed to create claim: ' . $payload['error']);

        $wpdb->update($purchases_table, [
            'claim_xumm_uuid' => $payload['uuid'],
            'updated_at'      => current_time('mysql')
        ], ['id' => $purchase_id]);

        mod_log("claim_nft: purchase=$purchase_id uuid=" . $payload['uuid']);

        json_success([
            'purchase_id'   => $purchase_id,
            'nftoken_id'    => $purchase['nftoken_id'],
            'tier_name'     => $purchase['tier_name'],
            'edition_number' => $purchase['edition_number'],
            'uuid'          => $payload['uuid'],
            'qr_png'        => $payload['refs']['qr_png'] ?? null,
            'deeplink'      => $payload['next']['always'] ?? null,
            'websocket'     => $payload['refs']['websocket_status'] ?? null
        ]);
    }

    // ────────────────────────────────────────────────────────────────────
    // ── v294: RECONCILE DELIVERY ──────────────────────────────────────────
    // Called by the trading hub dashboard when it detects ?claim_complete=1
    // in the URL (the Xaman return_url after a mobile claim). Because the
    // JS poll loop is dead at this point, we verify delivery via ledger_entry
    // rather than relying on a tx_hash from the poll. If the sell offer is
    // gone from the ledger, the NFT was accepted and we mark it delivered.
    // ────────────────────────────────────────────────────────────────────
    if ($action === 'reconcile_delivery') {
        $purchase_id = intval($_POST['purchase_id'] ?? 0);
        $buyer       = sanitize_text_field($_POST['buyer_account'] ?? '');

        if (!$purchase_id || !$buyer) json_error('Missing purchase_id or buyer_account');

        $purchase = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $purchases_table WHERE id = %d AND buyer_account = %s",
            $purchase_id, $buyer
        ), ARRAY_A);

        if (!$purchase) json_error('Purchase not found');

        // Already delivered — idempotent
        if (!empty($purchase['delivered'])) {
            json_success(['status' => 'already_delivered', 'purchase_id' => $purchase_id]);
        }

        if (empty($purchase['sell_offer_id'])) {
            json_error('No sell offer ID on record — cannot reconcile');
        }

        $check = xrpl_rpc('ledger_entry', [
            'nft_offer'    => $purchase['sell_offer_id'],
            'ledger_index' => 'validated'
        ]);

        $offer_gone = isset($check['error']) && $check['error'] === 'entryNotFound';

        if (!$offer_gone) {
            // Offer still exists — NFT not yet claimed on-chain
            json_success(['status' => 'pending', 'purchase_id' => $purchase_id,
                         'message' => 'NFT not yet claimed on-chain — sell offer still exists']);
        }

        // Offer is gone — accepted on-chain. Mark delivered.
        $now = current_time('mysql');
        $wpdb->update($purchases_table, [
            'delivered'    => 1,
            'delivered_at' => $now,
            'mint_status'  => 'claimed',
            'updated_at'   => $now
        ], ['id' => $purchase_id]);

        // Update group claim_progress
        if ($purchase['group_id']) {
            $claimed_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $purchases_table WHERE group_id = %d AND delivered = 1",
                $purchase['group_id']
            ));
            $group = $wpdb->get_row($wpdb->prepare(
                "SELECT quantity FROM $groups_table WHERE id = %d",
                $purchase['group_id']
            ), ARRAY_A);
            $g_updates = ['claim_progress' => $claimed_count, 'updated_at' => $now];
            if ($group && intval($claimed_count) >= intval($group['quantity'])) {
                $g_updates['status'] = 'claimed';
            }
            $wpdb->update($groups_table, $g_updates, ['id' => $purchase['group_id']]);
        }

        mod_log("reconcile_delivery: purchase=$purchase_id marked delivered via ledger reconcile (offer gone)");

        json_success([
            'status'      => 'delivered',
            'purchase_id' => $purchase_id,
            'nftoken_id'  => $purchase['nftoken_id']
        ]);
    }

    // CONFIRM DELIVERY (Task #15 — mark NFT as delivered after claim)
    // ────────────────────────────────────────────────────────────────────
    if ($action === 'confirm_delivery') {
        mod_log("confirm_delivery: Starting");

        $purchase_id = intval($_POST['purchase_id'] ?? 0);
        $buyer       = sanitize_text_field($_POST['buyer_account'] ?? '');
        $tx_hash     = sanitize_text_field($_POST['tx_hash'] ?? '');

        if (!$purchase_id || !$buyer) json_error('Missing purchase_id or buyer_account');

        $purchase = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $purchases_table WHERE id = %d AND buyer_account = %s",
            $purchase_id, $buyer
        ), ARRAY_A);

        if (!$purchase) json_error('Purchase not found');

        // v242: State guard — only allow delivery confirmation when the NFT is
        // actually in the minted state with a sell offer created. Prevents
        // malicious or accidental calls from marking pending/failed purchases as delivered.
        if ($purchase['mint_status'] !== 'minted') {
            mod_log("confirm_delivery: BLOCKED — purchase=$purchase_id is in status '{$purchase['mint_status']}', not 'minted'");
            json_error('Cannot confirm delivery: NFT is not in minted state (status: ' . $purchase['mint_status'] . ')');
        }
        if (empty($purchase['sell_offer_id'])) {
            mod_log("confirm_delivery: BLOCKED — purchase=$purchase_id has no sell_offer_id");
            json_error('Cannot confirm delivery: no sell offer exists for this purchase');
        }

        // S1-c: verify the claim ON-CHAIN before marking delivered. A gone sell offer is
        // proof the buyer accepted it; if the offer still exists the claim has not happened
        // (or is mid-propagation), so DEFER: write nothing, return pending. The xrpl-listener
        // (accept_offer) and the client reconcile lanes finalise delivered=1 on the real event.
        // Mirrors reconcile_delivery's proven ledger_entry check. Closes F-3 (forged delivery).
        $s1c_check = xrpl_rpc('ledger_entry', [
            'nft_offer'    => $purchase['sell_offer_id'],
            'ledger_index' => 'validated'
        ]);
        $s1c_offer_gone = isset($s1c_check['error']) && $s1c_check['error'] === 'entryNotFound';
        if (!$s1c_offer_gone) {
            mod_log("confirm_delivery: DEFERRED purchase=$purchase_id sell offer still on ledger; leaving to reconcile/listener");
            json_success(['status' => 'pending', 'purchase_id' => $purchase_id,
                          'message' => 'Claim confirming on-chain, will finalise shortly']);
        }

        $now = current_time('mysql');
        $wpdb->update($purchases_table, [
            'claim_tx_hash' => $tx_hash,
            'delivered'     => 1,
            'delivered_at'  => $now,
            'mint_status'   => 'claimed',
            'updated_at'    => $now
        ], ['id' => $purchase_id]);

        // Update group claim_progress
        if ($purchase['group_id']) {
            $claimed_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $purchases_table WHERE group_id = %d AND delivered = 1",
                $purchase['group_id']
            ));
            $group = $wpdb->get_row($wpdb->prepare(
                "SELECT quantity FROM $groups_table WHERE id = %d",
                $purchase['group_id']
            ), ARRAY_A);

            $updates = ['claim_progress' => $claimed_count, 'updated_at' => $now];
            if ($group && intval($claimed_count) >= intval($group['quantity'])) {
                $updates['status'] = 'claimed';
            }
            $wpdb->update($groups_table, $updates, ['id' => $purchase['group_id']]);
        }

        mod_log("confirm_delivery: purchase=$purchase_id delivered tx=$tx_hash");

        json_success([
            'purchase_id' => $purchase_id,
            'delivered'   => true,
            'claim_tx'    => $tx_hash
        ]);
    }

    // ────────────────────────────────────────────────────────────────────
    // v885 (Defect Q): initiate_purchase + process_purchase REMOVED.
    //
    // Both were legacy shims with ZERO callers -- verified across the whole theme and the VPS
    // tree (mint-on-demand.js sends only: reserve_purchase, process_group, claim_nft,
    // confirm_delivery, create, publish, create_fee_payment, record_joey_tx). Both sat behind
    // the same nonce gate as everything else while enforcing NO mint_limit_per_wallet check,
    // so either could be POSTed directly to bypass a listing's stated per-wallet cap.
    // initiate_purchase also duplicated the reservation logic without the launch-date,
    // allowlist, progressive-pricing or transfer-fee handling reserve_purchase has gained.
    //
    // Deleted rather than guarded: nothing calls them, so there is no behaviour to preserve.
    // ────────────────────────────────────────────────────────────────────

    // ────────────────────────────────────────────────────────────────────
    // CREATOR SELF-MINT (v43b — FIXED)
    // Mint NFT(s) and create 0 XRP sell offers to the artist's wallet.
    // The artist claims via Pending Claims in the trading hub dashboard,
    // signing NFTokenAcceptOffer in Xaman — same UX as a buyer claim.
    // ────────────────────────────────────────────────────────────────────
    if ($action === 'creator_mint') {
        mod_log("creator_mint: Starting");

        $listing_id = intval($_POST['listing_id'] ?? 0);
        $artist     = sanitize_text_field($_POST['artist_account'] ?? '');
        $qty        = max(1, min(10, intval($_POST['quantity'] ?? 1)));

        if (!$listing_id || !$artist) json_error('Missing listing_id or artist_account');

        if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $artist)) {
            json_error('Invalid artist wallet address');
        }

        // v885 (Defect Z): artist_account arrives from the CLIENT, and the only prior check was
        // that it matched listing.artist_account -- which is PUBLIC on every listing page. Anyone
        // holding a session nonce could therefore POST creator_mint for any active, fee-paid
        // listing and mint out that creator's entire supply to the creator's own wallet before a
        // single buyer saw it. Nothing could be stolen (Destination is the artist) but the drop
        // was destroyable by a stranger.
        //
        // The creator dashboard already resolves identity server-side via imc_session_wallet()
        // (page-creator-dashboard.php L24) and passes it to the client as CONFIG.account, so a
        // legitimate creator ALWAYS holds a valid session here -- a hard check cannot lock them
        // out. imc_session_require_wallet() is the same resolver artist-profile / watchlist /
        // chat / tasks already use, so this inherits the IMC_SESSION_TOKEN_ONLY gate for free.
        $cm_auth = function_exists('imc_session_require_wallet')
            ? imc_session_require_wallet($artist)
            : ['ok' => false, 'wallet' => '', 'error' => 'auth'];
        if (empty($cm_auth['ok'])) {
            mod_log("creator_mint: BLOCKED (" . ($cm_auth['error'] ?? 'auth') . ") posted=$artist session=" . (!empty($cm_auth['wallet']) ? $cm_auth['wallet'] : 'none'));
            json_error('Not authenticated as the listing owner', 403);
        }
        $artist = $cm_auth['wallet']; // the SESSION is authoritative from here on

        $listing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $listings_table WHERE id = %d", $listing_id
        ), ARRAY_A);

        if (!$listing) json_error('Listing not found');
        if ($listing['artist_account'] !== $artist) json_error('You are not the owner of this listing');
        if ($listing['status'] !== 'active') json_error('Listing must be active to mint');
        if (!$listing['platform_fee_paid']) json_error('Platform fee must be paid first');

        $minted  = intval($listing['minted_count']);
        $total   = intval($listing['total_editions']);
        $is_oe   = (($listing['edition_type'] ?? 'fixed') === 'open');

        if (!$is_oe && ($minted + $qty) > $total) {
            $remaining = $total - $minted;
            json_error("Only $remaining edition(s) remaining (requested $qty)");
        }

        if (!acquire_xrpl_lock('imc_mint', 300)) {
            json_error('Server busy — please try again in a moment');
        }

        $seq = get_account_sequence(IMC_PLATFORM_WALLET);
        if (!$seq) {
            release_xrpl_lock('imc_mint');
            json_error('Could not get XRPL account sequence');
        }

        $is_tiered    = (bool)($listing['has_tiers'] ?? false);
        $minted_count = 0;
        $minted_data  = [];
        $errors       = [];
        $now          = current_time('mysql');
        $expires_at   = date('Y-m-d H:i:s', strtotime($now . ' +1 hour'));

        // v43b: Create a purchase group (mirrors buyer flow for consistency)
        $wpdb->insert($groups_table, [
            'listing_id'             => $listing_id,
            'buyer_account'          => $artist,
            'quantity'               => $qty,
            'total_price_xrp'        => 0,
            'payment_verified'       => 1,
            'payment_verified_at'    => $now,
            'reservation_expires_at' => $expires_at,
            'status'                 => 'minting',
            'created_at'             => $now,
            'updated_at'             => $now,
        ]);
        $group_id = $wpdb->insert_id;
        if (!$group_id) {
            release_xrpl_lock('imc_mint');
            json_error('Failed to create purchase group');
        }
        mod_log("creator_mint: group_id=$group_id listing=$listing_id artist=$artist qty=$qty");

        for ($i = 0; $i < $qty; $i++) {
            touch_xrpl_lock('imc_mint');   // v896 (O): heartbeat — the server still accepts qty up to 10
            // v885 (Defect AA): reset EVERY per-iteration variable. Without this a later
            // failure inherits the previous iteration's $mint/$edition/$tier and the rollback
            // decision below is made on stale data.
            $tier             = null;
            $mint             = null;
            $edition          = null;
            $tier_incremented = false;
            $edition_assigned = false;
            try {
                // 1. Pick tier if tiered
                if ($is_tiered) {
                    $wpdb->query('START TRANSACTION');
                    $tier = pick_random_tier($listing_id);
                    if ($tier) {
                        $wpdb->query($wpdb->prepare(
                            "UPDATE $tiers_table SET minted_count = minted_count + 1 WHERE id = %d", $tier['id']
                        ));
                        $tier_incremented = true; // v885 (AA): needed by the rollback below
                    }
                    $wpdb->query('COMMIT');
                    if (!$tier) { $errors[] = "Tier slots exhausted at edition " . ($i + 1); break; }
                }

                // 2. Increment edition count (atomic via LAST_INSERT_ID — v384)
                if ($is_oe) {
                    $wpdb->query($wpdb->prepare(
                        "UPDATE $listings_table SET minted_count = LAST_INSERT_ID(minted_count + 1) WHERE id = %d", $listing_id
                    ));
                } else {
                    $rows = $wpdb->query($wpdb->prepare(
                        "UPDATE $listings_table SET minted_count = LAST_INSERT_ID(minted_count + 1) WHERE id = %d AND minted_count < total_editions", $listing_id
                    ));
                    if (!$rows) { $errors[] = "Edition cap reached at " . ($i + 1); break; }
                }
                $edition_assigned = true; // v885 (AA): needed by the rollback below
                $edition = intval($wpdb->get_var("SELECT LAST_INSERT_ID()"));

                // 3. Build metadata + pin to IPFS
                $metadata = build_edition_metadata($listing, $tier, $edition);
                $pin_name = sanitize_title($listing['nft_name']) . "-edition-{$edition}.json";
                $pin      = pin_metadata_to_ipfs($metadata, $pin_name);
                if (!$pin['success']) throw new \Exception('IPFS pin failed');

                $metadata_uri = 'ipfs://' . $pin['hash'];

                // 4. Mint NFT on XRPL
                $mint = mint_nft_onchain(
                    $listing['artist_account'],
                    $metadata_uri,
                    intval($listing['collection_taxon']),
                    intval($listing['transfer_fee']),
                    (bool)$listing['is_transferable'],
                    $seq
                );
                $seq++;
                if (!$mint['success']) throw new \Exception('XRPL mint failed: ' . ($mint['error'] ?? 'unknown'));
                if (!$mint['nftoken_id']) throw new \Exception('Mint succeeded but no NFTokenID');

                // v594 token-uniqueness backstop: refuse a mis-extracted token already owned by another purchase.
                $__dup = imc_nftoken_collision_owner($mint['nftoken_id'], 0);
                if ($__dup) throw new \Exception("NFTokenID {$mint['nftoken_id']} already assigned to purchase $__dup — refusing to mint (extraction collision)");

                // 5. Create 0 XRP sell offer to the artist
                $offer = create_sell_offer($mint['nftoken_id'], $artist, $seq);
                $seq++;
                if (!$offer['success']) throw new \Exception('Sell offer failed: ' . ($offer['error'] ?? 'unknown'));

                // 6. Insert purchase record (correct column names, delivered=0)
                $__bd = [
                    'group_id'           => $group_id,
                    'listing_id'         => $listing_id,
                    'buyer_account'      => $artist,
                    'artist_account'     => $artist,
                    'edition_number'     => $edition,
                    'tier_id'            => $tier['id'] ?? null,
                    'tier_name'          => $tier['tier_name'] ?? null,
                    'metadata_ipfs'      => $pin['hash'],
                    'price_xrp'          => 0,
                    'price_currency'     => 'XRP',
                    'payment_verified'   => 1,
                    'payment_verified_at'=> $now,
                    'nftoken_id'         => $mint['nftoken_id'],
                    'mint_tx_hash'       => $mint['hash'],
                    'sell_offer_id'      => $offer['offer_id'],
                    'sell_offer_tx_hash' => $offer['hash'],
                    'mint_status'        => 'minted',
                    'delivered'          => 0,
                    'minted_at'          => $now,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ];
                $__bw = $wpdb->insert($purchases_table, $__bd);
                if ($__bw === false && imc_b_refused()) {   // B POST-mint: NFT exists; record it, never claim the edition twice
                    imc_b_dup('creator_mint', 0, $group_id, $listing_id, $__bd['edition_number'], (string) ($__bd['nftoken_id'] ?? ''));
                    $__bd['error_message'] = 'B-DUP: minted on-chain as edition ' . $__bd['edition_number'] . ' but that edition was already live -- edition recorded as 0, reconcile metadata';
                    $__bd['edition_number'] = 0;
                    $wpdb->insert($purchases_table, $__bd);
                }

                $minted_count++;
                $minted_data[] = [
                    'edition'       => $edition,
                    'nftoken_id'    => $mint['nftoken_id'],
                    'sell_offer_id' => $offer['offer_id'],
                    'tier'          => $tier['tier_name'] ?? null,
                ];

                mod_log("creator_mint: edition=$edition nft={$mint['nftoken_id']} offer={$offer['offer_id']} artist=$artist");

            } catch (\Exception $e) {
                $errors[] = "Edition " . ($i + 1) . ": " . $e->getMessage();
                mod_log("creator_mint: FAILED edition " . ($i + 1) . ": " . $e->getMessage(), 'ERROR');

                // v885 (Defect AA): this block previously ONLY logged -- no rollback, no record.
                // Two consequences: (1) a failure after the edition allocator silently LEAKED an
                // edition forever, and (2) a mint that succeeded but whose offer failed left an
                // NFT on-chain with NO purchase row at all, because the row is only inserted
                // after the offer. Mirror vps_mint_failed's rule: roll back ONLY when nothing
                // reached the ledger; when it did, keep the counters and record the orphan so it
                // is recoverable instead of invisible.
                $cm_onchain = !empty($mint['nftoken_id']);
                if (!$cm_onchain) {
                    if ($edition_assigned) {
                        $wpdb->query($wpdb->prepare(
                            "UPDATE $listings_table SET minted_count = GREATEST(0, minted_count - 1) WHERE id = %d", $listing_id
                        ));
                        mod_log("creator_mint: rolled back minted_count (nothing on-chain) listing=$listing_id");
                    }
                    if ($tier_incremented && !empty($tier['id'])) {
                        $wpdb->query($wpdb->prepare(
                            "UPDATE $tiers_table SET minted_count = GREATEST(0, minted_count - 1) WHERE id = %d", $tier['id']
                        ));
                        mod_log("creator_mint: rolled back tier minted_count (nothing on-chain) tier={$tier['id']}");
                    }
                } else {
                    // NFT IS on-chain but the offer failed. Counters stay (the edition is really
                    // consumed). Record the purchase so the standard offer-replay recovery can
                    // find it -- exactly what the stranded NFTs needed and did not have.
                    $cm_dup = imc_nftoken_collision_owner($mint['nftoken_id'], 0);
                    if (!$cm_dup) {
                        $__bd = [
                            'group_id'        => $group_id,
                            'listing_id'      => $listing_id,
                            'buyer_account'   => $artist,
                            'artist_account'  => $artist,
                            'edition_number'  => intval($edition),
                            'tier_id'         => $tier['id'] ?? null,
                            'tier_name'       => $tier['tier_name'] ?? null,
                            'price_xrp'       => 0,
                            'price_currency'  => 'XRP',
                            'payment_verified'=> 1,
                            'nftoken_id'      => $mint['nftoken_id'],
                            'mint_tx_hash'    => $mint['hash'] ?? '',
                            'mint_status'     => 'failed',
                            'error_message'   => substr('Minted on-chain, offer failed: ' . $e->getMessage(), 0, 500),
                            'delivered'       => 0,
                            'created_at'      => $now,
                            'updated_at'      => $now,
                        ];
                        $__bw = $wpdb->insert($purchases_table, $__bd);
                        if ($__bw === false && imc_b_refused()) {   // B POST-mint: NFT exists; record it, never claim the edition twice
                            imc_b_dup('creator_mint-orphan', 0, $group_id, $listing_id, $__bd['edition_number'], (string) ($__bd['nftoken_id'] ?? ''));
                            $__bd['error_message'] = 'B-DUP: minted on-chain as edition ' . $__bd['edition_number'] . ' but that edition was already live -- edition recorded as 0, reconcile metadata';
                            $__bd['edition_number'] = 0;
                            $wpdb->insert($purchases_table, $__bd);
                        }
                        mod_log("creator_mint: RECORDED orphan nftoken={$mint['nftoken_id']} edition=$edition (minted on-chain, offer failed) -- recoverable via offer replay");
                    } else {
                        mod_log("creator_mint: NOT recording nftoken={$mint['nftoken_id']} -- already owned by purchase $cm_dup");
                    }
                }
            }
        }

        // Update group status
        $group_status = ($minted_count === $qty) ? 'minted' : ($minted_count > 0 ? 'partial' : 'failed');
        $wpdb->update($groups_table, [
            'status'        => $group_status,
            'mint_progress' => $minted_count,
            'updated_at'    => $now,
        ], ['id' => $group_id]);

        release_xrpl_lock('imc_mint');

        mod_log("creator_mint: DONE listing=$listing_id group=$group_id artist=$artist minted=$minted_count/$qty errors=" . count($errors));

        json_success([
            'minted_count' => $minted_count,
            'requested'    => $qty,
            'group_id'     => $group_id,
            'minted'       => $minted_data,
            'errors'       => $errors,
        ]);
    }

    // ────────────────────────────────────────────────────────────────────
    // ADMIN: FIX ORPHANED CREATOR-MINTED NFTs (v43b)
    // Creates sell offers for NFTs that were minted but have no DB records.
    // One-time remediation tool.
    // ────────────────────────────────────────────────────────────────────
    admin_fix_handler:
    if ($action === 'admin_fix_creator_mint') {
        mod_log("admin_fix_creator_mint: Starting");

        $listing_id = intval($_POST['listing_id'] ?? 0);
        $artist     = sanitize_text_field($_POST['artist_account'] ?? '');
        $nft_ids    = $_POST['nft_ids'] ?? '';

        if (!$listing_id || !$artist || !$nft_ids) json_error('Missing listing_id, artist_account, or nft_ids');

        // Security: verify caller is the listing artist
        $listing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $listings_table WHERE id = %d", $listing_id
        ), ARRAY_A);
        if (!$listing) json_error('Listing not found');
        if ($listing['artist_account'] !== $artist) json_error('Not your listing');

        $ids = array_map('trim', explode(',', $nft_ids));
        $ids = array_filter($ids, function($id) {
            return preg_match('/^[0-9A-Fa-f]{64}$/', $id);
        });
        if (empty($ids)) json_error('No valid NFT IDs');

        if (!acquire_xrpl_lock('imc_mint', 300)) {
            json_error('Server busy');
        }

        $seq = get_account_sequence(IMC_PLATFORM_WALLET);
        if (!$seq) {
            release_xrpl_lock('imc_mint');
            json_error('Could not get XRPL sequence');
        }

        $now        = current_time('mysql');
        $expires_at = date('Y-m-d H:i:s', strtotime($now . ' +1 hour'));
        $qty        = count($ids);

        // Create purchase group
        $wpdb->insert($groups_table, [
            'listing_id'             => $listing_id,
            'buyer_account'          => $artist,
            'quantity'               => $qty,
            'total_price_xrp'        => 0,
            'payment_verified'       => 1,
            'payment_verified_at'    => $now,
            'reservation_expires_at' => $expires_at,
            'status'                 => 'minting',
            'created_at'             => $now,
            'updated_at'             => $now,
        ]);
        $group_id = $wpdb->insert_id;

        $fixed = 0;
        $errors = [];

        foreach ($ids as $i => $nft_id) {
            touch_xrpl_lock('imc_mint');   // v896 (O): heartbeat while repairing
            try {
                // Check not already in DB
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $purchases_table WHERE nftoken_id = %s", $nft_id
                ));
                if ($existing) { $errors[] = "$nft_id already in DB (purchase #$existing)"; continue; }

                // Create sell offer
                $offer = create_sell_offer($nft_id, $artist, $seq);
                $seq++;
                if (!$offer['success']) throw new \Exception('Sell offer failed: ' . ($offer['error'] ?? 'unknown'));

                // Edition numbering: minted_count was already incremented during
                // original broken creator_mint. Assign sequentially by position.
                $edition = $i + 1;

                // Insert purchase record
                $__bd = [
                    'group_id'           => $group_id,
                    'listing_id'         => $listing_id,
                    'buyer_account'      => $artist,
                    'artist_account'     => $artist,
                    'edition_number'     => $edition,
                    'price_xrp'          => 0,
                    'price_currency'     => 'XRP',
                    'payment_verified'   => 1,
                    'payment_verified_at'=> $now,
                    'nftoken_id'         => $nft_id,
                    'sell_offer_id'      => $offer['offer_id'],
                    'sell_offer_tx_hash' => $offer['hash'],
                    'mint_status'        => 'minted',
                    'delivered'          => 0,
                    'minted_at'          => $now,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ];
                $__bw = $wpdb->insert($purchases_table, $__bd);
                if ($__bw === false && imc_b_refused()) {   // B POST-mint: NFT exists; record it, never claim the edition twice
                    imc_b_dup('admin_fix_creator_mint', 0, $group_id, $listing_id, $__bd['edition_number'], (string) ($__bd['nftoken_id'] ?? ''));
                    $__bd['error_message'] = 'B-DUP: minted on-chain as edition ' . $__bd['edition_number'] . ' but that edition was already live -- edition recorded as 0, reconcile metadata';
                    $__bd['edition_number'] = 0;
                    $wpdb->insert($purchases_table, $__bd);
                }

                $fixed++;
                mod_log("admin_fix_creator_mint: nft=$nft_id offer={$offer['offer_id']} edition=$edition");

            } catch (\Exception $e) {
                $errors[] = "$nft_id: " . $e->getMessage();
                mod_log("admin_fix_creator_mint: FAILED $nft_id: " . $e->getMessage(), 'ERROR');
            }
        }

        $wpdb->update($groups_table, [
            'status'        => ($fixed === $qty) ? 'minted' : 'partial',
            'mint_progress' => $fixed,
            'updated_at'    => $now,
        ], ['id' => $group_id]);

        release_xrpl_lock('imc_mint');

        mod_log("admin_fix_creator_mint: DONE group=$group_id fixed=$fixed/$qty errors=" . count($errors));

        json_success([
            'fixed'    => $fixed,
            'total'    => $qty,
            'group_id' => $group_id,
            'errors'   => $errors,
        ]);
    }

    // ────────────────────────────────────────────────────────────────────
    // ADMIN: FIX TIER MAPPING FOR ORPHANED CREATOR-MINTED NFTs (v43c)
    // For purchases missing metadata_ipfs: queries XRPL nft_info to get
    // the on-chain URI, backfills metadata_ipfs, fetches metadata from
    // IPFS, matches image CID to tiers, updates tier_id + tier_name.
    // ────────────────────────────────────────────────────────────────────
    admin_fix_tier_handler:
    if ($action === 'admin_fix_tier_mapping') {
        mod_log("admin_fix_tier_mapping: Starting");

        $group_id   = intval($_POST['group_id'] ?? 0);
        $listing_id = intval($_POST['listing_id'] ?? 0);

        if (!$group_id || !$listing_id) json_error('Missing group_id or listing_id');

        // Get all purchases in this group
        $purchases = $wpdb->get_results($wpdb->prepare(
            "SELECT id, metadata_ipfs, nftoken_id, tier_id FROM $purchases_table WHERE group_id = %d AND listing_id = %d",
            $group_id, $listing_id
        ), ARRAY_A);

        if (!$purchases) json_error('No purchases found for this group');

        // Get all tiers for this listing (build lookup by cover_ipfs CID)
        $tiers_table = $wpdb->prefix . 'imc_listing_tiers';
        $tiers = $wpdb->get_results($wpdb->prepare(
            "SELECT id, tier_name, tier_order, cover_ipfs FROM $tiers_table WHERE listing_id = %d",
            $listing_id
        ), ARRAY_A);

        // Build CID → tier lookup
        $cid_to_tier = [];
        foreach ($tiers as $t) {
            $cid = str_replace('ipfs://', '', $t['cover_ipfs'] ?? '');
            if ($cid) $cid_to_tier[$cid] = $t;
        }

        $updated = 0;
        $errors  = [];

        foreach ($purchases as $p) {
            $purchase_id  = $p['id'];
            $nftoken_id   = $p['nftoken_id'];
            $metadata_cid = $p['metadata_ipfs'];

            if ($p['tier_id']) { $errors[] = "#$purchase_id: already has tier"; continue; }

            // Step 1: If metadata_ipfs is missing, query XRPL for the NFT's URI
            if (!$metadata_cid && $nftoken_id) {
                mod_log("admin_fix_tier_mapping: #$purchase_id — querying XRPL nft_info for $nftoken_id");
                $nft_result = xrpl_rpc('nft_info', ['nft_id' => $nftoken_id]);

                $uri_hex = $nft_result['uri'] ?? '';
                if (!$uri_hex) {
                    $errors[] = "#$purchase_id: XRPL nft_info returned no URI";
                    continue;
                }

                // URI is hex-encoded on XRPL — decode it
                $uri = hex2bin($uri_hex);
                if (!$uri) { $errors[] = "#$purchase_id: hex decode failed"; continue; }

                // Extract IPFS CID from URI (format: ipfs://QmXXX...)
                $metadata_cid = str_replace('ipfs://', '', $uri);
                if (!$metadata_cid) { $errors[] = "#$purchase_id: no IPFS CID in URI: $uri"; continue; }

                // Backfill metadata_ipfs in purchase record
                $wpdb->update($purchases_table, [
                    'metadata_ipfs' => $metadata_cid,
                    'updated_at'    => current_time('mysql'),
                ], ['id' => $purchase_id]);
                mod_log("admin_fix_tier_mapping: #$purchase_id — backfilled metadata_ipfs=$metadata_cid");
            }

            if (!$metadata_cid) { $errors[] = "#$purchase_id: still no metadata_ipfs"; continue; }

            // Step 2: Fetch metadata JSON from IPFS
            $meta_url = "https://gateway.pinata.cloud/ipfs/$metadata_cid";
            $ch = curl_init($meta_url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $meta_json = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code !== 200 || !$meta_json) {
                $errors[] = "#$purchase_id: IPFS fetch failed (HTTP $http_code)";
                continue;
            }

            $meta = json_decode($meta_json, true);
            if (!$meta) { $errors[] = "#$purchase_id: invalid JSON"; continue; }

            // Step 3: Extract image CID and match to tier
            $image_uri = $meta['image'] ?? '';
            $image_cid = str_replace('ipfs://', '', $image_uri);

            if (!$image_cid) { $errors[] = "#$purchase_id: no image in metadata"; continue; }

            $matched_tier = $cid_to_tier[$image_cid] ?? null;
            if (!$matched_tier) {
                $errors[] = "#$purchase_id: image CID not found in tiers (CID: " . substr($image_cid, 0, 20) . "...)";
                continue;
            }

            // Step 4: Update purchase record with tier info
            $wpdb->update($purchases_table, [
                'tier_id'    => $matched_tier['id'],
                'tier_name'  => $matched_tier['tier_name'],
                'updated_at' => current_time('mysql'),
            ], ['id' => $purchase_id]);

            $updated++;
            mod_log("admin_fix_tier_mapping: #$purchase_id → tier={$matched_tier['tier_name']} (id={$matched_tier['id']})");
        }

        mod_log("admin_fix_tier_mapping: DONE group=$group_id updated=$updated/" . count($purchases));

        json_success([
            'updated' => $updated,
            'total'   => count($purchases),
            'errors'  => $errors,
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // v397: VPS MINT WORKER ENDPOINTS
    // Lightweight REST endpoints for VPS-delegated minting. Each <200ms.
    // Auth: IMC_COMP_ADMIN_KEY verified above via goto vps_mint_endpoints.
    // ════════════════════════════════════════════════════════════════════════
    vps_mint_endpoints:

    if ($action === 'vps_batch_next') {
        // M5: the leader chain asks "who paid next?" READ-ONLY -- no claim here: the mint
        // lock is the claim (vps_batch_start sets 'minting' under it; a second taker exits
        // on already_minted, v452), so two colliding chains self-terminate. Ordered by
        // created_at,id -- the same key M7b's queue_position promises buyers. The
        // compensation lane's group is 'paid' for only milliseconds before admin_compensate
        // flips it to 'minting'; if selected in that window the chained CLI waits on the
        // lock admin_compensate holds, then exits on already_minted. Harmless.
        $exclude = intval($_POST['exclude_group_id'] ?? 0);
        $mlimit = max(1, min(25, intval($_POST['limit'] ?? 1)));   // M6-a: the leader may ask for a slate
        $mrows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, quantity FROM $groups_table
              WHERE status = 'paid' AND payment_verified = 1 AND mint_progress < quantity AND id <> %d
              ORDER BY created_at ASC, id ASC LIMIT %d", $exclude, $mlimit
        ), ARRAY_A);
        $mnext = $mrows[0] ?? null;
        $mqueue = 0;
        if ($mnext) {
            $mqueue = intval($wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $groups_table WHERE status = 'paid' AND payment_verified = 1 AND mint_progress < quantity AND id <> %d", $exclude
            )));
            mod_log("vps_batch_next: -> group={$mnext['id']} (queue=$mqueue, exclude=$exclude)");
        }
        json_success(['group_id' => $mnext['id'] ?? null, 'queue_ahead' => max(0, $mqueue - 1), 'candidates' => $mrows]);   // M6-a: additive
    }

    // ==========================================================================================
    // vps_redrive_free (Phase 2 / F2, 2 Sep 2026) -- the missing automated retry for FREE mints.
    //
    // After any row fails, vps_batch_finish leaves the group 'failed' or 'partial'. The ONLY path
    // that moved it back to 'paid' was process_group -- client-side, nonce-gated -- so a free-mint
    // buyer who closed the tab sat there until they came back (and cleanup Pass 3 could release
    // their hold at 24h before F3). retry-failed-mints excludes FREE_MINT structurally (it goes via
    // admin_compensate, which needs a 64-char payment hash); redrive-free-mints only saw
    // paid/progress-0. This action is what redrive-free-mints now calls first for failed/partial
    // free groups. It does EXACTLY what process_group's retry block does -- the same reset UPDATE,
    // the same three permanent-error exclusions -- then CAS-promotes the group to 'paid' so
    // vps_batch_claim can take it. It does NOT dispatch; the cron dispatches, as it already does.
    //
    // NOTHING-RESETTABLE GUARD: if no row can be reset (every failure is a permanent
    // temMALFORMED/tecNO_PERMISSION, or the good rows are already claimed and nothing failed), it
    // refuses and flags the group once. Without this, vps_batch_claim would find no rows, write
    // 'failed' ("No purchases to mint"), and the cron would cycle failed->paid->failed every 10
    // minutes until its attempt cap. Those groups are M2b's (evidence-gated admin release).
    // ==========================================================================================
    if ($action === 'vps_redrive_free') {
        $rf_gid = intval($_POST['group_id'] ?? 0);
        if (!$rf_gid) json_error('Missing group_id');
        $rf_group = $wpdb->get_row($wpdb->prepare("SELECT id, status, payment_tx_hash, payment_verified, quantity, mint_progress FROM $groups_table WHERE id = %d", $rf_gid), ARRAY_A);
        if (!$rf_group) json_success(['redriven' => false, 'reason' => 'not-found']);
        if ($rf_group['payment_tx_hash'] !== 'FREE_MINT' || intval($rf_group['payment_verified']) !== 1) {
            json_success(['redriven' => false, 'reason' => 'not-a-verified-free-mint']);
        }
        if ($rf_group['status'] === 'paid') {
            // Phase 4e (2 Sep 2026): a 'paid' group can sit over FAILED rows -- recover_stuck_mints resets
            // pending/paid/minting rows but never 'failed', so a CLI that died after the fails but before
            // batch_finish leaves exactly this. Lane 1 then dispatched into 'No purchases to mint' (400),
            // batch_start wrote 'failed', and lane 2 healed it a tick + a backoff later. Reset here so
            // the dispatch that follows finds its rows. Same predicate as below; no-op when nothing failed.
            $rf_reset_paid = $wpdb->query($wpdb->prepare(
                "UPDATE $purchases_table SET mint_status = 'paid', error_message = NULL, updated_at = %s
                  WHERE group_id = %d AND mint_status = 'failed'
                    AND COALESCE(error_message,'') NOT LIKE '%%temMALFORMED%%'
                    AND COALESCE(error_message,'') NOT LIKE '%%tecNO_PERMISSION%%'
                    AND COALESCE(error_message,'') NOT LIKE '%%malformed%%'",
                current_time('mysql'), $rf_gid
            ));
            if (intval($rf_reset_paid) > 0) mod_log("vps_redrive_free: group=$rf_gid already paid -- reset " . intval($rf_reset_paid) . " failed row(s) so the dispatch finds them (Phase 4e)");
            json_success(['redriven' => true, 'reason' => 'already-paid', 'reset' => intval($rf_reset_paid)]);
        }
        if (!in_array($rf_group['status'], ['failed', 'partial'], true)) {
            json_success(['redriven' => false, 'reason' => 'status-' . $rf_group['status']]);
        }
        $rf_now = current_time('mysql');
        // Mirror of process_group's retry block (M10 fix / v285) -- predicate kept byte-identical so a
        // cron re-drive behaves exactly like the buyer pressing Retry.
        $rf_resettable = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $purchases_table
              WHERE group_id = %d AND mint_status = 'failed'
                AND COALESCE(error_message,'') NOT LIKE '%%temMALFORMED%%'
                AND COALESCE(error_message,'') NOT LIKE '%%tecNO_PERMISSION%%'
                AND COALESCE(error_message,'') NOT LIKE '%%malformed%%'",
            $rf_gid
        )));
        $rf_eligible = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $purchases_table WHERE group_id = %d AND mint_status IN ('paid','minting')", $rf_gid
        )));
        if ($rf_resettable + $rf_eligible === 0) {
            // Complete-but-mislabelled: every row already minted/claimed (a retry finished after the
            // label was written). Relabel; nothing to dispatch. Otherwise it is a permanent-failure case.
            $rf_done = intval($wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $purchases_table WHERE group_id = %d AND mint_status IN ('minted','claimed')", $rf_gid
            )));
            if ($rf_done >= intval($rf_group['quantity']) && $rf_done > 0) {
                $wpdb->update($groups_table, ['status' => 'minted', 'mint_progress' => $rf_done, 'updated_at' => $rf_now], ['id' => $rf_gid]);
                mod_log("vps_redrive_free: group=$rf_gid was {$rf_group['status']} but all $rf_done rows are minted/claimed -- relabelled 'minted'");
                json_success(['redriven' => false, 'reason' => 'already-complete']);
            }
            imc_flag_attention($rf_gid, 'redrive-free: nothing resettable (permanent failures or already complete) -- M2b');
            mod_log("vps_redrive_free: group=$rf_gid status={$rf_group['status']} has NO resettable rows -- flagged, not re-driven");
            json_success(['redriven' => false, 'reason' => 'nothing-resettable']);
        }
        $rf_reset = $wpdb->query($wpdb->prepare(
            "UPDATE $purchases_table SET mint_status = 'paid', error_message = NULL, updated_at = %s
              WHERE group_id = %d AND mint_status = 'failed'
                AND COALESCE(error_message,'') NOT LIKE '%%temMALFORMED%%'
                AND COALESCE(error_message,'') NOT LIKE '%%tecNO_PERMISSION%%'
                AND COALESCE(error_message,'') NOT LIKE '%%malformed%%'",
            $rf_now, $rf_gid
        ));
        // CAS on the statuses we read -- a concurrent process_group / claim that already moved it wins.
        $rf_cas = $wpdb->query($wpdb->prepare(
            "UPDATE $groups_table SET status = 'paid', updated_at = %s WHERE id = %d AND status IN ('failed','partial')",
            $rf_now, $rf_gid
        ));
        mod_log("vps_redrive_free: group=$rf_gid {$rf_group['status']}->paid reset=" . intval($rf_reset) . " eligible=$rf_eligible cas=" . intval($rf_cas));
        json_success(['redriven' => (intval($rf_cas) === 1), 'reason' => (intval($rf_cas) === 1 ? 'reset' : 'lost-cas'), 'reset' => intval($rf_reset)]);
    }

    if ($action === 'vps_batch_claim') {
        // M6-a: claim an EXTRA group into a union hop. The caller already holds the mint
        // lock (acquired by its vps_batch_start); this endpoint verifies that a live lock
        // exists, heartbeats it, and then CLAIMS the group with a CAS -- 0 rows means
        // another actor (admin_compensate's ms-window, a colliding chain) got there first
        // and the caller simply skips it. Response mirrors vps_batch_start minus pinata_jwt.
        $group_id = intval($_POST['group_id'] ?? 0);
        if (!$group_id) json_error('Missing group_id');
        $claim_lock = __DIR__ . '/logs/xrpl_lock_imc_mint.lock';
        if (!file_exists($claim_lock) || (time() - intval(@filemtime($claim_lock))) > 300) {
            json_error('No live mint lock -- claim refused (claim is only valid inside a held batch)', 409);
        }
        touch_xrpl_lock('imc_mint');   // M0 heartbeat -- this call happens mid-hold
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE $groups_table SET status = 'minting', updated_at = %s WHERE id = %d AND status = 'paid' AND payment_verified = 1",
            current_time('mysql'), $group_id
        ));
        if ($claimed !== 1) {
            $cur = $wpdb->get_var($wpdb->prepare("SELECT status FROM $groups_table WHERE id = %d", $group_id));
            json_success(['claimed' => false, 'reason' => $cur ?: 'not-found']);
        }
        $group = $wpdb->get_row($wpdb->prepare("SELECT * FROM $groups_table WHERE id = %d", $group_id), ARRAY_A);
        $listing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $listings_table WHERE id = %d", intval($group['listing_id'])), ARRAY_A);
        if (!$listing) {
            $wpdb->update($groups_table, ['status' => 'failed', 'error_message' => 'Listing not found', 'updated_at' => current_time('mysql')], ['id' => $group_id]);
            json_error('Listing not found');
        }
        $purchases = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $purchases_table WHERE group_id = %d AND mint_status IN ('paid','minting') ORDER BY id ASC", $group_id
        ), ARRAY_A);
        // Phase 2 (2 Sep 2026): count 'claimed' as well. A partial group whose good NFT the buyer had
        // already claimed seeded the tally at 0, so the retry re-finished as 'partial' with progress
        // short by the claimed rows -- and a fully-claimed group re-dispatched was written 'failed'.
        $already_minted = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $purchases_table WHERE group_id = %d AND mint_status IN ('minted','claimed')", $group_id
        )));
        if (empty($purchases)) {   // v452 shape: everything already minted, or nothing to do
            if ($already_minted > 0 && $already_minted >= intval($group['quantity'])) {
                $wpdb->update($groups_table, ['status' => 'minted', 'updated_at' => current_time('mysql')], ['id' => $group_id]);
                json_success(['claimed' => false, 'reason' => 'already_minted']);
            }
            $wpdb->update($groups_table, ['status' => 'failed', 'error_message' => 'No purchases to mint', 'updated_at' => current_time('mysql')], ['id' => $group_id]);
            json_success(['claimed' => false, 'reason' => 'no-purchases']);
        }
        mod_log("vps_batch_claim: group=$group_id claimed into union (qty={$group['quantity']}, purchases=" . count($purchases) . ", already_minted=$already_minted)");
        json_success(['claimed' => true, 'group' => $group, 'listing' => $listing, 'purchases' => $purchases,
                      'is_tiered' => (bool)($listing['has_tiers'] ?? false), 'already_minted' => $already_minted]);
    }

    if ($action === 'vps_batch_start') {
        $group_id = intval($_POST['group_id'] ?? 0);
        if (!$group_id) json_error('Missing group_id');

        // Fetch group
        $group = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $groups_table WHERE id = %d", $group_id
        ), ARRAY_A);
        if (!$group) json_error('Group not found');

        // Fetch listing
        $listing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $listings_table WHERE id = %d", $group['listing_id']
        ), ARRAY_A);
        if (!$listing) {
            $wpdb->update($groups_table, ['status' => 'failed', 'error_message' => 'Listing not found', 'updated_at' => current_time('mysql')], ['id' => $group_id]);
            json_error('Listing not found');
        }

        // Fetch purchases needing minting
        $purchases = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $purchases_table WHERE group_id = %d AND mint_status IN ('paid','minting') ORDER BY id ASC", $group_id
        ), ARRAY_A);
        $already_minted = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $purchases_table WHERE group_id = %d AND mint_status = 'minted'", $group_id
        )));

        if (empty($purchases)) {
            // v452: Check if concurrent call already minted everything
            if ($already_minted > 0 && $already_minted >= intval($group['quantity'])) {
                $wpdb->update($groups_table, ['status' => 'minted', 'updated_at' => current_time('mysql')], ['id' => $group_id]);
                mod_log("process_group(creator): All $already_minted purchases already minted — group=$group_id status restored to 'minted'");
                json_success(['status' => 'already_minted', 'group_id' => $group_id]);
            }
            $wpdb->update($groups_table, ['status' => 'failed', 'error_message' => 'No purchases to mint', 'updated_at' => current_time('mysql')], ['id' => $group_id]);
            json_error('No purchases to mint');
        }

        // v429: NON-BLOCKING lock attempt.
        // The VPS CLI (which has no time limit) handles retry logic.
        // Previously this waited up to 600s, causing the web host to kill the process
        // and leave groups permanently stuck in 'minting'.
        $lock_file = __DIR__ . "/logs/xrpl_lock_imc_mint.lock";
        $lock_acquired = false;

        // Check for stale lock (>300s) and clear it
        if (file_exists($lock_file)) {
            $lock_time = @filemtime($lock_file);
            if ($lock_time && (time() - $lock_time > 300)) {
                @unlink($lock_file);
                mod_log("vps_batch_start: cleared stale lock (age=" . (time() - $lock_time) . "s)");
            }
        }

        // Try once — do NOT loop/wait
        if (!file_exists($lock_file)) {
            $fp = @fopen($lock_file, 'x'); // Atomic create
            if ($fp) {
                fwrite($fp, time() . "\n" . getmypid());
                fclose($fp);
                $lock_acquired = true;
                mod_log("vps_batch_start: lock acquired for group=$group_id");
            }
        }

        if (!$lock_acquired) {
            // Return immediately with retry flag — VPS CLI will retry in 5s
            // Must be HTTP 200 so the VPS CLI's JSON parser accepts the response
            mod_log("vps_batch_start: lock busy, returning retry for group=$group_id");
            wp_send_json(['success' => false, 'error' => 'Lock busy — retry', 'retry' => true]);
        }

        // v429: Set 'minting' AFTER lock acquired (not before)
        // Previously set at line 4921 before lock — if lock failed, group stuck forever.
        $wpdb->update($groups_table, ['status' => 'minting', 'updated_at' => current_time('mysql')], ['id' => $group_id]);

        // v242: Post-lock re-check
        $post_lock = $wpdb->get_row($wpdb->prepare("SELECT status FROM $groups_table WHERE id = %d", $group_id), ARRAY_A);
        if ($post_lock && in_array($post_lock['status'], ['minted','claimed'])) {
            release_xrpl_lock('imc_mint');
            json_success(['already_done' => true, 'status' => $post_lock['status']]);
        }

        mod_log("vps_batch_start: group=$group_id qty={$group['quantity']} purchases=" . count($purchases) . " already_minted=$already_minted");

        json_success([
            'group'          => $group,
            'listing'        => $listing,
            'purchases'      => $purchases,
            'is_tiered'      => (bool)($listing['has_tiers'] ?? false),
            'already_minted' => $already_minted,
            'pinata_jwt'     => defined('IMC_PINATA_JWT') ? IMC_PINATA_JWT : '',
        ]);
    }

    if ($action === 'vps_prepare_nft') {
        $group_id    = intval($_POST['group_id'] ?? 0);
        $purchase_id = intval($_POST['purchase_id'] ?? 0);
        $listing_id  = intval($_POST['listing_id'] ?? 0);
        if (!$purchase_id || !$listing_id) json_error('Missing purchase_id or listing_id');
        touch_xrpl_lock('imc_mint');   // M0: heartbeat -- the CLI holds the lock across this call; keeps a LIVE holder from ever looking stale (v896 Defect O, batch path)

        // Mark purchase as minting
        $wpdb->update($purchases_table, ['mint_status' => 'minting', 'updated_at' => current_time('mysql')], ['id' => $purchase_id]);

        // Fetch listing (needed for build_edition_metadata)
        $listing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $listings_table WHERE id = %d", $listing_id), ARRAY_A);
        if (!$listing) json_error('Listing not found');

        $is_tiered = (bool)($listing['has_tiers'] ?? false);
        $tier = null;
        $tier_incremented = false;
        $edition_assigned = false;

        // ── 1. Pick random tier (inside DB transaction for row lock) ──
        if ($is_tiered) {
            $wpdb->query('START TRANSACTION');
            $tier = pick_random_tier($listing_id);
            if ($tier) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE $tiers_table SET minted_count = minted_count + 1 WHERE id = %d", $tier['id']
                ));
                $tier_incremented = true;
            }
            $wpdb->query('COMMIT');
            if (!$tier) {
                json_error('All tier slots exhausted');
            }
        }

        // ── 2. Assign edition number (atomic via LAST_INSERT_ID) ──
        $is_oe = (($listing['edition_type'] ?? 'fixed') === 'open');
        // M-ED-R (2 Sep 2026): a prior attempt on THIS row may have failed on the gap branch of
        // vps_mint_failed, which retained the edition and set edition_retained=1. That number is
        // below minted_count, was issued by this same allocator, has NO NFT (the flag is only ever
        // set on the not-on-chain branch), and is bound to this purchase_id -- reuse it. NOT a new
        // allocation path: no counter is touched, no new number exists. nftoken_id empty is the belt.
        $medr_prior = $wpdb->get_row($wpdb->prepare("SELECT edition_number, edition_retained, nftoken_id FROM $purchases_table WHERE id = %d", $purchase_id), ARRAY_A);
        $medr_reuse = (IMC_MED_REUSE && intval($medr_prior['edition_retained'] ?? 0) === 1 && intval($medr_prior['edition_number'] ?? 0) > 0 && empty($medr_prior['nftoken_id']))
            ? intval($medr_prior['edition_number']) : 0;
        if ($medr_reuse > 0) {
            $edition_rows = 1;   // satisfies the cap guard below: the number was allocated within the cap when first issued
            mod_log("vps_prepare_nft: REUSING retained edition $medr_reuse for purchase=$purchase_id (M-ED-R)");
        } elseif ($is_oe) {
            $edition_rows = $wpdb->query($wpdb->prepare(
                "UPDATE $listings_table SET minted_count = LAST_INSERT_ID(minted_count + 1) WHERE id = %d", $listing_id
            ));
        } else {
            $edition_rows = $wpdb->query($wpdb->prepare(
                "UPDATE $listings_table SET minted_count = LAST_INSERT_ID(minted_count + 1) WHERE id = %d AND minted_count < total_editions", $listing_id
            ));
        }
        if ($edition_rows === 0 || $edition_rows === false) {
            // Rollback tier if we incremented it
            if ($tier_incremented && $tier) {
                $wpdb->query($wpdb->prepare("UPDATE $tiers_table SET minted_count = GREATEST(0, minted_count - 1) WHERE id = %d", $tier['id']));
            }
            json_error('Listing edition cap reached');
        }
        $edition_assigned = true;
        $edition = ($medr_reuse > 0) ? $medr_reuse : intval($wpdb->get_var("SELECT LAST_INSERT_ID()"));

        // ── 2b. PERSIST the allocation to the purchase row (v895, Defect H) ──
        //
        // THE GAP THIS CLOSES
        // The edition was allocated above and RETURNED to the caller, but never written
        // anywhere. Only vps_complete_nft (L7581) persisted it — i.e. on SUCCESS ONLY. Any
        // mint that died between prepare and complete left the row at edition_number = 0
        // while listings.minted_count had ALREADY moved. The number that was consumed
        // existed nowhere in the database.
        //
        // That is what forced the nine tier backfills to be reconstructed
        // from IPFS metadata: the ledger knew which edition each NFT was, and we did not.
        //
        // vps_prepare_nft is the ONLY allocator with this gap (verified): admin_compensate
        // and process_group write the edition by UPDATE at completion, and creator_mint
        // creates the row already carrying it. This one allocates against a PRE-EXISTING
        // row and left it at zero.
        //
        // SAFE TO WRITE EARLY:
        //   - no UNIQUE(listing_id, edition_number) exists — it was deliberately dropped in
        //     v43 (see L405-411) precisely because edition_number starts at 0
        //   - the v277 double-mint guard keys on nftoken_id, NOT edition_number, so an early
        //     value cannot make the CLI think a row is already minted
        //   - no reader treats edition_number > 0 as "minted"; the only SELECTs (L3423,
        //     L3471) are display-only
        //   - vps_complete_nft rewrites the same value on success — a harmless no-op
        //
        // ⚠ THIS WRITE HAS A COUNTERPART. vps_mint_failed rolls minted_count BACK when the
        // NFT is not on-chain, returning this edition to the pool for the next buyer. It
        // MUST therefore clear these columns on that path, or a failed row would keep an
        // edition number that now belongs to someone else. See the matching v895 block in
        // vps_mint_failed — the two ship together or not at all.
        //
        // Placed AFTER the cap guard, so a rejected allocation writes nothing.
        $__b2b = $wpdb->update($purchases_table, [
            'edition_number'   => $edition,
            'edition_retained' => 0,   // M-ED-R: the retained number is now in play again; a further failure re-decides
            'tier_id'          => $tier ? intval($tier['id']) : null,
            'tier_name'        => $tier ? $tier['tier_name'] : null,
            'updated_at'       => current_time('mysql'),
        ], ['id' => $purchase_id]);
        if ($__b2b === false && imc_b_refused()) {
            // B PRE-mint: edition $edition is already live on this listing. Nothing is on-chain. The
            // allocator is BEHIND the live editions -- do not roll it back. Undo the tier, clear any
            // retained claim on this row, flag, and fail this attempt; the retry allocates fresh.
            if ($tier_incremented && $tier) {
                $wpdb->query($wpdb->prepare("UPDATE $tiers_table SET minted_count = GREATEST(0, minted_count - 1) WHERE id = %d", intval($tier['id'])));
            }
            $wpdb->update($purchases_table, ['edition_number' => 0, 'edition_retained' => 0, 'tier_id' => null, 'tier_name' => null, 'updated_at' => current_time('mysql')], ['id' => $purchase_id]);
            imc_b_dup('PRE-mint', $purchase_id, $group_id, $listing_id, $edition);
            json_error("Edition $edition is already live on listing $listing_id (allocator behind) -- attempt refused before minting; retry will allocate fresh", 409);
        }

        // ── 3. Build per-edition metadata ──
        $metadata = build_edition_metadata($listing, $tier, $edition);
        $pin_name = sanitize_title($listing['nft_name']) . "-edition-{$edition}.json";

        mod_log("vps_prepare_nft: purchase=$purchase_id edition=$edition tier=" . ($tier['tier_name'] ?? 'none'));

        json_success([
            'edition'          => $edition,
            'metadata'         => $metadata,
            'pin_name'         => $pin_name,
            'tier_id'          => $tier ? $tier['id'] : null,
            'tier_name'        => $tier ? $tier['tier_name'] : null,
            'tier_incremented' => $tier_incremented,
            'edition_assigned' => $edition_assigned,
            'artist_account'   => $listing['artist_account'],
            'collection_taxon' => intval($listing['collection_taxon']),
            'transfer_fee'     => intval($listing['transfer_fee']),
            'is_transferable'  => (bool)$listing['is_transferable'],
        ]);
    }

    if ($action === 'vps_save_nftoken') {
        // v277: Save nftoken_id immediately after XRPL mint, BEFORE sell offer.
        // This is the double-mint guard anchor point.
        $purchase_id  = intval($_POST['purchase_id'] ?? 0);
        $nftoken_id   = sanitize_text_field($_POST['nftoken_id'] ?? '');
        $mint_tx_hash = sanitize_text_field($_POST['mint_tx_hash'] ?? '');
        if (!$purchase_id || !$nftoken_id) json_error('Missing purchase_id or nftoken_id');
        touch_xrpl_lock('imc_mint');   // M0: heartbeat -- the CLI holds the lock across this call; keeps a LIVE holder from ever looking stale (v896 Defect O, batch path)

        // v593 FIX: token-uniqueness backstop. An NFTokenID belongs to exactly ONE purchase.
        // If mint-time extraction ever returns a token already assigned to another purchase
        // (e.g. an NFTokenPage-split misfire), refuse to save it — persisting it would hand the
        // wrong NFT to a buyer. Fail loudly (worker then routes to vps_mint_failed; real token
        // is reconciled). This is additive and never fires for a correctly-extracted token.
        $dup_owner = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $purchases_table WHERE nftoken_id = %s AND id <> %d LIMIT 1",
            $nftoken_id, $purchase_id
        ));
        if ($dup_owner) {
            mod_log("vps_save_nftoken: REJECTED nftoken=$nftoken_id for purchase=$purchase_id — already owned by purchase $dup_owner (extraction collision)");
            json_error('NFTokenID already assigned to purchase ' . intval($dup_owner) . ' — refusing to save (collision)', 409);
        }

        $wpdb->update($purchases_table, [
            'nftoken_id'   => $nftoken_id,
            'mint_tx_hash' => $mint_tx_hash,
            'updated_at'   => current_time('mysql')
        ], ['id' => $purchase_id]);

        mod_log("vps_save_nftoken: purchase=$purchase_id nftoken=$nftoken_id");
        json_success(['saved' => true]);
    }

    if ($action === 'vps_complete_nft') {
        $purchase_id       = intval($_POST['purchase_id'] ?? 0);
        $group_id          = intval($_POST['group_id'] ?? 0);
        $listing_id        = intval($_POST['listing_id'] ?? 0);
        $edition           = intval($_POST['edition'] ?? 0);
        $tier_id           = !empty($_POST['tier_id']) ? intval($_POST['tier_id']) : null;
        $tier_name         = sanitize_text_field($_POST['tier_name'] ?? '');
        $metadata_ipfs     = sanitize_text_field($_POST['metadata_ipfs'] ?? '');
        $nftoken_id        = sanitize_text_field($_POST['nftoken_id'] ?? '');
        $mint_tx_hash      = sanitize_text_field($_POST['mint_tx_hash'] ?? '');
        $sell_offer_id     = sanitize_text_field($_POST['sell_offer_id'] ?? '');
        $sell_offer_tx_hash = sanitize_text_field($_POST['sell_offer_tx_hash'] ?? '');
        $buyer_account     = sanitize_text_field($_POST['buyer_account'] ?? '');
        $minted_count_in   = intval($_POST['minted_count'] ?? 0);

        if (!$purchase_id || !$nftoken_id || !$sell_offer_id) json_error('Missing required fields');

        // 4g-1 (4 Sep 2026): make this action SAFE TO REPEAT so the CLI may retry it (4g-2).
        // The row write below is a full-value UPDATE by primary key, and the group-progress
        // write takes an ABSOLUTE value from the payload -- both are already idempotent. The
        // allowlist consumption is `minted_count = minted_count + 1`, which a retry would
        // DOUBLE-COUNT. So: if this purchase is already complete, the allowlist was consumed on
        // the first call -- still do the row/progress writes (harmless, and they heal a
        // half-written row) but skip the increment. Seen in practice: a purchase POST
        // returned HTTP 500 AFTER the mint and the offer had both validated on-chain; the CLI
        // never checked the result, so the row sat at 'minting' with no sell_offer_id and no net
        // could see it.
        $imc_already_complete = ('1' === (string) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM $purchases_table WHERE id = %d AND mint_status IN ('minted','claimed') LIMIT 1",
            $purchase_id
        )));
        if ($imc_already_complete) mod_log("vps_complete_nft: purchase=$purchase_id already complete -- re-applying row/progress, SKIPPING allowlist consumption (4g-1 idempotent replay)");
        touch_xrpl_lock('imc_mint');   // M0: heartbeat -- the CLI holds the lock across this call; keeps a LIVE holder from ever looking stale (v896 Defect O, batch path)

        // Update purchase record (same fields as process_group step 7)
        $__bd = [
            'edition_number'     => $edition,
            'tier_id'            => $tier_id,
            'tier_name'          => $tier_name ?: null,
            'metadata_ipfs'      => $metadata_ipfs,
            'nftoken_id'         => $nftoken_id,
            'mint_tx_hash'       => $mint_tx_hash,
            'sell_offer_id'      => $sell_offer_id,
            'sell_offer_tx_hash' => $sell_offer_tx_hash,
            'mint_status'        => 'minted',
            'minted_at'          => current_time('mysql'),
            'updated_at'         => current_time('mysql')
        ];
        $__bw = $wpdb->update($purchases_table, $__bd, ['id' => $purchase_id]);
        if ($__bw === false && imc_b_refused()) {   // B POST-mint: NFT exists; bind it, never claim the edition twice
            imc_b_dup('vps_complete_nft', $purchase_id, $group_id, $listing_id, $__bd['edition_number'], $nftoken_id);
            $__bd['error_message'] = 'B-DUP: minted on-chain as edition ' . $__bd['edition_number'] . ' but that edition was already live -- edition recorded as 0, reconcile metadata';
            $__bd['edition_number'] = 0;
            $wpdb->update($purchases_table, $__bd, ['id' => $purchase_id]);
        }

        // Update allowlist minted_count
        // v728: scoped to allowlist-priced groups when the flag is on. If the group is
        // unknown in this path, FAIL OPEN (increment) — preserving legacy behaviour
        // rather than silently under-counting.
        if ($listing_id && $buyer_account) {
            $imc_do_increment = !$imc_already_complete;   // 4g-1: never consume twice on a replay
            if (IMC_ALLOWLIST_SCOPED_CONSUME && !empty($group_id)) {
                $imc_g_alq = $wpdb->get_var($wpdb->prepare(
                    "SELECT allowlist_qty FROM {$wpdb->prefix}imc_purchase_groups WHERE id = %d", $group_id
                ));
                if ($imc_g_alq !== null) $imc_do_increment = $imc_do_increment && (intval($imc_g_alq) > 0);   // 4g-1: AND, never re-enable
            }
            if ($imc_do_increment) {
            $al_entries_table = $wpdb->prefix . 'imc_allowlist_entries';
            $al_table = $wpdb->prefix . 'imc_allowlists';
            $wpdb->query($wpdb->prepare(
                "UPDATE $al_entries_table e JOIN $al_table a ON e.allowlist_id = a.id SET e.minted_count = e.minted_count + 1 WHERE a.listing_id = %d AND e.wallet_address = %s",
                $listing_id, $buyer_account
            ));
            }
        }

        // Update group progress (frontend polls get_group_status)
        if ($group_id && $minted_count_in > 0) {
            $wpdb->update($groups_table, [
                'mint_progress' => $minted_count_in,
                'updated_at'    => current_time('mysql')
            ], ['id' => $group_id]);
        }

        mod_log("vps_complete_nft: purchase=$purchase_id edition=$edition nftoken=$nftoken_id offer=$sell_offer_id");
        json_success(['completed' => true, 'minted_count' => $minted_count_in]);
    }

    if ($action === 'vps_mint_failed') {
        $purchase_id      = intval($_POST['purchase_id'] ?? 0);
        $listing_id       = intval($_POST['listing_id'] ?? 0);
        $tier_id          = !empty($_POST['tier_id']) ? intval($_POST['tier_id']) : null;
        $error_message    = sanitize_text_field($_POST['error_message'] ?? 'Unknown error');
        $edition_assigned = filter_var($_POST['edition_assigned'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $tier_incremented = filter_var($_POST['tier_incremented'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (!$purchase_id) json_error('Missing purchase_id');

        // 4g-4 (4 Sep 2026): CLAIM THIS FAILURE ATOMICALLY so the CLI may retry the report.
        // Two writes below are NOT repeat-safe: the tier rollback is an unguarded
        // `GREATEST(0, minted_count - 1)` (a replay frees a tier slot TWICE -- the one way this
        // action could ever produce two NFTs of the same 1-of-1 tier), and `retry_count + 1`
        // feeds the cron attempt caps (a replay could make a lane give up early). The listing
        // rollback is already replay-safe via M-ED's `AND minted_count = %d` topmost guard.
        // This single UPDATE both marks the row and decides: it matches exactly once per
        // failure, so the FIRST report does the rollbacks and every replay skips them.
        // It also fails in the SAFE DIRECTION -- if PHP dies between this claim and the
        // rollbacks, a slot is LEAKED (one fewer mint available), never freed twice.
        $imc_first_report = (1 === (int) $wpdb->query($wpdb->prepare(
            "UPDATE $purchases_table SET mint_status = 'failed', retry_count = retry_count + 1, updated_at = %s
              WHERE id = %d AND mint_status <> 'failed'",
            current_time('mysql'), $purchase_id
        )));
        if (!$imc_first_report) mod_log("vps_mint_failed: purchase=$purchase_id already reported failed -- REPLAY, skipping tier + counter rollbacks (4g-4)");
        touch_xrpl_lock('imc_mint');   // M0: heartbeat -- the CLI holds the lock across this call; keeps a LIVE holder from ever looking stale (v896 Defect O, batch path)

        // v384: Only rollback counts if the NFT was NOT already minted on-chain.
        //
        // v885 (Defects C / C-2 / S): the DB read below is unreliable in EXACTLY the case that
        // matters. When vps_save_nftoken fails (HTTP 500, collision guard, transport error) the
        // column purchases.nftoken_id was never written -- so this query returns empty for an NFT
        // that IS on the ledger, the rollback returns a consumed edition AND its 1-of-1 tier to
        // the pool, and both are re-issued and minted a SECOND time. That is what produced
        // duplicate editions on an affected listing, and the tier counter
        // hid it because increment -> rollback -> re-increment nets to exactly 1.
        //
        // The CALLER knows the truth (it holds the extracted NFTokenID), so trust an explicitly
        // supplied id over the read. Absent param => behaviour byte-identical to before, which
        // is what makes this deployable ahead of the CLI.
        $posted_onchain_nft = sanitize_text_field($_POST['onchain_nftoken_id'] ?? '');
        $nft_on_chain = !empty($posted_onchain_nft) || !empty($wpdb->get_var($wpdb->prepare(
            "SELECT nftoken_id FROM $purchases_table WHERE id = %d", $purchase_id
        )));
        if (!empty($posted_onchain_nft)) {
            mod_log("vps_mint_failed: caller reports NFT ON-CHAIN (nftoken=$posted_onchain_nft) for purchase=$purchase_id -- rollback SUPPRESSED");
            // C-FIX (2 Sep 2026): v885 used this id to SUPPRESS the rollback but never wrote it, so
            // the row stayed nftoken-less and the retry re-minted (the orphan). Bind it now, guarded
            // by the SAME collision check vps_save_nftoken applies: if the id already belongs to
            // another row the caller mis-extracted it (v593 signature) and binding would corrupt --
            // flag for the reconcile sweep instead. Either way this row's edition stays as it was.
            if (IMC_DEFECT_C_PERSIST) {
                $cfx_owner = intval($wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $purchases_table WHERE nftoken_id = %s AND id <> %d LIMIT 1", $posted_onchain_nft, $purchase_id
                )));
                if ($cfx_owner > 0) {
                    $cfx_gid = intval($wpdb->get_var($wpdb->prepare("SELECT group_id FROM $purchases_table WHERE id = %d", $purchase_id)));
                    if ($cfx_gid > 0) imc_flag_attention($cfx_gid, "onchain-id-collision (nftoken=$posted_onchain_nft also on purchase=$cfx_owner; this row's NFT is on-chain under an unknown id -- run imc-reconcile-nftokens --orphans)");
                    mod_log("vps_mint_failed: C-FIX NOT binding nftoken=$posted_onchain_nft to purchase=$purchase_id -- collides with purchase=$cfx_owner (flagged)");
                } else {
                    $wpdb->update($purchases_table, ['nftoken_id' => $posted_onchain_nft, 'updated_at' => current_time('mysql')], ['id' => $purchase_id]);
                    mod_log("vps_mint_failed: C-FIX bound nftoken=$posted_onchain_nft to purchase=$purchase_id -- retry will take offer-recovery, not a second mint");
                }
            }
        }

        $rolled_back_edition = false;
        $rolled_back_tier = false;

        // M8 fix: Rollback listing minted_count only if NFT not on-chain
        if ($imc_first_report && $edition_assigned && !$nft_on_chain && $listing_id) {   // 4g-4: first report only
            // M-ED (1 Sep 2026): return the edition to the pool ONLY IF IT IS STILL THE LAST ONE ALLOCATED.
            // Edition numbers are identities handed out from listings.minted_count, and the batch CLI
            // allocates every edition of a group in Phase 1 BEFORE minting any in Phase 2. An
            // unconditional decrement here therefore rolled a counter that later editions had already
            // moved past: qty=3 allocates 5,6,7; #5 fails not-on-chain; counter 7->6; the next buyer
            // gets LAST_INSERT_ID(6+1) = 7 -- a DUPLICATE of the live edition 7, and no UNIQUE index
            // exists to stop it (dropped in v43). Guarded: decrement only when minted_count still
            // equals the failed edition (v895 persisted it on the row at allocation). Otherwise the
            // number stays consumed as a harmless GAP and the retry allocates fresh. The three inline
            // allocators (admin_compensate / process_group / creator_mint) allocate-mint-rollback one
            // edition per iteration and are safe by sequencing; M6's cross-group batching is not.
            $med_edition = intval($wpdb->get_var($wpdb->prepare("SELECT edition_number FROM $purchases_table WHERE id = %d", $purchase_id)));
            $med_rows = 0;
            if ($med_edition > 0) {
                $med_rows = (int) $wpdb->query($wpdb->prepare(
                    "UPDATE $listings_table SET minted_count = minted_count - 1 WHERE id = %d AND minted_count = %d", $listing_id, $med_edition
                ));
            }
            $rolled_back_edition = ($med_rows === 1);
            if ($rolled_back_edition) {
                mod_log("vps_mint_failed: edition $med_edition RETURNED to the pool for listing=$listing_id (it was the last allocated) (M-ED)");
            } else {
                mod_log("vps_mint_failed: edition " . ($med_edition ?: '?') . " NOT returned for listing=$listing_id -- later editions were allocated after it (or none persisted); left as a GAP to prevent a duplicate (M-ED)");
            }

            // v895 (Defect H) — THE COUNTERPART TO THE PREPARE-TIME WRITE.
            //
            // We have just returned this edition to the pool: minted_count went DOWN, so the
            // next buyer will legitimately be assigned the same number. Since vps_prepare_nft
            // now stamps edition_number on the row at allocation time, leaving it here would
            // mean TWO purchase rows claiming the same edition on the same listing — one
            // failed, one live. Not a duplicate NFT, but corrupt data that every later audit
            // would have to reason around.
            //
            // Cleared ONLY on this branch. When the NFT IS on-chain the edition was correctly
            // KEPT (the C/C-2/S rule), and preserving the number on the row is precisely what
            // Defect H exists to achieve — that is the case where the ledger has an NFT and we
            // want the database to remember which edition it was.
            // M-ED-R (2 Sep 2026): the clear was written for the ROLLED-BACK case -- the counter went
            // down, the number is back in the pool, the row must not keep it. On the GAP branch M-ED
            // left the number CONSUMED: minted_count never moved below it, no other row can ever be
            // handed it, and clearing it here is what turned every non-topmost failure into a permanent
            // hole. Keep it, and flag edition_retained=1 so vps_prepare_nft reuses it on
            // retry. This block is INSIDE the !$nft_on_chain branch by construction -- the on-chain
            // (Defect C) path above can never reach it, so a retained edition has no NFT anywhere.
            // Tier is cleared either way: its counter is rolled back unconditionally below.
            $medr_keep = (IMC_MED_REUSE && !$rolled_back_edition && $med_edition > 0);
            $wpdb->update($purchases_table, [
                'edition_number'   => $medr_keep ? $med_edition : 0,
                'edition_retained' => $medr_keep ? 1 : 0,
                'tier_id'          => null,
                'tier_name'        => null,
            ], ['id' => $purchase_id]);
            mod_log("vps_mint_failed: " . ($medr_keep ? "edition $med_edition RETAINED (flagged) on" : "edition_number cleared on") . " purchase=$purchase_id (NFT not on-chain; counter returned=" . ($rolled_back_edition ? 'yes' : 'no -- gap') . ") (M-ED-R)");
        } elseif ($edition_assigned && $nft_on_chain) {
            mod_log("vps_mint_failed: NOT rolling back minted_count — NFT exists on-chain for purchase=$purchase_id");
        }

        // M9 fix: Rollback tier minted_count only if NFT not on-chain
        if ($imc_first_report && $tier_incremented && $tier_id && !$nft_on_chain) {   // 4g-4: NEVER free a tier slot twice
            $wpdb->query($wpdb->prepare(
                "UPDATE $tiers_table SET minted_count = GREATEST(0, minted_count - 1) WHERE id = %d", $tier_id
            ));
            $rolled_back_tier = true;
            mod_log("vps_mint_failed: Rolled back tier minted_count for tier=$tier_id (NFT not on-chain)");
        } elseif ($tier_incremented && $tier_id && $nft_on_chain) {
            mod_log("vps_mint_failed: NOT rolling back tier — NFT exists on-chain for purchase=$purchase_id");
        }

        // Mark purchase failed. 4g-4: mint_status and retry_count were applied by the atomic
        // claim above (exactly once); only the message is written here, and it is idempotent -- a
        // replay refreshes the reason without touching the counter or the status.
        $wpdb->update($purchases_table, [
            'error_message' => substr($error_message, 0, 500),
            'updated_at'    => current_time('mysql')
        ], ['id' => $purchase_id]);

        mod_log("vps_mint_failed: purchase=$purchase_id error=$error_message rolled_back_edition=$rolled_back_edition rolled_back_tier=$rolled_back_tier");
        json_success(['failed' => true, 'rolled_back_edition' => $rolled_back_edition, 'rolled_back_tier' => $rolled_back_tier]);
    }

    if ($action === 'vps_batch_finish') {
        $group_id     = intval($_POST['group_id'] ?? 0);
        $listing_id   = intval($_POST['listing_id'] ?? 0);
        $minted_count = intval($_POST['minted_count'] ?? 0);
        $total_qty    = intval($_POST['total_qty'] ?? 0);
        $errors_json  = $_POST['errors'] ?? '[]';

        if (!$group_id) json_error('Missing group_id');

        // Release XRPL lock
        release_xrpl_lock('imc_mint');

        // Release reserved_count (v242: only release what was minted)
        if ($listing_id) {
            if ($minted_count > 0) {
                // PARTIAL: only what minted. Paired with recover_stuck_mints above, this was
                // the second half of the Defect M double-release — now clamped by the helper.
                imc_release_reservation($group_id, $listing_id, $minted_count, 'vps-batch-finish-minted');
            }
        }

        // Determine final status (v242: three-state)
        if ($minted_count === $total_qty && $total_qty > 0) {
            $final_status = 'minted';
        } elseif ($minted_count > 0) {
            $final_status = 'partial';
        } else {
            $final_status = 'failed';
        }

        $errors = json_decode($errors_json, true) ?: [];
        $error_msg = !empty($errors) ? implode('; ', $errors) : null;

        // Check sold_out (fixed editions only)
        if ($listing_id) {
            $listing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $listings_table WHERE id = %d", $listing_id), ARRAY_A);
            if ($listing && ($listing['edition_type'] ?? 'fixed') === 'fixed') {
                $remaining = $wpdb->get_var($wpdb->prepare(
                    "SELECT GREATEST(0, CAST(total_editions AS SIGNED) - CAST(minted_count AS SIGNED)) FROM $listings_table WHERE id = %d", $listing_id
                ));
                if (intval($remaining) <= 0) {
                    $wpdb->update($listings_table, ['status' => 'sold_out', 'updated_at' => current_time('mysql')], ['id' => $listing_id]);
                    mod_log("vps_batch_finish: listing $listing_id is now sold_out");
                }
            }
        }

        // Update group final status
        $wpdb->update($groups_table, [
            'status'        => $final_status,
            'error_message' => $error_msg,
            'updated_at'    => current_time('mysql')
        ], ['id' => $group_id]);

        // Invalidate collection cache
        if ($minted_count > 0 && $listing_id && function_exists('imc_invalidate_collection_cache')) {
            $listing = $listing ?? $wpdb->get_row($wpdb->prepare("SELECT artist_account, collection_taxon FROM $listings_table WHERE id = %d", $listing_id), ARRAY_A);
            if ($listing) {
                imc_invalidate_collection_cache($listing['artist_account'], $listing['collection_taxon']);
            }
        }

        mod_log("vps_batch_finish: group=$group_id status=$final_status minted=$minted_count/$total_qty");
        json_success(['final_status' => $final_status, 'minted_count' => $minted_count]);
    }

    json_error('Unknown POST action');
}

json_error('Invalid request method', 405);