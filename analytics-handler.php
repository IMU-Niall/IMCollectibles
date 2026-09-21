<?php
/**
 * File: analytics-handler.php (Production Ready)
 * Path: /wp-content/themes/astra/xrpl-nft-marketplace/backend/analytics-handler.php
 * Description: Returns lightweight analytics for the public dashboard with nonce validation.
 */

require_once dirname(__DIR__, 5) . '/wp-load.php';
if (!defined('ABSPATH')) exit;

header('Content-Type: application/json');

global $wpdb;

// --- Logging ---
$log_dir  = __DIR__ . '/logs';
$log_file = $log_dir . '/analytics-handler.log';
if (!is_dir($log_dir)) { @mkdir($log_dir, 0755, true); }
function anl_log($msg) {
    global $log_file;
    @file_put_contents($log_file, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
}

// --- Tables (aligned names) ---
$offers_table    = $wpdb->prefix . 'xumm_offers';
$mints_table     = $wpdb->prefix . 'xumm_nft_mints';
$watchlist_table = $wpdb->prefix . 'xaman_watchlist'; // aligned with watchlist-handler.php
$task_table      = $wpdb->prefix . 'xumm_task_rewards';

// --- Input ---
$action = sanitize_text_field($_GET['action'] ?? '');
$nonce  = sanitize_text_field($_GET['nonce'] ?? '');

if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
    anl_log('Invalid nonce');
    wp_send_json_error(['error' => 'Invalid nonce'], 403);
}

// Helper: soft table check (returns bool; no fatal errors)
function table_exists_soft($table) {
    global $wpdb;
    $like = $wpdb->esc_like($table);
    return (bool)$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $like));
}

// Ensure tables exist (non-fatal; we’ll return empty sets if missing)
$exists = [
    'offers'    => table_exists_soft($offers_table),
    'mints'     => table_exists_soft($mints_table),
    'watchlist' => table_exists_soft($watchlist_table),
    'tasks'     => table_exists_soft($task_table),
];

try {
    switch ($action) {
        case 'top_traded':
            if (!$exists['offers']) {
                wp_send_json_success(['top_traded' => []]);
            }
            $rows = $wpdb->get_results("
                SELECT target_nft_id, COUNT(*) AS trade_count
                FROM $offers_table
                WHERE status = 'accepted'
                GROUP BY target_nft_id
                ORDER BY trade_count DESC
                LIMIT 10
            ", ARRAY_A) ?: [];
            if ($wpdb->last_error) throw new Exception($wpdb->last_error);
            wp_send_json_success(['top_traded' => $rows]);
            break;

        case 'top_creators':
            if (!$exists['mints']) {
                wp_send_json_success(['top_creators' => []]);
            }
            $rows = $wpdb->get_results("
                SELECT account, COUNT(*) AS minted
                FROM $mints_table
                GROUP BY account
                ORDER BY minted DESC
                LIMIT 10
            ", ARRAY_A) ?: [];
            if ($wpdb->last_error) throw new Exception($wpdb->last_error);
            wp_send_json_success(['top_creators' => $rows]);
            break;

        case 'top_watchlist':
            if (!$exists['watchlist']) {
                wp_send_json_success(['top_watchlist' => []]);
            }
            $rows = $wpdb->get_results("
                SELECT nft_id, COUNT(*) AS count
                FROM $watchlist_table
                GROUP BY nft_id
                ORDER BY count DESC
                LIMIT 10
            ", ARRAY_A) ?: [];
            if ($wpdb->last_error) throw new Exception($wpdb->last_error);
            wp_send_json_success(['top_watchlist' => $rows]);
            break;

        case 'task_leaderboard':
            if (!$exists['tasks']) {
                wp_send_json_success(['task_leaderboard' => []]);
            }
            $rows = $wpdb->get_results("
                SELECT account, SUM(points) AS total_points
                FROM $task_table
                GROUP BY account
                ORDER BY total_points DESC
                LIMIT 10
            ", ARRAY_A) ?: [];
            if ($wpdb->last_error) throw new Exception($wpdb->last_error);
            wp_send_json_success(['task_leaderboard' => $rows]);
            break;

        default:
            wp_send_json_error(['error' => 'Unknown action'], 400);
    }
} catch (Exception $e) {
    anl_log('Error: ' . $e->getMessage());
    wp_send_json_error(['error' => 'Analytics failed'], 500);
}
exit;
