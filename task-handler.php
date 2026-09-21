<?php
/**
 * File: task-handler.php (SECURE - v3.2)
 * Path: /wp-content/themes/astra/xrpl-nft-marketplace/backend/task-handler.php
 * 
 * v3.2 UPDATES:
 * - NEW: weekly_guardian_mint task (300 points for minting GOTF)
 * - UPDATED: verify_protector_mint and verify_guardian_mint use wp_nft_mints table
 * - This table is populated by the VPS mint webhook
 * 
 * v3.1 CRITICAL FIXES:
 * - FIXED: Game win verification now uses wp_game_progress table (was looking for non-existent wp_game_scores)
 * - FIXED: Burn verification now uses wp_nft_transfers table (was looking for non-existent wp_nft_burns)
 * - Game wins are tracked via JSON tasks data in game_progress table
 * - Burns are tracked via nft_transfers table with status='confirmed'
 * 
 * Endpoints:
 *   GET  ?action=list_tasks&nonce=...   → Tasks; completions for the session wallet
 *   GET  ?action=get_history&account=...&nonce=...    → Get user's completion history  
 *   GET  ?action=leaderboard&nonce=...                → Top point earners
 *   POST action=complete_task (account, task_id)      → Complete a task (server validates)
 *   POST action=redeem_boost                          → Redeem boost pass
 */

require_once dirname(__DIR__, 5) . '/wp-load.php';
if (!defined('ABSPATH')) exit;

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

global $wpdb;

// ============================================================================
// TABLE NAMES
// ============================================================================
$task_table = $wpdb->prefix . 'xumm_task_rewards';
$profile_table = $wpdb->prefix . 'xaman_profiles';

// ============================================================================
// LOGGING
// ============================================================================

$log_dir  = __DIR__ . '/logs';
$log_file = $log_dir . '/task-handler.log';
if (!is_dir($log_dir)) { @mkdir($log_dir, 0755, true); }

