<?php
/**
 * ============================================================================
 * FILE: authorized-minter-handler.php
 * PATH: /wp-content/themes/astra/xrpl-nft-marketplace/backend/
 * ============================================================================
 * 
 * PHASE 1: Artist Authorization for Mint-on-Demand
 * 
 * PURPOSE:
 * Handle one-time artist authorization where they sign AccountSet to allow
 * IMCollectibles platform wallet to mint NFTs on their behalf.
 * 
 * KEY XRPL CONCEPT:
 * - Artist signs: AccountSet with NFTokenMinter = Platform Wallet, SetFlag = 10
 * - Platform can then mint with: Account = Platform, Issuer = Artist
 * - Artist receives ALL royalties via TransferFee
 * - CRITICAL: Each wallet can only have ONE authorized minter!
 * 
 * ENDPOINTS:
 * GET  ?action=check_status&account=rXXX  - Check if artist is authorized
 * GET  ?action=poll&uuid=XXX              - Poll XUMM payload status
 * POST action=request_auth                - Create authorization XUMM payload
 * 
 * @version 1.0.0
 */

// WordPress bootstrap
require_once dirname(__DIR__, 5) . '/wp-load.php';
if (!defined('ABSPATH')) exit;

// Headers
header('Content-Type: application/json; charset=utf-8');
$am_allowed_origins = [
    'https://imcollectibles.xyz',
    'https://www.imcollectibles.xyz',
    'https://imcollectibles.io',
    'https://www.imcollectibles.io',
    'https://improtectors.com',
    'https://www.improtectors.com'
];
$am_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . (in_array($am_origin, $am_allowed_origins) ? $am_origin : $am_allowed_origins[0]));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ============================================================================
// CONFIGURATION
// ============================================================================

// Platform wallet that will be authorized to mint (main funded wallet)
//
// v892 (Defect AG): FAIL CLOSED — and this is the most dangerous of the four fallback
// sites. This file builds the AccountSet payload an ARTIST SIGNS (L175 / L427:
// 'NFTokenMinter' => IMC_PLATFORM_WALLET) and also verifies authorisation (L153:
// $minter === IMC_PLATFORM_WALLET).
//
// If the fallback fired, every artist onboarding from that moment would be asked to
// authorise the WRONG wallet -- and every mint on their listings would then fail
// tecNO_PERMISSION. That is Defect AK, self-inflicted, at scale. Worse, the verify at
// L153 would be wrong too, so the AK reserve-gate and the minter audit would
// both report those artists as correctly authorised while nothing could mint.
//
// Refuse outright: there is no read path in this handler worth keeping alive without it.
if (!defined('IMC_PLATFORM_WALLET')) {
    if (function_exists('auth_log')) {
        auth_log('CRITICAL: IMC_PLATFORM_WALLET not defined in wp-config — refusing to build or verify any minter authorisation.');
    }
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Platform wallet not configured — minter authorisation unavailable. Contact support.']);
    exit;
}

// XRPL RPC endpoint
if (!defined('XRPL_RPC')) {
    define('XRPL_RPC', defined('IMU_XRPL_RPC') ? IMU_XRPL_RPC : 'https://xrplcluster.com');
}

// XUMM credentials (from existing config)
$xumm_api_key = defined('XUMM_API_KEY') ? XUMM_API_KEY : '';
$xumm_api_secret = defined('XUMM_API_SECRET') ? XUMM_API_SECRET : '';

// Logging
$log_file = __DIR__ . '/logs/auth-minter.log';
if (!file_exists(dirname($log_file))) @mkdir(dirname($log_file), 0755, true);

