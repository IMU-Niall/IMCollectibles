<?php
/**
 * ============================================================================
 * FILE: currency-handler.php
 * PATH: /wp-content/themes/astra/xrpl-nft-marketplace/backend/
 * VERSION: v71 — Multi-Currency Support
 * ============================================================================
 * 
 * PURPOSE:
 * Centralized currency management for the IMCollectibles marketplace.
 * Handles token validation, trustline checking, and currency configuration.
 * 
 * ENDPOINTS:
 * GET  ?action=get_known_tokens          - List well-known XRPL tokens
 * GET  ?action=check_trustline           - Check if wallet has trustline
 * GET  ?action=get_account_currencies    - Get all currencies a wallet holds
 * GET  ?action=validate_currency         - Validate a currency code/issuer pair
 * 
 * @version 1.0.0
 */

// WordPress bootstrap
require_once dirname(__DIR__, 5) . '/wp-load.php';
if (!defined('ABSPATH')) exit;

// Headers
header('Content-Type: application/json; charset=utf-8');
// CORS whitelist
$imc_allowed_origins = ['https://imcollectibles.xyz','https://www.imcollectibles.xyz','https://imcollectibles.io','https://www.imcollectibles.io','https://improtectors.com','https://www.improtectors.com'];
$imc_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . (in_array($imc_origin, $imc_allowed_origins) ? $imc_origin : $imc_allowed_origins[0]));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-cache, no-store, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ============================================================================
// CONFIGURATION — Well-Known XRPL Tokens
// ============================================================================

// Tokens pre-configured for the IMU ecosystem
define('IMC_KNOWN_TOKENS', [
    'XRP' => [
        'currency' => 'XRP',
        'name' => 'XRP',
        'icon' => '💧',
        'decimals' => 6,
        'is_native' => true
    ],
    'XFT' => [
        'currency' => 'XFT',
        'issuer' => 'rGpnoqYLzWytxwQhhz715nRbqyCHM7zhxt',
        'name' => 'XFT (Frequency Token)',
        'icon' => '🎵',
        'decimals' => 8,
        'description' => 'The official IMU ecosystem token'
    ],
    'SCHMECKLES' => [
        'currency' => 'SCHMECKLES',
        'issuer' => 'rPxw83ZP6thv7KmG5DpAW4cDW55DZRZ9wu',
        'name' => 'Schmeckles',
        'icon' => '🪙',
        'decimals' => 8,
        'description' => 'Community meme token'
    ],
    'XMEME' => [
        'currency' => 'XMEME',
        'issuer' => 'r4UPddYeGeZgDhSGPkooURsQtmGda4oYQW',
        'name' => 'XMEME',
        'icon' => '🐸',
        'decimals' => 8,
        'description' => 'Meme token on XRPL'
    ]
]);

// RPC endpoint
$RPC_URL = 'https://xrplcluster.com/';

// Logging
$log_file = __DIR__ . '/logs/currency.log';
if (!file_exists(dirname($log_file))) @mkdir(dirname($log_file), 0755, true);

