<?php
/**
 * Template Name: Frequency Fountain
 * 
 * v2.0 POLISHED VERSION
 * Changes from v1.1:
 * - Removed NFT Holdings / IMUTV Revenue Share dividers
 * - Refresh button moved inside balances section
 * - CSS override handles: 2-column grid, black/gold buttons, smaller fountain
 * 
 * FIXED VERSION - Uses correct xumm-proxy.php API endpoint
 * 
 * Standalone page for XFT rewards claiming with animated fountain
 * Features:
 * - Elegant golden fountain with animated water
 * - Golden coins stacked around fountain
 * - Three states: Not logged in, Not eligible, Eligible
 * - Claim buttons, balance display, cooldown timer
 * - Triggers existing frequency-fountain.js visualizer on claim
 * 
 * @package IMCollectibles
 */

get_header();

// Check login status from cookie
$account = function_exists('imc_session_wallet') ? imc_session_wallet() : '';
$is_logged_in = !empty($account) && preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account);

// Initialize all variables
$xft_balance = 0;
$guardians = 0;
$protectors_freq = 0;
$protectors_ledger = 0;
$protectors_lasVegas = 0;
$protectors_firepit = 0;
$rewards = 0;
$can_claim = false;
$time_left = 0;
$is_eligible = false;
$total_nfts = 0;
$lasvegas_boost = 1;
$total_share = 0;

// Debug mode - set to true to see diagnostic info
$debug_mode = isset($_GET['debug']) && $_GET['debug'] === '1';

