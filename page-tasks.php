<?php
/**
 * Template Name: IMU Tasks
 * File: page-tasks.php
 * Path: /wp-content/themes/astra/page-tasks.php
 * 
 * v3.0 - Mobile UI improvements:
 * - Stats boxes side-by-side on mobile (2-column grid)
 * - Category dropdown selector on mobile (hidden buttons)
 * - Updated task definitions display
 */

// ── OG Meta ─────────────────────────────────────────────────────────────────
global $imc_og_data;
$imc_og_data = [
    'title'       => 'Tasks & Rewards | IMCollectibles',
    'description' => 'Complete tasks, earn XFT token rewards, and unlock achievements in the IMCollectibles ecosystem on the XRP Ledger.',
    'image'       => defined('IMC_OG_DEFAULT_IMAGE') ? IMC_OG_DEFAULT_IMAGE : 'https://imcollectibles.io/wp-content/uploads/og-default.png',
    'url'         => home_url('/tasks/'),
    'type'        => 'website',
];

get_header();

$nonce = wp_create_nonce('xrpl_marketplace_nonce');
$user_account = function_exists('imc_session_wallet') ? imc_session_wallet() : '';

?>

<style>
/* Tasks Page Container */
.tasks-page-container {
    position: relative;
    min-height: 100vh;
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    z-index: 1;
}

/* Marketplace Navigation Header */
.marketplace-header-nav {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 1rem;
    padding: 1rem 2rem;
    flex-wrap: wrap;
    width: 100%;
    max-width: 1200px;
}

.marketplace-header-nav .nav-link {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    background: rgba(255, 215, 0, 0.1);
    border: 1px solid rgba(255, 215, 0, 0.3);
    border-radius: 8px;
    color: #FFD700;
    text-decoration: none;
    font-size: 0.9rem;
    font-weight: 500;
    transition: all 0.2s ease;
}

.marketplace-header-nav .nav-link:hover {
    background: rgba(255, 215, 0, 0.2);
    border-color: rgba(255, 215, 0, 0.5);
    transform: translateY(-2px);
}