function currency_log($msg) {
    global $log_file;
    @file_put_contents($log_file, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
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
 * Make XRPL RPC call
 */
function xrpl_rpc($method, $params = [], $timeout = 10) {
    global $RPC_URL;
    
    $response = wp_remote_post($RPC_URL, [
        'body' => json_encode(['method' => $method, 'params' => [$params]]),
        'headers' => ['Content-Type' => 'application/json'],
        'timeout' => $timeout
    ]);
    
    if (is_wp_error($response)) {
        return ['error' => $response->get_error_message()];
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    return $body['result'] ?? ['error' => 'Invalid response'];
}

/**
 * Validate XRPL account format
 */
function is_valid_xrpl_address($address) {
    return preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $address);
}

/**
 * Convert 3-char currency to hex if needed (for non-standard chars)
 */
function currency_to_hex($currency) {
    // Standard 3-char ASCII currencies don't need conversion
    if (strlen($currency) <= 3 && preg_match('/^[A-Za-z0-9]{1,3}$/', $currency)) {
        return strtoupper($currency);
    }
    
    // Already hex (40 chars)
    if (strlen($currency) === 40 && preg_match('/^[A-Fa-f0-9]{40}$/', $currency)) {
        return strtoupper($currency);
    }
    
    // Convert to hex-encoded currency (padded to 40 chars)
    $hex = bin2hex($currency);
    return strtoupper(str_pad($hex, 40, '0'));
}

/**
 * Convert hex currency code back to readable form
 */
function hex_to_currency($hex) {
    if (strlen($hex) <= 3) return $hex;
    if (strlen($hex) !== 40) return $hex;
    
    // Remove trailing zeros and convert
    $trimmed = rtrim($hex, '0');
    if (strlen($trimmed) % 2 !== 0) $trimmed .= '0';
    
    $ascii = @hex2bin($trimmed);
    if ($ascii && preg_match('/^[\x20-\x7E]+$/', $ascii)) {
        return $ascii;
    }
    
    return $hex; // Return original if not convertible
}

/**
 * Check if wallet has trustline for a token
 */
function check_trustline($account, $currency, $issuer) {
    if (!is_valid_xrpl_address($account)) {
        return ['has_trustline' => false, 'error' => 'Invalid account'];
    }
    
    if ($currency === 'XRP') {
        return ['has_trustline' => true, 'balance' => null]; // XRP doesn't need trustline
    }
    
    if (!is_valid_xrpl_address($issuer)) {
        return ['has_trustline' => false, 'error' => 'Invalid issuer'];
    }
    
    $result = xrpl_rpc('account_lines', [
        'account' => $account,
        'peer' => $issuer,
        'ledger_index' => 'validated'
    ]);
    
    if (isset($result['error'])) {
        return ['has_trustline' => false, 'error' => $result['error']];
    }
    
    $currency_upper = strtoupper($currency);
    $currency_hex = currency_to_hex($currency);
    
    foreach ($result['lines'] ?? [] as $line) {
        $line_currency = strtoupper($line['currency'] ?? '');
        if ($line_currency === $currency_upper || $line_currency === $currency_hex) {
            return [
                'has_trustline' => true,
                'balance' => floatval($line['balance'] ?? 0),
                'limit' => floatval($line['limit'] ?? 0)
            ];
        }
    }
    
    return ['has_trustline' => false];
}

/**
 * Get all currencies a wallet holds
 */
function get_account_currencies($account) {
    if (!is_valid_xrpl_address($account)) {
        return ['error' => 'Invalid account'];
    }
    
    $currencies = [];
    
    // Get XRP balance
    $account_info = xrpl_rpc('account_info', [
        'account' => $account,
        'ledger_index' => 'validated'
    ]);
    
    if (!isset($account_info['error'])) {
        $xrp_drops = intval($account_info['account_data']['Balance'] ?? 0);
        $currencies[] = [
            'currency' => 'XRP',
            'balance' => $xrp_drops / 1000000,
            'is_native' => true
        ];
    }
    
    // Get token trustlines
    $lines = xrpl_rpc('account_lines', [
        'account' => $account,
        'ledger_index' => 'validated'
    ]);
    
    if (!isset($lines['error'])) {
        foreach ($lines['lines'] ?? [] as $line) {
            $balance = floatval($line['balance'] ?? 0);
            if ($balance > 0) {
                $currency_code = $line['currency'];
                $display_name = strlen($currency_code) === 40 ? hex_to_currency($currency_code) : $currency_code;
                
                $currencies[] = [
                    'currency' => $currency_code,
                    'display_name' => $display_name,
                    'issuer' => $line['account'],
                    'balance' => $balance,
                    'limit' => floatval($line['limit'] ?? 0)
                ];
            }
        }
    }
    
    return $currencies;
}

// ============================================================================
// REQUEST HANDLING
// ============================================================================

$action = sanitize_text_field($_GET['action'] ?? $_POST['action'] ?? '');

// --- GET: List known tokens ---
if ($action === 'get_known_tokens') {
    $tokens = [];
    foreach (IMC_KNOWN_TOKENS as $code => $token) {
        $tokens[] = array_merge(['code' => $code], $token);
    }
    json_success($tokens);
}

// --- GET: Check trustline ---
if ($action === 'check_trustline') {
    $account = sanitize_text_field($_GET['account'] ?? '');
    $currency = strtoupper(sanitize_text_field($_GET['currency'] ?? ''));
    $issuer = sanitize_text_field($_GET['issuer'] ?? '');
    
    if (!$account || !$currency) {
        json_error('Missing account or currency');
    }
    
    // For known tokens, use stored issuer
    if (isset(IMC_KNOWN_TOKENS[$currency]) && !$issuer) {
        $issuer = IMC_KNOWN_TOKENS[$currency]['issuer'] ?? '';
    }
    
    $result = check_trustline($account, $currency, $issuer);
    json_success($result);
}

// --- GET: Get account currencies ---
if ($action === 'get_account_currencies') {
    $account = sanitize_text_field($_GET['account'] ?? '');
    
    if (!$account) {
        json_error('Missing account');
    }
    
    $currencies = get_account_currencies($account);
    
    if (isset($currencies['error'])) {
        json_error($currencies['error']);
    }
    
    // Enhance with known token info
    foreach ($currencies as &$curr) {
        $code = strtoupper($curr['currency'] ?? '');
        if (isset(IMC_KNOWN_TOKENS[$code])) {
            $curr['known_token'] = true;
            $curr['name'] = IMC_KNOWN_TOKENS[$code]['name'];
            $curr['icon'] = IMC_KNOWN_TOKENS[$code]['icon'];
        }
    }
    
    json_success($currencies);
}

// --- GET: Validate currency configuration ---
if ($action === 'validate_currency') {
    $currency = strtoupper(sanitize_text_field($_GET['currency'] ?? ''));
    $issuer = sanitize_text_field($_GET['issuer'] ?? '');
    
    if (!$currency) {
        json_error('Missing currency');
    }
    
    // XRP is always valid
    if ($currency === 'XRP') {
        json_success([
            'valid' => true,
            'currency' => 'XRP',
            'is_native' => true,
            'name' => 'XRP'
        ]);
    }
    
    // Check if known token
    if (isset(IMC_KNOWN_TOKENS[$currency])) {
        $token = IMC_KNOWN_TOKENS[$currency];
        json_success([
            'valid' => true,
            'currency' => $currency,
            'issuer' => $token['issuer'],
            'name' => $token['name'],
            'icon' => $token['icon'],
            'known_token' => true
        ]);
    }
    
    // Custom token - validate issuer
    if (!$issuer) {
        json_error('Issuer required for custom tokens');
    }
    
    if (!is_valid_xrpl_address($issuer)) {
        json_error('Invalid issuer address');
    }
    
    // Verify issuer account exists
    $account_info = xrpl_rpc('account_info', [
        'account' => $issuer,
        'ledger_index' => 'validated'
    ]);
    
    if (isset($account_info['error'])) {
        json_error('Issuer account not found on XRPL');
    }
    
    json_success([
        'valid' => true,
        'currency' => $currency,
        'issuer' => $issuer,
        'name' => $currency,
        'known_token' => false
    ]);
}

// Unknown action
json_error('Unknown action');