function tk_log($msg) {
    global $log_file;
    @file_put_contents($log_file, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
}

// ============================================================================
// 🔒 SERVER-SIDE TASK DEFINITIONS (v3.2)
// ============================================================================

function get_task_definitions() {
    return [
        // ==================== DAILY TASKS (max 55 pts/day) ====================
        'daily_login' => [
            'id'          => 'daily_login',
            'name'        => 'Daily Check-In',
            'description' => 'Log in to the marketplace and claim your daily reward',
            'points'      => 10,
            'type'        => 'daily',
            'category'    => 'engagement',
            'verify'      => null,
            'enabled'     => true,
            'icon'        => '📅',
            'max_per_day' => 1
        ],
        'daily_browse' => [
            'id'          => 'daily_browse',
            'name'        => 'Daily Explorer',
            'description' => 'Browse 10 different NFTs',
            'points'      => 10,
            'type'        => 'daily',
            'category'    => 'engagement',
            'verify'      => 'verify_daily_browse',
            'enabled'     => true,
            'icon'        => '🔍',
            'max_per_day' => 1
        ],
        'daily_gamer' => [
            'id'          => 'daily_gamer',
            'name'        => 'Daily Gamer',
            'description' => 'Win a game at Champions of Frequencies',
            'points'      => 25,
            'type'        => 'daily',
            'category'    => 'gaming',
            'verify'      => 'verify_daily_game_win',
            'enabled'     => true,
            'icon'        => '🎮',
            'max_per_day' => 1
        ],
        'daily_watchlist' => [
            'id'          => 'daily_watchlist',
            'name'        => 'Favourites Fan',
            'description' => 'Add an NFT to your favourites',
            'points'      => 10,
            'type'        => 'daily',
            'category'    => 'engagement',
            'verify'      => 'verify_watchlist_add',
            'enabled'     => true,
            'icon'        => '⭐',
            'max_per_day' => 1
        ],

        // ==================== WEEKLY TASKS ====================
        'weekly_protector_mint' => [
            'id'          => 'weekly_protector_mint',
            'name'        => 'Protector',
            'description' => 'Mint a Protector from our site (POTF or POTL)',
            'points'      => 250,
            'type'        => 'weekly',
            'category'    => 'minting',
            'verify'      => 'verify_protector_mint',
            'enabled'     => true,
            'icon'        => '⚔️',
            'max_per_week' => null  // UNLIMITED
        ],
        'weekly_guardian_mint' => [
            'id'          => 'weekly_guardian_mint',
            'name'        => 'Guardian',
            'description' => 'Mint a Guardian from our site (GOTF)',
            'points'      => 2500,
            'type'        => 'weekly',
            'category'    => 'minting',
            'verify'      => 'verify_guardian_mint',
            'enabled'     => true,
            'icon'        => '🛡️',
            'max_per_week' => null  // UNLIMITED
        ],
        'weekly_music_collector' => [
            'id'          => 'weekly_music_collector',
            'name'        => 'Music Collector',
            'description' => 'Purchase a Music NFT (Mint or Secondary market)',
            'points'      => 100,
            'type'        => 'weekly',
            'category'    => 'trading',
            'verify'      => 'verify_music_purchase',
            'enabled'     => true,
            'icon'        => '🎵',
            'max_per_week' => 1
        ],
        'weekly_sound_shifter' => [
            'id'          => 'weekly_sound_shifter',
            'name'        => 'Sound Shifter',
            'description' => 'Sell a Music NFT on the secondary market',
            'points'      => 100,
            'type'        => 'weekly',
            'category'    => 'trading',
            'verify'      => 'verify_music_sale',
            'enabled'     => true,
            'icon'        => '🔊',
            'max_per_week' => 1
        ],
        'weekly_purchase' => [
            'id'          => 'weekly_purchase',
            'name'        => 'Collector',
            'description' => 'Complete an NFT Purchase (secondary market)',
            'points'      => 100,
            'type'        => 'weekly',
            'category'    => 'trading',
            'verify'      => 'verify_weekly_purchase',
            'enabled'     => true,
            'icon'        => '🛒',
            'max_per_week' => 1
        ],
        'weekly_early_collector' => [
            'id'          => 'weekly_early_collector',
            'name'        => 'Early Collector',
            'description' => 'Purchase an NFT from Recent Drops / New Mint',
            'points'      => 100,
            'type'        => 'weekly',
            'category'    => 'trading',
            'verify'      => 'verify_recent_drop_purchase',
            'enabled'     => true,
            'icon'        => '🚀',
            'max_per_week' => 1
        ],
        'weekly_avid_gamer' => [
            'id'          => 'weekly_avid_gamer',
            'name'        => 'Avid Gamer',
            'description' => 'Win 5 games at Champions of Frequencies',
            'points'      => 100,
            'type'        => 'weekly',
            'category'    => 'gaming',
            'verify'      => 'verify_weekly_game_wins',
            'enabled'     => true,
            'icon'        => '🏆',
            'max_per_week' => 1
        ],

        // ==================== MONTHLY TASKS ====================
        'monthly_active' => [
            'id'          => 'monthly_active',
            'name'        => 'Frequency Feeder',
            'description' => 'Log in at least 15 days this month',
            'points'      => 100,
            'type'        => 'monthly',
            'category'    => 'engagement',
            'verify'      => 'verify_monthly_logins',
            'enabled'     => true,
            'icon'        => '📆'
        ],
        'monthly_volume' => [
            'id'          => 'monthly_volume',
            'name'        => 'Big Spender',
            'description' => 'Spend at least 100 XRP this month',
            'points'      => 100,
            'type'        => 'monthly',
            'category'    => 'trading',
            'verify'      => 'verify_monthly_volume',
            'enabled'     => true,
            'icon'        => '💰'
        ],
        'monthly_extra_protection' => [
            'id'          => 'monthly_extra_protection',
            'name'        => 'Extra Protection',
            'description' => 'Buy 10 Protectors in one month',
            'points'      => 250,
            'type'        => 'monthly',
            'category'    => 'trading',
            'verify'      => 'verify_monthly_protector_buys',
            'enabled'     => true,
            'icon'        => '🛡️'
        ],
        'monthly_champion' => [
            'id'          => 'monthly_champion',
            'name'        => 'Champion of Frequencies',
            'description' => 'Win 25 games at Champions of Frequencies',
            'points'      => 250,
            'type'        => 'monthly',
            'category'    => 'gaming',
            'verify'      => 'verify_monthly_game_wins',
            'enabled'     => true,
            'icon'        => '👑'
        ],

        // ==================== ONE-TIME ACHIEVEMENTS ====================
        'hold_protector' => [
            'id'          => 'hold_protector',
            'name'        => 'Protector Holder',
            'description' => 'Own a Protector NFT',
            'points'      => 100,
            'type'        => 'onetime',
            'category'    => 'achievement',
            'verify'      => 'verify_holds_protector',
            'enabled'     => true,
            'icon'        => '⚔️'
        ],
        'hold_guardian' => [
            'id'          => 'hold_guardian',
            'name'        => 'Guardian Holder',
            'description' => 'Own a Guardian NFT',
            'points'      => 250,
            'type'        => 'onetime',
            'category'    => 'achievement',
            'verify'      => 'verify_holds_guardian',
            'enabled'     => true,
            'icon'        => '🛡️'
        ],
        'frequency_collector' => [
            'id'          => 'frequency_collector',
            'name'        => 'Frequency Collector',
            'description' => 'Own 10 Protectors',
            'points'      => 250,
            'type'        => 'onetime',
            'category'    => 'achievement',
            'verify'      => 'verify_owns_ten_protectors',
            'enabled'     => true,
            'icon'        => '🎵'
        ],
        'forever_guarded' => [
            'id'          => 'forever_guarded',
            'name'        => 'Forever Guarded',
            'description' => 'Own 10 Guardians',
            'points'      => 500,
            'type'        => 'onetime',
            'category'    => 'achievement',
            'verify'      => 'verify_owns_ten_guardians',
            'enabled'     => true,
            'icon'        => '🏰'
        ],
        'frequencies_on_fire' => [
            'id'          => 'frequencies_on_fire',
            'name'        => 'Frequencies on Fire',
            'description' => 'Burn 10 Protectors at the Frequency Firepit',
            'points'      => 250,
            'type'        => 'onetime',
            'category'    => 'achievement',
            'verify'      => 'verify_burned_ten_protectors',
            'enabled'     => true,
            'icon'        => '🔥'
        ],
        'ten_trades' => [
            'id'          => 'ten_trades',
            'name'        => 'Experienced Trader',
            'description' => 'Complete 10 NFT trades (buys or sells)',
            'points'      => 100,
            'type'        => 'onetime',
            'category'    => 'achievement',
            'verify'      => 'verify_ten_trades',
            'enabled'     => true,
            'icon'        => '🔟'
        ],
        'first_purchase' => [
            'id'          => 'first_purchase',
            'name'        => 'First Purchase',
            'description' => 'Complete your first NFT purchase',
            'points'      => 100,
            'type'        => 'onetime',
            'category'    => 'achievement',
            'verify'      => 'verify_first_purchase',
            'enabled'     => true,
            'icon'        => '🎉'
        ],
        'first_sale' => [
            'id'          => 'first_sale',
            'name'        => 'First Sale',
            'description' => 'Complete your first NFT sale',
            'points'      => 100,
            'type'        => 'onetime',
            'category'    => 'achievement',
            'verify'      => 'verify_first_sale',
            'enabled'     => true,
            'icon'        => '🎊'
        ],

        // ==================== SPECIAL/PROMOTIONAL ====================
        'early_adopter' => [
            'id'          => 'early_adopter',
            'name'        => 'Early Adopter',
            'description' => 'Joined during marketplace beta',
            'points'      => 500,
            'type'        => 'onetime',
            'category'    => 'achievement',
            'verify'      => null,
            'enabled'     => false, // Admin-granted only
            'icon'        => '🚀'
        ],
    ];
}

// ============================================================================
// VERIFICATION FUNCTIONS - BASIC
// ============================================================================

function verify_watchlist_add($account) {
    global $wpdb;
    $watchlist_table = $wpdb->prefix . 'xaman_watchlist';
    
    if (!$wpdb->get_var("SHOW TABLES LIKE '$watchlist_table'")) {
        tk_log("Watchlist table doesn't exist: $watchlist_table");
        return false;
    }
    
    $count = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $watchlist_table WHERE account=%s AND DATE(created_at)=CURDATE()",
        $account
    ));
    return $count > 0;
}

