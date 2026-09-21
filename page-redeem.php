<?php
/**
 * Template Name: IMU Redeem
 * File: page-redeem.php
 * Path: /wp-content/themes/astra/page-redeem.php
 * 
 * v3.0 - Updates:
 * - Updated boost pricing (40-50% reduction)
 * - Organized into 5 rows with row labels
 * - Fixed mobile icon background positioning
 * - Added Coming Soon items (Vouchers, IMU Pro Pass)
 */

// ── OG Meta ─────────────────────────────────────────────────────────────────
global $imc_og_data;
$imc_og_data = [
    'title'       => 'Redeem | IMCollectibles',
    'description' => 'Redeem your IMU tokens and vouchers for exclusive NFTs and rewards on the IMCollectibles marketplace on the XRP Ledger.',
    'image'       => defined('IMC_OG_DEFAULT_IMAGE') ? IMC_OG_DEFAULT_IMAGE : 'https://imcollectibles.io/wp-content/uploads/og-default.png',
    'url'         => home_url('/redeem/'),
    'type'        => 'website',
];

get_header();

$nonce = wp_create_nonce('xrpl_marketplace_nonce');
$user_account = function_exists('imc_session_wallet') ? imc_session_wallet() : '';
$handler_url = get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/task-handler.php';

// Get user stats if logged in
$user_points = 0;
$tasks_completed = 0;

if ($user_account) {
    global $wpdb;
    $task_table = $wpdb->prefix . 'xumm_task_rewards';
    
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$task_table'") === $task_table;
    
    if ($table_exists) {
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT SUM(points) as total_points, COUNT(*) as tasks_completed 
             FROM $task_table WHERE account = %s",
            $user_account
        ));
        
        if ($stats) {
            $user_points = (int)($stats->total_points ?? 0);
            $tasks_completed = (int)($stats->tasks_completed ?? 0);
        }
    }
}