if ($is_logged_in) {
    global $wpdb;
    
    // =================================================================
    // FETCH XFT BALANCE - Using XRPL direct API (same as profile page)
    // =================================================================
    $xft_issuer = 'rGpnoqYLzWytxwQhhz715nRbqyCHM7zhxt';
    $xft_transient_key = 'xaman_xft_' . md5($account);
    $xft_balance = get_transient($xft_transient_key);
    
    if ($xft_balance === false || isset($_GET['refresh_balances'])) {
        // Fetch from XRPL API (s1.ripple.com) - same method as handle_xft_claim
        $xrpl_api_url = "https://s1.ripple.com:51234/";
        $request = [
            'method' => 'account_lines',
            'params' => [['account' => $account, 'ledger_index' => 'current']]
        ];
        
        $response = wp_remote_post($xrpl_api_url, [
            'body' => json_encode($request),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 20
        ]);
        
        $xft_balance = 0;
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($body['result']['lines']) && is_array($body['result']['lines'])) {
                foreach ($body['result']['lines'] as $line) {
                    if (isset($line['account']) && $line['account'] === $xft_issuer && 
                        isset($line['currency']) && $line['currency'] === 'XFT') {
                        $xft_balance = floatval($line['balance']);
                        break;
                    }
                }
            }
            set_transient($xft_transient_key, $xft_balance, 300);
        }
    }
    
    // =================================================================
    // FETCH NFTs - Using xumm-proxy.php (SAME as profile page)
    // =================================================================
    $nft_transient_key = 'xaman_nft_' . md5($account); // NOTE: 'xaman_nft_' not 'xaman_nfts_'
    $nfts = get_transient($nft_transient_key);
    
    if ($nfts === false || isset($_GET['refresh_balances'])) {
        delete_transient($nft_transient_key);
        
        // Use xumm-proxy.php - counts_only mode skips metadata fetch (10-20s → 2-3s)
        $proxy_url = home_url('/xumm-proxy.php') . "?account={$account}&counts_only=true&t=" . time();
        if (isset($_GET['refresh_balances'])) {
            $proxy_url .= '&force_check=true';
        }
        
        $response = wp_remote_get($proxy_url, ['timeout' => 30]); // counts_only is fast
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $nfts = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($nfts['result'])) {
                set_transient($nft_transient_key, $nfts, HOUR_IN_SECONDS);
            }
        }
    }
    
    // =================================================================
    // COUNT ELIGIBLE NFTs
    // =================================================================
    // First check if proxy already returned counts (optimized path)
    if (isset($nfts['result']['guardians'])) {
        // Use pre-calculated counts from proxy
        if (function_exists('get_nft_counts_from_proxy')) {
            $proxy_counts = get_nft_counts_from_proxy($nfts);
            $guardians = $proxy_counts['guardians'];
            $protectors_freq = $proxy_counts['protectors_freq'];
            $protectors_ledger = $proxy_counts['protectors_ledger'];
            $protectors_lasVegas = $proxy_counts['protectors_lasVegas'];
            $protectors_firepit = $proxy_counts['protectors_firepit'];
        } else {
            // Manual extraction
            $guardians = intval($nfts['result']['guardians'] ?? 0);
            $protectors_freq = intval($nfts['result']['protectors_freq'] ?? 0);
            $protectors_ledger = intval($nfts['result']['protectors_ledger'] ?? 0);
            $protectors_lasVegas = intval($nfts['result']['protectors_lasVegas'] ?? 0);
            $protectors_firepit = intval($nfts['result']['protectors_firepit'] ?? 0);
        }
    } elseif (isset($nfts['result']['account_nfts']) && function_exists('filter_eligible_nfts')) {
        // Fallback: calculate locally using filter_eligible_nfts
        $filtered = filter_eligible_nfts($nfts['result']['account_nfts']);
        $guardians = $filtered['counts']['guardians'];
        $protectors_freq = $filtered['counts']['protectors_freq'];
        $protectors_ledger = $filtered['counts']['protectors_ledger'];
        $protectors_lasVegas = $filtered['counts']['protectors_lasVegas'];
        $protectors_firepit = $filtered['counts']['protectors_firepit'];
    }
    
    $total_nfts = $guardians + $protectors_freq + $protectors_ledger + $protectors_lasVegas + $protectors_firepit;
    
    // =================================================================
    // CALCULATE REWARDS
    // =================================================================
    if (function_exists('calculate_xft_rewards')) {
        $rewards = calculate_xft_rewards($guardians, $protectors_freq, $protectors_ledger, $protectors_lasVegas, $protectors_firepit, $xft_balance);
    } else {
        // Fallback calculation (60% reduced rates — matches calculate_xft_rewards)
        $protector_total = $protectors_freq + $protectors_ledger;
        $nft_rewards = ($protector_total * 20) + ($guardians * 200) + ($protectors_lasVegas * 80) + ($protectors_firepit * 220);
        $xft_rewards = 0;
        if ($xft_balance >= 1000000) $xft_rewards = 240;
        elseif ($xft_balance >= 500000) $xft_rewards = 100;
        elseif ($xft_balance >= 100000) $xft_rewards = 20;
        $rewards = $nft_rewards + $xft_rewards;
    }
    
    // =================================================================
    // CHECK ELIGIBILITY - Must own NFTs OR have 100k+ XFT
    // =================================================================
    $is_eligible = ($total_nfts > 0) || ($xft_balance >= 100000);
    
    // =================================================================
    // =================================================================
    // CALCULATE IMUTV BOOST AND SHARE
    // Rates confirmed (v111):
    //   POTF = 0.00025 | POTL = 0.00025 | GOTF = 0.00293 | Firepit = 0.0025
    //   POLV = +1% boost per NFT to all owned PROTECTOR NFTs only
    // =================================================================
    
    // ── Tier Boost: POTF + POTL (combined count) ──
    $prot_count = $protectors_freq + $protectors_ledger;
    if ($prot_count >= 250)     $prot_tier_boost = 0.25;
    elseif ($prot_count >= 100) $prot_tier_boost = 0.20;
    elseif ($prot_count >= 50)  $prot_tier_boost = 0.15;
    elseif ($prot_count >= 25)  $prot_tier_boost = 0.10;
    elseif ($prot_count >= 10)  $prot_tier_boost = 0.05;
    else                        $prot_tier_boost = 0;
    
    // ── Tier Boost: GOTF + Firepit (combined count) ──
    $guard_count = $guardians + $protectors_firepit;
    if ($guard_count >= 25)     $guard_tier_boost = 0.25;
    elseif ($guard_count >= 20) $guard_tier_boost = 0.20;
    elseif ($guard_count >= 15) $guard_tier_boost = 0.15;
    elseif ($guard_count >= 10) $guard_tier_boost = 0.10;
    elseif ($guard_count >= 5)  $guard_tier_boost = 0.05;
    else                        $guard_tier_boost = 0;
    
    // ── POLV: +1% per NFT to PROTECTOR NFTs only (not GOTF/Firepit) ──
    $lv_boost = $protectors_lasVegas * 0.01;
    
    // ── Share calculation ──
    // Protectors get: tier boost + LV boost
    $base_share    = $prot_count          * 0.00025  * (1 + $prot_tier_boost + $lv_boost);
    // Guardians/Firepit get: tier boost only (NO LV boost)
    $guardian_share = $guardians           * 0.00293  * (1 + $guard_tier_boost);
    $firepit_share = $protectors_firepit   * 0.0025   * (1 + $guard_tier_boost);
    $total_share   = $base_share + $guardian_share + $firepit_share;
    
    // For display: LV boost percentage shown to user
    $lasvegas_boost = 1 + $lv_boost;
    
    // =================================================================
    // CHECK CLAIM COOLDOWN STATUS
    // =================================================================
    $claim_table = $wpdb->prefix . 'xft_claims';
    $last_claim = $wpdb->get_var($wpdb->prepare(
        "SELECT last_claim FROM $claim_table WHERE xrpl_account = %s",
        $account
    ));
    
    if ($last_claim) {
        $time_left = (24 * 3600) - (current_time('timestamp', 1) - strtotime($last_claim));
        $can_claim = $time_left <= 0;
        if ($time_left < 0) $time_left = 0;
    } else {
        $can_claim = true;
        $time_left = 0;
    }
}

