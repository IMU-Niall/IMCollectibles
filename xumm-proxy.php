<?php
// File: xumm-proxy.php (CLEAN, PRODUCTION-READY)
// Notes:
// - Single POST handler (routes JSON 'action' or handles Xumm webhooks). No duplicate webhook blocks.
// - Single GET action route for check_nft_owner (with nonce); removed duplicate at bottom.
// - Sign-In payload now has custom_meta at ROOT (not under options).
// - verify_escrow_funds() uses account_info (XRP) and account_lines (IOUs) correctly.
// - Rewards endpoints preserved. swap_intake webhook forward preserved.
// - Logging, nonce validation, and DB updates preserved.

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
// SEC (21 Sep 2026): error_log is set after wp-load, below -- WP_CONTENT_DIR does not exist yet here.

ob_start();
require_once __DIR__ . '/wp-load.php';
ini_set('error_log', WP_CONTENT_DIR . '/imc-logs/php-error.log');
require_once ABSPATH . 'wp-admin/includes/file.php';

// === XRPL RPC resolver (centralized) =========================================
// Precedence: IMU_XRPL_RPC (env or constant) -> XRP_RPC_URL (wp-config) -> xrplcluster.com
if (!defined('IMU_XRPL_RPC')) {
    define('IMU_XRPL_RPC',
        (getenv('IMU_XRPL_RPC') ?: (
            defined('XRP_RPC_URL') ? XRP_RPC_URL : 'https://xrplcluster.com'
        ))
    );
}
function imu_xrpl_rpc_url(): string {
    $url = IMU_XRPL_RPC ?: (defined('XRP_RPC_URL') ? XRP_RPC_URL : '');
    if (!$url) $url = 'https://xrplcluster.com';
    return rtrim($url, '/');
}
function imu_xrpl_rpc(string $method, array $params = [], int $timeout = 12): array {
    $body = json_encode(['method' => $method, 'params' => [$params]]);
    $res  = wp_remote_post(imu_xrpl_rpc_url(), [
        'body'    => $body,
        'headers' => ['Content-Type' => 'application/json'],
        'timeout' => $timeout,
    ]);
    if (is_wp_error($res)) {
        return ['error' => $res->get_error_message()];
    }
    $json = json_decode(wp_remote_retrieve_body($res), true);
    return is_array($json) ? $json : ['error' => 'bad_json'];
}

// Safely fetch txjson from Xumm payloads (supports both shapes)
function imu_get_payload_txjson(array $p) : array {
    // Webhook -> we usually have raw $data
    if (isset($p['payload']['txjson']) && is_array($p['payload']['txjson'])) {
        return $p['payload']['txjson'];
    }
    if (isset($p['payload']['request_json']) && is_array($p['payload']['request_json'])) {
        return $p['payload']['request_json'];
    }
    // Direct webhook POST (root-level payloadResponse only)
    if (isset($p['request_json']) && is_array($p['request_json'])) {
        return $p['request_json'];
    }
    return [];
}

// Derive a robust type string
function imu_resolve_payload_type(array $p) : string {
    // Prefer explicit custom_meta
    $t = $p['custom_meta']['type'] ?? ($p['custom_meta']['blob']['type'] ?? null);
    if ($t) return $t;

    $txjson = imu_get_payload_txjson($p);
    $tt = $txjson['TransactionType'] ?? '';

    if ($tt === 'NFTokenBurn') return 'nft_burn';
    if ($tt === 'Payment')     return 'tip';
    if ($tt === 'TrustSet')    return 'trustline';
    if ($tt === 'NFTokenAcceptOffer') return 'nft_offer_accept';
    if ($tt === 'NFTokenCreateOffer') return 'nft_offer';
    if ($tt === 'NFTokenCancelOffer') return 'nft_offer_cancel';

    return 'signin';
}


// JSON error helper (sets HTTP code)
function json_error($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

// Load .env for wallet config
// v280: defined() guards prevent "Constant already defined" warnings when
// xumm-proxy.php is loaded more than once in the same PHP process.
if (file_exists(__DIR__ . '/.env')) {
    $env = parse_ini_file(__DIR__ . '/.env');
    if (!defined('PROJECT_WALLET'))  define('PROJECT_WALLET',  $env['PROJECT_WALLET']  ?? '');
    if (!defined('XUMM_API_KEY'))    define('XUMM_API_KEY',    $env['XUMM_API_KEY']    ?? '');
    if (!defined('XUMM_API_SECRET')) define('XUMM_API_SECRET', $env['XUMM_API_SECRET'] ?? '');
} else {
    if (!defined('PROJECT_WALLET'))  define('PROJECT_WALLET',  '');
    if (!defined('XUMM_API_KEY'))    define('XUMM_API_KEY',    '');
    if (!defined('XUMM_API_SECRET')) define('XUMM_API_SECRET', '');
}

// CORS (allow main + www + trade) + preflight
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = [
    'https://imcollectibles.xyz',
    'https://www.imcollectibles.xyz',
    'https://trade.imcollectibles.xyz',
    'https://airdrop.imcollectibles.xyz',
    'https://imcollectibles.io',
    'https://www.imcollectibles.io',
    'https://trade.imcollectibles.io',
    'https://airdrop.imcollectibles.io',
];
if (in_array($origin, $allowed, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
} else {
    header('Access-Control-Allow-Origin: https://imcollectibles.io'); // legacy default
}
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X_IMU_PROXY');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Logging
// Logs are written outside any web-served directory, with a deny rule created
// alongside them.
$imc_log_dir  = WP_CONTENT_DIR . '/imc-logs';
if (!is_dir($imc_log_dir)) {
    mkdir($imc_log_dir, 0755, true);
}
if (!file_exists($imc_log_dir . '/.htaccess')) {
    @file_put_contents($imc_log_dir . '/.htaccess',
        "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
}
$log_file     = $imc_log_dir . '/xumm-webhook.log';
$bithomp_log  = $imc_log_dir . '/bithomp_api.log';

// Keys / wallets
$api_key         = defined('XUMM_API_KEY') ? XUMM_API_KEY : ini_get('XUMM_API_KEY');
$api_secret      = defined('XUMM_API_SECRET') ? XUMM_API_SECRET : ini_get('XUMM_API_SECRET');
$bithomp_api_key = defined('BITHOMP_API_KEY') ? BITHOMP_API_KEY : '';
$escrow_account  = defined('IMU_ESCROW_ACCOUNT') ? IMU_ESCROW_ACCOUNT : 'raCHBwU87Y4ya7E1zGJtHY4i76XDgxZFcz';
$fee_wallet      = defined('PLATFORM_FEE_WALLET') ? PLATFORM_FEE_WALLET : 'riMCgymFVzdqQoTR82m5oUJE697bDHrJm';

// -------------------- HELPERS --------------------

function get_on_ledger_transfer_fee($nft_id) {
    global $log_file;
    $rpc_urls = [imu_xrpl_rpc_url(), 'https://s1.ripple.com:51234/', 'https://s2.ripple.com:51234/'];

    foreach ($rpc_urls as $rpc_url) {
        $req = ['method' => 'nft_info', 'params' => [['nft_id' => $nft_id, 'ledger_index' => 'validated']]];
        $res = wp_remote_post($rpc_url, [
            'body'    => json_encode($req),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 10
        ]);
        if (is_wp_error($res)) {
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "nft_info error for $nft_id @ $rpc_url: " . $res->get_error_message() . "\n", FILE_APPEND);
            continue;
        }
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if (($body['result']['status'] ?? '') === 'success') {
            $tf = $body['result']['transfer_fee'] ?? null;
            if ($tf !== null) {
                file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "TransferFee for $nft_id = $tf (bps)\n", FILE_APPEND);
                return $tf / 100; // basis points -> percent
            }
        }
    }
    file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "TransferFee unavailable for $nft_id\n", FILE_APPEND);
    return false;
}

function extract_royalty_info($bithomp_data) {
    $issuer = $bithomp_data['issuer'] ?? 'unknown';
    $royalty_percent = ($bithomp_data['transferFee'] ?? 0) / 100;
    $wallet = $bithomp_data['metadata']['royalty_wallet'] ?? ($bithomp_data['issuer'] ?? '');
    return [$royalty_percent, $wallet];
}

// ================================================================================
// LOCAL METADATA API - For image/metadata only (Dec 2024)
// Does NOT affect XRPL on-ledger checks (balances, ownership, counts)
// FIXED: Uses POST to ?action=batch (same as my-nfts-handler.php)
// ================================================================================
function fetch_from_local_metadata_api($nft_ids) {
    if (!is_array($nft_ids)) $nft_ids = [$nft_ids];
    if (empty($nft_ids)) return [];
    
    $results = [];
    foreach (array_chunk($nft_ids, 100) as $chunk) {
        // Use POST to batch endpoint (matches my-nfts-handler.php)
        $url = 'https://metadata.imcollectibles.io/?action=batch';
        $response = wp_remote_post($url, [
            'timeout' => 10,
            'sslverify' => true,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ],
            'body' => json_encode(['ids' => array_values($chunk)])
        ]);
        
        if (is_wp_error($response)) {
            error_log('VPS batch API error: ' . $response->get_error_message());
            continue;
        }
        if (wp_remote_retrieve_response_code($response) !== 200) {
            error_log('VPS batch API returned: ' . wp_remote_retrieve_response_code($response));
            continue;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        // Handle both response formats: {nfts: [...]} or {results: [...]}
        $nfts_array = $body['nfts'] ?? $body['results'] ?? [];
        
        if (empty($nfts_array)) continue;
        
        foreach ($nfts_array as $nft) {
            // Handle multiple possible ID field names
            // FIX: Normalize to uppercase for consistent lookups
            $nft_id = strtoupper($nft['nftokenID'] ?? $nft['nft_token_id'] ?? $nft['NFTokenID'] ?? '');
            if (!$nft_id || !preg_match('/^[0-9A-F]{64}$/', $nft_id)) continue;
            
            // Normalize the response structure
            $meta = $nft['metadata'] ?? [];
            $image = $nft['image_proxy'] ?? $meta['image'] ?? $nft['image_resolved'] ?? $nft['image_url'] ?? $nft['image'] ?? null;
            
            // Convert IPFS to gateway
            if ($image && strpos($image, 'ipfs://') === 0) {
                $image = 'https://<your-pinata-gateway>/ipfs/' . substr($image, 7);
            }
            
            $results[$nft_id] = [
                'nftokenID' => $nft_id,
                'issuer' => $nft['issuer'] ?? 'unknown',
                'metadata' => [
                    'name' => $meta['name'] ?? $nft['name'] ?? 'Unnamed NFT',
                    'image' => $image,
                    'description' => $meta['description'] ?? $nft['description'] ?? '',
                    'attributes' => $meta['attributes'] ?? $nft['attributes'] ?? []
                ]
            ];
        }
    }
    return $results;
}
// ================================================================================

function get_nft_metadata($nft_id) {
    global $bithomp_api_key, $bithomp_log, $log_file;

    // FIX: Normalize NFT ID to uppercase for consistent caching/lookups
    $nft_id = strtoupper(trim($nft_id));
    if (!preg_match('/^[0-9A-F]{64}$/', $nft_id)) {
        return null;
    }

    // Check cache first
    $transient_key = 'bithomp_nft_' . md5($nft_id);
    if ($cached = get_transient($transient_key)) {
        return $cached;
    }

    // Get royalty from on-ledger (always do this - critical for trading)
    $royalty_percent = get_on_ledger_transfer_fee($nft_id);
    $royalty_wallet = '';
    $issuer = 'unknown';
    $image = '/wp-content/uploads/fallback-nft.svg';
    $nft_name = 'Unnamed NFT';
    $trustline_warning = null;

    // === TRY LOCAL API FIRST (for image/metadata only) ===
    $local_data = fetch_from_local_metadata_api([$nft_id]);
    
    if (!empty($local_data[$nft_id])) {
        $local = $local_data[$nft_id];
        $issuer = $local['issuer'] ?? 'unknown';
        $image = $local['metadata']['image'] ?? $image;
        $nft_name = $local['metadata']['name'] ?? 'Unnamed NFT';
        $royalty_wallet = $issuer;
        
        // Still check trustline (on-ledger)
        if (!validate_issuer_trustline($issuer, 'XFT')) {
            $trustline_warning = "Issuer $issuer lacks XFT trustline, non-XRP offers may fail.";
        }
    } else {
        // === FALLBACK TO BITHOMP ===
        $url = "https://bithomp.com/api/v2/nft/$nft_id?uri=true&metadata=true&assets=true";
        $response = wp_remote_get($url, [
            'headers' => ['x-bithomp-token' => $bithomp_api_key],
            'timeout' => 10
        ]);
        if (!is_wp_error($response)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($body['nftokenID'])) {
                $issuer = $body['issuer'] ?? 'unknown';
                if ($royalty_percent === false) {
                    list($royalty_percent, $royalty_wallet) = extract_royalty_info($body);
                } else {
                    $royalty_wallet = $body['metadata']['royalty_wallet'] ?? ($body['issuer'] ?? '');
                }
                $image = $body['assets']['image'] ?? ($body['metadata']['image'] ?? ($body['uri'] ?? $image));
                $nft_name = $body['metadata']['name'] ?? 'Unnamed NFT';
                if (!validate_issuer_trustline($issuer, 'XFT')) {
                    $trustline_warning = "Issuer $issuer lacks XFT trustline, non-XRP offers may fail.";
                }
            }
        }
    }

    if ($royalty_percent === false || $royalty_percent === 0) {
        $royalty_percent = 0;
    }

    $metadata = [
        'nft_name' => $nft_name,
        'royalty_percent' => $royalty_percent,
        'royalty_wallet' => $royalty_wallet,
        'issuer' => $issuer,
        'image' => $image,
        'nftokenID' => $nft_id,
        'trustline_warning' => $trustline_warning
    ];

    cache_nft_to_db($nft_id, $metadata);
    set_transient($transient_key, $metadata, 3600);
    return $metadata;
}

