<?php
/**
 * ============================================================================
 * FILE: imc-tc-handler.php
 * PATH: /wp-content/themes/astra/xrpl-nft-marketplace/backend/
 * ============================================================================
 * 
 * IMCollectibles Terms & Conditions Handler (v195)
 * 
 * Handles T&C acceptance check and recording for wallet-based users.
 * Called via AJAX from the header.php modal overlay.
 * 
 * Actions:
 *   - check_tc  (GET)  — Check if wallet has accepted current T&C version
 *   - accept_tc (POST) — Record T&C acceptance
 * 
 * DB Table: wp_imc_tc_acceptance
 * 
 * Version support: Bump IMC_TC_VERSION to re-prompt all users when T&C change.
 * ============================================================================
 */

require_once dirname(__DIR__, 5) . '/wp-load.php';
if (!defined('ABSPATH')) exit;

// Current T&C version — bump this to re-prompt all users
if (!defined('IMC_TC_VERSION')) {
    define('IMC_TC_VERSION', '1.2');
}

header('Content-Type: application/json');

// ── Ensure table exists ──────────────────────────────────────────────────
function imc_ensure_tc_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'imc_tc_acceptance';
    
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
        return $table;
    }
    
    $charset = $wpdb->get_charset_collate();
    $wpdb->query("
        CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            wallet_address VARCHAR(50) NOT NULL,
            tc_version VARCHAR(20) NOT NULL DEFAULT '1.0',
            accepted_at DATETIME NOT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent TEXT DEFAULT NULL,
            UNIQUE KEY wallet_version (wallet_address, tc_version),
            KEY idx_wallet (wallet_address),
            KEY idx_version (tc_version)
        ) $charset
    ");
    
    return $table;
}

// ── Check T&C acceptance ─────────────────────────────────────────────────
function imc_check_tc($wallet) {
    global $wpdb;
    $table = imc_ensure_tc_table();
    $version = IMC_TC_VERSION;
    
    // Transient cache to avoid DB hit on every page load
    $cache_key = 'imc_tc_' . md5($wallet . '_' . $version);
    $cached = get_transient($cache_key);
    if ($cached === 'accepted') {
        return true;
    }
    
    $accepted = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE wallet_address = %s AND tc_version = %s",
        $wallet, $version
    ));
    
    if ($accepted > 0) {
        set_transient($cache_key, 'accepted', 86400); // 24hr cache
        return true;
    }
    
    return false;
}

// ── Record T&C acceptance ────────────────────────────────────────────────
function imc_accept_tc($wallet) {
    global $wpdb;
    $table = imc_ensure_tc_table();
    $version = IMC_TC_VERSION;
    
    // Don't duplicate
    if (imc_check_tc($wallet)) {
        return ['success' => true, 'already_accepted' => true];
    }
    
    $result = $wpdb->insert($table, [
        'wallet_address' => $wallet,
        'tc_version'     => $version,
        'accepted_at'    => current_time('mysql'),
        'ip_address'     => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent'     => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
    ]);
    
    if ($result === false) {
        return ['success' => false, 'error' => 'Failed to record acceptance'];
    }
    
    // Set cache
    $cache_key = 'imc_tc_' . md5($wallet . '_' . $version);
    set_transient($cache_key, 'accepted', 86400);
    
    error_log("[IMC-TC] T&C v{$version} accepted by {$wallet}");
    
    return ['success' => true, 'version' => $version];
}

// ── Route actions ────────────────────────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$nonce  = $_GET['nonce']  ?? $_POST['nonce']  ?? '';

// Validate nonce
if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid nonce']);
    exit;
}

switch ($action) {
    case 'check_tc':
        $wallet = sanitize_text_field($_GET['wallet'] ?? '');
        if (!$wallet || !preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $wallet)) {
            echo json_encode(['success' => false, 'error' => 'Invalid wallet']);
            exit;
        }
        $accepted = imc_check_tc($wallet);
        echo json_encode(['success' => true, 'accepted' => $accepted, 'version' => IMC_TC_VERSION]);
        break;
        
    case 'accept_tc':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'POST required']);
            exit;
        }
        $wallet = sanitize_text_field($_POST['wallet'] ?? '');
        if (!$wallet || !preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $wallet)) {
            echo json_encode(['success' => false, 'error' => 'Invalid wallet']);
            exit;
        }
        $result = imc_accept_tc($wallet);
        echo json_encode($result);
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action. Use check_tc or accept_tc']);
        break;
}
exit;