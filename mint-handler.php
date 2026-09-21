<?php
/**
 * File: mint-handler.php (Production Ready)
 * Path: /wp-content/themes/astra/xrpl-nft-marketplace/backend/mint-handler.php
 * Purpose: Persist NFT minting intents / records (pre/post on-ledger), including optional dynamic-NFT config.
 * Notes:
 *  - Requires nonce: xrpl_marketplace_nonce
 *  - Aligned table name: {$wpdb->prefix}xumm_nft_mints
 *  - Safe defaults; auto-creates table if missing
 */

require_once dirname(__DIR__, 5) . '/wp-load.php';
if (!defined('ABSPATH')) exit;

header('Content-Type: application/json');

global $wpdb;
$table = $wpdb->prefix . 'xumm_nft_mints';

// Create table if missing (idempotent)
$wpdb->query("
CREATE TABLE IF NOT EXISTS $table (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  account VARCHAR(35) NOT NULL,
  mint_type VARCHAR(32) NOT NULL,
  collection_name VARCHAR(128) DEFAULT '',
  nft_id VARCHAR(64) DEFAULT NULL,              -- Optional: set after mint tx confirmed
  nft_name VARCHAR(200) NOT NULL,
  media_type VARCHAR(32) NOT NULL,
  ipfs_link TEXT NOT NULL,
  description TEXT,
  price DECIMAL(20,8) DEFAULT 0,
  royalty_percent DECIMAL(5,2) DEFAULT 0,
  status ENUM('pending','active','archived','failed') NOT NULL DEFAULT 'active',
  is_dynamic TINYINT(1) NOT NULL DEFAULT 0,
  dynamic_config TEXT DEFAULT NULL,             -- JSON blob: {trigger, threshold, new_uri}
  tx_hash VARCHAR(64) DEFAULT NULL,             -- Optional: on-ledger mint tx hash
  issuer VARCHAR(35) DEFAULT NULL,              -- Optional: set from mint result if available
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_account (account),
  KEY idx_status (status),
  KEY idx_nftid (nft_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Small helper
function mh_json_bad($msg, $code = 400) {
    wp_send_json_error(['error' => $msg], $code);
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'POST') {
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
            mh_json_bad('Invalid nonce', 403);
        }

        $account          = sanitize_text_field($_POST['account'] ?? '');
        $mint_type        = sanitize_text_field($_POST['mint_type'] ?? '');
        $collection_name  = sanitize_text_field($_POST['collection_name'] ?? '');
        $nft_name         = sanitize_text_field($_POST['nft_name'] ?? '');
        $media_type       = sanitize_text_field($_POST['media_type'] ?? '');
        $ipfs_link        = esc_url_raw($_POST['ipfs_link'] ?? '');
        $description      = sanitize_textarea_field($_POST['description'] ?? '');
        $price            = floatval($_POST['price'] ?? 0);
        $royalty_percent  = floatval($_POST['royalty_percent'] ?? 0);

        $is_dynamic       = isset($_POST['is_dynamic']) && ($_POST['is_dynamic'] === '1' || $_POST['is_dynamic'] === 'true');
        $dynamic_trigger  = sanitize_text_field($_POST['dynamic_trigger'] ?? '');
        $dynamic_threshold= intval($_POST['dynamic_threshold'] ?? 0);
        $dynamic_new_uri  = esc_url_raw($_POST['dynamic_new_uri'] ?? '');

        // Optional fields if mint already completed (webhook backfill)
        $nft_id           = sanitize_text_field($_POST['nft_id'] ?? '');
        $tx_hash          = sanitize_text_field($_POST['tx_hash'] ?? '');
        $issuer           = sanitize_text_field($_POST['issuer'] ?? '');

        // Basic validation
        if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) mh_json_bad('Invalid XRPL account');
        if ($mint_type === '' || $nft_name === '' || $media_type === '' || $ipfs_link === '') mh_json_bad('Missing required fields');
        if ($royalty_percent < 0 || $royalty_percent > 50) mh_json_bad('Royalty must be between 0 and 50');

        // Dynamic config blob
        $dynamic_config = null;
        if ($is_dynamic) {
            if ($dynamic_trigger === '' || $dynamic_new_uri === '') {
                mh_json_bad('Dynamic NFT requires trigger and new URI');
            }
            $dynamic_config = wp_json_encode([
                'trigger'   => $dynamic_trigger,
                'threshold' => $dynamic_threshold,
                'new_uri'   => $dynamic_new_uri
            ], JSON_UNESCAPED_SLASHES);
        }

        $now = current_time('mysql');

        $ok = $wpdb->insert($table, [
            'account'          => $account,
            'mint_type'        => $mint_type,
            'collection_name'  => $collection_name,
            'nft_id'           => ($nft_id && preg_match('/^[0-9A-Fa-f]{64}$/', $nft_id)) ? $nft_id : null,
            'nft_name'         => $nft_name,
            'media_type'       => $media_type,
            'ipfs_link'        => $ipfs_link,
            'description'      => $description,
            'price'            => $price,
            'royalty_percent'  => $royalty_percent,
            'status'           => 'active',
            'is_dynamic'       => $is_dynamic ? 1 : 0,
            'dynamic_config'   => $dynamic_config,
            'tx_hash'          => ($tx_hash && preg_match('/^[0-9A-Fa-f]{64}$/', $tx_hash)) ? $tx_hash : null,
            'issuer'           => ($issuer && preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $issuer)) ? $issuer : null,
            'created_at'       => $now,
            'updated_at'       => $now
        ], ['%s','%s','%s','%s','%s','%s','%s','%s','%f','%f','%s','%d','%s','%s','%s','%s','%s']);

        if ($ok === false) {
            wp_send_json_error(['error' => 'Failed to store mint: ' . $wpdb->last_error], 500);
        }

        wp_send_json_success(['mint_id' => $wpdb->insert_id]);
    }

    if ($method === 'GET') {
        // Optional filters
        $nonce = sanitize_text_field($_GET['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
            mh_json_bad('Invalid nonce', 403);
        }

        $account = sanitize_text_field($_GET['account'] ?? '');
        $status  = sanitize_text_field($_GET['status'] ?? 'active'); // active|pending|archived|failed
        $limit   = max(1, min(100, intval($_GET['limit'] ?? 50)));
        $offset  = max(0, intval($_GET['offset'] ?? 0));

        $where = '1=1';
        $args  = [];

        if ($account) {
            if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) mh_json_bad('Invalid XRPL account');
            $where .= ' AND account=%s';
            $args[] = $account;
        }
        if ($status) {
            $where .= ' AND status=%s';
            $args[] = $status;
        }

        $sql = $wpdb->prepare("SELECT * FROM $table WHERE $where ORDER BY created_at DESC LIMIT %d OFFSET %d", array_merge($args, [$limit, $offset]));
        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];

        wp_send_json_success([
            'mints'  => $rows,
            'limit'  => $limit,
            'offset' => $offset
        ]);
    }

    mh_json_bad('Invalid request', 400);
} catch (Exception $e) {
    wp_send_json_error(['error' => 'Mint handler error: ' . $e->getMessage()], 500);
}
exit;