// After fetching metadata from Bithomp or on-ledger, cache it in the database for long-term storage and faster grid loading.
function cache_nft_to_db($nft_id, $metadata) {
    global $wpdb;
    $table_name = 'wp_xumm_nft_cache'; // Force 'wp_' prefix to match desired table name and avoid prefix issues.

    // FIX: Normalize NFT ID to uppercase to prevent duplicates
    $nft_id = strtoupper(trim($nft_id));

    // Prepare metadata as JSON for longtext field.
    $metadata_json = wp_json_encode($metadata);

    // Insert or update based on nftoken_id (unique index).
    $wpdb->replace(
        $table_name,
        [
            'nftoken_id' => $nft_id,
            'issuer' => $metadata['issuer'] ?? 'unknown',
            'taxon' => isset($metadata['taxon']) ? (int)$metadata['taxon'] : 0,
            'metadata' => $metadata_json,
            'owner' => $metadata['owner'] ?? null,  // Owner may be null if not set.
        ],
        ['%s', '%s', '%d', '%s', '%s']
    );

    if ($wpdb->last_error) {
        error_log("DB insert error for NFT $nft_id: " . $wpdb->last_error);
    } else {
        error_log("Cached NFT $nft_id to database.");
    }
}


function validate_issuer_trustline($issuer, $currency) {
    if ($currency === 'XRP') return true;
    $r = imu_xrpl_rpc('account_lines', ['account' => $issuer, 'ledger_index' => 'current']);
    if (!empty($r['error'])) return false;

    foreach ($r['result']['lines'] ?? [] as $line) {
        if (($line['currency'] ?? '') === $currency && ($line['account'] ?? '') === 'rGpnoqYLzWytxwQhhz715nRbqyCHM7zhxt') {
            return true;
        }
    }
    return false;
}

function validate_nft_ownership($account, $nft_ids) {
    global $bithomp_api_key, $log_file, $bithomp_log;
    $url = "https://bithomp.com/api/v2/nfts?account=$account&uri=true&metadata=true&assets=true";
    $response = wp_remote_get($url, [
        'headers' => ['x-bithomp-token' => $bithomp_api_key],
        'timeout' => 8
    ]);
    if (is_wp_error($response)) {
        file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Ownership validation error for $account: " . $response->get_error_message() . "\n", FILE_APPEND);
        return false;
    }
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($body['error'])) {
        file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Bithomp API error for ownership: " . $body['error'] . "\n", FILE_APPEND);
        return false;
    }
    // FIX: Normalize both arrays to uppercase for case-insensitive comparison
    $owned_nfts = array_map('strtoupper', array_column($body['nfts'] ?? [], 'nftokenID'));
    $nft_ids_upper = array_map('strtoupper', $nft_ids);
    $valid = array_intersect($nft_ids_upper, $owned_nfts) === $nft_ids_upper;
    file_put_contents($bithomp_log, date('[Y-m-d H:i:s] ') . "Ownership check for $account, NFTs: " . implode(',', $nft_ids_upper) . ", Valid: " . ($valid ? 'Yes' : 'No') . "\n", FILE_APPEND);
    return $valid;
}

function validate_xft_trustline($account) {
    $r = imu_xrpl_rpc('account_lines', ['account' => $account, 'ledger_index' => 'current']);
    if (!empty($r['error'])) return false;

    foreach ($r['result']['lines'] ?? [] as $line) {
        if (($line['account'] ?? '') === 'rGpnoqYLzWytxwQhhz715nRbqyCHM7zhxt' && ($line['currency'] ?? '') === 'XFT') {
            return true;
        }
    }
    return false;
}

function verify_escrow_funds($account, $amount, $currency, $issuer = null) {
    $amount   = (float)$amount;
    $currency = strtoupper((string)$currency);
    if ($amount <= 0 || empty($account) || empty($currency)) return false;

    // XRP balance: account_info -> drops
    if ($currency === 'XRP') {
        $r = imu_xrpl_rpc('account_info', [
            'account'      => $account,
            'ledger_index' => 'validated'
        ]);
        if (!empty($r['error'])) return false;
        $drops = (int)($r['result']['account_data']['Balance'] ?? 0);
        return ($drops / 1000000) >= $amount;
    }

    // IOU trustline balance via account_lines; require issuer
    if (empty($issuer)) return false;
    $r = imu_xrpl_rpc('account_lines', [
        'account'      => $account,
        'peer'         => $issuer,
        'ledger_index' => 'validated',
        'limit'        => 10
    ]);
    if (!empty($r['error'])) return false;

    foreach (($r['result']['lines'] ?? []) as $line) {
        $cur  = $line['currency'] ?? '';
        $peer = $line['account']  ?? ($line['issuer'] ?? '');
        $bal  = (float)($line['balance'] ?? 0);
        if ($cur === $currency && $peer === $issuer) {
            return $bal >= $amount;
        }
    }
    return false;
}

function create_xumm_payload_with_webhook(array $txjson, array $custom_meta_blob = []) {
    global $api_key, $api_secret, $log_file;

    $type        = $custom_meta_blob['type'] ?? (isset($txjson['TransactionType']) ? strtolower($txjson['TransactionType']) : 'payload');
    $instruction = 'Open in Xaman to review & sign';

    $payload = [
        'txjson'      => $txjson,
        'webhook'     => 'https://imcollectibles.io/xumm-proxy.php',
        'custom_meta' => [
            'identifier'  => uniqid('imu_', true),
            'instruction' => $instruction,
            'blob'        => $custom_meta_blob,
            'type'        => $type
        ],
        'options'     => [
            'expire'     => 180,
            'return_url' => [
                'web' => home_url('/events/?tip_sent=1'),
                'app' => home_url('/events/?tip_sent=1')
            ],
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
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT        => 15
    ]);
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Xumm API request: " . json_encode($payload) . "\n", FILE_APPEND);
    file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Xumm API response (HTTP $status): $response\n", FILE_APPEND);

    $data = json_decode($response, true);
    if ($status !== 200 || empty($data['uuid']) || empty($data['refs']['qr_png'])) {
        $err = is_array($data) ? ($data['error']['message'] ?? 'Xumm API error') : 'Xumm API error';
        return ['error' => $err];
    }
    return $data;
}

// -------------------- SIGN-IN (Xaman) --------------------
if (isset($_GET['signin']) && $_GET['signin'] === 'true') {
    $payload = [
        'txjson'  => ['TransactionType' => 'SignIn'],
        'webhook' => 'https://imcollectibles.io/xumm-proxy.php',
        'custom_meta' => [
            'instruction' => 'Sign in to access your profile.',
            'blob'        => ['type' => 'signin']
        ],
        'options' => [
            'expire' => 300,
            'return_url' => [
                'web' => 'https://imcollectibles.io/xaman-signin-complete.php?id={id}',
                'app' => 'https://imcollectibles.io/xaman-signin-complete.php?id={id}'
            ]
        ]
    ];
    $ch = curl_init('https://xumm.app/api/v1/platform/payload');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'X-API-Key: ' . $api_key,
            'X-API-Secret: ' . $api_secret,
            'Content-Type: application/json'
        ]
    ]);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Curl error: " . curl_error($ch) . "\n", FILE_APPEND);
        json_error('Curl error: ' . curl_error($ch));
    }
    $data = json_decode($response, true);
    curl_close($ch);
    if ($data && isset($data['uuid'])) {
        $return_url = str_replace('{id}', $data['uuid'], $payload['options']['return_url']['web']);
        file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "New payload created: {$data['uuid']} with return URL: $return_url\n", FILE_APPEND);
        echo json_encode([
            'uuid' => $data['uuid'],
            'qr' => $data['refs']['qr_png'],
            'deeplink' => $data['next']['always']
        ]);
    } else {
        file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Failed to create payload: " . $response . "\n", FILE_APPEND);
        json_error('Failed to create payload: ' . $response);
    }
    exit;
}

// -------------------- MASTER POST HANDLER --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data  = json_decode($input, true);
    // Log the action and body size only -- request bodies carry wallets and amounts.
    file_put_contents($log_file, date('[Y-m-d H:i:s] ') . 'POST received: action=' . (isset($data['action']) ? preg_replace('/[^A-Za-z0-9_-]/', '', (string) $data['action']) : '-') . ' bytes=' . strlen($input) . "\n", FILE_APPEND);

    // Action from JSON body or query string
    $action = isset($data['action']) ? $data['action'] : ($_GET['action'] ?? '');
    $nonce  = isset($data['_wpnonce']) ? $data['_wpnonce'] : ($_GET['_wpnonce'] ?? '');