function verify_daily_browse($account) {
    global $wpdb;
    $analytics_table = $wpdb->prefix . 'nft_analytics';
    
    if (!$wpdb->get_var("SHOW TABLES LIKE '$analytics_table'")) {
        return true; // Trust-based if no analytics
    }
    
    $count = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT nft_id) FROM $analytics_table 
         WHERE account=%s AND action='view' AND DATE(timestamp)=CURDATE()",
        $account
    ));
    return $count >= 10;
}

// ============================================================================
// 🎮 GAME WIN VERIFICATION - USES wp_game_progress TABLE (FIXED!)
// The game stores tasks/progress as JSON in this table
// ============================================================================

/**
 * Get game task data from the game_progress table
 * Returns parsed tasks array or empty array
 */
function get_game_tasks_data($account) {
    global $wpdb;
    $progress_table = $wpdb->prefix . 'game_progress';
    
    if (!$wpdb->get_var("SHOW TABLES LIKE '$progress_table'")) {
        tk_log("Game progress table doesn't exist: $progress_table");
        return [];
    }
    
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT tasks, progress_data, updated_at FROM $progress_table WHERE xrpl_account = %s",
        $account
    ), ARRAY_A);
    
    if (!$row) {
        tk_log("No game progress found for account: $account");
        return [];
    }
    
    // Try tasks column first, fallback to progress_data
    $tasks = json_decode($row['tasks'] ?? '{}', true);
    if (empty($tasks) && !empty($row['progress_data'])) {
        $progress = json_decode($row['progress_data'], true);
        $tasks = $progress['tasks'] ?? [];
    }
    
    return $tasks ?: [];
}

/**
 * Check if user has won at least 1 game today
 * Reads from wp_game_progress.tasks JSON
 */
function verify_daily_game_win($account) {
    global $wpdb;
    $progress_table = $wpdb->prefix . 'game_progress';
    
    if (!$wpdb->get_var("SHOW TABLES LIKE '$progress_table'")) {
        tk_log("verify_daily_game_win: game_progress table doesn't exist");
        return false;
    }
    
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT tasks, progress_data, updated_at FROM $progress_table WHERE xrpl_account = %s",
        $account
    ), ARRAY_A);
    
    if (!$row) {
        tk_log("verify_daily_game_win: No progress found for $account");
        return false;
    }
    
    // Check if updated today
    $updated_date = date('Y-m-d', strtotime($row['updated_at'] ?? ''));
    $today = date('Y-m-d');
    
    if ($updated_date !== $today) {
        tk_log("verify_daily_game_win: Progress not updated today for $account (last: $updated_date)");
        return false;
    }
    
    // Parse tasks JSON
    $tasks = json_decode($row['tasks'] ?? '{}', true);
    if (empty($tasks) && !empty($row['progress_data'])) {
        $progress = json_decode($row['progress_data'], true);
        $tasks = $progress['tasks'] ?? [];
    }
    
    // Check daily winGame progress (game.html tracks this)
    $daily_wins = (int)($tasks['daily']['winGame']['progress'] ?? 0);
    $completed = $tasks['daily']['winGame']['completed'] ?? false;
    
    tk_log("verify_daily_game_win: $account - wins=$daily_wins, completed=" . ($completed ? 'yes' : 'no'));
    
    return $daily_wins >= 1 || $completed;
}

/**
 * Check if user has won at least 5 games this week
 * Reads from wp_game_progress.tasks JSON
 */
function verify_weekly_game_wins($account) {
    global $wpdb;
    $progress_table = $wpdb->prefix . 'game_progress';
    
    if (!$wpdb->get_var("SHOW TABLES LIKE '$progress_table'")) {
        return false;
    }
    
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT tasks, progress_data FROM $progress_table WHERE xrpl_account = %s",
        $account
    ), ARRAY_A);
    
    if (!$row) return false;
    
    $tasks = json_decode($row['tasks'] ?? '{}', true);
    if (empty($tasks) && !empty($row['progress_data'])) {
        $progress = json_decode($row['progress_data'], true);
        $tasks = $progress['tasks'] ?? [];
    }
    
    // Check weekly winTenGames progress (best indicator of weekly wins)
    $weekly_wins = (int)($tasks['weekly']['winTenGames']['progress'] ?? 0);
    
    // Also check daily progress as fallback
    $daily_three = (int)($tasks['daily']['winThreeMatches']['progress'] ?? 0);
    
    $total = max($weekly_wins, $daily_three);
    
    tk_log("verify_weekly_game_wins: $account - weekly=$weekly_wins, daily3=$daily_three, total=$total");
    
    return $total >= 5;
}

/**
 * Check if user has won at least 25 games this month
 * Reads from wp_game_progress.tasks JSON
 */
function verify_monthly_game_wins($account) {
    global $wpdb;
    $progress_table = $wpdb->prefix . 'game_progress';
    
    if (!$wpdb->get_var("SHOW TABLES LIKE '$progress_table'")) {
        return false;
    }
    
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT tasks, progress_data FROM $progress_table WHERE xrpl_account = %s",
        $account
    ), ARRAY_A);
    
    if (!$row) return false;
    
    $tasks = json_decode($row['tasks'] ?? '{}', true);
    if (empty($tasks) && !empty($row['progress_data'])) {
        $progress = json_decode($row['progress_data'], true);
        $tasks = $progress['tasks'] ?? [];
    }
    
    // Weekly winTenGames is best indicator
    $weekly_wins = (int)($tasks['weekly']['winTenGames']['progress'] ?? 0);
    $weekly_completed = $tasks['weekly']['winTenGames']['completed'] ?? false;
    
    // If completed winTenGames multiple times, estimate higher
    $estimated = $weekly_wins;
    if ($weekly_completed && $weekly_wins < 10) {
        $estimated = 10;
    }
    
    tk_log("verify_monthly_game_wins: $account - wins=$weekly_wins, completed=" . ($weekly_completed ? 'yes' : 'no') . ", estimate=$estimated");
    
    return $estimated >= 25;
}

// ============================================================================
// 🔥 BURN VERIFICATION - USES wp_nft_transfers TABLE (FIXED!)
// Burns are tracked in nft_transfers with status='confirmed'
// ============================================================================

/**
 * Count confirmed burns from the nft_transfers table
 * This is the table used by burn-to-earn.php
 */