function auth_log($msg) {
    global $log_file;
    @file_put_contents($log_file, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
}

// ============================================================================
// DATABASE TABLE
// ============================================================================

global $wpdb;
$table_name = $wpdb->prefix . 'imc_authorized_minters';

// Create table if not exists
$wpdb->query("
CREATE TABLE IF NOT EXISTS $table_name (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    artist_account VARCHAR(35) NOT NULL,
    platform_wallet VARCHAR(35) NOT NULL,
    status ENUM('pending','active','revoked') DEFAULT 'pending',
    auth_tx_hash VARCHAR(64) DEFAULT NULL,
    xumm_uuid VARCHAR(64) DEFAULT NULL,
    authorized_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY idx_artist (artist_account)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

function json_response($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function json_error($msg, $code = 400) {
    json_response(['success' => false, 'error' => $msg], $code);
}

function json_success($data) {
    json_response(['success' => true, 'data' => $data]);
}

/**
 * Check on-chain if account has NFTokenMinter field set
 */
function check_onchain_minter($account) {
    $request = [
        'method' => 'account_info',
        'params' => [[
            'account' => $account,
            'ledger_index' => 'validated'
        ]]
    ];
    
    $response = wp_remote_post(XRPL_RPC, [
        'body' => json_encode($request),
        'headers' => ['Content-Type' => 'application/json'],
        'timeout' => 15
    ]);
    
    if (is_wp_error($response)) {
        auth_log("RPC error: " . $response->get_error_message());
        return ['error' => $response->get_error_message()];
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if (isset($body['result']['account_data'])) {
        $data = $body['result']['account_data'];
        $minter = $data['NFTokenMinter'] ?? null;
        
        return [
            'has_minter' => !empty($minter),
            'current_minter' => $minter,
            'is_imc' => $minter === IMC_PLATFORM_WALLET
        ];
    }
    
    return ['error' => 'Failed to fetch account'];
}

/**
 * Create XUMM payload for AccountSet authorization
 */
function create_xumm_auth_payload($artist_account) {
    global $xumm_api_key, $xumm_api_secret;
    
    if (!$xumm_api_key || !$xumm_api_secret) {
        return ['error' => 'XUMM API not configured'];
    }
    
    // AccountSet transaction to authorize IMCollectibles
    $payload = [
        'txjson' => [
            'TransactionType' => 'AccountSet',
            'Account' => $artist_account,
            'NFTokenMinter' => IMC_PLATFORM_WALLET,
            'SetFlag' => 10  // asfAuthorizedNFTokenMinter
        ],
        'options' => [
            'submit' => true,
            'expire' => 1800,  // 30 minutes
            'return_url' => [
                'web' => home_url('/mint/?auth_signed=1'),
                'app' => home_url('/mint/?auth_signed=1')
            ]
        ],
        'custom_meta' => [
            'identifier' => 'imc_auth_' . time(),
            'blob' => [
                'type' => 'authorize_minter',
                'artist' => $artist_account,
                'platform' => IMC_PLATFORM_WALLET
            ],
            'instruction' => 'Authorize IMCollectibles to mint NFTs on your behalf. You remain the Issuer and receive all royalties.'
        ]
    ];
    
    $ch = curl_init('https://xumm.app/api/v1/platform/payload');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-API-Key: ' . $xumm_api_key,
            'X-API-Secret: ' . $xumm_api_secret
        ],
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        auth_log("XUMM error HTTP $http_code: $response");
        return ['error' => 'Failed to create XUMM payload'];
    }
    
    return json_decode($response, true);
}

/**
 * Poll XUMM payload status
 */
function poll_xumm($uuid) {
    global $xumm_api_key, $xumm_api_secret;
    
    $ch = curl_init("https://xumm.app/api/v1/platform/payload/$uuid");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'X-API-Key: ' . $xumm_api_key,
            'X-API-Secret: ' . $xumm_api_secret
        ],
        CURLOPT_TIMEOUT => 15
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// ============================================================================
// ROUTE HANDLING
// ============================================================================

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {

    // ========================================================================
    // GET: Check authorization status
    // ========================================================================
    if ($method === 'GET' && $action === 'check_status') {
        $account = sanitize_text_field($_GET['account'] ?? '');
        
        if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
            json_error('Invalid XRPL account format');
        }
        
        // Check on-chain first (source of truth)
        $onchain = check_onchain_minter($account);
        
        if (isset($onchain['error'])) {
            json_error($onchain['error']);
        }
        
        // Determine status
        $status = 'not_authorized';
        $is_authorized = false;
        
        if ($onchain['is_imc']) {
            $status = 'authorized';
            $is_authorized = true;
            
            // Sync to database
            global $wpdb, $table_name;
            $wpdb->replace($table_name, [
                'artist_account' => $account,
                'platform_wallet' => IMC_PLATFORM_WALLET,
                'status' => 'active',
                'authorized_at' => current_time('mysql'),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ]);
            
        } elseif ($onchain['has_minter']) {
            $status = 'has_other_minter';
        }
        
        json_success([
            'account' => $account,
            'is_authorized' => $is_authorized,
            'status' => $status,
            'current_minter' => $onchain['current_minter'],
            'platform_wallet' => IMC_PLATFORM_WALLET
        ]);
    }

    // ========================================================================
    // GET: Poll XUMM payload
    // ========================================================================
    if ($method === 'GET' && $action === 'poll') {
        $uuid = sanitize_text_field($_GET['uuid'] ?? '');
        if (!$uuid) json_error('Missing UUID');
        
        $payload = poll_xumm($uuid);
        if (!$payload) json_error('Failed to poll');
        
        $meta = $payload['meta'] ?? [];
        $response_data = $payload['response'] ?? [];
        $custom = $payload['custom_meta'] ?? [];
        
        $result = [
            'uuid' => $uuid,
            'signed' => $meta['signed'] ?? false,
            'resolved' => $meta['resolved'] ?? false,
            'expired' => $meta['expired'] ?? false
        ];
        
        // If signed, verify on-chain result before declaring success
        if ($result['signed']) {
            $artist = $custom['blob']['artist'] ?? null;
            $tx_hash = $response_data['txid'] ?? null;
            $dispatched = $response_data['dispatched_result'] ?? '';
            
            // v202: Check dispatched_result for immediate failure detection
            if ($dispatched && strpos($dispatched, 'tec') === 0 || strpos($dispatched, 'tef') === 0 || strpos($dispatched, 'tem') === 0) {
                auth_log("Authorization tx FAILED for $artist: $dispatched");
                $result['tx_failed'] = true;
                $result['tx_error'] = $dispatched;
                json_success($result);
            }
            
            // v202: Verify on-chain if we have tx_hash
            $on_chain_confirmed = false;
            if ($tx_hash) {
                $tx_check = wp_remote_post(XRPL_RPC, [
                    'body' => json_encode(['method' => 'tx', 'params' => [['transaction' => $tx_hash, 'binary' => false]]]),
                    'headers' => ['Content-Type' => 'application/json'],
                    'timeout' => 10
                ]);
                
                if (!is_wp_error($tx_check)) {
                    $tx_body = json_decode(wp_remote_retrieve_body($tx_check), true);
                    $validated = $tx_body['result']['validated'] ?? false;
                    $tx_result = $tx_body['result']['meta']['TransactionResult'] ?? null;
                    
                    if ($validated && $tx_result === 'tesSUCCESS') {
                        $on_chain_confirmed = true;
                        $result['tx_confirmed'] = true;
                        auth_log("Authorization tx CONFIRMED on-chain for $artist: $tx_hash");
                    } elseif ($validated && $tx_result !== null && $tx_result !== 'tesSUCCESS') {
                        auth_log("Authorization tx FAILED on-chain for $artist: $tx_result");
                        $result['tx_failed'] = true;
                        $result['tx_error'] = $tx_result;
                        json_success($result);
                    } else {
                        // Not yet validated — signed but pending
                        $result['tx_pending'] = true;
                    }
                }
            } elseif ($dispatched === 'tesSUCCESS') {
                $on_chain_confirmed = true;
                $result['tx_confirmed'] = true;
            }
            
            // Only update DB if on-chain confirmed
            if ($on_chain_confirmed && $artist && $tx_hash) {
                global $wpdb, $table_name;
                $wpdb->update($table_name, [
                    'status' => 'active',
                    'auth_tx_hash' => $tx_hash,
                    'authorized_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ], ['artist_account' => $artist]);
                
                auth_log("Artist $artist authorized. TX: $tx_hash");
                $result['tx_hash'] = $tx_hash;
            }
        }
        
        json_success($result);
    }

    // ========================================================================
    // POST: Request authorization
    // ========================================================================
    if ($method === 'POST' && $action === 'request_auth') {
        // Verify nonce
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
            json_error('Invalid security token', 403);
        }
        
        $account = sanitize_text_field($_POST['account'] ?? '');
        
        if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
            json_error('Invalid XRPL account');
        }
        
        // Check current status
        $onchain = check_onchain_minter($account);
        
        if ($onchain['is_imc']) {
            json_error('Already authorized with IMCollectibles');
        }
        
        // Note: We allow authorization even if another minter exists
        // The AccountSet transaction will replace the existing minter
        // This enables artists to switch from other marketplaces to IMC
        
        // --- Joey branch (v552, additive): return the AccountSet txjson for local
        // signing. Front-end then calls joey_verify_authorize. Xaman path below untouched.
        // v686: the SERVER decides the wallet. The client flag depends on an async boot
        // (bundle load -> 150ms poll -> reconnect round-trip); a Joey user who acted before it
        // finished silently received a XUMM payload for a wallet they don't use -- and the
        // account-binding guard, which only runs when the client flag is set, was skipped with
        // it. The xrpl_wallet_type cookie is set at login and available on the first request,
        // so it is authoritative. Xaman users are unaffected (their cookie never says joey).
        if (($_POST['wallet'] ?? '') === 'joey' || ($_COOKIE['xrpl_wallet_type'] ?? '') === 'joey') {
            json_success(['wallet' => 'joey', 'txjson' => [
                'TransactionType' => 'AccountSet',
                'Account'         => $account,
                'NFTokenMinter'   => IMC_PLATFORM_WALLET,
                'SetFlag'         => 10
            ]]);
        }

        // Create XUMM payload
        $payload = create_xumm_auth_payload($account);
        
        if (isset($payload['error'])) {
            json_error($payload['error']);
        }
        
        if (!isset($payload['uuid'])) {
            json_error('Failed to create authorization request');
        }
        
        // Store pending record
        global $wpdb, $table_name;
        $wpdb->replace($table_name, [
            'artist_account' => $account,
            'platform_wallet' => IMC_PLATFORM_WALLET,
            'status' => 'pending',
            'xumm_uuid' => $payload['uuid'],
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ]);
        
        auth_log("Auth requested for $account. UUID: {$payload['uuid']}");
        
        json_success([
            'uuid' => $payload['uuid'],
            'qr_png' => $payload['refs']['qr_png'] ?? null,
            'deeplink' => $payload['next']['always'] ?? null,
            'websocket' => $payload['refs']['websocket_status'] ?? null
        ]);
    }

    // ========================================================================
    // POST: joey_verify_authorize (v552 -- Joey/WalletConnect authorize confirm)
    //   Verifies a signed AccountSet on-chain (validated + tesSUCCESS + AccountSet +
    //   Account==artist), confirms NFTokenMinter is now the platform (definitive
    //   end-state via check_onchain_minter), then marks the artist authorized.
    //   Mirrors the poll/check_status confirm bookkeeping. Brand-new action; additive.
    // ========================================================================
    if ($method === 'POST' && $action === 'joey_verify_authorize') {
        $nonce   = sanitize_text_field($_POST['nonce']   ?? '');
        if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) json_error('Invalid security token', 403);
        $account = sanitize_text_field($_POST['account'] ?? '');
        $tx_hash = sanitize_text_field($_POST['tx_hash'] ?? '');
        if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{24,34}$/', $account)) json_error('Invalid XRPL account');
        if (!preg_match('/^[A-Fa-f0-9]{64}$/', $tx_hash))               json_error('Invalid transaction hash');

        $tx = null;
        for ($i = 0; $i < 6; $i++) {
            $resp = wp_remote_post(XRPL_RPC, [
                'body'    => json_encode(['method' => 'tx', 'params' => [['transaction' => $tx_hash, 'binary' => false]]]),
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 15
            ]);
            if (!is_wp_error($resp)) {
                $body = json_decode(wp_remote_retrieve_body($resp), true);
                $tx = $body['result'] ?? null;
                if (!empty($tx['validated'])) break;
            }
            sleep(2);
        }
        if (empty($tx) || empty($tx['validated'])) json_error('Transaction not validated -- please retry');
        if (($tx['meta']['TransactionResult'] ?? '') !== 'tesSUCCESS') json_error('Authorization failed on-chain: ' . ($tx['meta']['TransactionResult'] ?? 'unknown'));
        if (($tx['TransactionType'] ?? '') !== 'AccountSet') json_error('Unexpected transaction type');
        if (($tx['Account'] ?? '') !== $account)            json_error('Transaction account mismatch');

        // Definitive end-state confirmation: NFTokenMinter must now be the platform.
        $onchain = check_onchain_minter($account);
        if (empty($onchain['is_imc'])) json_error('NFTokenMinter not set to IMCollectibles yet -- please retry');

        global $wpdb, $table_name;
        $wpdb->replace($table_name, [
            'artist_account'  => $account,
            'platform_wallet' => IMC_PLATFORM_WALLET,
            'status'          => 'active',
            'auth_tx_hash'    => $tx_hash,
            'authorized_at'   => current_time('mysql'),
            'created_at'      => current_time('mysql'),
            'updated_at'      => current_time('mysql')
        ]);
        auth_log("joey_verify_authorize: $account authorized tx=$tx_hash");
        json_success(['account' => $account, 'is_authorized' => true, 'status' => 'authorized', 'tx_hash' => $tx_hash]);
    }

    json_error('Unknown action or invalid method', 400);

} catch (Exception $e) {
    auth_log("Error: " . $e->getMessage());
    json_error('Server error', 500);
}