if (!empty($action)) {
        // Allow airdrop NFT claims and polling without nonce (from airdrop subdomain only)
        // SEC (21 Sep 2026): the origin test MUST be parenthesised. Without the inner
        // brackets && binds tighter than ||, making the second origin comparison a
        // standalone disjunct rather than a condition on the action, which widens this
        // exemption well beyond the two airdrop actions it is meant to cover.
        $is_airdrop_origin = in_array($origin, ['https://airdrop.imcollectibles.xyz', 'https://airdrop.imcollectibles.io'], true);
        $is_airdrop_request = $is_airdrop_origin && (
            ($action === 'create_payload' &&
             isset($data['payload']['txjson']['TransactionType']) &&
             $data['payload']['txjson']['TransactionType'] === 'NFTokenAcceptOffer')
            || $action === 'get_payload'
        );
        
        // --------- ACTION ROUTES (require nonce except airdrop) ----------
        if (!$is_airdrop_request && !wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Invalid nonce for action $action: $nonce\n", FILE_APPEND);
            json_error('Invalid nonce', 400);
        }

        switch ($action) {
            case 'create_payload': {
                // For airdrop NFT claims
                if (!isset($data['payload']['txjson'])) {
                    json_error('Missing txjson', 400);
                }
                
                $txjson = $data['payload']['txjson'];
                $options = $data['payload']['options'] ?? [];
                
                // Only allow NFTokenAcceptOffer from airdrop
                if ($txjson['TransactionType'] !== 'NFTokenAcceptOffer') {
                    json_error('Only NFTokenAcceptOffer allowed', 400);
                }
                
                $payload = [
                    'txjson' => $txjson,
                    'options' => array_merge($options, ['expire' => 5])
                ];
                
                $ch = curl_init('https://xumm.app/api/v1/platform/payload');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($payload),
                    CURLOPT_HTTPHEADER => [
                        'X-API-Key: ' . $api_key,
                        'X-API-Secret: ' . $api_secret,
                        'Content-Type: application/json'
                    ]
                ]);
                $response = curl_exec($ch);
                $err = curl_error($ch);
                curl_close($ch);
                
                if ($err) {
                    json_error('Curl error: ' . $err, 500);
                }
                
                $result = json_decode($response, true);
                file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Airdrop claim payload created: " . json_encode($result) . "\n", FILE_APPEND);
                
                echo json_encode($result);
                exit;
            }
            
            case 'get_payload': {
                // For polling payload status
                $uuid = $data['uuid'] ?? '';
                if (empty($uuid)) {
                    json_error('Missing UUID', 400);
                }
                
                $ch = curl_init('https://xumm.app/api/v1/platform/payload/' . $uuid);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        'X-API-Key: ' . $api_key,
                        'X-API-Secret: ' . $api_secret
                    ]
                ]);
                $response = curl_exec($ch);
                curl_close($ch);
                
                echo $response;
                exit;
            }
            
            case 'create-tip': {
                if (!is_array($data) || !isset($data['payload']['txjson']) || !isset($data['payload']['custom_meta']['blob']['type']) || $data['payload']['custom_meta']['blob']['type'] !== 'tip') {
                    file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Invalid tip payload structure: " . json_encode($data, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);
                    json_error('Invalid tip payload', 400);
                }

                $txjson      = $data['payload']['txjson'];
                $custom_meta = $data['payload']['custom_meta'];
                $event_id        = $custom_meta['blob']['event_id'] ?? 0;
                $tip_account_id  = $custom_meta['blob']['tip_account_id'] ?? 0;
                $destination     = $txjson['Destination'] ?? '';
                $amount          = isset($txjson['Amount']['value']) ? (float)$txjson['Amount']['value'] : ((float)$txjson['Amount'] / 1000000);
                $currency        = isset($txjson['Amount']['currency']) ? $txjson['Amount']['currency'] : 'XRP';
                $account         = $custom_meta['blob']['account'] ?? '';

                // Validate inputs
                if (empty($event_id) || empty($tip_account_id) || empty($destination) || $amount < 0.01 || !preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $destination)) {
                    file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Invalid tip data: event_id=$event_id, tip_account_id=$tip_account_id, destination=$destination, amount=$amount\n", FILE_APPEND);
                    json_error('Invalid tip data', 400);
                }

                // Validate recipient in DB
                global $wpdb;
                $tip_accounts_table = $wpdb->prefix . 'tip_accounts';
                $tip_account = $wpdb->get_row($wpdb->prepare(
                    "SELECT xrp_address, status FROM $tip_accounts_table WHERE id = %d",
                    $tip_account_id
                ));
                if (!$tip_account) json_error('Invalid tip recipient', 400);
                if ($tip_account->xrp_address !== $destination) json_error('Invalid tip recipient', 400);
                if ($tip_account->status !== 'active') json_error('Recipient account is not active', 400);

                // Validate XFT trustline for sender if currency is XFT
                if ($currency === 'XFT') {
                    if (empty($account) || !validate_xft_trustline($account)) {
                        file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "No XFT trustline for account: $account\n", FILE_APPEND);
                        json_error('Account lacks XFT trustline', 400);
                    }
                }

                // Create Xumm payload
                $payload_response = create_xumm_payload_with_webhook($txjson, $custom_meta['blob']);
                if (isset($payload_response['error'])) {
                    file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Failed to create tip payload: " . $payload_response['error'] . "\n", FILE_APPEND);
                    json_error('Failed to create tip payload: ' . $payload_response['error'], 500);
                }

                // Store in xumm_status for fallback
                $inserted = $wpdb->insert($wpdb->prefix . 'xumm_status', [
                    'uuid'           => $payload_response['uuid'],
                    'account'        => $account,
                    'signed'         => 0,
                    'timestamp'      => time(),
                    'claimed'        => 0,
                    'type'           => 'tip',
                    'tip_event_id'   => $event_id,
                    'tip_account_id' => $tip_account_id,
                    'tip_amount'     => $amount,
                    'tip_currency'   => $currency
                ]);

                if ($inserted === false) {
                    file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Failed to insert tip status for UUID: {$payload_response['uuid']}: " . $wpdb->last_error . "\n", FILE_APPEND);
                }

                echo json_encode([
                    'success'  => true,
                    'uuid'     => $payload_response['uuid'],
                    'qr'       => $payload_response['refs']['qr_png'],
                    'deeplink' => $payload_response['next']['always']
                ]);
                exit;
            }
            
            // =========================================================================
            // 🎮 STORE-PENDING WITH GAME BOOSTER SUPPORT
            // =========================================================================
            case 'store-pending': {
    $xrpl_account = sanitize_text_field($data['account'] ?? '');
    $rewards = $data['rewards'] ?? [];
    if (empty($xrpl_account) || empty($rewards) || !preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $xrpl_account)) {
        file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Store pending invalid data: account=$xrpl_account, rewards=" . json_encode($rewards) . "\n", FILE_APPEND);
        json_error('Invalid request data', 400);
    }
    global $wpdb;
    $table_name = $wpdb->prefix . 'game_xft_claims';

    // Ensure table exists; widen sources to TEXT
    $wpdb->query("CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        xrpl_account VARCHAR(35) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        session_id VARCHAR(50) NOT NULL,
        uuid VARCHAR(36) DEFAULT '',
        status VARCHAR(20) DEFAULT 'pending',
        tx_hash VARCHAR(64) DEFAULT '',
        created_at DATETIME NOT NULL,
        sources TEXT DEFAULT '',
        currency VARCHAR(10) DEFAULT 'XFT'
    )");
    $wpdb->query("ALTER TABLE $table_name MODIFY sources TEXT");
    $wpdb->query("ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS currency VARCHAR(10) DEFAULT 'XFT'");

    // Add session_id if missing (old tables)
    $columns = $wpdb->get_results("SHOW COLUMNS FROM $table_name");
    $has_session_id = false;
    foreach ($columns as $col) {
        if ($col->Field === 'session_id') { $has_session_id = true; break; }
    }
    if (!$has_session_id) {
        $wpdb->query("ALTER TABLE $table_name ADD session_id VARCHAR(50) NOT NULL DEFAULT ''");
    }

    // =========================================================================
    // 🎮 GAME BOOSTER CHECK - Check for active game boost
    // =========================================================================
    $game_boost = ['active' => false, 'multiplier' => 1.0];
    if (function_exists('imu_get_active_boost')) {
        $game_boost = imu_get_active_boost($xrpl_account, 'game');
        if ($game_boost['active']) {
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "🎮 GAME BOOST ACTIVE: account=$xrpl_account, tier={$game_boost['tier']}, multiplier={$game_boost['multiplier']}x\n", FILE_APPEND);
        }
    }
    $boost_multiplier = $game_boost['active'] ? (float)$game_boost['multiplier'] : 1.0;
    $boost_tier = $game_boost['active'] ? $game_boost['tier'] : null;
    // =========================================================================

    // Insert rewards with dedupe + cleanup pending > 7 days -> expired
    $insertedAny = false;
    $errors = [];
    $total_boosted = 0;
    
    foreach ($rewards as $reward) {
        $base_amount = (float)($reward['amount'] ?? 0);
        $session_id = sanitize_text_field($reward['session_id'] ?? '');
        $sources = $reward['sources'] ?? [];
        $currency = sanitize_text_field($reward['currency'] ?? 'XFT');

        if ($base_amount <= 0 || $session_id === '') continue;

        // Per-reward amount cap (defence in depth — VPS also caps at 10,000 total)
        $max_per_reward = ($currency === 'XMEME') ? 1000000 : 10000;
        if ($base_amount > $max_per_reward) {
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Rejected oversized reward: $base_amount $currency (max: $max_per_reward) for $xrpl_account\n", FILE_APPEND);
            continue;
        }

        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE session_id = %s", $session_id));
        if ($exists > 0) continue;

        $cleanup = $wpdb->query($wpdb->prepare("UPDATE $table_name SET status = 'expired' WHERE created_at < NOW() - INTERVAL 7 DAY AND status = %s", 'pending'));
        if ($cleanup > 0) {
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Expired cleanup (updated to expired): $cleanup rows\n", FILE_APPEND);
        }

        // =====================================================================
        // 🎮 APPLY GAME BOOST MULTIPLIER
        // =====================================================================
        $final_amount = $base_amount;
        
        if ($boost_multiplier > 1.0) {
            $final_amount = round($base_amount * $boost_multiplier, 2);
            $boost_bonus = round($final_amount - $base_amount, 2);
            
            // Add boost source entry for transparency
            $tier_labels = ['bronze' => '🥉 Bronze', 'silver' => '🥈 Silver', 'gold' => '🥇 Gold'];
            $tier_label = $tier_labels[$boost_tier] ?? ucfirst($boost_tier);
            $sources[] = [
                'amount' => $boost_bonus,
                'source' => "{$tier_label} Game Booster ({$boost_multiplier}x)"
            ];
            
            $total_boosted += $boost_bonus;
            
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "🎮 BOOST APPLIED: base=$base_amount, boosted=$final_amount (+$boost_bonus), session=$session_id\n", FILE_APPEND);
        }
        // =====================================================================

        $sources_json = json_encode($sources);

        $insert = $wpdb->insert($table_name, [
            'xrpl_account' => $xrpl_account,
            'amount' => $final_amount,  // Use boosted amount
            'session_id' => $session_id,
            'uuid' => '',
            'status' => 'pending',
            'tx_hash' => '',
            'created_at' => current_time('mysql', 1),
            'sources' => $sources_json,
            'currency' => $currency
        ]);

        if ($insert === false) {
            $errors[] = "Insert failed for $session_id: " . $wpdb->last_error;
        } else {
            $insertedAny = true;
        }
    }
    
    // Log summary if boost was applied
    if ($total_boosted > 0) {
        file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "🎮 BOOST SUMMARY: account=$xrpl_account, total_bonus=$total_boosted, tier=$boost_tier\n", FILE_APPEND);
    }
    
    if (!empty($errors)) json_error(implode('; ', $errors), 500);
    
    // Return success with boost info
    echo json_encode([
        'success' => true,
        'boost_applied' => $boost_multiplier > 1.0,
        'boost_multiplier' => $boost_multiplier,
        'boost_tier' => $boost_tier
    ]);
    exit;
}

            case 'verify-rewards': {
    $xrpl_account = sanitize_text_field($data['account'] ?? '');
    if (empty($xrpl_account) || !preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $xrpl_account)) {
        file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Verify rewards invalid account: $xrpl_account\n", FILE_APPEND);
        json_error('Invalid account', 400);
    }
    global $wpdb;
    $table_name = $wpdb->prefix . 'game_xft_claims';
    $cooldown_table = $wpdb->prefix . 'game_cooldowns';
    try {
        // Cleanup expired
        $cleanup = $wpdb->query($wpdb->prepare("UPDATE $table_name SET status = 'expired' WHERE created_at < NOW() - INTERVAL 7 DAY AND status = %s", 'pending'));
        file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Expired cleanup on verify (updated to expired): $cleanup rows\n", FILE_APPEND);

        // Require XFT trustline (for XFT; assume XMEME has own)
        if (!validate_xft_trustline($xrpl_account)) {
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "No XFT trustline for account: $xrpl_account\n", FILE_APPEND);
            json_error('Account lacks XFT trustline', 400);
        }

        // Featured NFT ownership check
        $has_nft = false;
        if (function_exists('has_featured_nft')) {
            try {
                $has_nft = has_featured_nft($xrpl_account);
            } catch (Exception $e) {
                file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "has_featured_nft error for $xrpl_account: " . $e->getMessage() . "\n", FILE_APPEND);
                json_error('NFT validation failed: ' . $e->getMessage(), 500);
            }
        } else {
            // Fallback: XRPL account_nfts scan
            $nfts = [];
            $marker = null;
            $attempts = 0;
            $max_pages = 20;
            do {
                $params = ['account' => $xrpl_account, 'ledger_index' => 'validated', 'limit' => 200];
                if ($marker) $params['marker'] = $marker;
                $resp = imu_xrpl_rpc('account_nfts', $params, 12);
                if (!empty($resp['error'])) json_error('NFT fetch failed: ' . $resp['error'], 500);
                foreach (($resp['result']['account_nfts'] ?? []) as $nft) {
                    $issuer = $nft['Issuer'] ?? 'unknown';
                    $taxon = isset($nft['NFTokenTaxon']) ? (int)$nft['NFTokenTaxon'] : -1;
                    if (
                        ($issuer === 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR' && $taxon === 0) ||
                        ($issuer === 'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga' && $taxon === 717825) ||
                        ($issuer === 'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt' && $taxon === 1056369418) ||
                        ($issuer === 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR' && $taxon === 777)
                    ) { $has_nft = true; break 2; }
                }
                $marker = $resp['result']['marker'] ?? null;
                $attempts++;
            } while ($marker && $attempts < $max_pages);
        }
        if (!$has_nft) json_error('Account lacks NFT from featured collections (Guardians, Protectors Las Vegas, Freq, or Ledger)', 400);

        // Get pending with currency
        $pending = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE xrpl_account = %s AND status = %s",
            $xrpl_account, 'pending'
        ), ARRAY_A);

        $xft_pending = [];
        $xmeme_pending = [];
        foreach ($pending as $p) {
            $curr = $p['currency'] ?? 'XFT';
            if ($curr === 'XMEME') $xmeme_pending[] = $p;
            else $xft_pending[] = $p;
        }

        $xft_amount = array_reduce($xft_pending, fn($s, $p) => $s + (float)$p['amount'], 0);
        $xmeme_amount = array_reduce($xmeme_pending, fn($s, $p) => $s + (float)$p['amount'], 0);

        if ($xft_amount <= 0 && $xmeme_amount <= 0) json_error('No pending rewards found on server', 400);

        $vps_url = defined('IMC_GAME_REWARDS_URL') ? IMC_GAME_REWARDS_URL : '';
        $tx_hashes = [];
        $errors = [];
        $new_cooldowns = ['XFT' => 0, 'XMEME' => 0];

        // Process XFT
        if ($xft_amount > 0) {
            $post_data = [
                'account' => $xrpl_account,
                'amount' => $xft_amount,
                'currency' => 'XFT',
                'nonce' => wp_create_nonce('xrpl_marketplace_nonce')
            ];
            $response = wp_remote_post($vps_url, [
                'body' => $post_data,
                'timeout' => 15,
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded']
            ]);
            if (is_wp_error($response)) {
                $errors['XFT'] = 'XFT Payment processing failed: ' . $response->get_error_message();
            } else {
                $response_code = wp_remote_retrieve_response_code($response);
                $body = json_decode(wp_remote_retrieve_body($response), true);
                if ($response_code === 200 && isset($body['success']) && $body['success']) {
                    $tx_hashes['XFT'] = $body['tx_hash'] ?? '';
                    foreach ($xft_pending as $p) {
                        $wpdb->update($table_name, ['status' => 'completed', 'tx_hash' => $tx_hashes['XFT']], ['id' => $p['id']]);
                    }
                    $new_cooldowns['XFT'] = time() + 86400;
                } else {
                    $error = $body['error'] ?? 'Unknown error';
                    $errors['XFT'] = 'XFT Payment processing failed: ' . $error;
                    if (preg_match('/Cooldown active for XFT. Try again in (\d+) seconds/', $error, $matches)) {
                        $seconds = (int)$matches[1];
                        $new_cooldowns['XFT'] = time() + $seconds;
                    }
                }
            }
        }

        // Process XMEME
        if ($xmeme_amount > 0) {
            $post_data = [
                'account' => $xrpl_account,
                'amount' => $xmeme_amount,
                'currency' => 'XMEME',
                'nonce' => wp_create_nonce('xrpl_marketplace_nonce')
            ];
            $response = wp_remote_post($vps_url, [
                'body' => $post_data,
                'timeout' => 15,
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded']
            ]);
            if (is_wp_error($response)) {
                $errors['XMEME'] = 'XMEME Payment processing failed: ' . $response->get_error_message();
            } else {
                $response_code = wp_remote_retrieve_response_code($response);
                $body = json_decode(wp_remote_retrieve_body($response), true);
                if ($response_code === 200 && isset($body['success']) && $body['success']) {
                    $tx_hashes['XMEME'] = $body['tx_hash'] ?? '';
                    foreach ($xmeme_pending as $p) {
                        $wpdb->update($table_name, ['status' => 'completed', 'tx_hash' => $tx_hashes['XMEME']], ['id' => $p['id']]);
                    }
                    $new_cooldowns['XMEME'] = time() + 86400;
                } else {
                    $error = $body['error'] ?? 'Unknown error';
                    $errors['XMEME'] = 'XMEME Payment processing failed: ' . $error;
                    if (preg_match('/Cooldown active for XMEME. Try again in (\d+) seconds/', $error, $matches)) {
                        $seconds = (int)$matches[1];
                        $new_cooldowns['XMEME'] = time() + $seconds;
                    }
                }
            }
        }

        // Always update cooldowns
        $wpdb->replace($cooldown_table, [
            'xrpl_account' => $xrpl_account,
            'cooldowns_data' => json_encode($new_cooldowns),
            'last_updated' => current_time('mysql', 1)
        ], ['%s', '%s', '%s']);
        file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Cooldown updated for $xrpl_account: " . json_encode($new_cooldowns) . "\n", FILE_APPEND);

        if (!empty($errors)) {
            json_error(implode('; ', $errors), 500);
        }

        echo json_encode(['success' => true, 'tx_hashes' => $tx_hashes]);
        exit;
    } catch (Exception $e) {
        file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Verify rewards error for $xrpl_account: " . $e->getMessage() . "\n", FILE_APPEND);
        json_error('Internal server error: ' . $e->getMessage(), 500);
    }
}

            case 'update-claim-status': {
                // ============================================================
                // AUTHORISATION. This action marks an XFT reward claim
                // 'completed' and writes its tx_hash. The nonce above is not
                // authentication -- get_nonce is registered on wp_ajax_nopriv,
                // so any caller can obtain one. Without the key check below,
                // anyone holding a claim uuid could burn that claim and write
                // an unverified hash into the record.
                //
                // Same shape as the vps_* guard in mint-on-demand-handler.php:
                // IMC_COMP_ADMIN_KEY, hash_equals, fail-closed on an unset key.
                // ============================================================
                $ucs_key      = defined('IMC_COMP_ADMIN_KEY') ? IMC_COMP_ADMIN_KEY : '';
                $ucs_provided = trim($data['admin_key'] ?? ($_POST['admin_key'] ?? ''));
                if (empty($ucs_key) || !hash_equals($ucs_key, $ucs_provided)) {
                    http_response_code(403);
                    json_error('Unauthorized', 403);
                }

                $uuid    = sanitize_text_field($data['uuid'] ?? '');
                $tx_hash = sanitize_text_field($data['tx_hash'] ?? '');
                if ($uuid === '') json_error('Missing uuid', 400);
                global $wpdb;
                $table_name = $wpdb->prefix . 'game_xft_claims';
                $updated = $wpdb->update($table_name, [
                    'status' => 'completed',
                    'tx_hash'=> $tx_hash
                ], ['uuid' => $uuid]);
                echo json_encode(['success' => $updated !== false]);
                exit;
            }
            
            // =================================================================
            // CASE 'nft_mint' - Creates NFTokenMint transaction for XUMM
            // Added: v40 - January 2026
            // =================================================================
            case 'nft_mint': {
                file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "NFT Mint request received\n", FILE_APPEND);
                
                // Get parameters from POST (sent by mint.js line 1306-1313)
                $uri = sanitize_text_field($_POST['uri'] ?? '');
                $transfer_fee = intval($_POST['transfer_fee'] ?? 0);
                $flags = intval($_POST['flags'] ?? 8);
                $flags |= 16; // tfMutable (0x10) — Phase 17: Dynamic NFT, always set
                $taxon = intval($_POST['taxon'] ?? 0);
                
                if (empty($uri)) {
                    file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "NFT Mint error: URI required\n", FILE_APPEND);
                    json_error('URI is required', 400);
                }
                
                if ($transfer_fee < 0 || $transfer_fee > 50000) {
                    json_error('Invalid transfer fee. Must be 0-50000 (0-50%)', 400);
                }
                
                $uri_hex = strtoupper(bin2hex($uri));
                
                file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "NFT Mint: URI=$uri, TransferFee=$transfer_fee, Flags=$flags, Taxon=$taxon\n", FILE_APPEND);
                
                // v471: Bithomp minter identification memo (append-only, non-functional)
                // Xaman-signed path — lowercase hex matches offer-handler.php convention.
                $txjson = [
                    'TransactionType' => 'NFTokenMint',
                    'URI' => $uri_hex,
                    'Flags' => $flags,
                    'NFTokenTaxon' => $taxon,
                    'SourceTag' => 2606240013,
                    'Memos' => [[ 'Memo' => [
                        'MemoType' => bin2hex('IMCollectibles'),
                        'MemoData' => bin2hex('NFT Mint by IMCollectibles.io')
                    ]]]
                ];
                
                if ($transfer_fee > 0) {
                    $txjson['TransferFee'] = $transfer_fee;
                }
                
                $payload = [
                    'txjson' => $txjson,
                    'custom_meta' => [
                        'instruction' => 'Sign to mint your NFT on the XRP Ledger',
                        'blob' => [
                            'type' => 'nft_mint',
                            'uri' => $uri,
                            'taxon' => $taxon
                        ]
                    ],
                    'options' => [
                        'expire' => 300,
                        'submit' => true
                    ]
                ];
                
                $ch = curl_init('https://xumm.app/api/v1/platform/payload');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($payload),
                    CURLOPT_HTTPHEADER => [
                        'X-API-Key: ' . $api_key,
                        'X-API-Secret: ' . $api_secret,
                        'Content-Type: application/json'
                    ],
                    CURLOPT_TIMEOUT => 30
                ]);
                
                $response = curl_exec($ch);
                $curl_err = curl_error($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($curl_err) {
                    file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "NFT Mint curl error: $curl_err\n", FILE_APPEND);
                    json_error('Failed to create mint request: ' . $curl_err, 500);
                }
                
                $result = json_decode($response, true);
                file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "NFT Mint XUMM response (HTTP $http_code)\n", FILE_APPEND);
                
                if ($http_code === 200 && isset($result['uuid'])) {
                    global $wpdb;
                    $status_table = $wpdb->prefix . 'xumm_status';
                    
                    $wpdb->insert($status_table, [
                        'uuid'      => $result['uuid'],
                        'account'   => '',
                        'signed'    => 0,
                        'tx_hash'   => null,
                        'nft_id'    => null,
                        'timestamp' => time(),
                        'claimed'   => 0,
                        'type'      => 'nft_mint',
                        'redirect'  => '',
                        'expires'   => date('Y-m-d H:i:s', time() + 300)
                    ], ['%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s']);
                    
                    echo json_encode([
                        'success'   => true,
                        'uuid'      => $result['uuid'],
                        'qr_png'    => $result['refs']['qr_png'] ?? '',
                        'deeplink'  => $result['next']['always'] ?? ''
                    ]);
                    file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "NFT Mint payload created: {$result['uuid']}\n", FILE_APPEND);
                } else {
                    $error_msg = $result['error']['message'] ?? $result['error'] ?? 'Unknown XUMM error';
                    file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "NFT Mint XUMM error: $error_msg\n", FILE_APPEND);
                    json_error('XUMM error: ' . $error_msg, 500);
                }
                exit;
            }

            // =================================================================
            // CASE 'poll' - Polls XUMM for transaction status
            // Used by mint.js pollXummResult() function
            // Added: v40 - January 2026
            // =================================================================
            case 'poll': {
                $uuid = sanitize_text_field($_POST['uuid'] ?? '');
                
                if (empty($uuid)) {
                    json_error('UUID required', 400);
                }
                
                file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Poll request for UUID: $uuid\n", FILE_APPEND);
                
                global $wpdb;
                $status_table = $wpdb->prefix . 'xumm_status';
                $cached = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $status_table WHERE uuid = %s",
                    $uuid
                ), ARRAY_A);
                
                if ($cached && $cached['signed'] && !empty($cached['tx_hash'])) {
                    file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Poll: Found signed in DB for $uuid\n", FILE_APPEND);
                    echo json_encode([
                        'success'  => true,
                        'signed'   => true,
                        'tx_hash'  => $cached['tx_hash'],
                        'account'  => $cached['account'] ?? '',
                        'nft_id'   => $cached['nft_id'] ?? '',
                        'expired'  => false,
                        'rejected' => false
                    ]);
                    exit;
                }
                
                $ch = curl_init("https://xumm.app/api/v1/platform/payload/$uuid");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        'X-API-Key: ' . $api_key,
                        'X-API-Secret: ' . $api_secret
                    ],
                    CURLOPT_TIMEOUT => 10
                ]);
                
                $response = curl_exec($ch);
                $curl_err = curl_error($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($curl_err || $http_code !== 200) {
                    file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Poll XUMM API error: $curl_err (HTTP $http_code)\n", FILE_APPEND);
                    echo json_encode(['success' => false, 'signed' => false, 'error' => 'Failed to fetch status']);
                    exit;
                }
                
                $payload = json_decode($response, true);
                
                if (!isset($payload['meta'])) {
                    echo json_encode(['success' => false, 'signed' => false, 'error' => 'Invalid payload response']);
                    exit;
                }
                
                $meta = $payload['meta'];
                $signed = !empty($meta['signed']);
                $expired = !empty($meta['expired']);
                $rejected = !empty($meta['cancelled']);
                
                $result = [
                    'success'  => true,
                    'signed'   => $signed,
                    'expired'  => $expired,
                    'rejected' => $rejected
                ];
                
                if ($signed && isset($payload['response'])) {
                    $tx_hash = $payload['response']['txid'] ?? '';
                    $account = $payload['response']['account'] ?? '';
                    
                    $result['tx_hash'] = $tx_hash;
                    $result['account'] = $account;
                    
                    $nft_id = '';
                    if (!empty($tx_hash) && isset($payload['response']['dispatched_result']['meta']['AffectedNodes'])) {
                        foreach ($payload['response']['dispatched_result']['meta']['AffectedNodes'] as $node) {
                            if (isset($node['CreatedNode']['LedgerEntryType']) && 
                                $node['CreatedNode']['LedgerEntryType'] === 'NFTokenPage') {
                                $tokens = $node['CreatedNode']['NewFields']['NFTokens'] ?? [];
                                if (!empty($tokens)) {
                                    $nft_id = end($tokens)['NFToken']['NFTokenID'] ?? '';
                                }
                            }
                            if (isset($node['ModifiedNode']['LedgerEntryType']) && 
                                $node['ModifiedNode']['LedgerEntryType'] === 'NFTokenPage') {
                                $final_tokens = $node['ModifiedNode']['FinalFields']['NFTokens'] ?? [];
                                $prev_tokens = $node['ModifiedNode']['PreviousFields']['NFTokens'] ?? [];
                                if (count($final_tokens) > count($prev_tokens) && !empty($final_tokens)) {
                                    $nft_id = end($final_tokens)['NFToken']['NFTokenID'] ?? '';
                                }
                            }
                        }
                    }
                    
                    $result['nft_id'] = $nft_id;
                    
                    if (!empty($tx_hash)) {
                        $wpdb->update($status_table, [
                            'signed'    => 1,
                            'tx_hash'   => $tx_hash,
                            'nft_id'    => $nft_id ?: null,
                            'account'   => $account,
                            'timestamp' => time()
                        ], ['uuid' => $uuid], ['%d', '%s', '%s', '%s', '%d'], ['%s']);
                        
                        file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Poll: Updated DB - tx_hash=$tx_hash, nft_id=$nft_id\n", FILE_APPEND);
                    }
                }
                
                echo json_encode($result);
                exit;
            }

            default:
                json_error('Unknown action', 400);
        }

    } else {
        // --------- XUMM WEBHOOK (no action) ----------
        file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Webhook received (general)\n", FILE_APPEND);

        if (!is_array($data) || !isset($data['payloadResponse']['payload_uuidv4'])) {
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Invalid webhook payload: " . json_encode($data) . "\n", FILE_APPEND);
            json_error('Invalid webhook payload', 400);
        }

        // Early route: swap_intake forward to group-offer-handler.php
        // Early route: swap_intake forward to group-offer-handler.php
$early_type = imu_resolve_payload_type($data);


        if ($early_type === 'swap_intake') {
            try {
                $fan_in_url   = get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/group-offer-handler.php';
                $proxy_secret = getenv('IMU_PROXY_SECRET') ?: '';

                // Use constant if defined, else fall back to env VPS_TOKEN
                $vps_bearer   = (defined('IMU_VPS_TOKEN') && IMU_VPS_TOKEN) ? IMU_VPS_TOKEN : (getenv('VPS_TOKEN') ?: '');

                $payload      = $data;
                $payload['action'] = 'webhook';

                // Always send proxy secret. ALSO include Authorization if available.
                $headers = [
                    'Content-Type' => 'application/json',
                    'X_IMU_PROXY'  => $proxy_secret
                ];
                if (!empty($vps_bearer)) {
                    $headers['Authorization'] = 'Bearer ' . $vps_bearer;
                }

                $fan = wp_remote_post($fan_in_url, [
                    'headers' => $headers,
                    'body'    => json_encode($payload),
                    'timeout' => 20,
                ]);

                $code = is_wp_error($fan) ? 0 : (int) wp_remote_retrieve_response_code($fan);
                $txt  = is_wp_error($fan) ? $fan->get_error_message() : wp_remote_retrieve_body($fan);

                file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "swap_intake fan-in => $fan_in_url :: code=$code :: body=$txt\n", FILE_APPEND);

                $forward_body = json_decode($txt, true);
                if ($code !== 200 || !isset($forward_body['success']) || !$forward_body['success']) {
                    json_error('Forwarded handler failed', 500);
                }

                echo json_encode(['success' => true, 'status' => 'forwarded']);
                exit;
            } catch (Throwable $e) {
                file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "swap_intake fan-in error: " . $e->getMessage() . "\n", FILE_APPEND);
                json_error('webhook_forward_failed', 500);
            }
        }

        $uuid = sanitize_text_field($data['payloadResponse']['payload_uuidv4']);
        if (!isset($data['payloadResponse']['signed']) || !$data['payloadResponse']['signed']) {
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Webhook received but not signed yet for UUID: $uuid\n", FILE_APPEND);
            echo json_encode(['status' => 'received']);
            exit;
        }

        // Fetch payload details from Xumm API with retries
        $retries = 3;
        $retry_delay = 2;
        $payload_data = null;
        $status = 0;
        while ($retries > 0) {
            $ch = curl_init("https://xumm.app/api/v1/platform/payload/$uuid");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['X-API-Key: ' . $api_key, 'X-API-Secret: ' . $api_secret],
                CURLOPT_TIMEOUT        => 10
            ]);
            $payload_response = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $payload_data = json_decode($payload_response, true);
            curl_close($ch);

            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Webhook API check UUID=$uuid :: status=$status :: resp=$payload_response\n", FILE_APPEND);

            if ($status === 200 && isset($payload_data['response']['account']) && ($payload_data['meta']['signed'] ?? false)) {
                break;
            }
            $retries--;
            if ($retries > 0) { sleep($retry_delay); }
        }
        if ($retries === 0) json_error('Failed to fetch payload data after retries', 500);

        $account = $payload_data['response']['account'] ?? null;
        $tx_hash = $payload_data['response']['txid'] ?? null;
        $type = imu_resolve_payload_type($payload_data);



        if (!$account) json_error('No account in payload response', 400);

        global $wpdb;

        // --- Handle different webhook types ---

        // Basic Sign-In
        if ($type === 'signin') {
            $table_name = $wpdb->prefix . 'xumm_status';
            $status_data = [
                'uuid'      => $uuid,
                'account'   => $account,
                'signed'    => 1,
                'timestamp' => time(),
                'claimed'   => 0,
                'type'      => 'signin',
                'redirect'  => 'https://imcollectibles.io/?t=' . time() . '&xrpl_account=' . urlencode($account)
            ];
            $wpdb->insert($table_name, $status_data, ['%s','%s','%d','%d','%d','%s','%s']);

            echo json_encode([
                'status'   => 'success',
                'uuid'     => $uuid,
                'account'  => $account,
                'signed'   => true,
                'type'     => $type,
                'tx_hash'  => $tx_hash,
                'redirect' => $status_data['redirect']
            ]);
            exit;
        }

        // NFT offer accept -> payouts
        // M7a: MINT-PAYMENT / FEE payloads (routed here because mint-on-demand-handler.php now sends the
        // type inside custom_meta.blob). Pre-M7a these resolved to 'tip' and landed in the default branch
        // below as tips with no tx_hash. Persist ONLY on dispatched_result === tesSUCCESS so that a row
        // with a tx_hash MEANS success; failures get no row and surface through the poll fallback as before.
        // The XUMM re-verify above is what makes this unauthenticated POST trustworthy -- it is kept.
        // This branch never touches the purchase tables (the handler remains their sole writer).
        if (in_array($type, ['nft_purchase', 'listing_fee', 'vault_fee', 'nft_claim'], true)) {   // M7a-3: claims (NFTokenAcceptOffer) persist on tesSUCCESS too
            $table_name = $wpdb->prefix . 'xumm_status';
            $mp_dr = (string) ($payload_data['response']['dispatched_result'] ?? '');
            if (!empty($tx_hash) && $mp_dr === 'tesSUCCESS') {
                $mp_row = ['account' => $account, 'signed' => 1, 'tx_hash' => $tx_hash, 'timestamp' => time(), 'claimed' => 0, 'type' => $type, 'redirect' => ''];
                $mp_exists = $wpdb->get_var($wpdb->prepare("SELECT uuid FROM $table_name WHERE uuid = %s", $uuid));   // uuid is the PRIMARY KEY
                if ($mp_exists) {
                    $wpdb->update($table_name, $mp_row, ['uuid' => $uuid], ['%s','%d','%s','%d','%d','%s','%s'], ['%s']);
                } else {
                    $wpdb->insert($table_name, ['uuid' => $uuid] + $mp_row, ['%s','%s','%d','%s','%d','%d','%s','%s']);
                }
                file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "M7a mint payment persisted uuid=$uuid type=$type account=$account tx=$tx_hash (" . ($mp_exists ? 'updated' : 'inserted') . ")\n", FILE_APPEND);
            } else {
                file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "M7a mint payment uuid=$uuid type=$type signed but dispatched_result='$mp_dr' -- NOT persisted (poll fallback surfaces it)\n", FILE_APPEND);
            }
            echo json_encode(['status' => 'success', 'uuid' => $uuid, 'type' => $type, 'persisted' => (!empty($tx_hash) && $mp_dr === 'tesSUCCESS')]);
            exit;
        }
        if ($type === 'nft_offer_accept') {
            $offer_id   = sanitize_text_field($data['custom_meta']['offer_id'] ?? '');
            $table_name = $wpdb->prefix . 'xumm_nft_bundle_offers';
            $offer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE offer_id = %s", $offer_id));
            if (!$offer) json_error('Offer not found', 400);

            $amount   = $offer->net_amount + $offer->platform_fee + $offer->royalty_amount;
            $currency = $offer->fee_currency;
            $issuer   = $currency === 'XFT' ? 'rGpnoqYLzWytxwQhhz715nRbqyCHM7zhxt' : null;

            if (!verify_escrow_funds($escrow_account, $amount, $currency, $issuer)) {
                json_error('Insufficient funds in escrow wallet', 400);
            }

            $transaction_fee = '12000';
            $payouts = [];
            if ($offer->platform_fee > 0) {
                $payouts[] = [
                    'TransactionType' => 'Payment',
                    'Account'         => $escrow_account,
                    'Destination'     => $fee_wallet,
                    'Amount'          => $currency === 'XFT'
                        ? ['value' => (string)$offer->platform_fee, 'currency' => 'XFT', 'issuer' => 'rGpnoqYLzWytxwQhhz715nRbqyCHM7zhxt']
                        : (string)($offer->platform_fee * 1000000),
                    'Fee'            => $transaction_fee,
                    'SourceTag'      => 2606240013
                ];
            }
            if ($offer->royalty_amount > 0) {
                $payouts[] = [
                    'TransactionType' => 'Payment',
                    'Account'         => $escrow_account,
                    'Destination'     => $offer->royalty_wallet,
                    'Amount'          => $currency === 'XFT'
                        ? ['value' => (string)$offer->royalty_amount, 'currency' => 'XFT', 'issuer' => 'rGpnoqYLzWytxwQhhz715nRbqyCHM7zhxt']
                        : (string)($offer->royalty_amount * 1000000),
                    'Fee'            => $transaction_fee,
                    'SourceTag'      => 2606240013
                ];
            }
            $payouts[] = [
                'TransactionType' => 'Payment',
                'Account'         => $escrow_account,
                'Destination'     => $offer->target_account,
                'Amount'          => $currency === 'XFT'
                    ? ['value' => (string)$offer->net_amount, 'currency' => 'XFT', 'issuer' => 'rGpnoqYLzWytxwQhhz715nRbqyCHM7zhxt']
                    : (string)($offer->net_amount * 1000000),
                'Fee'            => $transaction_fee,
                'SourceTag'      => 2606240013
            ];

            $payout_hashes = [];
            foreach ($payouts as $index => $txjson) {
                $payload = create_xumm_payload_with_webhook($txjson, [
                    'offer_id' => $offer_id,
                    'type'     => 'payout_' . ($index === 0 ? 'platform_fee' : ($index === 1 ? 'royalty' : 'seller')),
                    'nft_name' => $data['custom_meta']['nft_name'] ?? ''
                ]);
                if (isset($payload['error']) || !isset($payload['uuid'])) json_error('Failed to create payout payload', 500);
                $payout_hashes[] = ['uuid' => $payload['uuid'], 'type' => 'payout_' . ($index === 0 ? 'platform_fee' : ($index === 1 ? 'royalty' : 'seller'))];
            }

            $updated = $wpdb->update($table_name, [
                'status'        => 'accepted',
                'tx_hash'       => $tx_hash,
                'payout_hashes' => json_encode($payout_hashes)
            ], ['offer_id' => $offer_id, 'uuid' => $uuid], ['%s','%s','%s'], ['%s','%s']);

            if ($updated !== false && function_exists('broadcast_websocket')) {
                broadcast_websocket([
                    'type'          => 'offer_updated',
                    'offer_id'      => $offer_id,
                    'status'        => 'accepted',
                    'account'       => $account,
                    'payout_hashes' => $payout_hashes
                ]);
            }

            echo json_encode([
                'status'        => 'success',
                'uuid'          => $uuid,
                'account'       => $account,
                'type'          => $type,
                'tx_hash'       => $tx_hash,
                'payout_hashes' => $payout_hashes
            ]);
            exit;
        }

        // NFT offer (create/cancel/reject) bookkeeping
        if (in_array($type, ['nft_offer', 'nft_offer_cancel', 'nft_offer_reject'], true)) {
            $offer_id   = sanitize_text_field($data['custom_meta']['offer_id'] ?? '');
            $table_name = $wpdb->prefix . 'xumm_nft_bundle_offers';

            $status_map = [
                'nft_offer'         => 'pending',
                'nft_offer_cancel'  => 'canceled',
                'nft_offer_reject'  => 'rejected'
            ];
            $new_status = $status_map[$type] ?? 'pending';

            $updated = $wpdb->update($table_name, [
                'status'  => $new_status,
                'tx_hash' => $tx_hash
            ], ['offer_id' => $offer_id, 'uuid' => $uuid], ['%s','%s'], ['%s','%s']);

            if ($updated !== false && function_exists('broadcast_websocket')) {
                broadcast_websocket([
                    'type'     => 'offer_updated',
                    'offer_id' => $offer_id,
                    'status'   => $new_status,
                    'account'  => $account
                ]);
            }

            echo json_encode([
                'status'   => 'success',
                'uuid'     => $uuid,
                'account'  => $account,
                'type'     => $type,
                'tx_hash'  => $tx_hash
            ]);
            exit;
        }

        // Game reward webhook (mark completed)
        if ($type === 'game_reward') {
            $table_name = $wpdb->prefix . 'game_xft_claims';
            $updated = $wpdb->update($table_name, [
                'status'  => 'completed',
                'tx_hash' => $tx_hash
            ], ['uuid' => $uuid], ['%s','%s'], ['%s']);

            echo json_encode([
                'status'  => 'success',
                'uuid'    => $uuid,
                'account' => $account,
                'type'    => $type,
                'tx_hash' => $tx_hash
            ]);
            exit;
        }

        // NFT Burn handling