function count_confirmed_burns($account) {
    global $wpdb;
    $transfers_table = $wpdb->prefix . 'nft_transfers';
    
    if (!$wpdb->get_var("SHOW TABLES LIKE '$transfers_table'")) {
        tk_log("count_confirmed_burns: nft_transfers table doesn't exist");
        return 0;
    }
    
    $count = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $transfers_table 
         WHERE xrpl_account = %s AND status = 'confirmed'",
        $account
    ));
    
    tk_log("count_confirmed_burns: $account has $count confirmed burns");
    return $count;
}

/**
 * Check if user has burned at least 10 protectors
 * Reads from wp_nft_transfers where status='confirmed'
 */
function verify_burned_ten_protectors($account) {
    $burn_count = count_confirmed_burns($account);
    tk_log("verify_burned_ten_protectors: $account has $burn_count burns (need 10)");
    return $burn_count >= 10;
}

// ============================================================================
// 🪙 MINT VERIFICATION - USES wp_nft_mints TABLE (v3.2)
// This table is populated by the VPS mint webhook
// ============================================================================

/**
 * Verify user has minted a Protector (POTF or POTL) this week
 * Checks wp_nft_mints table populated by VPS webhook
 */
function verify_protector_mint($account) {
    global $wpdb;
    $mint_table = $wpdb->prefix . 'nft_mints';
    
    // Check if table exists
    if (!$wpdb->get_var("SHOW TABLES LIKE '$mint_table'")) {
        tk_log("verify_protector_mint: wp_nft_mints table doesn't exist");
        return false;
    }
    
    // Check for POTF or POTL mints this week
    $count = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $mint_table 
         WHERE account = %s 
         AND collection IN ('POTF', 'POTL', 'potf', 'potl')
         AND minted_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)",
        $account
    ));
    
    tk_log("verify_protector_mint: account=$account, protector_mints_this_week=$count");
    
    return $count > 0;
}

/**
 * Verify user has minted a Guardian (GOTF) this week
 * Checks wp_nft_mints table populated by VPS webhook
 */
function verify_guardian_mint($account) {
    global $wpdb;
    $mint_table = $wpdb->prefix . 'nft_mints';
    
    // Check if table exists
    if (!$wpdb->get_var("SHOW TABLES LIKE '$mint_table'")) {
        tk_log("verify_guardian_mint: wp_nft_mints table doesn't exist");
        return false;
    }
    
    // Check for GOTF mints this week
    $count = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $mint_table 
         WHERE account = %s 
         AND collection IN ('GOTF', 'gotf')
         AND minted_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)",
        $account
    ));
    
    tk_log("verify_guardian_mint: account=$account, gotf_mints_this_week=$count");
    
    return $count > 0;
}

// ============================================================================
// OTHER VERIFICATION FUNCTIONS
// ============================================================================

function verify_music_purchase($account) {
    global $wpdb;
    $offers_table = $wpdb->prefix . 'xumm_offers';
    $imc_purchases = $wpdb->prefix . 'imc_purchases';
    $imc_listings = $wpdb->prefix . 'imc_listings';
    $imc_groups = $wpdb->prefix . 'imc_purchase_groups';   // 4k-2
    
    $count = 0;
    
    // Check secondary market (xumm_offers)
    // Fix (Aug 2026): guard the phantom nft_type column - wp_xumm_offers is the raw
    // offers firehose and never had it, so this query errored every call. The guard
    // skips the block cleanly; it already contributed 0, so counts are unchanged.
    if ($wpdb->get_var("SHOW TABLES LIKE '$offers_table'") === $offers_table
        && $wpdb->get_var("SHOW COLUMNS FROM $offers_table LIKE 'nft_type'")) {
        $count += (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $offers_table 
             WHERE offerer_account=%s AND status='accepted' AND nft_type='music'
             AND accepted_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)",
            $account
        ));
    }
    
    // v62 FIX: Also check MOD purchases (primary market)
    if ($wpdb->get_var("SHOW TABLES LIKE '$imc_purchases'") === $imc_purchases) {
        $count += (int)$wpdb->get_var($wpdb->prepare(
    // 4k-2 (5 Sep 2026): count the MINTER, not the current owner.
    // wp_imc_purchases.buyer_account is an OWNERSHIP POINTER -- the ownership listener
    // rewrites it to the new owner on every sale/transfer ("enables Step A3 + correct Access tab"),
    // reinforced by the reconciliation jobs. Correct for its purpose, wrong for this question.
    // This counter corrects BOTH directions:
    //   GAIN  - RECEIVING a transferred NFT credited you for someone else's mint (farmable).
    //   LOSS  - SELLING your own removed credit you had genuinely earned.
    // wp_imc_purchase_groups.buyer_account is IMMUTABLE (all INSERT, never UPDATE; the listener's
    // only group write stamps payment_tx_hash alone), so it is the stable record of who minted.
    //
    // UNION ALL, not a single OR: `g.buyer_account = %s OR (g.id IS NULL AND p.buyer_account = %s)`
    // spans two tables, so the optimiser abandons idx_buyer and FULL-SCANS (measured on the
    // type=ALL query). Split into two arms, each has a single-table anchor and keeps its own index
    // (measured on a small table; arm 1 ref on idx_buyer covering, arm 2 ref + Not exists).
    // COLLATION: purchases.buyer_account is utf8mb4_general_ci, groups.buyer_account is
    // utf8mb4_unicode_520_ci. The two columns must NEVER meet in one expression -- COALESCE(g.x,p.x)
    // raises ERROR 1271, get_var() returns NULL, intval(NULL)=0, and the counter reads zero forever.
    // Every comparison below is column-vs-literal, so they never meet.
    // The second arm covers orphan purchases (nullable group_id; admin_compensate deletes
    // groups on its bare-row path) -- counted, never silently dropped.
    // p.created_at is UNCHANGED: the listener writes only buyer_account and updated_at, so the
    // week window is unaffected. Do NOT switch it to g.created_at.
            "SELECT COALESCE(SUM(c), 0) FROM (
                 SELECT COUNT(*) AS c
                   FROM $imc_groups g
                   JOIN $imc_purchases p ON p.group_id = g.id
                   JOIN $imc_listings l ON p.listing_id = l.id
                  WHERE g.buyer_account = %s
                    AND p.mint_status IN ('minted', 'claimed')
                    AND l.nft_type = 'music'
                    AND p.created_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
                 UNION ALL
                 SELECT COUNT(*)
                   FROM $imc_purchases p
                   LEFT JOIN $imc_groups g ON g.id = p.group_id
                   JOIN $imc_listings l ON p.listing_id = l.id
                  WHERE p.buyer_account = %s AND g.id IS NULL
                    AND p.mint_status IN ('minted', 'claimed')
                    AND l.nft_type = 'music'
                    AND p.created_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
             ) x",
            $account, $account
        ));
    }
    
    return $count > 0;
}

