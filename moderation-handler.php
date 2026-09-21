<?php
/**
 * File: moderation-handler.php (Production Ready)
 * Path: /wp-content/themes/astra/xrpl-nft-marketplace/backend/moderation-handler.php
 * Description: Admin moderation panel for flagging NFTs/users and managing blacklists.
 */

require_once dirname(__DIR__, 5) . '/wp-load.php';
if (!defined('ABSPATH')) exit;

header('Content-Type: application/json');

global $wpdb;

// --- Logging ---
$log_dir  = __DIR__ . '/logs';
$log_file = $log_dir . '/moderation-handler.log';
if (!is_dir($log_dir)) { @mkdir($log_dir, 0755, true); }
function mod_log($msg) {
    global $log_file;
    @file_put_contents($log_file, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
}

// --- Tables ---
$table         = $wpdb->prefix . 'xumm_moderation_flags';
$profile_table = $wpdb->prefix . 'xaman_profiles';

// Ensure flags table exists (idempotent)
$wpdb->query("
CREATE TABLE IF NOT EXISTS $table (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  account VARCHAR(35) NOT NULL,
  nft_id VARCHAR(64) DEFAULT NULL,
  reason VARCHAR(255) NOT NULL,
  flagged_by VARCHAR(100) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'flagged',
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_account (account),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Admin shared secret for blacklist ops
$admin_secret = defined('ADMIN_API_TOKEN') ? ADMIN_API_TOKEN : getenv('ADMIN_API_TOKEN');
if (!$admin_secret) {
    mod_log('Missing ADMIN_API_TOKEN');
    wp_send_json_error(['error' => 'Server configuration error'], 500);
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'POST') {
        // Submit a flag
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
            mod_log('Invalid nonce');
            wp_send_json_error(['error' => 'Invalid nonce'], 403);
        }

        $account    = sanitize_text_field($_POST['account'] ?? '');
        $reason     = sanitize_text_field($_POST['reason'] ?? '');
        $nft_id     = sanitize_text_field($_POST['nft_id'] ?? '');
        $flagged_by = sanitize_text_field($_POST['flagged_by'] ?? '');

        if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account) || !$reason || !$flagged_by) {
            wp_send_json_error(['error' => 'Invalid moderation fields'], 400);
        }
        if ($nft_id && !preg_match('/^[0-9A-Fa-f]{64}$/', $nft_id)) {
            wp_send_json_error(['error' => 'Invalid NFT ID'], 400);
        }

        $ok = $wpdb->insert($table, [
            'account'    => $account,
            'nft_id'     => $nft_id ?: null,
            'reason'     => $reason,
            'flagged_by' => $flagged_by,
            'status'     => 'flagged',
            'created_at' => current_time('mysql')
        ], ['%s','%s','%s','%s','%s','%s']);

        if ($ok === false) {
            mod_log('Insert failed: ' . $wpdb->last_error);
            wp_send_json_error(['error' => 'Failed to submit flag'], 500);
        }

        wp_send_json_success(['message' => 'Flag submitted']);
    }

    if ($method === 'GET') {
        // List latest flags
        $nonce = sanitize_text_field($_GET['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
            mod_log('Invalid nonce');
            wp_send_json_error(['error' => 'Invalid nonce'], 403);
        }

        $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC LIMIT 50", ARRAY_A) ?: [];
        wp_send_json_success(['flags' => $rows]);
    }

    if ($method === 'PUT' && isset($_GET['blacklist'], $_GET['account']) && (isset($_GET['token']) || isset($_SERVER['HTTP_X_ADMIN_TOKEN']))) {
        // Blacklist/Unban via admin token (server-to-server)
        $input   = json_decode(file_get_contents('php://input'), true) ?: [];
        $account = sanitize_text_field($_GET['account']);
        // The token may arrive as a header (preferred -- a query string lands in
        // access logs, proxy logs and Referer) or, for compatibility with existing
        // callers, as ?token=. Header wins when both are present.
        $token   = sanitize_text_field($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? $_GET['token']);
        $action  = sanitize_text_field($input['action'] ?? 'ban');

        if (!hash_equals((string) $admin_secret, (string) $token)) {
            mod_log('Unauthorized blacklist attempt');
            wp_send_json_error(['error' => 'Unauthorized'], 403);
        }
        if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
            wp_send_json_error(['error' => 'Invalid XRPL account'], 400);
        }

        if ($action === 'ban' || $action === 'unban') {
            $val = ($action === 'ban') ? 1 : 0;
            $ok  = $wpdb->update($profile_table, ['blacklisted' => $val], ['xrpl_account' => $account], ['%d'], ['%s']);
            if ($ok === false) {
                mod_log('Blacklist update failed: ' . $wpdb->last_error);
                wp_send_json_error(['error' => 'DB error'], 500);
            }

            // Optional email via OneSignal (if configured)
            $prof = $wpdb->get_row($wpdb->prepare("SELECT email FROM $profile_table WHERE xrpl_account=%s", $account));
            if ($prof && !empty($prof->email) && defined('ONESIGNAL_APP_ID') && defined('ONESIGNAL_API_KEY')) {
                $subject = $action === 'ban' ? 'Account Blacklisted' : 'Account Restored';
                $body    = $action === 'ban'
                    ? 'Your account has been blacklisted due to a policy violation.'
                    : 'Your account blacklist has been removed.';
                $payload = [
                    'app_id'                => ONESIGNAL_APP_ID,
                    'include_email_tokens'  => [$prof->email],
                    'email_subject'         => $subject,
                    'email_body'            => $body
                ];
                $res = wp_remote_post('https://onesignal.com/api/v1/notifications', [
                    'headers' => ['Authorization' => 'Basic ' . ONESIGNAL_API_KEY, 'Content-Type' => 'application/json'],
                    'body'    => wp_json_encode($payload),
                    'timeout' => 10
                ]);
                if (is_wp_error($res)) {
                    mod_log('OneSignal email error: ' . $res->get_error_message());
                }
            }

            wp_send_json_success(['message' => $action === 'ban' ? 'User blacklisted' : 'Blacklist removed']);
        } else {
            wp_send_json_error(['error' => 'Unknown action'], 400);
        }
    }

    wp_send_json_error(['error' => 'Invalid moderation request'], 400);
} catch (Exception $e) {
    mod_log('Error: ' . $e->getMessage());
    wp_send_json_error(['error' => 'Moderation failed'], 500);
}
exit;