// NFT Burn handling
if ($type === 'nft_burn') {
    // 1) custom_meta.blob.nft_id, or 2) request_json.NFTokenID
    $nft_id_meta = strtoupper(trim(sanitize_text_field($payload_data['custom_meta']['blob']['nft_id'] ?? '')));
    $txjson      = imu_get_payload_txjson($payload_data);
    $nft_id_tx   = strtoupper(trim(sanitize_text_field($txjson['NFTokenID'] ?? '')));
    $nft_id      = preg_match('/^[0-9A-F]{64}$/', $nft_id_meta) ? $nft_id_meta : $nft_id_tx;

    if (!preg_match('/^[0-9A-F]{64}$/', $nft_id)) {
        json_error('Invalid NFT ID in webhook', 400);
    }

    $transfers_table = $wpdb->prefix . 'nft_transfers';

    // --- Retry longer & accept multiple proofs of burn ---
    $maxTries = 30; // ~60s to allow for ledger validation delays
    $burn_confirmed = false;
    $last_err = null;

    while ($maxTries-- > 0) {
        $tx_details = imu_xrpl_rpc('tx', ['transaction' => $tx_hash, 'binary' => false]);

        if (!empty($tx_details['error'])) {
            $last_err = $tx_details['error'];
        } else {
            $result = $tx_details['result'] ?? [];
            $validated = $result['validated'] ?? false;
            $meta   = $result['meta'] ?? null;
            $tes    = $meta['TransactionResult'] ?? null;

            // Only proceed if validated ledger and tesSUCCESS
            if ($validated && $tes === 'tesSUCCESS') {
                // Proof #1: DeletedNode for NFTokenObject
                foreach ($meta['AffectedNodes'] ?? [] as $node) {
                    if (isset($node['DeletedNode']) && $node['DeletedNode']['LedgerEntryType'] === 'NFTokenObject' && strtoupper($node['DeletedNode']['FinalFields']['NFTokenID'] ?? '') === $nft_id) {
                        $burn_confirmed = true;
                        break;
                    }
                    // Proof #2: ModifiedNode NFTokenPage with NFT removed (check if the NFT ID is in previous but not final)
                    if (isset($node['ModifiedNode']) && $node['ModifiedNode']['LedgerEntryType'] === 'NFTokenPage') {
                        $previous_nfts = $node['ModifiedNode']['PreviousFields']['NFTokens'] ?? [];
                        $final_nfts = $node['ModifiedNode']['FinalFields']['NFTokens'] ?? [];
                        $prev_ids = array_map(function($n) { return strtoupper($n['NFToken']['NFTokenID'] ?? ''); }, $previous_nfts);
                        $final_ids = array_map(function($n) { return strtoupper($n['NFToken']['NFTokenID'] ?? ''); }, $final_nfts);
                        if (in_array($nft_id, $prev_ids) && !in_array($nft_id, $final_ids)) {
                            $burn_confirmed = true;
                            break;
                        }
                    }
                }

                // Proof #3: Final confirmation - NFT no longer in owner's account_nfts
                if (!$burn_confirmed) {
                    $an_params = ['account' => $account, 'ledger_index' => 'validated', 'limit' => 1000];
                    $marker = null;
                    $nft_found = false;
                    do {
                        if ($marker) $an_params['marker'] = $marker;
                        $an_details = imu_xrpl_rpc('account_nfts', $an_params);
                        if (!empty($an_details['error'])) {
                            $last_err = $an_details['error'];
                            break;
                        }
                        $an_result = $an_details['result'] ?? [];
                        foreach ($an_result['account_nfts'] ?? [] as $owned_nft) {
                            if (strtoupper($owned_nft['NFTokenID'] ?? '') === $nft_id) {
                                $nft_found = true;
                                break 2;
                            }
                        }
                        $marker = $an_result['marker'] ?? null;
                    } while ($marker);
                    $burn_confirmed = !$nft_found;
                }
            } else {
                $last_err = !$validated ? 'not_validated' : $tes;
            }

            if ($burn_confirmed) break;
        }
        if ($maxTries > 0) { sleep(2); }
    }

    if (!$burn_confirmed) {
        file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "No burn confirmation after retries for NFT $nft_id, tx $tx_hash (last err: " . json_encode($last_err) . ")\n", FILE_APPEND);
        json_error('Burn not confirmed in transaction meta', 400);
    }

    // Idempotent update to confirmed