// Define rewards organized by rows (NEW PRICING - 40-50% reduction)
$reward_rows = [
    // ROW 1: Weekly Protectors Boosters
    [
        'label' => '⛲ Protectors Boosters - 1 Week',
        'rewards' => [
            [
                'id' => 'bronze_fountain_week',
                'name' => 'Bronze',
                'description' => '1.25x XFT rewards at Frequency Fountain for 7 days.',
                'points' => 1500,  // Was 2500
                'icon' => '🥉',
                'category' => 'boost',
                'tier' => 'bronze',
                'boost_type' => 'fountain',
                'duration' => 'week',
                'multiplier' => '1.25x'
            ],
            [
                'id' => 'silver_fountain_week',
                'name' => 'Silver',
                'description' => '1.5x XFT rewards at Frequency Fountain for 7 days.',
                'points' => 2500,  // Was 5000
                'icon' => '🥈',
                'category' => 'boost',
                'tier' => 'silver',
                'boost_type' => 'fountain',
                'duration' => 'week',
                'multiplier' => '1.5x'
            ],
            [
                'id' => 'gold_fountain_week',
                'name' => 'Gold',
                'description' => '2x XFT rewards at Frequency Fountain for 7 days.',
                'points' => 5000,  // Was 10000
                'icon' => '🥇',
                'category' => 'boost',
                'tier' => 'gold',
                'boost_type' => 'fountain',
                'duration' => 'week',
                'multiplier' => '2x'
            ]
        ]
    ],
    
    // ROW 2: Weekly Gaming Boosters
    [
        'label' => '🎮 Gaming Boosters - 1 Week',
        'rewards' => [
            [
                'id' => 'bronze_game_week',
                'name' => 'Bronze',
                'description' => '1.25x rewards in Champions of Frequencies for 7 days.',
                'points' => 1500,  // Was 2500
                'icon' => '🥉',
                'category' => 'boost',
                'tier' => 'bronze',
                'boost_type' => 'game',
                'duration' => 'week',
                'multiplier' => '1.25x'
            ],
            [
                'id' => 'silver_game_week',
                'name' => 'Silver',
                'description' => '1.5x rewards in Champions of Frequencies for 7 days.',
                'points' => 2500,  // Was 5000
                'icon' => '🥈',
                'category' => 'boost',
                'tier' => 'silver',
                'boost_type' => 'game',
                'duration' => 'week',
                'multiplier' => '1.5x'
            ],
            [
                'id' => 'gold_game_week',
                'name' => 'Gold',
                'description' => '2x rewards in Champions of Frequencies for 7 days.',
                'points' => 5000,  // Was 10000
                'icon' => '🥇',
                'category' => 'boost',
                'tier' => 'gold',
                'boost_type' => 'game',
                'duration' => 'week',
                'multiplier' => '2x'
            ]
        ]
    ],
    
    // ROW 3: Monthly Protectors Boosters
    [
        'label' => '⛲ Protectors Boosters - 1 Month',
        'rewards' => [
            [
                'id' => 'bronze_fountain_month',
                'name' => 'Bronze',
                'description' => '1.25x XFT rewards at Frequency Fountain for 30 days.',
                'points' => 4500,  // Was 7500
                'icon' => '🥉',
                'category' => 'boost',
                'tier' => 'bronze',
                'boost_type' => 'fountain',
                'duration' => 'month',
                'multiplier' => '1.25x'
            ],
            [
                'id' => 'silver_fountain_month',
                'name' => 'Silver',
                'description' => '1.5x XFT rewards at Frequency Fountain for 30 days.',
                'points' => 7500,  // Was 15000
                'icon' => '🥈',
                'category' => 'boost',
                'tier' => 'silver',
                'boost_type' => 'fountain',
                'duration' => 'month',
                'multiplier' => '1.5x'
            ],
            [
                'id' => 'gold_fountain_month',
                'name' => 'Gold',
                'description' => '2x XFT rewards at Frequency Fountain for 30 days.',
                'points' => 15000,  // Was 30000
                'icon' => '🥇',
                'category' => 'boost',
                'tier' => 'gold',
                'boost_type' => 'fountain',
                'duration' => 'month',
                'multiplier' => '2x'
            ]
        ]
    ],
    
    // ROW 4: Monthly Gaming Boosters
    [
        'label' => '🎮 Gaming Boosters - 1 Month',
        'rewards' => [
            [
                'id' => 'bronze_game_month',
                'name' => 'Bronze',
                'description' => '1.25x rewards in Champions of Frequencies for 30 days.',
                'points' => 4500,  // Was 7500
                'icon' => '🥉',
                'category' => 'boost',
                'tier' => 'bronze',
                'boost_type' => 'game',
                'duration' => 'month',
                'multiplier' => '1.25x'
            ],
            [
                'id' => 'silver_game_month',
                'name' => 'Silver',
                'description' => '1.5x rewards in Champions of Frequencies for 30 days.',
                'points' => 7500,  // Was 15000
                'icon' => '🥈',
                'category' => 'boost',
                'tier' => 'silver',
                'boost_type' => 'game',
                'duration' => 'month',
                'multiplier' => '1.5x'
            ],
            [
                'id' => 'gold_game_month',
                'name' => 'Gold',
                'description' => '2x rewards in Champions of Frequencies for 30 days.',
                'points' => 15000,  // Was 30000
                'icon' => '🥇',
                'category' => 'boost',
                'tier' => 'gold',
                'boost_type' => 'game',
                'duration' => 'month',
                'multiplier' => '2x'
            ]
        ]
    ],
    
    // ROW 5: Coming Soon Items
    [
        'label' => '🎁 More Rewards',
        'rewards' => [
            [
                'id' => 'voucher_5',
                'name' => '$5 IMU Merch Voucher',
                'description' => 'Get $5 off at the IMU Merch Store.',
                'points' => 5000,
                'icon' => '🛍️',
                'category' => 'voucher',
                'coming_soon' => true
            ],
            [
                'id' => 'voucher_10',
                'name' => '$10 IMU Merch Voucher',
                'description' => 'Get $10 off at the IMU Merch Store.',
                'points' => 10000,
                'icon' => '🛍️',
                'category' => 'voucher',
                'coming_soon' => true
            ],
            [
                'id' => 'pro_pass',
                'name' => 'IMU Pro Pass (1 Month)',
                'description' => 'Unlock premium features across the IMU ecosystem.',
                'points' => 25000,
                'icon' => '⭐',
                'category' => 'subscription',
                'coming_soon' => true
            ]
        ]
    ]
];
?>