// Enqueue the frequency-fountain visualizer scripts (for claim animation)
wp_enqueue_style('frequency-fountain-css', get_template_directory_uri() . '/css/frequency-fountain.css', [], filemtime(get_template_directory() . '/css/frequency-fountain.css'));
wp_enqueue_script('confetti', 'https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js', [], '1.6.0', true);
wp_enqueue_script('frequency-fountain-js', get_template_directory_uri() . '/js/frequency-fountain.js', ['confetti'], filemtime(get_template_directory() . '/js/frequency-fountain.js'), true);
?>

<link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/css/frequency-fountain-page.css?v=<?php echo time(); ?>">

<?php if ($debug_mode): ?>
<!-- DEBUG INFO -->
<div style="background:#333;color:#0f0;padding:20px;margin:20px;font-family:monospace;font-size:12px;border-radius:10px;">
    <h3 style="color:#ff0;">🔧 DEBUG INFO</h3>
    <p><strong>Account:</strong> <?php echo esc_html($account); ?></p>
    <p><strong>Is Logged In:</strong> <?php echo $is_logged_in ? 'YES' : 'NO'; ?></p>
    <p><strong>Is Eligible:</strong> <?php echo $is_eligible ? 'YES' : 'NO'; ?></p>
    <p><strong>XFT Balance:</strong> <?php echo number_format($xft_balance, 2); ?></p>
    <p><strong>Total NFTs:</strong> <?php echo $total_nfts; ?></p>
    <p><strong>Guardians:</strong> <?php echo $guardians; ?></p>
    <p><strong>Freq Protectors:</strong> <?php echo $protectors_freq; ?></p>
    <p><strong>Ledger Protectors:</strong> <?php echo $protectors_ledger; ?></p>
    <p><strong>Vegas Protectors:</strong> <?php echo $protectors_lasVegas; ?></p>
    <p><strong>Firepit Protectors:</strong> <?php echo $protectors_firepit; ?></p>
    <p><strong>Daily Rewards:</strong> <?php echo $rewards; ?> XFT</p>
    <p><strong>Can Claim:</strong> <?php echo $can_claim ? 'YES' : 'NO'; ?></p>
    <p><strong>Time Left:</strong> <?php echo gmdate("H:i:s", max(0, $time_left)); ?></p>
</div>
<?php endif; ?>