$updated = $wpdb->update($transfers_table, [
    'status' => 'confirmed',
    'tx_hash' => $tx_hash,
    'updated_at' => current_time('mysql', 1)
], ['payload_uuid' => $uuid, 'nft_token_id' => $nft_id], ['%s', '%s', '%s'], ['%s', '%s']);
    if ($updated === false) {
        file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Failed to confirm burn in DB for NFT $nft_id\n", FILE_APPEND);
        json_error('Failed to update burn status', 500);
    }
    file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Burn confirmed for NFT $nft_id, tx $tx_hash\n", FILE_APPEND);
    echo json_encode(['status' => 'confirmed']);
    exit;
}



        // Default: tip or trustline
        $table_name = $wpdb->prefix . 'xumm_status';
        $redirect   = ($type === 'tip')
            ? 'https://imcollectibles.io/live/?event_id=' . urlencode($data['custom_meta']['event_id'] ?? '') . '&t=' . time()
            : 'https://imcollectibles.io/?t=' . time() . '&xrpl_account=' . urlencode($account);

        $status_data = [
            'uuid'      => $uuid,
            'account'   => $account,
            'signed'    => 1,
            'timestamp' => time(),
            'claimed'   => 0,
            'type'      => $type,
            'redirect'  => $redirect
        ];

        if ($type === 'tip') {
            $status_data['tip_event_id']   = $data['custom_meta']['event_id']      ?? null;
            $status_data['tip_account_id'] = $data['custom_meta']['tip_account_id']?? null;
            $status_data['tip_amount']     = is_array($payload_data['payload']['txjson']['Amount'])
                ? $payload_data['payload']['txjson']['Amount']['value']
                : ($payload_data['payload']['txjson']['Amount'] / 1000000);
            $status_data['tip_currency']   = is_array($payload_data['payload']['txjson']['Amount'])
                ? $payload_data['payload']['txjson']['Amount']['currency']
                : 'XRP';
        }

        $wpdb->insert($table_name, $status_data, [
            '%s','%s','%d','%d','%d','%s','%s','%d','%d','%s','%s'
        ]);

        echo json_encode([
            'status'  => 'success',
            'uuid'    => $uuid,
            'account' => $account,
            'signed'  => true,
            'type'    => $type,
            'tx_hash' => $tx_hash
        ]);
        exit;
    }

}