function verify_music_sale($account) {
    global $wpdb;
    $offers_table = $wpdb->prefix . 'xumm_offers';
    
    if (!$wpdb->get_var("SHOW TABLES LIKE '$offers_table'")) return false;
    // Fix (Aug 2026): phantom nft_type column - guard so an absent column returns
    // false (unchanged: the errored query already yielded 0 -> false).
    if (!$wpdb->get_var("SHOW COLUMNS FROM $offers_table LIKE 'nft_type'")) return false;
    
    $count = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $offers_table 
         WHERE target_account=%s AND status='accepted' AND nft_type='music'
         AND accepted_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)",
        $account
    ));
    return $count > 0;
}

function verify_weekly_purchase($account) {
    global $wpdb;
    $offers_table = $wpdb->prefix . 'xumm_offers';
    
    // v63 FIX: This task is "secondary market" only - do NOT include MOD purchases
    // MOD purchases are covered by Music Collector and Early Collector tasks
    if (!$wpdb->get_var("SHOW TABLES LIKE '$offers_table'") === $offers_table) {
        return false;
    }
    
    $count = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $offers_table 
         WHERE offerer_account=%s AND status='accepted'
         AND accepted_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)",
        $account
    ));
    
    return $count > 0;
}

function verify_recent_drop_purchase($account) {
    global $wpdb;
    $offers_table = $wpdb->prefix . 'xumm_offers';
    $imc_purchases = $wpdb->prefix . 'imc_purchases';
    $imc_groups = $wpdb->prefix . 'imc_purchase_groups';   // 4k-2
    
    $count = 0;
    
    // Check secondary market with is_recent_drop flag (legacy)
    // Fix (Aug 2026): guard the phantom is_recent_drop column (wp_xumm_offers never
    // had it). Skips cleanly; already contributed 0, so counts are unchanged.
    if ($wpdb->get_var("SHOW TABLES LIKE '$offers_table'") === $offers_table
        && $wpdb->get_var("SHOW COLUMNS FROM $offers_table LIKE 'is_recent_drop'")) {
        $count += (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $offers_table 
             WHERE offerer_account=%s AND status='accepted' AND is_recent_drop=1
             AND accepted_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)",
            $account
        ));
    }
    
    // v62 FIX: MOD purchases ARE recent drops by definition!
    if ($wpdb->get_var("SHOW TABLES LIKE '$imc_purchases'") === $imc_purchases) {
        $count += (int)$wpdb->get_var($wpdb->prepare(
            // 4k-2: see the note on verify_music_purchase -- MINTER not current owner, UNION ALL
            // to keep the index, and the two collations never meet in one expression.
            "SELECT COALESCE(SUM(c), 0) FROM (
                 SELECT COUNT(*) AS c
                   FROM $imc_groups g
                   JOIN $imc_purchases p ON p.group_id = g.id
                  WHERE g.buyer_account = %s
                    AND p.mint_status IN ('minted', 'claimed')
                    AND p.created_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
                 UNION ALL
                 SELECT COUNT(*)
                   FROM $imc_purchases p
                   LEFT JOIN $imc_groups g ON g.id = p.group_id
                  WHERE p.buyer_account = %s AND g.id IS NULL
                    AND p.mint_status IN ('minted', 'claimed')
                    AND p.created_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
             ) x",
            $account, $account
        ));
    }
    
    return $count > 0;
}

function verify_monthly_volume($account) {
    global $wpdb;
    $offers_table = $wpdb->prefix . 'xumm_offers';
    
    if (!$wpdb->get_var("SHOW TABLES LIKE '$offers_table'")) return false;
    
    $volume = (float)$wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(amount), 0) FROM $offers_table 
         WHERE offerer_account=%s AND status='accepted' AND currency='XRP'
         AND MONTH(accepted_at)=MONTH(CURDATE()) AND YEAR(accepted_at)=YEAR(CURDATE())",
        $account
    ));
    return $volume >= 100;
}

function verify_monthly_logins($account) {
    global $wpdb, $task_table;
    $days = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT DATE(timestamp)) FROM $task_table 
         WHERE account=%s AND task_id='daily_login'
         AND MONTH(timestamp)=MONTH(CURDATE()) AND YEAR(timestamp)=YEAR(CURDATE())",
        $account
    ));
    return $days >= 15;
}

function verify_monthly_protector_buys($account) {
    global $wpdb;
    $offers_table = $wpdb->prefix . 'xumm_offers';
    
    if (!$wpdb->get_var("SHOW TABLES LIKE '$offers_table'")) return false;
    // Fix (Aug 2026): phantom collection column - guard so an absent column returns
    // false (unchanged: the errored query already yielded 0 -> false).
    if (!$wpdb->get_var("SHOW COLUMNS FROM $offers_table LIKE 'collection'")) return false;
    
    $count = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $offers_table 
         WHERE offerer_account=%s AND status='accepted' 
         AND (collection LIKE '%protector%' OR collection IN ('POTF', 'POTL'))
         AND MONTH(accepted_at)=MONTH(CURDATE()) AND YEAR(accepted_at)=YEAR(CURDATE())",
        $account
    ));
    return $count >= 10;
}

function verify_first_purchase($account) {
    global $wpdb;
    $offers_table = $wpdb->prefix . 'xumm_offers';
    
    if (!$wpdb->get_var("SHOW TABLES LIKE '$offers_table'")) return false;
    
    $count = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $offers_table WHERE offerer_account=%s AND status='accepted'",
        $account
    ));
    return $count >= 1;
}

function verify_first_sale($account) {
    global $wpdb;
    $offers_table = $wpdb->prefix . 'xumm_offers';
    
    if (!$wpdb->get_var("SHOW TABLES LIKE '$offers_table'")) return false;
    
    $count = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $offers_table WHERE target_account=%s AND status='accepted'",
        $account
    ));
    return $count >= 1;
}

