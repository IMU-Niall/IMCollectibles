<?php
/**
 * IMC Trustline Manager
 * ============================================================================
 * Location: /wp-content/themes/astra/xrpl-nft-marketplace/backend/imc-trustline-manager.php
 *
 * Shared utility that ensures the platform fee wallet has active trustlines
 * for any XRPL token accepted as payment. Called during:
 *   1. Listing creation — when artist selects accepted currencies
 *   2. Secondary offer creation — when buyer pays in a non-XRP token
 *
 * Calls the configured remote signing service to check/create trustlines.
 * Caches results in WordPress transients to avoid hitting XRPL every time.
 *
 * wp-config.php constants required:
 *   IMC_FEE_SIGNER_URL     = <configured in wp-config>
 *   IMC_FEE_SIGNER_API_KEY = <shared key matching VPS .env IMC_FEE_SIGNER_API_KEY>
 *   IMC_FEE_WALLET         = riMCgymFVzdqQoTR82m5oUJE697bDHrJm
 * ============================================================================
 */

if (!defined('ABSPATH')) {
    // Allow direct inclusion from standalone handlers that load wp-load.php
    // but guard against direct web access
    if (!defined('LH_LOADED') && !defined('MOD_LOADED') && !defined('OFFER_LOADED')) {
        exit('Direct access not allowed');
    }
}

/**
 * Ensure the fee wallet has a trustline for the given currency/issuer.
 *
 * Returns: ['success' => bool, 'already_existed' => bool, 'error' => string|null]
 *
 * Uses WordPress transients to cache known trustlines for 24 hours,
 * so repeated listing creations with the same currency don't hit XRPL.
 *
 * @param string $currency  Token currency code (e.g. 'XFT', 'RLUSD')
 * @param string $issuer    Token issuer XRPL address
 * @return array
 */
function ensure_fee_wallet_trustline($currency, $issuer) {
    // XRP never needs a trustline
    if (strtoupper($currency) === 'XRP') {
        return ['success' => true, 'already_existed' => true, 'error' => null];
    }

    $currency = strtoupper(trim($currency));
    $issuer   = trim($issuer);

    // Validate inputs
    if (empty($currency) || empty($issuer)) {
        return ['success' => false, 'already_existed' => false, 'error' => 'Currency and issuer required'];
    }

    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $issuer)) {
        return ['success' => false, 'already_existed' => false, 'error' => 'Invalid issuer address'];
    }

    // Check transient cache first (avoids RPC call)
    $cache_key = 'imc_trustline_' . md5($currency . '_' . $issuer);
    if (function_exists('get_transient')) {
        $cached = get_transient($cache_key);
        if ($cached === 'active') {
            trustline_log("Cache hit: $currency/$issuer — trustline confirmed");
            return ['success' => true, 'already_existed' => true, 'error' => null];
        }
    }

    // Get VPS signer config
    $signer_url = defined('IMC_FEE_SIGNER_URL') ? IMC_FEE_SIGNER_URL : '';
    $signer_key = defined('IMC_FEE_SIGNER_API_KEY') ? IMC_FEE_SIGNER_API_KEY : '';

    if (empty($signer_url) || empty($signer_key)) {
        trustline_log("WARNING: Fee signer not configured (IMC_FEE_SIGNER_URL / IMC_FEE_SIGNER_API_KEY)");
        return ['success' => false, 'already_existed' => false, 'error' => 'Fee trustline signer not configured'];
    }

    // Call VPS check_and_set (checks first, creates if missing)
    trustline_log("Calling VPS check_and_set for $currency / $issuer");

    $payload = json_encode([
        'action'   => 'check_and_set',
        'api_key'  => $signer_key,
        'currency' => $currency,
        'issuer'   => $issuer
    ]);

    $ch = curl_init($signer_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 3
    ]);

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        trustline_log("VPS call failed: $err");
        return ['success' => false, 'already_existed' => false, 'error' => "VPS connection error: $err"];
    }

    $data = json_decode($resp, true);

    if (!$data || !isset($data['success'])) {
        trustline_log("VPS returned invalid response: " . substr($resp, 0, 200));
        return ['success' => false, 'already_existed' => false, 'error' => 'Invalid VPS response'];
    }

    if (!$data['success']) {
        trustline_log("VPS error: " . ($data['error'] ?? 'unknown'));
        return ['success' => false, 'already_existed' => false, 'error' => $data['error'] ?? 'VPS error'];
    }

    $result_data = $data['data'] ?? [];
    $already_existed = !empty($result_data['already_exists']);
    $action_taken = $result_data['action'] ?? 'unknown';

    if ($action_taken === 'created') {
        trustline_log("Trustline CREATED for $currency / $issuer (tx: " . ($result_data['tx_hash'] ?? '-') . ")");
    } else {
        trustline_log("Trustline already active for $currency / $issuer");
    }

    // Cache the result for 24 hours
    if (function_exists('set_transient')) {
        set_transient($cache_key, 'active', DAY_IN_SECONDS);
    }

    return [
        'success'         => true,
        'already_existed' => $already_existed,
        'tx_hash'         => $result_data['tx_hash'] ?? null,
        'error'           => null
    ];
}

/**
 * Ensure trustlines for ALL currencies in an accepted_currencies array.
 * Called during listing creation when an artist selects multiple payment tokens.
 *
 * @param array $currencies  Array of currency entries [['currency' => 'XFT', 'issuer' => 'rGpn...'], ...]
 * @return array  ['success' => bool, 'results' => [...], 'errors' => [...]]
 */
function ensure_fee_wallet_trustlines_for_listing($currencies) {
    if (!is_array($currencies)) {
        return ['success' => true, 'results' => [], 'errors' => []];
    }

    $results = [];
    $errors  = [];

    foreach ($currencies as $curr) {
        $code   = strtoupper(trim($curr['currency'] ?? ''));
        $issuer = trim($curr['issuer'] ?? '');

        // Skip XRP (no trustline needed)
        if ($code === 'XRP' || empty($code)) continue;

        // Skip if no issuer (shouldn't happen for non-XRP but be safe)
        if (empty($issuer)) {
            trustline_log("Skipping $code — no issuer specified");
            continue;
        }

        $result = ensure_fee_wallet_trustline($code, $issuer);
        $results[] = array_merge($result, ['currency' => $code, 'issuer' => $issuer]);

        if (!$result['success']) {
            $errors[] = "$code: " . ($result['error'] ?? 'unknown error');
        }
    }

    return [
        'success' => empty($errors),
        'results' => $results,
        'errors'  => $errors
    ];
}

/**
 * Internal logging
 */
function trustline_log($msg) {
    static $log_dir = null;
    if ($log_dir === null) {
        $log_dir = defined('ABSPATH')
            ? (get_template_directory() . '/xrpl-nft-marketplace/backend/logs')
            : __DIR__ . '/logs';
        if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);
    }
    $log_file = $log_dir . '/trustline-manager.log';
    @file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}