<div class="ffp-page">
    <!-- Animated Background -->
    <div class="ffp-background">
        <div class="ffp-gradient-overlay"></div>
        <div class="ffp-particles" id="ffp-particles"></div>
        <div class="ffp-glow-orb ffp-glow-orb-1"></div>
        <div class="ffp-glow-orb ffp-glow-orb-2"></div>
        <div class="ffp-glow-orb ffp-glow-orb-3"></div>
    </div>
    
    <!-- Main Content Container - CENTERED -->
    <div class="ffp-container">
        
        <!-- Header Section -->
        <div class="ffp-header">
            <h1 class="ffp-title">
                <span class="ffp-title-icon">⛲</span>
                Frequency Fountain
            </h1>
            <p class="ffp-subtitle">Daily Rewards for Protectors of IMU</p>
        </div>
        
        <!-- Fountain Display -->
        <div class="ffp-fountain-section">
            <div class="ffp-fountain-wrapper">
                <!-- Premium SVG Fountain -->
                <div class="ffp-fountain <?php echo $is_logged_in && $is_eligible ? 'ffp-fountain-active' : ''; ?>" id="ffp-fountain">
                    <svg viewBox="0 0 400 500" class="ffp-fountain-svg">
                        <defs>
                            <!-- Stone Gradients -->
                            <linearGradient id="ffpMarbleGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" style="stop-color:#9e9895"/>
                                <stop offset="25%" style="stop-color:#7d7a77"/>
                                <stop offset="50%" style="stop-color:#5e5b58"/>
                                <stop offset="75%" style="stop-color:#4a4745"/>
                                <stop offset="100%" style="stop-color:#3a3735"/>
                            </linearGradient>
                            <linearGradient id="ffpMarbleLight" x1="0%" y1="0%" x2="0%" y2="100%">
                                <stop offset="0%" style="stop-color:#b5b0ab"/>
                                <stop offset="100%" style="stop-color:#6d6a67"/>
                            </linearGradient>
                            <!-- Gold Gradients -->
                            <linearGradient id="ffpGoldRich" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" style="stop-color:#fff6b5"/>
                                <stop offset="25%" style="stop-color:#f4d03f"/>
                                <stop offset="50%" style="stop-color:#d4af37"/>
                                <stop offset="75%" style="stop-color:#b8860b"/>
                                <stop offset="100%" style="stop-color:#996515"/>
                            </linearGradient>
                            <linearGradient id="ffpGoldShine" x1="0%" y1="0%" x2="100%" y2="0%">
                                <stop offset="0%" style="stop-color:#fff9db"/>
                                <stop offset="30%" style="stop-color:#ffd700"/>
                                <stop offset="50%" style="stop-color:#ffec8b"/>
                                <stop offset="70%" style="stop-color:#ffd700"/>
                                <stop offset="100%" style="stop-color:#fff9db"/>
                            </linearGradient>
                            <!-- Water Gradient -->
                            <linearGradient id="ffpWaterBlue" x1="0%" y1="0%" x2="0%" y2="100%">
                                <stop offset="0%" style="stop-color:#87ceeb"/>
                                <stop offset="50%" style="stop-color:#5ba3c0"/>
                                <stop offset="100%" style="stop-color:#4682b4"/>
                            </linearGradient>
                            <!-- Filters -->
                            <filter id="ffpGoldGlow" x="-50%" y="-50%" width="200%" height="200%">
                                <feGaussianBlur stdDeviation="6" result="blur"/>
                                <feFlood flood-color="#ffd700" flood-opacity="0.5"/>
                                <feComposite in2="blur" operator="in"/>
                                <feMerge>
                                    <feMergeNode/>
                                    <feMergeNode in="SourceGraphic"/>
                                </feMerge>
                            </filter>
                            <filter id="ffpStoneShadow" x="-20%" y="-20%" width="140%" height="140%">
                                <feDropShadow dx="0" dy="8" stdDeviation="10" flood-opacity="0.6"/>
                            </filter>
                            <filter id="ffpInnerGlow" x="-50%" y="-50%" width="200%" height="200%">
                                <feGaussianBlur stdDeviation="3" result="blur"/>
                                <feMerge>
                                    <feMergeNode in="blur"/>
                                    <feMergeNode in="SourceGraphic"/>
                                </feMerge>
                            </filter>
                        </defs>
                        
                        <!-- ==================== BASE PLATFORM ==================== -->
                        <ellipse cx="200" cy="470" rx="180" ry="28" fill="url(#ffpMarbleGrad)" filter="url(#ffpStoneShadow)"/>
                        <ellipse cx="200" cy="465" rx="175" ry="25" fill="url(#ffpMarbleLight)"/>
                        <!-- Gold trim on platform -->
                        <ellipse cx="200" cy="468" rx="178" ry="26" fill="none" stroke="url(#ffpGoldRich)" stroke-width="3" filter="url(#ffpGoldGlow)"/>
                        
                        <!-- ==================== BOTTOM BASIN (Large) ==================== -->
                        <path d="M35 440 Q35 390 75 370 L325 370 Q365 390 365 440 Q365 470 200 475 Q35 470 35 440Z" 
                              fill="url(#ffpMarbleGrad)" filter="url(#ffpStoneShadow)"/>
                        <!-- Basin interior (water area) -->
                        <ellipse cx="200" cy="380" rx="130" ry="18" fill="url(#ffpMarbleLight)"/>
                        <ellipse cx="200" cy="385" rx="115" ry="14" fill="#2a2825" opacity="0.5"/>
                        <!-- Gold rim -->
                        <ellipse cx="200" cy="370" rx="135" ry="16" fill="none" stroke="url(#ffpGoldRich)" stroke-width="5" filter="url(#ffpGoldGlow)"/>
                        
                        <!-- ==================== MAIN COLUMN ==================== -->
                        <rect x="170" y="200" width="60" height="170" fill="url(#ffpMarbleGrad)" filter="url(#ffpStoneShadow)"/>
                        <rect x="173" y="203" width="54" height="164" fill="url(#ffpMarbleLight)"/>
                        <!-- Column decorative bands -->
                        <ellipse cx="200" cy="220" rx="35" ry="6" fill="url(#ffpGoldRich)" filter="url(#ffpGoldGlow)"/>
                        <ellipse cx="200" cy="260" rx="35" ry="6" fill="url(#ffpGoldRich)" filter="url(#ffpGoldGlow)"/>
                        <ellipse cx="200" cy="300" rx="35" ry="6" fill="url(#ffpGoldRich)" filter="url(#ffpGoldGlow)"/>
                        <ellipse cx="200" cy="340" rx="35" ry="6" fill="url(#ffpGoldRich)" filter="url(#ffpGoldGlow)"/>
                        
                        <!-- ==================== MIDDLE TIER BOWL ==================== -->
                        <path d="M110 195 Q110 165 145 150 L255 150 Q290 165 290 195 Q290 215 200 222 Q110 215 110 195Z" 
                              fill="url(#ffpMarbleGrad)" filter="url(#ffpStoneShadow)"/>
                        <ellipse cx="200" cy="158" rx="68" ry="12" fill="url(#ffpMarbleLight)"/>
                        <ellipse cx="200" cy="163" rx="55" ry="8" fill="#2a2825" opacity="0.4"/>
                        <ellipse cx="200" cy="150" rx="72" ry="11" fill="none" stroke="url(#ffpGoldRich)" stroke-width="4" filter="url(#ffpGoldGlow)"/>
                        
                        <!-- Upper Column -->
                        <rect x="180" y="75" width="40" height="75" fill="url(#ffpMarbleGrad)"/>
                        <rect x="183" y="78" width="34" height="69" fill="url(#ffpMarbleLight)"/>
                        <ellipse cx="200" cy="95" rx="25" ry="5" fill="url(#ffpGoldRich)" filter="url(#ffpGoldGlow)"/>
                        <ellipse cx="200" cy="125" rx="25" ry="5" fill="url(#ffpGoldRich)" filter="url(#ffpGoldGlow)"/>
                        
                        <!-- ==================== TOP TIER BOWL ==================== -->
                        <path d="M145 72 Q145 48 170 38 L230 38 Q255 48 255 72 Q255 88 200 95 Q145 88 145 72Z" 
                              fill="url(#ffpMarbleGrad)" filter="url(#ffpStoneShadow)"/>
                        <ellipse cx="200" cy="48" rx="40" ry="10" fill="url(#ffpMarbleLight)"/>
                        <ellipse cx="200" cy="53" rx="32" ry="6" fill="#2a2825" opacity="0.4"/>
                        <ellipse cx="200" cy="38" rx="44" ry="9" fill="none" stroke="url(#ffpGoldRich)" stroke-width="3" filter="url(#ffpGoldGlow)"/>
                        
                        <!-- ==================== GOLDEN SPOUT ==================== -->
                        <rect x="188" y="12" width="24" height="30" rx="3" fill="url(#ffpGoldRich)" filter="url(#ffpGoldGlow)"/>
                        <ellipse cx="200" cy="12" rx="14" ry="6" fill="url(#ffpGoldShine)" filter="url(#ffpInnerGlow)"/>
                        
                        <!-- ==================== XFT EMBLEM ==================== -->
                        <circle cx="200" cy="280" r="25" fill="url(#ffpGoldRich)" filter="url(#ffpGoldGlow)"/>
                        <circle cx="200" cy="280" r="20" fill="url(#ffpGoldShine)"/>
                        <text x="200" y="288" text-anchor="middle" font-family="Cinzel, serif" font-size="22" font-weight="bold" fill="#1a1510">X</text>
                        
                        <!-- ==================== DECORATIVE GEMS ==================== -->
                        <!-- Bowl gems -->
                        <circle cx="135" cy="185" r="7" fill="url(#ffpGoldRich)" filter="url(#ffpGoldGlow)"/>
                        <circle cx="265" cy="185" r="7" fill="url(#ffpGoldRich)" filter="url(#ffpGoldGlow)"/>
                        <!-- Basin gems -->
                        <circle cx="75" cy="420" r="8" fill="url(#ffpGoldRich)" filter="url(#ffpGoldGlow)"/>
                        <circle cx="325" cy="420" r="8" fill="url(#ffpGoldRich)" filter="url(#ffpGoldGlow)"/>
                        <circle cx="120" cy="430" r="6" fill="url(#ffpGoldRich)" filter="url(#ffpGoldGlow)"/>
                        <circle cx="280" cy="430" r="6" fill="url(#ffpGoldRich)" filter="url(#ffpGoldGlow)"/>
                        <!-- Top tier gems -->
                        <circle cx="160" cy="62" r="5" fill="url(#ffpGoldRich)" filter="url(#ffpGoldGlow)"/>
                        <circle cx="240" cy="62" r="5" fill="url(#ffpGoldRich)" filter="url(#ffpGoldGlow)"/>
                    </svg>
                    
                    <!-- Animated Water System -->
                    <div class="ffp-water-system">
                        <div class="ffp-water-spout"></div>
                        <div class="ffp-water-cascade-1"></div>
                        <div class="ffp-water-cascade-2"></div>
                        <div class="ffp-water-basin"></div>
                        <div class="ffp-water-droplets">
                            <span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span>
                        </div>
                    </div>
                </div>
                
                <!-- Stacked Coins Around Fountain -->
                <div class="ffp-coins-ground" id="ffp-coins-ground"></div>
            </div>
        </div>
        
        <!-- State Content -->
        <div class="ffp-state-content">
            
            <?php if (!$is_logged_in): ?>
            <!-- ========== NOT LOGGED IN STATE ========== -->
            <div class="ffp-state ffp-state-not-logged-in">
                <div class="ffp-state-icon">🔐</div>
                <h2 class="ffp-state-title">Connect Your Wallet</h2>
                <p class="ffp-state-message">Login to view eligible frequencies from the fountain!</p>
                <a href="<?php echo esc_url(home_url('/profile/')); ?>" class="ffp-btn ffp-btn-primary ffp-btn-glow">
                    <span>Connect Wallet</span>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                        <polyline points="10 17 15 12 10 7"/>
                        <line x1="15" y1="12" x2="3" y2="12"/>
                    </svg>
                </a>
            </div>
            
            <?php elseif (!$is_eligible): ?>
            <!-- ========== NOT ELIGIBLE STATE ========== -->
            <div class="ffp-state ffp-state-not-eligible">
                <div class="ffp-state-icon">🛡️</div>
                <h2 class="ffp-state-title">Become a Protector</h2>
                <p class="ffp-state-message">Purchase a Protector to claim from the Frequency Fountain!</p>
                <div class="ffp-eligibility-info">
                    <p>You need one of the following to claim rewards:</p>
                    <ul>
                        <li>🛡️ Own any Protector NFT</li>
                        <li>💎 Hold 100,000+ $XFT tokens</li>
                    </ul>
                </div>
                <a href="https://mint.imcollectibles.io/protectors" class="ffp-btn ffp-btn-primary ffp-btn-glow" target="_blank">
                    <span>Become a Protector</span>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    </svg>
                </a>
            </div>
            
            <?php else: ?>
            <!-- ========== ELIGIBLE STATE - SHOW CLAIM UI ========== -->
            <div class="ffp-state ffp-state-eligible">
                
                <!-- Claim Buttons Section -->
                <div class="ffp-claim-section">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="claim-xft-form">
                        <input type="hidden" name="action" value="handle_xft_claim">
                        <?php wp_nonce_field('xft_claim', '_wpnonce'); ?>
                        <input type="hidden" name="claim_xft" value="1">
                        <button type="submit" id="claim-xft-btn" <?php echo $can_claim ? '' : 'disabled'; ?> class="ffp-btn ffp-btn-claim <?php echo $can_claim ? 'ffp-btn-glow' : ''; ?>">
                            <span class="ffp-btn-icon">💰</span>
                            <span class="ffp-btn-text">Claim $XFT Rewards</span>
                            <?php if (!$can_claim): ?>
                                <span class="ffp-btn-badge">Recharging</span>
                            <?php endif; ?>
                        </button>
                    </form>
                    
                    <button id="claim-imutv-btn" class="ffp-btn ffp-btn-claim ffp-btn-secondary" disabled>
                        <span class="ffp-btn-icon">📺</span>
                        <span class="ffp-btn-text">Claim IMUTV AD Revenue</span>
                        <span class="ffp-btn-badge ffp-badge-coming-soon">Coming Soon</span>
                    </button>
                </div>
                
                <!-- Cooldown Timer -->
                <div class="ffp-timer-section">
                    <div id="claim-timer" class="ffp-timer" data-time-left="<?php echo esc_attr(max(0, $time_left)); ?>">
                        <?php if (!$can_claim && $time_left > 0): ?>
                            <div class="ffp-timer-container">
                                <div class="ffp-timer-icon">⏳</div>
                                <div class="ffp-timer-content">
                                    <div class="ffp-timer-label">Fountain Recharging</div>
                                    <div class="ffp-timer-countdown" id="countdown">
                                        <span class="ffp-timer-value" id="timer-hours">00</span>
                                        <span class="ffp-timer-separator">:</span>
                                        <span class="ffp-timer-value" id="timer-minutes">00</span>
                                        <span class="ffp-timer-separator">:</span>
                                        <span class="ffp-timer-value" id="timer-seconds">00</span>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="ffp-timer-ready">
                                <span class="ffp-ready-icon">✨</span>
                                <span class="ffp-ready-text">Fountain Ready!</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Balance Display Box -->
                <div class="ffp-balances-section">
                    <div class="ffp-balances-header">
                        <h3 class="ffp-balances-title">
                            <span class="ffp-balances-icon">📊</span>
                            IMU Balances
                        </h3>
                    </div>
                    
                    <!-- Refresh Balances Button (moved here in v2.0) -->
                    <div class="ffp-refresh-section">
                        <a href="<?php echo esc_url(home_url('/frequency-fountain/?refresh_balances=1')); ?>" class="ffp-refresh-btn">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M23 4v6h-6"/>
                                <path d="M1 20v-6h6"/>
                                <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
                            </svg>
                            <span>Refresh Balances</span>
                        </a>
                    </div>
                    
                    <div class="ffp-balances-grid">
                        <!-- Primary Stats - Highlighted -->
                        <div class="ffp-balance-item ffp-balance-primary">
                            <div class="ffp-balance-icon">💎</div>
                            <div class="ffp-balance-info">
                                <span class="ffp-balance-label">$XFT Balance</span>
                                <span class="ffp-balance-value"><?php echo esc_html(number_format($xft_balance, 2)); ?></span>
                            </div>
                        </div>
                        
                        <div class="ffp-balance-item ffp-balance-primary ffp-balance-rewards">
                            <div class="ffp-balance-icon">🎁</div>
                            <div class="ffp-balance-info">
                                <span class="ffp-balance-label">Daily $XFT Rewards</span>
                                <span class="ffp-balance-value ffp-value-gold"><?php echo esc_html(number_format($rewards, 2)); ?> XFT</span>
                            </div>
                        </div>
                        
                        <!-- NFT Holdings (divider removed in v2.0) -->
                        <div class="ffp-balance-item">
                            <div class="ffp-balance-icon">👑</div>
                            <div class="ffp-balance-info">
                                <span class="ffp-balance-label">Guardians of the Frequencies</span>
                                <span class="ffp-balance-value"><?php echo esc_html($guardians); ?></span>
                            </div>
                        </div>
                        
                        <div class="ffp-balance-item">
                            <div class="ffp-balance-icon">🔥</div>
                            <div class="ffp-balance-info">
                                <span class="ffp-balance-label">Firepit Protectors</span>
                                <span class="ffp-balance-value"><?php echo esc_html($protectors_firepit); ?></span>
                            </div>
                        </div>
                        
                        <div class="ffp-balance-item">
                            <div class="ffp-balance-icon">🎰</div>
                            <div class="ffp-balance-info">
                                <span class="ffp-balance-label">Protectors of Las Vegas</span>
                                <span class="ffp-balance-value"><?php echo esc_html($protectors_lasVegas); ?></span>
                            </div>
                        </div>
                        
                        <div class="ffp-balance-item">
                            <div class="ffp-balance-icon">📒</div>
                            <div class="ffp-balance-info">
                                <span class="ffp-balance-label">Protectors of the Ledger</span>
                                <span class="ffp-balance-value"><?php echo esc_html($protectors_ledger); ?></span>
                            </div>
                        </div>
                        
                        <div class="ffp-balance-item">
                            <div class="ffp-balance-icon">🎵</div>
                            <div class="ffp-balance-info">
                                <span class="ffp-balance-label">Protectors of the Frequencies</span>
                                <span class="ffp-balance-value"><?php echo esc_html($protectors_freq); ?></span>
                            </div>
                        </div>
                        
                        <!-- IMUTV Stats (divider removed in v2.0) -->
                        <div class="ffp-balance-item">
                            <div class="ffp-balance-icon">📈</div>
                            <div class="ffp-balance-info">
                                <span class="ffp-balance-label">IMUTV AD Revenue Boost</span>
                                <span class="ffp-balance-value"><?php echo esc_html(number_format(($lasvegas_boost - 1) * 100, 2)); ?>%</span>
                            </div>
                        </div>
                        
                        <div class="ffp-balance-item">
                            <div class="ffp-balance-icon">📊</div>
                            <div class="ffp-balance-info">
                                <span class="ffp-balance-label">IMUTV AD Revenue Share</span>
                                <span class="ffp-balance-value"><?php echo esc_html(number_format($total_share, 6)); ?>%</span>
                            </div>
                        </div>
                        
                        <div class="ffp-balance-item">
                            <div class="ffp-balance-icon">📺</div>
                            <div class="ffp-balance-info">
                                <span class="ffp-balance-label">IMUTV AD Revenue Amount</span>
                                <span class="ffp-balance-value ffp-coming-soon">Coming Soon</span>
                            </div>
                        </div>
                    </div>
                </div>
                
            </div>
            <?php endif; ?>
            
        </div>
        
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== Timer Countdown =====
    const timerDiv = document.getElementById('claim-timer');
    if (timerDiv) {
        let timeLeft = parseInt(timerDiv.dataset.timeLeft) || 0;
        
        if (timeLeft > 0) {
            const hoursEl = document.getElementById('timer-hours');
            const minutesEl = document.getElementById('timer-minutes');
            const secondsEl = document.getElementById('timer-seconds');
            
            function updateTimer() {
                if (timeLeft <= 0) {
                    window.location.reload();
                    return;
                }
                
                const hours = Math.floor(timeLeft / 3600);
                const minutes = Math.floor((timeLeft % 3600) / 60);
                const seconds = timeLeft % 60;
                
                if (hoursEl) hoursEl.textContent = String(hours).padStart(2, '0');
                if (minutesEl) minutesEl.textContent = String(minutes).padStart(2, '0');
                if (secondsEl) secondsEl.textContent = String(seconds).padStart(2, '0');
                
                timeLeft--;
            }
            
            updateTimer();
            setInterval(updateTimer, 1000);
        }
    }
    
    // ===== Generate Background Particles =====
    const particleContainer = document.getElementById('ffp-particles');
    if (particleContainer) {
        for (let i = 0; i < 40; i++) {
            const particle = document.createElement('div');
            particle.className = 'ffp-particle';
            particle.style.left = `${Math.random() * 100}%`;
            particle.style.top = `${Math.random() * 100}%`;
            particle.style.animationDelay = `${Math.random() * 8}s`;
            particle.style.animationDuration = `${6 + Math.random() * 6}s`;
            particleContainer.appendChild(particle);
        }
    }
    
    // ===== Generate Ground Coins =====
    const coinsContainer = document.getElementById('ffp-coins-ground');
    if (coinsContainer) {
        const coinCount = 35;
        for (let i = 0; i < coinCount; i++) {
            const coin = document.createElement('div');
            coin.className = 'ffp-ground-coin';
            
            // Position coins around the fountain base in elliptical pattern
            const angle = (i / coinCount) * Math.PI * 2;
            const radiusX = 130 + Math.random() * 70;
            const radiusY = 30 + Math.random() * 20;
            const x = Math.cos(angle) * radiusX;
            const y = Math.sin(angle) * radiusY;
            
            coin.style.setProperty('--coin-x', `${x}px`);
            coin.style.setProperty('--coin-y', `${y}px`);
            coin.style.setProperty('--coin-delay', `${i * 0.06}s`);
            coin.style.setProperty('--coin-z', Math.floor(Math.random() * 5));
            
            coinsContainer.appendChild(coin);
        }
    }
    
    // ===== Refresh Button Spinner =====
    const refreshBtn = document.querySelector('.ffp-refresh-btn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function() {
            this.classList.add('ffp-refreshing');
        });
    }
    
    // ===== Claim Form Handler =====
    // NOTE: frequency-fountain.js handles the claim form submission.
    // It intercepts the submit via preventDefault(), shows the fountain 
    // animation overlay, and makes an AJAX request.
    // DO NOT add another submit handler here - it causes the form to 
    // navigate away before the animation can show!
});
</script>

<?php get_footer(); ?>