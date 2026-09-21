<?php
/**
 * File: offer-handler.php (v94 - GTC Only)
 * Path: /wp-content/themes/astra/xrpl-nft-marketplace/backend/offer-handler.php
 * 
 * Version: 4.0.0 - v95 GTC-Only Offers (cleanup)
 * 
 * Changes in v94:
 *  - REMOVED auction/timed offer support (XRPL limitations - see docs)
 *  - ALL offers are now Good Till Cancelled (GTC)
 *  - No XRPL Expiration field used
 *  - Simplified offer creation flow
 *  - Kept listing_mode column for future batch transaction support
 * 
 * Note: Auctions will be revisited when XRPL batch transactions are available
 * See: https://xrpl.org/docs/concepts/tokens/nfts/running-an-nft-auction
 */

require_once dirname(__DIR__, 5) . '/wp-load.php';
if (!defined('ABSPATH')) exit;
define('OFFER_LOADED', true);
require_once __DIR__ . '/imc-trustline-manager.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: SAMEORIGIN');

global $wpdb;
$offers_table = $wpdb->prefix . 'xumm_offers';

// Helper function to validate XRPL account
function is_xrpl_account($account) {
    return preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account);
}


/** ----------------- Config / Globals ----------------- */
$RPC_URL         = 'https://s2-clio.ripple.com:51234/';  // Primary RPC (true Clio). v760/P1a: was xrplcluster.com, which rejects ALL Clio methods (nft_info, nft_sell_offers, nft_buy_offers, account_nfts) - 20 read calls in this file were silently dead.
$IOU_ISSUER      = defined('IMU_XFT_ISSUER') ? IMU_XFT_ISSUER : 'rGpnoqYLzWytxwQhhz715nRbqyCHM7zhxt';
$WEBHOOK_URL     = home_url('/xumm-proxy.php');
$XUMM_API_KEY    = defined('XUMM_API_KEY')    ? XUMM_API_KEY    : (getenv('XUMM_API_KEY')    ?: '');
$XUMM_API_SECRET = defined('XUMM_API_SECRET') ? XUMM_API_SECRET : (getenv('XUMM_API_SECRET') ?: '');
$BITHOMP_TOKEN   = defined('BITHOMP_API_KEY') ? BITHOMP_API_KEY : (getenv('BITHOMP_API_KEY') ?: '');

// Phase 2: Platform fee configuration
$PLATFORM_FEE_PERCENT = defined('IMU_PLATFORM_FEE_PERCENT') ? IMU_PLATFORM_FEE_PERCENT : 1.5;

// v655 (Phase 3): Brokered secondary-market pilot gate. Brokered listings are OFF
// for everyone by default; they activate ONLY when 'imc_broker_pilot_enabled' is '1'
// AND the NFT's issuer is on the pilot allowlist — so the live DIRECT listing flow is
// completely unaffected until the pilot is explicitly enabled for controlled collections.
if (!defined('IMC_BROKER_FEE_WALLET'))  define('IMC_BROKER_FEE_WALLET', 'riMCgymFVzdqQoTR82m5oUJE697bDHrJm');
if (!defined('IMC_BROKER_FEE_PERCENT')) define('IMC_BROKER_FEE_PERCENT', 2.0);
function imc_broker_should_broker($nft_issuer) {
    if (get_option('imc_broker_pilot_enabled', '0') !== '1') return false;
    if (empty($nft_issuer)) return false;
    $allow = get_option('imc_broker_pilot_issuers', 'rKDFM3xaC3B7ijWkX4iHcMTcLFgxW2dK74');
    $allow = array_filter(array_map('trim', explode(',', (string)$allow)));
    return in_array($nft_issuer, $allow, true);
}

/**
 * v657 (Phase 5): Call the configured remote broker-signing service, which
 * signs NFTokenAcceptOffer / NFTokenCancelOffer for the fee wallet (riMCgym) ONLY.
 * No funds are custodied — the ledger splits atomically. Requires two WP constants
 * (add to wp-config.php):
 *   IMC_BROKER_SIGNER_URL     = <configured in wp-config>
 *   IMC_BROKER_SIGNER_API_KEY = <configured in wp-config>
 */
function imc_call_broker_signer($action, $params = []) {
    $url = defined('IMC_BROKER_SIGNER_URL') ? IMC_BROKER_SIGNER_URL : '';
    $key = defined('IMC_BROKER_SIGNER_API_KEY') ? IMC_BROKER_SIGNER_API_KEY : '';
    if (empty($url) || empty($key)) {
        return ['success' => false, 'error' => 'Broker signer not configured (IMC_BROKER_SIGNER_URL / IMC_BROKER_SIGNER_API_KEY)'];
    }
    $payload = json_encode(array_merge(['action' => $action, 'api_key' => $key], $params));
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 25,   // broker_accept polls for validated meta
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) {
        error_log("imc_call_broker_signer($action): connection error: $err");
        return ['success' => false, 'error' => "Broker signer connection error: $err"];
    }
    $data = json_decode($resp, true);
    if (!is_array($data) || !isset($data['success'])) {
        error_log("imc_call_broker_signer($action): invalid response: " . substr((string)$resp, 0, 200));
        return ['success' => false, 'error' => 'Invalid broker signer response'];
    }
    return $data;
}
$PLATFORM_WALLET = defined('IMU_PLATFORM_WALLET') ? IMU_PLATFORM_WALLET : '';

$log_dir  = __DIR__ . '/logs';
$log_file = $log_dir . '/offer-handler.log';
if (!is_dir($log_dir)) { @mkdir($log_dir, 0755, true); }
ini_set('log_errors', 1);
ini_set('error_log', $log_file);

/** ----------------- DB ensure (v94 - simplified) ----------------- */
$wpdb->query("CREATE TABLE IF NOT EXISTS $offers_table (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  offer_id VARCHAR(64) NOT NULL DEFAULT '',
  uuid VARCHAR(36) NOT NULL DEFAULT '',
  offerer_account VARCHAR(35) NOT NULL,
  target_account VARCHAR(35) NOT NULL,
  target_nft_id VARCHAR(64) NOT NULL,
  offer_type ENUM('buy','sell') NOT NULL,
  listing_mode VARCHAR(10) NOT NULL DEFAULT 'gtc',
  amount DECIMAL(38,16) NOT NULL,
  currency VARCHAR(10) NOT NULL DEFAULT 'XRP',
  net_amount DECIMAL(38,16) NOT NULL,
  platform_fee DECIMAL(38,16) NOT NULL DEFAULT 0,
  fee_currency VARCHAR(10) NOT NULL DEFAULT 'XRP',
  royalty_amount DECIMAL(38,16) NOT NULL DEFAULT 0,
  royalty_wallet VARCHAR(35) DEFAULT '',
  royalty_percent DECIMAL(7,3) NOT NULL DEFAULT 0,
  status ENUM('pending','active','accepted','rejected','failed','expired','cancelled') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL,
  expires_at DATETIME DEFAULT NULL,
  accepted_at DATETIME DEFAULT NULL,
  KEY idx_offer (offer_id),
  KEY idx_uuid (uuid),
  KEY idx_target (target_account),
  KEY idx_status (status),
  KEY idx_nft (target_nft_id),
  KEY idx_offerer (offerer_account),
  KEY idx_type_status (offer_type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// v94: Ensure listing_mode column exists (for migration from older versions)
$existing_cols = $wpdb->get_col("DESCRIBE $offers_table", 0);
if (!in_array('listing_mode', $existing_cols)) {
    $wpdb->query("ALTER TABLE $offers_table ADD COLUMN listing_mode VARCHAR(10) NOT NULL DEFAULT 'gtc' AFTER offer_type");
}
// v257: synced_at — timestamp of last XRPL ledger cross-reference for this offer row
if (!in_array('synced_at', $existing_cols)) {
    $wpdb->query("ALTER TABLE $offers_table ADD COLUMN synced_at DATETIME DEFAULT NULL AFTER accepted_at");
    $wpdb->query("ALTER TABLE $offers_table ADD INDEX idx_synced (synced_at)");
}
// v421: destination — the XRPL wallet a directed sell offer is locked to.
// Critical for showing incoming transfers on the dashboard instantly.
if (!in_array('destination', $existing_cols)) {
    $wpdb->query("ALTER TABLE $offers_table ADD COLUMN destination VARCHAR(35) DEFAULT NULL AFTER target_account");
    $wpdb->query("ALTER TABLE $offers_table ADD INDEX idx_destination (destination)");
    error_log("v421: Added destination column + index to $offers_table");
}

// v427: Wallet blacklist table — block malicious/spam offers from display.
// Offers from blacklisted wallets are filtered on read (dashboard, prescan)
// and blocked on write (VPS listener). The XRPL offers still exist on-chain
// but our marketplace simply doesn't show them.
$blacklist_table = $wpdb->prefix . 'xumm_blacklist';
$wpdb->query("CREATE TABLE IF NOT EXISTS $blacklist_table (
    id INT AUTO_INCREMENT PRIMARY KEY,
    wallet_address VARCHAR(40) NOT NULL,
    reason VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(40) DEFAULT NULL,
    UNIQUE KEY uk_wallet (wallet_address),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/**
 * Load active blacklisted wallets (cached 5 min).
 * Returns array of lowercase wallet addresses.
 */
function imc_get_blacklisted_wallets() {
    $cached = get_transient('imc_wallet_blacklist');
    if (is_array($cached)) return $cached;

    global $wpdb;
    $table = $wpdb->prefix . 'xumm_blacklist';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) return [];

    $rows = $wpdb->get_col("SELECT LOWER(wallet_address) FROM $table WHERE is_active = 1");
    $list = is_array($rows) ? $rows : [];
    set_transient('imc_wallet_blacklist', $list, 300); // 5 min cache
    return $list;
}

/**
 * Phase 4C: active scam-issuer set (VPS-sourced; mirrors imc_get_blacklisted_wallets).
 * 30-min transient. Kill-switch: define('IMC_SCAM_FILTER_DISABLED', true).
 * Fail-open to last-good/empty on any error so a slow/absent VPS never blocks a page.
 * Returns array of lowercase issuer addresses.
 */
if (!function_exists('imc_get_scam_issuers')) {
function imc_get_scam_issuers() {
    if (defined('IMC_SCAM_FILTER_DISABLED') && IMC_SCAM_FILTER_DISABLED) return [];
    $cached = get_transient('imc_scam_issuers');
    if (is_array($cached)) return $cached;
    $resp = wp_remote_get('https://metadata.imcollectibles.io/?action=scam_issuers', ['timeout' => 3]);
    if (is_wp_error($resp) || (int)wp_remote_retrieve_response_code($resp) !== 200) {
        $last = get_transient('imc_scam_issuers'); return is_array($last) ? $last : [];
    }
    $json = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($json) || empty($json['issuers']) || !is_array($json['issuers'])) {
        $last = get_transient('imc_scam_issuers'); return is_array($last) ? $last : [];
    }
    $list = [];
    foreach ($json['issuers'] as $i) {
        $i = strtolower(trim((string)$i));
        if (preg_match('/^r[1-9a-hj-np-za-km-z]{24,34}$/i', $i)) $list[] = $i;
    }
    set_transient('imc_scam_issuers', $list, 1800); // 30 min
    return $list;
}
}


/** ----------------- XRPL helpers ----------------- */
function xrpl_rpc($method, $params = [], $timeout = 12) {
  global $RPC_URL, $log_file;
  $req = json_encode(['method' => $method, 'params' => [$params]]);
  $res = wp_remote_post($RPC_URL, [
    'body'    => $req,
    'headers' => ['Content-Type' => 'application/json'],
    'timeout' => $timeout
  ]);
  if (is_wp_error($res)) {
    $err = $res->get_error_message();
    error_log("RPC $method error: $err");
    return ['error' => $err];
  }
  $body = json_decode(wp_remote_retrieve_body($res), true);
  return $body ?: ['error' => 'Empty RPC body'];
}

/**
 * Get NFT info DIRECTLY FROM XRPL LEDGER (on-chain verification)
 * Uses same approach as xumm-proxy.php check_nft_owner
 * DO NOT use metadata API for owner - it can be stale!
 */
function nft_info_compact($nft_id) {
  global $log_file;
  
  // Ensure uppercase (XRPL standard)
  $nft_id = strtoupper(trim($nft_id));
  
  // METHOD 1: Direct XRPL RPC (Clio). v760/P1a: was xrplcluster.com, which
  // rejects nft_info entirely — the call always failed through to Method 2.
  $rpc_url = 'https://s2-clio.ripple.com:51234';
  
  $req = json_encode([
    'method' => 'nft_info',
    'params' => [['nft_id' => $nft_id, 'ledger_index' => 'validated']]
  ]);
  
  error_log("nft_info_compact: Querying $rpc_url for NFT $nft_id");
  
  $res = wp_remote_post($rpc_url, [
    'body'    => $req,
    'headers' => ['Content-Type' => 'application/json'],
    'timeout' => 15,
    'sslverify' => true
  ]);
  
  if (!is_wp_error($res)) {
    $http_code = wp_remote_retrieve_response_code($res);
    $body = json_decode(wp_remote_retrieve_body($res), true);
    
    error_log("nft_info_compact: HTTP $http_code from s2-clio for $nft_id");
    
    if (!empty($body) && ($body['result']['status'] ?? '') === 'success') {
      $result = $body['result'];
      $owner = $result['owner'] ?? 
               ($result['nft_info']['Owner'] ?? null) ?? 
               ($result['nft']['owner'] ?? null);
      
      if ($owner) {
        error_log("nft_info_compact: SUCCESS - NFT $nft_id owner is $owner (on-chain via s2-clio)");
        return [
          'owner'        => $owner,
          'issuer'       => $result['issuer'] ?? null,
          'taxon'        => isset($result['nft_taxon']) ? (int)$result['nft_taxon'] : 0,
          'transfer_fee' => isset($result['transfer_fee']) ? (int)$result['transfer_fee'] : 0,
          'flags'        => $result['flags'] ?? 0
        ];
      }
    }
    error_log("nft_info_compact: s2-clio response did not contain owner for $nft_id: " . wp_remote_retrieve_body($res));
  } else {
    error_log("nft_info_compact: s2-clio error for $nft_id: " . $res->get_error_message());
  }
  
  // METHOD 2: Try s2.ripple.com (alternative Clio)
  $rpc_url2 = 'https://s2.ripple.com:51234';
  error_log("nft_info_compact: Trying fallback $rpc_url2 for NFT $nft_id");
  
  $res2 = wp_remote_post($rpc_url2, [
    'body'    => $req,
    'headers' => ['Content-Type' => 'application/json'],
    'timeout' => 15,
    'sslverify' => true
  ]);
  
  if (!is_wp_error($res2)) {
    $body2 = json_decode(wp_remote_retrieve_body($res2), true);
    
    if (!empty($body2) && ($body2['result']['status'] ?? '') === 'success') {
      $result = $body2['result'];
      $owner = $result['owner'] ?? 
               ($result['nft_info']['Owner'] ?? null) ?? 
               ($result['nft']['owner'] ?? null);
      
      if ($owner) {
        error_log("nft_info_compact: SUCCESS - NFT $nft_id owner is $owner (on-chain via s2.ripple)");
        return [
          'owner'        => $owner,
          'issuer'       => $result['issuer'] ?? null,
          'taxon'        => isset($result['nft_taxon']) ? (int)$result['nft_taxon'] : 0,
          'transfer_fee' => isset($result['transfer_fee']) ? (int)$result['transfer_fee'] : 0,
          'flags'        => $result['flags'] ?? 0
        ];
      }
    }
  } else {
    error_log("nft_info_compact: s2.ripple error for $nft_id: " . $res2->get_error_message());
  }
  
  // METHOD 3: Call xumm-proxy.php check_nft_owner endpoint as final fallback
  // This is the same endpoint that works for other parts of the site
  error_log("nft_info_compact: Trying xumm-proxy.php fallback for NFT $nft_id");
  
  $nonce = wp_create_nonce('xrpl_marketplace_nonce');
  $proxy_url = home_url("/xumm-proxy.php?action=check_nft_owner&nft_id=" . urlencode($nft_id) . "&expected_owner=any&_wpnonce=" . urlencode($nonce));
  
  $res3 = wp_remote_get($proxy_url, ['timeout' => 15]);
  
  if (!is_wp_error($res3)) {
    $body3 = json_decode(wp_remote_retrieve_body($res3), true);
    error_log("nft_info_compact: xumm-proxy response for $nft_id: " . wp_remote_retrieve_body($res3));
    
    if (!empty($body3['success']) && !empty($body3['owner'])) {
      error_log("nft_info_compact: SUCCESS - NFT $nft_id owner is " . $body3['owner'] . " (via xumm-proxy)");
      return [
        'owner'        => $body3['owner'],
        'issuer'       => null,  // xumm-proxy doesn't return these
        'taxon'        => 0,
        'transfer_fee' => 0,
        'flags'        => 0
      ];
    }
  } else {
    error_log("nft_info_compact: xumm-proxy error for $nft_id: " . $res3->get_error_message());
  }
  
  error_log("nft_info_compact: ALL METHODS FAILED for $nft_id");
  return null;
}

function extract_offer_id_from_meta($tx) {
  $meta = $tx['result']['meta'] ?? [];
  if (!empty($meta['OfferID'])) return $meta['OfferID'];
  foreach (($meta['AffectedNodes'] ?? []) as $n) {
    $node = $n['CreatedNode'] ?? null;
    if ($node) {
      $let = $node['LedgerEntryType'] ?? ($node['NewFields']['LedgerEntryType'] ?? '');
      if ($let === 'NFTokenOffer' && !empty($node['LedgerIndex'])) {
        return $node['LedgerIndex'];
      }
    }
  }
  return null;
}

function xrp_decimal_to_drops($s) {
  $s = trim($s);
  if ($s === '0' || preg_match('/^0+(\.0+)?$/', $s)) return '0';
  if (!preg_match('/^\d+(\.\d{1,6})?$/', $s)) return null;
  if (strpos($s, '.') === false) {
    $i = ltrim($s, '0');
    return $i === '' ? '0' : $i . '000000';
  }
  list($int, $frac) = explode('.', $s, 2);
  $int  = ltrim($int, '0'); if ($int === '') $int = '0';
  $frac = str_pad($frac, 6, '0');
  $joined = ltrim($int . $frac, '0');
  return $joined === '' ? '0' : $joined;
}

/**
 * v652: Convert a currency ticker to XRPL wire format for token (IOU) Amounts.
 * 3-char codes pass through; 4+ char codes (XMEME, RLUSD, SCHMECKLES) become the
 * 40-char hex the ledger requires; already-hex codes pass through unchanged.
 * Byte-identical to currency-handler.php so encodings
 * match the trustlines those endpoints opened. Guarded to avoid any redefinition.
 */
if (!function_exists('currency_to_hex')) {
    function currency_to_hex($currency) {
        if (strlen($currency) <= 3 && preg_match('/^[A-Za-z0-9]{1,3}$/', $currency)) {
            return strtoupper($currency);
        }
        if (strlen($currency) === 40 && preg_match('/^[A-Fa-f0-9]{40}$/', $currency)) {
            return strtoupper($currency);
        }
        $hex = bin2hex($currency);
        return strtoupper(str_pad($hex, 40, '0'));
    }
}

/**
 * v706: DISPLAY-ONLY decode of an XRPL wire currency code back to its ticker.
 * Inverse of currency_to_hex(). Ledger/Bithomp Amount objects carry the 40-char
 * hex form for any ticker longer than 3 chars; rendering that raw breaks the UI.
 * Returns the ticker when the code decodes to printable ASCII, otherwise returns
 * the input UNCHANGED (AMM LP codes, demurrage codes and other non-text codes
 * fail safe). NEVER used to build a transaction Amount -- display only.
 */
if (!function_exists('imc_currency_display')) {
    function imc_currency_display($code) {
        $code = (string)$code;
        if (strlen($code) !== 40 || !preg_match('/^[A-Fa-f0-9]{40}$/', $code)) {
            return $code;
        }
        $trimmed = rtrim($code, '0');
        if ($trimmed === '') return $code;
        if (strlen($trimmed) % 2 !== 0) $trimmed .= '0';
        $ascii = @hex2bin($trimmed);
        if ($ascii !== false && $ascii !== '' && preg_match('/^[\x20-\x7E]+$/', $ascii)) {
            return $ascii;
        }
        return $code;
    }
}

function has_active_trustline($account, $currency, $issuer) {
  $r = xrpl_rpc('account_lines', ['account' => $account, 'ledger_index' => 'validated', 'limit' => 400]);
  if (!empty($r['error'])) return false;
  foreach (($r['result']['lines'] ?? []) as $line) {
    if (currency_to_hex($line['currency'] ?? '') === currency_to_hex($currency) && ($line['account'] ?? '') === $issuer) {
      if (!empty($line['freeze']) || !empty($line['freeze_peer'])) return false;
      return true;
    }
  }
  return false;
}

/** Get XRP balance for an account */
function get_xrp_balance($account) {
  $r = xrpl_rpc('account_info', ['account' => $account, 'ledger_index' => 'validated']);
  if (!empty($r['error']) || empty($r['result']['account_data']['Balance'])) {
    return 0;
  }
  $drops = $r['result']['account_data']['Balance'];
  return (float)bcdiv($drops, '1000000', 6);
}

/** Fallback NFT metadata (Bithomp) */
if (!function_exists('get_nft_metadata')) {
  function get_nft_metadata($nft_id) {
    global $BITHOMP_TOKEN, $wpdb;
    $fallback = [
      'nft_name' => 'Unnamed NFT',
      'name'     => 'Unnamed NFT',
      'image'    => '/wp-content/uploads/fallback-nft.svg',
      'issuer'   => '',
      'nftokenID'=> $nft_id
    ];
    
    error_log("get_nft_metadata: Fetching metadata for $nft_id");
    
    // METHOD 0: Check our own WordPress DB first (best for NFTs minted on our platform)
    // These are always available immediately after minting, no external API needed
    if ($wpdb) {
        $purchases_table = $wpdb->prefix . 'imc_purchases';
        $listings_table = $wpdb->prefix . 'imc_listings';
        
        // Check if tables exist (safe for sites that don't have MOD)
        $has_table = $wpdb->get_var("SHOW TABLES LIKE '$purchases_table'");
        if ($has_table) {
            $local = $wpdb->get_row($wpdb->prepare(
                "SELECT p.nftoken_id, p.metadata_ipfs, p.tier_name, p.edition_number,
                        l.nft_name, l.cover_ipfs, l.artist_account
                 FROM $purchases_table p
                 LEFT JOIN $listings_table l ON p.listing_id = l.id
                 WHERE p.nftoken_id = %s LIMIT 1",
                $nft_id
            ), ARRAY_A);
            
            if ($local && !empty($local['nft_name'])) {
                $cover = $local['cover_ipfs'] ?? '';
                $img = $fallback['image'];
                if ($cover) {
                    $cid = preg_replace('#^ipfs://#', '', trim($cover));
                    // v287 FIX: Use VPS img.php?url= instead of Pinata public gateway.
                    // Pinata throttles on mobile and adds latency to every dashboard
                    // metadata call. img.php?url= fetches from our known cover_ipfs CID
                    // directly (bypasses the VPS indexer), caches result, serves instantly.
                    $img = 'https://metadata.imcollectibles.io/img.php?url=' . urlencode('ipfs://' . $cid);
                }
                $name = $local['nft_name'];
                if (!empty($local['edition_number'])) {
                    $name .= ' #' . $local['edition_number'];
                }
                error_log("get_nft_metadata: SUCCESS from local DB for $nft_id: name='$name'");
                return [
                    'nft_name'  => $name,
                    'name'      => $name,
                    'image'     => $img,
                    'issuer'    => $local['artist_account'] ?? '',
                    'nftokenID' => $nft_id
                ];
            }
        }
    }
    
    // METHOD 1: Try IMU VPS metadata API first (best for IMU collections)
    // Update this URL to match your actual VPS endpoint
    // P1d: fetch=fast (bounded miss-path w/ write-back) replaces fetch=true.
    $imu_urls = [
      'https://metadata.imcollectibles.io/?action=get&id=' . urlencode($nft_id) . '&fetch=fast',
      'https://imcollectibles.io/nft-api/?action=get&id=' . urlencode($nft_id) . '&fetch=fast'
    ];
    
    foreach ($imu_urls as $imu_url) {
      $imu_res = wp_remote_get($imu_url, [
        'timeout' => 8,
        'sslverify' => false,
        'headers' => ['Accept' => 'application/json']
      ]);
      
      if (!is_wp_error($imu_res)) {
        $body = wp_remote_retrieve_body($imu_res);
        $imu_body = json_decode($body, true);
        
        if (!empty($imu_body['success']) && !empty($imu_body['nft'])) {
          $nft = $imu_body['nft'];
          // P1d GUARD: any-mode pending row = content miss; continue to the
          // existing fallback chain exactly as a VPS miss always has.
          if (!empty($nft['decode_status']) && $nft['decode_status'] !== 'success') {
            error_log("get_nft_metadata: VPS row pending decode for $nft_id - falling through");
            continue;
          }
          $metadata = $nft['metadata'] ?? [];
          $assets = $nft['assets'] ?? [];
          
          $img = $assets['image'] ?? ($metadata['image'] ?? ($nft['image'] ?? $fallback['image']));
          $nm = $metadata['name'] ?? ($nft['name'] ?? $fallback['nft_name']);
          
          error_log("get_nft_metadata: SUCCESS from IMU API for $nft_id: name='$nm', image='$img'");
          return [
            'nft_name'  => $nm,
            'name'      => $nm,
            'image'     => $img,
            'issuer'    => $nft['issuer'] ?? '',
            'nftokenID' => $nft_id
          ];
        } else {
          error_log("get_nft_metadata: IMU API response not successful for $nft_id from $imu_url: " . substr($body, 0, 200));
        }
      } else {
        error_log("get_nft_metadata: IMU API error for $nft_id from $imu_url: " . $imu_res->get_error_message());
      }
    }
    
    // P2b-D1 (Aug 2026): METHOD 2 (Bithomp) deleted - its URL carried
    // assets=true, free-tier-rejected, so this tier has failed on every
    // invocation. The chain falls through to the fallback exactly as it
    // already did in practice.
    error_log("get_nft_metadata: All methods failed for $nft_id, using fallback");
    return $fallback;
  }
}


/**
 * P2g (Aug 2026): epoch-aware expiry guard for wp_xumm_offers reads.
 * The listener now stores expiration (unix epoch; NULL/0 = GTC). Column may
 * not exist until its first run, so the clause is emitted only when present.
 */
function imc_2g_exp_sql() {
    static $has = null;
    if ($has === null) {
        global $wpdb;
        $t = $wpdb->prefix . 'xumm_offers';
        $has = (bool)$wpdb->get_var("SHOW COLUMNS FROM {$t} LIKE 'expiration'");
    }
    return $has ? " AND (expiration IS NULL OR expiration = 0 OR expiration > UNIX_TIMESTAMP()) " : '';
}

/**
 * P2b (Aug 2026): one-call enrichment for DB-first offer lanes.
 * A single VPS batch (any=1) returns names, proxy images and transfer_fee for
 * a set of NFT ids - replacing per-NFT Bithomp fan-outs (the incoming fetcher
 * alone was up to 101 sequential calls). Graceful empty map on any failure.
 * @return array map[nft_id] => ['name'=>..,'image'=>..,'transfer_fee'=>int]
 */
function imc_2b_enrich($nft_ids) {
    $out = [];
    $nft_ids = array_values(array_unique(array_filter($nft_ids)));
    if (empty($nft_ids)) return $out;
    // D2 (Aug 2026): one retry + a 60s last-good fallback. This function was
    // ALL-OR-NOTHING — any single failure blanked every row of its lane at once
    // (the "couple fine, more added, crashes out" curve). A blip now degrades to
    // slightly-stale names, never to a wholesale-fallback section.
    sort($nft_ids);
    $k2b = 'imc2b_' . md5(implode(',', $nft_ids));
    $res = null;
    for ($e_try = 0; $e_try < 2; $e_try++) {
        $res = wp_remote_post((defined('IMC_INDEXER_URL') ? IMC_INDEXER_URL : '') . '?action=batch', [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['ids' => array_slice($nft_ids, 0, 100), 'any' => '1']),
            'timeout' => 6
        ]);
        if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) break;
        if ($e_try === 0) usleep(500000);
    }
    if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
        $fb = get_transient($k2b);
        return is_array($fb) ? $fb : $out;
    }
    $body = json_decode(wp_remote_retrieve_body($res), true);
    foreach (($body['nfts'] ?? []) as $n) {
        $nid = $n['nft_token_id'] ?? '';
        if (!$nid) continue;
        // D1 (Aug 2026): GUARDED proxy-first. image_proxy is emitted UNCONDITIONALLY
        // by the batch (even for rows with no image fields), so under any=1 an
        // imageless pending row would stamp a guaranteed-400 URL. Only use the proxy
        // when an underlying image actually exists; otherwise stamp no image at all.
        $e_res = (string)($n['image_resolved'] ?? '');
        $e_url = (string)($n['image_url'] ?? '');
        $img = '';
        if ($e_res !== '' || $e_url !== '') {
            $img = (string)($n['image_proxy'] ?? '');
            if ($img === '') $img = ($e_res !== '' ? $e_res : $e_url);
        }
        $out[$nid] = [
            'name'         => (!empty($n['name']) && $n['name'] !== 'Unnamed NFT') ? $n['name'] : '',
            'image'        => $img,
            'transfer_fee' => (int)($n['transfer_fee'] ?? 0)
        ];
    }
    if (!empty($out)) set_transient($k2b, $out, 60); // D2: last-good for the fallback path
    return $out;
}

/**
 * Fetch NFT offers via Bithomp API - FAST indexed data
 * Returns all offers created by OR targeting the account
 * 
 * @param string $account XRPL account address
 * @return array ['outgoing' => [...], 'incoming' => [...], 'source' => 'bithomp'|'error']
 */
function fetch_offers_via_bithomp($account) {
    // P2b-S2 (Aug 2026): DB-first. The listener captures every offer XRPL-wide
    // into wp_xumm_offers (reconciled ledger-true by P2b-0); this account's own
    // offers are read in ONE indexed query instead of a 15s Bithomp round-trip.
    // Function name kept for its callers; contract keys identical; source 'db'.
    global $wpdb;
    $t = $wpdb->prefix . 'xumm_offers';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT offer_id, target_nft_id, offer_type, amount, currency, destination, created_at
         FROM {$t}
         WHERE offerer_account = %s AND status = 'active'
         " . imc_2g_exp_sql() . "
         ORDER BY created_at DESC LIMIT 100",
        $account
    ), ARRAY_A) ?: [];

    $enrich = imc_2b_enrich(array_column($rows, 'target_nft_id'));
    $outgoing = [];
    foreach ($rows as $r) {
        $nid = $r['target_nft_id'] ?? '';
        $e = $enrich[$nid] ?? [];
        $outgoing[] = [
            'offer_id'    => $r['offer_id'] ?? '',
            'nft_id'      => $nid,
            'nft_name'    => !empty($e['name']) ? $e['name'] : 'Unnamed NFT',
            'nft_image'   => !empty($e['image']) ? $e['image'] : '/wp-content/uploads/fallback-nft.svg',
            'type'        => (($r['offer_type'] ?? 'sell') === 'buy') ? 'buy' : 'sell',
            'amount'      => (float)($r['amount'] ?? 0),
            'currency'    => $r['currency'] ?? 'XRP',
            'destination' => $r['destination'] ?? null,
            'expiration'  => null,
            'status'      => 'active',
            'source'      => 'db'
        ];
    }

    $incoming = fetch_incoming_offers_via_bithomp($account);

    return [
        'outgoing' => $outgoing,
        'incoming' => $incoming,
        'source'   => 'db'
    ];
}

/**
 * Fetch incoming offers (buy offers on NFTs this account owns) via Bithomp
 */
function fetch_incoming_offers_via_bithomp($account) {
    // P2b-S1 (Aug 2026): DB-first, replacing up to 101 sequential Bithomp calls
    // (owned-list + per-NFT buyOffers probes) with ONE indexed query - the A3
    // pattern from get_dashboard_v2, blacklist enforced IN the SQL. Contract
    // keys identical to the Bithomp version, now with real names/images and
    // ledger-true royalty math from the store's transfer_fee.
    global $wpdb;
    $t  = $wpdb->prefix . 'xumm_offers';
    $p  = $wpdb->prefix . 'imc_purchases';
    $bl = $wpdb->prefix . 'xumm_blacklist';
    if ($wpdb->get_var("SHOW TABLES LIKE '$p'") !== $p) return [];
    $bl_sql = ($wpdb->get_var("SHOW TABLES LIKE '$bl'") === $bl)
        ? "AND o.offerer_account NOT IN (SELECT wallet_address FROM {$bl})" : '';

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT o.offer_id, o.target_nft_id, o.offerer_account, o.amount, o.currency,
                o.destination, o.created_at" . (imc_2g_exp_sql() !== '' ? ", o.amount_issuer" : "") . "
         FROM {$t} o
         INNER JOIN {$p} pp ON o.target_nft_id = CONVERT(pp.nftoken_id USING utf8mb4) COLLATE utf8mb4_general_ci
         WHERE pp.buyer_account = %s
           AND o.offer_type = 'buy'
           AND o.status = 'active'
           AND o.offerer_account != %s
           {$bl_sql}
         " . str_replace('expiration', 'o.expiration', imc_2g_exp_sql()) . "
         ORDER BY o.created_at DESC LIMIT 50",
        $account, $account
    ), ARRAY_A) ?: [];

    $enrich = imc_2b_enrich(array_column($rows, 'target_nft_id'));
    $incoming = [];
    $seen = [];
    foreach ($rows as $r) {
        $oid = $r['offer_id'] ?? '';
        if ($oid === '' || isset($seen[$oid])) continue;
        $seen[$oid] = true;
        $nid = $r['target_nft_id'] ?? '';
        $e = $enrich[$nid] ?? [];
        $amount = (float)($r['amount'] ?? 0);
        $royalty_percent = ((int)($e['transfer_fee'] ?? 0)) / 1000;
        $royalty_amount  = $amount * ($royalty_percent / 100);
        $incoming[] = [
            'offer_id'        => $oid,
            'nft_id'          => $nid,
            'nft_name'        => !empty($e['name']) ? $e['name'] : 'Unnamed NFT',
            'nft_image'       => !empty($e['image']) ? $e['image'] : '/wp-content/uploads/fallback-nft.svg',
            'type'            => 'buy',
            'amount'          => $amount,
            'currency'        => $r['currency'] ?? 'XRP',
            'offerer'         => $r['offerer_account'] ?? '',
            'destination'     => $r['destination'] ?? null,
            'expiration'      => null,
            // P2g (G-1): payment-side trust flags - what this offer PAYS WITH.
            'payment_is_token' => (($r['currency'] ?? 'XRP') !== 'XRP'),
            'payment_scam'     => (isset($r['amount_issuer']) && $r['amount_issuer'] && function_exists('imc_get_scam_issuers')
                                   && in_array(strtolower($r['amount_issuer']), imc_get_scam_issuers(), true)),
            'royalty_percent' => $royalty_percent,
            'royalty_amount'  => round($royalty_amount, 6),
            'net_amount'      => round($amount - $royalty_amount, 6),
            'status'          => 'active',
            'source'          => 'db'
        ];
    }
    // P2-J1: hide-to-protect — scam-payment offers never leave this function
    // (policy call, 12 Aug 2026). NULL amount_issuer = fail-open, kept.
    $incoming = array_values(array_filter($incoming, function ($j1_r) { return empty($j1_r['payment_scam']); }));
    return $incoming;
}

/**
 * Fetch offers via XRPL RPC with proper pagination
 * Used as fallback when Bithomp is unavailable
 */
function fetch_outgoing_offers_via_xrpl($account) {
    $offers = [];
    $marker = null;
    $max_pages = 5; // Prevent infinite loops
    $page = 0;
    
    do {
        $params = [
            'account' => $account,
            'type' => 'nft_offer',
            'ledger_index' => 'validated',
            'limit' => 200
        ];
        
        if ($marker) {
            $params['marker'] = $marker;
        }
        
        $result = xrpl_rpc('account_objects', $params);
        
        if (isset($result['error']) || !isset($result['result']['account_objects'])) {
            break;
        }
        
        foreach ($result['result']['account_objects'] as $obj) {
            if (($obj['LedgerEntryType'] ?? '') !== 'NFTokenOffer') continue;
            
            $nft_id = $obj['NFTokenID'] ?? '';
            $flags = $obj['Flags'] ?? 0;
            $is_sell_offer = ($flags & 1) === 1;
            
            $amount_raw = $obj['Amount'] ?? '0';
            if (is_string($amount_raw)) {
                $amount = (float)$amount_raw / 1000000;
                $currency = 'XRP';
            } else {
                $amount = (float)($amount_raw['value'] ?? 0);
                $currency = $amount_raw['currency'] ?? 'XRP';
            }
            
            $meta = get_nft_metadata($nft_id);
            
            $offers[] = [
                'offer_id' => $obj['index'] ?? '',
                'nft_id' => $nft_id,
                'nft_name' => $meta['nft_name'] ?? $meta['name'] ?? 'Unnamed NFT',
                'nft_image' => $meta['image'] ?? '/wp-content/uploads/fallback-nft.svg',
                'type' => $is_sell_offer ? 'sell' : 'buy',
                'amount' => $amount,
                'currency' => $currency,
                'destination' => $obj['Destination'] ?? null,
                'expiration' => $obj['Expiration'] ?? null,
                'status' => 'active',
                'source' => 'ledger'
            ];
        }
        
        $marker = $result['result']['marker'] ?? null;
        $page++;
        
    } while ($marker && $page < $max_pages);
    
    error_log("fetch_outgoing_offers_via_xrpl: Found " . count($offers) . " offers in $page pages");
    
    return $offers;
}

/**
 * Fetch incoming offers via XRPL RPC with proper pagination
 * WARNING: This is slow for large collections (N+1 queries)
 * @param string $account XRPL account
 * @param int $max_nfts Maximum NFTs to check (0 = all)
 */
function fetch_incoming_offers_via_xrpl($account, $max_nfts = 0) {
    return fetch_incoming_offers_via_xrpl_v2($account, $max_nfts);
}

/**
 * Fetch incoming GIFT offers (sell offers from others where you are the Destination)
 * These are NFTs being offered TO you - airdrops, gifts, transfers
 * Uses account_tx to find NFTokenCreateOffer transactions targeting this account
 * 
 * @param string $account XRPL account to check
 * @return array List of incoming gift offers
 */
function fetch_incoming_gift_offers($account) {
    // P2b-S3 (Aug 2026): DB-first (A2 pattern - sell offers whose Destination is
    // this wallet). Replaces a no-op account_tx scan + a 15s Bithomp call.
    // Contract keys identical; blacklist enforced in the SQL.
    global $wpdb;
    $t  = $wpdb->prefix . 'xumm_offers';
    $bl = $wpdb->prefix . 'xumm_blacklist';
    $bl_sql = ($wpdb->get_var("SHOW TABLES LIKE '$bl'") === $bl)
        ? "AND offerer_account NOT IN (SELECT wallet_address FROM {$bl})" : '';

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT offer_id, target_nft_id, offerer_account, amount, currency, created_at
         FROM {$t}
         WHERE destination = %s
           AND offer_type = 'sell'
           AND status = 'active'
           AND offerer_account != %s
           {$bl_sql}
         " . imc_2g_exp_sql() . "
         ORDER BY created_at DESC LIMIT 50",
        $account, $account
    ), ARRAY_A) ?: [];

    $enrich = imc_2b_enrich(array_column($rows, 'target_nft_id'));
    $offers = [];
    foreach ($rows as $r) {
        $nid = $r['target_nft_id'] ?? '';
        $e = $enrich[$nid] ?? [];
        $offers[] = [
            'offer_id'        => $r['offer_id'] ?? '',
            'nft_id'          => $nid,
            'nft_name'        => !empty($e['name']) ? $e['name'] : 'Incoming NFT',
            'nft_image'       => !empty($e['image']) ? $e['image'] : '/wp-content/uploads/fallback-nft.svg',
            'type'            => 'gift',
            'is_transfer'     => true,
            'is_gift'         => true,
            'amount'          => (float)($r['amount'] ?? 0),
            'currency'        => $r['currency'] ?? 'XRP',
            'offerer'         => $r['offerer_account'] ?? '',
            'destination'     => $account,
            'expiration'      => null,
            'royalty_percent' => 0,
            'royalty_amount'  => 0,
            'net_amount'      => 0,
            'status'          => 'active',
            'source'          => 'db',
            'accept_type'     => 'sell_offer'
        ];
    }
    return $offers;
}

/**
 * Fetch incoming gift offers by checking specific NFT sell offers via XRPL
 * This requires knowing which NFT IDs to check - typically from recent transactions
 * 
 * @param string $account Destination account (recipient)
 * @param array $nft_ids List of NFT IDs to check for sell offers
 * @return array List of sell offers destined to this account
 */
function fetch_sell_offers_for_destination($account, $nft_ids = []) {
    $offers = [];
    
    foreach ($nft_ids as $nft_id) {
        $sell_offers = xrpl_rpc('nft_sell_offers', [
            'nft_id' => $nft_id,
            'ledger_index' => 'validated'
        ], 5);
        
        if (isset($sell_offers['error']) || !isset($sell_offers['result']['offers'])) {
            continue;
        }
        
        foreach ($sell_offers['result']['offers'] as $offer) {
            $destination = $offer['destination'] ?? null;
            
            // Only include offers where we are the destination
            if ($destination && strtolower($destination) === strtolower($account)) {
                $amount_raw = $offer['amount'] ?? '0';
                
                if (is_string($amount_raw)) {
                    $amount = (float)$amount_raw / 1000000;
                    $currency = 'XRP';
                } else {
                    $amount = (float)($amount_raw['value'] ?? 0);
                    $currency = $amount_raw['currency'] ?? 'XRP';
                }
                
                $is_free = ($amount == 0);
                $meta = get_nft_metadata($nft_id);
                
                $offers[] = [
                    'offer_id' => $offer['nft_offer_index'] ?? '',
                    'nft_id' => $nft_id,
                    'nft_name' => $meta['nft_name'] ?? $meta['name'] ?? 'Incoming NFT',
                    'nft_image' => $meta['image'] ?? '/wp-content/uploads/fallback-nft.svg',
                    'type' => 'gift',
                    'is_transfer' => true,
                    'is_gift' => true,
                    'amount' => $amount,
                    'currency' => $currency,
                    'offerer' => $offer['owner'] ?? '',
                    'destination' => $account,
                    'expiration' => $offer['expiration'] ?? null,
                    'royalty_percent' => 0,
                    'royalty_amount' => 0,
                    'net_amount' => 0,
                    'status' => 'active',
                    'source' => 'ledger',
                    'accept_type' => 'sell_offer'
                ];
            }
        }
    }
    
    return $offers;
}

/**
 * Fetch INCOMING TRANSFER OFFERS - Sell offers where this account is the Destination
 * These are NFTs others want to GIVE to this account
 * 
 * @param string $account XRPL account to check
 * @return array Array of transfer offers destined to this account
 */
function fetch_incoming_transfer_offers($account) {
    // P2b-S4 (Aug 2026): DB-first destination lane (was Bithomp + a 200-tx
    // account_tx sweep). Original type semantics preserved: zero-amount =
    // transfer_in, priced = sell_to_me. Blacklist in the SQL.
    global $wpdb;
    $t  = $wpdb->prefix . 'xumm_offers';
    $bl = $wpdb->prefix . 'xumm_blacklist';
    $bl_sql = ($wpdb->get_var("SHOW TABLES LIKE '$bl'") === $bl)
        ? "AND offerer_account NOT IN (SELECT wallet_address FROM {$bl})" : '';

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT offer_id, target_nft_id, offerer_account, amount, currency, created_at
         FROM {$t}
         WHERE destination = %s
           AND offer_type = 'sell'
           AND status = 'active'
           AND offerer_account != %s
           {$bl_sql}
         " . imc_2g_exp_sql() . "
         ORDER BY created_at DESC LIMIT 50",
        $account, $account
    ), ARRAY_A) ?: [];

    $enrich = imc_2b_enrich(array_column($rows, 'target_nft_id'));
    $offers = [];
    foreach ($rows as $r) {
        $nid = $r['target_nft_id'] ?? '';
        $e = $enrich[$nid] ?? [];
        $amount = (float)($r['amount'] ?? 0);
        $is_transfer = ($amount == 0);
        $offers[] = [
            'offer_id'         => $r['offer_id'] ?? '',
            'nft_id'           => $nid,
            'nft_name'         => !empty($e['name']) ? $e['name'] : 'Unnamed NFT',
            'nft_image'        => !empty($e['image']) ? $e['image'] : '/wp-content/uploads/fallback-nft.svg',
            'type'             => $is_transfer ? 'transfer_in' : 'sell_to_me',
            'is_transfer'      => $is_transfer,
            'is_incoming_gift' => true,
            'amount'           => $amount,
            'currency'         => $r['currency'] ?? 'XRP',
            'offerer'          => $r['offerer_account'] ?? '',
            'destination'      => $account,
            'expiration'       => null,
            'status'           => 'active',
            'source'           => 'db'
        ];
    }
    return $offers;
}

/**
 * Fetch incoming offers via XRPL RPC v2 - handles 0-value transfer offers
 * @param string $account XRPL account
 * @param int $max_nfts Maximum NFTs to check (0 = 100 default cap)
 */
function fetch_incoming_offers_via_xrpl_v2($account, $max_nfts = 0) {
    $offers = [];
    $marker = null;
    $max_pages = 5;
    $page = 0;
    
    $start_time = microtime(true);
    
    // First get all NFTs owned by this account
    $all_nfts = [];
    
    do {
        $params = [
            'account' => $account,
            'ledger_index' => 'validated',
            'limit' => 400
        ];
        
        if ($marker) {
            $params['marker'] = $marker;
        }
        
        $result = xrpl_rpc('account_nfts', $params, 20);
        
        if (isset($result['error']) || !isset($result['result']['account_nfts'])) {
            error_log("fetch_incoming_offers_via_xrpl_v2: Error getting NFTs - " . json_encode($result['error'] ?? 'no result'));
            break;
        }
        
        $all_nfts = array_merge($all_nfts, $result['result']['account_nfts']);
        $marker = $result['result']['marker'] ?? null;
        $page++;
        
        // If max_nfts is set and we have enough, stop
        if ($max_nfts > 0 && count($all_nfts) >= $max_nfts) {
            $all_nfts = array_slice($all_nfts, 0, $max_nfts);
            break;
        }
        
    } while ($marker && $page < $max_pages);
    
    $nft_count = count($all_nfts);
    error_log("fetch_incoming_offers_via_xrpl_v2: Found $nft_count NFTs to check for offers");
    
    // Limit to reasonable number to avoid excessive slowness (100 cap)
    $nfts_to_check = array_slice($all_nfts, 0, min($max_nfts ?: 100, 100));
    
    $checked = 0;
    $offers_found = 0;
    
    // Now check each NFT for buy offers (including 0-value transfer offers)
    foreach ($nfts_to_check as $nft) {
        $nft_id = $nft['NFTokenID'] ?? '';
        if (!$nft_id) continue;
        
        $checked++;
        
        // Log progress every 10 NFTs
        if ($checked % 10 === 0) {
            error_log("fetch_incoming_offers_via_xrpl_v2: Progress $checked/" . count($nfts_to_check) . " NFTs checked, $offers_found offers found");
        }
        
        $buy_offers = xrpl_rpc('nft_buy_offers', [
            'nft_id' => $nft_id,
            'ledger_index' => 'validated'
        ], 5);
        
        // Check for error or no offers - note: objectNotFound means no offers exist (this is OK)
        if (isset($buy_offers['error'])) {
            // objectNotFound is expected when there are no offers
            if (strpos($buy_offers['error'], 'objectNotFound') === false) {
                error_log("fetch_incoming_offers_via_xrpl_v2: Error for NFT $nft_id: " . $buy_offers['error']);
            }
            continue;
        }
        
        if (!isset($buy_offers['result']['offers'])) {
            continue;
        }
        
        $transfer_fee = $nft['TransferFee'] ?? 0;
        $royalty_percent = $transfer_fee / 1000;
        
        foreach ($buy_offers['result']['offers'] as $offer) {
            $offerer = $offer['owner'] ?? '';
            
            // Skip own offers
            if ($offerer === $account) continue;
            
            $amount_raw = $offer['amount'] ?? '0';
            if (is_string($amount_raw)) {
                $amount = (float)$amount_raw / 1000000;
                $currency = 'XRP';
            } else {
                $amount = (float)($amount_raw['value'] ?? 0);
                $currency = $amount_raw['currency'] ?? 'XRP';
            }
            
            // Determine if this is a transfer offer (0-value)
            $is_transfer_offer = ($amount == 0);
            $offer_type = $is_transfer_offer ? 'transfer' : 'buy';
            
            $royalty_amount = $amount * ($royalty_percent / 100);
            $net_amount = $amount - $royalty_amount;
            
            // Get metadata only for NFTs with offers (avoid unnecessary calls)
            $meta = get_nft_metadata($nft_id);
            
            $offers[] = [
                'offer_id' => $offer['nft_offer_index'] ?? '',
                'nft_id' => $nft_id,
                'nft_name' => $meta['nft_name'] ?? $meta['name'] ?? 'Unnamed NFT',
                'nft_image' => $meta['image'] ?? '/wp-content/uploads/fallback-nft.svg',
                'type' => $offer_type,  // 'buy' or 'transfer'
                'is_transfer' => $is_transfer_offer,
                'amount' => $amount,
                'currency' => $currency,
                'offerer' => $offerer,
                'destination' => $offer['destination'] ?? null,
                'expiration' => $offer['expiration'] ?? null,
                'royalty_percent' => $royalty_percent,
                'royalty_amount' => round($royalty_amount, 6),
                'net_amount' => round($net_amount, 6),
                'status' => 'active',
                'source' => 'ledger'
            ];
            
            $offers_found++;
        }
    }
    
    $elapsed = round((microtime(true) - $start_time) * 1000);
    error_log("fetch_incoming_offers_via_xrpl_v2: Completed in {$elapsed}ms - checked $checked NFTs, found " . count($offers) . " incoming offers");
    
    return $offers;
}

/** ----------------- Xumm payload helper ----------------- */
function create_xumm_payload_with_webhook_local($txjson, $custom_meta_blob = []) {
  global $XUMM_API_KEY, $XUMM_API_SECRET, $WEBHOOK_URL, $log_file;
  if (!$XUMM_API_KEY || !$XUMM_API_SECRET) {
    error_log("create_xumm_payload: Missing XUMM API credentials");
    return ['error' => 'Missing XUMM API credentials'];
  }
  $type = $custom_meta_blob['type'] ?? (isset($txjson['TransactionType']) ? strtolower($txjson['TransactionType']) : 'payload');
  
  // Set appropriate instruction based on transaction type
  $instruction = 'Open in Xaman to review & sign';
  if (isset($custom_meta_blob['type'])) {
      switch ($custom_meta_blob['type']) {
          case 'nft_offer_create':
              $instruction = 'NFT Buy Offer on IMCollectibles';
              break;
          case 'nft_sell_offer_create':
              $instruction = 'NFT Listing on IMCollectibles';
              break;
          case 'nft_offer_accept':
              $instruction = 'Accept NFT Offer on IMCollectibles';
              break;
          case 'nft_offer_cancel':
              $instruction = 'Cancel NFT Offer on IMCollectibles';
              break;
      }
  }
  
  // -- Same-tab UX (Leg 2, additive): return the signer to the page they started on. --
  // Scoped to the single-NFT page (/nft/) and open-redirect-safe: same-origin host only,
  // path rebuilt via home_url(), validated by wp_validate_redirect(). Any miss => current default.
  $imc_return = home_url('/trading-hub/?offer_result=1');
  $imc_ref = $_SERVER['HTTP_REFERER'] ?? '';
  if ($imc_ref !== '') {
    $imc_rp = wp_parse_url($imc_ref);
    $imc_hp = wp_parse_url(home_url('/'));
    if (!empty($imc_rp['host']) && !empty($imc_hp['host'])
        && strcasecmp($imc_rp['host'], $imc_hp['host']) === 0
        && !empty($imc_rp['path'])
        && (strpos($imc_rp['path'], '/nft/') === 0 || strpos($imc_rp['path'], '/trading-hub-dashboard/') === 0)) {
      $imc_path = $imc_rp['path'];
      if (!empty($imc_rp['query'])) $imc_path .= '?' . $imc_rp['query'];
      $imc_return = wp_validate_redirect(home_url($imc_path), $imc_return);
    }
  }
  
  $payload = [
    'txjson' => $txjson,
    'custom_meta' => [
      'identifier'  => uniqid('offer_', true),
      'instruction' => $instruction,
      'blob'        => $custom_meta_blob,
      'type'        => $type
    ],
    'options' => [
      'submit'       => true,
      'multisign'    => false,
      'expire'       => 300,
      'return_url'   => ['web' => $imc_return, 'app' => $imc_return]
    ]
  ];
  if (!empty($WEBHOOK_URL)) {
    $payload['options']['pathfinding_fallback'] = false;
    $payload['custom_meta']['blob']['webhook'] = $WEBHOOK_URL;
  }
  $res = wp_remote_post('https://xumm.app/api/v1/platform/payload', [
    'body'    => json_encode($payload),
    'headers' => [
      'Content-Type'     => 'application/json',
      'X-API-Key'        => $XUMM_API_KEY,
      'X-API-Secret'     => $XUMM_API_SECRET
    ],
    'timeout' => 15
  ]);
  if (is_wp_error($res)) {
    error_log('create_xumm_payload: ' . $res->get_error_message());
    return ['error' => 'XUMM request failed: ' . $res->get_error_message()];
  }
  $body = json_decode(wp_remote_retrieve_body($res), true);
  if (empty($body['uuid'])) {
    error_log('create_xumm_payload: Missing uuid. Body=' . print_r($body, true));
    return ['error' => $body['error']['reference'] ?? 'XUMM payload creation failed'];
  }
  return $body;
}

/** ----------------- Calculate fees helper ----------------- */
function calculate_fees($amount_num, $royalty_percent, $platform_fee_percent) {
  if (function_exists('bcmul') && function_exists('bcsub') && function_exists('bcdiv')) {
    $royalty_rate   = bcdiv((string)$royalty_percent, '100', 10);
    $platform_rate  = bcdiv((string)$platform_fee_percent, '100', 10);
    $royalty_amount = bcmul($amount_num, $royalty_rate, 16);
    $platform_fee   = bcmul($amount_num, $platform_rate, 16);
    $net_amount     = bcsub(bcsub($amount_num, $royalty_amount, 16), $platform_fee, 16);
  } else {
    $royalty_amount = number_format((float)$amount_num * ($royalty_percent / 100.0), 16, '.', '');
    $platform_fee   = number_format((float)$amount_num * ($platform_fee_percent / 100.0), 16, '.', '');
    $net_amount     = number_format((float)$amount_num - (float)$royalty_amount - (float)$platform_fee, 16, '.', '');
  }
  return [
    'royalty_amount' => $royalty_amount,
    'platform_fee'   => $platform_fee,
    'net_amount'     => $net_amount
  ];
}


/** =============================================================================
 *  GET ENDPOINTS
 *  ============================================================================= */

/* ============================================================================
 * P2H-D1b (Aug 2026): nft_offer_counts — batch ACTIVE BUY-offer counts per NFT
 * for the profile drill-in pills. Read-only; blacklist + expiration filters
 * inherited verbatim from the 2b lane pattern. Hide-at-zero is client-side.
 * ==========================================================================*/
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['action'] ?? '') === 'nft_offer_counts')) {
    $nonce = sanitize_text_field($_GET['nonce'] ?? '');
    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        wp_send_json_error(['error' => 'Invalid nonce'], 403); exit;
    }
    $ids = [];
    foreach (explode(',', (string)($_GET['ids'] ?? '')) as $oc_id) {
        $oc_id = strtoupper(trim($oc_id));
        if (preg_match('/^[0-9A-F]{64}$/', $oc_id)) { $ids[] = $oc_id; }
        if (count($ids) >= 100) { break; }
    }
    $ids = array_values(array_unique($ids));
    if (empty($ids)) { wp_send_json(['success' => true, 'counts' => new stdClass()]); exit; }
    global $wpdb;
    $t  = $wpdb->prefix . 'xumm_offers';
    $bl = $wpdb->prefix . 'xumm_blacklist';
    $oc_bl_sql = ($wpdb->get_var("SHOW TABLES LIKE '$bl'") === $bl)
        ? "AND offerer_account NOT IN (SELECT wallet_address FROM {$bl})" : '';
    $oc_ph = implode(',', array_fill(0, count($ids), '%s'));
    $oc_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT target_nft_id, COUNT(*) AS c
         FROM {$t}
         WHERE target_nft_id IN ({$oc_ph})
           AND offer_type = 'buy' AND status = 'active'
           {$oc_bl_sql}
         " . imc_2g_exp_sql() . "
         GROUP BY target_nft_id",
        $ids
    ), ARRAY_A) ?: [];
    $oc_counts = [];
    foreach ($oc_rows as $oc_r) { $oc_counts[$oc_r['target_nft_id']] = (int)$oc_r['c']; }
    wp_send_json(['success' => true, 'counts' => $oc_counts ?: new stdClass()]);
    exit;
}

/* ============================================================================
 * P2I (Aug 2026): wallet_balances — session-scoped balances for the header
 * dropdown. Account resolved from the SESSION TOKEN ONLY (never a request
 * param — this is deliberately not an arbitrary-address balance API).
 * Reserve math from live server_info, never hardcoded. Scam trustlines are
 * COLLAPSED to a count, not silently hidden. 60s transient per account.
 * ==========================================================================*/
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['action'] ?? '') === 'wallet_balances')) {
    $nonce = sanitize_text_field($_GET['nonce'] ?? '');
    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        wp_send_json_error(['error' => 'Invalid nonce'], 403); exit;
    }
    $wb_account = function_exists('imc_session_resolve_wallet') ? (string) imc_session_resolve_wallet() : '';
    if ($wb_account === '' || !preg_match('/^r[1-9A-HJ-NP-Za-km-z]{24,34}$/', $wb_account)) {
        wp_send_json_error(['error' => 'Not authenticated'], 401); exit;
    }
    $wb_ck = 'imc_wb_' . md5($wb_account);
    $wb_cached = get_transient($wb_ck);
    if (is_array($wb_cached)) { wp_send_json($wb_cached); exit; }
    $wb_ai = xrpl_rpc('account_info', ['account' => $wb_account, 'ledger_index' => 'validated']);
    if (!empty($wb_ai['error']) || empty($wb_ai['result']['account_data'])) {
        wp_send_json_error(['error' => 'Balance lookup failed'], 502); exit;
    }
    $wb_ad = $wb_ai['result']['account_data'];
    $wb_balance = (float) bcdiv((string)($wb_ad['Balance'] ?? '0'), '1000000', 6);
    $wb_owner_count = (int) ($wb_ad['OwnerCount'] ?? 0);
    // live reserve math (falls back to current mainnet values only if server_info is unreachable)
    $wb_si = xrpl_rpc('server_info', []);
    $wb_vl = $wb_si['result']['info']['validated_ledger'] ?? [];
    $wb_base = isset($wb_vl['reserve_base_xrp']) ? (float)$wb_vl['reserve_base_xrp'] : 1.0;
    $wb_inc  = isset($wb_vl['reserve_inc_xrp'])  ? (float)$wb_vl['reserve_inc_xrp']  : 0.2;
    $wb_reserve   = $wb_base + ($wb_inc * $wb_owner_count);
    $wb_available = max(0, $wb_balance - $wb_reserve);
    $wb_scams = function_exists('imc_get_scam_issuers') ? imc_get_scam_issuers() : [];
    $wb_tokens = []; $wb_scam_lines = 0;
    $wb_al = xrpl_rpc('account_lines', ['account' => $wb_account, 'ledger_index' => 'validated', 'limit' => 400]);
    foreach (($wb_al['result']['lines'] ?? []) as $wb_line) {
        $wb_bal = (float) ($wb_line['balance'] ?? 0);
        if ($wb_bal <= 0) { continue; }
        if (in_array(strtolower($wb_line['account'] ?? ''), $wb_scams, true)) { $wb_scam_lines++; continue; }
        $wb_tokens[] = [
            'currency' => (string)($wb_line['currency'] ?? ''),
            'ticker'   => imc_currency_display((string)($wb_line['currency'] ?? '')),
            'issuer'   => (string)($wb_line['account'] ?? ''),
            'balance'  => $wb_bal,
        ];
    }
    usort($wb_tokens, function($a, $b) { return $b['balance'] <=> $a['balance']; });
    $wb_resp = [
        'success' => true,
        'account' => $wb_account,
        'xrp'     => ['balance' => $wb_balance, 'reserve' => $wb_reserve, 'available' => round($wb_available, 6), 'owner_count' => $wb_owner_count],
        'tokens'  => array_slice($wb_tokens, 0, 50),
        'scam_lines' => $wb_scam_lines,
    ];
    set_transient($wb_ck, $wb_resp, 60);
    wp_send_json($wb_resp);
    exit;
}

/** GET: get_nft_name (used by trading.js) */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['action'] ?? '') === 'get_nft_name') ) {
  $nft_id = sanitize_text_field($_GET['nft_id'] ?? '');
  if (!preg_match('/^[0-9A-Fa-f]{64}$/', $nft_id)) {
    status_header(400);
    wp_send_json(['success' => false, 'error' => 'Invalid NFTokenID']);
  }
  $meta = get_nft_metadata($nft_id);
  wp_send_json(['success' => true, 'nft_name' => $meta['nft_name'], 'image' => $meta['image']]);
  exit;
}

/** GET: check_balance (XRP & IOU) */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['action'] ?? '') === 'check_balance') ) {
  $account    = sanitize_text_field($_GET['account'] ?? '');
  $currency   = strtoupper(sanitize_text_field($_GET['currency'] ?? 'XRP'));
  $issuer     = sanitize_text_field($_GET['issuer'] ?? '');
  $amount_raw = trim((string)($_GET['amount'] ?? '0'));
  
  if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
    status_header(400);
    wp_send_json(['success' => false, 'error' => 'Invalid account']);
  }
  
  // v86: For IOU, try to look up issuer if not provided
  if ($currency !== 'XRP' && !$issuer) {
    if (function_exists('imc_get_token_by_ticker')) {
      $token_info = imc_get_token_by_ticker($currency);
      if ($token_info && !empty($token_info['issuer'])) {
        $issuer = $token_info['issuer'];
      }
    }
    // Fallback for known tokens
    if (!$issuer) {
      $known_issuers = [
        'XFT' => 'rGpnoqYLzWytxwQhhz715nRbqyCHM7zhxt',
        'RLUSD' => 'rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De',
        'SOLO' => 'rsoLo2S1kiGeCcn6hCUXVrCpGMWLrRrLZz',
        'SCHMECKLES' => 'rPxw83ZP6thv7KmG5DpAW4cDW55DZRZ9wu',
        'XMEME' => 'r4UPddYeGeZgDhSGPkooURsQtmGda4oYQW'
      ];
      $issuer = $known_issuers[$currency] ?? '';
    }
  }
  
  if ($currency === 'XRP') {
    $ai = xrpl_rpc('account_info', ['account' => $account, 'ledger_index' => 'validated']);
    if (!empty($ai['error'])) {
      error_log("check_balance: Account info unavailable for $account: " . $ai['error']);
      wp_send_json(['success' => false, 'error' => 'Account lookup failed']);
    }
    $bal = $ai['result']['account_data']['Balance'] ?? null;
    if ($bal === null) {
      error_log("check_balance: Unable to read balance for $account");
      wp_send_json(['success' => false, 'error' => 'Unable to read balance']);
    }
    $drops = xrp_decimal_to_drops($amount_raw);
    if ($drops === null) {
      error_log("check_balance: Invalid amount format for XRP: $amount_raw");
      status_header(400);
      wp_send_json(['success' => false, 'error' => 'Invalid amount format']);
    }
    $reserve = 10000000;
    $available = bcsub($bal, (string)$reserve, 0);
    $ok = bccomp($available, $drops, 0) >= 0;
    $balOut = bcdiv($bal, '1000000', 6);
    error_log("[check_balance] acct=$account ccy=$currency issuer=$issuer amount=$amount_raw ok=" . ($ok ? '1':'0') . " bal=$balOut");
    wp_send_json([
      'success'   => true,
      'ok'        => $ok,
      'balance'   => $balOut,
      'currency'  => 'XRP',
      'requested' => $amount_raw
    ]);
    exit;
  } else {
    if (!$issuer) {
      wp_send_json(['success' => false, 'error' => 'Missing issuer for IOU']);
    }
    $r = xrpl_rpc('account_lines', ['account' => $account, 'peer' => $issuer, 'ledger_index' => 'validated']);
    if (!empty($r['error'])) {
      error_log("check_balance: Account lines unavailable for $account: " . $r['error']);
      wp_send_json(['success' => false, 'error' => 'Trustline lookup failed']);
    }
    $line = null;
    foreach (($r['result']['lines'] ?? []) as $l) {
      if (currency_to_hex($l['currency'] ?? '') === currency_to_hex($currency) && ($l['account'] ?? '') === $issuer) {
        $line = $l; break;
      }
    }
    if (!$line) {
      error_log("check_balance: Missing trustline for $currency/$issuer on $account");
      wp_send_json(['success' => false, 'error' => 'No trustline', 'ok' => false, 'balance' => '0']);
    }
    if (!empty($line['freeze']) || !empty($line['freeze_peer'])) {
        error_log("check_balance: Trustline frozen for $currency/$issuer on $account");
        wp_send_json(['success' => false, 'error' => 'Trustline frozen', 'ok' => false, 'balance' => '0']);
    }
    $bal = (float)($line['balance'] ?? 0);
    $ok  = $bal >= (float)$amount_raw;
    $balOut = number_format($bal, 6, '.', '');
    error_log("[check_balance] acct=$account ccy=$currency issuer=$issuer amount=$amount_raw ok=" . ($ok ? '1':'0') . " bal=$balOut");
    wp_send_json([
      'success'   => true,
      'ok'        => $ok,
      'balance'   => $balOut,
      'currency'  => $currency,
      'requested' => $amount_raw
    ]);
    exit;
  }
}

/** GET: poll_xumm_payload - Direct XUMM polling without database lookup */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['action'] ?? '') === 'poll_xumm_payload')) {
  global $XUMM_API_KEY, $XUMM_API_SECRET;
  
  $uuid = sanitize_text_field($_GET['uuid'] ?? '');
  $nonce = sanitize_text_field($_GET['nonce'] ?? '');
  $expected_type = sanitize_text_field($_GET['type'] ?? ''); // e.g. 'nft_offer_cancel'
  
  if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
    status_header(403);
    wp_send_json(['success' => false, 'error' => 'Invalid nonce']);
  }
  
  if (!preg_match('/^[a-f0-9\-]{36}$/', $uuid)) {
    status_header(400);
    wp_send_json(['success' => false, 'error' => 'Invalid UUID format']);
  }
  
  if (empty($XUMM_API_KEY) || empty($XUMM_API_SECRET)) {
    status_header(500);
    wp_send_json(['success' => false, 'error' => 'XUMM credentials not configured']);
  }
  
  // Query XUMM API directly
  $xumm_url = "https://xumm.app/api/v1/platform/payload/{$uuid}";
  $xumm_res = wp_remote_get($xumm_url, [
    'headers' => [
      'X-API-Key'    => $XUMM_API_KEY,
      'X-API-Secret' => $XUMM_API_SECRET,
      'Content-Type' => 'application/json'
    ],
    'timeout' => 10
  ]);
  
  if (is_wp_error($xumm_res)) {
    error_log("poll_xumm_payload: XUMM API error for $uuid: " . $xumm_res->get_error_message());
    wp_send_json(['success' => false, 'error' => 'Failed to contact XUMM', 'status' => 'pending']);
  }
  
  $xumm_data = json_decode(wp_remote_retrieve_body($xumm_res), true);
  $meta = $xumm_data['meta'] ?? [];
  $response = $xumm_data['response'] ?? [];
  $custom_meta = $xumm_data['custom_meta'] ?? [];
  $payload_type = $custom_meta['blob']['type'] ?? '';
  
  error_log("poll_xumm_payload: UUID=$uuid signed=" . ($meta['signed'] ?? 'false') . " resolved=" . ($meta['resolved'] ?? 'false') . " type=$payload_type");
  
  // Check for expired
  if (!empty($meta['expired']) && $meta['expired'] === true) {
    wp_send_json([
      'success' => true,
      'status' => 'expired',
      'signed' => false,
      'type' => $payload_type
    ]);
    exit;
  }
  
  // Check for rejection (resolved but not signed)
  if (!empty($meta['resolved']) && empty($meta['signed'])) {
    wp_send_json([
      'success' => true,
      'status' => 'rejected',
      'signed' => false,
      'type' => $payload_type
    ]);
    exit;
  }
  
  // Check for signed
  if (!empty($meta['signed']) && $meta['signed'] === true) {
    $tx_hash = $response['txid'] ?? '';
    $dispatched = $response['dispatched_result'] ?? '';
    $account = $response['account'] ?? '';
    
    // If we have tx_hash, verify on-chain
    $on_chain_status = 'signed_pending';
    if ($tx_hash) {
      $tx_res = wp_remote_post('https://xrplcluster.com/', [
        'body' => json_encode(['method' => 'tx', 'params' => [['transaction' => $tx_hash, 'binary' => false]]]),
        'headers' => ['Content-Type' => 'application/json'],
        'timeout' => 10
      ]);
      
      if (!is_wp_error($tx_res)) {
        $tx_body = json_decode(wp_remote_retrieve_body($tx_res), true);
        $validated = $tx_body['result']['validated'] ?? false;
        $tx_result = $tx_body['result']['meta']['TransactionResult'] ?? null;
        
        if ($validated && $tx_result === 'tesSUCCESS') {
          $on_chain_status = 'confirmed';
        } elseif ($validated && $tx_result !== null && $tx_result !== 'tesSUCCESS') {
          $on_chain_status = 'failed';
        }
      }
    } elseif ($dispatched === 'tesSUCCESS') {
      $on_chain_status = 'confirmed';
    } elseif ($dispatched && strpos($dispatched, 'tec') === 0) {
      $on_chain_status = 'failed';
    }
    
    // v199: When cancel is confirmed on-chain, update the offers table immediately
    // (The webhook may also do this, but it relies on UUID lookup which can fail
    //  because the cancel payload UUID differs from the original offer UUID)
    if ($on_chain_status === 'confirmed' && $payload_type === 'nft_offer_cancel') {
      $cancel_offer_id = $custom_meta['blob']['offer_id'] ?? '';
      if ($cancel_offer_id) {
        global $wpdb;
        $offers_table = $wpdb->prefix . 'xumm_offers';
        $updated = $wpdb->update($offers_table, ['status' => 'cancelled'], ['offer_id' => $cancel_offer_id, 'status' => 'active']);
        error_log("poll_xumm_payload: Cancel confirmed for offer_id=$cancel_offer_id, DB rows updated=$updated");
      }
    }

    // v201: When accept is confirmed on-chain, update the offers table immediately
    // Same pattern as v199 cancel fix — the webhook relies on UUID lookup which can fail
    // because buy_listing overwrites the UUID on the listing row, and accept flows from
    // the dashboard use accept_sell/accept_buy endpoints that don't store UUID in offers table.
    if ($on_chain_status === 'confirmed' && $payload_type === 'nft_offer_accept') {
      $accept_offer_id = $custom_meta['blob']['offer_id'] ?? '';
      if ($accept_offer_id) {
        global $wpdb;
        $offers_table = $wpdb->prefix . 'xumm_offers';
        // Update the accepted offer (could be buy or sell offer being accepted)
        $updated = $wpdb->update(
          $offers_table, 
          ['status' => 'accepted', 'accepted_at' => current_time('mysql')], 
          ['offer_id' => $accept_offer_id, 'status' => 'active']
        );
        error_log("poll_xumm_payload: Accept confirmed for offer_id=$accept_offer_id, DB rows updated=$updated");
        
        // If this was accepting a buy offer on our NFT, also cancel any active sell listings
        // for the same NFT (ownership transferred, sell listings are now invalid)
        $accept_nft_id = $custom_meta['blob']['nft_id'] ?? '';
        if ($accept_nft_id && $updated > 0) {
          $stale = $wpdb->update(
            $offers_table,
            ['status' => 'cancelled'],
            ['target_nft_id' => $accept_nft_id, 'offer_type' => 'sell', 'status' => 'active']
          );
          if ($stale > 0) {
            error_log("poll_xumm_payload: Cancelled $stale stale sell listing(s) for NFT $accept_nft_id after accept");
          }
        }
      }
    }

    // v424: When a TRANSFER OFFER is confirmed on-chain, extract the offer_id
    // from the transaction metadata and update the DB record.
    // Without this, the record stays offer_id='' / status='pending' and
    // Step A2 in the dashboard never finds it.
    // A transfer is: NFTokenCreateOffer with Amount=0 and Destination set.
    if ($on_chain_status === 'confirmed' && $payload_type === 'nft_transfer_offer') {
      $transfer_offer_id = '';
      // Extract offer_id from AffectedNodes → CreatedNode → NFTokenOffer
      if (!empty($tx_body['result']['meta']['AffectedNodes'])) {
        foreach ($tx_body['result']['meta']['AffectedNodes'] as $node) {
          $created = $node['CreatedNode'] ?? null;
          if ($created && ($created['LedgerEntryType'] ?? '') === 'NFTokenOffer') {
            $transfer_offer_id = $created['LedgerIndex'] ?? '';
            break;
          }
        }
      }
      if ($transfer_offer_id) {
        global $wpdb;
        $offers_table = $wpdb->prefix . 'xumm_offers';
        $updated = $wpdb->update(
          $offers_table,
          ['offer_id' => $transfer_offer_id, 'status' => 'active'],
          ['uuid' => $uuid]
        );
        error_log("poll_xumm_payload: Transfer offer confirmed, offer_id=$transfer_offer_id, uuid=$uuid, DB rows=$updated");
      } else {
        // Dispatched path (no tx_body) or couldn't find offer — still mark active
        global $wpdb;
        $offers_table = $wpdb->prefix . 'xumm_offers';
        $wpdb->update($offers_table, ['status' => 'active'], ['uuid' => $uuid]);
        error_log("poll_xumm_payload: Transfer offer confirmed but no offer_id extracted, uuid=$uuid");
      }
    }
    
    wp_send_json([
      'success' => true,
      'status' => $on_chain_status,
      'signed' => true,
      'type' => $payload_type,
      'tx_hash' => $tx_hash,
      'dispatched_result' => $dispatched,
      'account' => $account
    ]);
    exit;
  }
  
  // Still pending
  wp_send_json([
    'success' => true,
    'status' => 'pending',
    'signed' => false,
    'type' => $payload_type
  ]);
  exit;
}

/** GET: get_offer_status */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['action'] ?? '') === 'get_offer_status') ) {
  global $XUMM_API_KEY, $XUMM_API_SECRET;
  
  $uuid  = sanitize_text_field($_GET['uuid']);
  $nonce = sanitize_text_field($_GET['nonce'] ?? '');
  if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
    status_header(400);
    wp_send_json(['success' => false, 'error' => 'Invalid nonce']);
  }
  $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $offers_table WHERE uuid=%s", $uuid), ARRAY_A);
  if (!$row) {
    error_log("get_offer_status: Offer not found for UUID $uuid");
    status_header(404);
    wp_send_json(['success' => false, 'error' => 'Offer not found']);
  }
  $current = wp_get_current_user();
  if (!user_can($current, 'manage_options')) {
    $account = sanitize_text_field($_GET['account'] ?? '');
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
      status_header(403);
      wp_send_json(['success' => false, 'error' => 'Forbidden']);
    }
    if ($row['offerer_account'] !== $account && $row['target_account'] !== $account) {
      status_header(403);
      wp_send_json(['success' => false, 'error' => 'Forbidden']);
    }
  }
  
  // If status is still pending, check XUMM directly for real-time status
  if ($row['status'] === 'pending' && $XUMM_API_KEY && $XUMM_API_SECRET) {
    $xumm_url = "https://xumm.app/api/v1/platform/payload/{$uuid}";
    $xumm_res = wp_remote_get($xumm_url, [
      'headers' => [
        'X-API-Key'    => $XUMM_API_KEY,
        'X-API-Secret' => $XUMM_API_SECRET,
        'Content-Type' => 'application/json'
      ],
      'timeout' => 10
    ]);
    
    if (!is_wp_error($xumm_res)) {
      $xumm_data = json_decode(wp_remote_retrieve_body($xumm_res), true);
      error_log("get_offer_status: XUMM payload check for $uuid");
      
      $meta = $xumm_data['meta'] ?? [];
      $response = $xumm_data['response'] ?? [];
      
      // Check if signed and resolved
      if (!empty($meta['signed']) && $meta['signed'] === true && !empty($meta['resolved'])) {
        $tx_hash = $response['txid'] ?? '';
        $dispatched = $response['dispatched_result'] ?? '';
        
        error_log("get_offer_status: UUID $uuid signed. txid=$tx_hash, dispatched_result=$dispatched");
        
        // If we have a tx_hash, verify ON-CHAIN (same approach as burn-to-earn)
        if ($tx_hash) {
          $tx_res = wp_remote_post('https://xrplcluster.com/', [
            'body' => json_encode(['method' => 'tx', 'params' => [['transaction' => $tx_hash, 'binary' => false]]]),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 10
          ]);
          
          if (!is_wp_error($tx_res)) {
            $tx_body = json_decode(wp_remote_retrieve_body($tx_res), true);
            $validated = $tx_body['result']['validated'] ?? false;
            $tx_result = $tx_body['result']['meta']['TransactionResult'] ?? null;
            
            error_log("get_offer_status: On-chain check for $tx_hash: validated=$validated, TransactionResult=$tx_result");
            
            // CRITICAL: Only mark active if BOTH validated AND tesSUCCESS
            if ($validated && $tx_result === 'tesSUCCESS') {
              // v201: Detect transaction type to set correct status
              $tx_type = $tx_body['result']['TransactionType'] ?? '';
              
              if ($tx_type === 'NFTokenAcceptOffer') {
                // This is an ACCEPT flow — mark as accepted, not active
                $wpdb->update($offers_table, [
                  'status' => 'accepted', 
                  'accepted_at' => current_time('mysql')
                ], ['uuid' => $uuid]);
                error_log("get_offer_status: ACCEPTED! UUID $uuid confirmed on-chain (NFTokenAcceptOffer)");
                
                $row['status'] = 'accepted';
                
                // Also clean up stale sell listings for this NFT
                $accept_nft_id = $row['target_nft_id'] ?? '';
                if ($accept_nft_id && ($row['offer_type'] ?? '') === 'buy') {
                  $stale = $wpdb->update(
                    $offers_table,
                    ['status' => 'cancelled'],
                    ['target_nft_id' => $accept_nft_id, 'offer_type' => 'sell', 'status' => 'active']
                  );
                  if ($stale > 0) {
                    error_log("get_offer_status: Cancelled $stale stale sell listing(s) for NFT $accept_nft_id after accept");
                  }
                }
                
              } else {
                // This is a CREATE flow — extract offer_id and mark active
                $offer_id = '';
                foreach (($tx_body['result']['meta']['AffectedNodes'] ?? []) as $node) {
                  if (isset($node['CreatedNode']['LedgerEntryType']) && 
                      $node['CreatedNode']['LedgerEntryType'] === 'NFTokenOffer') {
                    $offer_id = $node['CreatedNode']['LedgerIndex'] ?? '';
                    break;
                  }
                }
                
                // Update database status to active
                $update_data = ['status' => 'active'];
                if ($offer_id) {
                  $update_data['offer_id'] = $offer_id;
                }
                
                $wpdb->update($offers_table, $update_data, ['uuid' => $uuid]);
                error_log("get_offer_status: SUCCESS! UUID $uuid confirmed on-chain, offer_id=$offer_id");
                
                $row['status'] = 'active';
                $row['offer_id'] = $offer_id;
              }
              
            } elseif ($validated && $tx_result !== null && $tx_result !== 'tesSUCCESS') {
              // Transaction validated but FAILED on-chain
              error_log("get_offer_status: UUID $uuid FAILED on-chain with: $tx_result");
              $wpdb->update($offers_table, ['status' => 'failed'], ['uuid' => $uuid]);
              $row['status'] = 'failed';
              $row['error'] = "Transaction failed: $tx_result";
              
            } else {
              // Transaction not yet validated - still processing
              error_log("get_offer_status: UUID $uuid signed but not yet validated on-chain");
              $row['status'] = 'signed_pending';
            }
          } else {
            // RPC error - keep as signed_pending, will retry
            error_log("get_offer_status: RPC error verifying tx $tx_hash: " . $tx_res->get_error_message());
            $row['status'] = 'signed_pending';
          }
          
        } elseif ($dispatched === 'tesSUCCESS') {
          // No txid but XUMM says tesSUCCESS - rare edge case, trust XUMM
          $wpdb->update($offers_table, ['status' => 'active'], ['uuid' => $uuid]);
          error_log("get_offer_status: UUID $uuid marked active (no txid, trusting XUMM dispatched_result)");
          $row['status'] = 'active';
          
        } elseif (!empty($dispatched) && (strpos($dispatched, 'tec') === 0 || strpos($dispatched, 'tef') === 0 || strpos($dispatched, 'tem') === 0)) {
          // XUMM already knows it failed
          error_log("get_offer_status: UUID $uuid FAILED per XUMM: $dispatched");
          $wpdb->update($offers_table, ['status' => 'failed'], ['uuid' => $uuid]);
          $row['status'] = 'failed';
          $row['error'] = "Transaction failed: $dispatched";
          
        } else {
          // Signed but waiting for on-chain confirmation
          error_log("get_offer_status: UUID $uuid signed, awaiting on-chain confirmation");
          $row['status'] = 'signed_pending';
        }
        
      } elseif (isset($meta['resolved']) && $meta['resolved'] === true && empty($meta['signed'])) {
        // User rejected the transaction
        error_log("get_offer_status: UUID $uuid was REJECTED by user");
        $wpdb->update($offers_table, ['status' => 'rejected'], ['uuid' => $uuid]);
        $row['status'] = 'rejected';
        
      } elseif (isset($meta['expired']) && $meta['expired'] === true) {
        // Payload expired
        error_log("get_offer_status: UUID $uuid EXPIRED");
        $wpdb->update($offers_table, ['status' => 'expired'], ['uuid' => $uuid]);
        $row['status'] = 'expired';
      }
      // else: still pending (not signed yet), no change
    } else {
      error_log("get_offer_status: XUMM API error for $uuid: " . $xumm_res->get_error_message());
    }
  }
  
  wp_send_json(['success' => true] + $row);
  exit;
}

/** GET: get_listings (v94 - browse active GTC sell offers) */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['action'] ?? '') === 'get_listings')) {
    $nft_id = sanitize_text_field($_GET['nft_id'] ?? '');
    $issuer = sanitize_text_field($_GET['issuer'] ?? '');
    $seller = sanitize_text_field($_GET['seller'] ?? '');
    $limit  = max(1, min(100, intval($_GET['limit'] ?? 50)));
    $offset = max(0, intval($_GET['offset'] ?? 0));
    
    $sort = sanitize_text_field($_GET['sort'] ?? 'newest');
    
    // v94: All listings are GTC now
    $where = "offer_type='sell' AND status='active'";
    $params = [];
    
    if ($nft_id && preg_match('/^[0-9A-Fa-f]{64}$/', $nft_id)) {
        $where .= " AND target_nft_id=%s";
        $params[] = $nft_id;
    }
    
    if ($seller && preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $seller)) {
        $where .= " AND offerer_account=%s";
        $params[] = $seller;
    }
    
    // Sorting options
    $order_by = "created_at DESC";
    switch ($sort) {
        case 'price_low':
            $order_by = "amount ASC, created_at DESC";
            break;
        case 'price_high':
            $order_by = "amount DESC, created_at DESC";
            break;
        case 'newest':
        default:
            $order_by = "created_at DESC";
    }
    
    $sql = "SELECT * FROM $offers_table WHERE $where ORDER BY $order_by LIMIT %d OFFSET %d";
    $params[] = $limit;
    $params[] = $offset;
    
    $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: [];
    
    // Simplified listing response (all GTC)
    $listings = array_map(function($row) {
        return [
            'id'              => (int)$row['id'],
            'offer_id'        => $row['offer_id'],
            'nft_id'          => $row['target_nft_id'],
            'seller'          => $row['offerer_account'],
            'amount'          => (float)$row['amount'],
            'currency'        => $row['currency'],
            'platform_fee'    => (float)$row['platform_fee'],
            'royalty_amount'  => (float)$row['royalty_amount'],
            'royalty_percent' => (float)$row['royalty_percent'],
            'net_amount'      => (float)$row['net_amount'],
            'created_at'      => $row['created_at']
        ];
    }, $rows);
    
    // Get total count
    $count_params = array_slice($params, 0, -2);
    if (empty($count_params)) {
        $total = $wpdb->get_var("SELECT COUNT(*) FROM $offers_table WHERE offer_type='sell' AND status='active'");
    } else {
        $count_where = $where;
        $total = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $offers_table WHERE $count_where", ...$count_params));
    }
    
    wp_send_json([
        'success'  => true, 
        'listings' => $listings,
        'total'    => (int)$total,
        'limit'    => $limit,
        'offset'   => $offset
    ]);
    exit;
}


/** =============================================================================
 *  POST ENDPOINTS
 *  ============================================================================= */

/** POST: create_offer (buy offer) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'create_offer')) {
  global $PLATFORM_FEE_PERCENT;
  
  $nonce         = sanitize_text_field($_POST['nonce'] ?? '');
  $account       = sanitize_text_field($_POST['account'] ?? '');
  $target_nft_id = sanitize_text_field($_POST['target_nft_id'] ?? '');
  $currency      = strtoupper(sanitize_text_field($_POST['currency'] ?? 'XRP'));
  $amount_raw    = trim((string)($_POST['amount'] ?? ''));
  $fee_currency  = strtoupper(sanitize_text_field($_POST['fee_currency'] ?? 'XRP'));
  // v86: Get issuer from request for multi-currency support
  $issuer        = sanitize_text_field($_POST['issuer'] ?? '');

  if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
    status_header(400);
    wp_send_json(['success' => false, 'error' => 'Invalid nonce']);
  }
  if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
    status_header(400);
    wp_send_json(['success' => false, 'error' => 'Invalid account']);
  }
  if (!preg_match('/^[0-9A-Fa-f]{64}$/', $target_nft_id)) {
    status_header(400);
    wp_send_json(['success' => false, 'error' => 'Invalid NFTokenID']);
  }
  
  // Ensure NFT ID is uppercase (XRPL standard)
  $target_nft_id = strtoupper($target_nft_id);
  
  // v86: For IOU tokens, require issuer
  if ($currency !== 'XRP' && !$issuer) {
    // Try to look up from Token Manager
    if (function_exists('imc_get_token_by_ticker')) {
      $token_info = imc_get_token_by_ticker($currency);
      if ($token_info && !empty($token_info['issuer'])) {
        $issuer = $token_info['issuer'];
      }
    }
    // Fallback for known tokens
    if (!$issuer) {
      $known_issuers = [
        'XFT' => 'rGpnoqYLzWytxwQhhz715nRbqyCHM7zhxt',
        'RLUSD' => 'rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De',
        'SOLO' => 'rsoLo2S1kiGeCcn6hCUXVrCpGMWLrRrLZz',
        'SCHMECKLES' => 'rPxw83ZP6thv7KmG5DpAW4cDW55DZRZ9wu',
        'XMEME' => 'r4UPddYeGeZgDhSGPkooURsQtmGda4oYQW'
      ];
      $issuer = $known_issuers[$currency] ?? '';
    }
    if (!$issuer) {
      status_header(400);
      wp_send_json(['success' => false, 'error' => 'Missing issuer for token: ' . $currency]);
    }
  }

  // v190: Ensure fee wallet has trustline for this currency
  if ($fee_currency !== 'XRP' && !empty($issuer)) {
      $tl_result = ensure_fee_wallet_trustline($fee_currency, $issuer);
      if (!$tl_result['success']) {
          error_log("offer-handler: Fee wallet trustline warning for $fee_currency: " . ($tl_result['error'] ?? ''));
          // Non-blocking: offer proceeds — trustline retried at settlement
      }
  }

  $ni = nft_info_compact($target_nft_id);
  
  if (!$ni || empty($ni['owner'])) {
    error_log("create_offer: Unable to verify NFT $target_nft_id on-ledger");
    wp_send_json(['success' => false, 'error' => 'Unable to verify NFT on-ledger. Please try again.']);
  }
  
  $owner = $ni['owner'];
  error_log("create_offer: NFT $target_nft_id owner is $owner, offerer is $account");
  
  // Check if offerer is trying to buy their own NFT
  if (strtolower($owner) === strtolower($account)) {
    error_log("create_offer: User $account trying to make offer on their own NFT $target_nft_id");
    wp_send_json(['success' => false, 'error' => 'You cannot make an offer on your own NFT']);
  }
  
  $flags = $ni['flags'] ?? 0;
  $onlyXrp = false;
  if (is_array($flags)) {
    $onlyXrp = !empty($flags['lsfOnlyXRP']);
  } else {
    $onlyXrp = ((int)$flags & 0x00000002) !== 0;
  }
  if ($onlyXrp && $currency !== 'XRP') {
    wp_send_json(['success' => false, 'error' => 'This NFT only accepts XRP offers']);
  }

  $royalty_percent = isset($ni['transfer_fee']) ? ((float)$ni['transfer_fee'] / 1000.0) : 0.0;
  $royalty_wallet  = $ni['issuer'] ?? '';

  // v653b: Pre-sign trustline gates for token buy offers (parity with the sell path).
  // (1) Issuer (per-NFT, global) must hold the token trustline for a royalty NFT, or the
  //     ledger rejects the NFTokenCreateOffer with tecNO_LINE. (2) The current owner — the
  //     party who receives proceeds when they accept — must be able to receive the token.
  // The buyer's own trustline is validated further below.
  if ($currency !== 'XRP') {
    if ($royalty_percent > 0 && !empty($royalty_wallet) && !has_active_trustline($royalty_wallet, $currency, $issuer)) {
      wp_send_json(['success' => false, 'error' => 'NFT Issuer Needs This Trustline First',
        'issuer_trustline_required' => true, 'nft_issuer' => $royalty_wallet, 'currency' => $currency]);
    }
    if (!empty($owner) && !has_active_trustline($owner, $currency, $issuer)) {
      wp_send_json(['success' => false, 'error' => 'Current owner cannot receive ' . $currency . ' yet — they need a trustline first',
        'seller_trustline_required' => true, 'currency' => $currency]);
    }
  }

  // v656 (Phase 4): Brokered buy gate — mirrors the sell path. OFF by default; true
  // only for pilot-allowlisted issuers with the pilot flag on. Direct buy flow otherwise.
  $is_brokered = imc_broker_should_broker($royalty_wallet);

  // Amount parsing
  if ($currency === 'XRP') {
    $drops = xrp_decimal_to_drops($amount_raw);
    if ($drops === null) {
      if (preg_match('/^\d+$/', $amount_raw) && (int)$amount_raw > 1000000 && (int)$amount_raw < 100000000000000) {
        $drops = $amount_raw;
        error_log("create_offer: Treating amount as drops: $amount_raw");
      } else {
        wp_send_json(['success' => false, 'error' => 'Invalid XRP amount (max 6 decimals)']);
      }
    }
    if ($drops === '0') {
      wp_send_json(['success' => false, 'error' => 'Amount must be > 0']);
    }
    $offer_amount = $drops;
    if (function_exists('bcdiv')) {
      $amount_num = bcdiv($drops, '1000000', 6);
    } else {
      $len = strlen($drops);
      $whole = $len > 6 ? substr($drops, 0, $len - 6) : '0';
      $frac  = str_pad(substr($drops, -6), 6, '0', STR_PAD_LEFT);
      $whole = ltrim($whole, '0'); if ($whole === '') $whole = '0';
      $amount_num = $whole . '.' . $frac;
    }
  } else {
    if (!preg_match('/^\d+(\.\d{1,16})?$/', $amount_raw)) {
      wp_send_json(['success' => false, 'error' => 'Invalid IOU amount format']);
    }
    if ((float)$amount_raw <= 0.0) {
      wp_send_json(['success' => false, 'error' => 'Amount must be > 0']);
    }
    // v86: Check trustline for the specific token
    if (!has_active_trustline($account, $currency, $issuer)) {
      // v717 (G3A): carry a structured flag + the resolved issuer so the client can offer a
      // Set Trustline / Buy action instead of a dead-end toast. This is the BUYER'S OWN
      // trustline, so it IS fixable by them -- unlike the issuer-royalty gate above, which
      // is not. Additive only: nothing consumes these fields until G3B ships, so the
      // rejection itself is byte-for-byte unchanged in behaviour.
      wp_send_json(['success' => false, 'error' => "Buyer lacks active $currency trustline",
        'buyer_trustline_required' => true, 'currency' => $currency, 'issuer' => $issuer]);
    }
    // v86: Use dynamic currency and issuer
    $offer_amount = ['currency' => currency_to_hex($currency), 'issuer' => $issuer, 'value' => $amount_raw];
    $amount_num   = number_format((float)$amount_raw, 16, '.', '');
  }

  // v193: Enforce $5,000 USD maximum — compliance + volatility protection
  $max_usd = defined('IMC_MAX_PRICE_USD') ? IMC_MAX_PRICE_USD : 5000;
  $usd_equiv = null;
  if ($currency === 'XRP') {
    $xrp_usd = class_exists('IMC_PriceOracle') ? IMC_PriceOracle::get_xrp_usd_price() : null;
    if ($xrp_usd && $xrp_usd > 0) {
      $usd_equiv = (float)$amount_num * $xrp_usd;
    }
  } elseif ($currency === 'RLUSD') {
    $usd_equiv = (float)$amount_num; // 1:1 USD peg
  }
  if ($usd_equiv !== null && $usd_equiv > $max_usd) {
    wp_send_json(['success' => false, 'error' => 'Maximum offer value is $' . number_format($max_usd) . ' USD (current: ~$' . number_format($usd_equiv, 2) . ')']);
  }

  $memoJson = 'Buy Offer via IMCollectibles.io';
  if (strlen($memoJson) > 1024) {
    wp_send_json(['success' => false, 'error' => 'Memo too large']);
  }

  $txjson = [
    'TransactionType' => 'NFTokenCreateOffer',
    'Account'         => $account,
    'Owner'           => $owner,
    'NFTokenID'       => $target_nft_id,
    'Amount'          => $offer_amount,
    'SourceTag'       => 2606240013,
    'Memos' => [[ 'Memo' => [
      'MemoType' => bin2hex('IMCollectibles'),
      'MemoData' => bin2hex($memoJson)
    ]]]
  ];

  // v656 (Phase 4): broker-lock the buy offer to the fee wallet so it can only be
  // matched by the broker — both offers broker-destined = fee non-bypassable.
  if ($is_brokered) {
    $txjson['Destination'] = IMC_BROKER_FEE_WALLET;
  }

  // --- Joey (WalletConnect) branch -- v558, additive. Buy offer; hand back txjson for local
  // signing. joey_verify_create_buy_offer writes the row AFTER on-chain confirmation (no orphan rows).
  // v686: the SERVER decides the wallet. The client flag depends on an async boot
  // (bundle load -> 150ms poll -> reconnect round-trip); a Joey user who acted before it
  // finished silently received a XUMM payload for a wallet they don't use -- and the
  // account-binding guard, which only runs when the client flag is set, was skipped with
  // it. The xrpl_wallet_type cookie is set at login and available on the first request,
  // so it is authoritative. Xaman users are unaffected (their cookie never says joey).
  if (($_POST['wallet'] ?? '') === 'joey' || ($_COOKIE['xrpl_wallet_type'] ?? '') === 'joey') {
    wp_send_json(['success' => true, 'wallet' => 'joey', 'txjson' => $txjson]);
    exit;
  }

  $payload = create_xumm_payload_with_webhook_local($txjson, [
    'type'   => 'nft_offer_create',
    'nft_id' => $target_nft_id
  ]);
  if (isset($payload['error'])) {
    wp_send_json(['success' => false, 'error' => $payload['error']]);
  }

  // Phase 2: Calculate fees including platform fee
  $fees = calculate_fees($amount_num, $royalty_percent, $PLATFORM_FEE_PERCENT);

  $ins = $wpdb->insert($offers_table, [
    'offer_id'        => '',
    'uuid'            => $payload['uuid'],
    'offerer_account' => $account,
    'target_account'  => $owner,
    'target_nft_id'   => $target_nft_id,
    'offer_type'      => 'buy',
    'broker_mode'     => $is_brokered ? 1 : 0,
    'amount'          => $amount_num,
    'currency'        => $currency,
    'net_amount'      => $fees['net_amount'],
    'platform_fee'    => $fees['platform_fee'],
    'fee_currency'    => $fee_currency,
    'royalty_amount'  => $fees['royalty_amount'],
    'royalty_wallet'  => $royalty_wallet,
    'royalty_percent' => $royalty_percent,
    'status'          => 'pending',
    'created_at'      => current_time('mysql')
  ], [
    '%s','%s','%s','%s','%s',
    '%s',
    '%s','%s','%s','%s',
    '%s','%s','%s','%s',
    '%s','%s'
  ]);
  if ($ins === false) {
    error_log("create_offer: Failed to insert offer for NFT $target_nft_id by $account: " . $wpdb->last_error);
    status_header(500);
    wp_send_json(['success' => false, 'error' => 'Failed to store offer']);
  }
  error_log("create_offer: Created offer for NFT $target_nft_id by $account");

  wp_send_json([
    'success'      => true,
    'offer_id'     => '',
    'payload_uuid' => $payload['uuid'],
    'qr'           => $payload['refs']['qr_png'] ?? '',
    'deeplink'     => $payload['next']['always'] ?? '',
    'platform_fee' => (float)$fees['platform_fee'],
    'royalty'      => (float)$fees['royalty_amount'],
    'net_amount'   => (float)$fees['net_amount']
  ]);
  exit;
}


/** POST: create_sell_offer (v94 - GTC Only) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'create_sell_offer')) {
    global $PLATFORM_FEE_PERCENT, $IOU_ISSUER;
    
    $nonce        = sanitize_text_field($_POST['nonce'] ?? '');
    $account      = sanitize_text_field($_POST['account'] ?? '');
    $nft_id       = sanitize_text_field($_POST['nft_id'] ?? '');
    $amount_raw   = sanitize_text_field($_POST['amount'] ?? '');
    $currency     = strtoupper(sanitize_text_field($_POST['currency'] ?? 'XRP'));
    $issuer       = sanitize_text_field($_POST['issuer'] ?? '');
    
    error_log("create_sell_offer: account=$account, nft_id=$nft_id, amount=$amount_raw, currency=$currency");
    
    // Validate nonce
    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        error_log("create_sell_offer: Invalid nonce");
        status_header(403);
        wp_send_json(['success' => false, 'error' => 'Invalid nonce']);
    }
    
    // Validate account
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        error_log("create_sell_offer: Invalid account $account");
        status_header(400);
        wp_send_json(['success' => false, 'error' => 'Invalid XRPL account']);
    }
    
    // Validate NFT ID
    if (!preg_match('/^[A-Fa-f0-9]{64}$/', $nft_id)) {
        error_log("create_sell_offer: Invalid NFT ID $nft_id");
        status_header(400);
        wp_send_json(['success' => false, 'error' => 'Invalid NFT ID']);
    }
    
    // For IOU tokens, require issuer - look up from Token Manager or fallback
    if ($currency !== 'XRP' && !$issuer) {
        if (function_exists('imc_get_token_by_ticker')) {
            $token_info = imc_get_token_by_ticker($currency);
            if ($token_info && !empty($token_info['issuer'])) {
                $issuer = $token_info['issuer'];
            }
        }
        // Fallback for known tokens
        if (!$issuer) {
            $known_issuers = [
                'XFT' => 'rGpnoqYLzWytxwQhhz715nRbqyCHM7zhxt',
                'RLUSD' => 'rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De',
                'SOLO' => 'rsoLo2S1kiGeCcn6hCUXVrCpGMWLrRrLZz',
                'SCHMECKLES' => 'rPxw83ZP6thv7KmG5DpAW4cDW55DZRZ9wu',
                'XMEME' => 'r4UPddYeGeZgDhSGPkooURsQtmGda4oYQW'
            ];
            $issuer = $known_issuers[$currency] ?? '';
        }
        if (!$issuer) {
            status_header(400);
            wp_send_json(['success' => false, 'error' => 'Missing issuer for token: ' . $currency]);
        }
    }
    
    // v191: Ensure fee wallet has trustline for sell offer currency
    if ($currency !== 'XRP' && !empty($issuer)) {
        $tl_result = ensure_fee_wallet_trustline($currency, $issuer);
        if (!$tl_result['success']) {
            error_log("offer-handler: Fee wallet trustline warning for sell offer $currency: " . ($tl_result['error'] ?? ''));
            // Non-blocking: sell offer proceeds — trustline retried at settlement
        }
    }
    
    // Verify seller owns the NFT
    $ni = nft_info_compact($nft_id);
    if (!$ni) {
        error_log("create_sell_offer: NFT not found $nft_id");
        status_header(404);
        wp_send_json(['success' => false, 'error' => 'NFT not found on ledger']);
    }
    if ($ni['owner'] !== $account) {
        error_log("create_sell_offer: Not owner - NFT owned by {$ni['owner']}, request from $account");
        status_header(403);
        wp_send_json(['success' => false, 'error' => 'You do not own this NFT']);
    }
    
    // Check onlyXRP flag on NFT
    $flags = $ni['flags'] ?? 0;
    $onlyXrp = false;
    if (is_array($flags)) {
        $onlyXrp = !empty($flags['lsfOnlyXRP']);
    } else {
        $onlyXrp = ((int)$flags & 0x00000002) !== 0;
    }
    if ($onlyXrp && $currency !== 'XRP') {
        wp_send_json(['success' => false, 'error' => 'This NFT only accepts XRP offers']);
    }
    
    // v197: Clean up stale pending sell offers before checking duplicates.
    // GTC listings have expires_at=NULL so the normal expiry cleanup never catches them.
    // If user created a listing but never signed (or rejected in Xaman), the pending
    // record blocks all future attempts forever. Auto-expire after 10 minutes.
    $wpdb->query($wpdb->prepare(
        "UPDATE $offers_table SET status='expired' 
         WHERE target_nft_id=%s AND offerer_account=%s AND offer_type='sell' 
         AND status='pending' AND created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)",
        $nft_id, $account
    ));
    
    // Check if already listed (active = confirmed on-chain, recent pending = signing in progress)
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $offers_table 
         WHERE target_nft_id=%s AND offerer_account=%s AND offer_type='sell' AND status IN ('pending','active')",
        $nft_id, $account
    ));
    if ($existing > 0) {
        status_header(409);
        wp_send_json(['success' => false, 'error' => 'NFT is already listed for sale']);
    }
    
    // Get royalty info from NFT
    $royalty_percent = isset($ni['transfer_fee']) ? ((float)$ni['transfer_fee'] / 1000.0) : 0.0;
    $royalty_wallet = $ni['issuer'] ?? '';

    // v653: Pre-sign issuer-trustline gate. For a royalty NFT listed in a token, the
    // NFT's OWN issuer must already hold that token's trustline, or the ledger rejects
    // the NFTokenCreateOffer with tecNO_LINE. We check the actual per-NFT issuer
    // ($royalty_wallet, resolved live from the ledger) — never a single hardcoded
    // wallet — BEFORE building the Xaman payload, so the user gets a clean message
    // instead of a failed signature. has_active_trustline is now hex-normalised (v652),
    // so this is correct for 4+ char tokens too.
    if ($currency !== 'XRP' && $royalty_percent > 0 && !empty($royalty_wallet)) {
        if (!has_active_trustline($royalty_wallet, $currency, $issuer)) {
            error_log("create_sell_offer: issuer $royalty_wallet lacks $currency trustline — blocked pre-sign");
            wp_send_json([
                'success' => false,
                'error'   => 'NFT Issuer Needs This Trustline First',
                'issuer_trustline_required' => true,
                'nft_issuer' => $royalty_wallet,
                'currency'   => $currency
            ]);
        }
    }
    
    // v653b: Seller (lister) must hold the sale token's trustline to RECEIVE proceeds
    // when the NFT sells. Checked before signing so the lister isn't left with a
    // listing that can never settle. (XRP needs no trustline.)
    if ($currency !== 'XRP' && !has_active_trustline($account, $currency, $issuer)) {
        wp_send_json([
            'success' => false,
            'error'   => 'You Need This Trustline First',
            'seller_trustline_required' => true,
            'currency' => $currency
        ]);
    }

    // v655 (Phase 3): Brokered listing gate. OFF by default; true only for
    // pilot-allowlisted issuers with the pilot flag on. Direct flow otherwise.
    $is_brokered = imc_broker_should_broker($royalty_wallet);

    // Build amount for XRPL transaction
    if ($currency === 'XRP') {
        if (!preg_match('/^\d+(\.\d{1,6})?$/', $amount_raw)) {
            wp_send_json(['success' => false, 'error' => 'Invalid XRP amount format']);
        }
        $xrp_float = (float)$amount_raw;
        if ($xrp_float < 0.000001) {
            wp_send_json(['success' => false, 'error' => 'Amount must be at least 0.000001 XRP']);
        }
        $drops = bcmul($amount_raw, '1000000', 0);
        $offer_amount = $drops;
        $amount_num = $amount_raw;
    } else {
        if (!preg_match('/^\d+(\.\d{1,16})?$/', $amount_raw)) {
            wp_send_json(['success' => false, 'error' => 'Invalid token amount format']);
        }
        if ((float)$amount_raw <= 0.0) {
            wp_send_json(['success' => false, 'error' => 'Amount must be > 0']);
        }
        $offer_amount = ['currency' => currency_to_hex($currency), 'issuer' => $issuer, 'value' => $amount_raw];
        $amount_num = $amount_raw;
    }
    
    // v193: Enforce $5,000 USD maximum — compliance + volatility protection
    $max_usd = defined('IMC_MAX_PRICE_USD') ? IMC_MAX_PRICE_USD : 5000;
    $usd_equiv = null;
    if ($currency === 'XRP') {
        $xrp_usd = class_exists('IMC_PriceOracle') ? IMC_PriceOracle::get_xrp_usd_price() : null;
        if ($xrp_usd && $xrp_usd > 0) {
            $usd_equiv = (float)$amount_num * $xrp_usd;
        }
    } elseif ($currency === 'RLUSD') {
        $usd_equiv = (float)$amount_num; // 1:1 USD peg
    }
    if ($usd_equiv !== null && $usd_equiv > $max_usd) {
        wp_send_json(['success' => false, 'error' => 'Maximum listing value is $' . number_format($max_usd) . ' USD (current: ~$' . number_format($usd_equiv, 2) . ')']);
    }

    // Calculate fees
    $fees = calculate_fees($amount_num, $royalty_percent, $PLATFORM_FEE_PERCENT);
    
    // v655 (Phase 3): brokered listings apply the 2% secondary fee and set the sell
    // offer to the seller's NET (deducted model) so the broker fee fits inside the
    // buy/sell spread. Direct listings keep their existing amount + fee untouched.
    if ($is_brokered) {
        $b_fee = round((float)$amount_num * (IMC_BROKER_FEE_PERCENT / 100.0), ($currency === 'XRP') ? 6 : 15);
        $b_net = (float)$amount_num - $b_fee - (float)$fees['royalty_amount'];
        if ($b_net <= 0) {
            wp_send_json(['success' => false, 'error' => 'Price too low to cover the 2% fee and royalty']);
        }
        $fees['platform_fee'] = $b_fee;
        $fees['net_amount']   = $b_net;
        $b_net_str = rtrim(rtrim(sprintf('%.15f', $b_net), '0'), '.');
        if ($currency === 'XRP') {
            $offer_amount = bcmul($b_net_str, '1000000', 0);
        } else {
            $offer_amount = ['currency' => currency_to_hex($currency), 'issuer' => $issuer, 'value' => $b_net_str];
        }
    }

    // v94: GTC - NO Expiration field (offer stays until cancelled or accepted)
    $memoJson = 'Sell Offer via IMCollectibles.io';
    
    $txjson = [
        'TransactionType' => 'NFTokenCreateOffer',
        'Account'         => $account,
        'NFTokenID'       => $nft_id,
        'Amount'          => $offer_amount,
        'Flags'           => 1, // tfSellNFToken
        'SourceTag'       => 2606240013,
        'Memos'           => [['Memo' => [
            'MemoType' => bin2hex('IMCollectibles'),
            'MemoData' => bin2hex($memoJson)
        ]]]
    ];
    // v655 (Phase 3): broker-lock the sell offer so ONLY the fee wallet can accept it —
    // this makes the 2% fee non-bypassable and enables brokered mode.
    if ($is_brokered) {
        $txjson['Destination'] = IMC_BROKER_FEE_WALLET;
    }

    // Note: NO Expiration field - GTC offer stays on ledger until cancelled or accepted
    
    // --- Joey (WalletConnect) branch -- v544, additive --------------------
    // A live Joey session makes the front-end POST wallet=joey. Every guard
    // above (nonce, ownership, already-listed, amount format, $5k cap) has
    // already run, so $txjson is fully vetted here. Hand it back for local
    // signing. We deliberately do NOT create a Xaman payload or a DB row in
    // this branch -- joey_verify_create_offer writes the authoritative row
    // AFTER the signed tx is confirmed on-chain (no orphan pending rows).
    if (($_POST['wallet'] ?? '') === 'joey' || ($_COOKIE['xrpl_wallet_type'] ?? '') === 'joey') {
        wp_send_json([
            'success'      => true,
            'wallet'       => 'joey',
            'txjson'       => $txjson,
            'platform_fee' => (float)$fees['platform_fee'],
            'royalty'      => (float)$fees['royalty_amount'],
            'net_amount'   => (float)$fees['net_amount']
        ]);
        exit;
    }

    // Create XUMM payload
    $payload = create_xumm_payload_with_webhook_local($txjson, [
        'type'   => 'nft_sell_offer_create',
        'nft_id' => $nft_id
    ]);
    
    if (isset($payload['error'])) {
        error_log("create_sell_offer: XUMM payload error: " . $payload['error']);
        wp_send_json(['success' => false, 'error' => $payload['error']]);
    }
    
    // Store in database
    $ins = $wpdb->insert($offers_table, [
        'offer_id'        => '',
        'uuid'            => $payload['uuid'],
        'offerer_account' => $account,
        'target_account'  => $account,
        'target_nft_id'   => $nft_id,
        'offer_type'      => 'sell',
        'listing_mode'    => 'gtc',
        'broker_mode'     => $is_brokered ? 1 : 0,
        'amount'          => $amount_num,
        'currency'        => $currency,
        'net_amount'      => $fees['net_amount'],
        'platform_fee'    => $fees['platform_fee'],
        'fee_currency'    => $currency,
        'royalty_amount'  => $fees['royalty_amount'],
        'royalty_wallet'  => $royalty_wallet,
        'royalty_percent' => $royalty_percent,
        'status'          => 'pending',
        'created_at'      => current_time('mysql'),
        'expires_at'      => null  // GTC - no expiration
    ]);
    
    if ($ins === false) {
        error_log("create_sell_offer: DB insert failed: " . $wpdb->last_error);
        status_header(500);
        wp_send_json(['success' => false, 'error' => 'Failed to store listing']);
    }
    
    error_log("create_sell_offer: Created GTC listing for NFT $nft_id by $account");
    
    wp_send_json([
        'success'      => true,
        'offer_id'     => '',
        'payload_uuid' => $payload['uuid'],
        'qr'           => $payload['refs']['qr_png'] ?? '',
        'deeplink'     => $payload['next']['always'] ?? '',
        'platform_fee' => (float)$fees['platform_fee'],
        'royalty'      => (float)$fees['royalty_amount'],
        'net_amount'   => (float)$fees['net_amount']
    ]);
    exit;
}


/** POST: joey_verify_create_offer (v544 -- Joey/WalletConnect sell-offer confirm)
 *  Joey signs locally and returns a tx_hash (no Xaman uuid). This verifies the
 *  signed NFTokenCreateOffer on-chain, extracts the offer_id, and writes the
 *  authoritative offers row -- the same outcome poll_xumm_payload produces for
 *  the Xaman flow, in one synchronous step. ALL money fields are re-derived from
 *  the ON-CHAIN tx; nothing financial is trusted from the client. Brand-new
 *  action: touches no existing dispatch, runs only on action=joey_verify_create_offer.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'joey_verify_create_offer')) {
    $nonce   = sanitize_text_field($_POST['nonce']   ?? '');
    $tx_hash = sanitize_text_field($_POST['tx_hash'] ?? '');

    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        status_header(403);
        wp_send_json(['success' => false, 'error' => 'Invalid nonce']);
    }
    if (strlen($tx_hash) < 40 || !preg_match('/^[0-9A-Fa-f]+$/', $tx_hash)) {
        wp_send_json(['success' => false, 'error' => 'Invalid transaction hash']);
    }

    // Verify on-chain (validated + tesSUCCESS), polling briefly for ledger close.
    $tx = null;
    for ($i = 0; $i < 5; $i++) {
        $tx = xrpl_rpc('tx', ['transaction' => $tx_hash, 'binary' => false]);
        if (!empty($tx['result']) && !empty($tx['result']['validated'])) break;
        sleep(2);
    }
    if (empty($tx['result'])) {
        wp_send_json(['success' => false, 'error' => 'Transaction not found on ledger']);
    }
    $r = $tx['result'];
    if (empty($r['validated'])) {
        wp_send_json(['success' => false, 'error' => 'Transaction not yet validated -- please retry']);
    }
    if (($r['meta']['TransactionResult'] ?? '') !== 'tesSUCCESS') {
        wp_send_json(['success' => false, 'error' => 'Transaction did not succeed on-chain: ' . ($r['meta']['TransactionResult'] ?? 'unknown')]);
    }
    if (($r['TransactionType'] ?? '') !== 'NFTokenCreateOffer') {
        wp_send_json(['success' => false, 'error' => 'Unexpected transaction type']);
    }
    if (((int)($r['Flags'] ?? 0) & 1) !== 1) {
        wp_send_json(['success' => false, 'error' => 'Transaction is not a sell offer']);
    }

    $account = sanitize_text_field($r['Account']   ?? '');
    $nft_id  = sanitize_text_field($r['NFTokenID'] ?? '');
    if (!$account || !$nft_id) {
        wp_send_json(['success' => false, 'error' => 'Confirmed tx missing account or NFT id']);
    }

    $offer_id = extract_offer_id_from_meta($tx);
    if (!$offer_id) {
        wp_send_json(['success' => false, 'error' => 'Could not read offer id from confirmed transaction']);
    }

    // Idempotent: never double-insert the same on-chain offer.
    $already = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $offers_table WHERE offer_id = %s", $offer_id
    ));
    if ($already > 0) {
        wp_send_json(['success' => true, 'offer_id' => $offer_id, 'already' => true]);
    }

    // Re-derive currency + amount from the ON-CHAIN Amount (authoritative).
    $amt = $r['Amount'];
    if (is_array($amt)) {
        $currency   = $amt['currency'] ?? '';
        $amount_num = (string)($amt['value'] ?? '0');
    } else {
        $currency   = 'XRP';
        $amount_num = bcdiv((string)$amt, '1000000', 6);
    }

    // Royalty from the NFT -- same source as create_sell_offer.
    $ni = nft_info_compact($nft_id);
    $royalty_percent = isset($ni['transfer_fee']) ? ((float)$ni['transfer_fee'] / 1000.0) : 0.0;
    $royalty_wallet  = $ni['issuer'] ?? '';
    $fees = calculate_fees($amount_num, $royalty_percent, $PLATFORM_FEE_PERCENT);

    // Authoritative row -- identical columns to the Xaman insert, already 'active'.
    $ins = $wpdb->insert($offers_table, [
        'offer_id'        => $offer_id,
        'uuid'            => 'joey:' . $tx_hash,
        'offerer_account' => $account,
        'target_account'  => $account,
        'target_nft_id'   => $nft_id,
        'offer_type'      => 'sell',
        'listing_mode'    => 'gtc',
        'broker_mode'     => ((($r['Destination'] ?? '') === IMC_BROKER_FEE_WALLET) ? 1 : 0),
        'amount'          => $amount_num,
        'currency'        => $currency,
        'net_amount'      => $fees['net_amount'],
        'platform_fee'    => $fees['platform_fee'],
        'fee_currency'    => $currency,
        'royalty_amount'  => $fees['royalty_amount'],
        'royalty_wallet'  => $royalty_wallet,
        'royalty_percent' => $royalty_percent,
        'status'          => 'active',
        'created_at'      => current_time('mysql'),
        'expires_at'      => null
    ]);
    if ($ins === false) {
        // v560: the XRPL listener can insert this same on-chain offer in the race window
        // between the dedupe check above and this insert. A row that now exists means the
        // listing IS stored -- return success rather than a spurious failure (the symptom
        // was an occasional 'Failed to store listing' that a manual refresh resolved).
        $exists = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $offers_table WHERE offer_id = %s", $offer_id
        ));
        if ($exists > 0) {
            wp_send_json(['success' => true, 'offer_id' => $offer_id, 'already' => true]);
        }
        error_log("joey_verify_create_offer: DB insert failed: " . $wpdb->last_error);
        status_header(500);
        wp_send_json(['success' => false, 'error' => 'Failed to store listing']);
    }

    error_log("joey_verify_create_offer: listing ACTIVE offer_id=$offer_id nft=$nft_id by $account tx=$tx_hash");
    wp_send_json(['success' => true, 'offer_id' => $offer_id, 'tx_hash' => $tx_hash]);
    exit;
}




/** =====================================================================
 *  SESSION-AUTH 2a-c (Aug 2026) -- JOEY ON-LEDGER LOGIN PROOF
 *  ---------------------------------------------------------------------
 *  Two additive POST actions, mirroring joey_verify_create_offer exactly:
 *
 *    joey_login_challenge -> issues a single-use server nonce (5-min transient).
 *    joey_login_verify    -> verifies a Joey-signed, SUBMITTED AccountSet that
 *                            carries that nonce in a Memo, then (and only then)
 *                            establishes identity: mints the httponly imc_session
 *                            token AND sets the soft xrpl_account/xrpl_wallet_type
 *                            cookies. PROOF-FIRST -- nothing is set until the
 *                            on-ledger AccountSet is confirmed validated+tesSUCCESS
 *                            with Account === claimed wallet and the issued nonce
 *                            in the memo. Abandon-before-sign => no identity at all.
 *
 *  Kill switch: IMC_JOEY_PROOF_ENABLED (default true, nested under IMC_JOEY_ENABLED).
 *  Touches NOTHING else: brand-new action strings, run only on their own POST.
 *  Xaman flow is entirely separate and unaffected.
 *  ===================================================================== */
if (!defined('IMC_JOEY_PROOF_ENABLED')) {
    define('IMC_JOEY_PROOF_ENABLED', (!defined('IMC_JOEY_ENABLED') || IMC_JOEY_ENABLED));
}

/** POST: joey_login_challenge -- issue a single-use nonce for a Joey login proof. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'joey_login_challenge')) {
    if (!IMC_JOEY_PROOF_ENABLED) {
        wp_send_json(['success' => false, 'error' => 'Joey login proof disabled']);
    }

    $account = sanitize_text_field($_POST['account'] ?? '');
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        wp_send_json(['success' => false, 'error' => 'Invalid account']);
    }

    // 16 random bytes -> 32 hex chars. Single-use, 5-minute lifetime, bound to the
    // claimed wallet at issue. Stored server-side; consumed (deleted) on verify.
    $nonce_hex = bin2hex(random_bytes(16));
    set_transient('imc_joey_nonce_' . hash('sha256', $nonce_hex), $account, 5 * MINUTE_IN_SECONDS);

    wp_send_json(['success' => true, 'nonce' => $nonce_hex]);
    exit;
}

/** POST: joey_login_verify -- verify the Joey-signed AccountSet carrying the nonce,
 *  then establish identity (mint imc_session token + set soft cookies). PROOF-FIRST. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'joey_login_verify')) {
    if (!IMC_JOEY_PROOF_ENABLED) {
        wp_send_json(['success' => false, 'error' => 'Joey login proof disabled']);
    }

    $nonce_hex = sanitize_text_field($_POST['nonce']   ?? '');
    $tx_hash   = sanitize_text_field($_POST['tx_hash'] ?? '');
    $claimed   = sanitize_text_field($_POST['account'] ?? '');

    if (!preg_match('/^[0-9a-fA-F]{32}$/', $nonce_hex)) {
        wp_send_json(['success' => false, 'error' => 'Invalid challenge']);
    }
    if (strlen($tx_hash) < 40 || !preg_match('/^[0-9A-Fa-f]+$/', $tx_hash)) {
        wp_send_json(['success' => false, 'error' => 'Invalid transaction hash']);
    }
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $claimed)) {
        wp_send_json(['success' => false, 'error' => 'Invalid account']);
    }

    // The nonce must be one we issued and have not yet consumed (single-use).
    $nonce_key    = 'imc_joey_nonce_' . hash('sha256', $nonce_hex);
    $nonce_wallet = get_transient($nonce_key);
    if ($nonce_wallet === false) {
        wp_send_json(['success' => false, 'error' => 'Challenge expired or already used -- please try again']);
    }
    // The nonce was issued for the claimed wallet.
    if ($nonce_wallet !== $claimed) {
        wp_send_json(['success' => false, 'error' => 'Challenge does not match account']);
    }

    // Verify on-chain (validated + tesSUCCESS), polling briefly for ledger close --
    // identical shape to joey_verify_create_offer.
    $tx = null;
    for ($i = 0; $i < 5; $i++) {
        $tx = xrpl_rpc('tx', ['transaction' => $tx_hash, 'binary' => false]);
        if (!empty($tx['result']) && !empty($tx['result']['validated'])) break;
        sleep(2);
    }
    if (empty($tx['result'])) {
        wp_send_json(['success' => false, 'error' => 'Transaction not found on ledger']);
    }
    $r = $tx['result'];
    if (empty($r['validated'])) {
        wp_send_json(['success' => false, 'error' => 'Transaction not yet validated -- please retry']);
    }
    if (($r['meta']['TransactionResult'] ?? '') !== 'tesSUCCESS') {
        wp_send_json(['success' => false, 'error' => 'Transaction did not succeed on-chain: ' . ($r['meta']['TransactionResult'] ?? 'unknown')]);
    }
    if (($r['TransactionType'] ?? '') !== 'AccountSet') {
        wp_send_json(['success' => false, 'error' => 'Unexpected transaction type']);
    }

    // The signer of the on-chain tx is the wallet -- only the key-holder can land a
    // tx from that account. It MUST equal the claimed wallet.
    $account = sanitize_text_field($r['Account'] ?? '');
    if (!$account || $account !== $claimed) {
        wp_send_json(['success' => false, 'error' => 'Confirmed tx account does not match']);
    }

    // The Memo must carry the exact nonce we issued. Walk the Memos array, hex-decode
    // each MemoData, and require an exact match.
    $memo_ok = false;
    if (!empty($r['Memos']) && is_array($r['Memos'])) {
        foreach ($r['Memos'] as $m) {
            $md = $m['Memo']['MemoData'] ?? '';
            if (!is_string($md) || $md === '') continue;
            $decoded = @hex2bin($md);
            if ($decoded !== false && strtolower(trim($decoded)) === strtolower($nonce_hex)) {
                $memo_ok = true;
                break;
            }
        }
    }
    if (!$memo_ok) {
        wp_send_json(['success' => false, 'error' => 'Proof memo missing or does not match']);
    }

    // ---- PROOF COMPLETE. Consume the nonce (single-use) and establish identity. ----
    delete_transient($nonce_key);

    // ---- I1-1a: APP BRANCH (IMUP3) --------------------------------------------
    // The proof above is shared, byte-for-byte, with the web path; only what happens
    // AFTER it differs. A native app has no cookie jar, so it needs the session token
    // in the response body and has no use for the soft identity cookies (which exist
    // purely to drive the logged-in state of the WEBSITE UI).
    //
    // This branch exits, so EVERYTHING BELOW IT IS THE WEB PATH, UNCHANGED. Nothing in
    // this file outside these lines is touched, and no offer or payment code is
    // involved: joey_login_verify is a self-contained login handler that happens to
    // live here beside the Joey offer verifiers.
    if (($_POST['app'] ?? '') === '1') {
        $app_token = function_exists('imc_session_mint')
            ? imc_session_mint($account, 'joey', 'imup3', true)
            : false;
        if (!is_string($app_token) || $app_token === '') {
            error_log("joey_login_verify: APP mint FAILED account=$account tx=$tx_hash");
            wp_send_json(['success' => false, 'error' => 'Could not create session']);
        }
        // Log the event, never the token - it is a 30-day bearer for a wallet identity.
        error_log("joey_login_verify: APP LOGIN PROVEN account=$account tx=$tx_hash");
        wp_send_json(['success' => true, 'account' => $account, 'token' => $app_token]);
        exit;
    }
    // ---- end APP BRANCH; the web path continues below, unchanged -----------------

    // 1) Mint the httponly imc_session token (server-bound, tamper-proof). This is the
    //    authoritative session for master access at 2b. Tagged wallet_type='joey'.
    if (function_exists('imc_session_mint')) {
        imc_session_mint($account, 'joey');
    }

    // 2) Set the soft identity cookies for the logged-in UI -- params identical to
    //    joey_ajax_login_connect / xaman-signin-complete.php. Set ONLY here, after proof.
    $opts = [
        'expires'  => time() + (86400 * 30),
        'path'     => '/',
        'domain'   => '.imcollectibles.io',
        'secure'   => true,
        'httponly' => false,
        'samesite' => 'Lax',
    ];
    setcookie('xrpl_account', $account, $opts);
    setcookie('xrpl_wallet_type', 'joey', $opts);

    error_log("joey_login_verify: LOGIN PROVEN account=$account tx=$tx_hash");
    wp_send_json(['success' => true, 'account' => $account]);
    exit;
}


/** POST: joey_verify_create_buy_offer (v558) -- confirm a Joey-signed BUY offer on-chain, then record it. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'joey_verify_create_buy_offer')) {
    $nonce   = sanitize_text_field($_POST['nonce'] ?? '');
    $tx_hash = sanitize_text_field($_POST['tx_hash'] ?? '');
    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        wp_send_json(['success' => false, 'error' => 'Invalid nonce']);
    }
    if (strlen($tx_hash) < 40 || !preg_match('/^[0-9A-Fa-f]+$/', $tx_hash)) {
        wp_send_json(['success' => false, 'error' => 'Invalid transaction hash']);
    }
    // Verify on-chain (validated + tesSUCCESS), polling briefly for ledger close.
    $tx = null;
    for ($i = 0; $i < 5; $i++) {
        $tx = xrpl_rpc('tx', ['transaction' => $tx_hash, 'binary' => false]);
        if (!empty($tx['result']) && !empty($tx['result']['validated'])) break;
        sleep(2);
    }
    if (empty($tx['result'])) {
        wp_send_json(['success' => false, 'error' => 'Transaction not found on ledger']);
    }
    $r = $tx['result'];
    if (empty($r['validated'])) {
        wp_send_json(['success' => false, 'error' => 'Transaction not yet validated -- please retry']);
    }
    if (($r['meta']['TransactionResult'] ?? '') !== 'tesSUCCESS') {
        wp_send_json(['success' => false, 'error' => 'Transaction did not succeed on-chain: ' . ($r['meta']['TransactionResult'] ?? 'unknown')]);
    }
    if (($r['TransactionType'] ?? '') !== 'NFTokenCreateOffer') {
        wp_send_json(['success' => false, 'error' => 'Unexpected transaction type']);
    }
    if (((int)($r['Flags'] ?? 0) & 1) === 1) {
        wp_send_json(['success' => false, 'error' => 'Transaction is not a buy offer']);
    }
    $account = sanitize_text_field($r['Account']   ?? ''); // the bidder
    $owner   = sanitize_text_field($r['Owner']     ?? ''); // the NFT owner
    $nft_id  = sanitize_text_field($r['NFTokenID'] ?? '');
    if (!$account || !$owner || !$nft_id) {
        wp_send_json(['success' => false, 'error' => 'Confirmed tx missing account, owner, or NFT id']);
    }
    $offer_id = extract_offer_id_from_meta($tx);
    if (!$offer_id) {
        wp_send_json(['success' => false, 'error' => 'Could not read offer id from confirmed transaction']);
    }
    $already = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $offers_table WHERE offer_id = %s", $offer_id
    ));
    if ($already > 0) {
        wp_send_json(['success' => true, 'offer_id' => $offer_id, 'already' => true]);
    }
    $amt = $r['Amount'];
    if (is_array($amt)) {
        $currency   = $amt['currency'] ?? '';
        $amount_num = (string)($amt['value'] ?? '0');
    } else {
        $currency   = 'XRP';
        $amount_num = bcdiv((string)$amt, '1000000', 6);
    }
    $ni = nft_info_compact($nft_id);
    $royalty_percent = isset($ni['transfer_fee']) ? ((float)$ni['transfer_fee'] / 1000.0) : 0.0;
    $royalty_wallet  = $ni['issuer'] ?? '';
    $fees = calculate_fees($amount_num, $royalty_percent, $PLATFORM_FEE_PERCENT);
    // Authoritative row -- mirrors the Xaman create_offer buy insert, already 'active'.
    $ins = $wpdb->insert($offers_table, [
        'offer_id'        => $offer_id,
        'uuid'            => 'joey:' . $tx_hash,
        'offerer_account' => $account,
        'target_account'  => $owner,
        'target_nft_id'   => $nft_id,
        'offer_type'      => 'buy',
        'broker_mode'     => ((($r['Destination'] ?? '') === IMC_BROKER_FEE_WALLET) ? 1 : 0),
        'amount'          => $amount_num,
        'currency'        => $currency,
        'net_amount'      => $fees['net_amount'],
        'platform_fee'    => $fees['platform_fee'],
        'fee_currency'    => $currency,
        'royalty_amount'  => $fees['royalty_amount'],
        'royalty_wallet'  => $royalty_wallet,
        'royalty_percent' => $royalty_percent,
        'status'          => 'active',
        'created_at'      => current_time('mysql')
    ]);
    if ($ins === false) {
        // v560: same listener race as the sell verify -- treat an existing on-chain offer as success.
        $exists = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $offers_table WHERE offer_id = %s", $offer_id
        ));
        if ($exists > 0) {
            wp_send_json(['success' => true, 'offer_id' => $offer_id, 'already' => true]);
        }
        error_log("joey_verify_create_buy_offer: DB insert failed: " . $wpdb->last_error);
        status_header(500);
        wp_send_json(['success' => false, 'error' => 'Failed to store offer']);
    }
    error_log("joey_verify_create_buy_offer: BUY offer ACTIVE offer_id=$offer_id nft=$nft_id by $account tx=$tx_hash");
    wp_send_json(['success' => true, 'offer_id' => $offer_id, 'tx_hash' => $tx_hash]);
    exit;
}

/** POST: create_transfer_offer (v250 - Free NFT transfer to a specific recipient) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'create_transfer_offer')) {
    $nonce       = sanitize_text_field($_POST['nonce']       ?? '');
    $account     = sanitize_text_field($_POST['account']     ?? '');
    $nft_id      = sanitize_text_field($_POST['nft_id']      ?? '');
    $destination = sanitize_text_field($_POST['destination'] ?? '');

    error_log("create_transfer_offer: account=$account, nft_id=$nft_id, destination=$destination");

    // Validate nonce
    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        error_log("create_transfer_offer: Invalid nonce");
        status_header(403);
        wp_send_json(['success' => false, 'error' => 'Invalid nonce']);
    }

    // Validate sender account
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        status_header(400);
        wp_send_json(['success' => false, 'error' => 'Invalid sender XRPL account']);
    }

    // Validate destination account
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $destination)) {
        status_header(400);
        wp_send_json(['success' => false, 'error' => 'Invalid recipient XRPL address']);
    }

    // Cannot transfer to self
    if ($account === $destination) {
        status_header(400);
        wp_send_json(['success' => false, 'error' => 'Cannot transfer an NFT to yourself']);
    }

    // Validate NFT ID
    if (!preg_match('/^[A-Fa-f0-9]{64}$/', $nft_id)) {
        status_header(400);
        wp_send_json(['success' => false, 'error' => 'Invalid NFT ID']);
    }

    // Verify sender owns the NFT
    $ni = nft_info_compact($nft_id);
    if (!$ni) {
        status_header(404);
        wp_send_json(['success' => false, 'error' => 'NFT not found on ledger']);
    }
    if ($ni['owner'] !== $account) {
        status_header(403);
        wp_send_json(['success' => false, 'error' => 'You do not own this NFT']);
    }

    // Build XRPL transaction: sell offer for 0 XRP to a specific destination (free transfer)
    // v453: Memo removed — NFTokenID and Destination already convey all transaction detail.
    // The previous memo contained redundant nft/to/market JSON that displayed as a hex blob
    // in Xaman, alarming users who couldn't read it.
    $txjson = [
        'TransactionType' => 'NFTokenCreateOffer',
        'Account'         => $account,
        'NFTokenID'       => $nft_id,
        'Amount'          => '0',          // Free transfer
        'Flags'           => 1,            // tfSellNFToken
        'Destination'     => $destination, // Locks offer to this recipient only
        'SourceTag'       => 2606240013,
    ];

    // --- Joey (WalletConnect) branch -- v561, additive. Hand back the 0-value,
    // destination-locked transfer txjson for local signing; joey_verify_create_transfer_offer
    // records it after on-chain confirmation. Inherits all validation above (ownership, addresses).
    if (($_POST['wallet'] ?? '') === 'joey' || ($_COOKIE['xrpl_wallet_type'] ?? '') === 'joey') {
        wp_send_json(['success' => true, 'wallet' => 'joey', 'txjson' => $txjson]);
        exit;
    }

    // Create XUMM payload
    $payload = create_xumm_payload_with_webhook_local($txjson, [
        'type'        => 'nft_transfer_offer',
        'nft_id'      => $nft_id,
        'destination' => $destination
    ]);

    if (isset($payload['error'])) {
        error_log("create_transfer_offer: XUMM payload error: " . $payload['error']);
        wp_send_json(['success' => false, 'error' => $payload['error']]);
    }

    // v421: Store transfer offer in DB immediately — enables instant dashboard visibility
    // for the destination wallet. Before this, transfers only appeared after Bithomp indexing.
    $wpdb->insert($offers_table, [
        'offer_id'        => '',  // Populated after XUMM signing via webhook
        'uuid'            => $payload['uuid'],
        'offerer_account' => $account,
        'target_account'  => $account,  // Sender (NFT owner)
        'destination'     => $destination,  // v421: Receiving wallet
        'target_nft_id'   => $nft_id,
        'offer_type'      => 'sell',
        'listing_mode'    => 'transfer',
        'amount'          => 0,
        'currency'        => 'XRP',
        'net_amount'      => 0,
        'platform_fee'    => 0,
        'fee_currency'    => 'XRP',
        'royalty_amount'  => 0,
        'royalty_wallet'  => '',
        'royalty_percent' => 0,
        'status'          => 'pending',
        'created_at'      => current_time('mysql')
    ]);

    error_log("create_transfer_offer: Transfer offer created for NFT $nft_id to $destination (DB record stored)");

    wp_send_json([
        'success'      => true,
        'payload_uuid' => $payload['uuid'],
        'qr'           => $payload['refs']['qr_png'] ?? '',
        'deeplink'     => $payload['next']['always'] ?? '',
    ]);
    exit;
}

/** POST: joey_verify_create_transfer_offer (v561) -- confirm a Joey-signed 0-value transfer offer, then record it. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'joey_verify_create_transfer_offer')) {
    $nonce   = sanitize_text_field($_POST['nonce'] ?? '');
    $tx_hash = sanitize_text_field($_POST['tx_hash'] ?? '');
    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        wp_send_json(['success' => false, 'error' => 'Invalid nonce']);
    }
    if (strlen($tx_hash) < 40 || !preg_match('/^[0-9A-Fa-f]+$/', $tx_hash)) {
        wp_send_json(['success' => false, 'error' => 'Invalid transaction hash']);
    }
    // Verify on-chain (validated + tesSUCCESS), polling briefly for ledger close.
    $tx = null;
    for ($i = 0; $i < 5; $i++) {
        $tx = xrpl_rpc('tx', ['transaction' => $tx_hash, 'binary' => false]);
        if (!empty($tx['result']) && !empty($tx['result']['validated'])) break;
        sleep(2);
    }
    if (empty($tx['result'])) {
        wp_send_json(['success' => false, 'error' => 'Transaction not found on ledger']);
    }
    $r = $tx['result'];
    if (empty($r['validated'])) {
        wp_send_json(['success' => false, 'error' => 'Transaction not yet validated -- please retry']);
    }
    if (($r['meta']['TransactionResult'] ?? '') !== 'tesSUCCESS') {
        wp_send_json(['success' => false, 'error' => 'Transaction did not succeed on-chain: ' . ($r['meta']['TransactionResult'] ?? 'unknown')]);
    }
    if (($r['TransactionType'] ?? '') !== 'NFTokenCreateOffer') {
        wp_send_json(['success' => false, 'error' => 'Unexpected transaction type']);
    }
    if (((int)($r['Flags'] ?? 0) & 1) !== 1) {
        wp_send_json(['success' => false, 'error' => 'Transaction is not a transfer (sell) offer']);
    }
    // A transfer is a zero-value, destination-locked sell offer -- enforce both.
    $dest = sanitize_text_field($r['Destination'] ?? '');
    if (!$dest) {
        wp_send_json(['success' => false, 'error' => 'Transaction is not a destination-locked transfer']);
    }
    $amt_raw = $r['Amount'] ?? '0';
    if (is_array($amt_raw) || (string)$amt_raw !== '0') {
        wp_send_json(['success' => false, 'error' => 'Transfer offer must be zero-value']);
    }
    $account = sanitize_text_field($r['Account']   ?? '');
    $nft_id  = sanitize_text_field($r['NFTokenID'] ?? '');
    if (!$account || !$nft_id) {
        wp_send_json(['success' => false, 'error' => 'Confirmed tx missing account or NFT id']);
    }
    $offer_id = extract_offer_id_from_meta($tx);
    if (!$offer_id) {
        wp_send_json(['success' => false, 'error' => 'Could not read offer id from confirmed transaction']);
    }
    // Idempotent: never double-insert the same on-chain offer.
    $already = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $offers_table WHERE offer_id = %s", $offer_id
    ));
    if ($already > 0) {
        wp_send_json(['success' => true, 'offer_id' => $offer_id, 'already' => true]);
    }
    // Authoritative row -- mirrors the Xaman create_transfer_offer insert, already 'active'.
    $ins = $wpdb->insert($offers_table, [
        'offer_id'        => $offer_id,
        'uuid'            => 'joey:' . $tx_hash,
        'offerer_account' => $account,
        'target_account'  => $account,
        'destination'     => $dest,
        'target_nft_id'   => $nft_id,
        'offer_type'      => 'sell',
        'listing_mode'    => 'transfer',
        'amount'          => 0,
        'currency'        => 'XRP',
        'net_amount'      => 0,
        'platform_fee'    => 0,
        'fee_currency'    => 'XRP',
        'royalty_amount'  => 0,
        'royalty_wallet'  => '',
        'royalty_percent' => 0,
        'status'          => 'active',
        'created_at'      => current_time('mysql')
    ]);
    if ($ins === false) {
        // Same listener race as the other verifies -- an existing on-chain offer means success.
        $exists = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $offers_table WHERE offer_id = %s", $offer_id
        ));
        if ($exists > 0) {
            wp_send_json(['success' => true, 'offer_id' => $offer_id, 'already' => true]);
        }
        error_log("joey_verify_create_transfer_offer: DB insert failed: " . $wpdb->last_error);
        status_header(500);
        wp_send_json(['success' => false, 'error' => 'Failed to store transfer']);
    }
    error_log("joey_verify_create_transfer_offer: transfer ACTIVE offer_id=$offer_id nft=$nft_id from $account to $dest tx=$tx_hash");
    wp_send_json(['success' => true, 'offer_id' => $offer_id, 'tx_hash' => $tx_hash]);
    exit;
}

/** POST: cancel_offer (v94 - All offers are GTC, can be cancelled anytime) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'cancel_offer')) {
    $nonce    = sanitize_text_field($_POST['nonce'] ?? '');
    $account  = sanitize_text_field($_POST['account'] ?? '');
    $offer_id = sanitize_text_field($_POST['offer_id'] ?? '');
    
    error_log("cancel_offer: account=$account, offer_id=$offer_id");
    
    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        error_log("cancel_offer: Invalid nonce");
        status_header(403);
        wp_send_json(['success' => false, 'error' => 'Invalid nonce']);
    }
    
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        status_header(400);
        wp_send_json(['success' => false, 'error' => 'Invalid account']);
    }
    
    if (!preg_match('/^[A-Fa-f0-9]{64}$/', $offer_id)) {
        status_header(400);
        wp_send_json(['success' => false, 'error' => 'Invalid offer ID format']);
    }
    
    // v94: All offers are GTC - no mode restrictions on cancellation
    
    // Verify offer exists on XRPL and is owned by this account
    $check_res = wp_remote_post('https://xrplcluster.com/', [
        'body' => json_encode([
            'method' => 'ledger_entry',
            'params' => [['index' => $offer_id, 'ledger_index' => 'validated']]
        ]),
        'headers' => ['Content-Type' => 'application/json'],
        'timeout' => 10
    ]);
    
    if (is_wp_error($check_res)) {
        error_log("cancel_offer: RPC error: " . $check_res->get_error_message());
        wp_send_json(['success' => false, 'error' => 'Could not verify offer on ledger']);
    }
    
    $check_body = json_decode(wp_remote_retrieve_body($check_res), true);
    
    // Check if offer exists
    if (isset($check_body['result']['error'])) {
        if ($check_body['result']['error'] === 'entryNotFound') {
            error_log("cancel_offer: Offer $offer_id not found on ledger (already cancelled?)");
            wp_send_json(['success' => false, 'error' => 'Offer not found on ledger - may already be cancelled']);
        }
        error_log("cancel_offer: Ledger error: " . $check_body['result']['error']);
        wp_send_json(['success' => false, 'error' => 'Ledger error: ' . $check_body['result']['error']]);
    }
    
    // Verify ownership
    $offer_node = $check_body['result']['node'] ?? [];
    $offer_owner = $offer_node['Owner'] ?? '';
    
    if ($offer_owner !== $account) {
        error_log("cancel_offer: Account $account doesn't own offer (owner: $offer_owner)");
        status_header(403);
        wp_send_json(['success' => false, 'error' => 'You do not own this offer']);
    }
    
    error_log("cancel_offer: Verified - offer $offer_id owned by $account, creating cancel payload");
    
    // Build NFTokenCancelOffer transaction
    $txjson = [
        'TransactionType' => 'NFTokenCancelOffer',
        'Account'         => $account,
        'NFTokenOffers'   => [$offer_id],
        'SourceTag'       => 2606240013
    ];
    
    // --- Joey branch (v546, additive) -- all ownership/format checks above have run.
    if (($_POST['wallet'] ?? '') === 'joey' || ($_COOKIE['xrpl_wallet_type'] ?? '') === 'joey') {
        wp_send_json(['success' => true, 'wallet' => 'joey', 'txjson' => $txjson]);
        exit;
    }
    
    $payload = create_xumm_payload_with_webhook_local($txjson, [
        'type'     => 'nft_offer_cancel',
        'offer_id' => $offer_id
    ]);
    
    if (isset($payload['error'])) {
        error_log("cancel_offer: XUMM payload error: " . $payload['error']);
        wp_send_json(['success' => false, 'error' => $payload['error']]);
    }
    
    error_log("cancel_offer: Created cancel payload UUID: " . $payload['uuid']);
    
    wp_send_json([
        'success'      => true,
        'payload_uuid' => $payload['uuid'],
        'qr'           => $payload['refs']['qr_png'] ?? '',
        'deeplink'     => $payload['next']['always'] ?? ''
    ]);
    exit;
}


/** POST: joey_verify_cancel_offer (v546 -- Joey/WalletConnect cancel confirm)
 *  Verifies a signed NFTokenCancelOffer on-chain and flips the affected offers
 *  rows active->cancelled, reading the cancelled offer ids FROM THE ON-CHAIN tx
 *  (NFTokenOffers). tesSUCCESS itself proves the canceller was authorised -- the
 *  ledger rejects a cancel by anyone other than the offer owner. Mirrors the
 *  poll_xumm_payload cancel-confirm path. Brand-new action; additive.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'joey_verify_cancel_offer')) {
    $nonce   = sanitize_text_field($_POST['nonce']   ?? '');
    $tx_hash = sanitize_text_field($_POST['tx_hash'] ?? '');

    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        status_header(403);
        wp_send_json(['success' => false, 'error' => 'Invalid nonce']);
    }
    if (strlen($tx_hash) < 40 || !preg_match('/^[0-9A-Fa-f]+$/', $tx_hash)) {
        wp_send_json(['success' => false, 'error' => 'Invalid transaction hash']);
    }

    $tx = null;
    for ($i = 0; $i < 5; $i++) {
        $tx = xrpl_rpc('tx', ['transaction' => $tx_hash, 'binary' => false]);
        if (!empty($tx['result']) && !empty($tx['result']['validated'])) break;
        sleep(2);
    }
    if (empty($tx['result'])) {
        wp_send_json(['success' => false, 'error' => 'Transaction not found on ledger']);
    }
    $r = $tx['result'];
    if (empty($r['validated'])) {
        wp_send_json(['success' => false, 'error' => 'Transaction not yet validated -- please retry']);
    }
    if (($r['meta']['TransactionResult'] ?? '') !== 'tesSUCCESS') {
        wp_send_json(['success' => false, 'error' => 'Cancel did not succeed on-chain: ' . ($r['meta']['TransactionResult'] ?? 'unknown')]);
    }
    if (($r['TransactionType'] ?? '') !== 'NFTokenCancelOffer') {
        wp_send_json(['success' => false, 'error' => 'Unexpected transaction type']);
    }

    $cancelled = $r['NFTokenOffers'] ?? [];
    if (!is_array($cancelled) || empty($cancelled)) {
        wp_send_json(['success' => false, 'error' => 'No cancelled offers found in transaction']);
    }

    $rows = 0;
    foreach ($cancelled as $oid) {
        $oid = sanitize_text_field($oid);
        if (!preg_match('/^[A-Fa-f0-9]{64}$/', $oid)) continue;
        $rows += (int)$wpdb->update($offers_table, ['status' => 'cancelled'], ['offer_id' => $oid, 'status' => 'active']);
    }

    error_log("joey_verify_cancel_offer: tx=$tx_hash cancelled=" . implode(',', $cancelled) . " rows=$rows");
    wp_send_json(['success' => true, 'cancelled' => $cancelled, 'rows' => $rows]);
    exit;
}

/** POST: joey_verify_accept_offer (v551 -- Joey/WalletConnect accept confirm)
 *  Verifies a signed NFTokenAcceptOffer on-chain, then mirrors the
 *  poll_xumm_payload accept-confirm bookkeeping: marks the accepted offer row
 *  'accepted' and cancels stale active sell listings for the NFT (ownership moved).
 *  Reads the accepted offer id FROM THE ON-CHAIN tx (NFTokenSellOffer/NFTokenBuyOffer);
 *  tesSUCCESS itself proves the accept was authorised + ownership transferred on-ledger.
 *  Serves accept_sell + accept_buy + accept_offer. Brand-new action; additive.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'joey_verify_accept_offer')) {
    global $wpdb, $offers_table;
    $nonce    = sanitize_text_field($_POST['nonce']   ?? '');
    $tx_hash  = sanitize_text_field($_POST['tx_hash'] ?? '');
    $nft_hint = sanitize_text_field($_POST['nft_id']  ?? '');

    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        status_header(403);
        wp_send_json(['success' => false, 'error' => 'Invalid nonce']);
    }
    if (strlen($tx_hash) < 40 || !preg_match('/^[0-9A-Fa-f]+$/', $tx_hash)) {
        wp_send_json(['success' => false, 'error' => 'Invalid transaction hash']);
    }

    $tx = null;
    for ($i = 0; $i < 5; $i++) {
        $tx = xrpl_rpc('tx', ['transaction' => $tx_hash, 'binary' => false]);
        if (!empty($tx['result']) && !empty($tx['result']['validated'])) break;
        sleep(2);
    }
    if (empty($tx['result'])) {
        wp_send_json(['success' => false, 'error' => 'Transaction not found on ledger']);
    }
    $r = $tx['result'];
    if (empty($r['validated'])) {
        wp_send_json(['success' => false, 'error' => 'Transaction not yet validated -- please retry']);
    }
    if (($r['meta']['TransactionResult'] ?? '') !== 'tesSUCCESS') {
        wp_send_json(['success' => false, 'error' => 'Accept did not succeed on-chain: ' . ($r['meta']['TransactionResult'] ?? 'unknown')]);
    }
    if (($r['TransactionType'] ?? '') !== 'NFTokenAcceptOffer') {
        wp_send_json(['success' => false, 'error' => 'Unexpected transaction type']);
    }

    $accepted_offer = sanitize_text_field($r['NFTokenSellOffer'] ?? ($r['NFTokenBuyOffer'] ?? ''));
    if (!preg_match('/^[A-Fa-f0-9]{64}$/', $accepted_offer)) {
        wp_send_json(['success' => false, 'error' => 'No accepted offer id found in transaction']);
    }

    if (empty($offers_table)) { $offers_table = $wpdb->prefix . 'xumm_offers'; }

    // Resolve NFT id: prefer the tracked offer row, fall back to the front-end hint.
    $nft_id = $wpdb->get_var($wpdb->prepare(
        "SELECT target_nft_id FROM {$offers_table} WHERE offer_id = %s LIMIT 1", $accepted_offer
    ));
    if (!$nft_id && preg_match('/^[A-Fa-f0-9]{64}$/', $nft_hint)) { $nft_id = $nft_hint; }

    // Mirror poll_xumm_payload accept-confirm bookkeeping exactly.
    $rows = (int) $wpdb->update($offers_table,
        ['status' => 'accepted', 'accepted_at' => current_time('mysql')],
        ['offer_id' => $accepted_offer, 'status' => 'active']);

    $stale = 0;
    if ($nft_id && preg_match('/^[A-Fa-f0-9]{64}$/', $nft_id)) {
        $stale = (int) $wpdb->update($offers_table,
            ['status' => 'cancelled'],
            ['target_nft_id' => $nft_id, 'offer_type' => 'sell', 'status' => 'active']);
    }

    error_log("joey_verify_accept_offer: tx=$tx_hash accepted=$accepted_offer nft=$nft_id rows=$rows stale=$stale");
    wp_send_json(['success' => true, 'accepted' => $accepted_offer, 'nft_id' => $nft_id, 'rows' => $rows, 'stale' => $stale]);
    exit;
}


/** POST: buy_listing (Phase 2 - accept sell offer / buy listed NFT) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'buy_listing')) {
    global $IOU_ISSUER;
    
    $nonce    = sanitize_text_field($_POST['nonce'] ?? '');
    $account  = sanitize_text_field($_POST['account'] ?? '');
    $offer_id = sanitize_text_field($_POST['offer_id'] ?? '');
    
    error_log("buy_listing: account=$account, offer_id=$offer_id");
    
    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        status_header(403);
        wp_send_json(['success' => false, 'error' => 'Invalid nonce']);
    }
    
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        status_header(400);
        wp_send_json(['success' => false, 'error' => 'Invalid account']);
    }
    
    if (!preg_match('/^[A-Fa-f0-9]{64}$/', $offer_id)) {
        status_header(400);
        wp_send_json(['success' => false, 'error' => 'Invalid offer ID']);
    }
    
    // Find the sell offer
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $offers_table WHERE offer_id=%s AND offer_type='sell' AND status='active'",
        $offer_id
    ), ARRAY_A);
    
    if (!$row) {
        error_log("buy_listing: Listing not found or not active: $offer_id");
        status_header(404);
        wp_send_json(['success' => false, 'error' => 'Listing not found or no longer available']);
    }
    
    // Cannot buy your own listing
    if ($row['offerer_account'] === $account) {
        status_header(400);
        wp_send_json(['success' => false, 'error' => 'Cannot buy your own listing']);
    }

    // -- Phase 4C: block buying a listing whose NFT issuer or seller is scam-flagged --
    $scam_4c = imc_get_scam_issuers();
    if (!empty($scam_4c)) {
        $g_iss = strtolower((string)($row['nft_issuer'] ?? ''));
        $g_cpt = strtolower((string)($row['offerer_account'] ?? ''));
        if (($g_iss && in_array($g_iss, $scam_4c)) || ($g_cpt && in_array($g_cpt, $scam_4c))) {
            wp_send_json(['success' => false, 'error' => 'This NFT or seller has been flagged as high-risk and cannot be traded here.']);
        }
    }
    
    // Check buyer has sufficient balance
    $currency = $row['currency'];
    $amount = $row['amount'];
    
    if ($currency === 'XRP') {
        $balance = get_xrp_balance($account);
        if ($balance < (float)$amount + 1) {
            wp_send_json(['success' => false, 'error' => 'Insufficient XRP balance']);
        }
    } else {
        // v197: Resolve issuer dynamically (was hardcoded to XFT)
        $listing_issuer = '';
        if (function_exists('imc_get_token_by_ticker')) {
            $token_info = imc_get_token_by_ticker($currency);
            if ($token_info && !empty($token_info['issuer'])) {
                $listing_issuer = $token_info['issuer'];
            }
        }
        if (!$listing_issuer) {
            $known_issuers = [
                'XFT' => 'rGpnoqYLzWytxwQhhz715nRbqyCHM7zhxt',
                'RLUSD' => 'rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De',
                'SOLO' => 'rsoLo2S1kiGeCcn6hCUXVrCpGMWLrRrLZz',
                'SCHMECKLES' => 'rPxw83ZP6thv7KmG5DpAW4cDW55DZRZ9wu',
                'XMEME' => 'r4UPddYeGeZgDhSGPkooURsQtmGda4oYQW'
            ];
            $listing_issuer = $known_issuers[$currency] ?? '';
        }
        if (!$listing_issuer) {
            error_log("buy_listing: Could not resolve issuer for currency=$currency");
            wp_send_json(['success' => false, 'error' => "Unsupported currency: $currency"]);
        }
        if (!has_active_trustline($account, $currency, $listing_issuer)) {
            // v717 (G3A): same treatment as create_offer above -- structured flag plus the
            // resolved issuer ($listing_issuer here), so the buyer gets an actionable prompt.
            wp_send_json(['success' => false, 'error' => "You need a $currency trustline to buy with $currency",
                'buyer_trustline_required' => true, 'currency' => $currency, 'issuer' => $listing_issuer]);
        }
    }
    
    // Build NFTokenAcceptOffer transaction
    $txjson = [
        'TransactionType'  => 'NFTokenAcceptOffer',
        'Account'          => $account,
        'NFTokenSellOffer' => $offer_id,
        'SourceTag'        => 2606240013
    ];
    
    $payload = create_xumm_payload_with_webhook_local($txjson, [
        'type'     => 'nft_offer_accept',
        'offer_id' => $offer_id,
        'nft_id'   => $row['target_nft_id'] ?? ''
    ]);
    
    if (isset($payload['error'])) {
        wp_send_json(['success' => false, 'error' => $payload['error']]);
    }
    
    // Update uuid for tracking
    $wpdb->update($offers_table, ['uuid' => $payload['uuid']], ['offer_id' => $offer_id]);
    
    error_log("buy_listing: Created buy payload for listing $offer_id by $account, nft=" . ($row['target_nft_id'] ?? 'unknown'));
    
    wp_send_json([
        'success'      => true,
        'payload_uuid' => $payload['uuid'],
        'qr'           => $payload['refs']['qr_png'] ?? '',
        'deeplink'     => $payload['next']['always'] ?? ''
    ]);
    exit;
}


/** POST: webhook (from xumm-proxy fanout) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'webhook' || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false)) {
  $sig = $_SERVER['HTTP_X_IMU_PROXY'] ?? '';
  $expected = getenv('IMU_PROXY_SECRET') ?: '';
  if (!$sig || !$expected || !hash_equals($expected, $sig)) {
    error_log('webhook: Unauthorized access attempt');
    status_header(401);
    wp_send_json(['success' => false, 'error' => 'Unauthorized']);
  }
  $raw  = file_get_contents('php://input');
  $data = json_decode($raw, true);
  error_log("webhook IN: $raw");

  if (!is_array($data) || empty($data['payloadResponse']['payload_uuidv4'])) {
    error_log("webhook: Invalid payload structure");
    wp_send_json(['success' => false, 'error' => 'Invalid webhook payload']);
  }

  $pr = $data['payloadResponse'] ?? [];
  if (!$pr || empty($pr['txid']) || !isset($pr['signed'])) {
    error_log("webhook: Invalid payloadResponse");
    wp_send_json(['success' => false, 'error' => 'Invalid webhook payload']);
  }

  $uuid    = sanitize_text_field($data['payloadResponse']['payload_uuidv4']);
  $signed  = !empty($data['payloadResponse']['signed']);
  $tx_hash = sanitize_text_field($data['payloadResponse']['txid'] ?? '');
  $type    = sanitize_text_field($data['custom_meta']['type'] ?? '');

  if (!$signed) {
    $wpdb->update($offers_table, ['status' => 'rejected'], ['uuid' => $uuid]);
    error_log("webhook: Declined transaction for UUID $uuid");
    wp_send_json(['success' => true, 'status' => 'declined']);
    exit;
  }

  $tx = xrpl_rpc('tx', ['transaction'=>$tx_hash, 'binary'=>false]);
  if (!empty($tx['error'])) {
    error_log("webhook: tx lookup failed for $tx_hash: " . $tx['error']);
    status_header(500);
    wp_send_json(['success' => false, 'error' => 'Transaction lookup failed']);
  }
  if (($tx['result']['validated'] ?? false) !== true) {
    error_log("webhook: Unvalidated tx $tx_hash");
    wp_send_json(['success' => true, 'status' => 'pending_validation']);
  }
  $tr = $tx['result']['meta']['TransactionResult'] ?? '';
  if ($tr !== 'tesSUCCESS') {
    $wpdb->update($offers_table, ['status' => 'rejected'], ['uuid' => $uuid]);
    error_log("webhook: Transaction not successful for tx $tx_hash: $tr");
    status_header(400);
    wp_send_json(['success' => false, 'error' => 'Transaction not successful: ' . $tr]);
  }

  $tt = $tx['result']['TransactionType'] ?? '';
  
  // Handle buy offer creation
  if ($type === 'nft_offer_create') {
    if ($tt !== 'NFTokenCreateOffer') {
      error_log("webhook: Unexpected tx type $tt for $uuid");
      wp_send_json(['success' => false, 'error' => 'Unexpected tx type']);
    }
    $offer_id = extract_offer_id_from_meta($tx);
    if (!$offer_id) {
      error_log("webhook: OfferID not found in meta for $tx_hash ($uuid)");
      wp_send_json(['success' => false, 'error' => 'OfferID not found in meta']);
    }
    $wpdb->update($offers_table, ['offer_id'=>$offer_id, 'status'=>'active'], ['uuid'=>$uuid]);
    error_log("webhook: Finalized offer_id $offer_id from tx $tx_hash ($uuid)");
    wp_send_json(['success' => true, 'status' => 'received']);
  } 
  // Phase 2: Handle sell offer creation
  elseif ($type === 'nft_sell_offer_create') {
    if ($tt !== 'NFTokenCreateOffer') {
      error_log("webhook: Unexpected tx type $tt for sell offer $uuid");
      wp_send_json(['success' => false, 'error' => 'Unexpected tx type']);
    }
    $offer_id = extract_offer_id_from_meta($tx);
    if (!$offer_id) {
      error_log("webhook: OfferID not found in meta for sell offer $tx_hash ($uuid)");
      wp_send_json(['success' => false, 'error' => 'OfferID not found in meta']);
    }
    $wpdb->update($offers_table, ['offer_id'=>$offer_id, 'status'=>'active'], ['uuid'=>$uuid]);
    error_log("webhook: Finalized sell offer_id $offer_id from tx $tx_hash ($uuid)");
    wp_send_json(['success' => true, 'status' => 'received']);
  }
  // Handle offer acceptance (buy or sell)
  elseif ($type === 'nft_offer_accept') {
    if ($tt !== 'NFTokenAcceptOffer') {
      error_log("webhook: Unexpected tx type $tt for $uuid");
      wp_send_json(['success' => false, 'error' => 'Unexpected tx type']);
    }
    $wpdb->update($offers_table, ['status'=>'accepted', 'accepted_at'=>current_time('mysql')], ['uuid'=>$uuid]);
    error_log("webhook: Accepted offer via tx $tx_hash ($uuid)");
    wp_send_json(['success' => true, 'status' => 'received']);
  }
  // Phase 2: Handle offer cancellation
  elseif ($type === 'nft_offer_cancel') {
    if ($tt !== 'NFTokenCancelOffer') {
      error_log("webhook: Unexpected tx type $tt for cancel $uuid");
      wp_send_json(['success' => false, 'error' => 'Unexpected tx type']);
    }
    // v199: Get offer_id from custom_meta (the cancel payload UUID differs from the 
    // original offer UUID, so looking up by UUID will fail). Fall back to UUID lookup.
    $cancel_offer_id = sanitize_text_field($data['custom_meta']['offer_id'] ?? '');
    if ($cancel_offer_id && preg_match('/^[A-Fa-f0-9]{64}$/', $cancel_offer_id)) {
      $wpdb->update($offers_table, ['status'=>'cancelled'], ['offer_id'=>$cancel_offer_id, 'status'=>'active']);
      error_log("webhook: Cancelled offer $cancel_offer_id via custom_meta, tx $tx_hash ($uuid)");
    } else {
      // Fallback: try UUID lookup (works if cancel_offer stored UUID, e.g. future change)
      $row = $wpdb->get_row($wpdb->prepare(
        "SELECT offer_id FROM $offers_table WHERE uuid=%s", $uuid
      ), ARRAY_A);
      if ($row && $row['offer_id']) {
        $wpdb->update($offers_table, ['status'=>'cancelled'], ['offer_id'=>$row['offer_id']]);
        error_log("webhook: Cancelled offer {$row['offer_id']} via UUID fallback, tx $tx_hash ($uuid)");
      } else {
        error_log("webhook: Could not find offer to cancel for uuid $uuid or offer_id $cancel_offer_id");
      }
    }
    wp_send_json(['success' => true, 'status' => 'received']);
  }
  else {
    error_log("webhook: Unhandled webhook type: $type for $uuid");
    wp_send_json(['success' => true, 'status' => 'received']);
  }
  exit;
}


/** POST: broker_settle (Phase 5) -- IMU (riMCgym) brokers a matched sell+buy pair.
 *  Both offers must be broker-locked (broker_mode=1, Destination=riMCgym) and active.
 *  No custody: the ledger splits atomically (seller net, 2% broker fee, issuer royalty).
 *  Server-signed remotely. Callable by the buyer or the seller. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'broker_settle')) {
    $nonce        = sanitize_text_field($_POST['nonce'] ?? '');
    $account      = sanitize_text_field($_POST['account'] ?? '');
    $buy_offer_id = strtoupper(sanitize_text_field($_POST['buy_offer_id'] ?? ''));
    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        status_header(400); wp_send_json(['success' => false, 'error' => 'Invalid nonce']);
    }
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        status_header(400); wp_send_json(['success' => false, 'error' => 'Invalid account']);
    }
    if (!preg_match('/^[A-F0-9]{64}$/', $buy_offer_id)) {
        status_header(400); wp_send_json(['success' => false, 'error' => 'Invalid buy_offer_id']);
    }

    // Load the broker-locked buy offer.
    $buy = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $offers_table WHERE offer_id=%s AND offer_type='buy' AND broker_mode=1 AND status='active'",
        $buy_offer_id), ARRAY_A);
    if (!$buy) { status_header(404); wp_send_json(['success' => false, 'error' => 'Brokered buy offer not found or inactive']); }

    // Idempotency: never double-broker.
    if (in_array($buy['settlement_state'], ['brokering', 'settled'], true)) {
        wp_send_json(['success' => false, 'error' => 'Settlement already in progress or completed', 'settlement_state' => $buy['settlement_state']]);
    }

    // Find the matching broker-locked sell offer (oldest active) for this NFT.
    $sell = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $offers_table WHERE target_nft_id=%s AND offer_type='sell' AND broker_mode=1 AND status='active' AND offer_id != '' ORDER BY created_at ASC LIMIT 1",
        $buy['target_nft_id']), ARRAY_A);
    if (!$sell) { status_header(409); wp_send_json(['success' => false, 'error' => 'No active brokered listing for this NFT']); }

    // Authorization: only the buyer or the seller may trigger settlement.
    if ($account !== $buy['offerer_account'] && $account !== $sell['offerer_account']) {
        status_header(403); wp_send_json(['success' => false, 'error' => 'Not authorized to settle this sale']);
    }

    // Live ownership: the seller must still hold the NFT.
    $ni = nft_info_compact($sell['target_nft_id']);
    if (!$ni || ($ni['owner'] ?? '') !== $sell['offerer_account']) {
        status_header(409); wp_send_json(['success' => false, 'error' => 'Listing owner no longer holds this NFT']);
    }

    // Phase 4C scam blocklist.
    $scam = function_exists('imc_get_scam_issuers') ? imc_get_scam_issuers() : [];
    if (!empty($scam)) {
        $iss = strtolower((string)($sell['nft_issuer'] ?? ($ni['issuer'] ?? '')));
        $bb  = strtolower((string)$buy['offerer_account']);
        $ss  = strtolower((string)$sell['offerer_account']);
        if (($iss && in_array($iss, $scam)) || in_array($bb, $scam) || in_array($ss, $scam)) {
            wp_send_json(['success' => false, 'error' => 'This NFT or wallet is flagged high-risk and cannot be traded here.']);
        }
    }

    // Fetch both offers on-ledger for exact amounts + broker-lock verification.
    $buy_le  = xrpl_rpc('ledger_entry', ['nft_offer' => $buy_offer_id,   'ledger_index' => 'validated']);
    $sell_le = xrpl_rpc('ledger_entry', ['nft_offer' => $sell['offer_id'], 'ledger_index' => 'validated']);
    $buy_node  = $buy_le['result']['node']  ?? null;
    $sell_node = $sell_le['result']['node'] ?? null;
    if (!$buy_node || !$sell_node) { status_header(409); wp_send_json(['success' => false, 'error' => 'One or both offers no longer exist on-ledger']); }
    if (($buy_node['Destination'] ?? '') !== IMC_BROKER_FEE_WALLET || ($sell_node['Destination'] ?? '') !== IMC_BROKER_FEE_WALLET) {
        status_header(409); wp_send_json(['success' => false, 'error' => 'Offers are not broker-locked to the fee wallet']);
    }

    // Compute the 2% broker fee from the buy (sticker) amount, in the sale currency.
    $buy_amt = $buy_node['Amount'];
    if (is_array($buy_amt)) {
        $fee_val    = round((float)$buy_amt['value'] * (IMC_BROKER_FEE_PERCENT / 100.0), 15);
        $broker_fee = ['currency' => $buy_amt['currency'], 'issuer' => $buy_amt['issuer'], 'value' => rtrim(rtrim(sprintf('%.15f', $fee_val), '0'), '.')];
    } else {
        $sticker_xrp = (float)$buy_amt / 1000000.0;
        $broker_fee  = bcmul(sprintf('%.6f', round($sticker_xrp * (IMC_BROKER_FEE_PERCENT / 100.0), 6)), '1000000', 0);
    }

    // Lock both rows (idempotency) then broker via the VPS signer.
    $wpdb->update($offers_table, ['settlement_state' => 'brokering'], ['id' => $buy['id']]);
    $wpdb->update($offers_table, ['settlement_state' => 'brokering'], ['id' => $sell['id']]);

    $res = imc_call_broker_signer('broker_accept', [
        'sell_offer' => $sell['offer_id'],
        'buy_offer'  => $buy_offer_id,
        'broker_fee' => $broker_fee,
    ]);
    $d = $res['data'] ?? [];

    if (!empty($res['success']) && !empty($d['success']) && (($d['engine_result'] ?? '') === 'tesSUCCESS')) {
        $tx_hash = (string)($d['tx_hash'] ?? '');
        $now = current_time('mysql');
        $wpdb->update($offers_table, ['status' => 'accepted', 'settlement_state' => 'settled', 'broker_accept_tx' => $tx_hash, 'buy_offer_index' => $buy_offer_id, 'settled_at' => $now], ['id' => $sell['id']]);
        $wpdb->update($offers_table, ['status' => 'accepted', 'settlement_state' => 'settled', 'broker_accept_tx' => $tx_hash, 'sell_offer_index' => $sell['offer_id'], 'settled_at' => $now], ['id' => $buy['id']]);

        // Cancel losing broker-locked buy offers for this NFT (broker is their Destination).
        $losers = $wpdb->get_col($wpdb->prepare(
            "SELECT offer_id FROM $offers_table WHERE target_nft_id=%s AND offer_type='buy' AND broker_mode=1 AND status='active' AND offer_id != %s AND offer_id != '' LIMIT 8",
            $buy['target_nft_id'], $buy_offer_id));
        if (!empty($losers)) {
            $cancel = imc_call_broker_signer('cancel_offer', ['offers' => array_values($losers)]);
            if (!empty($cancel['success'])) {
                foreach ($losers as $lo) {
                    $wpdb->update($offers_table, ['status' => 'cancelled', 'settlement_state' => 'cancelled'], ['offer_id' => $lo, 'status' => 'active']);
                }
            }
        }
        error_log("broker_settle: SETTLED nft={$buy['target_nft_id']} tx=$tx_hash");
        wp_send_json(['success' => true, 'brokered' => true, 'tx_hash' => $tx_hash, 'nft_id' => $buy['target_nft_id']]);
    } else {
        $err = $d['engine_result'] ?? ($res['error'] ?? ($d['error'] ?? 'broker failed'));
        $wpdb->update($offers_table, ['settlement_state' => 'broker_failed'], ['id' => $buy['id']]);
        $wpdb->update($offers_table, ['settlement_state' => 'broker_failed'], ['id' => $sell['id']]);
        error_log("broker_settle: FAILED nft={$buy['target_nft_id']} err=$err");
        status_header(502);
        wp_send_json(['success' => false, 'error' => "Broker settlement failed: $err", 'engine_result' => $err]);
    }
    exit;
}

/** POST: accept_offer (target accepts a buy offer) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'accept_offer')) {
    $nonce    = sanitize_text_field($_POST['nonce'] ?? '');
    $account  = sanitize_text_field($_POST['account'] ?? '');
    $offer_id = sanitize_text_field($_POST['offer_id'] ?? '');
    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        error_log("accept_offer: Invalid nonce for account $account, offer_id $offer_id");
        status_header(400);
        wp_send_json(['success' => false, 'error' => 'Invalid nonce']);
    }
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        error_log("accept_offer: Invalid account $account");
        status_header(400);
        wp_send_json(['success' => false, 'error' => 'Invalid account']);
    }
    if (!$offer_id) {
        error_log("accept_offer: Invalid offer_id $offer_id");
        status_header(400);
        wp_send_json(['success' => false, 'error' => 'Invalid offer_id']);
    }

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $offers_table WHERE offer_id=%s AND target_account=%s AND status IN ('pending', 'active')",
        $offer_id, $account
    ), ARRAY_A);
    if (!$row) {
        error_log("accept_offer: Offer not found or not pending for offer_id $offer_id, account $account");
        status_header(404);
        wp_send_json(['success' => false, 'error' => 'Offer not found or not pending']);
    }

    // v657 (Phase 5): brokered offers are Destination-locked to the fee wallet and
    // CANNOT be direct-accepted (the tx would fail on-chain). Route to broker settle.
    if ((int)($row['broker_mode'] ?? 0) === 1) {
        wp_send_json([
            'success' => false,
            'error'   => 'This is a brokered listing — it settles through IMCollectibles',
            'brokered' => true,
            'buy_offer_id' => $offer_id
        ]);
    }

    $ni = nft_info_compact($row['target_nft_id']);
    if (!$ni || $ni['owner'] !== $account) {
        error_log("accept_offer: NFT {$row['target_nft_id']} not owned by $account");
        status_header(400);
        wp_send_json(['success' => false, 'error' => 'You no longer own this NFT']);
    }

    // -- Phase 4C: block accepting an offer whose NFT issuer or offerer is scam-flagged --
    $scam_4c = imc_get_scam_issuers();
    if (!empty($scam_4c)) {
        $g_iss = strtolower((string)($row['nft_issuer'] ?? ($ni['issuer'] ?? '')));
        $g_cpt = strtolower((string)($row['offerer_account'] ?? ''));
        if (($g_iss && in_array($g_iss, $scam_4c)) || ($g_cpt && in_array($g_cpt, $scam_4c))) {
            wp_send_json(['success' => false, 'error' => 'This NFT or wallet has been flagged as high-risk and cannot be traded here.']);
        }
    }

    // v71: Check trustline for non-XRP offers
    $offer_currency = strtoupper($row['currency'] ?? 'XRP');
    if ($offer_currency !== 'XRP') {
        // Need to get the issuer from the original offer on ledger
        $offer_info = xrpl_rpc('ledger_entry', [
            'nft_offer' => $offer_id,
            'ledger_index' => 'validated'
        ]);
        
        if (!empty($offer_info['result']['node']['Amount']) && is_array($offer_info['result']['node']['Amount'])) {
            $offer_amount = $offer_info['result']['node']['Amount'];
            $currency = strtoupper($offer_amount['currency'] ?? '');
            $issuer = $offer_amount['issuer'] ?? '';
            
            if ($currency && $issuer && !has_active_trustline($account, $currency, $issuer)) {
                // Return info about needed trustline
                // v708 (Step E): was `strlen($currency) <= 3 ? $currency : 'Token'`, which told
                // the seller "set a trustline for Token" without saying WHICH token. The wire
                // code decodes to a real ticker in every normal case; only a genuinely
                // non-decodable code (AMM LP etc.) still falls back to 'Token', which also keeps
                // a 40-char string out of the toast. Display string only -- no control flow.
                $cd_v708 = imc_currency_display($currency);
                $currency_display = (strlen($cd_v708) < 40) ? $cd_v708 : 'Token';
                error_log("accept_offer: Account $account missing trustline for $currency from $issuer");
                status_header(400);
                wp_send_json([
                    'success' => false, 
                    'error' => "You need to set a trustline for $currency_display before accepting this offer",
                    'trustline_required' => true,
                    'currency' => $currency,
                    'issuer' => $issuer
                ]);
            }
        }
    }

    $txjson = [
        'TransactionType' => 'NFTokenAcceptOffer',
        'Account'         => $account,
        'NFTokenBuyOffer' => $offer_id,
        'SourceTag'       => 2606240013
    ];
    if (($_POST['wallet'] ?? '') === 'joey' || ($_COOKIE['xrpl_wallet_type'] ?? '') === 'joey') {
        wp_send_json(['success' => true, 'wallet' => 'joey', 'txjson' => $txjson, 'offer_id' => $offer_id, 'nft_id' => ($row['target_nft_id'] ?? ''), 'accept_type' => 'buy']);
        exit;
    }

    $payload = create_xumm_payload_with_webhook_local($txjson, [
        'type'     => 'nft_offer_accept',
        'offer_id' => $offer_id,
        'nft_id'   => $row['target_nft_id']
    ]);
    if (isset($payload['error'])) {
        wp_send_json(['success' => false, 'error' => $payload['error']]);
    }
    $wpdb->update($offers_table, ['uuid' => $payload['uuid']], ['offer_id' => $offer_id]);
    error_log("accept_offer: Created accept payload for offer $offer_id");
    wp_send_json([
        'success'      => true,
        'payload_uuid' => $payload['uuid'],
        'qr'           => $payload['refs']['qr_png'] ?? '',
        'deeplink'     => $payload['next']['always'] ?? ''
    ]);
    exit;
}


/** POST: decline (UI-only decline, doesn't cancel on-ledger) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'decline')) {
    $nonce    = sanitize_text_field($_POST['nonce'] ?? '');
    $account  = sanitize_text_field($_POST['account'] ?? '');
    $offer_id = sanitize_text_field($_POST['offer_id'] ?? '');
    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        status_header(400);
        wp_send_json(['success' => false, 'error' => 'Invalid nonce']);
    }
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        status_header(400);
        wp_send_json(['success' => false, 'error' => 'Invalid account']);
    }
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $offers_table WHERE offer_id=%s AND target_account=%s AND status IN ('pending','active')",
        $offer_id, $account
    ), ARRAY_A);
    if (!$row) {
        status_header(404);
        wp_send_json(['success' => false, 'error' => 'Offer not found']);
    }
    $wpdb->update($offers_table, ['status' => 'rejected'], ['offer_id' => $offer_id]);
    error_log("decline: Declined offer $offer_id by $account (UI only)");
    wp_send_json(['success' => true, 'message' => 'Offer declined']);
    exit;
}


/** GET: accept_sell - Accept an on-ledger sell offer (claim incoming transfer/gift) */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['action'] ?? '') === 'accept_sell')) {
    $account  = sanitize_text_field($_GET['account'] ?? '');
    $offer_id = sanitize_text_field($_GET['offer_id'] ?? '');
    $nonce    = sanitize_text_field($_GET['nonce'] ?? '');
    $skip_verify = isset($_GET['skip_verify']) && $_GET['skip_verify'] === '1';
    
    error_log("accept_sell: account=$account, offer_id=$offer_id, skip_verify=$skip_verify");
    
    // Validate nonce
    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        error_log("accept_sell: Invalid nonce");
        wp_send_json(['success' => false, 'error' => 'Invalid nonce']);
        exit;
    }
    
    // Validate account
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        error_log("accept_sell: Invalid account");
        wp_send_json(['success' => false, 'error' => 'Invalid account']);
        exit;
    }
    
    // Validate offer_id (64 hex chars)
    if (!preg_match('/^[A-Fa-f0-9]{64}$/', $offer_id)) {
        error_log("accept_sell: Invalid offer_id format");
        wp_send_json(['success' => false, 'error' => 'Invalid offer ID']);
        exit;
    }
    
    $nft_id = '';
    
    // Optionally verify the offer exists on-ledger (can be skipped if dashboard already verified)
    if (!$skip_verify) {
        $offer_check = xrpl_rpc('ledger_entry', [
            'nft_offer' => $offer_id,
            'ledger_index' => 'validated'
        ], 8); // Short timeout
        
        error_log("accept_sell: ledger_entry response: " . json_encode($offer_check));
        
        if (isset($offer_check['result']['error'])) {
            $err_code = $offer_check['result']['error'] ?? 'unknown';
            error_log("accept_sell: Ledger error: $err_code for offer $offer_id");
            // If object not found, it may have been claimed already
            if ($err_code === 'entryNotFound' || $err_code === 'objectNotFound') {
                wp_send_json(['success' => false, 'error' => 'Offer not found or already claimed']);
                exit;
            }
            // For other errors, try to proceed anyway - let Xumm/XRPL validate
            error_log("accept_sell: Proceeding despite error: $err_code");
        } elseif (isset($offer_check['result']['node'])) {
            $offer_node = $offer_check['result']['node'];
            $nft_id = $offer_node['NFTokenID'] ?? '';
            
            // Verify it's a sell offer (Flags & 1 == 1)
            $flags = $offer_node['Flags'] ?? 0;
            if (($flags & 1) !== 1) {
                error_log("accept_sell: Not a sell offer, flags=$flags");
                wp_send_json(['success' => false, 'error' => 'This is not a sell offer']);
                exit;
            }
            
            // Check if this offer has a destination and if it matches our account
            $destination = $offer_node['Destination'] ?? null;
            if ($destination && strtolower($destination) !== strtolower($account)) {
                error_log("accept_sell: Destination mismatch. Offer for $destination, user is $account");
                wp_send_json(['success' => false, 'error' => 'This offer is not for you']);
                exit;
            }
        } elseif (isset($offer_check['error'])) {
            // RPC call failed (timeout, network error, etc) - proceed anyway
            error_log("accept_sell: RPC failed: " . $offer_check['error'] . " - proceeding anyway");
        }
    } else {
        error_log("accept_sell: Skipping verification (skip_verify=1)");
    }

    // -- Phase 4C: block accepting a sell offer whose NFT issuer or seller is scam-flagged --
    // -- P2-J1: extended with the PAYMENT dimension (what the offer pays with). NULL = fail-open. --
    $scam_4c = imc_get_scam_issuers();
    if (!empty($scam_4c)) {
        $g_cols = 'nft_issuer, offerer_account' . (imc_2g_exp_sql() !== '' ? ', amount_issuer' : '');
        $g_row = $wpdb->get_row($wpdb->prepare("SELECT $g_cols FROM $offers_table WHERE offer_id = %s LIMIT 1", $offer_id), ARRAY_A);
        $g_iss = strtolower((string)($g_row['nft_issuer'] ?? ''));
        if ($g_iss === '' && !empty($nft_id)) { $g_ni = nft_info_compact($nft_id); $g_iss = strtolower((string)($g_ni['issuer'] ?? '')); }
        $g_cpt = strtolower((string)($g_row['offerer_account'] ?? (isset($offer_node) ? ($offer_node['Owner'] ?? '') : '')));
        if (($g_iss && in_array($g_iss, $scam_4c)) || ($g_cpt && in_array($g_cpt, $scam_4c))) {
            wp_send_json(['success' => false, 'error' => 'This NFT or seller has been flagged as high-risk and cannot be traded here.']);
        }
    }
    
    // Build NFTokenAcceptOffer transaction
    $txjson = [
        'TransactionType'  => 'NFTokenAcceptOffer',
        'Account'          => $account,
        'NFTokenSellOffer' => $offer_id,
        'SourceTag'        => 2606240013
    ];
    
    // v684: server-authoritative, completing the v683 change. That pass swept $_POST predicates
    // and so missed the three GET-based Joey branches in this same file (accept_sell, accept_buy,
    // cancel_offer). Pure predicate widening into an already-exiting branch; Xaman path untouched.
    if (($_GET['wallet'] ?? '') === 'joey' || ($_COOKIE['xrpl_wallet_type'] ?? '') === 'joey') {
        wp_send_json(['success' => true, 'wallet' => 'joey', 'txjson' => $txjson, 'offer_id' => $offer_id, 'nft_id' => ($nft_id ?? ''), 'accept_type' => 'sell']);
        exit;
    }

    $payload = create_xumm_payload_with_webhook_local($txjson, [
        'type'     => 'nft_offer_accept',
        'offer_id' => $offer_id,
        'nft_id'   => $nft_id,
        'accept_type' => 'sell'
    ]);
    
    if (isset($payload['error'])) {
        error_log("accept_sell: Failed to create payload: " . $payload['error']);
        wp_send_json(['success' => false, 'error' => $payload['error']]);
        exit;
    }
    
    error_log("accept_sell: Created accept payload for sell offer $offer_id, uuid=" . $payload['uuid']);
    
    wp_send_json([
        'success'      => true,
        'payload_uuid' => $payload['uuid'],
        'qr_code'      => $payload['refs']['qr_png'] ?? '',
        'deeplink'     => $payload['next']['always'] ?? '',
        'offer_id'     => $offer_id,
        'nft_id'       => $nft_id
    ]);
    exit;
}


/** GET: accept_buy - Accept an on-ledger buy offer on your NFT */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['action'] ?? '') === 'accept_buy')) {
    $account  = sanitize_text_field($_GET['account'] ?? '');
    $offer_id = sanitize_text_field($_GET['offer_id'] ?? '');
    $nonce    = sanitize_text_field($_GET['nonce'] ?? '');
    $skip_verify = isset($_GET['skip_verify']) && $_GET['skip_verify'] === '1';
    
    error_log("accept_buy: account=$account, offer_id=$offer_id, skip_verify=$skip_verify");
    
    // Validate nonce
    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        error_log("accept_buy: Invalid nonce");
        wp_send_json(['success' => false, 'error' => 'Invalid nonce']);
        exit;
    }
    
    // Validate account
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        error_log("accept_buy: Invalid account");
        wp_send_json(['success' => false, 'error' => 'Invalid account']);
        exit;
    }
    
    // Validate offer_id
    if (!preg_match('/^[A-Fa-f0-9]{64}$/', $offer_id)) {
        error_log("accept_buy: Invalid offer_id format");
        wp_send_json(['success' => false, 'error' => 'Invalid offer ID']);
        exit;
    }
    
    $nft_id = '';
    
    // Optionally verify the offer exists on-ledger
    if (!$skip_verify) {
        $offer_check = xrpl_rpc('ledger_entry', [
            'nft_offer' => $offer_id,
            'ledger_index' => 'validated'
        ], 8);
        
        error_log("accept_buy: ledger_entry response: " . json_encode($offer_check));
        
        if (isset($offer_check['result']['error'])) {
            $err_code = $offer_check['result']['error'] ?? 'unknown';
            if ($err_code === 'entryNotFound' || $err_code === 'objectNotFound') {
                wp_send_json(['success' => false, 'error' => 'Offer not found or already accepted']);
                exit;
            }
            error_log("accept_buy: Proceeding despite error: $err_code");
        } elseif (isset($offer_check['result']['node'])) {
            $offer_node = $offer_check['result']['node'];
            $nft_id = $offer_node['NFTokenID'] ?? '';
            
            // Verify it's a buy offer (Flags & 1 == 0)
            $flags = $offer_node['Flags'] ?? 0;
            if (($flags & 1) !== 0) {
                error_log("accept_buy: Not a buy offer, flags=$flags");
                wp_send_json(['success' => false, 'error' => 'This is not a buy offer']);
                exit;
            }
            
            // Verify user owns the NFT
            if ($nft_id) {
                $nft_info = nft_info_compact($nft_id);
                if (!$nft_info || $nft_info['owner'] !== $account) {
                    error_log("accept_buy: User $account doesn't own NFT $nft_id");
                    wp_send_json(['success' => false, 'error' => 'You do not own this NFT']);
                    exit;
                }
            }
        }
    } else {
        error_log("accept_buy: Skipping verification (skip_verify=1)");
    }

    // -- Phase 4C: block accepting a buy offer whose NFT issuer or buyer is scam-flagged --
    // -- P2-J1: extended with the PAYMENT dimension (what the offer pays with). NULL = fail-open. --
    $scam_4c = imc_get_scam_issuers();
    if (!empty($scam_4c)) {
        $g_cols = 'nft_issuer, offerer_account' . (imc_2g_exp_sql() !== '' ? ', amount_issuer' : '');
        $g_row = $wpdb->get_row($wpdb->prepare("SELECT $g_cols FROM $offers_table WHERE offer_id = %s LIMIT 1", $offer_id), ARRAY_A);
        $g_iss = strtolower((string)($g_row['nft_issuer'] ?? (isset($nft_info) ? ($nft_info['issuer'] ?? '') : '')));
        if ($g_iss === '' && !empty($nft_id)) { $g_ni = nft_info_compact($nft_id); $g_iss = strtolower((string)($g_ni['issuer'] ?? '')); }
        $g_cpt = strtolower((string)($g_row['offerer_account'] ?? (isset($offer_node) ? ($offer_node['Owner'] ?? '') : '')));
        if (($g_iss && in_array($g_iss, $scam_4c)) || ($g_cpt && in_array($g_cpt, $scam_4c))) {
            wp_send_json(['success' => false, 'error' => 'This NFT or wallet has been flagged as high-risk and cannot be traded here.']);
        }
    }
    
    // Build NFTokenAcceptOffer transaction
    $txjson = [
        'TransactionType' => 'NFTokenAcceptOffer',
        'Account'         => $account,
        'NFTokenBuyOffer' => $offer_id,
        'SourceTag'       => 2606240013
    ];
    
    // v684: server-authoritative -- see accept_sell above.
    if (($_GET['wallet'] ?? '') === 'joey' || ($_COOKIE['xrpl_wallet_type'] ?? '') === 'joey') {
        wp_send_json(['success' => true, 'wallet' => 'joey', 'txjson' => $txjson, 'offer_id' => $offer_id, 'nft_id' => ($nft_id ?? ''), 'accept_type' => 'buy']);
        exit;
    }

    $payload = create_xumm_payload_with_webhook_local($txjson, [
        'type'     => 'nft_offer_accept',
        'offer_id' => $offer_id,
        'nft_id'   => $nft_id,
        'accept_type' => 'buy'
    ]);
    
    if (isset($payload['error'])) {
        error_log("accept_buy: Failed to create payload: " . $payload['error']);
        wp_send_json(['success' => false, 'error' => $payload['error']]);
        exit;
    }
    
    error_log("accept_buy: Created accept payload for buy offer $offer_id, uuid=" . $payload['uuid']);
    
    wp_send_json([
        'success'      => true,
        'payload_uuid' => $payload['uuid'],
        'qr_code'      => $payload['refs']['qr_png'] ?? '',
        'deeplink'     => $payload['next']['always'] ?? '',
        'offer_id'     => $offer_id,
        'nft_id'       => $nft_id
    ]);
    exit;
}


/** GET: cancel_offer - Cancel your own offer on-ledger */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['action'] ?? '') === 'cancel_offer')) {
    $account  = sanitize_text_field($_GET['account'] ?? '');
    $offer_id = sanitize_text_field($_GET['offer_id'] ?? '');
    $nonce    = sanitize_text_field($_GET['nonce'] ?? '');
    
    error_log("cancel_offer: account=$account, offer_id=$offer_id");
    
    // Validate nonce
    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        error_log("cancel_offer: Invalid nonce");
        wp_send_json(['success' => false, 'error' => 'Invalid nonce']);
        exit;
    }
    
    // Validate account
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        wp_send_json(['success' => false, 'error' => 'Invalid account']);
        exit;
    }
    
    // Validate offer_id
    if (!preg_match('/^[A-Fa-f0-9]{64}$/', $offer_id)) {
        wp_send_json(['success' => false, 'error' => 'Invalid offer ID']);
        exit;
    }
    
    // Verify the offer exists and belongs to user
    $offer_check = xrpl_rpc('ledger_entry', [
        'nft_offer' => $offer_id,
        'ledger_index' => 'validated'
    ]);
    
    if (isset($offer_check['result']['error']) || !isset($offer_check['result']['node'])) {
        wp_send_json(['success' => false, 'error' => 'Offer not found or already cancelled']);
        exit;
    }
    
    $offer_node = $offer_check['result']['node'];
    $offer_owner = $offer_node['Owner'] ?? '';
    
    if (strtolower($offer_owner) !== strtolower($account)) {
        error_log("cancel_offer: User $account doesn't own offer (owner is $offer_owner)");
        wp_send_json(['success' => false, 'error' => 'You cannot cancel this offer']);
        exit;
    }
    
    // Build NFTokenCancelOffer transaction
    $txjson = [
        'TransactionType' => 'NFTokenCancelOffer',
        'Account'         => $account,
        'NFTokenOffers'   => [$offer_id],
        'SourceTag'       => 2606240013
    ];
    
    // --- Joey branch (v548, additive): ownership verified above; return the vetted
    // txjson for local signing. Front-end then calls joey_verify_cancel_offer (v546).
    // v684: server-authoritative -- see accept_sell above.
    if (($_GET['wallet'] ?? '') === 'joey' || ($_COOKIE['xrpl_wallet_type'] ?? '') === 'joey') {
        wp_send_json(['success' => true, 'wallet' => 'joey', 'txjson' => $txjson]);
        exit;
    }
    
    $payload = create_xumm_payload_with_webhook_local($txjson, [
        'type'     => 'nft_offer_cancel',
        'offer_id' => $offer_id
    ]);
    
    if (isset($payload['error'])) {
        wp_send_json(['success' => false, 'error' => $payload['error']]);
        exit;
    }
    
    error_log("cancel_offer: Created cancel payload for offer $offer_id, uuid=" . $payload['uuid']);
    
    wp_send_json([
        'success'      => true,
        'payload_uuid' => $payload['uuid'],
        'qr_code'      => $payload['refs']['qr_png'] ?? '',
        'deeplink'     => $payload['next']['always'] ?? '',
        'offer_id'     => $offer_id
    ]);
    exit;
}


/** ========================================================================
 * GET: get_dashboard_v2 -- v363 Unified Dashboard
 *
 * Returns ALL 5 dashboard sections from 2 Bithomp HTTP calls (zero XRPL).
 *
 * Call 1: Bithomp nft-offers/{account}?sellOffers=true&buyOffers=true
 *   -> sellOffers where owner=me:  listed_items + outgoing_transfers
 *   -> buyOffers where owner=me:   offers_made
 *   -> buyOffers where owner!=me:  offers_received
 *
 * Call 2: Bithomp address/{account}?sellDestination=true&nftOffers=true
 *   -> sell offers destined to me: incoming_transfers
 *
 * Fallback: If no Bithomp token, uses account_objects + batched nft_buy_offers.
 *
 * Response: { success, listed_items[], outgoing_transfers[], offers_made[],
 *             offers_received[], incoming_transfers[], totals, elapsed_ms }
 * ======================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['action'] ?? '') === 'get_dashboard_v2')) {
    $nonce   = sanitize_text_field($_GET['nonce'] ?? '');
    $account = sanitize_text_field($_GET['account'] ?? '');

    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        status_header(403);
        wp_send_json(['success' => false, 'error' => 'Invalid nonce']);
    }
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        status_header(400);
        wp_send_json(['success' => false, 'error' => 'Invalid account']);
    }

    $start_time = microtime(true);
    error_log("get_dashboard_v2: Starting for $account");

    // v419 HOTFIX: Per-step timing for diagnostics — visible in browser console
    $timing = [];

    global $BITHOMP_TOKEN;

    // v427: Load wallet blacklist once for this request
    $blacklisted = imc_get_blacklisted_wallets();
    $scam_issuers = imc_get_scam_issuers(); // Phase 4C: scam-issuer set (fail-open)
    $bl_table = $wpdb->prefix . 'xumm_blacklist';

    $result = [
        'success'            => true,
        'listed_items'       => [],
        'outgoing_transfers' => [],
        'offers_made'        => [],
        'offers_received'    => [],
        'incoming_transfers' => [],
    ];

    $seen = [];

    // -- Helper: parse Bithomp amount (drops string or IOU object) --
    $parse_amt = function($raw) {
        if (is_string($raw) || is_numeric($raw)) {
            return ['amount' => round((float)$raw / 1000000, 6), 'currency' => 'XRP', 'issuer' => null];
        }
        if (is_array($raw)) {
            return ['amount' => (float)($raw['value'] ?? 0), 'currency' => $raw['currency'] ?? 'XRP', 'issuer' => $raw['issuer'] ?? null]; /* P2-J1: payment issuer */
        }
        return ['amount' => 0, 'currency' => 'XRP', 'issuer' => null];
    };

    $call1_ok = false;
    $call2_ok = false;

    // ==============================================================
    // v399: HYBRID DASHBOARD — XRPL for MY offers, Bithomp for scale,
    // capped XRPL scan as supplement for cross-marketplace coverage.
    //
    // Step A: account_objects(me) → MY offers (1 fast XRPL call)
    // Step B: Bithomp nft-offers/{account} → offers ON my NFTs (1 API call, scales to 1000s)
    // Step C: XRPL nft_buy/sell_offers → capped supplement (max 30 NFTs, catches Bithomp gaps)
    // Step D: Bithomp sellDestination → incoming transfers
    // ==============================================================

    error_log("get_dashboard_v2: Hybrid query for $account");

    // ── Step A: account_objects → MY offers (listed, outgoing, made) ──
    // Single XRPL call — always fast regardless of NFT count
    $marker = null; $page = 0; $my_objs = [];
    do {
        $params = ['account' => $account, 'type' => 'nft_offer', 'ledger_index' => 'validated', 'limit' => 200];
        if ($marker) $params['marker'] = $marker;
        $r = xrpl_rpc('account_objects', $params, 15);
        if (!isset($r['error']) && isset($r['result']['account_objects'])) {
            $my_objs = array_merge($my_objs, $r['result']['account_objects']);
            $marker = $r['result']['marker'] ?? null;
        } else { break; }
        $page++;
    } while ($marker && $page < 3);

    foreach ($my_objs as $obj) {
        $nft_id = $obj['NFTokenID'] ?? '';
        $flags  = $obj['Flags'] ?? 0;
        $is_sell = ($flags & 1) === 1;
        $dest   = $obj['Destination'] ?? null;
        $amt    = $obj['Amount'] ?? '0';
        if (is_string($amt)) { $amount = (float)$amt / 1000000; $currency = 'XRP'; }
        else { $amount = (float)($amt['value'] ?? 0); $currency = $amt['currency'] ?? 'XRP'; }
        // v414/P1: Deferred metadata — resolved in batch after Step D
        $oid = $obj['index'] ?? '';
        if (!empty($oid)) $seen[$oid] = true;

        $row = [
            'offer_id' => $oid, 'nft_id' => $nft_id,
            'nft_name' => 'Unnamed NFT',
            'nft_image' => '/wp-content/uploads/fallback-nft.svg',
            'type' => $is_sell ? 'sell' : 'buy',
            'amount' => $amount, 'currency' => $currency,
            'destination' => $dest, 'expiration' => $obj['Expiration'] ?? null,
            'status' => 'active', 'source' => 'ledger'
        ];

        if ($is_sell) {
            if ($dest) { $row['recipient'] = $dest; $row['is_gift'] = ($amount == 0); $result['outgoing_transfers'][] = $row; }
            else { $result['listed_items'][] = $row; }
        } else {
            $result['offers_made'][] = $row;
        }
    }

    error_log("get_dashboard_v2: Step A done — listed=" . count($result['listed_items'])
        . " outgoing=" . count($result['outgoing_transfers'])
        . " made=" . count($result['offers_made']));
    $timing['step_a'] = round((microtime(true) - $start_time) * 1000);

    // ── Step A2: DB-first incoming transfers (instant for marketplace transfers) ──
    // v421: Query our own wp_xumm_offers for active sell offers WHERE destination = this wallet.
    // Transfers created through our marketplace are in our DB immediately — no Bithomp delay.
    // This is the ONLY way to show incoming transfers instantly.
    $a2_table = $wpdb->prefix . 'xumm_offers';
    $a2_bl = !empty($blacklisted) ? "AND offerer_account NOT IN (SELECT wallet_address FROM $bl_table WHERE is_active = 1)" : '';
    $a2_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT offer_id, target_nft_id, offerer_account, amount, currency, created_at, expires_at, listing_mode
           FROM $a2_table
          WHERE destination = %s
            AND status IN ('active','pending')
            AND offer_type = 'sell'
            $a2_bl
          ORDER BY created_at DESC
          LIMIT 50",
        $account
    ), ARRAY_A) ?: [];

    foreach ($a2_rows as $a2) {
        $a2_oid = $a2['offer_id'] ?? '';
        // Only add if we have an offer_id (signed) and haven't seen it in Step A
        if (!empty($a2_oid) && !isset($seen[$a2_oid])) {
            $seen[$a2_oid] = true;
            $a2_amt = (float)($a2['amount'] ?? 0);
            $result['incoming_transfers'][] = [
                'offer_id'    => $a2_oid,
                'nft_id'      => $a2['target_nft_id'] ?? '',
                'nft_name'    => 'Incoming NFT',
                'nft_image'   => '/wp-content/uploads/fallback-nft.svg',
                'type'        => 'incoming_transfer',
                'is_gift'     => ($a2_amt == 0),
                'amount'      => $a2_amt,
                'currency'    => $a2['currency'] ?? 'XRP',
                'from'        => $a2['offerer_account'] ?? '',
                'offerer'     => $a2['offerer_account'] ?? '',
                'expiration'  => $a2['expires_at'] ?? null,
                'status'      => 'active',
                'source'      => 'db',
                'accept_type' => 'sell_offer'
            ];
        }
    }

    if (!empty($a2_rows)) {
        error_log("get_dashboard_v2: Step A2 (DB) — found " . count($a2_rows) . " incoming transfers from marketplace DB");
    }
    $timing['step_a2'] = round((microtime(true) - $start_time) * 1000);

    // ── Step A3: DB-first offers received (buy offers on user's NFTs) ──────────
    // v426/4B: Query wp_xumm_offers for active buy offers on NFTs the user owns.
    // The XRPL listener captures ALL offers XRPL-wide and writes them here.
    // This replaces the need for Bithomp Step B for offer discovery.
    //
    // For marketplace NFTs: match via wp_imc_purchases (buyer_account).
    // For external NFTs: the listener stored them by target_nft_id — we match
    // against account_objects NFTs from Step A (if user has any offers on them).
    $a3_table = $wpdb->prefix . 'xumm_offers';
    $imc_p_a3 = $wpdb->prefix . 'imc_purchases';
    $has_imc_a3 = ($wpdb->get_var("SHOW TABLES LIKE '$imc_p_a3'") === $imc_p_a3);

    if ($has_imc_a3) {
        // Buy offers on NFTs the user owns (marketplace NFTs)
        $a3_bl = !empty($blacklisted) ? "AND o.offerer_account NOT IN (SELECT wallet_address FROM $bl_table WHERE is_active = 1)" : '';
        $a3_buy = $wpdb->get_results($wpdb->prepare(
            "SELECT o.offer_id, o.target_nft_id, o.offerer_account, o.amount, o.currency,
                    o.created_at, o.destination, o.nft_issuer, o.nft_taxon" . (imc_2g_exp_sql() !== '' ? ", o.amount_issuer" : "") . "
             FROM $a3_table o
             INNER JOIN $imc_p_a3 p ON o.target_nft_id = CONVERT(p.nftoken_id USING utf8mb4) COLLATE utf8mb4_general_ci
             WHERE p.buyer_account = %s
               AND o.offer_type = 'buy'
               AND o.status = 'active'
               AND o.offerer_account != %s
               $a3_bl
             ORDER BY o.created_at DESC
             LIMIT 50",
            $account, $account
        ), ARRAY_A) ?: [];

        foreach ($a3_buy as $a3) {
            $a3_oid = $a3['offer_id'] ?? '';
            if (!empty($a3_oid) && !isset($seen[$a3_oid])) {
                $seen[$a3_oid] = true;
                $a3_amt = (float)($a3['amount'] ?? 0);
                $a3_iss = $a3['amount_issuer'] ?? null; /* P2-J1 */
                $result['offers_received'][] = [
                    'offer_id'    => $a3_oid,
                    'amount_issuer' => $a3_iss,
                    'nft_id'      => $a3['target_nft_id'] ?? '',
                    'nft_name'    => 'Unnamed NFT',
                    'nft_image'   => '/wp-content/uploads/fallback-nft.svg',
                    'type'        => ($a3_amt == 0) ? 'transfer_request' : 'buy',
                    'is_transfer_request' => ($a3_amt == 0),
                    'amount'      => $a3_amt,
                    'currency'    => $a3['currency'] ?? 'XRP',
                    'offerer'     => $a3['offerer_account'] ?? '',
                    'from'        => $a3['offerer_account'] ?? '',
                    'destination' => $a3['destination'] ?? null,
                    'expiration'  => null,
                    'status'      => 'active',
                    'source'      => 'db',
                    'accept_type' => 'buy_offer'
                ];
            }
        }

        // Also get incoming sell offers with price (not just transfers — A2 only got amount=0)
        $a3_sells = $wpdb->get_results($wpdb->prepare(
            "SELECT offer_id, target_nft_id, offerer_account, amount, currency, destination
             FROM $a3_table
             WHERE destination = %s
               AND status = 'active'
               AND offer_type = 'sell'
               AND amount > 0
               $a3_bl
             ORDER BY created_at DESC
             LIMIT 50",
            $account
        ), ARRAY_A) ?: [];

        foreach ($a3_sells as $a3s) {
            $a3s_oid = $a3s['offer_id'] ?? '';
            if (!empty($a3s_oid) && !isset($seen[$a3s_oid])) {
                $seen[$a3s_oid] = true;
                $a3s_amt = (float)($a3s['amount'] ?? 0);
                $result['incoming_transfers'][] = [
                    'offer_id'    => $a3s_oid,
                    'nft_id'      => $a3s['target_nft_id'] ?? '',
                    'nft_name'    => 'Incoming NFT',
                    'nft_image'   => '/wp-content/uploads/fallback-nft.svg',
                    'type'        => 'incoming_transfer',
                    'is_gift'     => false,
                    'amount'      => $a3s_amt,
                    'currency'    => $a3s['currency'] ?? 'XRP',
                    'from'        => $a3s['offerer_account'] ?? '',
                    'offerer'     => $a3s['offerer_account'] ?? '',
                    'status'      => 'active',
                    'source'      => 'db',
                    'accept_type' => 'sell_offer'
                ];
            }
        }

        $a3_total = count($a3_buy) + count($a3_sells);
        if ($a3_total > 0) {
            error_log("get_dashboard_v2: Step A3 (DB) — " . count($a3_buy) . " buy offers + " . count($a3_sells) . " incoming sells from listener DB");
        }
    }
    $timing['step_a3'] = round((microtime(true) - $start_time) * 1000);

    // ── Step B: Bithomp nft-offers/{account} — BACKGROUND SYNC (3s timeout) ──
    // v426/4B: DB-first (Steps A2+A3) already provided offers from the listener.
    // Bithomp now runs as a quick background check to catch anything the listener
    // hasn't indexed yet. Timeout reduced from 15s to 3s. Any NEW offers found
    // are written back to wp_xumm_offers so the next load is even faster.
    if (!empty($BITHOMP_TOKEN)) {
        $url1 = "https://bithomp.com/api/v2/nft-offers/{$account}?sellOffers=true&buyOffers=true";

        $res1 = wp_remote_get($url1, [
            'headers' => ['x-bithomp-token' => $BITHOMP_TOKEN],
            'timeout' => 3  // v426/4B: was 15s — quick sync, DB is primary now
        ]);

        if (!is_wp_error($res1) && wp_remote_retrieve_response_code($res1) === 200) {
            $body1 = json_decode(wp_remote_retrieve_body($res1), true);
            $call1_ok = true;

            $all_offers = $body1['nftOffers'] ?? $body1['sellOffers'] ?? [];
            if (isset($body1['sellOffers']) && isset($body1['buyOffers'])) {
                foreach ($body1['sellOffers'] as &$o) { $o['_type'] = 'sell'; }
                foreach ($body1['buyOffers']  as &$o) { $o['_type'] = 'buy'; }
                $all_offers = array_merge($body1['sellOffers'], $body1['buyOffers']);
            }

            $bh_count = count($all_offers);
            error_log("get_dashboard_v2: Step B OK -- $bh_count offers from Bithomp");

            foreach ($all_offers as $offer) {
                $oid = $offer['offerIndex'] ?? $offer['index'] ?? '';
                if (empty($oid) || isset($seen[$oid])) continue;
                $seen[$oid] = true;

                $nft_id  = $offer['nftokenID'] ?? $offer['nftId'] ?? $offer['NFTokenID'] ?? '';
                $flags   = $offer['flags'] ?? $offer['Flags'] ?? 0;
                $is_sell = is_array($flags) ? !empty($flags['sellToken']) : (((int)$flags & 1) === 1);
                $is_sell = $is_sell || (($offer['_type'] ?? '') === 'sell') || (($offer['type'] ?? '') === 'sell');
                $owner   = $offer['owner'] ?? $offer['account'] ?? $offer['offerOwner'] ?? '';
                $dest    = $offer['destination'] ?? null;
                $p       = $parse_amt($offer['amount'] ?? '0');
                // v414/P1: Deferred metadata — resolved in batch after Step D
                $is_mine = !empty($owner) && strtolower($owner) === strtolower($account);

                // Skip MY offers — Step A already got those from XRPL (authoritative)
                if ($is_mine) continue;

                // v427: Skip blacklisted wallets
                if (!empty($blacklisted) && in_array(strtolower($owner), $blacklisted)) continue;

                if ($is_sell) {
                    // Sell offer from someone else
                    if ($dest && strtolower($dest) === strtolower($account)) {
                        $result['incoming_transfers'][] = [
                            'offer_id' => $oid, 'nft_id' => $nft_id,
                            'nft_name' => 'Incoming NFT',
                            'nft_image' => '/wp-content/uploads/fallback-nft.svg',
                            'type' => 'incoming_transfer', 'is_gift' => ($p['amount'] == 0),
                            'amount' => $p['amount'], 'currency' => $p['currency'],
                            'amount_issuer' => $p['issuer'] ?? null, /* P2-J1 */
                            'from' => $owner, 'offerer' => $owner,
                            'expiration' => $offer['expiration'] ?? null,
                            'status' => 'active', 'source' => 'bithomp',
                            'accept_type' => 'sell_offer'
                        ];
                    }
                } else {
                    // Buy offer from someone else on an NFT I own
                    $tf  = $offer['transferFee'] ?? $offer['nft']['transferFee'] ?? 0;
                    $rp  = is_numeric($tf) ? (float)$tf / 1000 : 0;
                    $ra  = $p['amount'] * ($rp / 100);
                    $net = $p['amount'] - $ra;

                    $result['offers_received'][] = [
                        'offer_id' => $oid, 'nft_id' => $nft_id,
                        'amount_issuer' => $p['issuer'] ?? null, /* P2-J1 */
                        'nft_name' => 'Unnamed NFT',
                        'nft_image' => '/wp-content/uploads/fallback-nft.svg',
                        'type' => ($p['amount'] == 0) ? 'transfer_request' : 'buy',
                        'is_transfer_request' => ($p['amount'] == 0),
                        'amount' => $p['amount'], 'currency' => $p['currency'],
                        'offerer' => $owner, 'from' => $owner,
                        'destination' => $dest,
                        'expiration' => $offer['expiration'] ?? null,
                        'royalty_percent' => $rp, 'royalty_amount' => round($ra, 6),
                        'net_amount' => round($net, 6),
                        'status' => 'active', 'source' => 'bithomp',
                        'accept_type' => 'buy_offer'
                    ];
                }
            }

            error_log("get_dashboard_v2: Step B done -- received=" . count($result['offers_received'])
                . " incoming=" . count($result['incoming_transfers']));
            $timing['step_b'] = round((microtime(true) - $start_time) * 1000);
        } else {
            $e = is_wp_error($res1) ? $res1->get_error_message() : ('HTTP ' . wp_remote_retrieve_response_code($res1));
            error_log("get_dashboard_v2: Step B failed -- $e");
            $timing['step_b'] = round((microtime(true) - $start_time) * 1000);
            $timing['step_b_failed'] = true;
        }
    }

    // ── Step C: XRPL supplement — ONLY when Bithomp Step B failed ──
    // v419 HOTFIX: Step C was the dashboard killer — 100 sequential XRPL calls
    // taking 22+ seconds. Bithomp Step B covers 99%+ of offers already.
    // Step C only runs as a fallback when Bithomp is down/failed.
    // Cap reduced to 15 NFTs (30 XRPL calls ≈ 3-4s) as a safety net.
    $max_xrpl_scan = 15;
    $skip_step_c = $call1_ok; // Bithomp succeeded → skip the expensive scan

    $my_nfts = []; $marker = null; $page = 0;

    if (!$skip_step_c) {
        error_log("get_dashboard_v2: Step C — Bithomp failed, running XRPL fallback scan");
    do {
        $params = ['account' => $account, 'ledger_index' => 'validated', 'limit' => 400];
        if ($marker) $params['marker'] = $marker;
        $r = xrpl_rpc('account_nfts', $params, 15);
        if (!isset($r['error']) && isset($r['result']['account_nfts'])) {
            $my_nfts = array_merge($my_nfts, $r['result']['account_nfts']);
            $marker = $r['result']['marker'] ?? null;
        } else { break; }
        $page++;
    } while ($marker && $page < 3);

    $nfts_to_scan = array_slice($my_nfts, 0, $max_xrpl_scan);
    $xrpl_supplement_count = 0;

    error_log("get_dashboard_v2: Step C — scanning " . count($nfts_to_scan) . " of " . count($my_nfts) . " NFTs via XRPL");

    $chunks = array_chunk($nfts_to_scan, 5);
    foreach ($chunks as $ci => $chunk) {
        if ($ci > 0) usleep(200000);
        foreach ($chunk as $nft) {
            $nft_id = $nft['NFTokenID'] ?? '';
            if (!$nft_id) continue;
            $tf = $nft['TransferFee'] ?? 0; $rp = $tf / 1000;

            // Buy offers on this NFT
            $br = xrpl_rpc('nft_buy_offers', ['nft_id' => $nft_id, 'ledger_index' => 'validated'], 5);
            if (!isset($br['error']) && isset($br['result']['offers'])) {
                foreach ($br['result']['offers'] as $offer) {
                    $offerer = $offer['owner'] ?? '';
                    if (strtolower($offerer) === strtolower($account)) continue;
                    $oid = $offer['nft_offer_index'] ?? '';
                    if (empty($oid) || isset($seen[$oid])) continue;
                    $seen[$oid] = true;
                    $amt = $offer['amount'] ?? '0';
                    if (is_string($amt)) { $a = (float)$amt / 1000000; $c = 'XRP'; $c_iss = null; }
                    else { $a = (float)($amt['value'] ?? 0); $c = $amt['currency'] ?? 'XRP'; $c_iss = $amt['issuer'] ?? null; } /* P2-J1 */
                    $ra = $a * ($rp / 100); $net = $a - $ra;
                    // v414/P1: Deferred metadata — resolved in batch after Step D
                    $result['offers_received'][] = [
                        'offer_id' => $oid, 'nft_id' => $nft_id,
                        'amount_issuer' => $c_iss ?? null, /* P2-J1 */
                        'nft_name' => 'Unnamed NFT',
                        'nft_image' => '/wp-content/uploads/fallback-nft.svg',
                        'type' => ($a == 0) ? 'transfer_request' : 'buy',
                        'is_transfer_request' => ($a == 0),
                        'amount' => $a, 'currency' => $c,
                        'offerer' => $offerer, 'from' => $offerer,
                        'destination' => $offer['destination'] ?? null,
                        'expiration' => $offer['expiration'] ?? null,
                        'royalty_percent' => $rp, 'royalty_amount' => round($ra, 6),
                        'net_amount' => round($net, 6),
                        'status' => 'active', 'source' => 'ledger',
                        'accept_type' => 'buy_offer'
                    ];
                    $xrpl_supplement_count++;
                }
            }

            // Sell offers on this NFT (transfer requests from others)
            $sr = xrpl_rpc('nft_sell_offers', ['nft_id' => $nft_id, 'ledger_index' => 'validated'], 5);
            if (!isset($sr['error']) && isset($sr['result']['offers'])) {
                foreach ($sr['result']['offers'] as $offer) {
                    $offerer = $offer['owner'] ?? '';
                    if (strtolower($offerer) === strtolower($account)) continue;
                    $oid = $offer['nft_offer_index'] ?? '';
                    if (empty($oid) || isset($seen[$oid])) continue;
                    $seen[$oid] = true;
                    $dest_check = $offer['destination'] ?? null;
                    $amt = $offer['amount'] ?? '0';
                    if (is_string($amt)) { $a = (float)$amt / 1000000; $c = 'XRP'; $c_iss = null; }
                    else { $a = (float)($amt['value'] ?? 0); $c = $amt['currency'] ?? 'XRP'; $c_iss = $amt['issuer'] ?? null; } /* P2-J1 */
                    // v414/P1: Deferred metadata — resolved in batch after Step D

                    if ($dest_check && strtolower($dest_check) === strtolower($account)) {
                        $result['incoming_transfers'][] = [
                            'offer_id' => $oid, 'nft_id' => $nft_id,
                            'nft_name' => 'Incoming NFT',
                            'nft_image' => '/wp-content/uploads/fallback-nft.svg',
                            'type' => 'incoming_transfer', 'is_gift' => ($a == 0),
                            'amount' => $a, 'currency' => $c,
                            'from' => $offerer, 'offerer' => $offerer,
                            'expiration' => $offer['expiration'] ?? null,
                            'amount_issuer' => $c_iss ?? null, /* P2-J1 */
                            'status' => 'active', 'source' => 'ledger',
                            'accept_type' => 'sell_offer'
                        ];
                    } else {
                        $result['offers_received'][] = [
                            'offer_id' => $oid, 'nft_id' => $nft_id,
                            'amount_issuer' => $c_iss ?? null, /* P2-J1 */
                            'nft_name' => 'Unnamed NFT',
                            'nft_image' => '/wp-content/uploads/fallback-nft.svg',
                            'type' => 'sell', 'amount' => $a, 'currency' => $c,
                            'offerer' => $offerer, 'from' => $offerer,
                            'destination' => $dest_check,
                            'expiration' => $offer['expiration'] ?? null,
                            'royalty_percent' => 0, 'royalty_amount' => 0,
                            'net_amount' => $a,
                            'amount_issuer' => $c_iss ?? null, /* P2-J1 */
                            'status' => 'active', 'source' => 'ledger',
                            'accept_type' => 'sell_offer'
                        ];
                    }
                    $xrpl_supplement_count++;
                }
            }
        }
    }

    $call1_ok = true;
    error_log("get_dashboard_v2: Step C done — xrpl_supplement=$xrpl_supplement_count nfts_scanned=" . count($nfts_to_scan) . "/" . count($my_nfts));
    $timing['step_c'] = round((microtime(true) - $start_time) * 1000);
    $timing['step_c_scanned'] = count($nfts_to_scan);
    } else {
        error_log("get_dashboard_v2: Step C SKIPPED — Bithomp Step B succeeded");
        $call1_ok = true;
        $xrpl_supplement_count = 0;
        $timing['step_c'] = round((microtime(true) - $start_time) * 1000);
        $timing['step_c_skipped'] = true;
    } // end if (!$skip_step_c)

    // ── Step D: Bithomp sellDestination — BACKGROUND SYNC (3s timeout) ──
    // v426/4B: DB Steps A2+A3 already have listener data. Bithomp is quick backup.
    if (!empty($BITHOMP_TOKEN)) {
        $url2 = "https://bithomp.com/api/v2/address/{$account}?sellDestination=true&nftOffers=true";

        $res2 = wp_remote_get($url2, [
            'headers' => ['x-bithomp-token' => $BITHOMP_TOKEN],
            'timeout' => 3  // v426/4B: was 12s — quick sync, DB is primary now
        ]);

        if (!is_wp_error($res2) && wp_remote_retrieve_response_code($res2) === 200) {
            $body2 = json_decode(wp_remote_retrieve_body($res2), true);
            $call2_ok = true;

            $top_keys = is_array($body2) ? implode(',', array_keys($body2)) : 'NOT_ARRAY';
            $nft_offer_keys = isset($body2['nftOffers']) && is_array($body2['nftOffers'])
                ? implode(',', array_keys($body2['nftOffers'])) : 'NONE';
            error_log("get_dashboard_v2: Bithomp supplement keys: top=[$top_keys] nftOffers=[$nft_offer_keys]");

            $sell_dest = $body2['nftOffers']['sellDestination']
                      ?? $body2['sellDestination']
                      ?? $body2['nftOffers']['incomingSellOffers']
                      ?? $body2['incomingSellOffers']
                      ?? $body2['sellOffersDestination']
                      ?? [];

            // Belt-and-suspenders scan
            $extra_sell = $body2['nftOffers']['sellOffers']
                       ?? $body2['sellOffers']
                       ?? $body2['nftOffers']['sell']
                       ?? [];
            foreach ($extra_sell as $so) {
                $so_dest = $so['destination'] ?? $so['Destination'] ?? null;
                if ($so_dest && strtolower($so_dest) === strtolower($account)) {
                    $sell_dest[] = $so;
                }
            }

            foreach ($sell_dest as $offer) {
                $oid = $offer['offerIndex'] ?? $offer['index'] ?? $offer['nft_offer_index'] ?? '';
                if (empty($oid) || isset($seen[$oid])) continue;
                $seen[$oid] = true;

                $nft_id = $offer['nftokenID'] ?? $offer['nftId'] ?? $offer['NFTokenID'] ?? '';
                $p      = $parse_amt($offer['amount'] ?? '0');
                $from   = $offer['owner'] ?? $offer['account'] ?? $offer['offerOwner'] ?? '';
                // v414/P1: Deferred metadata — resolved in batch after Step D

                // v427: Skip blacklisted wallets
                if (!empty($blacklisted) && in_array(strtolower($from), $blacklisted)) continue;

                $result['incoming_transfers'][] = [
                    'offer_id'    => $oid,
                    'nft_id'      => $nft_id,
                    'nft_name'    => 'Incoming NFT',
                    'nft_image'   => '/wp-content/uploads/fallback-nft.svg',
                    'type'        => 'incoming_transfer',
                    'is_gift'     => ($p['amount'] == 0),
                    'amount'      => $p['amount'],
                    'currency'    => $p['currency'],
                    'from'        => $from,
                    'offerer'     => $from,
                    'expiration'  => $offer['expiration'] ?? null,
                    'status'      => 'active',
                    'source'      => 'bithomp',
                    'accept_type' => 'sell_offer'
                ];
            }

            error_log("get_dashboard_v2: Bithomp supplement done -- incoming=" . count($result['incoming_transfers']));
        } else {
            $e = is_wp_error($res2) ? $res2->get_error_message() : ('HTTP ' . wp_remote_retrieve_response_code($res2));
            error_log("get_dashboard_v2: Bithomp supplement failed -- $e (non-critical, XRPL data still valid)");
        }
    }

    $timing['step_d'] = round((microtime(true) - $start_time) * 1000);

    // ══════════════════════════════════════════════════════════════════════
    // v414/P1: STEP E — BATCH METADATA RESOLUTION
    //
    // Steps A–D collected offers with placeholder nft_name/nft_image.
    // Now resolve ALL unique nft_ids in one batch pass:
    //   1. DB imc_purchases (instant, covers platform NFTs)
    //   2. VPS batch endpoint (single call, covers indexed NFTs)
    //   3. Bithomp individual with 24hr cache (max 10 API calls per request,
    //      progressive enrichment — after a few loads ALL NFTs are cached)
    //
    // Previous: N × get_nft_metadata() = N × (DB + VPS 8s×2 + Bithomp 12s)
    // Now:      1 DB query + 1 VPS call + max 10 Bithomp calls (mostly cached)
    // ══════════════════════════════════════════════════════════════════════

    // Collect all unique nft_ids across all 5 sections
    $all_nft_ids = [];
    foreach (['listed_items', 'outgoing_transfers', 'offers_made', 'offers_received', 'incoming_transfers'] as $section) {
        foreach ($result[$section] as $offer) {
            $nid = strtoupper($offer['nft_id'] ?? '');
            if ($nid && !isset($all_nft_ids[$nid])) {
                $all_nft_ids[$nid] = true;
            }
        }
    }
    $unique_ids = array_keys($all_nft_ids);
    $meta_count = count($unique_ids);
    error_log("get_dashboard_v2: Step E — batch resolving $meta_count unique NFT IDs");

    $meta_map   = [];
    $fallback_m = ['nft_name' => 'Unnamed NFT', 'name' => 'Unnamed NFT', 'image' => '/wp-content/uploads/fallback-nft.svg'];

    if (!empty($unique_ids)) {

        // ── E1: DB batch — platform NFTs (instant) ──────────────────────
        $p_table = $wpdb->prefix . 'imc_purchases';
        $l_table = $wpdb->prefix . 'imc_listings';
        if ($wpdb->get_var("SHOW TABLES LIKE '$p_table'") === $p_table) {
            $ph_e = implode(',', array_fill(0, count($unique_ids), '%s'));
            $db_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT p.nftoken_id, p.edition_number, p.metadata_ipfs,
                            l.nft_name, l.cover_ipfs, l.artist_account
                       FROM $p_table p
                       LEFT JOIN $l_table l ON p.listing_id = l.id
                      WHERE p.nftoken_id IN ($ph_e)
                        AND p.mint_status IN ('minted','claimed')",
                    ...$unique_ids
                ),
                ARRAY_A
            ) ?: [];

            foreach ($db_rows as $dbr) {
                $nid = $dbr['nftoken_id'];
                $img = $fallback_m['image'];

                // v428: Prefer per-NFT image via VPS proxy (each NFT has unique art).
                // Falls back to listing cover only if nftoken_id is empty.
                if (!empty($nid)) {
                    $img = 'https://metadata.imcollectibles.io/img.php?nft=' . urlencode($nid);
                } else {
                    $cover = $dbr['cover_ipfs'] ?? '';
                    if ($cover) {
                        $cid = preg_replace('#^ipfs://#', '', trim($cover));
                        if ($cid) $img = 'https://metadata.imcollectibles.io/img.php?url=' . urlencode('ipfs://' . $cid);
                    }
                }

                $nm = $dbr['nft_name'] ?? 'NFT';
                if (!empty($dbr['edition_number'])) $nm .= ' #' . $dbr['edition_number'];
                $meta_map[strtoupper($nid)] = ['nft_name' => $nm, 'name' => $nm, 'image' => $img];
            }
            error_log("get_dashboard_v2: Step E1 — DB resolved " . count($db_rows) . " platform NFTs");
            $timing['step_e1'] = round((microtime(true) - $start_time) * 1000);
        }

        // ── E2: VPS batch — indexed NFTs (single call) ──────────────────
        $remaining_e2 = array_values(array_filter($unique_ids, function($id) use ($meta_map) {
            return !isset($meta_map[$id]);
        }));

        if (!empty($remaining_e2)) {
            $vps_batch_ids = array_slice($remaining_e2, 0, 100);
            // D2 (Aug 2026): one retry — a transient blip must not blank the cascade.
            $vps_resp_e = false; $vps_code_e = 0;
            for ($e2_try = 0; $e2_try < 2; $e2_try++) {
                $vps_ch = curl_init('https://metadata.imcollectibles.io/?action=batch');
                curl_setopt_array($vps_ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode(['ids' => $vps_batch_ids, 'any' => '1']), // D1: see pending/failed/stream-born rows too
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 8,
                ]);
                $vps_resp_e = curl_exec($vps_ch);
                $vps_code_e = curl_getinfo($vps_ch, CURLINFO_HTTP_CODE);
                curl_close($vps_ch);
                if ($vps_code_e === 200 && $vps_resp_e) break;
                if ($e2_try === 0) usleep(400000);
            }

            if ($vps_code_e === 200 && $vps_resp_e) {
                $vps_data_e = json_decode($vps_resp_e, true);
                $vps_nfts_e = $vps_data_e['nfts'] ?? $vps_data_e['results'] ?? [];
                $vps_resolved = 0;
                foreach ($vps_nfts_e as $vn) {
                    $vnid = $vn['nftokenID'] ?? $vn['nft_token_id'] ?? '';
                    if (!$vnid || isset($meta_map[strtoupper($vnid)])) continue;
                    // D1 (Aug 2026): any=1 now returns pending/failed rows too. GUARDS:
                    // image_proxy is emitted UNCONDITIONALLY by the batch, so stamp an
                    // image ONLY when an underlying image exists (an honest fallback
                    // tile beats a 400ing URL); names ''/'Unnamed NFT' never overwrite —
                    // Step F's ?? chain keeps the lane label for partial entries.
                    $vm    = $vn['metadata'] ?? [];
                    $v_res = (string)($vn['image_resolved'] ?? '');
                    $v_url = (string)($vn['image_url'] ?? '');
                    $ventry = [];
                    if ($v_res !== '' || $v_url !== '') {
                        $vimg = (string)($vn['image_proxy'] ?? '');
                        if ($vimg === '') $vimg = (string)(($vm['image'] ?? '') !== '' ? $vm['image'] : ($v_res !== '' ? $v_res : $v_url));
                        $ventry['image'] = $vimg;
                    }
                    $vnm = (string)($vm['name'] ?? $vn['name'] ?? '');
                    if ($vnm !== '' && $vnm !== 'Unnamed NFT') { $ventry['nft_name'] = $vnm; $ventry['name'] = $vnm; }
                    if (!empty($ventry)) { $meta_map[strtoupper($vnid)] = $ventry; $vps_resolved++; }
                }
                error_log("get_dashboard_v2: Step E2 — VPS batch resolved $vps_resolved NFTs");
            }
            $timing['step_e2'] = round((microtime(true) - $start_time) * 1000);
        }

        // ── E2b (D3, Aug 2026): bounded live-resolve for ids the STORE HAS NEVER
        // SEEN — the stream reconnect-gap class (M-D1: 4 of 5 sampled gift
        // transfers were ABSENT entirely; minted 19 Aug in one batch, one issuer).
        // ?action=get&fetch=fast asks Clio (4s) then xrpl.to (6s) and WRITES BACK,
        // so each dashboard load permanently repairs up to $d3_max rows; the rest
        // heal on subsequent loads. Hard caps: a COUNT and a WALL-CLOCK budget so
        // the hub's 30s JS timeout is never approached (Clio answers ~0.5s for
        // on-ledger NFTs; the caps are for the pathological tail).
        // Kill switch: $d3_max = 0.
        $d3_max    = 5;
        $d3_budget = 8.0;  // D4: was 12 — with the 8s per-call cap, worst case ~2 slow calls
        $remaining_d3 = array_values(array_filter($unique_ids, function($id) use ($meta_map) {
            return !isset($meta_map[$id]);
        }));
        if (!empty($remaining_d3) && $d3_max > 0) {
            $d3_start = microtime(true); $d3_done = 0; $d3_hits = 0;
            foreach ($remaining_d3 as $d3_id) {
                if ($d3_done >= $d3_max || (microtime(true) - $d3_start) > $d3_budget) break;
                // D4 (21 Aug): per-id failure memo — a failed id costs ONE attempt per
                // hour instead of blocking the lane every load (head-of-line lesson).
                $d3_nk = 'd3neg_' . substr(md5($d3_id), 0, 12);
                if (get_transient($d3_nk) !== false) continue;
                $d3_ch = curl_init('https://metadata.imcollectibles.io/indexer.php?action=get&id=' . rawurlencode($d3_id) . '&fetch=fast&any=1');
                curl_setopt_array($d3_ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]); // D4: was 11
                $d3_resp = curl_exec($d3_ch);
                $d3_code = curl_getinfo($d3_ch, CURLINFO_HTTP_CODE);
                curl_close($d3_ch);
                $d3_done++;
                if ($d3_code !== 200 || !$d3_resp) continue;
                $d3_j = json_decode($d3_resp, true);
                $d3_n = is_array($d3_j) ? ($d3_j['nft'] ?? null) : null;
                if (!is_array($d3_n)) continue;
                // the same D1 guards as E2: image only when one exists; names
                // ''/'Unnamed NFT' never stamp.
                $d3_res = (string)($d3_n['image_resolved'] ?? '');
                $d3_url = (string)($d3_n['image_url'] ?? '');
                $d3_entry = [];
                if ($d3_res !== '' || $d3_url !== '') {
                    $d3_img = (string)($d3_n['image_proxy'] ?? '');
                    if ($d3_img === '') $d3_img = ($d3_res !== '' ? $d3_res : $d3_url);
                    $d3_entry['image'] = $d3_img;
                }
                $d3_nm = (string)($d3_n['name'] ?? '');
                if ($d3_nm !== '' && $d3_nm !== 'Unnamed NFT') { $d3_entry['nft_name'] = $d3_nm; $d3_entry['name'] = $d3_nm; }
                if (!empty($d3_entry)) { $meta_map[strtoupper($d3_id)] = $d3_entry; $d3_hits++; }
                else { set_transient($d3_nk, 1, 3600); } // D4: nothing stampable — memo 1h (row may be created-pending; the daemon names it later)
            }
            error_log("get_dashboard_v2: Step E2b — fetch=fast attempted $d3_done, resolved $d3_hits (stream-gap heal)");
            $timing['step_e2b'] = round((microtime(true) - $start_time) * 1000);
        }

        // ── E3: Bithomp individual with 24hr cache (max 10 API calls) ───
        $remaining_e3 = array_values(array_filter($unique_ids, function($id) use ($meta_map) {
            return !isset($meta_map[$id]);
        }));

        if (!empty($remaining_e3) && !empty($BITHOMP_TOKEN)) {
            $bh_api_calls = 0;
            $bh_max_calls = 0; // P2b-D2: E3's per-NFT URL carries assets=true (free-tier dead) - zeroed, cache-read path intact // v419 HOTFIX: was 10 — progressive enrichment, cache builds over loads
            $bh_cache_hits = 0;

            foreach ($remaining_e3 as $bh_nid) {
                // Check 24hr cache first (free — no API call)
                $bh_ck = 'bh_meta_' . substr(md5($bh_nid), 0, 12);
                $bh_cached = get_transient($bh_ck);
                if ($bh_cached !== false) {
                    $meta_map[$bh_nid] = $bh_cached;
                    $bh_cache_hits++;
                    continue;
                }

                if ($bh_api_calls >= $bh_max_calls) continue; // Hit limit, rest on next load

                $bh_url = "https://bithomp.com/api/v2/nft/" . rawurlencode($bh_nid) . "?metadata=true&assets=true";
                $bh_res = wp_remote_get($bh_url, [
                    'headers' => ['x-bithomp-token' => $BITHOMP_TOKEN],
                    'timeout' => 6
                ]);
                $bh_api_calls++;

                if (!is_wp_error($bh_res) && wp_remote_retrieve_response_code($bh_res) === 200) {
                    $bh_body = json_decode(wp_remote_retrieve_body($bh_res), true);
                    if (!empty($bh_body['nftokenID'])) {
                        $bh_img = $bh_body['assets']['image'] ?? $bh_body['metadata']['image'] ?? $fallback_m['image'];
                        $bh_nm  = $bh_body['metadata']['name'] ?? $fallback_m['nft_name'];
                        $bh_m   = ['nft_name' => $bh_nm, 'name' => $bh_nm, 'image' => $bh_img];
                        $meta_map[$bh_nid] = $bh_m;
                        set_transient($bh_ck, $bh_m, 86400); // 24hr cache
                        continue;
                    }
                }
                // Cache failures for 1hr to avoid re-hitting
                set_transient($bh_ck, $fallback_m, 3600);
                $meta_map[$bh_nid] = $fallback_m;
            }

            error_log("get_dashboard_v2: Step E3 — Bithomp: $bh_api_calls API calls, $bh_cache_hits cache hits, "
                . count($remaining_e3) . " needed");
            $timing['step_e3'] = round((microtime(true) - $start_time) * 1000);
            $timing['step_e3_api_calls'] = $bh_api_calls;
            $timing['step_e3_cache_hits'] = $bh_cache_hits;
        }
    }

    // ── STEP F: ENRICH — Map resolved metadata to all offer arrays ────
    $enriched_count = 0;
    foreach (['listed_items', 'outgoing_transfers', 'offers_made', 'offers_received', 'incoming_transfers'] as $section) {
        foreach ($result[$section] as &$offer) {
            $nid = strtoupper($offer['nft_id'] ?? '');
            if ($nid && isset($meta_map[$nid])) {
                $m = $meta_map[$nid];
                $offer['nft_name']  = $m['nft_name'] ?? $m['name'] ?? $offer['nft_name'];
                $offer['nft_image'] = $m['image'] ?? $offer['nft_image'];
                $enriched_count++;
            }
        }
        unset($offer);
    }

    error_log("get_dashboard_v2: Step F — enriched $enriched_count offer cards with metadata");
    $timing['step_f'] = round((microtime(true) - $start_time) * 1000);

    $elapsed = round((microtime(true) - $start_time) * 1000);
    $result['elapsed_ms']  = $elapsed;
    $result['timing']      = $timing; // v419 HOTFIX: per-step timing for diagnostics

    // Determine data source for JS-side messaging
    if ($call1_ok && $call2_ok) {
        $result['data_source'] = 'xrpl+bithomp';
    } else if ($call1_ok) {
        $result['data_source'] = 'xrpl+bithomp_partial';
    } else {
        $result['data_source'] = 'xrpl_only';
    }

    // -- Phase 4C: hide counterparty offers/transfers from blacklisted or scam-flagged wallets --
    // Single post-build pass over all loops' output (Steps A-E); keys on offerer/from.
    // Rows lacking both are kept (safe default). User's-own buckets are not filtered.
    if (!empty($blacklisted) || !empty($scam_issuers)) {
        $blk_dv2 = function($w) use ($blacklisted, $scam_issuers) { $w = strtolower((string)$w); return (!empty($blacklisted) && in_array($w, $blacklisted)) || (!empty($scam_issuers) && in_array($w, $scam_issuers)); };
        foreach (['offers_received', 'incoming_transfers'] as $bucket_4c) {
            if (!empty($result[$bucket_4c])) {
                $result[$bucket_4c] = array_values(array_filter($result[$bucket_4c], function($o) use ($blk_dv2) {
                    return !$blk_dv2($o['offerer'] ?? $o['from'] ?? '');
                }));
            }
        }
    }

    // -- v706: display-only currency normalisation (additive). Rows built from the
    // ledger/Bithomp (Steps A, B, C) carry the raw 40-char hex currency code, which
    // breaks the UI when rendered. Adds 'currency_display' to every row in every
    // bucket; 'currency' is left byte-identical so no downstream or transaction
    // behaviour changes. Mirrors the Phase 4C post-build pass above. Non-decodable
    // codes pass through unchanged (see imc_currency_display).
    foreach (['listed_items', 'outgoing_transfers', 'offers_made', 'offers_received', 'incoming_transfers'] as $bkt_v706) {
        if (empty($result[$bkt_v706]) || !is_array($result[$bkt_v706])) continue;
        foreach ($result[$bkt_v706] as $ix_v706 => $row_v706) {
            if (!is_array($row_v706)) continue;
            $result[$bkt_v706][$ix_v706]['currency_display'] = imc_currency_display($row_v706['currency'] ?? 'XRP');
        }
    }

    // -- P2-J1 (full): the payment dimension. Stamp token/scam flags on every
    //    row in every bucket, then REMOVE scam-payment offers from the two
    //    INCOMING lanes entirely (policy: hide to protect, 12 Aug 2026).
    //    Own lanes (offers_made / listed_items / outgoing) are NEVER hidden: a
    //    user must always see their own offers to be able to cancel them.
    //    NULL amount_issuer (historical / unknown) = fail-open, never hidden.
    $j1_hidden = 0;
    foreach (['listed_items', 'outgoing_transfers', 'offers_made', 'offers_received', 'incoming_transfers'] as $bkt_j1) {
        if (empty($result[$bkt_j1]) || !is_array($result[$bkt_j1])) continue;
        foreach ($result[$bkt_j1] as $ix_j1 => $row_j1) {
            if (!is_array($row_j1)) continue;
            $result[$bkt_j1][$ix_j1]['payment_is_token'] = (($row_j1['currency'] ?? 'XRP') !== 'XRP');
            $j1_iss = strtolower((string)($row_j1['amount_issuer'] ?? ''));
            $result[$bkt_j1][$ix_j1]['payment_scam'] = ($j1_iss !== '' && !empty($scam_issuers) && in_array($j1_iss, $scam_issuers, true));
        }
    }
    foreach (['offers_received', 'incoming_transfers'] as $bkt_j1h) {
        if (empty($result[$bkt_j1h]) || !is_array($result[$bkt_j1h])) continue;
        $j1_n = count($result[$bkt_j1h]);
        $result[$bkt_j1h] = array_values(array_filter($result[$bkt_j1h], function ($j1_r) { return empty($j1_r['payment_scam']); }));
        $j1_hidden += $j1_n - count($result[$bkt_j1h]);
    }
    if ($j1_hidden > 0) { $result['scam_offers_hidden'] = $j1_hidden; } // additive, silent (diagnostics only — no UI)

    $result['totals'] = [
        'listed_items'       => count($result['listed_items']),
        'outgoing_transfers' => count($result['outgoing_transfers']),
        'offers_made'        => count($result['offers_made']),
        'offers_received'    => count($result['offers_received']),
        'incoming_transfers' => count($result['incoming_transfers']),
    ];

    error_log("get_dashboard_v2: Done in {$elapsed}ms -- " . json_encode($result['totals']));
    wp_send_json($result);
    exit;
}


/** GET: get_dashboard_offers - OLD comprehensive endpoint (kept for backward compat) */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['action'] ?? '') === 'get_dashboard_offers')) {
    $nonce   = sanitize_text_field($_GET['nonce'] ?? '');
    $account = sanitize_text_field($_GET['account'] ?? '');
    $check_nfts = sanitize_text_field($_GET['nft_ids'] ?? '');     // Comma-separated NFT IDs to check for incoming transfers
    $check_senders = sanitize_text_field($_GET['senders'] ?? '');  // Comma-separated sender accounts to check
    $max_nfts = intval($_GET['max_nfts'] ?? 50);                   // Max owned NFTs to check for received offers
    
    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        status_header(403);
        wp_send_json(['success' => false, 'error' => 'Invalid nonce']);
    }
    
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        status_header(400);
        wp_send_json(['success' => false, 'error' => 'Invalid account']);
    }

    // Phase 4C: load blacklist + scam-issuer set once for this request (handler had neither)
    $blacklisted = imc_get_blacklisted_wallets();
    $scam_issuers = imc_get_scam_issuers();
    
    error_log("get_dashboard_offers: Starting for $account");
    $start_time = microtime(true);
    
    // Parse comma-separated lists
    $nft_ids_array = $check_nfts ? array_filter(array_map('trim', explode(',', $check_nfts))) : [];
    $senders_array = $check_senders ? array_filter(array_map('trim', explode(',', $check_senders))) : [];
    
    // Validate sender addresses
    $senders_array = array_filter($senders_array, function($s) use ($account) {
        return preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $s) && strtolower($s) !== strtolower($account);
    });
    
    error_log("get_dashboard_offers: Checking " . count($nft_ids_array) . " NFT IDs and " . count($senders_array) . " senders");
    
    $result = [
        'success' => true,
        'account' => $account,
        'incoming_transfers' => [],  // Sell offers TO me (gifts/airdrops I can claim)
        'outgoing_transfers' => [],  // Sell offers FROM me with destination (gifts I'm sending)
        'offers_received' => [],     // Buy offers on NFTs I own
        'listed_items' => [],        // My sell listings (no destination)
        'offers_made' => [],         // My buy offers on others' NFTs
        'totals' => []
    ];
    
    // ===== STEP 1: Get MY account_objects (my outgoing offers) =====
    error_log("get_dashboard_offers: Fetching account_objects for $account");
    
    $my_objects = [];
    $marker = null;
    $max_pages = 3;
    $page = 0;
    
    do {
        $params = [
            'account' => $account,
            'type' => 'nft_offer',
            'ledger_index' => 'validated',
            'limit' => 200
        ];
        if ($marker) $params['marker'] = $marker;
        
        $obj_result = xrpl_rpc('account_objects', $params, 15);
        
        if (!isset($obj_result['error']) && isset($obj_result['result']['account_objects'])) {
            $my_objects = array_merge($my_objects, $obj_result['result']['account_objects']);
            $marker = $obj_result['result']['marker'] ?? null;
        } else {
            break;
        }
        $page++;
    } while ($marker && $page < $max_pages);
    
    error_log("get_dashboard_offers: Found " . count($my_objects) . " of my NFT offers");
    
    // Categorize my offers
    foreach ($my_objects as $obj) {
        $nft_id = $obj['NFTokenID'] ?? '';
        $flags = $obj['Flags'] ?? 0;
        $is_sell = ($flags & 1) === 1;
        $destination = $obj['Destination'] ?? null;
        
        $amt = $obj['Amount'] ?? '0';
        if (is_string($amt)) {
            $amount = (float)$amt / 1000000;
            $currency = 'XRP';
        } else {
            $amount = (float)($amt['value'] ?? 0);
            $currency = $amt['currency'] ?? 'XRP';
        }
        
        $meta = get_nft_metadata($nft_id);
        
        $offer_data = [
            'offer_id' => $obj['index'] ?? '',
            'nft_id' => $nft_id,
            'nft_name' => $meta['nft_name'] ?? $meta['name'] ?? 'Unnamed NFT',
            'nft_image' => $meta['image'] ?? '/wp-content/uploads/fallback-nft.svg',
            'type' => $is_sell ? 'sell' : 'buy',
            'amount' => $amount,
            'currency' => $currency,
            'destination' => $destination,
            'expiration' => $obj['Expiration'] ?? null,
            'status' => 'active',
            'source' => 'ledger'
        ];
        
        if ($is_sell) {
            if ($destination) {
                // Outgoing transfer (gift I'm sending to someone)
                $offer_data['recipient'] = $destination;
                $offer_data['is_gift'] = ($amount == 0);
                $result['outgoing_transfers'][] = $offer_data;
            } else {
                // Listed item (my marketplace listing)
                $result['listed_items'][] = $offer_data;
            }
        } else {
            // Buy offer I made on someone else's NFT
            $result['offers_made'][] = $offer_data;
        }
    }
    
    // ===== STEP 2: Check for INCOMING TRANSFERS (sell offers TO me) =====
    // Method A: Check specific NFT IDs for sell offers destined to me
    if (!empty($nft_ids_array)) {
        error_log("get_dashboard_offers: Checking " . count($nft_ids_array) . " NFT IDs for incoming transfers");
        
        foreach ($nft_ids_array as $nft_id) {
            if (strlen($nft_id) !== 64) continue;
            
            $sell_result = xrpl_rpc('nft_sell_offers', [
                'nft_id' => $nft_id,
                'ledger_index' => 'validated'
            ], 8);
            
            if (isset($sell_result['error']) || !isset($sell_result['result']['offers'])) {
                continue;
            }
            
            foreach ($sell_result['result']['offers'] as $offer) {
                $dest = $offer['destination'] ?? null;
                
                // Is this offer destined to me?
                if ($dest && strtolower($dest) === strtolower($account)) {
                    $amt = $offer['amount'] ?? '0';
                    if (is_string($amt)) {
                        $amount = (float)$amt / 1000000;
                        $currency = 'XRP';
                    } else {
                        $amount = (float)($amt['value'] ?? 0);
                        $currency = $amt['currency'] ?? 'XRP';
                    }
                    
                    $meta = get_nft_metadata($nft_id);
                    
                    $result['incoming_transfers'][] = [
                        'offer_id' => $offer['nft_offer_index'] ?? '',
                        'nft_id' => $nft_id,
                        'nft_name' => $meta['nft_name'] ?? $meta['name'] ?? 'Incoming NFT',
                        'nft_image' => $meta['image'] ?? '/wp-content/uploads/fallback-nft.svg',
                        'type' => 'incoming_transfer',
                        'is_gift' => ($amount == 0),
                        'amount' => $amount,
                        'currency' => $currency,
                        'from' => $offer['owner'] ?? '',
                        'offerer' => $offer['owner'] ?? '',
                        'expiration' => $offer['expiration'] ?? null,
                        'status' => 'active',
                        'source' => 'ledger',
                        'accept_type' => 'sell_offer'  // We accept THEIR sell offer
                    ];
                }
            }
        }
    }
    
    // Method B: Check specific senders' account_objects for offers destined to me
    if (!empty($senders_array)) {
        error_log("get_dashboard_offers: Checking " . count($senders_array) . " senders for incoming transfers");
        
        foreach ($senders_array as $sender) {
            $sender_result = xrpl_rpc('account_objects', [
                'account' => $sender,
                'type' => 'nft_offer',
                'ledger_index' => 'validated',
                'limit' => 100
            ], 10);
            
            if (isset($sender_result['error']) || !isset($sender_result['result']['account_objects'])) {
                continue;
            }
            
            foreach ($sender_result['result']['account_objects'] as $obj) {
                $dest = $obj['Destination'] ?? null;
                
                // Is this offer destined to me?
                if ($dest && strtolower($dest) === strtolower($account)) {
                    $nft_id = $obj['NFTokenID'] ?? '';
                    $flags = $obj['Flags'] ?? 0;
                    $is_sell = ($flags & 1) === 1;
                    
                    // We only want SELL offers (gifts being sent to us)
                    if (!$is_sell) continue;
                    
                    $amt = $obj['Amount'] ?? '0';
                    if (is_string($amt)) {
                        $amount = (float)$amt / 1000000;
                        $currency = 'XRP';
                    } else {
                        $amount = (float)($amt['value'] ?? 0);
                        $currency = $amt['currency'] ?? 'XRP';
                    }
                    
                    $meta = get_nft_metadata($nft_id);
                    
                    $result['incoming_transfers'][] = [
                        'offer_id' => $obj['index'] ?? '',
                        'nft_id' => $nft_id,
                        'nft_name' => $meta['nft_name'] ?? $meta['name'] ?? 'Incoming NFT',
                        'nft_image' => $meta['image'] ?? '/wp-content/uploads/fallback-nft.svg',
                        'type' => 'incoming_transfer',
                        'is_gift' => ($amount == 0),
                        'amount' => $amount,
                        'currency' => $currency,
                        'from' => $sender,
                        'offerer' => $sender,
                        'expiration' => $obj['Expiration'] ?? null,
                        'status' => 'active',
                        'source' => 'ledger',
                        'accept_type' => 'sell_offer'
                    ];
                }
            }
        }
    }
    
    error_log("get_dashboard_offers: Found " . count($result['incoming_transfers']) . " incoming transfers from known sources");
    
    // ===== Method C: Scan user's recent transaction history for incoming offer notifications =====
    // This catches transfers from ANY sender without needing to know them in advance
    error_log("get_dashboard_offers: Scanning transaction history for incoming transfers");
    
    $tx_result = xrpl_rpc('account_tx', [
        'account' => $account,
        'ledger_index_min' => -1,
        'ledger_index_max' => -1,
        'limit' => 100,  // Check last 100 transactions
        'forward' => false  // Most recent first
    ], 15);
    
    if (!isset($tx_result['error']) && isset($tx_result['result']['transactions'])) {
        $existing_offer_ids = array_column($result['incoming_transfers'], 'offer_id');
        
        foreach ($tx_result['result']['transactions'] as $tx_entry) {
            $tx = $tx_entry['tx'] ?? $tx_entry;
            $meta = $tx_entry['meta'] ?? [];
            
            // Look for NFTokenCreateOffer transactions
            if (($tx['TransactionType'] ?? '') !== 'NFTokenCreateOffer') {
                continue;
            }
            
            // Check if this offer has our account as Destination
            $dest = $tx['Destination'] ?? null;
            if (!$dest || strtolower($dest) !== strtolower($account)) {
                continue;
            }
            
            // Check if it's a sell offer (Flags & 1)
            $flags = $tx['Flags'] ?? 0;
            $is_sell = ($flags & 1) === 1;
            if (!$is_sell) {
                continue;
            }
            
            // Get the offer index from the metadata
            $offer_id = null;
            $affected_nodes = $meta['AffectedNodes'] ?? [];
            foreach ($affected_nodes as $node) {
                $created = $node['CreatedNode'] ?? null;
                if ($created && ($created['LedgerEntryType'] ?? '') === 'NFTokenOffer') {
                    $offer_id = $created['LedgerIndex'] ?? null;
                    break;
                }
            }
            
            if (!$offer_id) {
                continue;
            }
            
            // Skip if we already have this offer from Method A or B
            if (in_array($offer_id, $existing_offer_ids)) {
                continue;
            }
            
            // Verify the offer still exists on ledger (hasn't been accepted/cancelled)
            $offer_check = xrpl_rpc('ledger_entry', [
                'nft_offer' => $offer_id,
                'ledger_index' => 'validated'
            ], 5);
            
            if (isset($offer_check['result']['error']) || !isset($offer_check['result']['node'])) {
                // Offer no longer exists
                continue;
            }
            
            $offer_node = $offer_check['result']['node'];
            $nft_id = $offer_node['NFTokenID'] ?? $tx['NFTokenID'] ?? '';
            $sender = $offer_node['Owner'] ?? $tx['Account'] ?? '';
            
            $amt = $offer_node['Amount'] ?? $tx['Amount'] ?? '0';
            if (is_string($amt)) {
                $amount = (float)$amt / 1000000;
                $currency = 'XRP';
            } else {
                $amount = (float)($amt['value'] ?? 0);
                $currency = $amt['currency'] ?? 'XRP';
            }
            
            $nft_meta = get_nft_metadata($nft_id);
            
            $result['incoming_transfers'][] = [
                'offer_id' => $offer_id,
                'nft_id' => $nft_id,
                'nft_name' => $nft_meta['nft_name'] ?? $nft_meta['name'] ?? 'Incoming NFT',
                'nft_image' => $nft_meta['image'] ?? '/wp-content/uploads/fallback-nft.svg',
                'type' => 'incoming_transfer',
                'is_gift' => ($amount == 0),
                'amount' => $amount,
                'currency' => $currency,
                'from' => $sender,
                'offerer' => $sender,
                'expiration' => $offer_node['Expiration'] ?? null,
                'status' => 'active',
                'source' => 'tx_history',
                'accept_type' => 'sell_offer'
            ];
            
            $existing_offer_ids[] = $offer_id; // Prevent duplicates
        }
        
        error_log("get_dashboard_offers: Found " . count($result['incoming_transfers']) . " total incoming transfers after tx scan");
    }
    
    // ===== Method D: Use Bithomp API to find offers destined to this account =====
    // Bithomp indexes the entire ledger and can search more efficiently
    global $BITHOMP_TOKEN;
    if (!empty($BITHOMP_TOKEN)) {
        error_log("get_dashboard_offers: Checking Bithomp for incoming offers");
        
        $bithomp_url = "https://bithomp.com/api/v2/address/{$account}/nfts/offers?destination=true&type=sell&limit=20";
        $bithomp_response = wp_remote_get($bithomp_url, [
            'headers' => [
                'x-bithomp-token' => $BITHOMP_TOKEN
            ],
            'timeout' => 10
        ]);
        
        if (!is_wp_error($bithomp_response) && wp_remote_retrieve_response_code($bithomp_response) === 200) {
            $bithomp_data = json_decode(wp_remote_retrieve_body($bithomp_response), true);
            $existing_offer_ids = array_column($result['incoming_transfers'], 'offer_id');
            
            if (isset($bithomp_data['offers']) && is_array($bithomp_data['offers'])) {
                foreach ($bithomp_data['offers'] as $offer) {
                    $offer_id = $offer['offerIndex'] ?? $offer['index'] ?? '';
                    
                    if (empty($offer_id) || in_array($offer_id, $existing_offer_ids)) {
                        continue;
                    }
                    
                    $nft_id = $offer['nftokenID'] ?? $offer['nftId'] ?? '';
                    $amount = isset($offer['amount']) ? (float)$offer['amount'] / 1000000 : 0;
                    $currency = 'XRP';
                    
                    if (is_array($offer['amount'] ?? null)) {
                        $amount = (float)($offer['amount']['value'] ?? 0);
                        $currency = $offer['amount']['currency'] ?? 'XRP';
                    }
                    
                    $nft_meta = get_nft_metadata($nft_id);
                    
                    $result['incoming_transfers'][] = [
                        'offer_id' => $offer_id,
                        'nft_id' => $nft_id,
                        'nft_name' => $nft_meta['nft_name'] ?? $nft_meta['name'] ?? $offer['nftName'] ?? 'Incoming NFT',
                        'nft_image' => $nft_meta['image'] ?? $offer['nftImage'] ?? '/wp-content/uploads/fallback-nft.svg',
                        'type' => 'incoming_transfer',
                        'is_gift' => ($amount == 0),
                        'amount' => $amount,
                        'currency' => $currency,
                        'from' => $offer['owner'] ?? $offer['account'] ?? '',
                        'offerer' => $offer['owner'] ?? $offer['account'] ?? '',
                        'expiration' => $offer['expiration'] ?? null,
                        'status' => 'active',
                        'source' => 'bithomp',
                        'accept_type' => 'sell_offer'
                    ];
                }
                
                error_log("get_dashboard_offers: Found " . count($result['incoming_transfers']) . " incoming transfers after Bithomp scan");
            }
        } else {
            error_log("get_dashboard_offers: Bithomp API call failed or returned error");
        }
    }
    
    // ===== Method E: Check recent NFT trading partners for pending offers =====
    // Find accounts we've traded NFTs with recently and check if they have pending offers for us
    if (count($result['incoming_transfers']) === 0) {
        error_log("get_dashboard_offers: Checking recent trading partners for incoming offers");
        
        $tx_result2 = xrpl_rpc('account_tx', [
            'account' => $account,
            'ledger_index_min' => -1,
            'ledger_index_max' => -1,
            'limit' => 50,
            'forward' => false
        ], 12);
        
        $trading_partners = [];
        if (!isset($tx_result2['error']) && isset($tx_result2['result']['transactions'])) {
            foreach ($tx_result2['result']['transactions'] as $tx_entry) {
                $tx = $tx_entry['tx'] ?? $tx_entry;
                $tx_type = $tx['TransactionType'] ?? '';
                
                // Look for NFT-related transactions
                if (in_array($tx_type, ['NFTokenAcceptOffer', 'NFTokenCreateOffer', 'NFTokenBurn'])) {
                    // Get the other party
                    $other = $tx['Account'] ?? '';
                    if ($other && strtolower($other) !== strtolower($account) && !in_array($other, $trading_partners)) {
                        $trading_partners[] = $other;
                    }
                }
                
                // Also check Payment transactions (might be from NFT sales/purchases)
                if ($tx_type === 'Payment') {
                    $dest = $tx['Destination'] ?? '';
                    $sender = $tx['Account'] ?? '';
                    if ($sender !== $account && !in_array($sender, $trading_partners)) {
                        $trading_partners[] = $sender;
                    }
                    if ($dest !== $account && !in_array($dest, $trading_partners)) {
                        $trading_partners[] = $dest;
                    }
                }
                
                // Limit to 10 partners to avoid too many API calls
                if (count($trading_partners) >= 10) break;
            }
        }
        
        error_log("get_dashboard_offers: Found " . count($trading_partners) . " recent trading partners");
        
        // Check each trading partner for offers destined to us
        $existing_offer_ids = array_column($result['incoming_transfers'], 'offer_id');
        
        foreach ($trading_partners as $partner) {
            $partner_result = xrpl_rpc('account_objects', [
                'account' => $partner,
                'type' => 'nft_offer',
                'ledger_index' => 'validated',
                'limit' => 50
            ], 8);
            
            if (isset($partner_result['error']) || !isset($partner_result['result']['account_objects'])) {
                continue;
            }
            
            foreach ($partner_result['result']['account_objects'] as $obj) {
                $dest = $obj['Destination'] ?? null;
                
                // Is this offer destined to me?
                if (!$dest || strtolower($dest) !== strtolower($account)) {
                    continue;
                }
                
                $offer_id = $obj['index'] ?? '';
                if (in_array($offer_id, $existing_offer_ids)) {
                    continue;
                }
                
                $nft_id = $obj['NFTokenID'] ?? '';
                $flags = $obj['Flags'] ?? 0;
                $is_sell = ($flags & 1) === 1;
                
                // We only want SELL offers (gifts being sent to us)
                if (!$is_sell) continue;
                
                $amt = $obj['Amount'] ?? '0';
                if (is_string($amt)) {
                    $amount = (float)$amt / 1000000;
                    $currency = 'XRP';
                } else {
                    $amount = (float)($amt['value'] ?? 0);
                    $currency = $amt['currency'] ?? 'XRP';
                }
                
                $meta = get_nft_metadata($nft_id);
                
                $result['incoming_transfers'][] = [
                    'offer_id' => $offer_id,
                    'nft_id' => $nft_id,
                    'nft_name' => $meta['nft_name'] ?? $meta['name'] ?? 'Incoming NFT',
                    'nft_image' => $meta['image'] ?? '/wp-content/uploads/fallback-nft.svg',
                    'type' => 'incoming_transfer',
                    'is_gift' => ($amount == 0),
                    'amount' => $amount,
                    'currency' => $currency,
                    'from' => $partner,
                    'offerer' => $partner,
                    'expiration' => $obj['Expiration'] ?? null,
                    'status' => 'active',
                    'source' => 'trading_partner',
                    'accept_type' => 'sell_offer'
                ];
                
                $existing_offer_ids[] = $offer_id;
            }
        }
        
        error_log("get_dashboard_offers: Final incoming transfer count: " . count($result['incoming_transfers']));
    }
    
    // ===== STEP 3: Check for OFFERS RECEIVED (buy offers on NFTs I own) =====
    error_log("get_dashboard_offers: Fetching owned NFTs to check for received offers");
    
    $my_nfts = [];
    $marker = null;
    $page = 0;
    
    do {
        $params = [
            'account' => $account,
            'ledger_index' => 'validated',
            'limit' => 400
        ];
        if ($marker) $params['marker'] = $marker;
        
        $nft_result = xrpl_rpc('account_nfts', $params, 15);
        
        if (!isset($nft_result['error']) && isset($nft_result['result']['account_nfts'])) {
            $my_nfts = array_merge($my_nfts, $nft_result['result']['account_nfts']);
            $marker = $nft_result['result']['marker'] ?? null;
        } else {
            break;
        }
        $page++;
    } while ($marker && $page < 2 && count($my_nfts) < $max_nfts);
    
    // Limit to max_nfts
    $my_nfts = array_slice($my_nfts, 0, $max_nfts);
    error_log("get_dashboard_offers: Checking " . count($my_nfts) . " owned NFTs for buy offers");
    
    // Check each NFT for buy offers
    $checked = 0;
    foreach ($my_nfts as $nft) {
        $nft_id = $nft['NFTokenID'] ?? '';
        if (!$nft_id) continue;
        
        $checked++;
        if ($checked % 20 === 0) {
            error_log("get_dashboard_offers: Progress - checked $checked NFTs for buy offers");
        }
        
        $buy_result = xrpl_rpc('nft_buy_offers', [
            'nft_id' => $nft_id,
            'ledger_index' => 'validated'
        ], 5);
        
        if (isset($buy_result['error']) || !isset($buy_result['result']['offers'])) {
            continue;
        }
        
        $transfer_fee = $nft['TransferFee'] ?? 0;
        $royalty_percent = $transfer_fee / 1000;
        
        foreach ($buy_result['result']['offers'] as $offer) {
            $offerer = $offer['owner'] ?? '';
            
            // Skip my own offers
            if (strtolower($offerer) === strtolower($account)) continue;
            
            $amt = $offer['amount'] ?? '0';
            if (is_string($amt)) {
                $amount = (float)$amt / 1000000;
                $currency = 'XRP';
            } else {
                $amount = (float)($amt['value'] ?? 0);
                $currency = $amt['currency'] ?? 'XRP';
            }
            
            $royalty_amount = $amount * ($royalty_percent / 100);
            $net_amount = $amount - $royalty_amount;
            
            $meta = get_nft_metadata($nft_id);
            
            $result['offers_received'][] = [
                'offer_id' => $offer['nft_offer_index'] ?? '',
                'amount_issuer' => (is_array($amt) ? ($amt['issuer'] ?? null) : null), /* P2-J1 */
                'nft_id' => $nft_id,
                'nft_name' => $meta['nft_name'] ?? $meta['name'] ?? 'Unnamed NFT',
                'nft_image' => $meta['image'] ?? '/wp-content/uploads/fallback-nft.svg',
                'type' => ($amount == 0) ? 'transfer_request' : 'buy',
                'is_transfer_request' => ($amount == 0),
                'amount' => $amount,
                'currency' => $currency,
                'offerer' => $offerer,
                'from' => $offerer,
                'destination' => $offer['destination'] ?? null,
                'expiration' => $offer['expiration'] ?? null,
                'royalty_percent' => $royalty_percent,
                'royalty_amount' => round($royalty_amount, 6),
                'net_amount' => round($net_amount, 6),
                'status' => 'active',
                'source' => 'ledger',
                'accept_type' => 'buy_offer'  // We accept their BUY offer
            ];
        }
    }
    
    error_log("get_dashboard_offers: Found " . count($result['offers_received']) . " offers received");
    
    // ===== STEP 4: Deduplicate =====
    $seen = [];
    foreach (['incoming_transfers', 'offers_received'] as $category) {
        $unique = [];
        foreach ($result[$category] as $offer) {
            $oid = $offer['offer_id'] ?? '';
            if ($oid && !isset($seen[$oid])) {
                $seen[$oid] = true;
                $unique[] = $offer;
            }
        }
        $result[$category] = $unique;
    }

    // -- Phase 4C: hide counterparty offers/transfers from blacklisted or scam-flagged wallets --
    // Single post-build pass (after dedup) over every method's output (A-E); keys on offerer/from.
    // Rows lacking both are kept (safe default). User's-own buckets are not filtered.
    if (!empty($blacklisted) || !empty($scam_issuers)) {
        $blk_do = function($w) use ($blacklisted, $scam_issuers) { $w = strtolower((string)$w); return (!empty($blacklisted) && in_array($w, $blacklisted)) || (!empty($scam_issuers) && in_array($w, $scam_issuers)); };
        foreach (['offers_received', 'incoming_transfers'] as $bucket_4c) {
            if (!empty($result[$bucket_4c])) {
                $result[$bucket_4c] = array_values(array_filter($result[$bucket_4c], function($o) use ($blk_do) {
                    return !$blk_do($o['offerer'] ?? $o['from'] ?? '');
                }));
            }
        }
    }
    
    // ===== STEP 5: Calculate totals and return =====
    $result['totals'] = [
        'incoming_transfers' => count($result['incoming_transfers']),
        'outgoing_transfers' => count($result['outgoing_transfers']),
        'offers_received' => count($result['offers_received']),
        'listed_items' => count($result['listed_items']),
        'offers_made' => count($result['offers_made'])
    ];
    
    $elapsed = round((microtime(true) - $start_time) * 1000);
    $result['elapsed_ms'] = $elapsed;
    $result['nfts_checked'] = $checked;
    
    error_log("get_dashboard_offers: Completed in {$elapsed}ms - " . json_encode($result['totals']));
    
    wp_send_json($result);
    exit;
}


/** GET: diagnose_offers - Diagnostic endpoint to check what data sources return (Admin only) */

// ═══════════════════════════════════════════════════════════════════════════
// v257: sync_nft_offers — Cross-reference DB offers for one NFT with XRPL
//
// Fetches live nft_sell_offers + nft_buy_offers from XRPL, marks any DB rows
// that are no longer on-chain as 'cancelled'. Also surfaces ledger offers
// that were created through third-party tools (not in our DB).
//
// Returns:
//   stale_count         — how many DB rows were just marked cancelled
//   sell_offers_live    — current SELL offers on-chain for this NFT
//   buy_offers_live     — current BUY offers on-chain for this NFT
//   db_offer_ids        — set of offer_ids from our DB (for UI reconciliation)
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['action'] ?? '') === 'sync_nft_offers')) {
    $nonce  = sanitize_text_field($_GET['nonce'] ?? '');
    $nft_id = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $_GET['nft_id'] ?? ''));

    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        wp_send_json_error(['error' => 'Invalid nonce'], 403); exit;
    }
    if (strlen($nft_id) !== 64) {
        wp_send_json_error(['error' => 'Invalid nft_id'], 400); exit;
    }

    error_log("sync_nft_offers: Starting for NFT $nft_id");

    // ── Helper: parse XRPL Amount to human-readable ──────────────────────
    $parse_amount = function($amt) {
        if (is_string($amt)) {
            return ['amount' => round((float)$amt / 1000000, 6), 'currency' => 'XRP',
                    'currency_display' => 'XRP', 'issuer' => ''];
        }
        // v706: 'currency' left byte-identical (ledger wire form). 'currency_display'
        // and 'issuer' are ADDITIVE -- display + brokered-buy issuer plumbing.
        $c_v706 = $amt['currency'] ?? 'XRP';
        return ['amount' => (float)($amt['value'] ?? 0), 'currency' => $c_v706,
                'currency_display' => imc_currency_display($c_v706),
                'issuer' => $amt['issuer'] ?? ''];
    };

    // ── Fetch live sell offers from XRPL ─────────────────────────────────
    $sell_result = xrpl_rpc('nft_sell_offers', ['nft_id' => $nft_id, 'ledger_index' => 'validated'], 8);
    $live_sell   = $sell_result['result']['offers'] ?? [];
    $live_sell_ids = array_flip(array_filter(array_column($live_sell, 'nft_offer_index')));

    // ── Fetch live buy offers from XRPL ──────────────────────────────────
    $buy_result = xrpl_rpc('nft_buy_offers', ['nft_id' => $nft_id, 'ledger_index' => 'validated'], 8);
    $live_buy   = $buy_result['result']['offers'] ?? [];
    $live_buy_ids = array_flip(array_filter(array_column($live_buy, 'nft_offer_index')));

    error_log("sync_nft_offers: XRPL live sell=" . count($live_sell) . " buy=" . count($live_buy));

    // ── Load our DB's active offers for this NFT ──────────────────────────
    $db_active = $wpdb->get_results($wpdb->prepare(
        "SELECT id, offer_id, offer_type, amount, currency, offerer_account, synced_at
         FROM $offers_table
         WHERE target_nft_id = %s AND status = 'active' AND offer_id != ''",
        $nft_id
    ), ARRAY_A) ?: [];

    // Offers synced within the last 5 minutes — skip rechecking those rows
    $now      = time();
    $ttl      = 300; // 5 min cooldown per row
    $stale    = [];
    $db_ids   = [];

    foreach ($db_active as $row) {
        $oid = $row['offer_id'];
        $db_ids[] = $oid;

        // Respect sync TTL
        $last = $row['synced_at'] ? strtotime($row['synced_at']) : 0;
        if (($now - $last) < $ttl) {
            continue; // recently verified — trust it
        }

        $on_ledger = ($row['offer_type'] === 'sell')
            ? isset($live_sell_ids[$oid])
            : isset($live_buy_ids[$oid]);

        if (!$on_ledger) {
            // Not on ledger — mark stale in DB
            $wpdb->update(
                $offers_table,
                ['status' => 'cancelled', 'synced_at' => current_time('mysql')],
                ['id' => $row['id']]
            );
            $stale[] = $oid;
            error_log("sync_nft_offers: Marked stale offer_id=$oid as cancelled");
        } else {
            // Still on ledger — update synced_at
            $wpdb->update(
                $offers_table,
                ['synced_at' => current_time('mysql')],
                ['id' => $row['id']]
            );
        }
    }

    // ── Format live sell offers for UI ────────────────────────────────────
    $sell_formatted = array_values(array_map(function($o) use ($parse_amount, $nft_id, $db_ids) {
        $a = $parse_amount($o['amount'] ?? '0');
        return [
            'offer_id'      => $o['nft_offer_index'] ?? '',
            'nft_id'        => $nft_id,
            'seller'        => $o['owner'] ?? '',
            'amount'        => $a['amount'],
            'currency'      => $a['currency'],
            'currency_display' => $a['currency_display'],
            'issuer'        => $a['issuer'],
            'destination'   => $o['destination'] ?? null,
            'expiration'    => $o['expiration'] ?? null,
            'in_our_db'     => in_array($o['nft_offer_index'] ?? '', $db_ids),
            'source'        => 'xrpl_ledger',
        ];
    }, $live_sell));

    // ── Format live buy offers for UI ─────────────────────────────────────
    $buy_formatted = array_values(array_map(function($o) use ($parse_amount, $nft_id, $db_ids) {
        $a = $parse_amount($o['amount'] ?? '0');
        return [
            'offer_id'      => $o['nft_offer_index'] ?? '',
            'nft_id'        => $nft_id,
            'buyer'         => $o['owner'] ?? '',
            'amount'        => $a['amount'],
            'currency'      => $a['currency'],
            'currency_display' => $a['currency_display'],
            'issuer'        => $a['issuer'],
            'destination'   => $o['destination'] ?? null,
            'expiration'    => $o['expiration'] ?? null,
            'in_our_db'     => in_array($o['nft_offer_index'] ?? '', $db_ids),
            'source'        => 'xrpl_ledger',
        ];
    }, $live_buy));

    // -- Phase 4C: hide live offers from blacklisted or scam-flagged wallets (fail-open) --
    $bl_4c = imc_get_blacklisted_wallets();
    $scam_4c = imc_get_scam_issuers();
    if (!empty($bl_4c) || !empty($scam_4c)) {
        $blk_4c = function($w) use ($bl_4c, $scam_4c) { $w = strtolower((string)$w); return (!empty($bl_4c) && in_array($w, $bl_4c)) || (!empty($scam_4c) && in_array($w, $scam_4c)); };
        $pay_4c = function($o) use ($scam_4c) { $i = strtolower((string)($o['issuer'] ?? '')); return $i !== '' && !empty($scam_4c) && in_array($i, $scam_4c); }; /* P2-J1: payment dimension */
        $sell_formatted = array_values(array_filter($sell_formatted, fn($o) => !$blk_4c($o['seller'] ?? '') && !$pay_4c($o)));
        $buy_formatted  = array_values(array_filter($buy_formatted,  fn($o) => !$blk_4c($o['buyer'] ?? '') && !$pay_4c($o)));
    }

    error_log("sync_nft_offers: Done. stale=" . count($stale));

    wp_send_json([
        'success'          => true,
        'nft_id'           => $nft_id,
        'stale_count'      => count($stale),
        'stale_offer_ids'  => $stale,
        'sell_offers_live' => $sell_formatted,
        'buy_offers_live'  => $buy_formatted,
        'db_offer_ids'     => $db_ids,
        'synced_at'        => date('c'),
    ]);
    exit;
}


// ═══════════════════════════════════════════════════════════════════════════
// v257: sync_account_offers — Reconcile an account's outgoing offers with XRPL
//
// Fetches account_objects (type=nft_offer) for the given wallet and marks
// any DB rows no longer on-chain as 'cancelled'. Called by the dashboard
// before rendering to prevent showing ghost listings.
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['action'] ?? '') === 'sync_account_offers')) {
    $nonce   = sanitize_text_field($_GET['nonce'] ?? '');
    $account = sanitize_text_field($_GET['account'] ?? '');

    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        wp_send_json_error(['error' => 'Invalid nonce'], 403); exit;
    }
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        wp_send_json_error(['error' => 'Invalid account'], 400); exit;
    }

    error_log("sync_account_offers: Starting for $account");

    // ── Pull all on-chain NFT offer objects for this account ─────────────
    $live_ids = [];
    $marker   = null;
    $pages    = 0;

    do {
        $params = ['account' => $account, 'type' => 'nft_offer', 'ledger_index' => 'validated', 'limit' => 200];
        if ($marker) $params['marker'] = $marker;
        $res = xrpl_rpc('account_objects', $params, 12);
        foreach ($res['result']['account_objects'] ?? [] as $obj) {
            if (!empty($obj['index'])) $live_ids[$obj['index']] = true;
        }
        $marker = $res['result']['marker'] ?? null;
        $pages++;
    } while ($marker && $pages < 5);

    error_log("sync_account_offers: " . count($live_ids) . " live offer objects on ledger");

    // ── DB active offers made BY this account ─────────────────────────────
    $db_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, offer_id, target_nft_id, offer_type, synced_at
         FROM $offers_table
         WHERE offerer_account = %s AND status = 'active' AND offer_id != ''",
        $account
    ), ARRAY_A) ?: [];

    $now  = time();
    $ttl  = 300;
    $stale = [];

    foreach ($db_rows as $row) {
        $last = $row['synced_at'] ? strtotime($row['synced_at']) : 0;
        if (($now - $last) < $ttl) continue;

        if (!isset($live_ids[$row['offer_id']])) {
            $wpdb->update(
                $offers_table,
                ['status' => 'cancelled', 'synced_at' => current_time('mysql')],
                ['id' => $row['id']]
            );
            $stale[] = ['offer_id' => $row['offer_id'], 'nft_id' => $row['target_nft_id'], 'type' => $row['offer_type']];
            error_log("sync_account_offers: Stale {$row['offer_type']} offer {$row['offer_id']} marked cancelled");
        } else {
            $wpdb->update($offers_table, ['synced_at' => current_time('mysql')], ['id' => $row['id']]);
        }
    }

    error_log("sync_account_offers: Done. stale=" . count($stale));

    wp_send_json([
        'success'     => true,
        'account'     => $account,
        'stale_count' => count($stale),
        'stale'       => $stale,
        'live_count'  => count($live_ids),
        'synced_at'   => date('c'),
    ]);
    exit;
}


if ($_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['action'] ?? '') === 'diagnose_offers')) {
    // v94: Admin-only diagnostic endpoint
    if (!current_user_can('manage_options')) {
        status_header(403);
        wp_send_json(['success' => false, 'error' => 'Admin access required']);
        exit;
    }
    
    global $BITHOMP_TOKEN;
    $account = sanitize_text_field($_GET['account'] ?? '');
    $sender = sanitize_text_field($_GET['sender'] ?? ''); // Optional: check specific sender
    $nft_id = sanitize_text_field($_GET['nft_id'] ?? ''); // Optional: check specific NFT
    
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        wp_send_json(['success' => false, 'error' => 'Invalid account']);
        exit;
    }
    
    $results = [
        'account' => $account,
        'bithomp_token_set' => !empty($BITHOMP_TOKEN),
        'tests' => []
    ];
    
    // Test 1: Get account NFTs count via XRPL
    $start = microtime(true);
    $nfts_result = xrpl_rpc('account_nfts', ['account' => $account, 'ledger_index' => 'validated', 'limit' => 50], 15);
    $nfts_list = $nfts_result['result']['account_nfts'] ?? [];
    $results['tests']['xrpl_account_nfts'] = [
        'success' => !isset($nfts_result['error']),
        'nft_count' => count($nfts_list),
        'has_marker' => isset($nfts_result['result']['marker']),
        'time_ms' => round((microtime(true) - $start) * 1000)
    ];
    
    // Test 2: Check first 5 NFTs for BUY offers (offers TO BUY NFTs you own)
    $results['tests']['xrpl_buy_offers_on_my_nfts'] = [
        'nfts_checked' => 0,
        'offers_found' => 0,
        'details' => [],
        'time_ms' => 0
    ];
    
    $start = microtime(true);
    $check_count = min(5, count($nfts_list));
    for ($i = 0; $i < $check_count; $i++) {
        $nid = $nfts_list[$i]['NFTokenID'] ?? '';
        if (!$nid) continue;
        
        $buy_offers = xrpl_rpc('nft_buy_offers', ['nft_id' => $nid, 'ledger_index' => 'validated'], 5);
        $results['tests']['xrpl_buy_offers_on_my_nfts']['nfts_checked']++;
        
        $offers = $buy_offers['result']['offers'] ?? [];
        if (!empty($offers)) {
            $results['tests']['xrpl_buy_offers_on_my_nfts']['offers_found'] += count($offers);
            foreach ($offers as $o) {
                $amt = $o['amount'] ?? '0';
                $results['tests']['xrpl_buy_offers_on_my_nfts']['details'][] = [
                    'nft_id' => substr($nid, 0, 20) . '...',
                    'offer_index' => $o['nft_offer_index'] ?? '',
                    'owner' => $o['owner'] ?? '',
                    'amount' => is_string($amt) ? ((float)$amt / 1000000) . ' XRP' : ($amt['value'] ?? '0') . ' ' . ($amt['currency'] ?? '?'),
                    'is_zero' => (is_string($amt) && $amt === '0') || (!is_string($amt) && ($amt['value'] ?? '0') == '0'),
                    'destination' => $o['destination'] ?? null
                ];
            }
        }
    }
    $results['tests']['xrpl_buy_offers_on_my_nfts']['time_ms'] = round((microtime(true) - $start) * 1000);
    
    // Test 3: Check for SELL offers destined TO this account (incoming gifts/airdrops)
    // This is the MISSING case - we need to find sell offers where Destination = our account
    $results['tests']['incoming_gift_offers'] = [
        'description' => 'Sell offers from others where YOU are the destination (airdrops/gifts)',
        'method' => 'checking sender account if provided',
        'offers_found' => 0,
        'details' => [],
        'time_ms' => 0
    ];
    
    if ($sender && preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $sender)) {
        // Check the sender's account_objects for NFT offers destined to us
        $start = microtime(true);
        $sender_objects = xrpl_rpc('account_objects', [
            'account' => $sender,
            'type' => 'nft_offer',
            'ledger_index' => 'validated',
            'limit' => 50
        ], 10);
        
        if (!isset($sender_objects['error']) && isset($sender_objects['result']['account_objects'])) {
            foreach ($sender_objects['result']['account_objects'] as $obj) {
                $dest = $obj['Destination'] ?? null;
                $flags = $obj['Flags'] ?? 0;
                $is_sell = ($flags & 1) === 1;
                
                // Check if this is a sell offer destined to us
                if ($dest && strtolower($dest) === strtolower($account)) {
                    $amt = $obj['Amount'] ?? '0';
                    $results['tests']['incoming_gift_offers']['offers_found']++;
                    $results['tests']['incoming_gift_offers']['details'][] = [
                        'offer_index' => $obj['index'] ?? '',
                        'nft_id' => $obj['NFTokenID'] ?? '',
                        'from' => $sender,
                        'destination' => $dest,
                        'is_sell_offer' => $is_sell,
                        'amount' => is_string($amt) ? ((float)$amt / 1000000) . ' XRP' : ($amt['value'] ?? '0') . ' ' . ($amt['currency'] ?? '?'),
                        'is_zero' => (is_string($amt) && $amt === '0'),
                        'flags' => $flags
                    ];
                }
            }
        }
        $results['tests']['incoming_gift_offers']['time_ms'] = round((microtime(true) - $start) * 1000);
    } else {
        $results['tests']['incoming_gift_offers']['note'] = 'Add ?sender=rXXX to check a specific sender for gifts to you';
    }
    
    // Test 4: If specific NFT ID provided, check both buy and sell offers
    if ($nft_id && strlen($nft_id) === 64) {
        $results['tests']['specific_nft'] = [
            'nft_id' => $nft_id,
            'buy_offers' => [],
            'sell_offers' => []
        ];
        
        // Check buy offers
        $buy = xrpl_rpc('nft_buy_offers', ['nft_id' => $nft_id, 'ledger_index' => 'validated'], 5);
        foreach (($buy['result']['offers'] ?? []) as $o) {
            $amt = $o['amount'] ?? '0';
            $results['tests']['specific_nft']['buy_offers'][] = [
                'offer_index' => $o['nft_offer_index'] ?? '',
                'owner' => $o['owner'] ?? '',
                'amount' => is_string($amt) ? ((float)$amt / 1000000) . ' XRP' : ($amt['value'] ?? '0') . ' ' . ($amt['currency'] ?? '?'),
                'destination' => $o['destination'] ?? null
            ];
        }
        
        // Check sell offers
        $sell = xrpl_rpc('nft_sell_offers', ['nft_id' => $nft_id, 'ledger_index' => 'validated'], 5);
        foreach (($sell['result']['offers'] ?? []) as $o) {
            $amt = $o['amount'] ?? '0';
            $results['tests']['specific_nft']['sell_offers'][] = [
                'offer_index' => $o['nft_offer_index'] ?? '',
                'owner' => $o['owner'] ?? '',
                'amount' => is_string($amt) ? ((float)$amt / 1000000) . ' XRP' : ($amt['value'] ?? '0') . ' ' . ($amt['currency'] ?? '?'),
                'destination' => $o['destination'] ?? null,
                'is_for_me' => strtolower($o['destination'] ?? '') === strtolower($account)
            ];
        }
    }
    
    // Test 5: Bithomp check
    if ($BITHOMP_TOKEN) {
        $start = microtime(true);
        $bithomp_url = "https://bithomp.com/api/v2/nfts/$account?buyOffers=true&sellOffers=true&limit=20";
        $bithomp_res = wp_remote_get($bithomp_url, [
            'headers' => ['x-bithomp-token' => $BITHOMP_TOKEN],
            'timeout' => 15
        ]);
        $results['tests']['bithomp_nfts'] = [
            'success' => !is_wp_error($bithomp_res),
            'http_code' => wp_remote_retrieve_response_code($bithomp_res),
            'nft_count' => 0,
            'nfts_with_buy_offers' => 0,
            'time_ms' => round((microtime(true) - $start) * 1000)
        ];
        if (!is_wp_error($bithomp_res)) {
            $body = json_decode(wp_remote_retrieve_body($bithomp_res), true);
            $nfts = $body['nfts'] ?? [];
            $results['tests']['bithomp_nfts']['nft_count'] = count($nfts);
            foreach ($nfts as $nft) {
                if (!empty($nft['buyOffers'])) {
                    $results['tests']['bithomp_nfts']['nfts_with_buy_offers']++;
                }
            }
        }
    }
    
    wp_send_json(['success' => true, 'diagnostics' => $results]);
    exit;
}


/** GET: get_my_outgoing_offers - Uses XRPL with pagination */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['action'] ?? '') === 'get_my_outgoing_offers')) {
    $nonce   = sanitize_text_field($_GET['nonce'] ?? '');
    $account = sanitize_text_field($_GET['account'] ?? '');
    $source_pref = sanitize_text_field($_GET['source'] ?? 'auto'); // auto, bithomp, ledger
    
    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        status_header(403);
        wp_send_json(['success' => false, 'error' => 'Invalid nonce']);
    }
    
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        status_header(400);
        wp_send_json(['success' => false, 'error' => 'Invalid account']);
    }
    
    error_log("get_my_outgoing_offers: Starting for $account (source: $source_pref)");
    
    $offers = [];
    $source_used = 'ledger';
    
    // Try XRPL ledger with pagination (most reliable for outgoing)
    $offers = fetch_outgoing_offers_via_xrpl($account);
    $source_used = 'xrpl_ledger';
    
    error_log("get_my_outgoing_offers: Found " . count($offers) . " outgoing offers via $source_used");
    
    wp_send_json([
        'success' => true,
        'offers' => $offers,
        'count' => count($offers),
        'source' => $source_used
    ]);
    exit;
}


/** GET: get_incoming_gifts - Check for SELL offers from others destined TO this account */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['action'] ?? '') === 'get_incoming_gifts')) {
    $nonce   = sanitize_text_field($_GET['nonce'] ?? '');
    $account = sanitize_text_field($_GET['account'] ?? '');
    $senders = sanitize_text_field($_GET['senders'] ?? ''); // Comma-separated list of sender accounts to check
    
    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        status_header(403);
        wp_send_json(['success' => false, 'error' => 'Invalid nonce']);
    }
    
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        status_header(400);
        wp_send_json(['success' => false, 'error' => 'Invalid account']);
    }
    
    error_log("get_incoming_gifts: Checking for sell offers destined TO $account");
    $start_time = microtime(true);
    
    $gifts = [];
    
    // If specific senders provided, check their account_objects for offers destined to us
    if ($senders) {
        $sender_list = array_filter(array_map('trim', explode(',', $senders)));
        
        foreach ($sender_list as $sender) {
            if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $sender)) continue;
            
            error_log("get_incoming_gifts: Checking sender $sender for offers to $account");
            
            $sender_objects = xrpl_rpc('account_objects', [
                'account' => $sender,
                'type' => 'nft_offer',
                'ledger_index' => 'validated',
                'limit' => 100
            ], 10);
            
            if (isset($sender_objects['error']) || !isset($sender_objects['result']['account_objects'])) {
                continue;
            }
            
            foreach ($sender_objects['result']['account_objects'] as $obj) {
                $dest = $obj['Destination'] ?? null;
                
                // Check if this offer is destined to us
                if ($dest && strtolower($dest) === strtolower($account)) {
                    $nft_id = $obj['NFTokenID'] ?? '';
                    $flags = $obj['Flags'] ?? 0;
                    $is_sell = ($flags & 1) === 1;
                    
                    $amt = $obj['Amount'] ?? '0';
                    if (is_string($amt)) {
                        $amount = (float)$amt / 1000000;
                        $currency = 'XRP';
                    } else {
                        $amount = (float)($amt['value'] ?? 0);
                        $currency = $amt['currency'] ?? 'XRP';
                    }
                    
                    $is_free = ($amount == 0);
                    $meta = get_nft_metadata($nft_id);
                    
                    $gifts[] = [
                        'offer_id' => $obj['index'] ?? '',
                        'nft_id' => $nft_id,
                        'nft_name' => $meta['nft_name'] ?? $meta['name'] ?? 'Incoming NFT',
                        'nft_image' => $meta['image'] ?? '/wp-content/uploads/fallback-nft.svg',
                        'type' => $is_sell ? 'gift' : 'buy_request',
                        'is_sell_offer' => $is_sell,
                        'is_gift' => $is_sell && $is_free,
                        'amount' => $amount,
                        'currency' => $currency,
                        'offerer' => $sender,
                        'destination' => $account,
                        'expiration' => $obj['Expiration'] ?? null,
                        'status' => 'active',
                        'source' => 'ledger',
                        'accept_type' => $is_sell ? 'sell_offer' : 'buy_offer'
                    ];
                }
            }
        }
    }
    
    // Also try Bithomp for incoming offers
    $bithomp_gifts = fetch_incoming_gift_offers($account);
    
    // Merge and dedupe
    $all_gifts = array_merge($gifts, $bithomp_gifts);
    $seen = [];
    $unique_gifts = [];
    foreach ($all_gifts as $g) {
        $oid = $g['offer_id'] ?? '';
        if ($oid && !isset($seen[$oid])) {
            $seen[$oid] = true;
            $unique_gifts[] = $g;
        }
    }
    
    $elapsed = round((microtime(true) - $start_time) * 1000);
    error_log("get_incoming_gifts: Found " . count($unique_gifts) . " gift offers in {$elapsed}ms");
    
    wp_send_json([
        'success' => true,
        'offers' => $unique_gifts,
        'count' => count($unique_gifts),
        'elapsed_ms' => $elapsed
    ]);
    exit;
}


/** GET: get_my_incoming_offers - Gets buy offers on your NFTs AND incoming gifts */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['action'] ?? '') === 'get_my_incoming_offers')) {
    global $BITHOMP_TOKEN;
    $nonce   = sanitize_text_field($_GET['nonce'] ?? '');
    $account = sanitize_text_field($_GET['account'] ?? '');
    $source_pref = sanitize_text_field($_GET['source'] ?? 'auto'); // auto, bithomp, ledger
    $check_senders = sanitize_text_field($_GET['senders'] ?? ''); // Optional: comma-separated senders to check for gifts
    
    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        status_header(403);
        wp_send_json(['success' => false, 'error' => 'Invalid nonce']);
    }
    
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        status_header(400);
        wp_send_json(['success' => false, 'error' => 'Invalid account']);
    }
    
    error_log("get_my_incoming_offers: Starting for $account (source: $source_pref)");
    $start_time = microtime(true);
    
    $all_offers = [];
    $source_used = 'xrpl_ledger';
    
    // ===== PART 1: Get buy offers on NFTs this account OWNS =====
    $buy_offers_on_my_nfts = [];
    $bithomp_success = false;
    
    // Try Bithomp's NFTs endpoint with buyOffers flag
    if ($source_pref !== 'ledger' && $BITHOMP_TOKEN) {
        error_log("get_my_incoming_offers: Trying Bithomp NFTs API with buyOffers");
        
        $bithomp_url = "https://bithomp.com/api/v2/nfts/$account?buyOffers=true&sellOffers=true&limit=200";
        $bithomp_res = wp_remote_get($bithomp_url, [
            'headers' => ['x-bithomp-token' => $BITHOMP_TOKEN],
            'timeout' => 20
        ]);
        
        if (!is_wp_error($bithomp_res)) {
            $http_code = wp_remote_retrieve_response_code($bithomp_res);
            $body = json_decode(wp_remote_retrieve_body($bithomp_res), true);
            
            error_log("get_my_incoming_offers: Bithomp NFTs HTTP $http_code, got " . count($body['nfts'] ?? []) . " NFTs");
            
            if ($http_code === 200 && isset($body['nfts'])) {
                foreach ($body['nfts'] as $nft) {
                    $buy_offers = $nft['buyOffers'] ?? [];
                    if (empty($buy_offers)) continue;
                    
                    $nft_id = $nft['nftokenID'] ?? '';
                    $transfer_fee = $nft['transferFee'] ?? 0;
                    $royalty_percent = $transfer_fee / 1000;
                    
                    foreach ($buy_offers as $offer) {
                        $offerer = $offer['owner'] ?? ($offer['account'] ?? '');
                        if (strtolower($offerer) === strtolower($account)) continue;
                        
                        $amount_raw = $offer['amount'] ?? '0';
                        if (is_string($amount_raw)) {
                            $amount = (float)$amount_raw / 1000000;
                            $currency = 'XRP';
                        } else {
                            $amount = (float)($amount_raw['value'] ?? 0);
                            $currency = $amount_raw['currency'] ?? 'XRP';
                        }
                        
                        $is_transfer = ($amount == 0);
                        $royalty_amount = $amount * ($royalty_percent / 100);
                        $net_amount = $amount - $royalty_amount;
                        
                        $nft_name = $nft['metadata']['name'] ?? ($nft['nftName'] ?? 'Unnamed NFT');
                        $nft_image = $nft['assets']['image'] ?? ($nft['metadata']['image'] ?? ($nft['image'] ?? '/wp-content/uploads/fallback-nft.svg'));
                        
                        $buy_offers_on_my_nfts[] = [
                            'offer_id' => $offer['offerIndex'] ?? ($offer['index'] ?? ''),
                            'nft_id' => $nft_id,
                            'nft_name' => $nft_name,
                            'nft_image' => $nft_image,
                            'type' => $is_transfer ? 'transfer_request' : 'buy',
                            'is_transfer' => $is_transfer,
                            'is_gift' => false,
                            'amount' => $amount,
                            'currency' => $currency,
                            'offerer' => $offerer,
                            'destination' => $offer['destination'] ?? null,
                            'expiration' => $offer['expiration'] ?? null,
                            'royalty_percent' => $royalty_percent,
                            'royalty_amount' => round($royalty_amount, 6),
                            'net_amount' => round($net_amount, 6),
                            'status' => 'active',
                            'source' => 'bithomp',
                            'accept_type' => 'buy_offer'
                        ];
                    }
                }
                
                if (!empty($buy_offers_on_my_nfts)) {
                    $source_used = 'bithomp';
                    $bithomp_success = true;
                }
            }
        }
    }
    
    // Fallback to XRPL for buy offers on my NFTs
    if (!$bithomp_success && $source_pref !== 'bithomp') {
        error_log("get_my_incoming_offers: Falling back to XRPL ledger for buy offers on owned NFTs");
        $buy_offers_on_my_nfts = fetch_incoming_offers_via_xrpl_v2($account);
        $source_used = 'xrpl_ledger';
    }
    
    $all_offers = array_merge($all_offers, $buy_offers_on_my_nfts);
    error_log("get_my_incoming_offers: Found " . count($buy_offers_on_my_nfts) . " buy offers on owned NFTs");
    
    // ===== PART 2: Check for INCOMING GIFTS (sell offers destined to this account) =====
    // This requires knowing potential senders - check if any were provided
    $gift_offers = [];
    
    if ($check_senders) {
        $sender_list = array_filter(array_map('trim', explode(',', $check_senders)));
        error_log("get_my_incoming_offers: Checking " . count($sender_list) . " senders for gifts");
        
        foreach ($sender_list as $sender) {
            if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $sender)) continue;
            
            $sender_objects = xrpl_rpc('account_objects', [
                'account' => $sender,
                'type' => 'nft_offer',
                'ledger_index' => 'validated',
                'limit' => 50
            ], 10);
            
            if (isset($sender_objects['error'])) continue;
            
            foreach (($sender_objects['result']['account_objects'] ?? []) as $obj) {
                $dest = $obj['Destination'] ?? null;
                if (!$dest || strtolower($dest) !== strtolower($account)) continue;
                
                $nft_id = $obj['NFTokenID'] ?? '';
                $flags = $obj['Flags'] ?? 0;
                $is_sell = ($flags & 1) === 1;
                
                $amt = $obj['Amount'] ?? '0';
                if (is_string($amt)) {
                    $amount = (float)$amt / 1000000;
                    $currency = 'XRP';
                } else {
                    $amount = (float)($amt['value'] ?? 0);
                    $currency = $amt['currency'] ?? 'XRP';
                }
                
                $is_free = ($amount == 0);
                $meta = get_nft_metadata($nft_id);
                
                $gift_offers[] = [
                    'offer_id' => $obj['index'] ?? '',
                    'nft_id' => $nft_id,
                    'nft_name' => $meta['nft_name'] ?? $meta['name'] ?? 'Incoming NFT',
                    'nft_image' => $meta['image'] ?? '/wp-content/uploads/fallback-nft.svg',
                    'type' => $is_sell ? ($is_free ? 'gift' : 'sale_to_me') : 'buy_request',
                    'is_transfer' => $is_free,
                    'is_gift' => $is_sell && $is_free,
                    'amount' => $amount,
                    'currency' => $currency,
                    'offerer' => $sender,
                    'destination' => $account,
                    'expiration' => $obj['Expiration'] ?? null,
                    'royalty_percent' => 0,
                    'royalty_amount' => 0,
                    'net_amount' => 0,
                    'status' => 'active',
                    'source' => 'ledger',
                    'accept_type' => $is_sell ? 'sell_offer' : 'buy_offer'
                ];
            }
        }
    }
    
    // Also check Bithomp for incoming gifts
    $bithomp_gifts = fetch_incoming_gift_offers($account);
    $gift_offers = array_merge($gift_offers, $bithomp_gifts);
    
    error_log("get_my_incoming_offers: Found " . count($gift_offers) . " incoming gift offers");
    $all_offers = array_merge($all_offers, $gift_offers);
    
    // Remove duplicates by offer_id
    $seen_offers = [];
    $unique_offers = [];
    foreach ($all_offers as $offer) {
        $oid = $offer['offer_id'] ?? '';
        if ($oid && !isset($seen_offers[$oid])) {
            $seen_offers[$oid] = true;
            $unique_offers[] = $offer;
        } elseif (!$oid) {
            $unique_offers[] = $offer;
        }
    }
    
    $elapsed = round((microtime(true) - $start_time) * 1000);
    error_log("get_my_incoming_offers: Completed in {$elapsed}ms with " . count($unique_offers) . " total offers");
    
    wp_send_json([
        'success' => true,
        'offers' => $unique_offers,
        'count' => count($unique_offers),
        'breakdown' => [
            'buy_offers_on_my_nfts' => count($buy_offers_on_my_nfts),
            'gift_offers' => count($gift_offers)
        ],
        'source' => $source_used,
        'elapsed_ms' => $elapsed,
        'note' => empty($check_senders) ? 'Add ?senders=rXXX,rYYY to check specific accounts for gifts to you' : null
    ]);
    exit;
}


/** GET: get_all_offers - Uses new helper functions with pagination */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['action'] ?? '') === 'get_all_offers')) {
    $nonce   = sanitize_text_field($_GET['nonce'] ?? '');
    $account = sanitize_text_field($_GET['account'] ?? '');
    
    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        status_header(403);
        wp_send_json(['success' => false, 'error' => 'Invalid nonce']);
    }
    
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        status_header(400);
        wp_send_json(['success' => false, 'error' => 'Invalid account']);
    }
    
    error_log("get_all_offers: Starting for $account");
    
    // Use the improved functions with proper pagination
    $outgoing = fetch_outgoing_offers_via_xrpl($account);
    
    // For incoming, try Bithomp first then fall back to XRPL
    global $BITHOMP_TOKEN;
    $incoming = [];
    $source_used = 'xrpl_ledger';
    
    if ($BITHOMP_TOKEN) {
        $incoming = fetch_incoming_offers_via_bithomp($account);
        if (!empty($incoming)) {
            $source_used = 'bithomp';
        }
    }
    
    if (empty($incoming)) {
        $incoming = fetch_incoming_offers_via_xrpl($account);
        $source_used = 'xrpl_ledger';
    }
    
    error_log("get_all_offers: Found " . count($outgoing) . " outgoing, " . count($incoming) . " incoming via $source_used");
    
    wp_send_json([
        'success' => true,
        'outgoing' => $outgoing,
        'incoming' => $incoming,
        'counts' => [
            'outgoing' => count($outgoing),
            'incoming' => count($incoming)
        ],
        'source' => $source_used
    ]);
    exit;
}


/** GET: get_offers (Database-based - for historical data) */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['action'] ?? '') === 'get_offers')) {
    $nonce   = sanitize_text_field($_GET['nonce'] ?? '');
    $account = sanitize_text_field($_GET['account'] ?? '');
    $type    = sanitize_text_field($_GET['type'] ?? 'all'); // incoming, outgoing, all
    $status  = sanitize_text_field($_GET['status'] ?? 'active'); // active, pending, all, etc
    
    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        status_header(400);
        wp_send_json(['success' => false, 'error' => 'Invalid nonce']);
    }
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        status_header(400);
        wp_send_json(['success' => false, 'error' => 'Invalid account']);
    }
    
    $where = [];
    $params = [];
    
    if ($type === 'incoming') {
        $where[] = "target_account=%s AND offer_type='buy'";
        $params[] = $account;
    } elseif ($type === 'outgoing') {
        $where[] = "offerer_account=%s";
        $params[] = $account;
    } else {
        $where[] = "(target_account=%s OR offerer_account=%s)";
        $params[] = $account;
        $params[] = $account;
    }
    
    // Status filtering - default to only pending and active offers
    if ($status === 'all') {
        // Show pending and active only (not cancelled, rejected, accepted, expired, failed)
        $where[] = "status IN ('pending', 'active')";
    } elseif ($status === 'history') {
        // Show all statuses including historical
        // No status filter
    } else {
        $where[] = "status=%s";
        $params[] = $status;
    }
    
    $where_sql = implode(' AND ', $where);
    $sql = "SELECT * FROM $offers_table WHERE $where_sql ORDER BY created_at DESC LIMIT 100";
    
    $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: [];
    
    // Enrich with NFT metadata
    $offers = array_map(function($row) {
        $meta = get_nft_metadata($row['target_nft_id']);
        return [
            'offer_id'       => $row['offer_id'],
            'uuid'           => $row['uuid'],
            'offerer'        => $row['offerer_account'],
            'target'         => $row['target_account'],
            'nft_id'         => $row['target_nft_id'],
            'nft_name'       => $meta['nft_name'],
            'nft_image'      => $meta['image'],
            'offer_type'     => $row['offer_type'],
            'amount'         => (float)$row['amount'],
            'currency'       => $row['currency'],
            'platform_fee'   => (float)$row['platform_fee'],
            'net_amount'     => (float)$row['net_amount'],
            'royalty_amount' => (float)$row['royalty_amount'],
            'royalty_percent' => (float)$row['royalty_percent'],
            'royalty_wallet' => $row['royalty_wallet'],
            'status'         => $row['status'],
            'created_at'     => $row['created_at'],
            'expires_at'     => $row['expires_at']
        ];
    }, $rows);

    wp_send_json(['success' => true, 'offers' => $offers]);
    exit;
}


// v94: Removed auction resolution cron (auctions removed from platform)
// Will be revisited when XRPL batch transactions are available


/** Cron: mark expired offers and broadcast updates */
function imu_cleanup_expired_offers() {
    global $wpdb, $offers_table;

    // ── 1. Mark time-expired offers ──────────────────────────────────────
    $expired = $wpdb->get_results("SELECT offer_id, target_account FROM $offers_table WHERE status IN ('pending', 'active') AND expires_at IS NOT NULL AND expires_at < NOW()", ARRAY_A);
    error_log("imu_cleanup_expired_offers: Found " . count($expired) . " time-expired offers");

    foreach ($expired as $row) {
        $offer_id = $row['offer_id'];
        $ok = $wpdb->update($offers_table, ['status' => 'expired'], ['offer_id' => $offer_id]);
        if ($ok === false) {
            error_log("imu_cleanup_expired_offers: Failed to update offer $offer_id: " . $wpdb->last_error);
            continue;
        }
        error_log("imu_cleanup_expired_offers: Marked offer $offer_id as expired");
        if (function_exists('imu_websocket_broadcast')) {
            imu_websocket_broadcast([
                'type'     => 'offer_updated',
                'offer_id' => $offer_id,
                'status'   => 'expired',
                'account'  => $row['target_account']
            ]);
        }
    }

    // ── 2. v257: XRPL cross-reference for stale active offers ────────────
    // Any offer that's been 'active' for >24h and has a real offer_id should be
    // spot-checked. We process up to 20 unique NFTs per cron run to keep runtime short.
    $stale_candidates = $wpdb->get_results(
        "SELECT DISTINCT target_nft_id FROM $offers_table
         WHERE status = 'active' AND offer_id != ''
           AND (synced_at IS NULL OR synced_at < DATE_SUB(NOW(), INTERVAL 24 HOUR))
         LIMIT 20",
        ARRAY_A
    );

    if (empty($stale_candidates)) {
        error_log("imu_cleanup_expired_offers: No stale candidates to XRPL-check");
        return;
    }

    error_log("imu_cleanup_expired_offers: XRPL-checking " . count($stale_candidates) . " NFTs for stale offers");

    foreach ($stale_candidates as $row) {
        $nft_id = $row['target_nft_id'];
        if (strlen($nft_id) !== 64) continue;

        // Fetch live sell offers
        $sell_res    = xrpl_rpc('nft_sell_offers', ['nft_id' => $nft_id, 'ledger_index' => 'validated'], 6);
        $live_sells  = array_flip(array_filter(array_column($sell_res['result']['offers'] ?? [], 'nft_offer_index')));

        // Fetch live buy offers
        $buy_res    = xrpl_rpc('nft_buy_offers', ['nft_id' => $nft_id, 'ledger_index' => 'validated'], 6);
        $live_buys  = array_flip(array_filter(array_column($buy_res['result']['offers'] ?? [], 'nft_offer_index')));

        $db_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, offer_id, offer_type FROM $offers_table
             WHERE target_nft_id = %s AND status = 'active' AND offer_id != ''",
            $nft_id
        ), ARRAY_A);

        foreach ($db_rows as $dbr) {
            $on_chain = ($dbr['offer_type'] === 'sell')
                ? isset($live_sells[$dbr['offer_id']])
                : isset($live_buys[$dbr['offer_id']]);

            if (!$on_chain) {
                $wpdb->update(
                    $offers_table,
                    ['status' => 'cancelled', 'synced_at' => current_time('mysql')],
                    ['id' => $dbr['id']]
                );
                error_log("imu_cleanup_expired_offers: Stale cron — marked {$dbr['offer_type']} offer {$dbr['offer_id']} cancelled (not on XRPL)");
            } else {
                $wpdb->update($offers_table, ['synced_at' => current_time('mysql')], ['id' => $dbr['id']]);
            }
        }
    }
}
add_action('imu_cleanup_expired_offers', 'imu_cleanup_expired_offers');
if (!wp_next_scheduled('imu_cleanup_expired_offers')) {
    wp_schedule_event(time(), 'hourly', 'imu_cleanup_expired_offers');
}

/** GET: get_my_offers — v288 split endpoint A
 *  Returns user's own active offers: sell listings, outgoing transfers, buy offers made.
 *  Fast: single account_objects call with no per-NFT loops.
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['action'] ?? '') === 'get_my_offers')) {
    $nonce   = sanitize_text_field($_GET['nonce'] ?? '');
    $account = sanitize_text_field($_GET['account'] ?? '');

    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        status_header(403);
        wp_send_json(['success' => false, 'error' => 'Invalid nonce']);
    }

    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        status_header(400);
        wp_send_json(['success' => false, 'error' => 'Invalid account']);
    }

    $start_time = microtime(true);

    $result = [
        'success' => true,
        'outgoing_transfers' => [],
        'listed_items'       => [],
        'offers_made'        => [],
    ];

    
    // ===== STEP 1: Get MY account_objects (my outgoing offers) =====
    error_log("get_my_offers: Fetching account_objects for $account");
    
    $my_objects = [];
    $marker = null;
    $max_pages = 3;
    $page = 0;
    
    do {
        $params = [
            'account' => $account,
            'type' => 'nft_offer',
            'ledger_index' => 'validated',
            'limit' => 200
        ];
        if ($marker) $params['marker'] = $marker;
        
        $obj_result = xrpl_rpc('account_objects', $params, 15);
        
        if (!isset($obj_result['error']) && isset($obj_result['result']['account_objects'])) {
            $my_objects = array_merge($my_objects, $obj_result['result']['account_objects']);
            $marker = $obj_result['result']['marker'] ?? null;
        } else {
            break;
        }
        $page++;
    } while ($marker && $page < $max_pages);
    
    error_log("get_my_offers: Found " . count($my_objects) . " of my NFT offers");
    
    // Categorize my offers
    foreach ($my_objects as $obj) {
        $nft_id = $obj['NFTokenID'] ?? '';
        $flags = $obj['Flags'] ?? 0;
        $is_sell = ($flags & 1) === 1;
        $destination = $obj['Destination'] ?? null;
        
        $amt = $obj['Amount'] ?? '0';
        if (is_string($amt)) {
            $amount = (float)$amt / 1000000;
            $currency = 'XRP';
        } else {
            $amount = (float)($amt['value'] ?? 0);
            $currency = $amt['currency'] ?? 'XRP';
        }
        
        $meta = get_nft_metadata($nft_id);
        
        $offer_data = [
            'offer_id' => $obj['index'] ?? '',
            'nft_id' => $nft_id,
            'nft_name' => $meta['nft_name'] ?? $meta['name'] ?? 'Unnamed NFT',
            'nft_image' => $meta['image'] ?? '/wp-content/uploads/fallback-nft.svg',
            'type' => $is_sell ? 'sell' : 'buy',
            'amount' => $amount,
            'currency' => $currency,
            'destination' => $destination,
            'expiration' => $obj['Expiration'] ?? null,
            'status' => 'active',
            'source' => 'ledger'
        ];
        
        if ($is_sell) {
            if ($destination) {
                // Outgoing transfer (gift I'm sending to someone)
                $offer_data['recipient'] = $destination;
                $offer_data['is_gift'] = ($amount == 0);
                $result['outgoing_transfers'][] = $offer_data;
            } else {
                // Listed item (my marketplace listing)
                $result['listed_items'][] = $offer_data;
            }
        } else {
            // Buy offer I made on someone else's NFT
            $result['offers_made'][] = $offer_data;
        }
    }
    

    $elapsed = round((microtime(true) - $start_time) * 1000);
    $result['elapsed_ms'] = $elapsed;
    $result['totals'] = [
        'outgoing_transfers' => count($result['outgoing_transfers']),
        'listed_items'       => count($result['listed_items']),
        'offers_made'        => count($result['offers_made']),
    ];
    error_log("get_my_offers: Completed in {$elapsed}ms");
    wp_send_json($result);
    exit;
}



/** GET: get_incoming_transfers — v288 split endpoint B
 *  Returns sell offers destined to this account (gifts/airdrops/transfers).
 *  Medium speed: scans tx history + Bithomp + trading partners.
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['action'] ?? '') === 'get_incoming_transfers')) {
    $nonce   = sanitize_text_field($_GET['nonce'] ?? '');
    $account = sanitize_text_field($_GET['account'] ?? '');

    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        status_header(403);
        wp_send_json(['success' => false, 'error' => 'Invalid nonce']);
    }

    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        status_header(400);
        wp_send_json(['success' => false, 'error' => 'Invalid account']);
    }

    $start_time = microtime(true);
    $check_nfts    = sanitize_text_field($_GET['nft_ids'] ?? '');
    $check_senders = sanitize_text_field($_GET['senders'] ?? '');
    $nft_ids_array = $check_nfts    ? array_filter(array_map('trim', explode(',', $check_nfts)))    : [];
    $senders_array = $check_senders ? array_filter(array_map('trim', explode(',', $check_senders))) : [];
    $senders_array = array_filter($senders_array, function($s) use ($account) {
        return preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $s) && strtolower($s) !== strtolower($account);
    });

    $result = [
        'success'            => true,
        'incoming_transfers' => [],
    ];

    
    // ===== STEP 2: Check for INCOMING TRANSFERS (sell offers TO me) =====
    // Method A: Check specific NFT IDs for sell offers destined to me
    if (!empty($nft_ids_array)) {
        error_log("get_incoming_transfers: Checking " . count($nft_ids_array) . " NFT IDs for incoming transfers");
        
        foreach ($nft_ids_array as $nft_id) {
            if (strlen($nft_id) !== 64) continue;
            
            $sell_result = xrpl_rpc('nft_sell_offers', [
                'nft_id' => $nft_id,
                'ledger_index' => 'validated'
            ], 8);
            
            if (isset($sell_result['error']) || !isset($sell_result['result']['offers'])) {
                continue;
            }
            
            foreach ($sell_result['result']['offers'] as $offer) {
                $dest = $offer['destination'] ?? null;
                
                // Is this offer destined to me?
                if ($dest && strtolower($dest) === strtolower($account)) {
                    $amt = $offer['amount'] ?? '0';
                    if (is_string($amt)) {
                        $amount = (float)$amt / 1000000;
                        $currency = 'XRP';
                    } else {
                        $amount = (float)($amt['value'] ?? 0);
                        $currency = $amt['currency'] ?? 'XRP';
                    }
                    
                    $meta = get_nft_metadata($nft_id);
                    
                    $result['incoming_transfers'][] = [
                        'offer_id' => $offer['nft_offer_index'] ?? '',
                        'nft_id' => $nft_id,
                        'nft_name' => $meta['nft_name'] ?? $meta['name'] ?? 'Incoming NFT',
                        'nft_image' => $meta['image'] ?? '/wp-content/uploads/fallback-nft.svg',
                        'type' => 'incoming_transfer',
                        'is_gift' => ($amount == 0),
                        'amount' => $amount,
                        'currency' => $currency,
                        'from' => $offer['owner'] ?? '',
                        'offerer' => $offer['owner'] ?? '',
                        'expiration' => $offer['expiration'] ?? null,
                        'status' => 'active',
                        'source' => 'ledger',
                        'accept_type' => 'sell_offer'  // We accept THEIR sell offer
                    ];
                }
            }
        }
    }
    
    // Method B: Check specific senders' account_objects for offers destined to me
    if (!empty($senders_array)) {
        error_log("get_incoming_transfers: Checking " . count($senders_array) . " senders for incoming transfers");
        
        foreach ($senders_array as $sender) {
            $sender_result = xrpl_rpc('account_objects', [
                'account' => $sender,
                'type' => 'nft_offer',
                'ledger_index' => 'validated',
                'limit' => 100
            ], 10);
            
            if (isset($sender_result['error']) || !isset($sender_result['result']['account_objects'])) {
                continue;
            }
            
            foreach ($sender_result['result']['account_objects'] as $obj) {
                $dest = $obj['Destination'] ?? null;
                
                // Is this offer destined to me?
                if ($dest && strtolower($dest) === strtolower($account)) {
                    $nft_id = $obj['NFTokenID'] ?? '';
                    $flags = $obj['Flags'] ?? 0;
                    $is_sell = ($flags & 1) === 1;
                    
                    // We only want SELL offers (gifts being sent to us)
                    if (!$is_sell) continue;
                    
                    $amt = $obj['Amount'] ?? '0';
                    if (is_string($amt)) {
                        $amount = (float)$amt / 1000000;
                        $currency = 'XRP';
                    } else {
                        $amount = (float)($amt['value'] ?? 0);
                        $currency = $amt['currency'] ?? 'XRP';
                    }
                    
                    $meta = get_nft_metadata($nft_id);
                    
                    $result['incoming_transfers'][] = [
                        'offer_id' => $obj['index'] ?? '',
                        'nft_id' => $nft_id,
                        'nft_name' => $meta['nft_name'] ?? $meta['name'] ?? 'Incoming NFT',
                        'nft_image' => $meta['image'] ?? '/wp-content/uploads/fallback-nft.svg',
                        'type' => 'incoming_transfer',
                        'is_gift' => ($amount == 0),
                        'amount' => $amount,
                        'currency' => $currency,
                        'from' => $sender,
                        'offerer' => $sender,
                        'expiration' => $obj['Expiration'] ?? null,
                        'status' => 'active',
                        'source' => 'ledger',
                        'accept_type' => 'sell_offer'
                    ];
                }
            }
        }
    }
    
    error_log("get_incoming_transfers: Found " . count($result['incoming_transfers']) . " incoming transfers from known sources");
    
    // ===== Method C: Scan user's recent transaction history for incoming offer notifications =====
    // This catches transfers from ANY sender without needing to know them in advance
    error_log("get_incoming_transfers: Scanning transaction history for incoming transfers");
    
    $tx_result = xrpl_rpc('account_tx', [
        'account' => $account,
        'ledger_index_min' => -1,
        'ledger_index_max' => -1,
        'limit' => 100,  // Check last 100 transactions
        'forward' => false  // Most recent first
    ], 15);
    
    if (!isset($tx_result['error']) && isset($tx_result['result']['transactions'])) {
        $existing_offer_ids = array_column($result['incoming_transfers'], 'offer_id');
        
        foreach ($tx_result['result']['transactions'] as $tx_entry) {
            $tx = $tx_entry['tx'] ?? $tx_entry;
            $meta = $tx_entry['meta'] ?? [];
            
            // Look for NFTokenCreateOffer transactions
            if (($tx['TransactionType'] ?? '') !== 'NFTokenCreateOffer') {
                continue;
            }
            
            // Check if this offer has our account as Destination
            $dest = $tx['Destination'] ?? null;
            if (!$dest || strtolower($dest) !== strtolower($account)) {
                continue;
            }
            
            // Check if it's a sell offer (Flags & 1)
            $flags = $tx['Flags'] ?? 0;
            $is_sell = ($flags & 1) === 1;
            if (!$is_sell) {
                continue;
            }
            
            // Get the offer index from the metadata
            $offer_id = null;
            $affected_nodes = $meta['AffectedNodes'] ?? [];
            foreach ($affected_nodes as $node) {
                $created = $node['CreatedNode'] ?? null;
                if ($created && ($created['LedgerEntryType'] ?? '') === 'NFTokenOffer') {
                    $offer_id = $created['LedgerIndex'] ?? null;
                    break;
                }
            }
            
            if (!$offer_id) {
                continue;
            }
            
            // Skip if we already have this offer from Method A or B
            if (in_array($offer_id, $existing_offer_ids)) {
                continue;
            }
            
            // Verify the offer still exists on ledger (hasn't been accepted/cancelled)
            $offer_check = xrpl_rpc('ledger_entry', [
                'nft_offer' => $offer_id,
                'ledger_index' => 'validated'
            ], 5);
            
            if (isset($offer_check['result']['error']) || !isset($offer_check['result']['node'])) {
                // Offer no longer exists
                continue;
            }
            
            $offer_node = $offer_check['result']['node'];
            $nft_id = $offer_node['NFTokenID'] ?? $tx['NFTokenID'] ?? '';
            $sender = $offer_node['Owner'] ?? $tx['Account'] ?? '';
            
            $amt = $offer_node['Amount'] ?? $tx['Amount'] ?? '0';
            if (is_string($amt)) {
                $amount = (float)$amt / 1000000;
                $currency = 'XRP';
            } else {
                $amount = (float)($amt['value'] ?? 0);
                $currency = $amt['currency'] ?? 'XRP';
            }
            
            $nft_meta = get_nft_metadata($nft_id);
            
            $result['incoming_transfers'][] = [
                'offer_id' => $offer_id,
                'nft_id' => $nft_id,
                'nft_name' => $nft_meta['nft_name'] ?? $nft_meta['name'] ?? 'Incoming NFT',
                'nft_image' => $nft_meta['image'] ?? '/wp-content/uploads/fallback-nft.svg',
                'type' => 'incoming_transfer',
                'is_gift' => ($amount == 0),
                'amount' => $amount,
                'currency' => $currency,
                'from' => $sender,
                'offerer' => $sender,
                'expiration' => $offer_node['Expiration'] ?? null,
                'status' => 'active',
                'source' => 'tx_history',
                'accept_type' => 'sell_offer'
            ];
            
            $existing_offer_ids[] = $offer_id; // Prevent duplicates
        }
        
        error_log("get_incoming_transfers: Found " . count($result['incoming_transfers']) . " total incoming transfers after tx scan");
    }
    
    // ===== Method D: Use Bithomp API to find offers destined to this account =====
    // Bithomp indexes the entire ledger and can search more efficiently
    global $BITHOMP_TOKEN;
    if (!empty($BITHOMP_TOKEN)) {
        error_log("get_incoming_transfers: Checking Bithomp for incoming offers");
        
        $bithomp_url = "https://bithomp.com/api/v2/address/{$account}/nfts/offers?destination=true&type=sell&limit=20";
        $bithomp_response = wp_remote_get($bithomp_url, [
            'headers' => [
                'x-bithomp-token' => $BITHOMP_TOKEN
            ],
            'timeout' => 10
        ]);
        
        if (!is_wp_error($bithomp_response) && wp_remote_retrieve_response_code($bithomp_response) === 200) {
            $bithomp_data = json_decode(wp_remote_retrieve_body($bithomp_response), true);
            $existing_offer_ids = array_column($result['incoming_transfers'], 'offer_id');
            
            if (isset($bithomp_data['offers']) && is_array($bithomp_data['offers'])) {
                foreach ($bithomp_data['offers'] as $offer) {
                    $offer_id = $offer['offerIndex'] ?? $offer['index'] ?? '';
                    
                    if (empty($offer_id) || in_array($offer_id, $existing_offer_ids)) {
                        continue;
                    }
                    
                    $nft_id = $offer['nftokenID'] ?? $offer['nftId'] ?? '';
                    $amount = isset($offer['amount']) ? (float)$offer['amount'] / 1000000 : 0;
                    $currency = 'XRP';
                    
                    if (is_array($offer['amount'] ?? null)) {
                        $amount = (float)($offer['amount']['value'] ?? 0);
                        $currency = $offer['amount']['currency'] ?? 'XRP';
                    }
                    
                    $nft_meta = get_nft_metadata($nft_id);
                    
                    $result['incoming_transfers'][] = [
                        'offer_id' => $offer_id,
                        'nft_id' => $nft_id,
                        'nft_name' => $nft_meta['nft_name'] ?? $nft_meta['name'] ?? $offer['nftName'] ?? 'Incoming NFT',
                        'nft_image' => $nft_meta['image'] ?? $offer['nftImage'] ?? '/wp-content/uploads/fallback-nft.svg',
                        'type' => 'incoming_transfer',
                        'is_gift' => ($amount == 0),
                        'amount' => $amount,
                        'currency' => $currency,
                        'from' => $offer['owner'] ?? $offer['account'] ?? '',
                        'offerer' => $offer['owner'] ?? $offer['account'] ?? '',
                        'expiration' => $offer['expiration'] ?? null,
                        'status' => 'active',
                        'source' => 'bithomp',
                        'accept_type' => 'sell_offer'
                    ];
                }
                
                error_log("get_incoming_transfers: Found " . count($result['incoming_transfers']) . " incoming transfers after Bithomp scan");
            }
        } else {
            error_log("get_incoming_transfers: Bithomp API call failed or returned error");
        }
    }
    
    // ===== Method E: Check recent NFT trading partners for pending offers =====
    // Find accounts we've traded NFTs with recently and check if they have pending offers for us
    if (count($result['incoming_transfers']) === 0) {
        error_log("get_incoming_transfers: Checking recent trading partners for incoming offers");
        
        $tx_result2 = xrpl_rpc('account_tx', [
            'account' => $account,
            'ledger_index_min' => -1,
            'ledger_index_max' => -1,
            'limit' => 50,
            'forward' => false
        ], 12);
        
        $trading_partners = [];
        if (!isset($tx_result2['error']) && isset($tx_result2['result']['transactions'])) {
            foreach ($tx_result2['result']['transactions'] as $tx_entry) {
                $tx = $tx_entry['tx'] ?? $tx_entry;
                $tx_type = $tx['TransactionType'] ?? '';
                
                // Look for NFT-related transactions
                if (in_array($tx_type, ['NFTokenAcceptOffer', 'NFTokenCreateOffer', 'NFTokenBurn'])) {
                    // Get the other party
                    $other = $tx['Account'] ?? '';
                    if ($other && strtolower($other) !== strtolower($account) && !in_array($other, $trading_partners)) {
                        $trading_partners[] = $other;
                    }
                }
                
                // Also check Payment transactions (might be from NFT sales/purchases)
                if ($tx_type === 'Payment') {
                    $dest = $tx['Destination'] ?? '';
                    $sender = $tx['Account'] ?? '';
                    if ($sender !== $account && !in_array($sender, $trading_partners)) {
                        $trading_partners[] = $sender;
                    }
                    if ($dest !== $account && !in_array($dest, $trading_partners)) {
                        $trading_partners[] = $dest;
                    }
                }
                
                // Limit to 10 partners to avoid too many API calls
                if (count($trading_partners) >= 10) break;
            }
        }
        
        error_log("get_incoming_transfers: Found " . count($trading_partners) . " recent trading partners");
        
        // Check each trading partner for offers destined to us
        $existing_offer_ids = array_column($result['incoming_transfers'], 'offer_id');
        
        foreach ($trading_partners as $partner) {
            $partner_result = xrpl_rpc('account_objects', [
                'account' => $partner,
                'type' => 'nft_offer',
                'ledger_index' => 'validated',
                'limit' => 50
            ], 8);
            
            if (isset($partner_result['error']) || !isset($partner_result['result']['account_objects'])) {
                continue;
            }
            
            foreach ($partner_result['result']['account_objects'] as $obj) {
                $dest = $obj['Destination'] ?? null;
                
                // Is this offer destined to me?
                if (!$dest || strtolower($dest) !== strtolower($account)) {
                    continue;
                }
                
                $offer_id = $obj['index'] ?? '';
                if (in_array($offer_id, $existing_offer_ids)) {
                    continue;
                }
                
                $nft_id = $obj['NFTokenID'] ?? '';
                $flags = $obj['Flags'] ?? 0;
                $is_sell = ($flags & 1) === 1;
                
                // We only want SELL offers (gifts being sent to us)
                if (!$is_sell) continue;
                
                $amt = $obj['Amount'] ?? '0';
                if (is_string($amt)) {
                    $amount = (float)$amt / 1000000;
                    $currency = 'XRP';
                } else {
                    $amount = (float)($amt['value'] ?? 0);
                    $currency = $amt['currency'] ?? 'XRP';
                }
                
                $meta = get_nft_metadata($nft_id);
                
                $result['incoming_transfers'][] = [
                    'offer_id' => $offer_id,
                    'nft_id' => $nft_id,
                    'nft_name' => $meta['nft_name'] ?? $meta['name'] ?? 'Incoming NFT',
                    'nft_image' => $meta['image'] ?? '/wp-content/uploads/fallback-nft.svg',
                    'type' => 'incoming_transfer',
                    'is_gift' => ($amount == 0),
                    'amount' => $amount,
                    'currency' => $currency,
                    'from' => $partner,
                    'offerer' => $partner,
                    'expiration' => $obj['Expiration'] ?? null,
                    'status' => 'active',
                    'source' => 'trading_partner',
                    'accept_type' => 'sell_offer'
                ];
                
                $existing_offer_ids[] = $offer_id;
            }
        }
        
        error_log("get_incoming_transfers: Final incoming transfer count: " . count($result['incoming_transfers']));
    }
    

    // Deduplicate
    $seen = []; $unique = [];
    foreach ($result['incoming_transfers'] as $offer) {
        $oid = $offer['offer_id'] ?? '';
        if ($oid && !isset($seen[$oid])) { $seen[$oid] = true; $unique[] = $offer; }
    }
    $result['incoming_transfers'] = $unique;

    $elapsed = round((microtime(true) - $start_time) * 1000);
    $result['elapsed_ms'] = $elapsed;
    $result['totals'] = ['incoming_transfers' => count($result['incoming_transfers'])];
    error_log("get_incoming_transfers: Completed in {$elapsed}ms");
    wp_send_json($result);
    exit;
}



/** GET: get_offers_received — v288 split endpoint C
 *  Returns buy offers on NFTs the user owns.
 *  Slowest: scans account_nfts then fires nft_buy_offers per NFT.
 *  Loaded last — section shows spinner until this resolves.
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['action'] ?? '') === 'get_offers_received')) {
    $nonce   = sanitize_text_field($_GET['nonce'] ?? '');
    $account = sanitize_text_field($_GET['account'] ?? '');

    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        status_header(403);
        wp_send_json(['success' => false, 'error' => 'Invalid nonce']);
    }

    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        status_header(400);
        wp_send_json(['success' => false, 'error' => 'Invalid account']);
    }

    $start_time = microtime(true);
    $max_nfts = intval($_GET['max_nfts'] ?? 50);

    $result = [
        'success'         => true,
        'offers_received' => [],
    ];

    $checked = 0;

    
    // ===== STEP 3: Check for OFFERS RECEIVED (buy offers on NFTs I own) =====
    error_log("get_offers_received: Fetching owned NFTs to check for received offers");
    
    $my_nfts = [];
    $marker = null;
    $page = 0;
    
    do {
        $params = [
            'account' => $account,
            'ledger_index' => 'validated',
            'limit' => 400
        ];
        if ($marker) $params['marker'] = $marker;
        
        $nft_result = xrpl_rpc('account_nfts', $params, 15);
        
        if (!isset($nft_result['error']) && isset($nft_result['result']['account_nfts'])) {
            $my_nfts = array_merge($my_nfts, $nft_result['result']['account_nfts']);
            $marker = $nft_result['result']['marker'] ?? null;
        } else {
            break;
        }
        $page++;
    } while ($marker && $page < 2 && count($my_nfts) < $max_nfts);
    
    // Limit to max_nfts
    $my_nfts = array_slice($my_nfts, 0, $max_nfts);
    error_log("get_offers_received: Checking " . count($my_nfts) . " owned NFTs for buy offers");
    
    // Check each NFT for buy offers
    $checked = 0;
    foreach ($my_nfts as $nft) {
        $nft_id = $nft['NFTokenID'] ?? '';
        if (!$nft_id) continue;
        
        $checked++;
        if ($checked % 20 === 0) {
            error_log("get_offers_received: Progress - checked $checked NFTs for buy offers");
        }
        
        $buy_result = xrpl_rpc('nft_buy_offers', [
            'nft_id' => $nft_id,
            'ledger_index' => 'validated'
        ], 5);
        
        if (isset($buy_result['error']) || !isset($buy_result['result']['offers'])) {
            continue;
        }
        
        $transfer_fee = $nft['TransferFee'] ?? 0;
        $royalty_percent = $transfer_fee / 1000;
        
        foreach ($buy_result['result']['offers'] as $offer) {
            $offerer = $offer['owner'] ?? '';
            
            // Skip my own offers
            if (strtolower($offerer) === strtolower($account)) continue;
            
            $amt = $offer['amount'] ?? '0';
            if (is_string($amt)) {
                $amount = (float)$amt / 1000000;
                $currency = 'XRP';
            } else {
                $amount = (float)($amt['value'] ?? 0);
                $currency = $amt['currency'] ?? 'XRP';
            }
            
            $royalty_amount = $amount * ($royalty_percent / 100);
            $net_amount = $amount - $royalty_amount;
            
            $meta = get_nft_metadata($nft_id);
            
            $result['offers_received'][] = [
                'offer_id' => $offer['nft_offer_index'] ?? '',
                'amount_issuer' => (is_array($amt) ? ($amt['issuer'] ?? null) : null), /* P2-J1 */
                'nft_id' => $nft_id,
                'nft_name' => $meta['nft_name'] ?? $meta['name'] ?? 'Unnamed NFT',
                'nft_image' => $meta['image'] ?? '/wp-content/uploads/fallback-nft.svg',
                'type' => ($amount == 0) ? 'transfer_request' : 'buy',
                'is_transfer_request' => ($amount == 0),
                'amount' => $amount,
                'currency' => $currency,
                'offerer' => $offerer,
                'from' => $offerer,
                'destination' => $offer['destination'] ?? null,
                'expiration' => $offer['expiration'] ?? null,
                'royalty_percent' => $royalty_percent,
                'royalty_amount' => round($royalty_amount, 6),
                'net_amount' => round($net_amount, 6),
                'status' => 'active',
                'source' => 'ledger',
                'accept_type' => 'buy_offer'  // We accept their BUY offer
            ];
        }
    }
    
    error_log("get_offers_received: Found " . count($result['offers_received']) . " offers received");
    
    // ===== STEP 4: Deduplicate =====
    $seen = [];
    foreach (['incoming_transfers', 'offers_received'] as $category) {
        $unique = [];
        foreach ($result[$category] as $offer) {
            $oid = $offer['offer_id'] ?? '';
            if ($oid && !isset($seen[$oid])) {
                $seen[$oid] = true;
                $unique[] = $offer;
            }
        }
        $result[$category] = $unique;
    }
    
    // ===== STEP 5: Calculate totals and return =====

    // Deduplicate offers_received
    $seen = []; $unique = [];
    foreach ($result['offers_received'] as $offer) {
        $oid = $offer['offer_id'] ?? '';
        if ($oid && !isset($seen[$oid])) { $seen[$oid] = true; $unique[] = $offer; }
    }
    $result['offers_received'] = $unique;

    $elapsed = round((microtime(true) - $start_time) * 1000);
    $result['elapsed_ms']  = $elapsed;
    $result['nfts_checked'] = $checked;
    $result['totals'] = ['offers_received' => count($result['offers_received'])];
    error_log("get_offers_received: Completed in {$elapsed}ms, checked $checked NFTs");
    wp_send_json($result);
    exit;
}
/** Fallback */
error_log("Fallback: Invalid request - Method: {$_SERVER['REQUEST_METHOD']}, Action: " . ($_GET['action'] ?? $_POST['action'] ?? 'none'));
status_header(400);
wp_send_json(['success' => false, 'error' => 'Invalid request']);