// -------------------- GET ACTIONS --------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    $nonce = $_GET['_wpnonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        json_error('Invalid nonce', 400);
    }
    
    // Reset invalid cooldowns
$cooldown_table = $wpdb->prefix . 'game_cooldowns';
$wpdb->query($wpdb->prepare(
    "UPDATE $cooldown_table SET cooldowns_data = %s WHERE JSON_EXTRACT(cooldowns_data, '$.XFT') > %d OR JSON_EXTRACT(cooldowns_data, '$.XMEME') > %d",
    json_encode(['XFT' => 0, 'XMEME' => 0]), time() + 86400, time() + 86400
));
file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Reset invalid cooldowns in $cooldown_table\n", FILE_APPEND);
    
    switch ($_GET['action']) {
        
        case 'fetch-pending': {
            $xrpl_account = sanitize_text_field($_GET['account'] ?? '');
            if (empty($xrpl_account) || !preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $xrpl_account)) json_error('Invalid account', 400);
            global $wpdb;
            $table_name = $wpdb->prefix . 'game_xft_claims';
            // Cleanup expired
            $cleanup = $wpdb->query($wpdb->prepare("UPDATE $table_name SET status = 'expired' WHERE created_at < NOW() - INTERVAL 7 DAY AND status = %s", 'pending'));
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Expired cleanup on fetch (updated to expired): $cleanup rows\n", FILE_APPEND);

            $rewards = $wpdb->get_results($wpdb->prepare(
                "SELECT amount, sources, UNIX_TIMESTAMP(created_at)*1000 AS timestamp, session_id, currency FROM $table_name WHERE xrpl_account = %s AND status = %s",
                $xrpl_account, 'pending'
            ), ARRAY_A);
            foreach ($rewards as &$reward) {
                $reward['sources']   = json_decode($reward['sources'], true) ?? [];
                $reward['timestamp'] = (int)$reward['timestamp'];
                $reward['currency'] = $reward['currency'] ?? 'XFT';
            }
            echo json_encode(['success' => true, 'rewards' => $rewards]);
            exit;
        }

        case 'fetch-claimed': {
            $xrpl_account = sanitize_text_field($_GET['account'] ?? '');
            if (empty($xrpl_account) || !preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $xrpl_account)) json_error('Invalid account', 400);
            global $wpdb;
            $table_name = $wpdb->prefix . 'game_xft_claims';
            $claimed = $wpdb->get_results($wpdb->prepare(
                "SELECT amount, sources, UNIX_TIMESTAMP(created_at)*1000 AS timestamp, status, tx_hash, session_id, currency FROM $table_name WHERE xrpl_account = %s AND status != %s",
                $xrpl_account, 'pending'
            ), ARRAY_A);
            foreach ($claimed as &$c) {
                $c['sources']   = json_decode($c['sources'], true) ?? [];
                $c['timestamp'] = (int)$c['timestamp'];
                $c['currency'] = $c['currency'] ?? 'XFT';
            }
            echo json_encode(['success' => true, 'claimed' => $claimed]);
            exit;
        }
        
        case 'fetch-cooldowns': {
    $xrpl_account = sanitize_text_field($_GET['account'] ?? '');
    if (empty($xrpl_account)) json_error('Invalid account', 400);
    global $wpdb;
    $table_name = $wpdb->prefix . 'game_cooldowns';
    $row = $wpdb->get_row($wpdb->prepare("SELECT cooldowns_data FROM $table_name WHERE xrpl_account = %s", $xrpl_account), ARRAY_A);
    $cooldowns = $row ? json_decode($row['cooldowns_data'], true) : ['XFT' => 0, 'XMEME' => 0];
    echo json_encode(['success' => true, 'cooldowns' => $cooldowns]);
    exit;
}
        
        case 'check_nft_owner': {
    $nft_id = strtoupper(trim(sanitize_text_field($_GET['nft_id'] ?? '')));
    $expected_owner = trim(sanitize_text_field($_GET['expected_owner'] ?? ''));
    $nonce = sanitize_text_field($_GET['_wpnonce'] ?? '');

    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Invalid nonce for check_nft_owner: $nonce\n", FILE_APPEND);
        json_error('Invalid nonce', 400);
    }
    if (!preg_match('/^[0-9A-F]{64}$/', $nft_id)) json_error('Invalid nft_id', 400);
    if ($expected_owner === '') json_error('Missing expected_owner', 400);

    $r = imu_xrpl_rpc('nft_info', ['nft_id' => $nft_id, 'ledger_index' => 'validated']);
    if (!empty($r['error']) || ($r['result']['status'] ?? '') !== 'success') {
        file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "RPC failed for nft_info $nft_id: " . json_encode($r) . "\n", FILE_APPEND);
        json_error('rpc_failed', 502);
    }

    $owner =
        $r['result']['owner'] ??
        ($r['result']['nft_info']['Owner'] ?? null) ??
        ($r['result']['nft']['owner'] ?? null);

    if (!$owner) {
        file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Owner not found for NFT $nft_id\n", FILE_APPEND);
        json_error('owner_not_found', 404);
    }

    echo json_encode([
        'success'    => true,
        'nft_id'     => $nft_id,
        'owner'      => $owner,
        'expected'   => $expected_owner,
        'is_intaken' => hash_equals($owner, $expected_owner),
    ]);
    exit;
}

        default:
            json_error('Unknown action', 400);
    }
}

