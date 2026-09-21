<?php
/**
 * ============================================================================
 * Mint Webhook Receiver v1.0
 * Path: /wp-content/themes/astra/xrpl-nft-marketplace/backend/mint-webhook.php
 * ============================================================================
 * 
 * Receives mint completion notifications from the VPS mint system.
 * Records mints in wp_nft_mints table for task verification.
 * 
 * Called by the mint service when a mint is completed:
 *   POST /mint-webhook.php
 *   {
 *     "secret": "your_webhook_secret",
 *     "account": "rXXXX...",
 *     "collection": "POTF",
 *     "nft_token_id": "000800...",
 *     "tx_hash": "ABC123...",
 *     "nft_name": "Protector #1234",
 *     "rarity": "rare"
 *   }
 * 
 * ============================================================================
 */

require_once dirname(__DIR__, 5) . '/wp-load.php';

header('Content-Type: application/json');
$mw_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($mw_origin, ['https://mint.imcollectibles.xyz','https://mint.imcollectibles.io'])) {
    header('Access-Control-Allow-Origin: ' . $mw_origin);
} else {
    header('Access-Control-Allow-Origin: https://mint.imcollectibles.io');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============================================================================
// CONFIGURATION
// ============================================================================

// Webhook secret — define IMC_MINT_WEBHOOK_SECRET in wp-config.php
// v179: Moved from hardcoded value to wp-config.php constant
if (!defined('IMC_MINT_WEBHOOK_SECRET')) {
    // No fallback: an unset constant must fail closed, never authorise a caller.
    define('IMC_MINT_WEBHOOK_SECRET', '');
}
define('MINT_WEBHOOK_SECRET', IMC_MINT_WEBHOOK_SECRET);

// Valid collections that can trigger tasks
$PROTECTOR_COLLECTIONS = ['POTF', 'POTL', 'potf', 'potl'];
$GUARDIAN_COLLECTIONS = ['GOTF', 'gotf'];

// Logging
$log_dir = __DIR__ . '/logs';
$log_file = $log_dir . '/mint-webhook.log';
if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);

function webhook_log($msg) {
    global $log_file;
    @file_put_contents($log_file, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
}

// ============================================================================
// REQUEST HANDLING
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    webhook_log("Invalid method: " . $_SERVER['REQUEST_METHOD']);
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    webhook_log("Invalid JSON input");
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

// Verify secret
$secret = $input['secret'] ?? '';
// SEC: fail closed. An unset constant leaves MINT_WEBHOOK_SECRET empty; without
// this guard an empty submitted secret would compare equal and authorise the call.
if (MINT_WEBHOOK_SECRET === '' || $secret === '') {
    webhook_log('Webhook secret not configured or not supplied');
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid secret']);
    exit;
}
if (!hash_equals(MINT_WEBHOOK_SECRET, $secret)) {
    webhook_log("Invalid secret from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid secret']);
    exit;
}

// Extract data
$account = sanitize_text_field($input['account'] ?? '');
$collection = strtoupper(sanitize_text_field($input['collection'] ?? ''));
$nft_token_id = sanitize_text_field($input['nft_token_id'] ?? '');
$tx_hash = sanitize_text_field($input['tx_hash'] ?? '');
$nft_name = sanitize_text_field($input['nft_name'] ?? '');
$rarity = sanitize_text_field($input['rarity'] ?? '');

webhook_log("Received mint: account=$account, collection=$collection, nft=$nft_token_id");

// Validate required fields
if (!$account || !preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
    webhook_log("Invalid account: $account");
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid XRPL account']);
    exit;
}

if (!$collection) {
    webhook_log("Missing collection");
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Collection required']);
    exit;
}

if (!$nft_token_id || strlen($nft_token_id) !== 64) {
    webhook_log("Invalid NFT token ID: $nft_token_id (length=" . strlen($nft_token_id) . ")");
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid NFT token ID']);
    exit;
}

// ============================================================================
// DATABASE INSERT
// ============================================================================

global $wpdb;
$mint_table = $wpdb->prefix . 'nft_mints';

// Create table if it doesn't exist
$charset = $wpdb->get_charset_collate();
$wpdb->query("
    CREATE TABLE IF NOT EXISTS $mint_table (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        account VARCHAR(50) NOT NULL,
        collection VARCHAR(20) NOT NULL,
        nft_token_id VARCHAR(64) NOT NULL,
        tx_hash VARCHAR(64),
        nft_name VARCHAR(255),
        rarity VARCHAR(50),
        minted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_account (account),
        INDEX idx_collection (collection),
        INDEX idx_minted_at (minted_at),
        UNIQUE KEY unique_mint (account, nft_token_id)
    ) $charset
");

// Check for duplicate
$existing = $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM $mint_table WHERE account = %s AND nft_token_id = %s",
    $account, $nft_token_id
));

if ($existing) {
    webhook_log("Duplicate mint ignored: account=$account, nft=$nft_token_id");
    echo json_encode(['success' => true, 'message' => 'Already recorded', 'duplicate' => true]);
    exit;
}

// Insert mint record
$result = $wpdb->insert($mint_table, [
    'account' => $account,
    'collection' => $collection,
    'nft_token_id' => $nft_token_id,
    'tx_hash' => $tx_hash,
    'nft_name' => $nft_name,
    'rarity' => $rarity,
    'minted_at' => current_time('mysql'),
], ['%s', '%s', '%s', '%s', '%s', '%s', '%s']);

if ($result === false) {
    webhook_log("Database insert failed: " . $wpdb->last_error);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit;
}

$insert_id = $wpdb->insert_id;
webhook_log("Mint recorded: ID=$insert_id, account=$account, collection=$collection, nft=$nft_token_id");

// ============================================================================
// RESPONSE
// ============================================================================

echo json_encode([
    'success' => true,
    'message' => 'Mint recorded successfully',
    'mint_id' => $insert_id,
    'account' => $account,
    'collection' => $collection,
]);