.marketplace-header-nav .nav-link.active {
    background: linear-gradient(135deg, #FFD700, #DAA520);
    color: #000;
    border-color: #FFD700;
}

.marketplace-header-nav .nav-link svg {
    width: 16px;
    height: 16px;
}

/* Tasks Page Styles */
.tasks-page {
    max-width: 1200px;
    width: 100%;
    margin: 0 auto;
    padding: 2rem;
    min-height: 80vh;
}

.tasks-header {
    text-align: center;
    margin-bottom: 2rem;
}

.tasks-header h1 {
    font-size: 2.5rem;
    margin-bottom: 0.5rem;
    font-family: 'Cinzel Decorative', 'Cinzel', 'Times New Roman', serif;
    font-weight: 700;
    letter-spacing: 0.07em;
    background: linear-gradient(180deg, #ffe066 0%, var(--imu-gold, #d6ba66) 40%, #996515 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    filter: drop-shadow(0 0 18px rgba(212, 175, 55, 0.4));
}

.tasks-header p {
    color: var(--text-secondary, #888);
    font-size: 1.1rem;
}

/* Stats Section - Desktop */
.tasks-stats {
    display: flex;
    justify-content: center;
    gap: 2rem;
    margin-bottom: 2rem;
    flex-wrap: wrap;
}

.stat-card {
    background: linear-gradient(135deg, rgba(255, 215, 0, 0.1), rgba(255, 165, 0, 0.05));
    border: 1px solid rgba(255, 215, 0, 0.3);
    border-radius: 12px;
    padding: 1.5rem 2.5rem;
    text-align: center;
    min-width: 150px;
}

.stat-value {
    font-size: 2rem;
    font-weight: bold;
    color: #FFD700;
}

.stat-label {
    font-size: 0.9rem;
    color: var(--text-secondary, #888);
    margin-top: 0.25rem;
}

/* Category Buttons - Desktop */
.tasks-categories {
    display: flex;
    justify-content: center;
    gap: 0.5rem;
    margin-bottom: 2rem;
    flex-wrap: wrap;
}

.category-btn {
    padding: 0.5rem 1.25rem;
    border: 1px solid rgba(255, 215, 0, 0.3);
    background: transparent;
    color: var(--text-primary, #fff);
    border-radius: 20px;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 0.9rem;
}

.category-btn:hover,
.category-btn.active {
    background: linear-gradient(135deg, #FFD700, #FFA500);
    color: #000;
    border-color: #FFD700;
}

/* Category Dropdown - Mobile Only */
.category-dropdown-wrapper {
    display: none;
    margin-bottom: 1.5rem;
    width: 100%;
}

.category-dropdown {
    width: 100%;
    padding: 0.875rem 2.5rem 0.875rem 1rem;
    background: linear-gradient(135deg, rgba(255, 215, 0, 0.1), rgba(255, 165, 0, 0.05));
    border: 1px solid rgba(255, 215, 0, 0.4);
    border-radius: 12px;
    color: #FFD700;
    font-size: 1rem;
    font-weight: 600;
    line-height: 1.4;
    min-height: 48px;
    height: auto;
    cursor: pointer;
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%23FFD700' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 1rem center;
    background-size: 20px;
}

.category-dropdown:focus {
    outline: none;
    border-color: #FFD700;
    box-shadow: 0 0 0 3px rgba(255, 215, 0, 0.2);
}

.category-dropdown option {
    background: #1a1a2e;
    color: #fff;
    padding: 0.5rem;
}

/* Tasks Grid */
.tasks-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1.5rem;
}

.task-card {
    background: var(--card-bg, #1a1a2e);
    border: 1px solid rgba(255, 215, 0, 0.2);
    border-radius: 12px;
    padding: 1.5rem;
    transition: all 0.3s;
}

.task-card:hover {
    border-color: rgba(255, 215, 0, 0.5);
    transform: translateY(-2px);
}

.task-card.completed {
    opacity: 0.7;
    border-color: rgba(76, 175, 80, 0.5);
}

.task-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1rem;
}

.task-icon {
    font-size: 2rem;
    width: 50px;
    height: 50px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(255, 215, 0, 0.1);
    border-radius: 10px;
}

.task-info h3 {
    margin: 0;
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

.task-type {
    font-size: 0.75rem;
    color: #FFD700;
    text-transform: uppercase;
    margin-top: 0.25rem;
}

.task-description {
    color: var(--text-secondary, #888);
    font-size: 0.9rem;
    margin-bottom: 1rem;
    line-height: 1.5;
}

.task-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.task-points {
    font-size: 1.25rem;
    font-weight: bold;
    color: #FFD700;
}

.task-points span {
    font-size: 0.8rem;
    color: var(--text-secondary, #888);
    font-weight: normal;
}

.claim-btn {
    padding: 0.5rem 1.25rem;
    background: linear-gradient(135deg, #FFD700, #FFA500);
    color: #000;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.claim-btn:hover:not(:disabled) {
    transform: scale(1.05);
    box-shadow: 0 4px 15px rgba(255, 215, 0, 0.3);
}

.claim-btn:disabled {
    background: #444;
    color: #888;
    cursor: not-allowed;
}

.claim-btn.completed {
    background: #4CAF50;
    color: #fff;
}

/* Login Prompt */
.login-prompt {
    text-align: center;
    padding: 4rem 2rem;
    background: var(--card-bg, #1a1a2e);
    border-radius: 12px;
    border: 1px solid rgba(255, 215, 0, 0.2);
}

.login-prompt h2 {
    color: var(--text-primary, #fff);
    margin-bottom: 1rem;
}

.login-prompt p {
    color: var(--text-secondary, #888);
    margin-bottom: 1.5rem;
}

.login-btn {
    padding: 0.75rem 2rem;
    background: linear-gradient(135deg, #FFD700, #FFA500);
    color: #000;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    font-size: 1rem;
}

/* Loading State */
.tasks-loading {
    text-align: center;
    padding: 3rem;
    color: var(--text-secondary, #888);
}

.tasks-loading .spinner {
    width: 40px;
    height: 40px;
    border: 3px solid rgba(255, 215, 0, 0.2);
    border-top-color: #FFD700;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin: 0 auto 1rem;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Leaderboard Section */
.leaderboard-section {
    margin-top: 3rem;
    padding-top: 2rem;
    border-top: 1px solid rgba(255, 215, 0, 0.2);
}

.leaderboard-section h2 {
    text-align: center;
    margin-bottom: 1.5rem;
    font-family: 'Cinzel Decorative', 'Cinzel', 'Times New Roman', serif;
    font-weight: 700;
    letter-spacing: 0.07em;
    background: linear-gradient(180deg, #ffe066 0%, var(--imu-gold, #d6ba66) 40%, #996515 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    filter: drop-shadow(0 0 14px rgba(212, 175, 55, 0.35));
}

.leaderboard-table {
    width: 100%;
    max-width: 600px;
    margin: 0 auto;
    border-collapse: collapse;
}

.leaderboard-table th,
.leaderboard-table td {
    padding: 0.75rem 1rem;
    text-align: left;
}

.leaderboard-table th {
    color: #FFD700;
    font-weight: 600;
    border-bottom: 1px solid rgba(255, 215, 0, 0.3);
}

.leaderboard-table td {
    color: var(--text-primary, #fff);
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.leaderboard-table tr.current-user {
    background: rgba(255, 215, 0, 0.1);
}

.rank-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    font-weight: bold;
    font-size: 0.9rem;
}

.rank-1 { background: linear-gradient(135deg, #FFD700, #FFA500); color: #000; }
.rank-2 { background: linear-gradient(135deg, #C0C0C0, #A0A0A0); color: #000; }
.rank-3 { background: linear-gradient(135deg, #CD7F32, #A0522D); color: #fff; }

/* Debug panel */
.debug-panel {
    background: rgba(0,0,0,0.8);
    color: #0f0;
    font-family: monospace;
    font-size: 11px;
    padding: 10px;
    margin-bottom: 20px;
    border-radius: 8px;
    max-height: 200px;
    overflow-y: auto;
}

/* ============================================
   MOBILE STYLES
   ============================================ */
@media (max-width: 768px) {
    .marketplace-header-nav {
        padding: 0.75rem 1rem;
        gap: 0.5rem;
    }
    
    .marketplace-header-nav .nav-link {
        padding: 0.4rem 0.75rem;
        font-size: 0.8rem;
    }
    
    .tasks-page { 
        padding: 1rem; 
    }
    
    .tasks-header h1 { 
        font-size: 1.75rem; 
    }
    
    /* Stats - Side by Side on Mobile */
    /* Order: Available + Completed side-by-side, Total Points below */
    .tasks-stats {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.75rem;
        margin-bottom: 1.5rem;
        max-width: 360px;
        margin-left: auto;
        margin-right: auto;
    }
    
    .stat-card {
        padding: 1rem;
        min-width: unset;
        text-align: center;
    }
    
    /* Total Points (now third) spans full width */
    .stat-card:nth-child(3) {
        grid-column: 1 / -1;
    }
    
    .stat-value {
        font-size: 1.5rem;
    }
    
    .stat-label {
        font-size: 0.8rem;
    }
    
    /* Hide category buttons on mobile, show dropdown */
    .tasks-categories {
        display: none !important;
    }
    
    .category-dropdown-wrapper {
        display: block !important;
        max-width: 360px;
        margin: 0 auto 1.5rem auto;
    }
    
    /* Tasks grid single column on mobile - CENTERED */
    .tasks-grid { 
        grid-template-columns: 1fr;
        max-width: 380px;
        margin-left: auto;
        margin-right: auto;
    }
    
    .task-card {
        padding: 1.25rem;
    }
    
    .task-icon {
        width: 45px;
        height: 45px;
        font-size: 1.75rem;
    }
    
    .task-info h3 {
        font-size: 1rem;
    }
    
    .task-description {
        font-size: 0.85rem;
    }
    
    .task-points {
        font-size: 1.1rem;
    }
    
    .claim-btn {
        padding: 0.5rem 1rem;
        font-size: 0.9rem;
    }
    
    .leaderboard-table th,
    .leaderboard-table td {
        padding: 0.5rem 0.75rem;
        font-size: 0.85rem;
    }
}

/* Small mobile */
@media (max-width: 400px) {
    .tasks-stats {
        gap: 0.5rem;
    }
    
    .stat-card {
        padding: 0.75rem;
    }
    
    .stat-value {
        font-size: 1.25rem;
    }
    
    .stat-label {
        font-size: 0.7rem;
    }
}
</style>

<div class="tasks-page-container">
    <!-- Marketplace Navigation Header -->
    <nav class="marketplace-header-nav">
        <a href="<?php echo esc_url(home_url('/trading-hub/')); ?>" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
            Trading Hub
        </a>
        <a href="<?php echo esc_url(home_url('/collections/')); ?>" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
            Collections
        </a>
        <a href="<?php echo esc_url(home_url('/mint/')); ?>" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"></line></svg>
            Mint NFT
        </a>
        <a href="<?php echo esc_url(home_url('/tasks/')); ?>" class="nav-link active">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            Tasks
        </a>
        <a href="<?php echo esc_url(home_url('/redeem/')); ?>" class="nav-link">
            🎁 Redeem
        </a>
        <a href="<?php echo esc_url(home_url('/burn-to-earn/')); ?>" class="nav-link">🔥 Burn to Earn</a>
        <a href="<?php echo esc_url(home_url('/trading-hub-dashboard/')); ?>" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
            Dashboard
        </a>
        <a href="<?php echo esc_url(home_url('/my-nfts/')); ?>" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg>
            My NFTs
        </a>
    </nav>

<div class="tasks-page">
    <div class="tasks-header">
        <h1>🎯 Tasks & Rewards</h1>
        <p>Complete tasks to earn points and climb the leaderboard!</p>
    </div>

    <!-- Debug panel (toggle with ?debug=1) -->
    <?php if (isset($_GET['debug'])): ?>
    <div class="debug-panel" id="debug-panel">
        <strong>Debug Info:</strong><br>
        User Account: <?php echo esc_html($user_account ?: '(not logged in)'); ?><br>
        Nonce: <?php echo esc_html(substr($nonce, 0, 10)); ?>...<br>
        <div id="debug-log"></div>
    </div>
    <?php endif; ?>

    <?php if (empty($user_account)): ?>
    <div class="login-prompt">
        <h2>🔐 Connect Your Wallet</h2>
        <p>Log in with Xaman to view your tasks and earn rewards.</p>
        <button class="login-btn" onclick="document.querySelector('#xaman-login-btn')?.click(); document.querySelector('.xaman-login-btn')?.click();">
            Connect with Xaman
        </button>
    </div>
    <?php else: ?>

    <div class="tasks-stats">
                <div class="stat-card">
            <div class="stat-value" id="total-points">-</div>
            <div class="stat-label">Total Points</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" id="available-count">-</div>
            <div class="stat-label">Available</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" id="completed-count">-</div>
            <div class="stat-label">Completed</div>
        </div>
    </div>

    <!-- Desktop: Category Buttons -->
    <div class="tasks-categories">
        <button class="category-btn active" data-category="all">All Tasks</button>
        <button class="category-btn" data-category="daily">Daily</button>
        <button class="category-btn" data-category="weekly">Weekly</button>
        <button class="category-btn" data-category="monthly">Monthly</button>
        <button class="category-btn" data-category="achievement">Achievements</button>
    </div>
    
    <!-- Mobile: Category Dropdown -->
    <div class="category-dropdown-wrapper">
        <select class="category-dropdown" id="category-select">
            <option value="all">📋 All Tasks</option>
            <option value="daily">📅 Daily Tasks</option>
            <option value="weekly">📆 Weekly Tasks</option>
            <option value="monthly">🗓️ Monthly Tasks</option>
            <option value="achievement">🏆 Achievements</option>
        </select>
    </div>

    <div class="tasks-grid" id="tasks-grid">
        <div class="tasks-loading">
            <div class="spinner"></div>
            <p>Loading tasks...</p>
        </div>
    </div>

    <div class="leaderboard-section">
        <h2>🏆 Leaderboard</h2>
        <table class="leaderboard-table" id="leaderboard-table">
            <thead>
                <tr>
                    <th>Rank</th>
                    <th>User</th>
                    <th>Points</th>
                </tr>
            </thead>
            <tbody id="leaderboard-body">
                <tr><td colspan="3" style="text-align: center; color: #888;">Loading...</td></tr>
            </tbody>
        </table>
    </div>

    <?php endif; ?>
</div>
</div><!-- .tasks-page-container -->

<?php if (!empty($user_account)): ?>
<script>
(function() {
    const userAccount = '<?php echo esc_js($user_account); ?>';
    const nonce = '<?php echo esc_js($nonce); ?>';
    const backendUrl = '/wp-content/themes/astra/xrpl-nft-marketplace/backend/task-handler.php';
    
    let allTasks = [];
    let currentCategory = 'all';

    function debugLog(msg) {
        console.log('[Tasks]', msg);
        const debugEl = document.getElementById('debug-log');
        if (debugEl) {
            debugEl.innerHTML += msg + '<br>';
        }
    }

    debugLog('Initializing tasks page');
    debugLog('User account: ' + userAccount);
    debugLog('Backend URL: ' + backendUrl);

    // Load tasks
    async function loadTasks() {
        const url = `${backendUrl}?action=list_tasks&account=${encodeURIComponent(userAccount)}&nonce=${encodeURIComponent(nonce)}`;
        debugLog('Fetching: ' + url);
        
        try {
            const res = await fetch(url);
            const text = await res.text();
            debugLog('Raw response: ' + text.substring(0, 200) + '...');
            
            let data;
            try {
                data = JSON.parse(text);
            } catch (parseErr) {
                debugLog('JSON parse error: ' + parseErr.message);
                document.getElementById('tasks-grid').innerHTML = `<p style="text-align:center;color:#f44;">Invalid JSON response from server</p>`;
                return;
            }
            
            debugLog('Parsed response: success=' + data.success);
            
            if (data.success && data.data) {
                allTasks = data.data.tasks || [];
                debugLog('Loaded ' + allTasks.length + ' tasks');
                updateStats(data.data);
                renderTasks();
            } else {
                const error = data.data?.error || data.message || 'Failed to load tasks';
                debugLog('Error: ' + error);
                document.getElementById('tasks-grid').innerHTML = `<p style="text-align:center;color:#f44;">Error: ${escapeHtml(error)}</p>`;
            }
        } catch (err) {
            debugLog('Fetch error: ' + err.message);
            console.error('Load tasks error:', err);
            document.getElementById('tasks-grid').innerHTML = `<p style="text-align:center;color:#f44;">Failed to connect to server: ${escapeHtml(err.message)}</p>`;
        }
    }

    // Update stats
    function updateStats(data) {
        const totalPoints = data.total_points || 0;
        document.getElementById('total-points').textContent = totalPoints.toLocaleString();
        const completed = allTasks.filter(t => t.completed).length;
        const available = allTasks.filter(t => t.can_claim).length;
        document.getElementById('completed-count').textContent = completed;
        document.getElementById('available-count').textContent = available;
        debugLog('Stats: ' + totalPoints + ' points, ' + completed + ' completed, ' + available + ' available');
    }

    // Render tasks
    function renderTasks() {
        const grid = document.getElementById('tasks-grid');
        let filtered = allTasks;
        
        if (currentCategory !== 'all') {
            if (currentCategory === 'achievement') {
                filtered = allTasks.filter(t => t.type === 'onetime');
            } else {
                filtered = allTasks.filter(t => t.type === currentCategory);
            }
        }

        if (filtered.length === 0) {
            grid.innerHTML = '<p style="text-align:center;color:#888;grid-column:1/-1;">No tasks in this category</p>';
            return;
        }

        grid.innerHTML = filtered.map(task => {
            let limitInfo = '';
            if (task.max_per_day) {
                limitInfo = `<span class="task-limit">Max ${task.max_per_day}/day</span>`;
            } else if (task.max_per_week === null && task.type === 'weekly') {
                limitInfo = `<span class="task-limit unlimited">Unlimited</span>`;
            } else if (task.max_per_week) {
                limitInfo = `<span class="task-limit">Max ${task.max_per_week}/week</span>`;
            }
            
            return `
            <div class="task-card ${task.completed ? 'completed' : ''}">
                <div class="task-header">
                    <div class="task-icon">${task.icon || '🎯'}</div>
                    <div class="task-info">
                        <h3>${escapeHtml(task.name)}</h3>
                        <div class="task-type">${task.type} ${limitInfo}</div>
                    </div>
                </div>
                <div class="task-description">${escapeHtml(task.description)}</div>
                <div class="task-footer">
                    <div class="task-points">${task.points} <span>points</span></div>
                    ${task.completed 
                        ? '<button class="claim-btn completed" disabled>✓ Completed</button>'
                        : task.can_claim
                            ? `<button class="claim-btn" onclick="claimTask('${task.id}', this)">Claim</button>`
                            : `<button class="claim-btn" disabled title="${task.requires_verification ? 'Complete the required action first' : 'Locked'}">${task.requires_verification ? 'Requirements not met' : 'Locked'}</button>`
                    }
                </div>
            </div>
        `}).join('');
    }

    // Claim task
    window.claimTask = async function(taskId, btn) {
        debugLog('Claiming task: ' + taskId);
        btn.disabled = true;
        btn.textContent = 'Claiming...';

        try {
            const formData = new FormData();
            formData.append('action', 'complete_task');
            formData.append('account', userAccount);
            formData.append('task_id', taskId);
            formData.append('nonce', nonce);

            debugLog('Posting to: ' + backendUrl);
            
            const res = await fetch(backendUrl, {
                method: 'POST',
                body: formData
            });
            
            const text = await res.text();
            debugLog('Claim response: ' + text.substring(0, 200));
            
            let data;
            try {
                data = JSON.parse(text);
            } catch (parseErr) {
                debugLog('JSON parse error on claim: ' + parseErr.message);
                showToast('Invalid server response', 'error');
                btn.disabled = false;
                btn.textContent = 'Claim';
                return;
            }

            if (data.success && data.data) {
                const d = data.data;
                showToast(`🎉 ${d.task_name || taskId} completed! +${d.points_earned || 0} points`, 'success');
                debugLog('Task claimed successfully!');
                await loadTasks();
                loadLeaderboard();
            } else {
                const error = data.data?.error || 'Failed to claim task';
                debugLog('Claim error: ' + error);
                showToast(error, 'error');
                btn.disabled = false;
                btn.textContent = 'Claim';
            }
        } catch (err) {
            debugLog('Claim fetch error: ' + err.message);
            console.error('Claim error:', err);
            showToast('Failed to claim task: ' + err.message, 'error');
            btn.disabled = false;
            btn.textContent = 'Claim';
        }
    };

    // Load leaderboard
    async function loadLeaderboard() {
        try {
            const res = await fetch(`${backendUrl}?action=leaderboard&limit=10&nonce=${encodeURIComponent(nonce)}`);
            const text = await res.text();
            
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('Leaderboard parse error:', e);
                return;
            }
            
            if (data.success && data.data?.leaderboard?.length) {
                const tbody = document.getElementById('leaderboard-body');
                tbody.innerHTML = data.data.leaderboard.map((entry, i) => {
                    const rank = i + 1;
                    const isCurrentUser = entry.account === userAccount;
                    const rankClass = rank <= 3 ? `rank-${rank}` : '';
                    return `
                        <tr class="${isCurrentUser ? 'current-user' : ''}">
                            <td><span class="rank-badge ${rankClass}">${rank}</span></td>
                            <td>${escapeHtml(entry.display_name || entry.account_short || 'Anonymous')}</td>
                            <td>${parseInt(entry.total_points || 0).toLocaleString()}</td>
                        </tr>
                    `;
                }).join('');
            } else if (data.success) {
                document.getElementById('leaderboard-body').innerHTML = '<tr><td colspan="3" style="text-align:center;color:#888;">No entries yet</td></tr>';
            }
        } catch (err) {
            console.error('Leaderboard error:', err);
        }
    }

    // Category buttons (desktop)
    document.querySelectorAll('.category-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.category-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            currentCategory = btn.dataset.category;
            
            // Sync dropdown with buttons
            const dropdown = document.getElementById('category-select');
            if (dropdown) dropdown.value = currentCategory;
            
            renderTasks();
        });
    });
    
    // Category dropdown (mobile)
    const categorySelect = document.getElementById('category-select');
    if (categorySelect) {
        categorySelect.addEventListener('change', (e) => {
            currentCategory = e.target.value;
            
            // Sync buttons with dropdown
            document.querySelectorAll('.category-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.category === currentCategory);
            });
            
            renderTasks();
        });
    }

    // Toast notification
    function showToast(message, type = 'info') {
        document.querySelectorAll('.tasks-toast').forEach(t => t.remove());
        
        const toast = document.createElement('div');
        toast.className = 'tasks-toast';
        toast.style.cssText = `
            position: fixed; bottom: 20px; right: 20px; z-index: 100000;
            padding: 1rem 1.5rem; border-radius: 8px; max-width: 350px;
            background: ${type === 'success' ? '#4CAF50' : type === 'error' ? '#f44336' : '#333'};
            color: white; font-weight: 500; box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        `;
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 5000);
    }

    // Escape HTML
    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Init
    loadTasks();
    loadLeaderboard();
})();
</script>

<style>
/* Task limit badge */
.task-limit {
    display: inline-block;
    font-size: 0.65rem;
    padding: 0.15rem 0.4rem;
    background: rgba(255, 215, 0, 0.2);
    border-radius: 4px;
    margin-left: 0.5rem;
    vertical-align: middle;
}

.task-limit.unlimited {
    background: rgba(76, 175, 80, 0.2);
    color: #4CAF50;
}
</style>
<?php endif; ?>

<?php get_footer(); ?>