// -------------------- check_uuid (polling from client) --------------------
if (isset($_GET['check_uuid'])) {
    ob_start();
    $uuid = sanitize_text_field($_GET['check_uuid']);
    file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Check UUID request for: $uuid\n", FILE_APPEND);

    $api_key    = defined('XUMM_API_KEY') ? XUMM_API_KEY : '';
    $api_secret = defined('XUMM_API_SECRET') ? XUMM_API_SECRET : '';
    if (empty($api_key) || empty($api_secret)) {
        echo json_encode(['signed' => false, 'error' => 'Missing API credentials']);
        ob_end_flush();
        exit;
    }

    // Check DB for signin payloads first
    global $wpdb;
    $table_name = $wpdb->prefix . 'xumm_status';
    $status_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE uuid = %s", $uuid), ARRAY_A);

    // Fix 2 (Aug 2026): RECENCY GATE. signed=1 rows carry a SIGNING-time timestamp
    // (webhook + signin-complete both stamp time() at sign-verify), so 120s here
    // cannot break slow logins - the clock starts at the sign, and pollers consume
    // within seconds. Old signed uuids were PERMANENT login credentials before this.
    // A stale row falls through to the API branch below, which is gated on XUMM's
    // own immutable resolved_at - so this pair closes the replay in both lanes.
    if ($status_row && $status_row['type'] === 'signin' && $status_row['signed'] && $status_row['account']
        && (int)$status_row['timestamp'] > (time() - 120)) {
        $account = $status_row['account'];
        $cookie_options = [
            'expires'  => time() + (86400 * 30),
            'path'     => '/',
            'domain'   => 'imcollectibles.io',
            'secure'   => true,
            'httponly' => false,
            'samesite' => 'Lax'
        ];
        // Fix D (Aug 2026): write xrpl_account on the CANONICAL '.imcollectibles.io'
        // scope -- the same scope xrpl_wallet_type below and every other writer already
        // uses. This line previously wrote the HOST-ONLY 'imcollectibles.io' scope, which
        // created a SECOND cookie of the same name alongside the dotted one written by the
        // Joey and ?xrpl_login= paths. With a duplicate present PHP keeps the LAST value it
        // parses while every JS reader here takes the FIRST -- so a page's PHP and its
        // JavaScript could operate on two different wallets at the same time.
        //
        // The stale host-only copy is expired in the SAME response, so browsers that already
        // hold one are cleaned up on their next login. Without that line the fix would only
        // work for browsers with no history.
        setcookie('xrpl_account', '', array_merge($cookie_options, ['expires' => time() - 3600]));
        setcookie('xrpl_account', $account, array_merge($cookie_options, ['domain' => '.imcollectibles.io']));
        // v684: mark the wallet type (handlers read xrpl_wallet_type as authoritative since v683).
        // Written on the CANONICAL '.imcollectibles.io' scope. (Fix D, Aug 2026: the xrpl_account
        // line above now uses that same scope, closing the v281 duplicate-cookie hazard.)
        setcookie('xrpl_wallet_type', 'xaman', array_merge($cookie_options, ['domain' => '.imcollectibles.io']));
        // Session-auth 2a-b (Step 2, Aug 2026): this is the DESKTOP Xaman completion
        // path (the login tab polls ?check_uuid=). The row is signed+verified, so the
        // account is PROVEN -- mint the httponly session token here. This is what gives
        // desktop Xaman users a token (previously only the phone's signin-complete
        // minted one). The poll fetch is credentials:include, so Set-Cookie lands in
        // the browser. Xaman only; guarded + kill-switched.
        // Session-auth B (Aug 2026): mint ONLY on the FIRST successful poll. The login
        // Session-fix B (Aug 2026). The gate is NO LONGER `!claimed`.
        //
        // WHY IT CHANGED: xaman-signin-complete.php (the PHONE) sets claimed=1 the moment
        // it completes. By the time the DESKTOP tab polls, claimed is already 1, so the
        // desktop's mint was skipped -- permanently. The desktop ended up holding a soft
        // cookie and NO session token, which after the 2b flip renders a logged-out header
        // while /login/ (reading the soft cookie) bounces it home: an unrecoverable loop.
        //
        // claimed itself is UNTOUCHED -- still written below, and it is load-bearing for
        // xaman-signin-complete's `claimed = 0 OR claimed IS NULL` recent-signin fallback.
        //
        // THE NEW GATE is "does THIS browser lack a token", plus a short transient lock.
        // The lock is why !claimed existed in the first place: a sign-in fires several
        // polls within milliseconds and none of them carries the just-set httponly cookie
        // yet, so the cookie test ALONE cannot collapse that burst (this is exactly what
        // produced repeated duplicate rows per login). Keyed on uuid|account and short-lived, the lock lets
        // precisely one poll of the burst mint; every later poll finds the cookie and
        // skips. Net effect: one row per browser per login, and the desktop finally gets
        // its token even though the phone already marked the payload claimed.
        $imc_cookie_name = defined('IMC_SESSION_COOKIE') ? IMC_SESSION_COOKIE : 'imc_session';
        $imc_mint_lock   = 'imc_mint_' . md5($uuid . '|' . $account);
        if (empty($_COOKIE[$imc_cookie_name])
            && !get_transient($imc_mint_lock)
            && function_exists('imc_session_mint')) {
            set_transient($imc_mint_lock, 1, 30);
            imc_session_mint($account, 'xaman');
        }
        $wpdb->update($table_name, ['claimed' => 1], ['uuid' => $uuid], ['%d'], ['%s']);
        echo json_encode([
            'signed'   => true,
            'account'  => $account,
            'type'     => 'signin',
            'redirect' => 'https://imcollectibles.io/'
        ]);
        ob_end_flush();
        exit;
    }

    // REMOVED: Cookie fallback - was causing account switch failures!
    // When user signs in with new wallet, we must NOT use old cookie.
    // Let the poll continue to check Xumm API for actual signin status.

    // Fallback to Xumm API (with limited retries)
    $retries = 3;
    $payload_data = null;
    $type = null;
    while ($retries > 0) {
        $ch = curl_init("https://xumm.app/api/v1/platform/payload/$uuid");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['X-API-Key: ' . $api_key, 'X-API-Secret: ' . $api_secret],
            CURLOPT_TIMEOUT        => 5
        ]);
        $response = curl_exec($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $payload_data = json_decode($response, true);
        curl_close($ch);

        if ($status !== 200 || !isset($payload_data['meta'])) {
            $retries--;
            if ($retries === 0) {
                echo json_encode(['signed' => false, 'error' => 'Failed to fetch payload status']);
                ob_end_flush();
                exit;
            }
            sleep(1);
            continue;
        }

        $type = imu_resolve_payload_type($payload_data);

        if (in_array($type, ['signin','tip','trustline','nft_offer','nft_offer_cancel','nft_offer_reject','nft_burn'], true)) break;


        $retries--;
        sleep(1);
    }

    if ($retries === 0) {
        echo json_encode(['signed' => false, 'error' => 'Invalid payload type']);
        ob_end_flush();
        exit;
    }

    $signed  = $payload_data['meta']['signed']   ?? false;
    $account = $payload_data['response']['account'] ?? null;
    $tx_hash = $payload_data['response']['txid'] ?? null;

    // Fix 2 (Aug 2026): RECENCY GATE for the API lane. The XUMM API reports old
    // payloads signed:true forever, and this branch used to re-set cookies, mint a
    // session AND refresh the DB row on every replay. Gate on XUMM's own immutable
    // response.resolved_at (the sign moment). Belt: if resolved_at is ever absent,
    // fall back to payload.created_at with a 300s window - safe because our payloads
    // are created with expire=180, so any legit sign happens within that bound,
    // while a replayed old uuid is ancient on BOTH clocks and is rejected either way.
    $imc_resolved_at = strtotime($payload_data['response']['resolved_at'] ?? '') ?: 0;
    $imc_created_at  = strtotime($payload_data['payload']['created_at'] ?? '') ?: 0;
    $imc_sign_fresh  = ($imc_resolved_at > (time() - 120))
        || ($imc_resolved_at === 0 && $imc_created_at > (time() - 300));
    if ($type === 'signin' && $signed && $account && $imc_sign_fresh) {
        $cookie_options = [
            'expires'  => time() + (86400 * 30),
            'path'     => '/',
            'domain'   => 'imcollectibles.io',
            'secure'   => true,
            'httponly' => false,
            'samesite' => 'Lax'
        ];
        // Fix D (Aug 2026): write xrpl_account on the CANONICAL '.imcollectibles.io'
        // scope -- the same scope xrpl_wallet_type below and every other writer already
        // uses. This line previously wrote the HOST-ONLY 'imcollectibles.io' scope, which
        // created a SECOND cookie of the same name alongside the dotted one written by the
        // Joey and ?xrpl_login= paths. With a duplicate present PHP keeps the LAST value it
        // parses while every JS reader here takes the FIRST -- so a page's PHP and its
        // JavaScript could operate on two different wallets at the same time.
        //
        // The stale host-only copy is expired in the SAME response, so browsers that already
        // hold one are cleaned up on their next login. Without that line the fix would only
        // work for browsers with no history.
        setcookie('xrpl_account', '', array_merge($cookie_options, ['expires' => time() - 3600]));
        setcookie('xrpl_account', $account, array_merge($cookie_options, ['domain' => '.imcollectibles.io']));
        // v684: see the sibling signin site above -- canonical scope, single cookie.
        setcookie('xrpl_wallet_type', 'xaman', array_merge($cookie_options, ['domain' => '.imcollectibles.io']));
        // Session-fix B (Aug 2026): THIS SITE NEVER MINTED. It is the API-fallback branch
        // (no DB row yet, payload fetched straight from XUMM), and it inserts the row with
        // claimed = 1 below -- which then permanently blocked the sibling DB-row branch
        // from ever minting for this browser. The payload is verified signed by the XUMM
        // API here, so the account has exactly the same standing as the sibling site and
        // is safe to mint from. Same cookie + transient-lock guard as the sibling.
        $imc_cookie_name = defined('IMC_SESSION_COOKIE') ? IMC_SESSION_COOKIE : 'imc_session';
        $imc_mint_lock   = 'imc_mint_' . md5($uuid . '|' . $account);
        if (empty($_COOKIE[$imc_cookie_name])
            && !get_transient($imc_mint_lock)
            && function_exists('imc_session_mint')) {
            set_transient($imc_mint_lock, 1, 30);
            imc_session_mint($account, 'xaman');
        }
        $wpdb->insert($table_name, [
            'uuid'      => $uuid,
            'account'   => $account,
            'signed'    => 1,
            'timestamp' => time(),
            'claimed'   => 1,
            'type'      => 'signin',
            'redirect'  => 'https://imcollectibles.io/'
        ], ['%s','%s','%d','%d','%d','%s','%s']);
        echo json_encode([
            'signed'   => true,
            'account'  => $account,
            'type'     => 'signin',
            'redirect' => 'https://imcollectibles.io/'
        ]);
        ob_end_flush();
        exit;
    }

    if (in_array($type, ['nft_offer','nft_offer_cancel','nft_offer_reject'], true)) {
        $table_name_offers = $wpdb->prefix . 'xumm_nft_bundle_offers';
        $offer = $wpdb->get_row($wpdb->prepare("SELECT offer_id, status FROM $table_name_offers WHERE uuid = %s", $uuid), ARRAY_A);
        if ($signed && $account && $offer) {
            $map = [
                'nft_offer'         => 'pending',
                'nft_offer_cancel'  => 'canceled',
                'nft_offer_reject'  => 'rejected'
            ];
            $new_status = $map[$type] ?? 'pending';
            $wpdb->update($table_name_offers, [
                'status'  => $new_status,
                'tx_hash' => $tx_hash
            ], ['offer_id' => $offer['offer_id'], 'uuid' => $uuid], ['%s','%s'], ['%s','%s']);
            echo json_encode([
                'signed'   => true,
                'account'  => $account,
                'type'     => $type,
                'tx_hash'  => $tx_hash,
                'offer_id' => $offer['offer_id']
            ]);
        } else {
            echo json_encode(['signed' => false, 'status' => 'pending']);
        }
        ob_end_flush();
        exit;
    }

    if (in_array($type, ['tip','trustline'], true)) {
        echo json_encode([
            'signed'  => (bool)$signed,
            'account' => $account,
            'type'    => $type,
            'tx_hash' => $tx_hash
        ]);
        ob_end_flush();
        exit;
    }
    
    if ($type === 'nft_burn') {
    $txjson = imu_get_payload_txjson($payload_data);
    echo json_encode([
        'signed'  => (bool)$signed,
        'account' => $account,
        'type'    => 'nft_burn',
        'tx_hash' => $tx_hash,
        'nft_id'  => $payload_data['custom_meta']['blob']['nft_id'] ?? ($txjson['NFTokenID'] ?? null),
    ]);
    ob_end_flush();
    exit;
}


    echo json_encode(['signed' => false]);
    ob_end_flush();
    exit;
}