function verify_holds_guardian($account) {
    return verify_holds_collection($account, 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR', 0);
}

function verify_holds_protector($account) {
    $collections = [
        ['issuer' => 'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga', 'taxon' => 717825],
        ['issuer' => 'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt', 'taxon' => 1056369418],
        ['issuer' => 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR', 'taxon' => 777],
        ['issuer' => 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR', 'taxon' => 666],
    ];
    foreach ($collections as $col) {
        if (verify_holds_collection($account, $col['issuer'], $col['taxon'])) {
            return true;
        }
    }
    return false;
}

function verify_owns_ten_protectors($account) {
    return count_protectors_owned($account) >= 10;
}

function verify_owns_ten_guardians($account) {
    return count_guardians_owned($account) >= 10;
}

function count_protectors_owned($account) {
    $collections = [
        ['issuer' => 'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga', 'taxon' => 717825],
        ['issuer' => 'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt', 'taxon' => 1056369418],
        ['issuer' => 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR', 'taxon' => 777],
        ['issuer' => 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR', 'taxon' => 666],
    ];
    return count_nfts_from_collections($account, $collections);
}

function count_guardians_owned($account) {
    return count_nfts_from_collections($account, [
        ['issuer' => 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR', 'taxon' => 0]
    ]);
}

function count_nfts_from_collections($account, $collections) {
    $rpc_url = 'https://s1.ripple.com:51234/';
    $body = json_encode([
        'method' => 'account_nfts',
        'params' => [['account' => $account, 'ledger_index' => 'validated', 'limit' => 400]]
    ]);
    
    $res = wp_remote_post($rpc_url, [
        'body'    => $body,
        'headers' => ['Content-Type' => 'application/json'],
        'timeout' => 15
    ]);
    
    if (is_wp_error($res)) {
        tk_log("XRPL RPC error: " . $res->get_error_message());
        return 0;
    }
    
    $data = json_decode(wp_remote_retrieve_body($res), true);
    $nfts = $data['result']['account_nfts'] ?? [];
    
    $count = 0;
    foreach ($nfts as $nft) {
        foreach ($collections as $col) {
            if (($nft['Issuer'] ?? '') === $col['issuer'] && (int)($nft['NFTokenTaxon'] ?? -1) === $col['taxon']) {
                $count++;
                break;
            }
        }
    }
    return $count;
}

function verify_holds_collection($account, $issuer, $taxon) {
    $rpc_url = 'https://s1.ripple.com:51234/';
    $body = json_encode([
        'method' => 'account_nfts',
        'params' => [['account' => $account, 'ledger_index' => 'validated', 'limit' => 400]]
    ]);
    
    $res = wp_remote_post($rpc_url, [
        'body'    => $body,
        'headers' => ['Content-Type' => 'application/json'],
        'timeout' => 15
    ]);
    
    if (is_wp_error($res)) return false;
    
    $data = json_decode(wp_remote_retrieve_body($res), true);
    $nfts = $data['result']['account_nfts'] ?? [];
    
    foreach ($nfts as $nft) {
        if (($nft['Issuer'] ?? '') === $issuer && (int)($nft['NFTokenTaxon'] ?? -1) === $taxon) {
            return true;
        }
    }
    return false;
}

function verify_ten_trades($account) {
    global $wpdb;
    $offers_table = $wpdb->prefix . 'xumm_offers';
    
    if (!$wpdb->get_var("SHOW TABLES LIKE '$offers_table'")) return false;
    
    $count = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $offers_table 
         WHERE (offerer_account=%s OR target_account=%s) AND status='accepted'",
        $account, $account
    ));
    return $count >= 10;
}

// ============================================================================
// DATABASE SETUP
// ============================================================================

function ensure_task_table($wpdb, $table) {
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
    
    if (!$table_exists) {
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            account VARCHAR(35) NOT NULL,
            task_id VARCHAR(50) NOT NULL DEFAULT '',
            task_name VARCHAR(100) NOT NULL DEFAULT '',
            points INT NOT NULL DEFAULT 0,
            type VARCHAR(20) NOT NULL DEFAULT 'onetime',
            period_key VARCHAR(20) DEFAULT NULL,
            timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_account (account),
            KEY idx_task_id (task_id),
            KEY idx_type_time (type, timestamp),
            KEY idx_period (account, task_id, period_key)
        ) $charset;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        tk_log("Created task table: $table");
        return true;
    }
    
    // Check for missing columns
    $columns = $wpdb->get_results("SHOW COLUMNS FROM $table");
    $col_names = array_map(function($c) { return $c->Field; }, $columns);
    
    if (!in_array('task_id', $col_names)) {
        $wpdb->query("ALTER TABLE $table ADD COLUMN task_id VARCHAR(50) NOT NULL DEFAULT '' AFTER account");
    }
    if (!in_array('period_key', $col_names)) {
        $wpdb->query("ALTER TABLE $table ADD COLUMN period_key VARCHAR(20) DEFAULT NULL AFTER type");
    }
    
    return true;
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

function is_valid_xrpl_account($account) {
    return $account && preg_match('/^r[1-9A-HJ-NP-Za-km-z]{24,34}$/', $account);
}

function get_period_key($type) {
    switch ($type) {
        case 'daily':   return date('Y-m-d');
        case 'weekly':  return date('Y-W');
        case 'monthly': return date('Y-m');
        default:        return 'onetime';
    }
}

// ============================================================================
// MAIN HANDLER
// ============================================================================

try {
    ensure_task_table($wpdb, $task_table);
    
    $action = sanitize_text_field($_REQUEST['action'] ?? '');
    $method = $_SERVER['REQUEST_METHOD'];

    // ========================================================================
    // GET: List all tasks with completion status
    // ========================================================================
    if ($method === 'GET' && $action === 'list_tasks') {
        // ====================================================================
        // IDENTITY. Task completions and the points total are this wallet's own
        // activity history, so they come from the session, not from ?account=.
        //
        // The task DEFINITIONS stay public: the rewards page must still render
        // what is on offer for a logged-out visitor. With no session the list is
        // returned with nothing completed, nothing claimable and zero points,
        // which is exactly what a logged-out visitor should see.
        // ====================================================================
        $tk_ls_auth = function_exists('imc_session_require_wallet')
            ? imc_session_require_wallet('')
            : array('ok' => false, 'wallet' => '');
        $account = !empty($tk_ls_auth['ok']) ? (string) $tk_ls_auth['wallet'] : '';
        if ($account !== '' && !is_valid_xrpl_account($account)) {
            $account = '';
        }

        $tasks = get_task_definitions();
        $completions = $wpdb->get_results($wpdb->prepare(
            "SELECT task_id, type, period_key, MAX(timestamp) as last_completed
             FROM $task_table WHERE account=%s GROUP BY task_id, type, period_key",
            $account
        ), ARRAY_A);
        
        $completion_map = [];
        foreach ($completions as $c) {
            $key = $c['task_id'] . '_' . ($c['period_key'] ?? 'onetime');
            $completion_map[$key] = $c;
        }

        $output = [];
        foreach ($tasks as $task) {
            if (!$task['enabled']) continue;
            
            $period_key = get_period_key($task['type']);
            $map_key = $task['id'] . '_' . $period_key;
            $completed = isset($completion_map[$map_key]);
            
            $can_claim = !$completed;
            $requires_verification = !empty($task['verify']);
            
            if ($account !== '' && $can_claim && $requires_verification) {
                $verify_fn = $task['verify'];
                if (function_exists($verify_fn)) {
                    $can_claim = call_user_func($verify_fn, $account);
                }
            }
            
            $output[] = [
                'id'                   => $task['id'],
                'name'                 => $task['name'],
                'description'          => $task['description'],
                'points'               => $task['points'],
                'type'                 => $task['type'],
                'icon'                 => $task['icon'] ?? '🎯',
                'completed'            => $completed,
                'can_claim'            => $can_claim,
                'requires_verification'=> $requires_verification,
                'max_per_day'          => $task['max_per_day'] ?? null,
                'max_per_week'         => $task['max_per_week'] ?? null,
            ];
        }

        $total_points = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(points), 0) FROM $task_table WHERE account=%s",
            $account
        ));

        wp_send_json_success(['tasks' => $output, 'total_points' => $total_points]);
        exit;
    }

    // ========================================================================
    // GET: Leaderboard
    // ========================================================================
    if ($method === 'GET' && $action === 'leaderboard') {
        $limit = min(50, max(1, intval($_GET['limit'] ?? 10)));
        $period = sanitize_text_field($_GET['period'] ?? 'all');
        
        $where_clause = '';
        if ($period === 'weekly') {
            $where_clause = "AND timestamp >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)";
        } elseif ($period === 'monthly') {
            $where_clause = "AND MONTH(timestamp) = MONTH(CURDATE()) AND YEAR(timestamp) = YEAR(CURDATE())";
        }
        
        $leaders = $wpdb->get_results(
            "SELECT account, SUM(points) as total_points, COUNT(*) as tasks_completed
             FROM $task_table WHERE points > 0 $where_clause
             GROUP BY account ORDER BY total_points DESC LIMIT $limit",
            ARRAY_A
        ) ?: [];
        
        foreach ($leaders as &$entry) {
            $profile = $wpdb->get_row($wpdb->prepare(
                "SELECT name FROM $profile_table WHERE xrpl_account=%s",
                $entry['account']
            ));
            $entry['display_name'] = $profile->name ?? null;
            $entry['account_short'] = substr($entry['account'], 0, 6) . '...' . substr($entry['account'], -4);
        }

        wp_send_json_success(['leaderboard' => $leaders]);
        exit;
    }

    // ========================================================================
    // POST: Complete a task
    // ========================================================================
    if ($method === 'POST' && $action === 'complete_task') {
        // Nonce verification for write operations
        $nonce = sanitize_text_field($_POST['nonce'] ?? $_SERVER['HTTP_X_WP_NONCE'] ?? '');
        if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
            wp_send_json_error(['error' => 'Invalid security token'], 403);
        }

        // Phase 1 (Aug 2026): identity from the session token; a posted
        // account that differs from the session wallet is rejected.
        $tk_auth = function_exists('imc_session_require_wallet')
            ? imc_session_require_wallet(sanitize_text_field($_POST['account'] ?? ''))
            : array('ok' => false, 'wallet' => '', 'error' => 'auth');
        if (empty($tk_auth['ok'])) {
            tk_log('complete_task rejected: ' . (($tk_auth['error'] ?? '') === 'mismatch' ? 'account mismatch (posted != session)' : 'not authenticated'));
            wp_send_json_error(['error' => (($tk_auth['error'] ?? '') === 'mismatch') ? 'Account mismatch' : 'Not authenticated'], 403);
        }
        $account = $tk_auth['wallet'];
        $task_id = sanitize_text_field($_POST['task_id'] ?? '');
        
        tk_log("complete_task: account=$account, task_id=$task_id");
        
        if (!is_valid_xrpl_account($account)) {
            wp_send_json_error(['error' => 'Invalid XRPL account'], 400);
        }

        $tasks = get_task_definitions();
        if (!isset($tasks[$task_id])) {
            wp_send_json_error(['error' => 'Unknown task: ' . $task_id], 404);
        }

        $task = $tasks[$task_id];
        if (!$task['enabled']) {
            wp_send_json_error(['error' => 'Task not available'], 403);
        }

        $period_key = get_period_key($task['type']);
        
        // Check if already completed
        $already = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $task_table WHERE account=%s AND task_id=%s AND period_key=%s",
            $account, $task_id, $period_key
        ));

        if ($already > 0 && $task['type'] !== 'weekly') {
            wp_send_json_error(['error' => 'Task already completed for this period'], 409);
        }
        
        // Weekly limit check
        if ($task['type'] === 'weekly') {
            $max_per_week = $task['max_per_week'] ?? 1;
            if ($max_per_week !== null) {
                $week_count = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $task_table WHERE account=%s AND task_id=%s AND period_key=%s",
                    $account, $task_id, $period_key
                ));
                if ($week_count >= $max_per_week) {
                    wp_send_json_error(['error' => 'Weekly limit reached'], 409);
                }
            }
        }

        // Run verification
        if (!empty($task['verify'])) {
            $verify_fn = $task['verify'];
            if (function_exists($verify_fn)) {
                $verified = call_user_func($verify_fn, $account);
                if (!$verified) {
                    tk_log("Verification failed: $task_id / $verify_fn for $account");
                    wp_send_json_error(['error' => 'Task requirements not met'], 403);
                }
            } else {
                wp_send_json_error(['error' => 'Task verification unavailable'], 500);
            }
        }

        // Record completion
        $points = $task['points'];
        $result = $wpdb->insert($task_table, [
            'account' => $account,
            'task_id' => $task_id,
            'task_name' => $task['name'],
            'points' => $points,
            'type' => $task['type'],
            'period_key' => $period_key,
            'timestamp' => current_time('mysql')
        ]);

        if ($result === false) {
            wp_send_json_error(['error' => 'Failed to record task'], 500);
        }

        $total_points = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(points), 0) FROM $task_table WHERE account=%s",
            $account
        ));

        tk_log("Task completed: $task_id by $account = $points pts, total=$total_points");

        wp_send_json_success([
            'message' => 'Task completed!',
            'task_id' => $task_id,
            'task_name' => $task['name'],
            'points_earned' => $points,
            'total_points' => $total_points
        ]);
        exit;
    }

    // ========================================================================
    // POST: Redeem boost pass
    // ========================================================================
    if ($method === 'POST' && $action === 'redeem_boost') {
        // Nonce verification for write operations
        $nonce = sanitize_text_field($_POST['nonce'] ?? $_SERVER['HTTP_X_WP_NONCE'] ?? '');
        if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
            wp_send_json_error(['error' => 'Invalid security token'], 403);
        }

        // Phase 1 (Aug 2026): identity from the session token; a posted
        // account that differs from the session wallet is rejected.
        $tk_auth = function_exists('imc_session_require_wallet')
            ? imc_session_require_wallet(sanitize_text_field($_POST['account'] ?? ''))
            : array('ok' => false, 'wallet' => '', 'error' => 'auth');
        if (empty($tk_auth['ok'])) {
            tk_log('redeem_boost rejected: ' . (($tk_auth['error'] ?? '') === 'mismatch' ? 'account mismatch (posted != session)' : 'not authenticated'));
            wp_send_json_error(['error' => (($tk_auth['error'] ?? '') === 'mismatch') ? 'Account mismatch' : 'Not authenticated'], 403);
        }
        $account = $tk_auth['wallet'];
        $boost_type = sanitize_text_field($_POST['boost_type'] ?? '');
        $tier = sanitize_text_field($_POST['tier'] ?? '');
        $duration = sanitize_text_field($_POST['duration'] ?? '');
        $points_cost = intval($_POST['points'] ?? 0);
        
        tk_log("redeem_boost: account=$account, type=$boost_type, tier=$tier, duration=$duration, points=$points_cost");
        
        if (!is_valid_xrpl_account($account)) {
            wp_send_json_error(['error' => 'Invalid XRPL account'], 400);
        }
        
        if (!in_array($boost_type, ['fountain', 'game'])) {
            wp_send_json_error(['error' => 'Invalid boost type'], 400);
        }
        
        if (!in_array($tier, ['bronze', 'silver', 'gold'])) {
            wp_send_json_error(['error' => 'Invalid boost tier'], 400);
        }
        
        if (!in_array($duration, ['week', 'month'])) {
            wp_send_json_error(['error' => 'Invalid boost duration'], 400);
        }
        
        // Server-side price validation (40-50% reduced pricing)
        $valid_costs = [
            'bronze_week' => 1500,
            'bronze_month' => 4500,
            'silver_week' => 2500,
            'silver_month' => 7500,
            'gold_week' => 5000,
            'gold_month' => 15000
        ];
        
        $expected_cost = $valid_costs[$tier . '_' . $duration] ?? 0;
        
        if ($points_cost !== $expected_cost || $expected_cost === 0) {
            tk_log("redeem_boost: Price mismatch - sent $points_cost, expected $expected_cost");
            wp_send_json_error(['error' => 'Invalid points cost'], 400);
        }
        
        $user_points = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(points), 0) FROM $task_table WHERE account = %s",
            $account
        ));
        
        if ($user_points < $points_cost) {
            wp_send_json_error([
                'error' => 'Insufficient points. You have ' . number_format($user_points) . ' but need ' . number_format($points_cost) . '.'
            ], 400);
        }
        
        if (!function_exists('imu_activate_boost')) {
            wp_send_json_error(['error' => 'Boost system not configured'], 500);
        }
        
        $success = imu_activate_boost($account, $boost_type, $tier, $duration, $points_cost);
        
        if ($success) {
            $tier_labels = ['bronze' => 'Bronze', 'silver' => 'Silver', 'gold' => 'Gold'];
            $type_labels = ['fountain' => 'Protectors', 'game' => 'Gaming'];
            $duration_labels = ['week' => '1 Week', 'month' => '1 Month'];
            
            $reward_name = $tier_labels[$tier] . ' ' . $type_labels[$boost_type] . ' Booster (' . $duration_labels[$duration] . ')';
            
            $wpdb->insert($task_table, [
                'account' => $account,
                'task_id' => 'boost_redemption_' . $tier . '_' . $boost_type . '_' . $duration,
                'task_name' => $reward_name,
                'points' => -$points_cost,
                'type' => 'redemption',
                'period_key' => date('Y-m-d'),
                'timestamp' => current_time('mysql')
            ]);
            
            $new_balance = $user_points - $points_cost;
            tk_log("redeem_boost: SUCCESS - $reward_name for $account. Balance: $new_balance");
            
            wp_send_json_success([
                'message' => 'Boost activated!',
                'boost_type' => $boost_type,
                'tier' => $tier,
                'duration' => $duration,
                'points_spent' => $points_cost,
                'new_balance' => $new_balance
            ]);
        } else {
            wp_send_json_error(['error' => 'Failed to activate boost'], 500);
        }
        exit;
    }

    // Legacy GET support
    if ($method === 'GET' && empty($action)) {
        $account = sanitize_text_field($_GET['account'] ?? '');
        if (is_valid_xrpl_account($account)) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT task_id, task_name, points, type, timestamp 
                 FROM $task_table WHERE account=%s ORDER BY timestamp DESC LIMIT 50",
                $account
            ), ARRAY_A) ?: [];
            $total = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(points),0) FROM $task_table WHERE account=%s",
                $account
            ));
            wp_send_json_success(['tasks' => $rows, 'total_points' => $total]);
            exit;
        }
    }

    wp_send_json_error(['error' => 'Invalid action: ' . $action], 400);

} catch (Exception $e) {
    tk_log('Exception: ' . $e->getMessage());
    wp_send_json_error(['error' => 'Task handler failed: ' . $e->getMessage()], 500);
}
exit;