<style>
/* ============================================
   REDEEM PAGE STYLES v3.0
   ============================================ */
.redeem-page-container {
    position: relative;
    min-height: 100vh;
    width: 100%;
    z-index: 1;
}

.redeem-page {
    max-width: 1200px;
    width: 100%;
    margin: 0 auto;
    padding: 2rem;
}

/* Hero Section */
.redeem-hero {
    text-align: center;
    padding: 3rem 2rem;
    margin-bottom: 3rem;
    background: linear-gradient(135deg, rgba(255, 215, 0, 0.1), rgba(255, 165, 0, 0.05));
    border: 1px solid rgba(255, 215, 0, 0.3);
    border-radius: 20px;
    position: relative;
    overflow: hidden;
}

.redeem-hero::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle, rgba(255, 215, 0, 0.1) 0%, transparent 50%);
    animation: heroGlow 8s ease-in-out infinite;
    pointer-events: none;
}

@keyframes heroGlow {
    0%, 100% { transform: translate(0, 0); }
    50% { transform: translate(10%, 10%); }
}

.redeem-hero h1 {
    font-size: 2.5rem;
    margin-bottom: 0.5rem;
    position: relative;
    z-index: 1;
    font-family: 'Cinzel Decorative', 'Cinzel', 'Times New Roman', serif;
    font-weight: 700;
    letter-spacing: 0.07em;
    background: linear-gradient(180deg, #ffe066 0%, var(--imu-gold, #d6ba66) 40%, #996515 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    filter: drop-shadow(0 0 18px rgba(212, 175, 55, 0.4));
}

.redeem-hero p {
    color: #888;
    font-size: 1.1rem;
    margin-bottom: 2rem;
    position: relative;
    z-index: 1;
}

.redeem-stats {
    display: flex;
    justify-content: center;
    gap: 3rem;
    flex-wrap: wrap;
    position: relative;
    z-index: 1;
}

.redeem-stat {
    text-align: center;
}

.redeem-stat-value {
    font-size: 3rem;
    font-weight: bold;
    color: #FFD700;
    line-height: 1;
}

.redeem-stat-label {
    font-size: 0.9rem;
    color: #888;
    margin-top: 0.5rem;
    text-transform: uppercase;
    letter-spacing: 1px;
}

/* Not Connected State */
.redeem-connect-prompt {
    background: rgba(255, 215, 0, 0.1);
    border: 1px dashed rgba(255, 215, 0, 0.4);
    border-radius: 12px;
    padding: 2rem;
    text-align: center;
    margin-top: 1.5rem;
}

.redeem-connect-prompt p {
    margin-bottom: 1rem;
    color: #ccc;
}

.redeem-connect-btn {
    display: inline-block;
    padding: 0.75rem 2rem;
    background: linear-gradient(135deg, #FFD700, #DAA520);
    color: #000;
    text-decoration: none;
    border-radius: 8px;
    font-weight: 600;
    transition: all 0.2s;
}

.redeem-connect-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(255, 215, 0, 0.3);
}

/* Row Labels */
.reward-row {
    margin-bottom: 2.5rem;
}

.reward-row-label {
    font-size: 1.25rem;
    color: #FFD700;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid rgba(255, 215, 0, 0.2);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

/* Rewards Grid - 3 columns for desktop */
.rewards-row-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.25rem;
}

/* Reward Card */
.reward-card {
    background: #1a1a2e;
    border: 1px solid rgba(255, 215, 0, 0.2);
    border-radius: 16px;
    padding: 1.5rem;
    transition: all 0.3s;
    display: flex;
    flex-direction: column;
    position: relative;
}

.reward-card:hover {
    border-color: rgba(255, 215, 0, 0.5);
    transform: translateY(-4px);
    box-shadow: 0 10px 30px rgba(255, 215, 0, 0.1);
}

.reward-card.affordable {
    border-color: rgba(76, 175, 80, 0.5);
}

.reward-card.coming-soon {
    opacity: 0.7;
}

.reward-card.coming-soon::after {
    content: 'COMING SOON';
    position: absolute;
    top: 1rem;
    right: 1rem;
    background: rgba(255, 165, 0, 0.9);
    color: #000;
    font-size: 0.65rem;
    font-weight: 700;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    letter-spacing: 0.5px;
}

/* Reward Header */
.reward-header {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    margin-bottom: 1rem;
}

.reward-icon-wrapper {
    position: relative;
    width: 60px;
    height: 60px;
    flex-shrink: 0;
}

.reward-icon-bg {
    position: absolute;
    width: 48px;
    height: 48px;
    background: rgba(255, 215, 0, 0.15);
    border-radius: 12px;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
}

.reward-icon {
    position: relative;
    z-index: 1;
    font-size: 2.5rem;
    width: 60px;
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.reward-info {
    flex: 1;
}

.reward-info h3 {
    margin: 0 0 0.25rem 0;
    font-size: 1.1rem;
    font-family: 'Cinzel', 'Times New Roman', serif;
    font-weight: 700;
    letter-spacing: 0.04em;
    background: linear-gradient(180deg, #ffe066 0%, var(--imu-gold, #d6ba66) 40%, #996515 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    filter: drop-shadow(0 0 8px rgba(212, 175, 55, 0.2));
}

.reward-multiplier {
    display: inline-block;
    font-size: 0.75rem;
    color: #FFD700;
    background: rgba(255, 215, 0, 0.15);
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    font-weight: 600;
}

.reward-category {
    font-size: 0.7rem;
    color: #888;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: 0.25rem;
}

.reward-description {
    color: #888;
    font-size: 0.85rem;
    line-height: 1.5;
    margin-bottom: 1rem;
    flex: 1;
}

/* Reward Footer */
.reward-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 1rem;
    border-top: 1px solid rgba(255, 215, 0, 0.1);
}

.reward-cost {
    font-size: 1.25rem;
    font-weight: bold;
    color: #FFD700;
}

.reward-cost span {
    font-size: 0.8rem;
    color: #888;
    font-weight: normal;
}

.reward-btn {
    padding: 0.6rem 1.25rem;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 0.9rem;
}

.reward-btn.can-afford {
    background: linear-gradient(135deg, #FFD700, #DAA520);
    color: #000;
}

.reward-btn.can-afford:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(255, 215, 0, 0.3);
}

.reward-btn.cannot-afford {
    background: rgba(255, 255, 255, 0.1);
    color: #666;
    cursor: not-allowed;
}

.reward-btn.not-connected {
    background: rgba(255, 215, 0, 0.2);
    color: #FFD700;
}

.reward-btn.coming-soon {
    background: rgba(255, 165, 0, 0.2);
    color: #FFA500;
    cursor: not-allowed;
}

/* Leaderboard Section */
.leaderboard-section {
    margin-bottom: 3rem;
    margin-top: 2rem;
}

.leaderboard-header {
    text-align: center;
    margin-bottom: 1.5rem;
}

.leaderboard-header h2 {
    font-size: 1.5rem;
    font-family: 'Cinzel Decorative', 'Cinzel', 'Times New Roman', serif;
    font-weight: 700;
    letter-spacing: 0.07em;
    background: linear-gradient(180deg, #ffe066 0%, var(--imu-gold, #d6ba66) 40%, #996515 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    filter: drop-shadow(0 0 14px rgba(212, 175, 55, 0.35));
}

.leaderboard-tabs {
    display: flex;
    justify-content: center;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
}

.leaderboard-tab {
    padding: 0.5rem 1.25rem;
    border: 1px solid rgba(255, 215, 0, 0.3);
    background: transparent;
    color: #fff;
    border-radius: 20px;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 0.9rem;
}

.leaderboard-tab:hover,
.leaderboard-tab.active {
    background: linear-gradient(135deg, #FFD700, #FFA500);
    color: #000;
    border-color: #FFD700;
}

.leaderboard-table-wrapper {
    background: #1a1a2e;
    border: 1px solid rgba(255, 215, 0, 0.2);
    border-radius: 16px;
    overflow: hidden;
}

.leaderboard-table {
    width: 100%;
    border-collapse: collapse;
}

.leaderboard-table th {
    background: rgba(255, 215, 0, 0.1);
    color: #FFD700;
    font-weight: 600;
    text-align: left;
    padding: 1rem 1.25rem;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.leaderboard-table td {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid rgba(255, 215, 0, 0.1);
    color: #e8e6e3;
}

.leaderboard-table tbody tr:last-child td {
    border-bottom: none;
}

.leaderboard-table tbody tr:hover {
    background: rgba(255, 215, 0, 0.05);
}

.leaderboard-table tbody tr.current-user {
    background: rgba(255, 215, 0, 0.1);
}

.leaderboard-rank {
    font-weight: 700;
    width: 50px;
}

.leaderboard-rank-1 { color: #ffd700; font-size: 1.2rem; }
.leaderboard-rank-2 { color: #c0c0c0; font-size: 1.1rem; }
.leaderboard-rank-3 { color: #cd7f32; font-size: 1.05rem; }

.leaderboard-user {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.leaderboard-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, #FFD700, #DAA520);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    color: #000;
    font-size: 0.9rem;
}

.leaderboard-name {
    font-weight: 500;
}

.leaderboard-points {
    color: #FFD700;
    font-weight: 600;
}

.leaderboard-tasks {
    color: #888;
}

/* Loading State */
.leaderboard-loading {
    text-align: center;
    padding: 3rem;
    color: #888;
}

.leaderboard-loading::before {
    content: '';
    display: block;
    width: 40px;
    height: 40px;
    border: 3px solid rgba(255, 215, 0, 0.2);
    border-top-color: #FFD700;
    border-radius: 50%;
    margin: 0 auto 1rem;
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Back Link */
.redeem-back-link {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    color: #888;
    text-decoration: none;
    margin-bottom: 1.5rem;
    font-size: 0.9rem;
    transition: color 0.2s;
}

.redeem-back-link:hover { color: #FFD700; }

/* ============================================
   MOBILE RESPONSIVE STYLES
   ============================================ */
@media (max-width: 992px) {
    .rewards-row-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .redeem-page { 
        padding: 1rem; 
    }
    
    .redeem-hero { 
        padding: 2rem 1rem; 
    }
    
    .redeem-hero h1 { 
        font-size: 1.75rem; 
    }
    
    .redeem-stats { 
        gap: 1.5rem; 
    }
    
    .redeem-stat-value { 
        font-size: 2rem; 
    }
    
    /* Stack cards on mobile */
    .rewards-row-grid { 
        grid-template-columns: 1fr; 
        gap: 1rem;
    }
    
    .reward-row-label {
        font-size: 1.1rem;
    }
    
    /* Mobile card styling - stacked/ladder cards */
    .reward-card {
        padding: 1.25rem;
    }
    
    /* FIXED: Icon background centered on icon, not badge */
    .reward-icon-wrapper {
        width: 50px;
        height: 50px;
    }
    
    .reward-icon-bg {
        width: 40px;
        height: 40px;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
    }
    
    .reward-icon {
        font-size: 2rem;
        width: 50px;
        height: 50px;
    }
    
    .reward-info h3 {
        font-size: 1rem;
    }
    
    .reward-description {
        font-size: 0.8rem;
    }
    
    .reward-cost {
        font-size: 1.1rem;
    }
    
    .reward-btn {
        padding: 0.5rem 1rem;
        font-size: 0.85rem;
    }
    
    .leaderboard-table th,
    .leaderboard-table td { 
        padding: 0.75rem; 
        font-size: 0.85rem; 
    }
    
    .leaderboard-table .hide-mobile { 
        display: none; 
    }
}

/* Small mobile */
@media (max-width: 400px) {
    .reward-header {
        gap: 0.75rem;
    }
    
    .reward-icon-wrapper {
        width: 45px;
        height: 45px;
    }
    
    .reward-icon-bg {
        width: 36px;
        height: 36px;
    }
    
    .reward-icon {
        font-size: 1.75rem;
        width: 45px;
        height: 45px;
    }
}
</style>

<div class="redeem-page-container">
    <div class="redeem-page">
        <a href="<?php echo esc_url(home_url('/tasks/')); ?>" class="redeem-back-link">← Back to Tasks</a>
        
        <!-- Hero Section -->
        <div class="redeem-hero">
            <h1>🎁 Rewards Hub</h1>
            <p>Redeem your hard-earned points for exclusive boosts and perks</p>
            
            <?php if ($user_account): ?>
                <div class="redeem-stats">
                    <div class="redeem-stat">
                        <div class="redeem-stat-value" id="user-points"><?php echo number_format($user_points); ?></div>
                        <div class="redeem-stat-label">Available Points</div>
                    </div>
                    <div class="redeem-stat">
                        <div class="redeem-stat-value" id="tasks-completed"><?php echo number_format($tasks_completed); ?></div>
                        <div class="redeem-stat-label">Tasks Completed</div>
                    </div>
                </div>
            <?php else: ?>
                <div class="redeem-connect-prompt">
                    <p>Connect your wallet to view your points and redeem rewards</p>
                    <a href="<?php echo esc_url(home_url('/login/?redirect=' . urlencode($_SERVER['REQUEST_URI']))); ?>" class="redeem-connect-btn">
                        Connect Wallet
                    </a>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Rewards by Row -->
        <?php foreach ($reward_rows as $row_index => $row): ?>
        <div class="reward-row">
            <div class="reward-row-label"><?php echo $row['label']; ?></div>
            <div class="rewards-row-grid">
                <?php foreach ($row['rewards'] as $reward): 
                    $can_afford = $user_account && $user_points >= $reward['points'];
                    $is_coming_soon = !empty($reward['coming_soon']);
                ?>
                    <div class="reward-card <?php echo $can_afford && !$is_coming_soon ? 'affordable' : ''; ?> <?php echo $is_coming_soon ? 'coming-soon' : ''; ?>">
                        <div class="reward-header">
                            <div class="reward-icon-wrapper">
                                <div class="reward-icon-bg"></div>
                                <div class="reward-icon"><?php echo $reward['icon']; ?></div>
                            </div>
                            <div class="reward-info">
                                <h3><?php echo esc_html($reward['name']); ?></h3>
                                <?php if (!empty($reward['multiplier'])): ?>
                                    <span class="reward-multiplier"><?php echo esc_html($reward['multiplier']); ?> Boost</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <p class="reward-description"><?php echo esc_html($reward['description']); ?></p>
                        <div class="reward-footer">
                            <div class="reward-cost">
                                <?php echo number_format($reward['points']); ?> <span>pts</span>
                            </div>
                            <?php if ($is_coming_soon): ?>
                                <button class="reward-btn coming-soon" disabled>Coming Soon</button>
                            <?php elseif (!$user_account): ?>
                                <button class="reward-btn not-connected" disabled>Connect Wallet</button>
                            <?php elseif ($can_afford): ?>
                                <button class="reward-btn can-afford" onclick="redeemReward('<?php echo esc_attr($reward['id']); ?>')">
                                    Redeem
                                </button>
                            <?php else: ?>
                                <button class="reward-btn cannot-afford" disabled>
                                    Need <?php echo number_format($reward['points'] - $user_points); ?> more
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
        
        <!-- Leaderboard Section -->
        <div class="leaderboard-section">
            <div class="leaderboard-header">
                <h2>🏅 Points Leaderboard</h2>
            </div>
            
            <div class="leaderboard-tabs">
                <button class="leaderboard-tab active" data-period="all">All Time</button>
                <button class="leaderboard-tab" data-period="monthly">This Month</button>
                <button class="leaderboard-tab" data-period="weekly">This Week</button>
            </div>
            
            <div class="leaderboard-table-wrapper">
                <table class="leaderboard-table">
                    <thead>
                        <tr>
                            <th style="width: 60px;">Rank</th>
                            <th>User</th>
                            <th>Points</th>
                            <th class="hide-mobile">Tasks</th>
                        </tr>
                    </thead>
                    <tbody id="leaderboard-body">
                        <tr>
                            <td colspan="4">
                                <div class="leaderboard-loading">Loading leaderboard...</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    const HANDLER_URL = '<?php echo esc_js($handler_url); ?>';
    const NONCE = '<?php echo esc_js($nonce); ?>';
    const USER_ACCOUNT = '<?php echo esc_js($user_account); ?>';
    
    let currentPeriod = 'all';
    
    // Tab clicks
    document.querySelectorAll('.leaderboard-tab').forEach(tab => {
        tab.addEventListener('click', function() {
            document.querySelectorAll('.leaderboard-tab').forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            currentPeriod = this.dataset.period;
            loadLeaderboard(currentPeriod);
        });
    });
    
    async function loadLeaderboard(period) {
        const tbody = document.getElementById('leaderboard-body');
        tbody.innerHTML = '<tr><td colspan="4"><div class="leaderboard-loading">Loading leaderboard...</div></td></tr>';
        
        try {
            const url = `${HANDLER_URL}?action=leaderboard&period=${period}&limit=20&nonce=${encodeURIComponent(NONCE)}`;
            const response = await fetch(url);
            
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            
            const data = await response.json();
            
            if (!data.success || !data.data?.leaderboard) {
                throw new Error(data.data?.error || 'Failed to load leaderboard');
            }
            
            renderLeaderboard(data.data.leaderboard);
        } catch (error) {
            console.error('Leaderboard error:', error);
            tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; color: #888; padding: 2rem;">Failed to load leaderboard</td></tr>';
        }
    }
    
    function renderLeaderboard(entries) {
        const tbody = document.getElementById('leaderboard-body');
        
        if (!entries.length) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; color: #888; padding: 2rem;">No entries yet. Complete tasks to appear on the leaderboard!</td></tr>';
            return;
        }
        
        const html = entries.map((entry, index) => {
            const rank = index + 1;
            let rankClass = 'leaderboard-rank';
            if (rank === 1) rankClass += ' leaderboard-rank-1';
            else if (rank === 2) rankClass += ' leaderboard-rank-2';
            else if (rank === 3) rankClass += ' leaderboard-rank-3';
            
            const isCurrentUser = USER_ACCOUNT && entry.account === USER_ACCOUNT;
            const displayName = entry.display_name || entry.account_short || entry.account.slice(0, 6) + '...' + entry.account.slice(-4);
            const initials = displayName.slice(0, 2).toUpperCase();
            
            return `
                <tr class="${isCurrentUser ? 'current-user' : ''}">
                    <td class="${rankClass}">${rank}</td>
                    <td>
                        <div class="leaderboard-user">
                            <div class="leaderboard-avatar">${initials}</div>
                            <span class="leaderboard-name">${escHtml(displayName)}${isCurrentUser ? ' (You)' : ''}</span>
                        </div>
                    </td>
                    <td class="leaderboard-points">${parseInt(entry.total_points).toLocaleString()}</td>
                    <td class="leaderboard-tasks hide-mobile">${parseInt(entry.tasks_completed).toLocaleString()}</td>
                </tr>
            `;
        }).join('');
        
        tbody.innerHTML = html;
    }
    
    function escHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
    
    // Boost pass definitions with NEW PRICING (40-50% reduction)
    const BOOST_PASSES = {
        // Weekly passes
        'bronze_fountain_week': { type: 'fountain', tier: 'bronze', duration: 'week', points: 1500 },
        'silver_fountain_week': { type: 'fountain', tier: 'silver', duration: 'week', points: 2500 },
        'gold_fountain_week': { type: 'fountain', tier: 'gold', duration: 'week', points: 5000 },
        'bronze_game_week': { type: 'game', tier: 'bronze', duration: 'week', points: 1500 },
        'silver_game_week': { type: 'game', tier: 'silver', duration: 'week', points: 2500 },
        'gold_game_week': { type: 'game', tier: 'gold', duration: 'week', points: 5000 },
        // Monthly passes
        'bronze_fountain_month': { type: 'fountain', tier: 'bronze', duration: 'month', points: 4500 },
        'silver_fountain_month': { type: 'fountain', tier: 'silver', duration: 'month', points: 7500 },
        'gold_fountain_month': { type: 'fountain', tier: 'gold', duration: 'month', points: 15000 },
        'bronze_game_month': { type: 'game', tier: 'bronze', duration: 'month', points: 4500 },
        'silver_game_month': { type: 'game', tier: 'silver', duration: 'month', points: 7500 },
        'gold_game_month': { type: 'game', tier: 'gold', duration: 'month', points: 15000 }
    };
    
    // Redeem function
    window.redeemReward = async function(rewardId) {
        if (!USER_ACCOUNT) {
            alert('Please connect your wallet first.');
            return;
        }
        
        const pass = BOOST_PASSES[rewardId];
        if (pass) {
            const tierNames = { bronze: '1.25x', silver: '1.5x', gold: '2x' };
            const typeNames = { fountain: 'Frequency Fountain', game: 'Champions of Frequencies' };
            const durationNames = { week: '7 days', month: '30 days' };
            
            const confirmMsg = `Activate ${tierNames[pass.tier]} boost for ${typeNames[pass.type]} for ${durationNames[pass.duration]}?\n\nCost: ${pass.points.toLocaleString()} points`;
            
            if (!confirm(confirmMsg)) {
                return;
            }
            
            try {
                const response = await fetch(HANDLER_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'redeem_boost',
                        account: USER_ACCOUNT,
                        boost_type: pass.type,
                        tier: pass.tier,
                        duration: pass.duration,
                        points: pass.points,
                        nonce: NONCE
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert(`🎉 Boost Activated!\n\n${tierNames[pass.tier]} ${typeNames[pass.type]} boost is now active for ${durationNames[pass.duration]}!\n\nYour rewards have been boosted!`);
                    location.reload();
                } else {
                    alert('❌ Failed to activate boost:\n\n' + (data.data?.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('Redeem error:', error);
                alert('Network error. Please try again.');
            }
        } else {
            alert('🎉 Coming Soon!\n\nThis reward will be available soon. Keep earning points!');
        }
    };
    
    // Load initial leaderboard
    loadLeaderboard('all');
})();
</script>

<?php get_footer(); ?>