// -------------------- NFT FETCH (by account) --------------------
if (isset($_GET['account']) && !isset($_GET['issuer']) && !isset($_GET['ids']) && !isset($_GET['refresh_combined'])) {
    $account     = sanitize_text_field($_GET['account']);
    $force_check = isset($_GET['force_check']) && $_GET['force_check'] === 'true';
    $counts_only = isset($_GET['counts_only']) && $_GET['counts_only'] === 'true';
    $rpc_url     = imu_xrpl_rpc_url();
    $nfts = [];
    $guardians = 0;
    $frequencies = 0;
    $ledger = 0;
    $lasvegas = 0;
    $firepit = 0;
    $marker = null;
    $attempts = 0;
    $max_attempts = 1000;
    $max_retries  = 3;

    if ($force_check) {
        $transient_key = 'xaman_nft_' . md5($account);
        delete_transient($transient_key);
        file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "Force cleared transient for $account\n", FILE_APPEND);
    }

    // -------------------------------------------------------------------
    // A1/A2/A3 (Sep 2026) - the page walk must not stop silently.
    //
    // A rate limit or upstream error is a SUCCESSFUL http transaction with a
    // non-200 code, so curl_exec returns a body and the old retry loop - which
    // only retried when curl_exec returned false - never fired for the exact
    // failure that happens in practice. The walk then hit `break` and the
    // partial counts were reported as 'success'. Rare at baseline, materially
    // higher during upstream incidents, and reproducible under load.
    //
    // Now: retry the SAME page on a non-200/malformed reply, with backoff,
    // and fall back to a second endpoint before giving up - the pattern
    // the distributor service has always used. If a page still cannot
    // be read, the walk is flagged INCOMPLETE and says so (A4).
    // -------------------------------------------------------------------
    $endpoints = array_values(array_unique([$rpc_url, 'https://xrplcluster.com']));
    $walk_complete = true;
    $walk_error    = '';

    do {
        $params = ['account' => $account, 'ledger_index' => 'validated', 'limit' => 100];
        if ($marker) $params['marker'] = $marker;
        $request = json_encode(['method' => 'account_nfts', 'params' => [$params]]);

        $data   = null;
        $status = 0;
        $error  = '';

        foreach ($endpoints as $ep_index => $endpoint) {
            for ($retry = 0; $retry <= $max_retries; $retry++) {
                $ch = curl_init($endpoint);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $request,
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                    CURLOPT_TIMEOUT        => 10,
                    CURLOPT_FOLLOWLOCATION => true
                ]);
                $response = curl_exec($ch);
                $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($response === false) { $error = curl_error($ch); }
                curl_close($ch);

                if ($response !== false && $status === 200) {
                    $decoded = json_decode($response, true);
                    if (isset($decoded['result']['account_nfts'])) {
                        $data = $decoded;
                        break 2;              // page read cleanly
                    }
                    $error = 'malformed response';
                } elseif ($response !== false) {
                    // Rate limit / upstream error. THIS is the case the old
                    // loop could not see. Back off before trying again.
                    $error = "HTTP $status";
                }

                if ($retry < $max_retries) { sleep(1 * ($retry + 1)); }
            }
            if (isset($endpoints[$ep_index + 1])) {
                file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "account_nfts page failed on $endpoint ($error) for $account - trying fallback\n", FILE_APPEND);
            }
        }

        if ($data === null) {
            // Every endpoint and retry exhausted for THIS page. Stop the walk
            // and mark it incomplete - never present a partial as whole.
            $walk_complete = false;
            $walk_error    = $error !== '' ? $error : 'page fetch failed';
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "account_nfts walk INCOMPLETE for $account after page " . ($attempts + 1) . ": $walk_error\n", FILE_APPEND);
            break;
        }

        foreach ($data['result']['account_nfts'] as $nft) {
    $issuer = $nft['Issuer'] ?? 'unknown';
    $taxon  = isset($nft['NFTokenTaxon']) ? (int)$nft['NFTokenTaxon'] : -1;
    // Count for discounts
    if ($issuer === 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR' && $taxon === 0) $guardians++;
    elseif ($issuer === 'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga' && $taxon === 717825) $frequencies++;
    elseif ($issuer === 'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt' && $taxon === 1056369418) $ledger++;
    elseif ($issuer === 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR' && $taxon === 777) $lasvegas++;
    elseif ($issuer === 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR' && $taxon === 666) $firepit++;
    
    // Include full NFT data only when not counts_only (saves memory for fountain page)
    if (!$counts_only) {
        $nfts[] = $nft;
    }
}
        $marker = $data['result']['marker'] ?? null;
        $attempts++;

        if ($attempts >= $max_attempts) break;
        usleep($counts_only ? 200000 : 500000); // 0.2s for counts_only, 0.5s for full fetch
    } while ($marker);

    $total = $counts_only ? ($guardians + $frequencies + $ledger + $lasvegas + $firepit) : count($nfts);
    // NOTE: Batch metadata pre-caching removed — it was a self-referencing loopback HTTP call
    // (home_url() → imcollectibles.xyz → itself) which deadlocks on the web host and blocked
    // the entire NFT list response. Metadata is fetched on-demand by the frontend ?ids= handler.
    // A4: the response SHAPE is unchanged - same keys, same types - so every
    // existing consumer behaves exactly as before. Only `status` flips and
    // `complete` is added, for callers that care whether the walk finished.
    echo json_encode([
    'result' => [
        'account_nfts'        => $nfts,
        'guardians'           => $guardians,
        'frequencies'         => $frequencies,
        'ledger'              => $ledger,
        'lasvegas'            => $lasvegas,
        'firepit'             => $firepit,  // Ensure this uses the correct variable
        'status'              => $walk_complete ? 'success' : 'error',
        'complete'            => $walk_complete,
        'error'               => $walk_complete ? '' : $walk_error,
        'total'               => $total
    ]
]);
    exit;
}

// -------------------- Fetch NFTs by Issuer & Taxon --------------------
if (isset($_GET['issuer']) && isset($_GET['taxon'])) {
    $issuer = sanitize_text_field($_GET['issuer']);
    $taxon  = (int)$_GET['taxon'];
    $limit  = isset($_GET['limit'])  ? max(1, (int)$_GET['limit'])  : 100;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

    if (empty($bithomp_api_key)) {
        echo json_encode(['result' => ['nfts' => [], 'status' => 'error', 'error' => 'Bithomp API key missing']]);
        exit;
    }

    $url = "https://bithomp.com/api/v2/nfts?issuer=" . urlencode($issuer) . "&taxon=$taxon&limit=$limit&offset=$offset&assets=true&metadata=true&uri=true";
    $response = wp_remote_get($url, [
        'headers' => ['x-bithomp-token' => $bithomp_api_key],
        'timeout' => 15
    ]);
    if (is_wp_error($response)) {
        echo json_encode(['result' => ['nfts' => [], 'status' => 'error', 'error' => $response->get_error_message()]]);
        exit;
    }
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (!isset($body['nfts']) || !is_array($body['nfts'])) {
        echo json_encode(['result' => ['nfts' => [], 'status' => 'error', 'error' => 'No NFTs found']]);
        exit;
    }

    $nfts = array_map(function($nft) {
        return [
            'NFTokenID'   => $nft['nftokenID'] ?? '',
            'Issuer'      => $nft['issuer'] ?? '',
            'NFTokenTaxon'=> $nft['taxon'] ?? 0,
            'owner'       => $nft['owner'] ?? '',
            'metadata'    => $nft['metadata'] ?? [],
            'assets'      => $nft['assets'] ?? [],
        ];
    }, $body['nfts']);

    echo json_encode([
        'result' => [
            'nfts'   => $nfts,
            'total'  => $body['total'] ?? count($nfts),
            'status' => 'success'
        ]
    ]);
    exit;
}

// -------------------- Batch NFT Metadata by IDs --------------------
// Modified: Uses local API first, falls back to Bithomp
if (isset($_GET['ids'])) {
    ob_start();
    // FIX: Normalize all NFT IDs to uppercase
    $nft_ids = array_filter(array_map(function($id) {
        return strtoupper(trim($id));
    }, explode(',', sanitize_text_field($_GET['ids']))));
    
    if (count($nft_ids) > 20) {
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Maximum 20 NFTs per request']);
        exit;
    }
    
    $nfts = [];
    $missing_ids = [];
    
    // Collection fallback images
    $collection_fallbacks = [
        'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR' => 'https://imcollectibles.io/wp-content/uploads/2025/12/GOTF.png',
        'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga' => 'https://imcollectibles.io/wp-content/uploads/2025/12/POTF.png',
        'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt' => 'https://imcollectibles.io/wp-content/uploads/2025/12/POTL.png',
        'rHsrif6nHTkmyh38W7JmYjairPWhq5P3AH' => 'https://imcollectibles.io/wp-content/uploads/2025/03/IMU-Special.png'
    ];
    
    // === TRY LOCAL API FIRST ===
    $local_results = fetch_from_local_metadata_api($nft_ids);
    
    foreach ($nft_ids as $nft_id) {
        if (isset($local_results[$nft_id])) {
            $local = $local_results[$nft_id];
            $issuer = $local['issuer'] ?? 'unknown';
            $image = $local['metadata']['image'] ?? null;
            
            // Apply collection fallback if no image
            if (!$image || strpos($image, 'placehold') !== false) {
                $image = $collection_fallbacks[$issuer] ?? '/wp-content/uploads/fallback-nft.svg';
            }
            
            // For POTL/POTF: use edition number from name (e.g. "Protectors of the Ledger #1221")
            // to construct direct VPS image URL. Falls back to collection image if name has no edition.
            if ($issuer === 'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt' || $issuer === 'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga') {
                $nft_name = $local['metadata']['name'] ?? '';
                if (preg_match('/#(\d+)/', $nft_name, $m)) {
                    $edition = (int)$m[1];
                    $folder = ($issuer === 'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt') ? 'ledger' : 'frequencies';
                    $image = "https://images.imcollectibles.io/{$folder}/protector-{$edition}.png";
                }
            }
            $nfts[] = [
                'nftokenID' => $nft_id,
                'metadata'  => [
                    'name'        => $local['metadata']['name'] ?? 'Unnamed NFT',
                    'image'       => $image,
                    'description' => $local['metadata']['description'] ?? '',
                    'attributes'  => $local['metadata']['attributes'] ?? []
                ],
                'issuer' => $issuer
            ];
        } else {
            $missing_ids[] = $nft_id;
        }
    }
    
    // === FALLBACK TO BITHOMP FOR MISSING ===
    if (!empty($missing_ids) && !empty($bithomp_api_key)) {
        $url = "https://bithomp.com/api/v2/nfts?ids=" . implode(',', array_map('urlencode', $missing_ids)) . "&uri=true&metadata=true&assets=true";
        $response = wp_remote_get($url, [
            'headers' => ['x-bithomp-token' => $bithomp_api_key],
            'timeout' => 15
        ]);
        
        if (!is_wp_error($response)) {
            $response_code = wp_remote_retrieve_response_code($response);

            // Handle Bithomp rate-limit — honour Retry-After header, then retry once
            if ($response_code === 429) {
                $wait = (int)(wp_remote_retrieve_header($response, 'retry-after') ?: 3);
                $wait = min($wait, 10); // Cap at 10s — don't block the web host worker indefinitely
                error_log("Bithomp 429 rate limit hit on ?ids= — sleeping {$wait}s");
                sleep($wait);
                $response      = wp_remote_get($url, ['headers' => ['x-bithomp-token' => $bithomp_api_key], 'timeout' => 15]);
                $response_code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if ($response_code === 200 && isset($body['nfts']) && is_array($body['nfts'])) {
                foreach ($body['nfts'] as $nft) {
                    $nft_id = $nft['nftokenID'];
                    $issuer = $nft['issuer'] ?? 'unknown';
                    $image  = $nft['assets']['image'] ?? ($nft['metadata']['image'] ?? ($nft['uri'] ?? null));
                    
                    // Collection fallback images (using correct URLs from page-my-nfts.php)
                    $fallbacks = [
                        'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR' => 'https://imcollectibles.io/wp-content/uploads/2025/12/GOTF.png',
                        'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga' => 'https://imcollectibles.io/wp-content/uploads/2025/12/POTF.png',
                        'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt' => 'https://imcollectibles.io/wp-content/uploads/2025/12/POTL.png',
                        'rHsrif6nHTkmyh38W7JmYjairPWhq5P3AH' => 'https://imcollectibles.io/wp-content/uploads/2025/03/IMU-Special.png'
                    ];
                    
                    // Convert IPFS first
                    if ($image && strpos((string)$image, 'ipfs://') === 0) {
                        $cid = substr($image, 7);
                        $image = "https://<your-pinata-gateway>/ipfs/{$cid}";
                    }
                    
                    // Use fallback if no image or placeholder
                    if (!$image || strpos($image, 'placehold') !== false) {
                        $image = $fallbacks[$issuer] ?? '/wp-content/uploads/fallback-nft.svg';
                    }
                    
                    // For POTL/POTF: use edition number from Bithomp name to construct VPS image URL
                    if ($issuer === 'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt' || $issuer === 'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga') {
                        $nft_name = $nft['metadata']['name'] ?? '';
                        if (preg_match('/#(\d+)/', $nft_name, $m)) {
                            $edition = (int)$m[1];
                            $folder = ($issuer === 'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt') ? 'ledger' : 'frequencies';
                            $image = "https://images.imcollectibles.io/{$folder}/protector-{$edition}.png";
                        }
                    }
                    $nfts[] = [
                        'nftokenID' => $nft_id,
                        'metadata'  => [
                            'name'        => $nft['metadata']['name'] ?? 'Unnamed NFT',
                            'image'       => $image,
                            'description' => $nft['metadata']['description'] ?? '',
                            'attributes'  => $nft['metadata']['attributes'] ?? []
                        ],
                        'assets' => $nft['assets'] ?? [],
                        'issuer' => $issuer
                    ];
                }
            }
        }
    }
    
    ob_clean();
    echo json_encode(['success' => true, 'nfts' => $nfts]);
    exit;
}

// -------------------- Royalty + Metadata Fetch for Offer Breakdown --------------------
if (isset($_GET['action']) && $_GET['action'] === 'get_nft_name' && isset($_GET['nft_id'])) {
    // FIX: Normalize NFT ID to uppercase
    $nft_id = strtoupper(trim(sanitize_text_field($_GET['nft_id'] ?? '')));
    $nonce  = sanitize_text_field($_GET['nonce'] ?? '');
    if (!preg_match('/^[0-9A-F]{64}$/', $nft_id)) {
        echo json_encode(['success' => false, 'error' => 'Invalid NFT ID format']);
        exit;
    }
    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        echo json_encode(['success' => false, 'error' => 'Invalid nonce']);
        exit;
    }
    $metadata = get_nft_metadata($nft_id);
    if (!$metadata) {
        echo json_encode(['success' => false, 'error' => 'NFT metadata not found']);
        exit;
    }
    echo json_encode([
        'success'         => true,
        'nft_name'        => $metadata['nft_name'],
        'image'           => $metadata['image'],
        'royalty_percent' => $metadata['royalty_percent'],
        'royalty_wallet'  => $metadata['royalty_wallet'],
        'issuer'          => $metadata['issuer'],
        'nftokenID'       => $metadata['nftokenID']
    ]);
    exit;
}

// -------------------- Refresh Combined (invalidate + refetch one NFT) --------------------
if (isset($_GET['refresh_combined'])) {
    // FIX: Normalize NFT ID to uppercase
    $nft_id = strtoupper(trim(sanitize_text_field($_GET['refresh_combined'] ?? '')));
    if (!preg_match('/^[0-9A-F]{64}$/', $nft_id)) json_error('Invalid NFT ID');
    if (empty($bithomp_api_key)) json_error('Bithomp API key missing');

    $transient_key = 'bithomp_nft_' . md5($nft_id);
    delete_transient($transient_key);

    $url = "https://bithomp.com/api/v2/nft/$nft_id?uri=true&metadata=true&assets=true";
    $response = wp_remote_get($url, [
        'headers' => ['x-bithomp-token' => $bithomp_api_key],
        'timeout' => 15
    ]);
    if (is_wp_error($response)) json_error('Failed to refresh NFT data: ' . $response->get_error_message());
    $response_code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if ($response_code !== 200 || isset($body['error'])) {
        $error = isset($body['error']) ? $body['error'] : 'Invalid response from Bithomp API';
        json_error('Bithomp API error: ' . $error);
    }
    if (!isset($body['nft'])) json_error('No NFT data available');

    $nft    = $body['nft'];
    $image  = $nft['assets']['image'] ?? ($nft['metadata']['image'] ?? ($nft['uri'] ?? null));
    $issuer = $nft['issuer'] ?? 'unknown';
    $fallbacks = [
        'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR' => 'https://imcollectibles.io/wp-content/uploads/guardians-fallback.jpg',
        'rf1MGf4U8CZzb2NDGa5qXPFm4eM39zKq9U' => 'https://imcollectibles.io/wp-content/uploads/protectors-fallback.jpg',
        'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga' => 'https://imcollectibles.io/wp-content/uploads/protectors-fallback.jpg',
        'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt' => 'https://imcollectibles.io/wp-content/uploads/protectors-fallback.jpg'
    ];
    if (!$image || strpos($image, 'cdn.bithomp.com') === false) {
        if (array_key_exists($issuer, $fallbacks)) {
            $image = $fallbacks[$issuer];
        } elseif (strpos((string)$image, 'ipfs://') === 0) {
            $cid = substr($image, 7);
            $gateways = [
                "https://<your-pinata-gateway>/ipfs/{$cid}",
                "https://ipfs.io/ipfs/{$cid}",
                "https://nftstorage.link/ipfs/{$cid}"
            ];
            $resolved_image = null;
            foreach ($gateways as $gateway) {
                $check = wp_remote_get($gateway, [
                    'timeout' => 4,
                    'headers' => ['Accept' => 'image/*'],
                    'sslverify' => false
                ]);
                if (!is_wp_error($check)) {
                    $status = wp_remote_retrieve_response_code($check);
                    $content_type = wp_remote_retrieve_header($check, 'content-type');
                    if ($status === 200 && strpos($content_type, 'image/') === 0) {
                        $resolved_image = $gateway; break;
                    }
                }
            }
            $image = $resolved_image ?? '/wp-content/uploads/fallback-nft.svg';
        } else {
            $image = '/wp-content/uploads/fallback-nft.svg';
        }
    }

    $result = [
        'nftokenID' => $nft['nftokenID'],
        'metadata'  => [
            'name'        => $nft['metadata']['name'] ?? 'Unnamed NFT',
            'image'       => $image,
            'description' => $nft['metadata']['description'] ?? 'No description available',
            'attributes'  => $nft['metadata']['attributes'] ?? []
        ],
        'assets' => $nft['assets'] ?? [],
        'issuer' => $issuer
    ];
    set_transient($transient_key, $result, 3600);
    echo json_encode(['success' => true, 'nft' => $result]);
    exit;
}

json_error('Invalid request', 404);
?>