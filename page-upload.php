<?php
/**
 * Template Name: Upload (IMUTV)
 * Slug target: /upload
 */
if ( ! defined('ABSPATH') ) exit;
get_header();

$current_user  = wp_get_current_user();
$prefill_email = ( $current_user && $current_user->exists() ) ? $current_user->user_email : '';
$sent          = isset($_GET['sent']) ? sanitize_text_field($_GET['sent']) : '';
$type          = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : '';
$error_reason  = isset($_GET['reason']) ? sanitize_text_field($_GET['reason']) : '';

/**
 * Pre-fill data from last submission for logged-in users
 */
$prefill_music = [];
$prefill_creator = [];
$prefill_streamer = [];
if ($current_user && $current_user->exists()) {
    global $wpdb;
    
    // Get last music submission
    $music_table = $wpdb->prefix . 'imu_music_submissions';
    if ($wpdb->get_var("SHOW TABLES LIKE '$music_table'") === $music_table) {
        $prefill_music = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $music_table WHERE email = %s ORDER BY submitted_at DESC LIMIT 1",
            $prefill_email
        ), ARRAY_A) ?: [];
    }
    
    // Get last creator submission
    $creator_table = $wpdb->prefix . 'imu_creator_submissions';
    if ($wpdb->get_var("SHOW TABLES LIKE '$creator_table'") === $creator_table) {
        $prefill_creator = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $creator_table WHERE email = %s ORDER BY submitted_at DESC LIMIT 1",
            $prefill_email
        ), ARRAY_A) ?: [];
    }
    
    // Get last streamer submission
    $streamer_table = $wpdb->prefix . 'imu_streamer_submissions';
    if ($wpdb->get_var("SHOW TABLES LIKE '$streamer_table'") === $streamer_table) {
        $prefill_streamer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $streamer_table WHERE email = %s ORDER BY submitted_at DESC LIMIT 1",
            $prefill_email
        ), ARRAY_A) ?: [];
    }
}

// Helper to safely get prefill value
function imu_prefill($data, $key, $default = '') {
    return isset($data[$key]) ? esc_attr($data[$key]) : $default;
}

// Decode JSON fields from prefill data
function imu_prefill_json($data, $key) {
    if (!isset($data[$key]) || empty($data[$key])) return [];
    $decoded = json_decode($data[$key], true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Load tokens from the Token Registry for dynamic pricing
 */
$imu_all_tokens = [];
$imu_core_tickers = ['XRP', 'RLUSD', 'XFT']; // Always displayed as core tokens
if (class_exists('IMU_Token_Registry')) {
    $imu_all_tokens = IMU_Token_Registry::get_all(false); // Only enabled tokens
}
// Fallback if class not available or empty
if (empty($imu_all_tokens)) {
    $imu_all_tokens = [
        ['ticker' => 'XRP', 'issuer' => '', 'display_name' => 'XRP', 'description' => 'Native XRPL Currency', 'logo_url' => 'https://cryptologos.cc/logos/xrp-xrp-logo.svg', 'is_native' => 1, 'is_stablecoin' => 0, 'trustline_url' => '', 'default_discount' => 0],
        ['ticker' => 'RLUSD', 'issuer' => 'rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De', 'display_name' => 'Ripple USD', 'description' => 'USD stablecoin by Ripple', 'logo_url' => 'https://s2.coinmarketcap.com/static/img/coins/64x64/32677.png', 'is_native' => 0, 'is_stablecoin' => 1, 'trustline_url' => 'https://xrpl.services/?issuer=rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De&currency=524C555344000000000000000000000000000000&limit=1000000', 'default_discount' => 0],
        ['ticker' => 'XFT', 'issuer' => 'rGpnoqYLzWytxwQhhz715nRbqyCHM7zhxt', 'display_name' => 'XFT Token', 'description' => 'IMUTV Ecosystem Token', 'logo_url' => '', 'is_native' => 0, 'is_stablecoin' => 0, 'trustline_url' => 'https://xrpl.services/?issuer=rGpnoqYLzWytxwQhhz715nRbqyCHM7zhxt&currency=5846540000000000000000000000000000000000&limit=1000000000', 'default_discount' => 10],
    ];
}

// Separate core and additional tokens
$imu_core_tokens = [];
$imu_additional_tokens = [];
foreach ($imu_all_tokens as $token) {
    if (in_array($token['ticker'], $imu_core_tickers)) {
        $imu_core_tokens[$token['ticker']] = $token;
    } else {
        $imu_additional_tokens[] = $token;
    }
}
// Ensure core tokens are in order
$imu_core_tokens_ordered = [];
foreach ($imu_core_tickers as $ticker) {
    if (isset($imu_core_tokens[$ticker])) {
        $imu_core_tokens_ordered[] = $imu_core_tokens[$ticker];
    }
}

/**
 * Helper function to render a single token row
 */
function imu_render_token_row($token, $checked = true, $show_remove = false) {
    $ticker = esc_attr($token['ticker']);
    $issuer = esc_attr($token['issuer'] ?? '');
    $display_name = esc_html($token['display_name'] ?? $token['ticker']);
    $description = esc_html($token['description'] ?? '');
    $logo_url = esc_url($token['logo_url'] ?? '');
    $trustline_url = $token['trustline_url'] ?? '';
    $is_native = !empty($token['is_native']);
    $is_stablecoin = !empty($token['is_stablecoin']);
    $is_xft = ($ticker === 'XFT');
    $discount = $is_xft ? 10 : (int)($token['default_discount'] ?? 0);
    $checked_attr = $checked ? 'checked' : '';
    
    // Badge HTML
    $badge_html = '';
    if ($is_native) {
        $badge_html = '<span class="imu-token-badge" style="background:#ddf4ff;color:#0366d6;">Native</span>';
    } elseif ($is_stablecoin) {
        $badge_html = '<span class="imu-token-badge" style="background:#e6ffed;color:#22863a;">Stablecoin</span>';
    } elseif ($is_xft) {
        $badge_html = '<span class="imu-token-badge" style="background:linear-gradient(135deg,var(--imu-gold, #d6ba66),#d4af37);color:#000;font-weight:600;">IMU Token</span>';
    }
    
    // Logo HTML
    if ($is_xft && empty($logo_url)) {
        $logo_html = '<div style="width:24px;height:24px;border-radius:50%;background:linear-gradient(135deg,var(--imu-gold, #d6ba66),#d4af37);display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:bold;color:#000;">XFT</div>';
    } elseif (!empty($logo_url)) {
        $logo_html = '<img src="' . $logo_url . '" class="imu-token-logo" alt="' . $ticker . '">';
    } else {
        $logo_html = '<div style="width:24px;height:24px;border-radius:50%;background:#333;display:flex;align-items:center;justify-content:center;font-size:8px;font-weight:bold;color:#fff;">' . substr($ticker, 0, 3) . '</div>';
    }
    
    // Trustline button (only for non-native tokens)
    $trustline_html = '';
    if (!$is_native && !empty($trustline_url)) {
        $trustline_html = '<a href="' . esc_url($trustline_url) . '" target="_blank" class="imu-trustline-btn" title="Set trustline for ' . $ticker . '">🔗 Trustline</a>';
    } elseif (!$is_native) {
        $trustline_html = '<span class="imu-trustline-btn disabled" title="Trustline not configured">🔗</span>';
    }
    
    // Remove button for additional tokens
    $remove_html = '';
    if ($show_remove) {
        $remove_html = '<button type="button" class="imu-token-remove-btn" onclick="removeTokenRow(this)" title="Remove token">✕</button>';
    }
    
    ob_start();
    ?>
    <div class="imu-token-row" data-ticker="<?php echo $ticker; ?>">
        <label>
            <input type="checkbox" name="accepted_tokens[<?php echo $ticker; ?>][enabled]" value="1" <?php echo $checked_attr; ?> class="imu-token-checkbox">
            <?php echo $logo_html; ?>
            <div class="imu-token-info">
                <strong><?php echo $ticker; ?></strong>
                <span><?php echo $description ?: $display_name; ?></span>
            </div>
        </label>
        <input type="hidden" name="accepted_tokens[<?php echo $ticker; ?>][issuer]" value="<?php echo $issuer; ?>">
        <div class="imu-discount-wrap">
            <label style="font-size:12px;color:#888;min-width:auto;">Discount:</label>
            <input type="number" name="accepted_tokens[<?php echo $ticker; ?>][discount]" value="<?php echo $discount; ?>" min="0" max="99" class="imu-input imu-discount-input">
            <span style="color:#888;">%</span>
        </div>
        <?php echo $trustline_html; ?>
        <?php echo $badge_html; ?>
        <?php echo $remove_html; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Map internal status codes to user-facing messages.
 */
$upload_error_message = '';
if ($sent === 'nonce_error') {
    $upload_error_message = 'Security check failed. Please refresh this page and submit the form again.';
} elseif ($sent === 'error') {
    // Generic failure – show a human-friendly reason if provided.
    if (!empty($error_reason)) {
        $upload_error_message = 'We could not process your submission: ' . $error_reason;
    } else {
        $upload_error_message = 'We could not process your submission. Please try again in a few minutes.';
    }
}
?>


<main id="primary" class="imu-onboarding" role="main" aria-label="IMUTV Upload">
      <style>
    /* Dual input row for content file + URL */
    .imu-upload-dual-input {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      align-items: center;
    }
    .imu-upload-dual-input .imu-input {
      flex: 1 1 220px;
      min-width: 0;
    }

    /* MP3 help tooltip styling */
    .imu-help-wrapper {
      position: relative;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .imu-help-icon {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 18px;
      height: 18px;
      border-radius: 50%;
      border: 1px solid var(--imu-gold, var(--imu-gold, #d6ba66));
      font-size: 11px;
      cursor: pointer;
      line-height: 1;
    }
    .imu-help-tooltip {
      position: absolute;
      top: 120%;
      left: 0;
      z-index: 1000;
      max-width: 260px;
      padding: 8px 10px;
      border-radius: 6px;
      background: #000;
      color: var(--imu-gold, var(--imu-gold, #d6ba66));
      font-size: 0.8rem;
      display: none;
    }
    .imu-help-wrapper:hover .imu-help-tooltip,
    .imu-help-wrapper:focus-within .imu-help-tooltip {
      display: block;
    }

    /* ========== MUSIC TYPE SELECTOR ========== */
    .imu-music-type-selector {
        display: flex;
        gap: 16px;
        flex-wrap: wrap;
        margin-bottom: 24px;
    }
    .imu-music-type-card {
        flex: 1;
        min-width: 200px;
        padding: 20px;
        background: rgba(255,255,255,0.03);
        border: 2px solid rgba(255,255,255,0.1);
        border-radius: 12px;
        cursor: pointer;
        transition: all 0.2s;
        text-align: center;
    }
    .imu-music-type-card:hover {
        border-color: rgba(var(--imu-gold-rgb), 0.5);
        background: rgba(255,255,255,0.05);
    }
    .imu-music-type-card:has(input:checked) {
        border-color: var(--imu-gold, var(--imu-gold, #d6ba66));
        background: rgba(var(--imu-gold-rgb), 0.1);
    }
    .imu-music-type-card h4 {
        margin: 0 0 8px;
        color: var(--imu-gold, var(--imu-gold, #d6ba66));
    }
    .imu-music-type-card p {
        margin: 0;
        font-size: 0.9rem;
        color: #aaa;
    }
    
    /* ========== TERRITORY AUTHORIZATION ========== */
    .imu-territory-section {
        margin-top: 20px;
        padding: 16px;
        background: rgba(255,255,255,0.02);
        border: 1px solid rgba(255,255,255,0.1);
        border-radius: 8px;
    }
    .imu-territory-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 12px;
    }
    .imu-territory-header label {
        flex: 1;
    }
    .imu-exclusions-list {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 12px;
    }
    .imu-exclusion-tag {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 12px;
        background: rgba(255,100,100,0.15);
        border: 1px solid rgba(255,100,100,0.3);
        border-radius: 20px;
        font-size: 13px;
        color: #ff9999;
    }
    .imu-exclusion-tag button {
        background: none;
        border: none;
        color: #ff6b6b;
        cursor: pointer;
        font-size: 14px;
        padding: 0;
        line-height: 1;
    }
    .imu-country-dropdown {
        position: relative;
        display: inline-block;
    }
    .imu-country-dropdown-btn {
        padding: 8px 16px;
        background: rgba(255,100,100,0.1);
        border: 1px solid rgba(255,100,100,0.3);
        color: #ff9999;
        border-radius: 6px;
        cursor: pointer;
        font-size: 13px;
        transition: all 0.2s;
    }
    .imu-country-dropdown-btn:hover {
        background: rgba(255,100,100,0.2);
    }
    .imu-country-dropdown-menu {
        position: absolute;
        top: 100%;
        left: 0;
        margin-top: 4px;
        background: #1a1a1a;
        border: 1px solid rgba(255,255,255,0.15);
        border-radius: 8px;
        min-width: 250px;
        max-height: 300px;
        overflow-y: auto;
        z-index: 100;
        display: none;
        box-shadow: 0 8px 24px rgba(0,0,0,0.5);
    }
    .imu-country-dropdown.open .imu-country-dropdown-menu {
        display: block;
    }
    .imu-country-search {
        padding: 10px;
        border-bottom: 1px solid rgba(255,255,255,0.1);
    }
    .imu-country-search input {
        width: 100%;
        padding: 8px 12px;
        background: rgba(0,0,0,0.3);
        border: 1px solid rgba(255,255,255,0.1);
        border-radius: 4px;
        color: #fff;
        font-size: 13px;
    }
    .imu-country-list {
        max-height: 200px;
        overflow-y: auto;
    }
    .imu-country-item {
        padding: 10px 14px;
        cursor: pointer;
        transition: background 0.15s;
        border-bottom: 1px solid rgba(255,255,255,0.05);
    }
    .imu-country-item:hover {
        background: rgba(255,255,255,0.05);
    }
    .imu-country-item.selected {
        background: rgba(255,100,100,0.1);
        color: #ff9999;
    }

    /* ========== MUSIC IN CONTENT SECTION ========== */
    .imu-music-usage-fields {
        margin-top: 16px;
        padding: 16px;
        background: rgba(var(--imu-gold-rgb), 0.05);
        border: 1px solid rgba(var(--imu-gold-rgb), 0.2);
        border-radius: 8px;
    }
    .imu-music-type-fields {
        margin-top: 16px;
    }

    /* ========== AUTO-REGISTRATION FIELDS ========== */
    .imu-registration-wrapper {
        margin-top: 0;
    }
    .imu-registration-fields {
        background: linear-gradient(135deg, rgba(212,175,55,0.1) 0%, rgba(0,0,0,0.4) 100%);
        border: 1px solid var(--imu-gold, var(--imu-gold, #d6ba66));
        border-radius: 8px;
        padding: 16px;
        margin-top: 12px;
    }
    .imu-registration-fields h4 {
        margin: 0 0 8px;
        color: var(--imu-gold, var(--imu-gold, #d6ba66));
        font-size: 1rem;
    }
    .imu-registration-fields p {
        margin: 0 0 16px;
        color: #ccc;
        font-size: 0.9rem;
    }
    .imu-registration-fields .reg-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }
    @media (max-width: 600px) {
        .imu-registration-fields .reg-grid {
            grid-template-columns: 1fr;
        }
    }
    .imu-email-status {
        font-size: 0.85rem;
        margin-top: 8px;
        padding: 8px 12px;
        border-radius: 6px;
    }
    .imu-email-status.checking {
        color: #888;
        background: rgba(255,255,255,0.05);
    }
    .imu-email-status.exists {
        color: #4CAF50;
        background: rgba(76,175,80,0.15);
        border: 1px solid rgba(76,175,80,0.3);
    }
    .imu-email-status.new-user {
        color: var(--imu-gold, var(--imu-gold, #d6ba66));
        background: rgba(212,175,55,0.15);
        border: 1px solid rgba(212,175,55,0.3);
    }
    .imu-password-strength {
        height: 4px;
        background: #333;
        border-radius: 2px;
        margin-top: 6px;
        overflow: hidden;
    }
    .imu-password-strength-bar {
        height: 100%;
        width: 0;
        transition: width 0.3s ease, background 0.3s ease;
    }
    .imu-password-strength-bar.weak { width: 33%; background: #f44336; }
    .imu-password-strength-bar.medium { width: 66%; background: #FFC107; }
    .imu-password-strength-bar.strong { width: 100%; background: #4CAF50; }
    .imu-password-match {
        font-size: 0.8rem;
        margin-top: 6px;
    }
    .imu-password-match.match { color: #4CAF50; }
    .imu-password-match.no-match { color: #f44336; }

    /* ========== DYNAMIC PRICING STYLES ========== */
    .imu-pricing-mode-toggle {
        display: flex;
        gap: 16px;
        flex-wrap: wrap;
    }
    .imu-radio-card {
        flex: 1;
        min-width: 200px;
        padding: 16px;
        background: rgba(255,255,255,0.03);
        border: 2px solid rgba(255,255,255,0.1);
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s;
    }
    .imu-radio-card:hover {
        border-color: rgba(var(--imu-gold-rgb), 0.5);
    }
    .imu-radio-card:has(input:checked) {
        border-color: var(--imu-gold, var(--imu-gold, #d6ba66));
        background: rgba(var(--imu-gold-rgb), 0.1);
    }
    .imu-token-selector {
        background: rgba(255,255,255,0.02);
        border: 1px solid rgba(255,255,255,0.1);
        border-radius: 8px;
        padding: 16px;
    }
    .imu-token-row {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 0;
        border-bottom: 1px solid rgba(255,255,255,0.06);
        transition: background 0.2s;
        flex-wrap: wrap;
    }
    .imu-token-row:last-child {
        border-bottom: none;
    }
    .imu-token-row:hover {
        background: rgba(255,255,255,0.02);
    }
    .imu-token-row:has(.imu-token-checkbox:not(:checked)) {
        opacity: 0.5;
    }
    .imu-token-row:has(.imu-token-checkbox:not(:checked)) .imu-discount-input {
        pointer-events: none;
    }
    .imu-token-row label {
        display: flex;
        align-items: center;
        gap: 10px;
        flex: 1;
        cursor: pointer;
        min-width: 180px;
    }
    .imu-token-logo {
        width: 24px;
        height: 24px;
        border-radius: 50%;
    }
    .imu-token-info strong {
        color: #fff;
        display: block;
    }
    .imu-token-info span {
        color: #888;
        font-size: 12px;
    }
    .imu-discount-wrap {
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .imu-discount-input {
        width: 60px !important;
        padding: 6px 8px !important;
        font-size: 13px !important;
        text-align: center;
        background: rgba(0,0,0,0.3) !important;
        border-color: rgba(255,255,255,0.1) !important;
    }
    .imu-discount-input:focus {
        border-color: var(--imu-gold, var(--imu-gold, #d6ba66)) !important;
    }
    .imu-token-badge {
        padding: 3px 8px;
        border-radius: 4px;
        font-size: 10px;
        white-space: nowrap;
    }
    .imu-info-card {
        border-radius: 8px;
        padding: 16px;
        margin-bottom: 16px;
    }
    .imu-info-card h4 {
        margin: 0 0 8px;
        font-size: 14px;
    }
    .imu-info-card ul {
        margin: 0;
        padding-left: 20px;
        font-size: 13px;
        line-height: 1.6;
    }
    .imu-info-card p {
        margin: 0;
        font-size: 13px;
        line-height: 1.6;
    }
    @media (max-width: 600px) {
        .imu-token-row {
            flex-direction: column;
            align-items: flex-start;
        }
        .imu-discount-wrap {
            margin-left: 34px;
        }
    }

    /* ========== MOBILE OPTIMIZATIONS ========== */
    @media (max-width: 768px) {
        /* Music type selector - stack on mobile */
        .imu-music-type-selector {
            flex-direction: column;
        }
        .imu-music-type-card {
            min-width: 100%;
        }

        /* Country dropdown - full width button on mobile */
        .imu-country-dropdown {
            width: 100%;
        }
        .imu-country-dropdown-btn {
            width: 100%;
            justify-content: center;
        }
        /* Note: Menu positioning handled by JavaScript on mobile */

        /* Exclusion tags - better wrap on mobile */
        .imu-exclusions-list {
            gap: 6px;
        }
        .imu-exclusion-tag {
            font-size: 12px;
            padding: 5px 10px;
        }

        /* Territory section - more padding */
        .imu-territory-section {
            padding: 12px;
        }

        /* Music usage fields - stack properly */
        .imu-music-usage-fields {
            padding: 12px;
        }

        /* Radio cards - stack on mobile */
        .imu-pricing-mode-toggle {
            flex-direction: column;
        }
        .imu-radio-card {
            min-width: 100%;
        }

        /* Note: Token dropdown menu positioning handled by JavaScript on mobile */

        /* Form grid adjustments */
        .grid-2, .grid-3 {
            grid-template-columns: 1fr !important;
        }

        /* Upload dual input - stack vertically */
        .imu-upload-dual-input {
            flex-direction: column;
        }
        .imu-upload-dual-input .imu-input {
            flex: 1 1 100%;
        }

        /* NFT gate row - stack vertically */
        .nft-gate-row.grid-3 {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        /* Custom token row - stack vertically */
        .custom-token-row.grid-3 {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        /* Help tooltip - better mobile positioning */
        .imu-help-tooltip {
            left: -10px;
            right: -10px;
            max-width: none;
            width: auto;
        }

        /* Token selector - better mobile layout */
        .imu-token-row label {
            min-width: 100%;
        }

        /* Registration fields grid */
        .imu-registration-fields .reg-grid {
            grid-template-columns: 1fr;
        }

        /* Tier cards - scroll horizontally or stack */
        .imu-tiers {
            flex-direction: column;
            align-items: center;
        }
        .imu-tier-card {
            width: 100%;
            max-width: 280px;
        }

        /* Form buttons - full width */
        .imu-form-section .imu-btn {
            width: 100%;
        }

        /* Checkbox groups - better spacing */
        .imu-checkbox-group {
            flex-direction: column;
            gap: 10px;
        }
        .imu-radio-group {
            flex-direction: column;
            gap: 10px;
        }
    }

    /* Small phone adjustments */
    @media (max-width: 480px) {
        .imu-music-type-card {
            padding: 16px;
        }
        .imu-music-type-card h4 {
            font-size: 1rem;
        }
        .imu-exclusion-tag {
            font-size: 11px;
            padding: 4px 8px;
        }
        .imu-territory-header label span {
            font-size: 0.9rem;
        }
        .imu-form-section h3 {
            font-size: 1.1rem;
        }
    }

    /* ========== TOUCH-FRIENDLY STYLES ========== */
    @media (pointer: coarse) {
        /* Minimum touch target size for buttons */
        .imu-btn,
        .imu-country-dropdown-btn,
        .imu-token-dropdown-btn,
        .imu-trustline-btn {
            min-height: 44px;
            min-width: 44px;
        }
        
        /* Larger checkboxes and radios for touch */
        input[type="checkbox"],
        input[type="radio"] {
            width: 22px;
            height: 22px;
            margin-right: 10px;
        }
        
        /* Country items larger for touch */
        .imu-country-item {
            padding: 14px;
            min-height: 48px;
        }
        
        /* Token dropdown items larger */
        .imu-token-dropdown-item {
            padding: 14px;
            min-height: 48px;
        }
        
        /* Exclusion tag remove button */
        .imu-exclusion-tag button {
            padding: 8px;
            min-width: 32px;
            min-height: 32px;
        }
        
        /* Form inputs taller */
        .imu-input,
        .imu-textarea,
        select.imu-input {
            min-height: 48px;
            font-size: 16px; /* Prevents iOS zoom on focus */
        }
        
        /* File inputs */
        input[type="file"].imu-input {
            min-height: 48px;
            padding: 12px;
        }
    }
    
    /* ========== TRUSTLINE BUTTON STYLES ========== */
    .imu-trustline-btn {
        padding: 4px 10px;
        font-size: 11px;
        background: transparent;
        border: 1px solid rgba(57,211,83,0.4);
        color: #39d353;
        border-radius: 4px;
        cursor: pointer;
        transition: all 0.2s;
        white-space: nowrap;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .imu-trustline-btn:hover {
        background: rgba(57,211,83,0.15);
        border-color: #39d353;
        color: #39d353;
    }
    .imu-trustline-btn.disabled {
        border-color: rgba(136,136,136,0.3);
        color: #555;
        cursor: default;
        pointer-events: none;
    }
    
    /* ========== ADD MORE TOKENS DROPDOWN ========== */
    .imu-token-add-section {
        margin-top: 16px;
        padding-top: 16px;
        border-top: 1px dashed rgba(255,255,255,0.15);
    }
    .imu-token-add-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
    }
    .imu-token-add-header > label {
        color: #aaa;
        font-size: 13px;
    }
    .imu-token-dropdown {
        position: relative;
        display: inline-block;
    }
    .imu-token-dropdown-btn {
        padding: 8px 16px;
        background: rgba(var(--imu-gold-rgb), 0.1);
        border: 1px solid rgba(var(--imu-gold-rgb), 0.3);
        color: var(--imu-gold, var(--imu-gold, #d6ba66));
        border-radius: 6px;
        cursor: pointer;
        font-size: 13px;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s;
    }
    .imu-token-dropdown-btn:hover {
        background: rgba(var(--imu-gold-rgb), 0.2);
        border-color: var(--imu-gold, var(--imu-gold, #d6ba66));
    }
    .imu-token-dropdown-menu {
        position: absolute;
        top: 100%;
        right: 0;
        margin-top: 4px;
        background: #1a1a1a;
        border: 1px solid rgba(255,255,255,0.15);
        border-radius: 8px;
        min-width: 280px;
        max-height: 300px;
        overflow-y: auto;
        z-index: 100;
        display: none;
        box-shadow: 0 8px 24px rgba(0,0,0,0.5);
    }
    .imu-token-dropdown.open .imu-token-dropdown-menu {
        display: block;
    }
    .imu-token-dropdown-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 14px;
        cursor: pointer;
        transition: background 0.15s;
        border-bottom: 1px solid rgba(255,255,255,0.05);
    }
    .imu-token-dropdown-item:last-child {
        border-bottom: none;
    }
    .imu-token-dropdown-item:hover {
        background: rgba(255,255,255,0.05);
    }
    .imu-token-dropdown-item.added {
        opacity: 0.4;
        pointer-events: none;
    }
    .imu-token-dropdown-item img,
    .imu-token-dropdown-item .token-logo-placeholder {
        width: 24px;
        height: 24px;
        border-radius: 50%;
    }
    .token-logo-placeholder {
        background: #333;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 8px;
        color: #fff;
    }
    .imu-token-dropdown-item .token-name {
        flex: 1;
    }
    .imu-token-dropdown-item .token-name strong {
        color: #fff;
        display: block;
        font-size: 13px;
    }
    .imu-token-dropdown-item .token-name span {
        color: #888;
        font-size: 11px;
    }
    .imu-token-dropdown-item .added-check {
        color: #39d353;
        font-size: 14px;
    }
    .imu-token-dropdown-empty {
        padding: 16px;
        text-align: center;
        color: #666;
        font-size: 13px;
    }
    
    /* Additional tokens container */
    .imu-additional-tokens {
        margin-top: 12px;
    }
    .imu-additional-tokens:empty {
        display: none;
    }
    .imu-token-remove-btn {
        padding: 4px 8px;
        background: rgba(255,100,100,0.1);
        border: 1px solid rgba(255,100,100,0.3);
        color: #ff6b6b;
        border-radius: 4px;
        cursor: pointer;
        font-size: 11px;
        transition: all 0.2s;
    }
    .imu-token-remove-btn:hover {
        background: rgba(255,100,100,0.2);
        border-color: #ff6b6b;
    }

    /* ========== WIZARD PROGRESS BAR ========== */
    .imu-wizard-progress {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px 10px;
        margin-bottom: 24px;
        max-width: 100%;
        overflow-x: auto;
    }
    .imu-progress-step {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 8px;
        opacity: 0.5;
        transition: opacity 0.3s ease;
        min-width: 70px;
    }
    .imu-progress-step.active,
    .imu-progress-step.completed {
        opacity: 1;
    }
    .imu-step-number {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.1);
        border: 2px solid rgba(255, 255, 255, 0.2);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 1rem;
        color: #fff;
        transition: all 0.3s ease;
    }
    .imu-progress-step.active .imu-step-number {
        background: linear-gradient(135deg, var(--imu-gold, var(--imu-gold, #d6ba66)), var(--imu-gold-secondary, #d4af37));
        border-color: var(--imu-gold, var(--imu-gold, #d6ba66));
        color: #000;
        box-shadow: 0 0 20px rgba(var(--imu-gold-rgb), 0.4);
    }
    .imu-progress-step.completed .imu-step-number {
        background: #39d353;
        border-color: #39d353;
        color: #fff;
    }
    .imu-progress-step.completed .imu-step-number::after {
        content: '✓';
    }
    .imu-progress-step.completed .imu-step-number span {
        display: none;
    }
    .imu-step-label {
        font-size: 0.75rem;
        color: rgba(255, 255, 255, 0.5);
        text-align: center;
        max-width: 80px;
        line-height: 1.2;
    }
    .imu-progress-step.active .imu-step-label {
        color: var(--imu-gold, var(--imu-gold, #d6ba66));
        font-weight: 600;
    }
    .imu-progress-step.completed .imu-step-label {
        color: #39d353;
    }
    .imu-progress-line {
        width: 40px;
        height: 2px;
        background: rgba(255, 255, 255, 0.15);
        margin: 0 4px;
        margin-bottom: 24px;
        transition: background 0.3s ease;
    }
    .imu-progress-line.completed {
        background: #39d353;
    }

    /* ========== WIZARD STEPS ========== */
    .imu-wizard-step {
        display: none;
        animation: imuFadeIn 0.3s ease;
    }
    .imu-wizard-step.active {
        display: block;
    }
    @keyframes imuFadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* ========== WIZARD NAVIGATION ========== */
    .imu-wizard-nav {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 24px;
        padding-top: 24px;
        border-top: 1px solid rgba(255,255,255,0.1);
        gap: 16px;
    }
    .imu-wizard-nav .imu-btn {
        min-width: 140px;
    }
    .imu-wizard-nav .imu-btn-back {
        background: transparent;
        border: 1px solid rgba(255,255,255,0.3);
        color: #fff;
    }
    .imu-wizard-nav .imu-btn-back:hover {
        background: rgba(255,255,255,0.1);
        border-color: rgba(255,255,255,0.5);
    }
    .imu-wizard-nav .imu-btn-next {
        background: linear-gradient(135deg, var(--imu-gold, var(--imu-gold, #d6ba66)), var(--imu-gold-secondary, #d4af37));
        color: #000;
        font-weight: 600;
    }
    .imu-wizard-nav .imu-btn-next:hover {
        box-shadow: 0 4px 15px rgba(var(--imu-gold-rgb), 0.4);
    }
    .imu-wizard-nav .spacer {
        flex: 1;
    }

    /* Step info header */
    .imu-step-header {
        margin-bottom: 24px;
        padding-bottom: 16px;
        border-bottom: 1px solid rgba(255,255,255,0.1);
    }
    .imu-step-header h2 {
        margin: 0 0 8px;
        font-size: 1.5rem;
        color: var(--imu-gold, var(--imu-gold, #d6ba66));
    }
    .imu-step-header p {
        margin: 0;
        color: #aaa;
        font-size: 0.95rem;
    }

    /* Pre-fill notice */
    .imu-prefill-notice {
        background: linear-gradient(135deg, rgba(57,211,83,0.1) 0%, rgba(0,0,0,0.3) 100%);
        border: 1px solid rgba(57,211,83,0.3);
        border-radius: 8px;
        padding: 12px 16px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .imu-prefill-notice .icon {
        font-size: 1.5rem;
    }
    .imu-prefill-notice .text {
        flex: 1;
    }
    .imu-prefill-notice .text strong {
        color: #39d353;
    }
    .imu-prefill-notice .text span {
        color: #aaa;
        font-size: 0.9rem;
    }

    /* Validation error styles for wizard */
    .imu-wizard-errors {
        background: rgba(255, 100, 100, 0.1);
        border: 1px solid rgba(255, 100, 100, 0.3);
        border-radius: 8px;
        padding: 16px;
        margin-bottom: 20px;
    }
    .imu-wizard-errors h4 {
        margin: 0 0 12px;
        color: #ff6b6b;
        font-size: 1rem;
    }
    .imu-wizard-errors ul {
        margin: 0;
        padding-left: 20px;
    }
    .imu-wizard-errors li {
        color: #ff9999;
        margin-bottom: 6px;
    }

    /* Mobile responsive wizard */
    @media (max-width: 600px) {
        .imu-wizard-progress {
            padding: 15px 5px;
            gap: 2px;
        }
        .imu-progress-step {
            min-width: 50px;
        }
        .imu-step-number {
            width: 32px;
            height: 32px;
            font-size: 0.85rem;
        }
        .imu-step-label {
            font-size: 0.65rem;
            max-width: 60px;
        }
        .imu-progress-line {
            width: 20px;
        }
        .imu-wizard-nav {
            flex-direction: column;
        }
        .imu-wizard-nav .imu-btn {
            width: 100%;
        }
        .imu-wizard-nav .spacer {
            display: none;
        }
    }
  </style>
  <section class="imu-hero reveal">
    <div class="container imu-center">
      <div class="imu-copy imu-glass" style="max-width:980px;margin:0 auto;">
        <h1 style="margin:0 0 10px;">Get Seen & Heard on IMUTV</h1>
        <p style="margin:0;">Choose your path to reveal the relevant submission form.</p>
      </div>
      <?php if ($sent === '1'): ?>
        <div class="imu-glass" style="display:block;max-width:980px;margin:12px auto 0;padding:12px 16px;color:#b4f0b4;">
          Thanks! Your <?php echo $type === 'music' ? 'music' : 'content'; ?> submission was received.
        </div>
      <?php elseif (!empty($upload_error_message)): ?>
        <div class="imu-glass" style="display:block;max-width:980px;margin:12px auto 0;padding:12px 16px;color:#f5b5b5;border:1px solid #ff6b6b;background:rgba(100,0,0,0.55);">
          <strong>Submission failed.</strong><br>
          <?php echo esc_html($upload_error_message); ?>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <section class="imu-section reveal">
    <div class="container imu-center" style="max-width:1100px;">
      <!-- Tier display cards -->
      <div class="imu-tiers">
        <div class="imu-tier-card">
          <h3>IMUTV Storage</h3>
          <p>50 GB</p>
          <p>of Storage</p>
          <p><strong>FREE</strong></p>
        </div>
        <div class="imu-tier-card">
          <h3>IMUTV Storage</h3>
          <p>50 GB</p>
          <p>of Additional Storage</p>
          <p><strong>$20 Per Year</strong></p>
        </div>
        <div class="imu-tier-card">
          <h3>IMUTV Storage</h3>
          <p>100 GB</p>
          <p>of Additional Storage</p>
          <p><strong>$35 Per Year</strong></p>
        </div>
        <div class="imu-tier-card">
          <h3>IMUTV Storage</h3>
          <p>250 GB</p>
          <p>of Additional Storage</p>
          <p><strong>$75 Per Year</strong></p>
        </div>
      </div>

      <!-- Choice buttons -->
      <div class="imu-copy imu-glass" style="max-width:760px;margin:0 auto 18px;">
        <div style="display:flex; gap:12px; justify-content:center; flex-wrap:wrap;">
          <button class="imu-btn" type="button" data-upload-choice="music">Music Artist</button>
          <button class="imu-btn" type="button" data-upload-choice="creator">Content Creator</button>
          <button class="imu-btn" type="button" data-upload-choice="streamer">Live Streamer</button>
        </div>
      </div>

           <!-- =========================
           MUSICIAN FORM (longform)
           ========================= -->
      <form id="imu-form-music" class="imu-form" style="display:none;" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
        <input type="hidden" name="action" value="imu_music_submit">
        <?php wp_nonce_field('imu_music_submit','imu_music_nonce'); ?>

        <div class="imu-longform">

          <!-- Header bar -->
          <section class="imu-form-section">
            <div class="imu-form-head">
              <h3>Musician Submission</h3>
            </div>
          </section>

          <!-- Wizard Progress Bar -->
          <div class="imu-wizard-progress" id="music-wizard-progress">
            <div class="imu-progress-step active" data-step="1">
              <div class="imu-step-number"><span>1</span></div>
              <div class="imu-step-label">Personal & Artist</div>
            </div>
            <div class="imu-progress-line"></div>
            <div class="imu-progress-step" data-step="2">
              <div class="imu-step-number"><span>2</span></div>
              <div class="imu-step-label">Content Details</div>
            </div>
            <div class="imu-progress-line"></div>
            <div class="imu-progress-step" data-step="3">
              <div class="imu-step-number"><span>3</span></div>
              <div class="imu-step-label">Monetization</div>
            </div>
            <div class="imu-progress-line"></div>
            <div class="imu-progress-step" data-step="4">
              <div class="imu-step-number"><span>4</span></div>
              <div class="imu-step-label">Upload & Submit</div>
            </div>
          </div>

          <!-- Error display area -->
          <div id="music-wizard-errors" class="imu-wizard-errors" style="display:none;">
            <h4>⚠️ Please fix the following:</h4>
            <ul id="music-wizard-error-list"></ul>
          </div>

          <!-- ==================== STEP 1: Personal & Artist Info ==================== -->
          <div class="imu-wizard-step active" data-step="1" id="music-step-1">
            
            <div class="imu-step-header">
              <h2>👤 Personal & Artist Information</h2>
              <p>Tell us about yourself and your music identity</p>
            </div>

            <?php if (!empty($prefill_music)): ?>
            <div class="imu-prefill-notice">
              <div class="icon">✨</div>
              <div class="text">
                <strong>Welcome back!</strong><br>
                <span>We've pre-filled your details from your last submission. Feel free to update anything.</span>
              </div>
            </div>
            <?php endif; ?>

          <!-- Personal Information -->
          <section class="imu-form-section">
            <h3>Personal Information</h3>
            <div class="grid-2">
              <div class="imu-field full">
                <label>Are You... <span style="color:#e88">*</span></label>
                <div class="imu-radio-group">
                  <label><input type="radio" name="submitter_type" value="artist" required <?php echo imu_prefill($prefill_music, 'submitter_type') === 'artist' ? 'checked' : ''; ?>> Artist</label>
                  <label><input type="radio" name="submitter_type" value="band" required <?php echo imu_prefill($prefill_music, 'submitter_type') === 'band' ? 'checked' : ''; ?>> Band</label>
                  <label><input type="radio" name="submitter_type" value="manager" required <?php echo imu_prefill($prefill_music, 'submitter_type') === 'manager' ? 'checked' : ''; ?>> Manager</label>
                  <label><input type="radio" name="submitter_type" value="label" required <?php echo imu_prefill($prefill_music, 'submitter_type') === 'label' ? 'checked' : ''; ?>> Independent Label</label>
                </div>
              </div>

              <div class="imu-field">
                <label>First Name <span style="color:#e88">*</span></label>
                <input type="text" name="first_name" class="imu-input" required value="<?php echo imu_prefill($prefill_music, 'first_name'); ?>">
              </div>
              <div class="imu-field">
                <label>Last Name <span style="color:#e88">*</span></label>
                <input type="text" name="last_name" class="imu-input" required value="<?php echo imu_prefill($prefill_music, 'last_name'); ?>">
              </div>

              <div class="imu-field">
                <label>Email Address <span style="color:#e88">*</span></label>
                <input type="email" name="email" class="imu-input" required value="<?php echo esc_attr($prefill_email); ?>">
              </div>
              <!-- Auto-Registration Fields (Music) -->
              <div class="imu-field full imu-registration-wrapper" data-form="music">
                <div class="imu-email-status" id="music-email-status" style="display:none;"></div>
                <div class="imu-registration-fields" id="music-registration-fields" style="display:none;">
                  <h4>🔐 Create Your IMUTV Account</h4>
                  <p>This email isn't registered yet. Create a password to set up your account when you submit.</p>
                  <div class="reg-grid">
                    <div class="imu-field">
                      <label>Create Password <span style="color:#e88">*</span></label>
                      <input type="password" name="create_password" id="music-create-password" class="imu-input" minlength="8" autocomplete="new-password">
                      <div class="imu-password-strength"><div class="imu-password-strength-bar" id="music-pw-strength"></div></div>
                      <small style="color:#888;">Minimum 8 characters</small>
                    </div>
                    <div class="imu-field">
                      <label>Confirm Password <span style="color:#e88">*</span></label>
                      <input type="password" name="confirm_password" id="music-confirm-password" class="imu-input" minlength="8" autocomplete="new-password">
                      <div class="imu-password-match" id="music-pw-match">Passwords must match</div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="imu-field">
                <label>Address</label>
                <input type="text" name="address" class="imu-input" value="<?php echo imu_prefill($prefill_music, 'address'); ?>">
              </div>

              <div class="imu-field full" id="label-name-wrap" style="display:none;">
                <label>Label Name <span style="color:#e88">*</span></label>
                <input type="text" name="label_name" class="imu-input" value="<?php echo imu_prefill($prefill_music, 'label_name'); ?>">
              </div>

<div class="imu-field full" id="proof-agreement-wrap" style="display:none;">
                <label>Proof of Artist Agreement <span style="color:#e88">*</span></label>
                <div id="proof-agreement-list">
                  <!-- FIX: Now accepts PDF files in addition to images -->
                  <input type="file" name="proof_agreement[]" class="imu-input" accept="image/*,.pdf,application/pdf" multiple>
                </div>
                <small>Accepted formats: Images (JPG, PNG, etc.) or PDF documents.</small>
              </div>
            </div>
          </section>

          <!-- Artist Information -->
          <section class="imu-form-section">
            <h3>Artist Information</h3>
            <div class="grid-2">
              <div class="imu-field">
                <label>Artist or Band Name <span style="color:#e88">*</span></label>
                <input type="text" name="artist_name" class="imu-input" required value="<?php echo imu_prefill($prefill_music, 'artist_name'); ?>">
              </div>

              <div class="imu-field">
                <label>Primary Genre</label>
                <input type="text" name="primary_genre" class="imu-input" value="<?php echo imu_prefill($prefill_music, 'primary_genre'); ?>">
              </div>
              <div class="imu-field">
                <label>Secondary Genre</label>
                <input type="text" name="secondary_genre" class="imu-input" value="<?php echo imu_prefill($prefill_music, 'secondary_genre'); ?>">
              </div>

              <div class="imu-field">
                <label>Location</label>
                <input type="text" name="location" class="imu-input" value="<?php echo imu_prefill($prefill_music, 'location'); ?>">
              </div>
              <div class="imu-field">
                <label>Website</label>
                <?php $prefill_website = imu_prefill($prefill_music, 'website'); ?>
                <input type="text" name="website" class="imu-input" placeholder="https://www.example.com or www.example.com" value="<?php echo $prefill_website; ?>">
              </div>

              <div class="imu-field full">
                <label>Artist Bio <span style="color:#e88">*</span></label>
                <textarea name="artist_bio" class="imu-textarea" required><?php echo imu_prefill($prefill_music, 'artist_bio'); ?></textarea>
              </div>
            </div>
          </section>


          <!-- Social Media -->
          <section class="imu-form-section">
            <h3>Social Media</h3>
            <?php 
              $prefill_social = imu_prefill_json($prefill_music, 'social');
            ?>
            <div class="grid-2">
              <div class="imu-field"><label>X</label><input type="url" name="social[x]" class="imu-input" placeholder="https://x.com/..." value="<?php echo esc_attr($prefill_social['x'] ?? ''); ?>"></div>
              <div class="imu-field"><label>Instagram</label><input type="url" name="social[instagram]" class="imu-input" placeholder="https://instagram.com/..." value="<?php echo esc_attr($prefill_social['instagram'] ?? ''); ?>"></div>
              <div class="imu-field"><label>TikTok</label><input type="url" name="social[tiktok]" class="imu-input" placeholder="https://tiktok.com/@..." value="<?php echo esc_attr($prefill_social['tiktok'] ?? ''); ?>"></div>
            </div>

            <div id="music-social-wrap" class="grid-2"></div>
            <div class="imu-actions" style="justify-content:flex-start;margin-top:8px;">
              <button class="imu-btn" type="button" data-add-music-social>Add More</button>
            </div>
          </section>

          <!-- Step 1 Navigation -->
          <div class="imu-wizard-nav">
            <div class="spacer"></div>
            <button type="button" class="imu-btn imu-btn-next" onclick="musicWizardNext(1)">Next: Content Details →</button>
          </div>

          </div><!-- End Step 1 -->

          <!-- ==================== STEP 2: Content Details ==================== -->
          <div class="imu-wizard-step" data-step="2" id="music-step-2">
            
            <div class="imu-step-header">
              <h2>🎵 Content Details</h2>
              <p>First select your content type, then fill in the track details</p>
            </div>

          <!-- Music Type Selector - NOW IN STEP 2 -->
          <section class="imu-form-section">
            <h3>Content Type</h3>
            <p style="margin:0 0 16px;color:#ccc;">Select the type of content you are uploading:</p>
            <div class="imu-music-type-selector">
              <label class="imu-music-type-card">
                <input type="radio" name="music_content_type" value="music_video" style="margin-bottom:12px;" required>
                <h4>🎬 Music Video</h4>
                <p>Upload a music video with thumbnail</p>
              </label>
              <label class="imu-music-type-card">
                <input type="radio" name="music_content_type" value="audio_only" style="margin-bottom:12px;" required>
                <h4>🎵 Audio Only</h4>
                <p>Upload audio track with cover art</p>
              </label>
            </div>
          </section>

          <!-- Track Details - Hidden until content type selected -->
          <div id="music-track-details-wrapper" style="display:none;">
          
          <!-- Track Details -->
          <section class="imu-form-section">
            <h3>Track Details</h3>
            <div class="grid-2">
              <div class="imu-field">
                <label>Track Name <span style="color:#e88">*</span></label>
                <input type="text" name="track_name" class="imu-input" required>
              </div>
              <div class="imu-field">
                <label>Performer <span style="color:#e88">*</span></label>
                <input type="text" name="performer" class="imu-input" required value="<?php echo imu_prefill($prefill_music, 'artist_name'); ?>">
              </div>

              <div class="imu-field">
                <label>Songwriter <span style="color:#e88">*</span></label>
                <input type="text" name="songwriter" class="imu-input" required>
              </div>
              <div class="imu-field" id="production-field-wrap">
                <label>Production/Engineering <span class="production-required-star" style="color:#e88;display:none;">*</span></label>
                <input type="text" name="production" class="imu-input" id="production-input">
              </div>

              <!-- Track Duration - Only shown for Audio Only -->
              <div class="imu-field" id="track-duration-wrap" style="display:none;">
                <label>Track Duration <span style="color:#e88">*</span></label>
                <input type="text" name="track_duration" id="track-duration-input" class="imu-input" placeholder="e.g., 3:45 or 03:45">
                <small style="color:#888;">Format: MM:SS</small>
              </div>
              
              <div class="imu-field">
                <label>UPC/EPN Code</label>
                <input type="text" name="upc" class="imu-input">
              </div>

              <!-- ISRC Video Code - Only shown for Music Video -->
              <div class="imu-field isrc-video-field">
                <label>ISRC Video Code</label>
                <input type="text" name="isrc_video" class="imu-input" placeholder="e.g., USRC17607839">
              </div>

              <!-- ISRC Audio Code - Shown for both -->
              <div class="imu-field">
                <label>ISRC Audio Code</label>
                <input type="text" name="isrc_audio" class="imu-input" placeholder="e.g., USRC17607839">
              </div>

              <!-- PRO Writers Registration -->
              <div class="imu-field">
                <label>PRO Writers Registration</label>
                <input type="text" name="pro_writers_registration" class="imu-input" placeholder="ASCAP / BMI / PRS etc.">
              </div>

              <!-- PRO Publishers Registration -->
              <div class="imu-field">
                <label>PRO Publishers Registration</label>
                <input type="text" name="pro_publishers_registration" class="imu-input" placeholder="ASCAP / BMI / PRS etc.">
              </div>

              <!-- ISWC -->
              <div class="imu-field">
                <label>ISWC</label>
                <input type="text" name="iswc" class="imu-input" placeholder="e.g., T-123.456.789-0">
                <small style="color:#888;">International Standard Musical Work Code</small>
              </div>

              <!-- IPI/CAE Writers Code -->
              <div class="imu-field">
                <label>IPI/CAE Writers Code</label>
                <input type="text" name="ipi_cae_writers" class="imu-input" placeholder="e.g., 123456789">
              </div>

              <!-- IPI/CAE Publishers Code -->
              <div class="imu-field">
                <label>IPI/CAE Publishers Code</label>
                <input type="text" name="ipi_cae_publishers" class="imu-input" placeholder="e.g., 123456789">
              </div>

              <!-- NEW: Track Description (above Lyrics) -->
<div class="imu-field full">
  <label>Track Description <span style="color:#e88">*</span></label>
  <textarea name="track_description"
            class="imu-textarea"
            required
            placeholder="Briefly describe the mood, story, and vision for this track."></textarea>
</div>

              <div class="imu-field full">
                <label>Lyrics</label>
                <textarea name="lyrics" class="imu-textarea" placeholder="Paste lyrics here (optional)"></textarea>
              </div>
            </div>

            <!-- Contributors (repeatable) -->
            <div id="music-contrib-wrap" class="grid-2">
              <div class="imu-field">
                <label>Contributor Name</label>
                <input type="text" name="contributors[0][name]" class="imu-input">
              </div>
              <div class="imu-field">
                <label>Contributor Role</label>
                <input type="text" name="contributors[0][role]" class="imu-input">
              </div>
            </div>
            <div class="imu-actions" style="justify-content:flex-start;margin-top:8px;">
              <button class="imu-btn" type="button" data-add-music-contrib>Add More</button>
            </div>

            <!-- Territory Authorization -->
            <div class="imu-territory-section">
              <div class="imu-territory-header">
                <label style="display:flex;align-items:center;gap:10px;">
                  <input type="checkbox" name="territory_worldwide" id="music-territory-worldwide" checked>
                  <span><strong>Content is authorized to go worldwide</strong></span>
                </label>
              </div>
              <div id="music-territory-exclusions" style="display:none;">
                <p style="margin:0 0 12px;color:#aaa;font-size:0.9rem;">Add countries where this content is NOT authorized:</p>
                <div class="imu-country-dropdown" id="music-country-dropdown">
                  <button type="button" class="imu-country-dropdown-btn" onclick="toggleCountryDropdown('music-country-dropdown')">
                    ➕ Add Exclusion
                  </button>
                  <div class="imu-country-dropdown-menu">
                    <div class="imu-country-search">
                      <input type="text" placeholder="Search countries..." onkeyup="filterCountries(this, 'music-country-dropdown')">
                    </div>
                    <div class="imu-country-list" data-target="music"></div>
                  </div>
                </div>
                <div class="imu-exclusions-list" id="music-exclusions-list"></div>
                <input type="hidden" name="territory_exclusions" id="music-territory-exclusions-input" value="">
              </div>
            </div>
          </section>

          <!-- NEW Sample Clearance -->
          <section class="imu-form-section">
            <h3>Sample / Lease Clearance</h3>
            <div class="grid-2">
              <div class="imu-field full">
                <label>Were any Samples used in the production of this track? <span style="color:#e88">*</span></label>
                <select name="samples_used" class="imu-input" required>
                  <option value="">Select...</option>
                  <option value="no">No</option>
                  <option value="yes">Yes</option>
                </select>
              </div>

              <div class="imu-field full" id="sample-proof-wrap" style="display:none;">
                <label>Clearance of Sample or Lease Proof <span style="color:#e88">*</span></label>
                <div id="sample-proof-list">
                  <input type="file" name="sample_proofs[]" class="imu-input" accept=".pdf">
                </div>
                <div class="imu-actions" style="justify-content:flex-start;margin-top:8px;">
                  <button class="imu-btn" type="button" data-add-sample-proof>Add More</button>
                </div>
              </div>
            </div>
          </section>

          <!-- NEW AI Input -->
          <section class="imu-form-section">
            <h3>AI Input</h3>
            <div class="grid-2">
              <!-- MUSIC AI -->
              <div class="imu-field full">
                <label>Was ANY of the music made with AI? <span style="color:#e88">*</span></label>
                <select name="ai_music" class="imu-input" required>
                  <option value="">Select...</option>
                  <option value="no">No</option>
                  <option value="yes">Yes</option>
                </select>
              </div>

              <div class="imu-field full" id="ai-music-form" style="display:none;">
                <div class="grid-2">
                  <div class="imu-field">
                    <label>What platform was used? <span style="color:#e88">*</span></label>
                    <input type="text" name="ai_music_platform" class="imu-input">
                  </div>
                  <div class="imu-field">
                    <label>What date was the track created? <span style="color:#e88">*</span></label>
                    <input type="date" name="ai_music_date" class="imu-input">
                  </div>
                </div>

                <div class="grid-2">
                  <div class="imu-field">
                    <label>Do you have commercial rights for this track? <span style="color:#e88">*</span></label>
                    <div class="imu-radio-group">
                      <label><input type="radio" name="ai_music_rights" value="yes"> Yes</label>
                      <label><input type="radio" name="ai_music_rights" value="no"> No</label>
                    </div>
                  </div>
                </div>

                <div class="grid-2">
                  <div class="imu-field">
                    <label>What parts of the track were AI created? <span style="color:#e88">*</span></label>
                    <div class="imu-checkbox-group">
                      <label><input type="checkbox" name="ai_music_parts_ai[]" value="full_song"> Full Song</label>
                      <label><input type="checkbox" name="ai_music_parts_ai[]" value="lyrics"> Lyrics</label>
                      <label><input type="checkbox" name="ai_music_parts_ai[]" value="melody"> Melody</label>
                      <label><input type="checkbox" name="ai_music_parts_ai[]" value="vocal_performance"> Vocal Performance</label>
                      <label><input type="checkbox" name="ai_music_parts_ai[]" value="harmony"> Harmony</label>
                      <label><input type="checkbox" name="ai_music_parts_ai[]" value="rhythm"> Rhythm</label>
                    </div>
                  </div>
                  <div class="imu-field">
  <label>What parts of the track had human influence? <span style="color:#e88">*</span></label>
  <div class="imu-checkbox-group">
    <label><input type="checkbox" name="ai_music_parts_human[]" value="lyrics"> Lyrics</label>
    <label><input type="checkbox" name="ai_music_parts_human[]" value="melody"> Melody</label>
    <label><input type="checkbox" name="ai_music_parts_human[]" value="vocal_performance"> Vocal Performance</label>
    <label><input type="checkbox" name="ai_music_parts_human[]" value="harmony"> Harmony</label>
    <label><input type="checkbox" name="ai_music_parts_human[]" value="rhythm"> Rhythm</label>
    <label><input type="checkbox" name="ai_music_parts_human[]" value="none"> None</label>
  </div>
</div>
                </div>
              </div>

              <!-- VIDEO AI -->
              <div class="imu-field full">
                <label>Was ANY of the video made with AI? <span style="color:#e88">*</span></label>
                <select name="ai_video" class="imu-input" required>
                  <option value="">Select...</option>
                  <option value="no">No</option>
                  <option value="yes">Yes</option>
                </select>
              </div>

              <div class="imu-field full" id="ai-video-form" style="display:none;">
                <div class="grid-2">
                  <div class="imu-field">
                    <label>What platform was used? <span style="color:#e88">*</span></label>
                    <input type="text" name="ai_video_platform" class="imu-input">
                  </div>
                  <div class="imu-field">
                    <label>What date was the video created? <span style="color:#e88">*</span></label>
                    <input type="date" name="ai_video_date" class="imu-input">
                  </div>
                </div>

                <div class="grid-2">
                  <div class="imu-field">
                    <label>Do you have commercial rights for this video? <span style="color:#e88">*</span></label>
                    <div class="imu-radio-group">
                      <label><input type="radio" name="ai_video_rights" value="yes"> Yes</label>
                      <label><input type="radio" name="ai_video_rights" value="no"> No</label>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </section>

          </div><!-- End music-track-details-wrapper -->

          <!-- Step 2 Navigation -->
          <div class="imu-wizard-nav">
            <button type="button" class="imu-btn imu-btn-back" onclick="musicWizardBack(2)">← Back</button>
            <div class="spacer"></div>
            <button type="button" class="imu-btn imu-btn-next" onclick="musicWizardNext(2)">Next: Monetization →</button>
          </div>

          </div><!-- End Step 2 -->

          <!-- ==================== STEP 3: Monetization ==================== -->
          <div class="imu-wizard-step" data-step="3" id="music-step-3">
            
            <div class="imu-step-header">
              <h2>💰 Monetization</h2>
              <p>Set up how you want to earn from your content</p>
            </div>

          <!-- Content Monetization (MUSIC) -->
          <section class="imu-form-section">
            <h3>Content Monetization</h3>
            <div class="grid-2">
              <div class="imu-field full">
                <label>Do you want this content to be locked or public? <span style="color:#e88">*</span></label>
                <select name="content_lock" class="imu-input" required>
                  <option value="">Select...</option>
                  <option value="public">Public</option>
                  <option value="locked">Locked</option>
                </select>
              </div>

              <div class="imu-field full" id="locked-options" style="display:none;">
                
                <!-- Info Box -->
                <div class="imu-message-box" style="background:#000;color:var(--imu-gold);padding:12px;margin-bottom:16px;border:1px solid var(--imu-gold);border-radius:6px;">
                  <strong>💰 Monetize Your Content</strong><br>
                  <span style="color:#ccc;">Accept payments via traditional Stripe checkout (-5% fee) OR XRPL tokens (Fee Free!)</span><br><br>
                  <span style="color:#fff;">Purchases unlock content on IMUTV + IMUP3!</span>
                </div>

                <!-- USD Price -->
                <div class="imu-field">
                  <label>USD Price (Stripe Checkout)</label>
                  <input type="number" name="usd_price" class="imu-input" step="0.01" min="0.50" placeholder="e.g. 5.99">
                  <small style="color:#888;">This is your base price. Card payments charge this amount.</small>
                </div>

                <!-- XRPL Wallet -->
                <div class="imu-field">
                  <label>Your XRPL Wallet Address</label>
                  <input type="text" name="xrp_wallet" class="imu-input" placeholder="rXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX">
                  <small style="color:#888;">Web3 payments go directly to this wallet.</small>
                </div>

                <!-- Pricing Mode Toggle -->
                <div class="imu-field full" style="margin-top:20px;">
                  <label style="font-weight:600;color:var(--imu-gold);margin-bottom:12px;display:block;">Web3 Pricing Mode</label>
                  <div class="imu-pricing-mode-toggle">
                    <label class="imu-radio-card">
                      <input type="radio" name="pricing_mode" value="dynamic" checked style="margin-right:8px;">
                      <strong style="color:#fff;">Dynamic Pricing</strong>
                      <span style="color:#39d353;font-size:12px;display:block;margin-top:4px;">✨ RECOMMENDED</span>
                      <p style="margin:8px 0 0;font-size:13px;color:#aaa;">Auto-calculates token amounts from USD price. Set discounts to incentivize specific tokens!</p>
                    </label>
                    <label class="imu-radio-card">
                      <input type="radio" name="pricing_mode" value="static" style="margin-right:8px;">
                      <strong style="color:#fff;">Static Pricing</strong>
                      <p style="margin:8px 0 0;font-size:13px;color:#aaa;">Manually set fixed token amounts. Prices won't change with market rates.</p>
                    </label>
                  </div>
                </div>

                <!-- DYNAMIC PRICING SECTION -->
                <div id="music-dynamic-pricing" style="margin-top:20px;">
                  
                  <!-- How It Works -->
                  <div class="imu-info-card" style="background:linear-gradient(135deg, rgba(57,211,83,0.1) 0%, rgba(0,0,0,0.4) 100%);border:1px solid rgba(57,211,83,0.3);">
                    <h4 style="color:#39d353;">📊 How Dynamic Pricing Works</h4>
                    <ul style="color:#ccc;">
                      <li>Token prices fetched in <strong style="color:#fff;">real-time</strong> from XRPL DEX + AMM pools</li>
                      <li>Buyers see <strong style="color:#fff;">live token amounts</strong> based on current market rates</li>
                      <li>Set <strong style="color:#39d353;">discounts</strong> to incentivize payments in specific tokens</li>
                    </ul>
                  </div>

                  <!-- Token Selection -->
                  <div class="imu-field full">
                    <label style="font-weight:600;margin-bottom:12px;display:block;">Select Accepted Tokens & Discounts</label>
                    <div class="imu-token-selector" id="music-token-selector">
                      
                      <!-- Core Tokens (XRP, RLUSD, XFT) - Always Displayed -->
                      <?php foreach ($imu_core_tokens_ordered as $token): ?>
                        <?php echo imu_render_token_row($token, true, false); ?>
                      <?php endforeach; ?>
                      
                      <!-- Additional Tokens Container -->
                      <div class="imu-additional-tokens" id="music-additional-tokens"></div>
                      
                      <!-- Add More Tokens Dropdown -->
                      <?php if (!empty($imu_additional_tokens)): ?>
                      <div class="imu-token-add-section">
                        <div class="imu-token-add-header">
                          <label>Accept more XRPL tokens?</label>
                          <div class="imu-token-dropdown" id="music-token-dropdown">
                            <button type="button" class="imu-token-dropdown-btn" onclick="toggleTokenDropdown('music-token-dropdown')">
                              <span>➕ Add Token</span>
                              <span style="font-size:10px;">▼</span>
                            </button>
                            <div class="imu-token-dropdown-menu">
                              <?php foreach ($imu_additional_tokens as $token): 
                                $ticker = esc_attr($token['ticker']);
                                $display = esc_html($token['display_name'] ?? $token['ticker']);
                                $desc = esc_html($token['description'] ?? '');
                                $logo = esc_url($token['logo_url'] ?? '');
                                $issuer = esc_attr($token['issuer'] ?? '');
                                $trustline = esc_url($token['trustline_url'] ?? '');
                                $discount = (int)($token['default_discount'] ?? 0);
                              ?>
                              <div class="imu-token-dropdown-item" 
                                   data-ticker="<?php echo $ticker; ?>"
                                   data-issuer="<?php echo $issuer; ?>"
                                   data-display="<?php echo $display; ?>"
                                   data-desc="<?php echo $desc; ?>"
                                   data-logo="<?php echo $logo; ?>"
                                   data-trustline="<?php echo $trustline; ?>"
                                   data-discount="<?php echo $discount; ?>"
                                   onclick="addTokenToSelector(this, 'music-additional-tokens')">
                                <?php if ($logo): ?>
                                  <img src="<?php echo $logo; ?>" alt="<?php echo $ticker; ?>">
                                <?php else: ?>
                                  <div class="token-logo-placeholder"><?php echo substr($ticker, 0, 3); ?></div>
                                <?php endif; ?>
                                <div class="token-name">
                                  <strong><?php echo $ticker; ?></strong>
                                  <span><?php echo $desc ?: $display; ?></span>
                                </div>
                                <span class="added-check" style="display:none;">✓</span>
                              </div>
                              <?php endforeach; ?>
                            </div>
                          </div>
                        </div>
                      </div>
                      <?php endif; ?>
                      
                    </div>
                    <small style="color:#888;display:block;margin-top:8px;">Enable tokens you want to accept. Set discounts (0-99%) to incentivize specific payment methods. Click 🔗 to set trustlines.</small>
                  </div>

                  <!-- Trustline Info -->
                  <div class="imu-info-card" style="background:linear-gradient(135deg, rgba(var(--imu-gold-rgb), 0.1) 0%, rgba(0,0,0,0.4) 100%);border:1px solid rgba(var(--imu-gold-rgb), 0.3);margin-top:16px;">
                    <h4 style="color:var(--imu-gold);">🔗 About Trustlines</h4>
                    <p style="color:#ccc;">To receive non-native XRPL tokens, your wallet needs a <strong style="color:#fff;">trustline</strong> for each token. Click the <span style="color:#39d353;">🔗 Trustline</span> button next to any token to set it up via xrpl.services.</p>
                  </div>
                </div>

                <!-- STATIC PRICING SECTION -->
                <div id="music-static-pricing" style="display:none;margin-top:20px;">
                  <div class="imu-info-card" style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.1);">
                    <h4 style="color:#fff;">⚠️ Static Pricing Mode</h4>
                    <p style="color:#aaa;">You're setting fixed token amounts. These prices <strong style="color:#fff;">won't change</strong> even if market rates fluctuate.</p>
                  </div>

                  <div class="imu-field">
                    <label>$XRP Price (Static)</label>
                    <input type="number" name="xrp_price" class="imu-input" step="0.000001" min="0" placeholder="e.g. 15">
                  </div>
                  <div class="imu-field">
                    <label>$RLUSD Price (Static)</label>
                    <input type="number" name="rlusd_price" class="imu-input" step="0.000001" min="0" placeholder="e.g. 5">
                  </div>
                  <div class="imu-field">
                    <label>$XFT Price (Static)</label>
                    <input type="number" name="xft_price" class="imu-input" step="0.000001" min="0" placeholder="e.g. 100">
                  </div>

                  <div id="music-custom-token-wrap">
                    <label style="font-weight:600;margin-bottom:8px;display:block;">Custom Token (Optional)</label>
                    <div class="custom-token-row grid-3">
                      <div class="imu-field">
                        <label>Ticker</label>
                        <input type="text" name="custom_tokens[0][ticker]" class="imu-input" placeholder="e.g. MYTOKEN">
                      </div>
                      <div class="imu-field">
                        <label>Issuer Address</label>
                        <input type="text" name="custom_tokens[0][issuer]" class="imu-input" placeholder="r...">
                      </div>
                      <div class="imu-field">
                        <label>Price</label>
                        <input type="number" name="custom_tokens[0][price]" class="imu-input" step="0.000001" min="0">
                      </div>
                    </div>
                  </div>
                  <div class="imu-actions" style="justify-content:flex-start;margin-top:8px;">
                    <button class="imu-btn" type="button" data-add-custom-token data-form="music">+ Add Custom Token</button>
                  </div>
                </div>

              </div>
            </div>
          </section>
          
          <!-- ==================== NFT ACCESS SECTION (MUSIC) ==================== -->
          <!-- FIX: Added Yes/No toggle question, fields only required when Yes selected -->
          <section class="imu-form-section">
            <h3>NFT Access</h3>
            
            <div class="imu-message-box" style="background:#000;color:var(--imu-gold);padding:12px;margin-bottom:16px;border:1px solid var(--imu-gold);border-radius:6px;">
              <strong>Allow NFT Holders free access to your content!</strong><br>
              <span style="color:#fff;">Simply enter the collection or issuer details, and holders of those NFTs will have free access to your content! (XRPL NFTs Only!)</span>
            </div>

            <!-- NEW: Yes/No toggle question -->
            <div class="grid-2">
              <div class="imu-field full">
                <label>Do you want to allow NFT Holders free access to your content? <span style="color:#e88">*</span></label>
                <select name="nft_access_enabled" id="music-nft-access-toggle" class="imu-input" required>
                  <option value="">Select...</option>
                  <option value="no">No</option>
                  <option value="yes">Yes</option>
                </select>
              </div>
            </div>

            <!-- NFT Gate fields - hidden by default, shown when Yes selected -->
            <div id="music-nft-gate-fields" style="display:none;margin-top:16px;">
              <p style="margin:8px 0 16px;color:#ccc;font-size:0.95em;">
                Grant free access to owners of these NFT collections (any one collection qualifies).
              </p>

              <div id="nft-gate-container-music">
                <div class="nft-gate-row grid-3" style="margin-bottom:12px;padding:12px;background:rgba(255,255,255,.03);border-radius:8px;">
                  <div class="imu-field">
                    <label>Collection Title (optional)</label>
                    <input type="text" name="nft_gate[0][title]" class="imu-input" placeholder="e.g., Founders Pass">
                  </div>
                  <div class="imu-field">
                    <label>Issuer Address <span style="color:#e88">*</span></label>
                    <!-- FIX: NOT required by default, JS will toggle based on Yes/No -->
                    <input type="text" name="nft_gate[0][issuer]" class="imu-input nft-issuer-field" placeholder="r123...">
                  </div>
                  <div class="imu-field">
                    <label>Taxon (leave blank = any)</label>
                    <input type="number" name="nft_gate[0][taxon]" class="imu-input" placeholder="Any">
                  </div>
                  <div class="imu-field">
                    <label>Min Required</label>
                    <input type="number" name="nft_gate[0][min]" class="imu-input" value="1" min="1">
                  </div>
                </div>
              </div>

              <div class="imu-actions" style="justify-content:flex-start;margin-top:8px;">
                <button class="imu-btn" type="button" data-add-nft-gate data-form="music">+ Add Another Collection</button>
              </div>
            </div>
          </section>

          <!-- Step 3 Navigation -->
          <div class="imu-wizard-nav">
            <button type="button" class="imu-btn imu-btn-back" onclick="musicWizardBack(3)">← Back</button>
            <div class="spacer"></div>
            <button type="button" class="imu-btn imu-btn-next" onclick="musicWizardNext(3)">Next: Upload & Submit →</button>
          </div>

          </div><!-- End Step 3 -->

          <!-- ==================== STEP 4: Upload & Submit ==================== -->
          <div class="imu-wizard-step" data-step="4" id="music-step-4">
            
            <div class="imu-step-header">
              <h2>📤 Upload & Submit</h2>
              <p>Upload your content and submit for review</p>
            </div>

          <!-- Upload Content -->
          <section class="imu-form-section">
            <h3>Upload Content</h3>
            
            <!-- Info box - changes based on music type -->
            <div class="imu-message-box" id="music-upload-info" style="background:#000;color:var(--imu-gold);padding:12px;margin-bottom:16px;border:1px solid var(--imu-gold);border-radius:6px;">
              <strong>Please select a content type in Step 1 to see upload requirements.</strong>
            </div>

            <!-- MUSIC VIDEO UPLOAD FIELDS -->
            <div id="music-video-upload-fields" style="display:none;">
              <div class="grid-2">
                <div class="imu-field">
                  <label>Video Content upload <span style="color:#e88">*</span></label>
                  <div class="imu-upload-dual-input">
                    <input type="file" name="content_file" class="imu-input" accept="video/*">
                    <input type="url"
                           name="content_url"
                           class="imu-input"
                           placeholder="https://drive.google.com/... or other storage link">
                  </div>
                  <small>Upload up to 400MB OR paste a storage link if your file is larger.</small>
                </div>
                <div class="imu-field">
                  <label class="imu-help-wrapper">
                    Thumbnail upload <span style="color:#e88">*</span>
                    <span class="imu-help-icon" aria-label="More info" tabindex="0">?</span>
                    <span class="imu-help-tooltip">
                      The optimal size for IMUTV Thumbnails is 1920x1080
                    </span>
                  </label>
                  <input type="file" name="thumb_file" class="imu-input" accept="image/*">
                  <small>Required for video uploads. Max file size 400MB.</small>
                </div>
              </div>
            </div>

            <!-- AUDIO ONLY UPLOAD FIELDS -->
            <div id="audio-only-upload-fields" style="display:none;">
              <div class="grid-2">
                <div class="imu-field">
                  <label class="imu-help-wrapper">
                    MP3/Audio Upload <span style="color:#e88">*</span>
                    <span class="imu-help-icon" aria-label="More info" tabindex="0">?</span>
                    <span class="imu-help-tooltip">
                      Upload your MP3/Audio file for the Audio Only section of IMUTV.
                      If your file is larger than 400MB, paste a storage link instead.
                    </span>
                  </label>
                  <div class="imu-upload-dual-input">
                    <input type="file"
                           name="mp3_file"
                           class="imu-input"
                           accept=".mp3,.wav,.flac,.aac,audio/*">
                    <input type="url"
                           name="mp3_url"
                           class="imu-input"
                           placeholder="https://drive.google.com/... or other audio storage link">
                  </div>
                  <small>Upload up to 400MB OR paste a storage link if your file is larger.</small>
                </div>
                <div class="imu-field">
                  <label class="imu-help-wrapper">
                    Cover Art upload <span style="color:#e88">*</span>
                    <span class="imu-help-icon" aria-label="More info" tabindex="0">?</span>
                    <span class="imu-help-tooltip">
                      The optimal size for IMUTV Cover Art is 1080x1080
                    </span>
                  </label>
                  <input type="file" name="cover_art_file" class="imu-input" accept="image/*">
                  <small>Required for audio submissions. Max file size 400MB.</small>
                </div>
              </div>
            </div>
            </section>

          <!-- Terms / Submit -->
          <section class="imu-form-section">
            <h3>Terms and Submit</h3>
            <div class="imu-terms-inner">
              <div class="imu-field">
                <label>
                  <input type="checkbox" name="accept_terms" value="1" required>
                  I have read and accept the
                  <a href="<?php echo esc_url( home_url('/terms') ); ?>">Terms and Conditions</a>
                  and
                  <a href="<?php echo esc_url( home_url('/Privacy') ); ?>">Privacy Policy</a>
                </label>
              </div>
              <div class="imu-field">
                <label>
                  <input type="checkbox" name="attest_rights" value="1" required>
                  I attest I have the proper legal rights and releases to use all submitted music and content
                </label>
              </div>
            </div>
          </section>

          <!-- Step 4 Navigation (Final) -->
          <div class="imu-wizard-nav">
            <button type="button" class="imu-btn imu-btn-back" onclick="musicWizardBack(4)">← Back</button>
            <div class="spacer"></div>
            <button class="imu-btn imu-btn-next" type="submit">🚀 Submit Music Submission</button>
          </div>

          </div><!-- End Step 4 -->


        </div>
      </form>

            <!-- =========================
           CREATOR FORM (longform)
           ========================= -->
      <form id="imu-form-creator" class="imu-form" style="display:none;" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
        <input type="hidden" name="action" value="imu_creator_submit">
        <?php wp_nonce_field('imu_creator_submit','imu_creator_nonce'); ?>

        <div class="imu-longform">

          <!-- Header bar -->
          <section class="imu-form-section">
            <div class="imu-form-head">
              <h3>Content Creator Submission</h3>
            </div>
          </section>

          <!-- Wizard Progress Bar -->
          <div class="imu-wizard-progress" id="creator-wizard-progress">
            <div class="imu-progress-step active" data-step="1">
              <div class="imu-step-number"><span>1</span></div>
              <div class="imu-step-label">Personal & Info</div>
            </div>
            <div class="imu-progress-line"></div>
            <div class="imu-progress-step" data-step="2">
              <div class="imu-step-number"><span>2</span></div>
              <div class="imu-step-label">Content Details</div>
            </div>
            <div class="imu-progress-line"></div>
            <div class="imu-progress-step" data-step="3">
              <div class="imu-step-number"><span>3</span></div>
              <div class="imu-step-label">Monetization</div>
            </div>
            <div class="imu-progress-line"></div>
            <div class="imu-progress-step" data-step="4">
              <div class="imu-step-number"><span>4</span></div>
              <div class="imu-step-label">Upload & Submit</div>
            </div>
          </div>

          <!-- Error display area -->
          <div id="creator-wizard-errors" class="imu-wizard-errors" style="display:none;">
            <h4>⚠️ Please fix the following:</h4>
            <ul id="creator-wizard-error-list"></ul>
          </div>

          <!-- ==================== STEP 1: Personal & Info ==================== -->
          <div class="imu-wizard-step active" data-step="1" id="creator-step-1">
            
            <div class="imu-step-header">
              <h2>👤 Personal & Creator Info</h2>
              <p>Tell us about yourself and your creative work</p>
            </div>

            <?php if (!empty($prefill_creator)): ?>
            <div class="imu-prefill-notice">
              <div class="icon">✨</div>
              <div class="text">
                <strong>Welcome back!</strong><br>
                <span>We've pre-filled your details from your last submission. Feel free to update anything.</span>
              </div>
            </div>
            <?php endif; ?>

          <!-- Basic Information -->
          <section class="imu-form-section">
            <h3>Basic Information</h3>
            <div class="grid-2">
              <div class="imu-field">
                <label>Email Address <span style="color:#e88">*</span></label>
                <input type="email" name="email" class="imu-input" required value="<?php echo esc_attr($prefill_email); ?>">
              </div>
              <!-- Auto-Registration Fields (Creator) -->
              <div class="imu-field full imu-registration-wrapper" data-form="creator">
                <div class="imu-email-status" id="creator-email-status" style="display:none;"></div>
                <div class="imu-registration-fields" id="creator-registration-fields" style="display:none;">
                  <h4>🔐 Create Your IMUTV Account</h4>
                  <p>This email isn't registered yet. Create a password to set up your account when you submit.</p>
                  <div class="reg-grid">
                    <div class="imu-field">
                      <label>Create Password <span style="color:#e88">*</span></label>
                      <input type="password" name="create_password" id="creator-create-password" class="imu-input" minlength="8" autocomplete="new-password">
                      <div class="imu-password-strength"><div class="imu-password-strength-bar" id="creator-pw-strength"></div></div>
                      <small style="color:#888;">Minimum 8 characters</small>
                    </div>
                    <div class="imu-field">
                      <label>Confirm Password <span style="color:#e88">*</span></label>
                      <input type="password" name="confirm_password" id="creator-confirm-password" class="imu-input" minlength="8" autocomplete="new-password">
                      <div class="imu-password-match" id="creator-pw-match">Passwords must match</div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="imu-field">
                <label>Creator Name <span style="color:#e88">*</span></label>
                <input type="text" name="creator_name" class="imu-input" required value="<?php echo imu_prefill($prefill_creator, 'creator_name'); ?>">
              </div>

              <div class="imu-field">
                <label>First Name <span style="color:#e88">*</span></label>
                <input type="text" name="first_name" class="imu-input" required value="<?php echo imu_prefill($prefill_creator, 'first_name'); ?>">
              </div>
              <div class="imu-field">
                <label>Last Name <span style="color:#e88">*</span></label>
                <input type="text" name="last_name" class="imu-input" required value="<?php echo imu_prefill($prefill_creator, 'last_name'); ?>">
              </div>

              <div class="imu-field">
                <label>Type of Content <span style="color:#e88">*</span></label>
                <input type="text" name="content_type" class="imu-input" required placeholder="e.g., interview, short film, doc, show episode" value="<?php echo imu_prefill($prefill_creator, 'content_type'); ?>">
              </div>
              <div class="imu-field">
                <label>Location</label>
                <input type="text" name="location" class="imu-input" value="<?php echo imu_prefill($prefill_creator, 'location'); ?>">
              </div>

              <div class="imu-field">
                <label>Website</label>
                <input type="text" name="website" class="imu-input" placeholder="https://www.example.com or www.example.com" value="<?php echo imu_prefill($prefill_creator, 'website'); ?>">
              </div>

              <div class="imu-field full">
                <label>Creator Bio <span style="color:#e88">*</span></label>
                <textarea name="creator_bio" class="imu-textarea" required><?php echo imu_prefill($prefill_creator, 'creator_bio'); ?></textarea>
              </div>
            </div>
          </section>

          <!-- Social Media -->
          <section class="imu-form-section">
            <h3>Social Media</h3>
            <?php $prefill_creator_social = imu_prefill_json($prefill_creator, 'social'); ?>
            <div class="grid-2">
              <div class="imu-field"><label>X</label><input type="url" name="social[x]" class="imu-input" placeholder="https://x.com/..." value="<?php echo esc_attr($prefill_creator_social['x'] ?? ''); ?>"></div>
              <div class="imu-field"><label>Instagram</label><input type="url" name="social[instagram]" class="imu-input" placeholder="https://instagram.com/..." value="<?php echo esc_attr($prefill_creator_social['instagram'] ?? ''); ?>"></div>
              <div class="imu-field"><label>TikTok</label><input type="url" name="social[tiktok]" class="imu-input" placeholder="https://tiktok.com/@..." value="<?php echo esc_attr($prefill_creator_social['tiktok'] ?? ''); ?>"></div>
            </div>

            <div id="creator-social-wrap" class="grid-2"></div>
            <div class="imu-actions" style="justify-content:flex-start;margin-top:8px;">
              <button class="imu-btn" type="button" data-add-creator-social>Add More</button>
            </div>
          </section>

          <!-- Step 1 Navigation -->
          <div class="imu-wizard-nav">
            <div class="spacer"></div>
            <button type="button" class="imu-btn imu-btn-next" onclick="creatorWizardNext(1)">Next: Content Details →</button>
          </div>

          </div><!-- End Creator Step 1 -->

          <!-- ==================== STEP 2: Content Details ==================== -->
          <div class="imu-wizard-step" data-step="2" id="creator-step-2">
            
            <div class="imu-step-header">
              <h2>🎬 Content Details</h2>
              <p>Tell us about your content, music usage, and any AI involvement</p>
            </div>

          <!-- Content Details -->
          <section class="imu-form-section">
            <h3>Content Details</h3>
            <div class="grid-2">
              <div class="imu-field">
                <label>Content Name <span style="color:#e88">*</span></label>
                <input type="text" name="content_name" class="imu-input" required>
              </div>
              <div class="imu-field">
                <label>Host</label>
                <input type="text" name="host" class="imu-input">
              </div>

              <div class="imu-field">
                <label>Guests</label>
                <input type="text" name="guests" class="imu-input" placeholder="Comma-separated">
              </div>
              <div class="imu-field">
                <label>Editor</label>
                <input type="text" name="editor" class="imu-input">
              </div>
            </div>

            <!-- Contributors (repeatable) -->
            <div id="creator-contrib-wrap" class="grid-2">
              <div class="imu-field">
                <label>Contributor Name</label>
                <input type="text" name="contributors[0][name]" class="imu-input">
              </div>
              <div class="imu-field">
                <label>Contributor Role</label>
                <input type="text" name="contributors[0][role]" class="imu-input">
              </div>
            </div>
            <div class="imu-actions" style="justify-content:flex-start;margin-top:8px;">
              <button class="imu-btn" type="button" data-add-creator-contrib>Add More</button>
            </div>

            <!-- Content Description -->
<div class="imu-field full" style="margin-top:16px;">
  <label>Content Description <span style="color:#e88">*</span></label>
  <textarea name="content_description"
            class="imu-textarea"
            required
            placeholder="Describe the concept, format, and target audience for this content."></textarea>
</div>

            <!-- Music Usage Section -->
            <div class="imu-field full" style="margin-top:20px;">
              <label>Was Music played in this content? <span style="color:#e88">*</span></label>
              <select name="music_played" id="creator-music-played" class="imu-input" required>
                <option value="">Select...</option>
                <option value="no">No</option>
                <option value="yes">Yes</option>
              </select>
            </div>

            <!-- Music Details - shown when Yes selected -->
            <div id="creator-music-details" class="imu-music-usage-fields" style="display:none;">
              <div class="imu-field full">
                <label>Was the music... <span style="color:#e88">*</span></label>
                <select name="music_type" id="creator-music-type" class="imu-input">
                  <option value="">Select...</option>
                  <option value="royalty_free">Royalty Free</option>
                  <option value="licensed">Licensed</option>
                  <option value="public_domain">Public Domain</option>
                  <option value="permission_obtained">Permission Obtained</option>
                  <option value="original">Original</option>
                </select>
              </div>

              <!-- Music fields - shown for all music types -->
              <div id="creator-music-fields" class="imu-music-type-fields" style="display:none;">
                <div class="grid-2">
                  <div class="imu-field">
                    <label>Track Title <span style="color:#e88">*</span></label>
                    <input type="text" name="music_track_title" id="creator-music-track-title" class="imu-input">
                  </div>
                  <div class="imu-field">
                    <label>Artist Name <span style="color:#e88">*</span></label>
                    <input type="text" name="music_artist_name" id="creator-music-artist-name" class="imu-input">
                  </div>
                </div>

                <!-- Proof upload - shown only for Licensed and Permission Obtained -->
                <div id="creator-music-proof-wrap" class="imu-field full" style="display:none;margin-top:12px;">
                  <label id="creator-music-proof-label">Proof of License <span style="color:#e88">*</span></label>
                  <input type="file" name="music_proof_file" id="creator-music-proof-file" class="imu-input" accept=".pdf,application/pdf">
                  <small>Upload a PDF document proving license or permission.</small>
                </div>
              </div>
            </div>

            <!-- Territory Authorization -->
            <div class="imu-territory-section" style="margin-top:20px;">
              <div class="imu-territory-header">
                <label style="display:flex;align-items:center;gap:10px;">
                  <input type="checkbox" name="territory_worldwide" id="creator-territory-worldwide" checked>
                  <span><strong>Content is authorized to go worldwide</strong></span>
                </label>
              </div>
              <div id="creator-territory-exclusions" style="display:none;">
                <p style="margin:0 0 12px;color:#aaa;font-size:0.9rem;">Add countries where this content is NOT authorized:</p>
                <div class="imu-country-dropdown" id="creator-country-dropdown">
                  <button type="button" class="imu-country-dropdown-btn" onclick="toggleCountryDropdown('creator-country-dropdown')">
                    ➕ Add Exclusion
                  </button>
                  <div class="imu-country-dropdown-menu">
                    <div class="imu-country-search">
                      <input type="text" placeholder="Search countries..." onkeyup="filterCountries(this, 'creator-country-dropdown')">
                    </div>
                    <div class="imu-country-list" data-target="creator"></div>
                  </div>
                </div>
                <div class="imu-exclusions-list" id="creator-exclusions-list"></div>
                <input type="hidden" name="territory_exclusions" id="creator-territory-exclusions-input" value="">
              </div>
            </div>
          </section>

          <!-- NEW AI Input -->
          <section class="imu-form-section">
            <h3>AI Input</h3>
            <div class="grid-2">
              <div class="imu-field full">
                <label>Was ANY of the content made with AI? <span style="color:#e88">*</span></label>
                <select name="ai_content" class="imu-input" required>
                  <option value="">Select...</option>
                  <option value="no">No</option>
                  <option value="yes">Yes</option>
                </select>
              </div>

              <div class="imu-field full" id="ai-content-form" style="display:none;">
                <div class="grid-2">
                  <div class="imu-field">
                    <label>What platform was used? <span style="color:#e88">*</span></label>
                    <input type="text" name="ai_content_platform" class="imu-input">
                  </div>
                  <div class="imu-field">
                    <label>What date was the video created? <span style="color:#e88">*</span></label>
                    <input type="date" name="ai_content_date" class="imu-input">
                  </div>
                </div>

                <div class="grid-2">
                  <div class="imu-field">
                    <label>Do you have commercial rights for this video? <span style="color:#e88">*</span></label>
                    <div class="imu-radio-group">
                      <label><input type="radio" name="ai_content_rights" value="yes"> Yes</label>
                      <label><input type="radio" name="ai_content_rights" value="no"> No</label>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </section>

          <!-- Step 2 Navigation -->
          <div class="imu-wizard-nav">
            <button type="button" class="imu-btn imu-btn-back" onclick="creatorWizardBack(2)">← Back</button>
            <div class="spacer"></div>
            <button type="button" class="imu-btn imu-btn-next" onclick="creatorWizardNext(2)">Next: Monetization →</button>
          </div>

          </div><!-- End Creator Step 2 -->

          <!-- ==================== STEP 3: Monetization ==================== -->
          <div class="imu-wizard-step" data-step="3" id="creator-step-3">
            
            <div class="imu-step-header">
              <h2>💰 Monetization</h2>
              <p>Set up how you want to earn from your content</p>
            </div>

          <!-- Content Monetization (CREATOR) -->
          <section class="imu-form-section">
            <h3>Content Monetization</h3>
            <div class="grid-2">
              <div class="imu-field full">
                <label>Do you want this content to be locked or public? <span style="color:#e88">*</span></label>
                <select name="content_lock" class="imu-input" required>
                  <option value="">Select...</option>
                  <option value="public">Public</option>
                  <option value="locked">Locked</option>
                </select>
              </div>

              <div class="imu-field full" id="creator-locked-options" style="display:none;">
                
                <!-- Info Box -->
                <div class="imu-message-box" style="background:#000;color:var(--imu-gold);padding:12px;margin-bottom:16px;border:1px solid var(--imu-gold);border-radius:6px;">
                  <strong>💰 Monetize Your Content</strong><br>
                  <span style="color:#ccc;">Accept payments via traditional Stripe checkout (-5% fee) OR XRPL tokens (Fee Free!)</span><br><br>
                  <span style="color:#fff;">Purchases unlock content on IMUTV + IMUP3!</span>
                </div>

                <!-- USD Price -->
                <div class="imu-field">
                  <label>USD Price (Stripe Checkout)</label>
                  <input type="number" name="usd_price" class="imu-input" step="0.01" min="0.50" placeholder="e.g. 5.99">
                  <small style="color:#888;">This is your base price. Card payments charge this amount.</small>
                </div>

                <!-- XRPL Wallet -->
                <div class="imu-field">
                  <label>Your XRPL Wallet Address</label>
                  <input type="text" name="xrp_wallet" class="imu-input" placeholder="rXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX">
                  <small style="color:#888;">Web3 payments go directly to this wallet.</small>
                </div>

                <!-- Pricing Mode Toggle -->
                <div class="imu-field full" style="margin-top:20px;">
                  <label style="font-weight:600;color:var(--imu-gold);margin-bottom:12px;display:block;">Web3 Pricing Mode</label>
                  <div class="imu-pricing-mode-toggle">
                    <label class="imu-radio-card">
                      <input type="radio" name="pricing_mode" value="dynamic" checked style="margin-right:8px;">
                      <strong style="color:#fff;">Dynamic Pricing</strong>
                      <span style="color:#39d353;font-size:12px;display:block;margin-top:4px;">✨ RECOMMENDED</span>
                      <p style="margin:8px 0 0;font-size:13px;color:#aaa;">Auto-calculates token amounts from USD price. Set discounts to incentivize specific tokens!</p>
                    </label>
                    <label class="imu-radio-card">
                      <input type="radio" name="pricing_mode" value="static" style="margin-right:8px;">
                      <strong style="color:#fff;">Static Pricing</strong>
                      <p style="margin:8px 0 0;font-size:13px;color:#aaa;">Manually set fixed token amounts. Prices won't change with market rates.</p>
                    </label>
                  </div>
                </div>

                <!-- DYNAMIC PRICING SECTION -->
                <div id="creator-dynamic-pricing" style="margin-top:20px;">
                  
                  <!-- How It Works -->
                  <div class="imu-info-card" style="background:linear-gradient(135deg, rgba(57,211,83,0.1) 0%, rgba(0,0,0,0.4) 100%);border:1px solid rgba(57,211,83,0.3);">
                    <h4 style="color:#39d353;">📊 How Dynamic Pricing Works</h4>
                    <ul style="color:#ccc;">
                      <li>Token prices fetched in <strong style="color:#fff;">real-time</strong> from XRPL DEX + AMM pools</li>
                      <li>Buyers see <strong style="color:#fff;">live token amounts</strong> based on current market rates</li>
                      <li>Set <strong style="color:#39d353;">discounts</strong> to incentivize payments in specific tokens</li>
                    </ul>
                  </div>

                  <!-- Token Selection -->
                  <div class="imu-field full">
                    <label style="font-weight:600;margin-bottom:12px;display:block;">Select Accepted Tokens & Discounts</label>
                    <div class="imu-token-selector" id="creator-token-selector">
                      
                      <!-- Core Tokens (XRP, RLUSD, XFT) - Always Displayed -->
                      <?php foreach ($imu_core_tokens_ordered as $token): ?>
                        <?php echo imu_render_token_row($token, true, false); ?>
                      <?php endforeach; ?>
                      
                      <!-- Additional Tokens Container -->
                      <div class="imu-additional-tokens" id="creator-additional-tokens"></div>
                      
                      <!-- Add More Tokens Dropdown -->
                      <?php if (!empty($imu_additional_tokens)): ?>
                      <div class="imu-token-add-section">
                        <div class="imu-token-add-header">
                          <label>Accept more XRPL tokens?</label>
                          <div class="imu-token-dropdown" id="creator-token-dropdown">
                            <button type="button" class="imu-token-dropdown-btn" onclick="toggleTokenDropdown('creator-token-dropdown')">
                              <span>➕ Add Token</span>
                              <span style="font-size:10px;">▼</span>
                            </button>
                            <div class="imu-token-dropdown-menu">
                              <?php foreach ($imu_additional_tokens as $token): 
                                $ticker = esc_attr($token['ticker']);
                                $display = esc_html($token['display_name'] ?? $token['ticker']);
                                $desc = esc_html($token['description'] ?? '');
                                $logo = esc_url($token['logo_url'] ?? '');
                                $issuer = esc_attr($token['issuer'] ?? '');
                                $trustline = esc_url($token['trustline_url'] ?? '');
                                $discount = (int)($token['default_discount'] ?? 0);
                              ?>
                              <div class="imu-token-dropdown-item" 
                                   data-ticker="<?php echo $ticker; ?>"
                                   data-issuer="<?php echo $issuer; ?>"
                                   data-display="<?php echo $display; ?>"
                                   data-desc="<?php echo $desc; ?>"
                                   data-logo="<?php echo $logo; ?>"
                                   data-trustline="<?php echo $trustline; ?>"
                                   data-discount="<?php echo $discount; ?>"
                                   onclick="addTokenToSelector(this, 'creator-additional-tokens')">
                                <?php if ($logo): ?>
                                  <img src="<?php echo $logo; ?>" alt="<?php echo $ticker; ?>">
                                <?php else: ?>
                                  <div class="token-logo-placeholder"><?php echo substr($ticker, 0, 3); ?></div>
                                <?php endif; ?>
                                <div class="token-name">
                                  <strong><?php echo $ticker; ?></strong>
                                  <span><?php echo $desc ?: $display; ?></span>
                                </div>
                                <span class="added-check" style="display:none;">✓</span>
                              </div>
                              <?php endforeach; ?>
                            </div>
                          </div>
                        </div>
                      </div>
                      <?php endif; ?>
                      
                    </div>
                    <small style="color:#888;display:block;margin-top:8px;">Enable tokens you want to accept. Set discounts (0-99%) to incentivize specific payment methods. Click 🔗 to set trustlines.</small>
                  </div>

                  <!-- Trustline Info -->
                  <div class="imu-info-card" style="background:linear-gradient(135deg, rgba(var(--imu-gold-rgb), 0.1) 0%, rgba(0,0,0,0.4) 100%);border:1px solid rgba(var(--imu-gold-rgb), 0.3);margin-top:16px;">
                    <h4 style="color:var(--imu-gold);">🔗 About Trustlines</h4>
                    <p style="color:#ccc;">To receive non-native XRPL tokens, your wallet needs a <strong style="color:#fff;">trustline</strong> for each token. Click the <span style="color:#39d353;">🔗 Trustline</span> button next to any token to set it up via xrpl.services.</p>
                  </div>
                </div>

                <!-- STATIC PRICING SECTION -->
                <div id="creator-static-pricing" style="display:none;margin-top:20px;">
                  <div class="imu-info-card" style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.1);">
                    <h4 style="color:#fff;">⚠️ Static Pricing Mode</h4>
                    <p style="color:#aaa;">You're setting fixed token amounts. These prices <strong style="color:#fff;">won't change</strong> even if market rates fluctuate.</p>
                  </div>

                  <div class="imu-field">
                    <label>$XRP Price (Static)</label>
                    <input type="number" name="xrp_price" class="imu-input" step="0.000001" min="0" placeholder="e.g. 15">
                  </div>
                  <div class="imu-field">
                    <label>$RLUSD Price (Static)</label>
                    <input type="number" name="rlusd_price" class="imu-input" step="0.000001" min="0" placeholder="e.g. 5">
                  </div>
                  <div class="imu-field">
                    <label>$XFT Price (Static)</label>
                    <input type="number" name="xft_price" class="imu-input" step="0.000001" min="0" placeholder="e.g. 100">
                  </div>

                  <div id="creator-custom-token-wrap">
                    <label style="font-weight:600;margin-bottom:8px;display:block;">Custom Token (Optional)</label>
                    <div class="custom-token-row grid-3">
                      <div class="imu-field">
                        <label>Ticker</label>
                        <input type="text" name="custom_tokens[0][ticker]" class="imu-input" placeholder="e.g. MYTOKEN">
                      </div>
                      <div class="imu-field">
                        <label>Issuer Address</label>
                        <input type="text" name="custom_tokens[0][issuer]" class="imu-input" placeholder="r...">
                      </div>
                      <div class="imu-field">
                        <label>Price</label>
                        <input type="number" name="custom_tokens[0][price]" class="imu-input" step="0.000001" min="0">
                      </div>
                    </div>
                  </div>
                  <div class="imu-actions" style="justify-content:flex-start;margin-top:8px;">
                    <button class="imu-btn" type="button" data-add-custom-token data-form="creator">+ Add Custom Token</button>
                  </div>
                </div>

              </div>
            </div>
          </section>
          
          <!-- ==================== NFT ACCESS SECTION (CREATOR) ==================== -->
          <!-- FIX: Added Yes/No toggle question, fields only required when Yes selected -->
          <section class="imu-form-section">
            <h3>NFT Access</h3>
            
            <div class="imu-message-box" style="background:#000;color:var(--imu-gold);padding:12px;margin-bottom:16px;border:1px solid var(--imu-gold);border-radius:6px;">
              <strong>Allow NFT Holders free access to your content!</strong><br>
              <span style="color:#fff;">Simply enter the collection or issuer details, and holders of those NFTs will have free access to your content!</span>
            </div>

            <!-- NEW: Yes/No toggle question -->
            <div class="grid-2">
              <div class="imu-field full">
                <label>Do you want to allow NFT Holders free access to your content? <span style="color:#e88">*</span></label>
                <select name="nft_access_enabled" id="creator-nft-access-toggle" class="imu-input" required>
                  <option value="">Select...</option>
                  <option value="no">No</option>
                  <option value="yes">Yes</option>
                </select>
              </div>
            </div>

            <!-- NFT Gate fields - hidden by default, shown when Yes selected -->
            <div id="creator-nft-gate-fields" style="display:none;margin-top:16px;">
              <p style="margin:8px 0 16px;color:#ccc;font-size:0.95em;">
                Grant free access to owners of these NFT collections (any one collection qualifies).
              </p>

              <div id="nft-gate-container-creator">
                <div class="nft-gate-row grid-3" style="margin-bottom:12px;padding:12px;background:rgba(255,255,255,.03);border-radius:8px;">
                  <div class="imu-field">
                    <label>Collection Title (optional)</label>
                    <input type="text" name="nft_gate[0][title]" class="imu-input" placeholder="e.g., Founders Pass">
                  </div>
                  <div class="imu-field">
                    <label>Issuer Address <span style="color:#e88">*</span></label>
                    <!-- FIX: NOT required by default, JS will toggle based on Yes/No -->
                    <input type="text" name="nft_gate[0][issuer]" class="imu-input nft-issuer-field" placeholder="r123...">
                  </div>
                  <div class="imu-field">
                    <label>Taxon (leave blank = any)</label>
                    <input type="number" name="nft_gate[0][taxon]" class="imu-input" placeholder="Any">
                  </div>
                  <div class="imu-field">
                    <label>Min Required</label>
                    <input type="number" name="nft_gate[0][min]" class="imu-input" value="1" min="1">
                  </div>
                </div>
              </div>

              <div class="imu-actions" style="justify-content:flex-start;margin-top:8px;">
                <button class="imu-btn" type="button" data-add-nft-gate data-form="creator">+ Add Another Collection</button>
              </div>
            </div>
          </section>

          <!-- Step 3 Navigation -->
          <div class="imu-wizard-nav">
            <button type="button" class="imu-btn imu-btn-back" onclick="creatorWizardBack(3)">← Back</button>
            <div class="spacer"></div>
            <button type="button" class="imu-btn imu-btn-next" onclick="creatorWizardNext(3)">Next: Upload & Submit →</button>
          </div>

          </div><!-- End Creator Step 3 -->

          <!-- ==================== STEP 4: Upload & Submit ==================== -->
          <div class="imu-wizard-step" data-step="4" id="creator-step-4">
            
            <div class="imu-step-header">
              <h2>📤 Upload & Submit</h2>
              <p>Upload your content and submit for review</p>
            </div>

          <!-- Upload Content -->
          <section class="imu-form-section">
            <h3>Upload Content</h3>
            <div class="grid-2">
              <div class="imu-field">
                <label>Content upload <span style="color:#e88">*</span></label>
                <div class="imu-upload-dual-input">
                  <input type="file" name="content_file" class="imu-input">
                  <input type="url"
                         name="content_url"
                         class="imu-input"
                         placeholder="https://drive.google.com/... or other storage link">
                </div>
                <small>Upload up to 400MB OR paste a storage link if your file is larger.</small>
              </div>
              <div class="imu-field">
                <label>Thumbnail upload <span style="color:#e88">*</span></label>
                <input type="file" name="thumb_file" class="imu-input" required>
                <small>Max file size 400MB.</small>
              </div>
            </div>
          </section>


          <!-- Terms / Submit -->
          <section class="imu-form-section">
            <h3>Terms and Submit</h3>
            <div class="imu-terms-inner">
              <div class="imu-field">
                <label>
                  <input type="checkbox" name="accept_terms" value="1" required>
                  I have read and accept the
                  <a href="<?php echo esc_url( home_url('/terms') ); ?>">Terms and Conditions</a>
                  and
                  <a href="<?php echo esc_url( home_url('/Privacy') ); ?>">Privacy Policy</a>
                </label>
              </div>
              <div class="imu-field">
                <label>
                  <input type="checkbox" name="attest_rights" value="1" required>
                  I attest I have the proper legal rights and releases to use all submitted music and content
                </label>
              </div>
            </div>
          </section>

          <!-- Step 4 Navigation (Final) -->
          <div class="imu-wizard-nav">
            <button type="button" class="imu-btn imu-btn-back" onclick="creatorWizardBack(4)">← Back</button>
            <div class="spacer"></div>
            <button class="imu-btn imu-btn-next" type="submit">🚀 Submit Creator Submission</button>
          </div>

          </div><!-- End Creator Step 4 -->


        </div>
      </form>
      
                 <!-- =========================
           LIVE STREAMER FORM — FINAL WITH HOST TYPE + MULTI-DATE EVENTS
           ========================= -->
      <form id="imu-form-streamer" class="imu-form" style="display:none;" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
        <input type="hidden" name="action" value="imu_streamer_submit">
        <?php wp_nonce_field('imu_streamer_submit','imu_streamer_nonce'); ?>

        <div class="imu-longform">

          <!-- Header bar -->
          <section class="imu-form-section">
            <div class="imu-form-head">
              <h3>Live Streamer Submission</h3>
            </div>
          </section>

          <!-- Wizard Progress Bar -->
          <div class="imu-wizard-progress" id="streamer-wizard-progress">
            <div class="imu-progress-step active" data-step="1">
              <div class="imu-step-number"><span>1</span></div>
              <div class="imu-step-label">Personal & Info</div>
            </div>
            <div class="imu-progress-line"></div>
            <div class="imu-progress-step" data-step="2">
              <div class="imu-step-number"><span>2</span></div>
              <div class="imu-step-label">Content Examples</div>
            </div>
            <div class="imu-progress-line"></div>
            <div class="imu-progress-step" data-step="3">
              <div class="imu-step-number"><span>3</span></div>
              <div class="imu-step-label">Monetization</div>
            </div>
            <div class="imu-progress-line"></div>
            <div class="imu-progress-step" data-step="4">
              <div class="imu-step-number"><span>4</span></div>
              <div class="imu-step-label">Submit</div>
            </div>
          </div>

          <!-- Error display area -->
          <div id="streamer-wizard-errors" class="imu-wizard-errors" style="display:none;">
            <h4>⚠️ Please fix the following:</h4>
            <ul id="streamer-wizard-error-list"></ul>
          </div>

          <!-- ==================== STEP 1: Personal & Info ==================== -->
          <div class="imu-wizard-step active" data-step="1" id="streamer-step-1">
            
            <div class="imu-step-header">
              <h2>👤 Personal & Streamer Info</h2>
              <p>Tell us about yourself and your streaming content</p>
            </div>

            <?php if (!empty($prefill_streamer)): ?>
            <div class="imu-prefill-notice">
              <div class="icon">✨</div>
              <div class="text">
                <strong>Welcome back!</strong><br>
                <span>We've pre-filled your details from your last submission. Feel free to update anything.</span>
              </div>
            </div>
            <?php endif; ?>

          <!-- Basic Information -->
          <section class="imu-form-section">
            <h3>Basic Information</h3>
            <div class="grid-2">
              <div class="imu-field">
                <label>Email Address <span style="color:#e88">*</span></label>
                <input type="email" name="email" class="imu-input" required value="<?php echo esc_attr($prefill_email); ?>">
              </div>
              <!-- Auto-Registration Fields (Streamer) -->
              <div class="imu-field full imu-registration-wrapper" data-form="streamer">
                <div class="imu-email-status" id="streamer-email-status" style="display:none;"></div>
                <div class="imu-registration-fields" id="streamer-registration-fields" style="display:none;">
                  <h4>🔐 Create Your IMUTV Account</h4>
                  <p>This email isn't registered yet. Create a password to set up your account when you submit.</p>
                  <div class="reg-grid">
                    <div class="imu-field">
                      <label>Create Password <span style="color:#e88">*</span></label>
                      <input type="password" name="create_password" id="streamer-create-password" class="imu-input" minlength="8" autocomplete="new-password">
                      <div class="imu-password-strength"><div class="imu-password-strength-bar" id="streamer-pw-strength"></div></div>
                      <small style="color:#888;">Minimum 8 characters</small>
                    </div>
                    <div class="imu-field">
                      <label>Confirm Password <span style="color:#e88">*</span></label>
                      <input type="password" name="confirm_password" id="streamer-confirm-password" class="imu-input" minlength="8" autocomplete="new-password">
                      <div class="imu-password-match" id="streamer-pw-match">Passwords must match</div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="imu-field">
                <label>Streamer Name <span style="color:#e88">*</span></label>
                <input type="text" name="streamer_name" class="imu-input" required value="<?php echo imu_prefill($prefill_streamer, 'streamer_name'); ?>">
              </div>

              <div class="imu-field">
                <label>First Name <span style="color:#e88">*</span></label>
                <input type="text" name="first_name" class="imu-input" required value="<?php echo imu_prefill($prefill_streamer, 'first_name'); ?>">
              </div>
              <div class="imu-field">
                <label>Last Name <span style="color:#e88">*</span></label>
                <input type="text" name="last_name" class="imu-input" required value="<?php echo imu_prefill($prefill_streamer, 'last_name'); ?>">
              </div>

              <div class="imu-field">
                <label>Location</label>
                <input type="text" name="location" class="imu-input" value="<?php echo imu_prefill($prefill_streamer, 'location'); ?>">
              </div>
              <div class="imu-field">
                <label>Type of Content <span style="color:#e88">*</span>
                <br><small>(e.g., Interviews, Gaming, etc.)</small></label>
                <input type="text" name="content_type" class="imu-input" required value="<?php echo imu_prefill($prefill_streamer, 'content_type'); ?>">
              </div>

              <div class="imu-field">
                <label>Topics of Content <span style="color:#e88">*</span>
                <br><small>(e.g., Web3, Music, Politics, etc.)</small></label>
                <input type="text" name="topics" class="imu-input" required value="<?php echo imu_prefill($prefill_streamer, 'topics'); ?>">
              </div>
              
              <div class="imu-field">
                <label>Website</label>
                <input type="text" name="website" class="imu-input" placeholder="https://www.example.com or www.example.com" value="<?php echo imu_prefill($prefill_streamer, 'website'); ?>">
              </div>

              <div class="imu-field full">
                <label>Creator Bio <span style="color:#e88">*</span></label>
                <textarea name="creator_bio" class="imu-textarea" required><?php echo imu_prefill($prefill_streamer, 'creator_bio'); ?></textarea>
              </div>

              <!-- Are you looking to host -->
              <div class="imu-field full">
                <label>Are you looking to host <span style="color:#e88">*</span></label>
                <div class="imu-checkbox-group" style="display:flex;gap:20px;margin-top:8px;">
                  <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="host_type[]" value="reoccurring" id="host-reoccurring">
                    <span>Reoccurring Show</span>
                  </label>
                  <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="host_type[]" value="oneoff" id="host-oneoff">
                    <span>One-Off Event</span>
                  </label>
                </div>
              </div>

              <!-- REOCCURRING SHOW FIELDS -->
              <div id="reoccurring-fields" style="display:none;margin-top:16px;">

                <!-- NEW: Requested Channel Name (above schedule) -->
<div class="imu-field full">
  <label>Requested Channel Name <span style="color:#e88">*</span></label>
  <input type="text"
         name="reoccurring_channel_name"
         class="imu-input"
         required
         placeholder="e.g., Coffee & Crypto, IMU Live Sessions">
</div>

                <div class="imu-field full">
                  <label>What is the show schedule? <span style="color:#e88">*</span></label>
                  <input type="text"
                         name="reoccurring_schedule"
                         class="imu-input"
                         placeholder="e.g., Daily, Weekly, Every Mon/Wed/Fri">
                </div>
              </div>

              <!-- ONE-OFF EVENT FIELDS -->
              <div id="oneoff-fields" style="display:none;margin-top:16px;">
                <div class="imu-field full">
                  <label>Event Name <span style="color:#e88">*</span></label>
                  <input type="text" name="oneoff_event_name" class="imu-input">
                </div>
                <div class="grid-2">
                  <div class="imu-field">
                    <label>Event Host Name</label>
                    <input type="text" name="oneoff_host_name" class="imu-input">
                  </div>
                  <div class="imu-field">
                    <label>Event Host Contact</label>
                    <input type="email" name="oneoff_host_contact" class="imu-input">
                  </div>
                </div>

                <!-- Multi-date event entries -->
                <div id="oneoff-dates-container">
                  <div class="oneoff-date-entry" style="margin-bottom:12px;padding:12px;background:rgba(255,255,255,.03);border-radius:8px;">
                    <div class="imu-field">
                      <label>Date of Event <span style="color:#e88">*</span></label>
                      <input type="date" name="oneoff_dates[0][date]" class="imu-input">
                    </div>
                  </div>
                </div>

                <div class="imu-actions" style="justify-content:flex-start;margin-top:8px;">
                  <button class="imu-btn" type="button" id="add-oneoff-date">+ Add Another Date</button>
                </div>

                <div class="imu-field full" style="margin-top:16px;">
                  <label>Authority to Broadcast (PDF)</label>
                  <input type="file" name="broadcast_auth[]" class="imu-input" accept=".pdf" multiple>
                  <small>Authority to broadcast will be required with Restricted Events.</small>
                </div>
              </div>
            </div>

            <!-- Territory Authorization -->
            <div class="imu-territory-section" style="margin-top:20px;">
              <div class="imu-territory-header">
                <label style="display:flex;align-items:center;gap:10px;">
                  <input type="checkbox" name="territory_worldwide" id="streamer-territory-worldwide" checked>
                  <span><strong>Content is authorized to go worldwide</strong></span>
                </label>
              </div>
              <div id="streamer-territory-exclusions" style="display:none;">
                <p style="margin:0 0 12px;color:#aaa;font-size:0.9rem;">Add countries where this content is NOT authorized:</p>
                <div class="imu-country-dropdown" id="streamer-country-dropdown">
                  <button type="button" class="imu-country-dropdown-btn" onclick="toggleCountryDropdown('streamer-country-dropdown')">
                    ➕ Add Exclusion
                  </button>
                  <div class="imu-country-dropdown-menu">
                    <div class="imu-country-search">
                      <input type="text" placeholder="Search countries..." onkeyup="filterCountries(this, 'streamer-country-dropdown')">
                    </div>
                    <div class="imu-country-list" data-target="streamer"></div>
                  </div>
                </div>
                <div class="imu-exclusions-list" id="streamer-exclusions-list"></div>
                <input type="hidden" name="territory_exclusions" id="streamer-territory-exclusions-input" value="">
              </div>
            </div>
          </section>

          <!-- Social Media -->
          <section class="imu-form-section">
            <h3>Social Media</h3>
            <?php $prefill_streamer_social = imu_prefill_json($prefill_streamer, 'social'); ?>
            <div class="grid-2">
              <div class="imu-field"><label>X</label><input type="url" name="social[x]" class="imu-input" placeholder="https://x.com/..." value="<?php echo esc_attr($prefill_streamer_social['x'] ?? ''); ?>"></div>
              <div class="imu-field"><label>Instagram</label><input type="url" name="social[instagram]" class="imu-input" placeholder="https://instagram.com/..." value="<?php echo esc_attr($prefill_streamer_social['instagram'] ?? ''); ?>"></div>
              <div class="imu-field"><label>TikTok</label><input type="url" name="social[tiktok]" class="imu-input" placeholder="https://tiktok.com/@..." value="<?php echo esc_attr($prefill_streamer_social['tiktok'] ?? ''); ?>"></div>
            </div>

            <div id="streamer-social-wrap" class="grid-2"></div>
            <div class="imu-actions" style="justify-content:flex-start;margin-top:8px;">
              <button class="imu-btn" type="button" data-add-streamer-social>Add More</button>
            </div>
          </section>

          <!-- Step 1 Navigation -->
          <div class="imu-wizard-nav">
            <div class="spacer"></div>
            <button type="button" class="imu-btn imu-btn-next" onclick="streamerWizardNext(1)">Next: Content Examples →</button>
          </div>

          </div><!-- End Streamer Step 1 -->

          <!-- ==================== STEP 2: Content Examples ==================== -->
          <div class="imu-wizard-step" data-step="2" id="streamer-step-2">
            
            <div class="imu-step-header">
              <h2>🎬 Content Examples</h2>
              <p>Share links to your previous live stream content</p>
            </div>

          <!-- Previous Content -->
          <section class="imu-form-section">
            <h3>Previous Content (Live Stream Examples)</h3>
            <div class="grid-2">
              <div class="imu-field full">
                <label>Live Stream Example 1 (YouTube/Vimeo/Twitch/etc. link) <span style="color:#e88">*</span></label>
                <input type="url" name="example_1" class="imu-input" placeholder="https://" required>
              </div>
              <div class="imu-field full">
                <label>Live Stream Example 2 (optional)</label>
                <input type="url" name="example_2" class="imu-input" placeholder="https://">
              </div>
            </div>

            <div class="imu-message-box" style="background:#000;color:var(--imu-gold);padding:16px;margin-top:20px;">
              <strong>Note:</strong> Uploading past live streams as on-demand content will require you to fill out the "Content Creator" form.
            </div>
          </section>

          <!-- Step 2 Navigation -->
          <div class="imu-wizard-nav">
            <button type="button" class="imu-btn imu-btn-back" onclick="streamerWizardBack(2)">← Back</button>
            <div class="spacer"></div>
            <button type="button" class="imu-btn imu-btn-next" onclick="streamerWizardNext(2)">Next: Monetization →</button>
          </div>

          </div><!-- End Streamer Step 2 -->

          <!-- ==================== STEP 3: Monetization ==================== -->
          <div class="imu-wizard-step" data-step="3" id="streamer-step-3">
            
            <div class="imu-step-header">
              <h2>💰 Monetization</h2>
              <p>Set up how you want to earn from your live streams</p>
            </div>
          
          <!-- Content Monetization (LIVE STREAMER) -->
<section class="imu-form-section">
  <h3>Content Monetization</h3>
  <div class="grid-2">
    <div class="imu-field full">
      <label>Do you want this content to be locked or public? <span style="color:#e88">*</span></label>
      <select name="content_lock" class="imu-input" required>
        <option value="">Select...</option>
        <option value="public">Public</option>
        <option value="locked">Locked</option>
      </select>
    </div>

    <div class="imu-field full" id="streamer-locked-options" style="display:none;">
      
      <!-- Info Box -->
      <div class="imu-message-box" style="background:#000;color:var(--imu-gold);padding:12px;margin-bottom:16px;border:1px solid var(--imu-gold);border-radius:6px;">
        <strong>💰 Monetize Your Channel</strong><br>
        <span style="color:#ccc;">Accept payments via traditional Stripe checkout (-5% fee) OR XRPL tokens (Fee Free!)</span><br><br>
        <span style="color:#fff;">Purchases unlock channel on IMUTV + IMUP3!</span>
      </div>

      <!-- USD Price -->
      <div class="imu-field">
        <label>USD Price (Stripe Checkout)</label>
        <input type="number" name="usd_price" class="imu-input" step="0.01" min="0.50" placeholder="e.g. 5.99">
        <small style="color:#888;">This is your base price. Card payments charge this amount.</small>
      </div>

      <!-- XRPL Wallet -->
      <div class="imu-field">
        <label>Your XRPL Wallet Address</label>
        <input type="text" name="xrp_wallet" class="imu-input" placeholder="rXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX">
        <small style="color:#888;">Web3 payments go directly to this wallet.</small>
      </div>

      <!-- Pricing Mode Toggle -->
      <div class="imu-field full" style="margin-top:20px;">
        <label style="font-weight:600;color:var(--imu-gold);margin-bottom:12px;display:block;">Web3 Pricing Mode</label>
        <div class="imu-pricing-mode-toggle">
          <label class="imu-radio-card">
            <input type="radio" name="pricing_mode" value="dynamic" checked style="margin-right:8px;">
            <strong style="color:#fff;">Dynamic Pricing</strong>
            <span style="color:#39d353;font-size:12px;display:block;margin-top:4px;">✨ RECOMMENDED</span>
            <p style="margin:8px 0 0;font-size:13px;color:#aaa;">Auto-calculates token amounts from USD price. Set discounts to incentivize specific tokens!</p>
          </label>
          <label class="imu-radio-card">
            <input type="radio" name="pricing_mode" value="static" style="margin-right:8px;">
            <strong style="color:#fff;">Static Pricing</strong>
            <p style="margin:8px 0 0;font-size:13px;color:#aaa;">Manually set fixed token amounts. Prices won't change with market rates.</p>
          </label>
        </div>
      </div>

      <!-- DYNAMIC PRICING SECTION -->
      <div id="streamer-dynamic-pricing" style="margin-top:20px;">
        
        <!-- How It Works -->
        <div class="imu-info-card" style="background:linear-gradient(135deg, rgba(57,211,83,0.1) 0%, rgba(0,0,0,0.4) 100%);border:1px solid rgba(57,211,83,0.3);">
          <h4 style="color:#39d353;">📊 How Dynamic Pricing Works</h4>
          <ul style="color:#ccc;">
            <li>Token prices fetched in <strong style="color:#fff;">real-time</strong> from XRPL DEX + AMM pools</li>
            <li>Buyers see <strong style="color:#fff;">live token amounts</strong> based on current market rates</li>
            <li>Set <strong style="color:#39d353;">discounts</strong> to incentivize payments in specific tokens</li>
          </ul>
        </div>

        <!-- Token Selection -->
        <div class="imu-field full">
          <label style="font-weight:600;margin-bottom:12px;display:block;">Select Accepted Tokens & Discounts</label>
          <div class="imu-token-selector" id="streamer-token-selector">
            
            <!-- Core Tokens (XRP, RLUSD, XFT) - Always Displayed -->
            <?php foreach ($imu_core_tokens_ordered as $token): ?>
              <?php echo imu_render_token_row($token, true, false); ?>
            <?php endforeach; ?>
            
            <!-- Additional Tokens Container -->
            <div class="imu-additional-tokens" id="streamer-additional-tokens"></div>
            
            <!-- Add More Tokens Dropdown -->
            <?php if (!empty($imu_additional_tokens)): ?>
            <div class="imu-token-add-section">
              <div class="imu-token-add-header">
                <label>Accept more XRPL tokens?</label>
                <div class="imu-token-dropdown" id="streamer-token-dropdown">
                  <button type="button" class="imu-token-dropdown-btn" onclick="toggleTokenDropdown('streamer-token-dropdown')">
                    <span>➕ Add Token</span>
                    <span style="font-size:10px;">▼</span>
                  </button>
                  <div class="imu-token-dropdown-menu">
                    <?php foreach ($imu_additional_tokens as $token): 
                      $ticker = esc_attr($token['ticker']);
                      $display = esc_html($token['display_name'] ?? $token['ticker']);
                      $desc = esc_html($token['description'] ?? '');
                      $logo = esc_url($token['logo_url'] ?? '');
                      $issuer = esc_attr($token['issuer'] ?? '');
                      $trustline = esc_url($token['trustline_url'] ?? '');
                      $discount = (int)($token['default_discount'] ?? 0);
                    ?>
                    <div class="imu-token-dropdown-item" 
                         data-ticker="<?php echo $ticker; ?>"
                         data-issuer="<?php echo $issuer; ?>"
                         data-display="<?php echo $display; ?>"
                         data-desc="<?php echo $desc; ?>"
                         data-logo="<?php echo $logo; ?>"
                         data-trustline="<?php echo $trustline; ?>"
                         data-discount="<?php echo $discount; ?>"
                         onclick="addTokenToSelector(this, 'streamer-additional-tokens')">
                      <?php if ($logo): ?>
                        <img src="<?php echo $logo; ?>" alt="<?php echo $ticker; ?>">
                      <?php else: ?>
                        <div class="token-logo-placeholder"><?php echo substr($ticker, 0, 3); ?></div>
                      <?php endif; ?>
                      <div class="token-name">
                        <strong><?php echo $ticker; ?></strong>
                        <span><?php echo $desc ?: $display; ?></span>
                      </div>
                      <span class="added-check" style="display:none;">✓</span>
                    </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </div>
            <?php endif; ?>
            
          </div>
          <small style="color:#888;display:block;margin-top:8px;">Enable tokens you want to accept. Set discounts (0-99%) to incentivize specific payment methods. Click 🔗 to set trustlines.</small>
        </div>

        <!-- Trustline Info -->
        <div class="imu-info-card" style="background:linear-gradient(135deg, rgba(var(--imu-gold-rgb), 0.1) 0%, rgba(0,0,0,0.4) 100%);border:1px solid rgba(var(--imu-gold-rgb), 0.3);margin-top:16px;">
          <h4 style="color:var(--imu-gold);">🔗 About Trustlines</h4>
          <p style="color:#ccc;">To receive non-native XRPL tokens, your wallet needs a <strong style="color:#fff;">trustline</strong> for each token. Click the <span style="color:#39d353;">🔗 Trustline</span> button next to any token to set it up via xrpl.services.</p>
        </div>
      </div>

      <!-- STATIC PRICING SECTION -->
      <div id="streamer-static-pricing" style="display:none;margin-top:20px;">
        <div class="imu-info-card" style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.1);">
          <h4 style="color:#fff;">⚠️ Static Pricing Mode</h4>
          <p style="color:#aaa;">You're setting fixed token amounts. These prices <strong style="color:#fff;">won't change</strong> even if market rates fluctuate.</p>
        </div>

        <div class="imu-field">
          <label>$XRP Price (Static)</label>
          <input type="number" name="xrp_price" class="imu-input" step="0.000001" min="0" placeholder="e.g. 15">
        </div>
        <div class="imu-field">
          <label>$RLUSD Price (Static)</label>
          <input type="number" name="rlusd_price" class="imu-input" step="0.000001" min="0" placeholder="e.g. 5">
        </div>
        <div class="imu-field">
          <label>$XFT Price (Static)</label>
          <input type="number" name="xft_price" class="imu-input" step="0.000001" min="0" placeholder="e.g. 100">
        </div>

        <div id="streamer-custom-token-wrap">
          <label style="font-weight:600;margin-bottom:8px;display:block;">Custom Token (Optional)</label>
          <div class="custom-token-row grid-3">
            <div class="imu-field">
              <label>Ticker</label>
              <input type="text" name="custom_tokens[0][ticker]" class="imu-input" placeholder="e.g. MYTOKEN">
            </div>
            <div class="imu-field">
              <label>Issuer Address</label>
              <input type="text" name="custom_tokens[0][issuer]" class="imu-input" placeholder="r...">
            </div>
            <div class="imu-field">
              <label>Price</label>
              <input type="number" name="custom_tokens[0][price]" class="imu-input" step="0.000001" min="0">
            </div>
          </div>
        </div>
        <div class="imu-actions" style="justify-content:flex-start;margin-top:8px;">
          <button class="imu-btn" type="button" data-add-custom-token data-form="streamer">+ Add Custom Token</button>
        </div>
      </div>

    </div>
  </div>
</section>
          
          <!-- ==================== NFT ACCESS SECTION (STREAMER) ==================== -->
          <!-- FIX: Added Yes/No toggle question, fields only required when Yes selected -->
          <section class="imu-form-section">
            <h3>NFT Access</h3>
            
            <div class="imu-message-box" style="background:#000;color:var(--imu-gold);padding:12px;margin-bottom:16px;border:1px solid var(--imu-gold);border-radius:6px;">
              <strong>Allow NFT Holders free access to your channel!</strong><br>
              <span style="color:#fff;">Simply enter the collection or issuer details, and holders of those NFTs will have free access to your channel!</span>
            </div>

            <!-- NEW: Yes/No toggle question -->
            <div class="grid-2">
              <div class="imu-field full">
                <label>Do you want to allow NFT Holders free access to your channel? <span style="color:#e88">*</span></label>
                <select name="nft_access_enabled" id="streamer-nft-access-toggle" class="imu-input" required>
                  <option value="">Select...</option>
                  <option value="no">No</option>
                  <option value="yes">Yes</option>
                </select>
              </div>
            </div>

            <!-- NFT Gate fields - hidden by default, shown when Yes selected -->
            <div id="streamer-nft-gate-fields" style="display:none;margin-top:16px;">
              <p style="margin:8px 0 16px;color:#ccc;font-size:0.95em;">
                Grant free access to owners of these NFT collections (any one collection qualifies).
              </p>

              <div id="nft-gate-container-streamer">
                <div class="nft-gate-row grid-3" style="margin-bottom:12px;padding:12px;background:rgba(255,255,255,.03);border-radius:8px;">
                  <div class="imu-field">
                    <label>Collection Title (optional)</label>
                    <input type="text" name="nft_gate[0][title]" class="imu-input" placeholder="e.g., Founders Pass">
                  </div>
                  <div class="imu-field">
                    <label>Issuer Address <span style="color:#e88">*</span></label>
                    <!-- FIX: NOT required by default, JS will toggle based on Yes/No -->
                    <input type="text" name="nft_gate[0][issuer]" class="imu-input nft-issuer-field" placeholder="r123...">
                  </div>
                  <div class="imu-field">
                    <label>Taxon (leave blank = any)</label>
                    <input type="number" name="nft_gate[0][taxon]" class="imu-input" placeholder="Any">
                  </div>
                  <div class="imu-field">
                    <label>Min Required</label>
                    <input type="number" name="nft_gate[0][min]" class="imu-input" value="1" min="1">
                  </div>
                </div>
              </div>

              <div class="imu-actions" style="justify-content:flex-start;margin-top:8px;">
                <button class="imu-btn" type="button" data-add-nft-gate data-form="streamer">+ Add Another Collection</button>
              </div>
            </div>
          </section>

          <!-- Step 3 Navigation -->
          <div class="imu-wizard-nav">
            <button type="button" class="imu-btn imu-btn-back" onclick="streamerWizardBack(3)">← Back</button>
            <div class="spacer"></div>
            <button type="button" class="imu-btn imu-btn-next" onclick="streamerWizardNext(3)">Next: Submit →</button>
          </div>

          </div><!-- End Streamer Step 3 -->

          <!-- ==================== STEP 4: Submit ==================== -->
          <div class="imu-wizard-step" data-step="4" id="streamer-step-4">
            
            <div class="imu-step-header">
              <h2>✅ Review & Submit</h2>
              <p>Accept the terms and submit your application</p>
            </div>

          <!-- Terms / Submit -->
          <section class="imu-form-section">
            <h3>Terms and Submit</h3>
            <div class="imu-terms-inner">
              <div class="imu-field">
                <label>
                  <input type="checkbox" name="accept_terms" value="1" required>
                  I have read and accept the
                  <a href="<?php echo esc_url( home_url('/terms') ); ?>">Terms and Conditions</a>
                  and
                  <a href="<?php echo esc_url( home_url('/Privacy') ); ?>">Privacy Policy</a>
                </label>
              </div>
              <div class="imu-field">
                <label>
                  <input type="checkbox" name="attest_rights" value="1" required>
                  I attest I have the proper legal rights and releases to use all submitted content and music
                </label>
              </div>
            </div>
          </section>

          <!-- Step 4 Navigation (Final) -->
          <div class="imu-wizard-nav">
            <button type="button" class="imu-btn imu-btn-back" onclick="streamerWizardBack(4)">← Back</button>
            <div class="spacer"></div>
            <button class="imu-btn imu-btn-next" type="submit">🚀 Submit Live Streamer Application</button>
          </div>

          </div><!-- End Streamer Step 4 -->

        </div>
      </form>
    </div>
  </section>
</main>

<script>
  // Shared max upload size: keep JS in sync with PHP constant.
  var IMU_MAX_UPLOAD_BYTES = <?php echo defined('IMU_MAX_UPLOAD_BYTES') ? (int) IMU_MAX_UPLOAD_BYTES : (400 * 1024 * 1024); ?>;

  // Tiny helper to show a toast if available, else fall back to alert.
  function imuUploadShowError(msg) {
    try {
      if (window.IMU_TOAST && typeof window.IMU_TOAST.error === 'function') {
        window.IMU_TOAST.error(msg);
        return;
      }
      if (window.IMU_TOAST && typeof window.IMU_TOAST.show === 'function') {
        window.IMU_TOAST.show(msg, 'error');
        return;
      }
    } catch (e) {
      // ignore toast errors, fall back to alert
    }
    alert(msg);
  }

  // === WIZARD NAVIGATION FOR MUSIC FORM ===
  var musicCurrentStep = 1;
  var musicTotalSteps = 4;

  function musicWizardNext(currentStep) {
    // Validate current step before advancing
    var errors = validateMusicStep(currentStep);
    
    if (errors.length > 0) {
      showMusicWizardErrors(errors);
      return;
    }
    
    hideMusicWizardErrors();
    
    if (currentStep < musicTotalSteps) {
      goToMusicStep(currentStep + 1);
    }
  }

  function musicWizardBack(currentStep) {
    hideMusicWizardErrors();
    if (currentStep > 1) {
      goToMusicStep(currentStep - 1);
    }
  }

  function goToMusicStep(stepNumber) {
    musicCurrentStep = stepNumber;
    
    // Update step visibility
    document.querySelectorAll('#imu-form-music .imu-wizard-step').forEach(function(step) {
      step.classList.remove('active');
    });
    var targetStep = document.getElementById('music-step-' + stepNumber);
    if (targetStep) {
      targetStep.classList.add('active');
    }
    
    // Update progress bar
    document.querySelectorAll('#music-wizard-progress .imu-progress-step').forEach(function(step, index) {
      var stepNum = index + 1;
      step.classList.remove('active', 'completed');
      if (stepNum === stepNumber) {
        step.classList.add('active');
      } else if (stepNum < stepNumber) {
        step.classList.add('completed');
      }
    });
    
    // Update progress lines
    document.querySelectorAll('#music-wizard-progress .imu-progress-line').forEach(function(line, index) {
      var lineNum = index + 1;
      if (lineNum < stepNumber) {
        line.classList.add('completed');
      } else {
        line.classList.remove('completed');
      }
    });
    
    // Scroll to top of form
    var form = document.getElementById('imu-form-music');
    if (form) {
      window.scrollTo({ top: form.offsetTop - 80, behavior: 'smooth' });
    }
  }

  function validateMusicStep(stepNumber) {
    var errors = [];
    var form = document.getElementById('imu-form-music');
    if (!form) return errors;
    
    var stepDiv = document.getElementById('music-step-' + stepNumber);
    if (!stepDiv) return errors;
    
    // Check required fields in current step
    var requiredInputs = stepDiv.querySelectorAll('input[required]:not([type="radio"]):not([type="checkbox"]), textarea[required], select[required]');
    requiredInputs.forEach(function(input) {
      // Skip hidden inputs
      if (input.offsetParent === null && !input.closest('[style*="display: none"]')) {
        // Field is visible, check it
      } else if (input.offsetParent === null) {
        return; // Skip hidden fields
      }
      
      if (!input.value.trim()) {
        var label = input.closest('.imu-field')?.querySelector('label')?.textContent?.replace(/\s*\*\s*$/, '').trim() || input.name;
        errors.push(label + ' is required');
      }
    });
    
    // Check required radio groups in current step
    var radioGroups = {};
    stepDiv.querySelectorAll('input[type="radio"][required]').forEach(function(radio) {
      if (!radioGroups[radio.name]) {
        radioGroups[radio.name] = false;
      }
      if (radio.checked) {
        radioGroups[radio.name] = true;
      }
    });
    
    for (var groupName in radioGroups) {
      if (!radioGroups[groupName]) {
        // Find a label for this group
        var firstRadio = stepDiv.querySelector('input[name="' + groupName + '"]');
        var fieldDiv = firstRadio?.closest('.imu-field');
        var label = fieldDiv?.querySelector('label')?.childNodes[0]?.textContent?.trim() || groupName;
        errors.push('Please select an option for: ' + label.replace(/\s*\*\s*$/, ''));
      }
    }
    
    // Step 2 specific validation - Content type must be selected
    if (stepNumber === 2) {
      var contentTypeSelected = form.querySelector('input[name="music_content_type"]:checked');
      if (!contentTypeSelected) {
        errors.push('Please select a Content Type (Music Video or Audio Only)');
      }
    }
    
    return errors;
  }

  function showMusicWizardErrors(errors) {
    var errorDiv = document.getElementById('music-wizard-errors');
    var errorList = document.getElementById('music-wizard-error-list');
    if (errorDiv && errorList) {
      errorList.innerHTML = errors.map(function(e) { return '<li>' + e + '</li>'; }).join('');
      errorDiv.style.display = 'block';
      errorDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }

  function hideMusicWizardErrors() {
    var errorDiv = document.getElementById('music-wizard-errors');
    if (errorDiv) {
      errorDiv.style.display = 'none';
    }
  }

  // === WIZARD NAVIGATION FOR CREATOR FORM ===
  var creatorCurrentStep = 1;
  var creatorTotalSteps = 4;

  function creatorWizardNext(currentStep) {
    var errors = validateCreatorStep(currentStep);
    
    if (errors.length > 0) {
      showCreatorWizardErrors(errors);
      return;
    }
    
    hideCreatorWizardErrors();
    
    if (currentStep < creatorTotalSteps) {
      goToCreatorStep(currentStep + 1);
    }
  }

  function creatorWizardBack(currentStep) {
    hideCreatorWizardErrors();
    if (currentStep > 1) {
      goToCreatorStep(currentStep - 1);
    }
  }

  function goToCreatorStep(stepNumber) {
    creatorCurrentStep = stepNumber;
    
    document.querySelectorAll('#imu-form-creator .imu-wizard-step').forEach(function(step) {
      step.classList.remove('active');
    });
    var targetStep = document.getElementById('creator-step-' + stepNumber);
    if (targetStep) {
      targetStep.classList.add('active');
    }
    
    document.querySelectorAll('#creator-wizard-progress .imu-progress-step').forEach(function(step, index) {
      var stepNum = index + 1;
      step.classList.remove('active', 'completed');
      if (stepNum === stepNumber) {
        step.classList.add('active');
      } else if (stepNum < stepNumber) {
        step.classList.add('completed');
      }
    });
    
    document.querySelectorAll('#creator-wizard-progress .imu-progress-line').forEach(function(line, index) {
      var lineNum = index + 1;
      if (lineNum < stepNumber) {
        line.classList.add('completed');
      } else {
        line.classList.remove('completed');
      }
    });
    
    var form = document.getElementById('imu-form-creator');
    if (form) {
      window.scrollTo({ top: form.offsetTop - 80, behavior: 'smooth' });
    }
  }

  function validateCreatorStep(stepNumber) {
    var errors = [];
    var form = document.getElementById('imu-form-creator');
    if (!form) return errors;
    
    var stepDiv = document.getElementById('creator-step-' + stepNumber);
    if (!stepDiv) return errors;
    
    var requiredInputs = stepDiv.querySelectorAll('input[required]:not([type="radio"]):not([type="checkbox"]), textarea[required], select[required]');
    requiredInputs.forEach(function(input) {
      if (input.offsetParent === null) return;
      
      if (!input.value.trim()) {
        var label = input.closest('.imu-field')?.querySelector('label')?.textContent?.replace(/\s*\*\s*$/, '').trim() || input.name;
        errors.push(label + ' is required');
      }
    });
    
    return errors;
  }

  function showCreatorWizardErrors(errors) {
    var errorDiv = document.getElementById('creator-wizard-errors');
    var errorList = document.getElementById('creator-wizard-error-list');
    if (errorDiv && errorList) {
      errorList.innerHTML = errors.map(function(e) { return '<li>' + e + '</li>'; }).join('');
      errorDiv.style.display = 'block';
      errorDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }

  function hideCreatorWizardErrors() {
    var errorDiv = document.getElementById('creator-wizard-errors');
    if (errorDiv) {
      errorDiv.style.display = 'none';
    }
  }

  // === WIZARD NAVIGATION FOR STREAMER FORM ===
  var streamerCurrentStep = 1;
  var streamerTotalSteps = 4;

  function streamerWizardNext(currentStep) {
    var errors = validateStreamerStep(currentStep);
    
    if (errors.length > 0) {
      showStreamerWizardErrors(errors);
      return;
    }
    
    hideStreamerWizardErrors();
    
    if (currentStep < streamerTotalSteps) {
      goToStreamerStep(currentStep + 1);
    }
  }

  function streamerWizardBack(currentStep) {
    hideStreamerWizardErrors();
    if (currentStep > 1) {
      goToStreamerStep(currentStep - 1);
    }
  }

  function goToStreamerStep(stepNumber) {
    streamerCurrentStep = stepNumber;
    
    document.querySelectorAll('#imu-form-streamer .imu-wizard-step').forEach(function(step) {
      step.classList.remove('active');
    });
    var targetStep = document.getElementById('streamer-step-' + stepNumber);
    if (targetStep) {
      targetStep.classList.add('active');
    }
    
    document.querySelectorAll('#streamer-wizard-progress .imu-progress-step').forEach(function(step, index) {
      var stepNum = index + 1;
      step.classList.remove('active', 'completed');
      if (stepNum === stepNumber) {
        step.classList.add('active');
      } else if (stepNum < stepNumber) {
        step.classList.add('completed');
      }
    });
    
    document.querySelectorAll('#streamer-wizard-progress .imu-progress-line').forEach(function(line, index) {
      var lineNum = index + 1;
      if (lineNum < stepNumber) {
        line.classList.add('completed');
      } else {
        line.classList.remove('completed');
      }
    });
    
    var form = document.getElementById('imu-form-streamer');
    if (form) {
      window.scrollTo({ top: form.offsetTop - 80, behavior: 'smooth' });
    }
  }

  function validateStreamerStep(stepNumber) {
    var errors = [];
    var form = document.getElementById('imu-form-streamer');
    if (!form) return errors;
    
    var stepDiv = document.getElementById('streamer-step-' + stepNumber);
    if (!stepDiv) return errors;
    
    var requiredInputs = stepDiv.querySelectorAll('input[required]:not([type="radio"]):not([type="checkbox"]), textarea[required], select[required]');
    requiredInputs.forEach(function(input) {
      if (input.offsetParent === null) return;
      
      if (!input.value.trim()) {
        var label = input.closest('.imu-field')?.querySelector('label')?.textContent?.replace(/\s*\*\s*$/, '').trim() || input.name;
        errors.push(label + ' is required');
      }
    });
    
    return errors;
  }

  function showStreamerWizardErrors(errors) {
    var errorDiv = document.getElementById('streamer-wizard-errors');
    var errorList = document.getElementById('streamer-wizard-error-list');
    if (errorDiv && errorList) {
      errorList.innerHTML = errors.map(function(e) { return '<li>' + e + '</li>'; }).join('');
      errorDiv.style.display = 'block';
      errorDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }

  function hideStreamerWizardErrors() {
    var errorDiv = document.getElementById('streamer-wizard-errors');
    if (errorDiv) {
      errorDiv.style.display = 'none';
    }
  }

  // === GLOBAL UPLOAD SUBMISSION OVERLAY ===
  // Creates a full-screen dark overlay with a loading bar when any upload form is submitting.
  document.addEventListener('DOMContentLoaded', function() {
    // 1) Inject minimal CSS for the overlay
    var style = document.createElement('style');
    style.textContent = `
      #imu-upload-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.92);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.25s ease-in-out;
      }
      #imu-upload-overlay.imu-upload-active {
        opacity: 1;
        pointer-events: auto;
      }
      .imu-upload-overlay-inner {
        max-width: 420px;
        width: 90%;
        background: rgba(10, 10, 10, 0.95);
        border-radius: 12px;
        padding: 24px 20px 20px;
        box-shadow: 0 0 30px rgba(0,0,0,0.7);
        text-align: center;
        border: 1px solid rgba(255, 215, 0, 0.35);
        font-family: 'Montserrat', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      }
      .imu-upload-title {
        font-size: 1.2rem;
        font-weight: 700;
        margin-bottom: 12px;
        color: #f7f7f7;
      }
      .imu-upload-text {
        font-size: 0.95rem;
        color: #ddd;
        margin-bottom: 18px;
      }
      .imu-upload-bar {
        position: relative;
        width: 100%;
        height: 10px;
        background: rgba(255, 255, 255, 0.06);
        border-radius: 999px;
        overflow: hidden;
        margin-bottom: 8px;
      }
      .imu-upload-bar-inner {
        position: absolute;
        left: -40%;
        top: 0;
        height: 100%;
        width: 40%;
        background: linear-gradient(90deg, #ffd700, #ffffff, #ffd700);
        animation: imu-upload-bar-anim 1.4s linear infinite;
      }
      .imu-upload-tip {
        font-size: 0.8rem;
        color: #888;
      }
      @keyframes imu-upload-bar-anim {
        0% { transform: translateX(0); }
        100% { transform: translateX(250%); }
      }
    `;
    document.head.appendChild(style);

    // 2) Create the overlay element and append to body
    var overlay = document.createElement('div');
    overlay.id = 'imu-upload-overlay';
    overlay.innerHTML = `
      <div class="imu-upload-overlay-inner">
        <div class="imu-upload-title">Processing Your Submission...</div>
        <div class="imu-upload-text">
          Please do not close this page as we process your submission!
        </div>
        <div class="imu-upload-bar">
          <div class="imu-upload-bar-inner"></div>
        </div>
        <div class="imu-upload-tip">
          Large files (video/audio) can take a minute or two to upload.
        </div>
      </div>
    `;
    document.body.appendChild(overlay);

    // 3) Helper to bind a form so it shows the overlay on successful submit
    function bindUploadOverlay(formId) {
      var form = document.getElementById(formId);
      if (!form) return;

      form.addEventListener('submit', function(e) {
        // If another handler already prevented default (validation error), do nothing.
        if (e.defaultPrevented) {
          return;
        }

        // Disable all submit buttons in this form to prevent double-clicks
        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function(btn) {
          btn.disabled = true;
        });
        // Show overlay
        overlay.classList.add('imu-upload-active');
      });
    }

    // Bind all three upload forms
    bindUploadOverlay('imu-form-music');
    bindUploadOverlay('imu-form-creator');
    bindUploadOverlay('imu-form-streamer');
  });

  // Toggle forms
  document.addEventListener('click', function(e){
    var choice = e.target.closest('[data-upload-choice]');
    if (!choice) return;
    var val = choice.getAttribute('data-upload-choice');
    var music = document.getElementById('imu-form-music');
    var creator = document.getElementById('imu-form-creator');
    var streamer = document.getElementById('imu-form-streamer');

    // Hide all
    music.style.display = creator.style.display = streamer.style.display = 'none';

    // Show selected
    if (val === 'music') music.style.display = 'block';
    if (val === 'creator') creator.style.display = 'block';
    if (val === 'streamer') streamer.style.display = 'block';

    var target = music.offsetTop || creator.offsetTop || streamer.offsetTop;
    window.scrollTo({ top: target - 80, behavior:'smooth' });
  });

  // Add-more helpers (contributors, socials, sample proofs, custom tokens)
  // Generic helper to add repeatable rows with unique [index] names
  function addRow(wrapperId, template) {
    var wrap = document.getElementById(wrapperId);
    if (!wrap) return;

    // Use a per-wrapper index so each new row gets a unique [n]
    var nextIndex = parseInt(wrap.dataset.nextIndex || '1', 10); // 0 is the initial row
    var row = document.createElement('div');
    row.className = template.class;

    // Replace all [0] tokens in the template with [nextIndex]
    row.innerHTML = template.html.replace(/\[0\]/g, '[' + nextIndex + ']');

    wrap.appendChild(row);
    wrap.dataset.nextIndex = String(nextIndex + 1);
  }

  document.addEventListener('click', function(e){
    if (e.target.matches('[data-add-music-contrib]')) {
      addRow('music-contrib-wrap', {
        class: 'grid-2',
        html: '<div class="imu-field"><label>Contributor Name</label><input type="text" name="contributors[0][name]" class="imu-input"></div><div class="imu-field"><label>Contributor Role</label><input type="text" name="contributors[0][role]" class="imu-input"></div>'
      });
    }
    if (e.target.matches('[data-add-creator-contrib]')) {
      addRow('creator-contrib-wrap', {
        class: 'grid-2',
        html: '<div class="imu-field"><label>Contributor Name</label><input type="text" name="contributors[0][name]" class="imu-input"></div><div class="imu-field"><label>Contributor Role</label><input type="text" name="contributors[0][role]" class="imu-input"></div>'
      });
    }
    if (e.target.matches('[data-add-music-social]')) {
      addRow('music-social-wrap', {
        class: 'grid-2',
        html: '<div class="imu-field"><label>Custom Label</label><input type="text" name="social_extra[0][label]" class="imu-input"></div><div class="imu-field"><label>Link URL</label><input type="url" name="social_extra[0][url]" class="imu-input" placeholder="https://"></div>'
      });
    }
    if (e.target.matches('[data-add-creator-social]')) {
      addRow('creator-social-wrap', {
        class: 'grid-2',
        html: '<div class="imu-field"><label>Custom Label</label><input type="text" name="social_extra[0][label]" class="imu-input"></div><div class="imu-field"><label>Link URL</label><input type="url" name="social_extra[0][url]" class="imu-input" placeholder="https://"></div>'
      });
    }
    if (e.target.matches('[data-add-streamer-social]')) {
      addRow('streamer-social-wrap', {
        class: 'grid-2',
        html: '<div class="imu-field"><label>Custom Label</label><input type="text" name="social_extra[0][label]" class="imu-input"></div><div class="imu-field"><label>Link URL</label><input type="url" name="social_extra[0][url]" class="imu-input" placeholder="https://"></div>'
      });
    }
    if (e.target.matches('[data-add-sample-proof]')) {
      var list = document.getElementById('sample-proof-list');
      var input = document.createElement('input');
      input.type = 'file';
      input.name = 'sample_proofs[]';
      input.className = 'imu-input';
      input.accept = '.pdf';
      list.appendChild(input);
    }
    if (e.target.matches('[data-add-custom-token]')) {
      addRow('custom-token-wrap', {
        class: 'custom-token-row grid-3',
        html: '<div class="imu-field"><label>Custom XRPL Token Ticker</label><input type="text" name="custom_tokens[0][ticker]" class="imu-input"></div><div class="imu-field"><label>Custom Token Issuer Address</label><input type="text" name="custom_tokens[0][issuer]" class="imu-input"></div><div class="imu-field"><label>Custom Token Price</label><input type="number" name="custom_tokens[0][price]" class="imu-input" step="0.000001" min="0"></div>'
      });
    }
  });

   // Conditionals for Music Form
  document.getElementById('imu-form-music').addEventListener('change', function(e) {
    // Submitter type → Label / Manager logic
    if (e.target.name === 'submitter_type') {
      var val = e.target.value;
      var labelWrap  = document.getElementById('label-name-wrap');
      var proofWrap  = document.getElementById('proof-agreement-wrap');
      var labelField = this.querySelector('[name="label_name"]');
      var proofField = this.querySelector('[name="proof_agreement[]"]');

      if (labelWrap && labelField) {
        labelWrap.style.display = (val === 'label') ? 'block' : 'none';
        labelField.required     = (val === 'label');
      }
      if (proofWrap && proofField) {
        var needsProof = (val === 'manager' || val === 'label');
        proofWrap.style.display = needsProof ? 'block' : 'none';
        proofField.required     = needsProof;
      }
    }

    // Sample clearance
    if (e.target.name === 'samples_used') {
      var sampleWrap  = document.getElementById('sample-proof-wrap');
      var sampleField = this.querySelector('[name="sample_proofs[]"]');
      var showSample  = (e.target.value === 'yes');

      if (sampleWrap && sampleField) {
        sampleWrap.style.display = showSample ? 'block' : 'none';
        sampleField.required     = showSample;
      }
    }

    // AI MUSIC DETAILS
    if (e.target.name === 'ai_music') {
      var showMusicAI = (e.target.value === 'yes');
      var musicBlock  = document.getElementById('ai-music-form');
      if (musicBlock) {
        musicBlock.style.display = showMusicAI ? 'block' : 'none';

        musicBlock.querySelectorAll('input, select').forEach(function(el){
          // We want platform/date/rights to be required when visible,
          // BUT NOT the checkbox groups (we'll validate those manually on submit).
          var name = el.name || '';

          if (
            name === 'ai_music_parts_ai[]' ||
            name === 'ai_music_parts_human[]'
          ) {
            el.required = false;
          } else {
            el.required = showMusicAI;
          }
        });
      }
    }


    // AI VIDEO DETAILS
    if (e.target.name === 'ai_video') {
      var showVideoAI = (e.target.value === 'yes');
      var videoBlock  = document.getElementById('ai-video-form');
      if (videoBlock) {
        videoBlock.style.display = showVideoAI ? 'block' : 'none';
        videoBlock.querySelectorAll('input, select').forEach(function(el){
          el.required = showVideoAI;
        });
      }
    }

    // Content Monetization (Locked options)
    if (e.target.name === 'content_lock') {
      var locked = (e.target.value === 'locked');
      var lockedOptions = document.getElementById('locked-options');
      if (lockedOptions) {
        lockedOptions.style.display = locked ? 'block' : 'none';
      }
    }
  });
  
  // === VALIDATE MUSIC FORM: Video path OR Audio-only path ===
  (function() {
    var musicForm = document.getElementById('imu-form-music');
    if (!musicForm) return;

    musicForm.addEventListener('submit', function(e) {
      var errors = [];
      var form   = this;

      function fileTooBig(input) {
        return input &&
               input.files &&
               input.files[0] &&
               input.files[0].size > IMU_MAX_UPLOAD_BYTES;
      }

      // --- Get all upload fields ---
      var contentFile  = form.querySelector('input[name="content_file"]');
      var contentUrl   = form.querySelector('input[name="content_url"]');
      var thumbFile    = form.querySelector('input[name="thumb_file"]');
      var coverFile    = form.querySelector('input[name="cover_art_file"]');
      var mp3File      = form.querySelector('input[name="mp3_file"]');
      var mp3Url       = form.querySelector('input[name="mp3_url"]');

      var hasContentFile = contentFile && contentFile.files && contentFile.files.length > 0;
      var hasContentUrl  = contentUrl && contentUrl.value.trim() !== '';
      var hasThumbFile   = thumbFile && thumbFile.files && thumbFile.files.length > 0;
      var hasCoverFile   = coverFile && coverFile.files && coverFile.files.length > 0;
      var hasMp3File     = mp3File && mp3File.files && mp3File.files.length > 0;
      var hasMp3Url      = mp3Url && mp3Url.value.trim() !== '';

      var hasVideoContent = hasContentFile || hasContentUrl;
      var hasMp3Content   = hasMp3File || hasMp3Url;

      // --- Validate upload paths ---
      // Path 1: Video + Thumbnail (1920x1080)
      // Path 2: MP3 + Cover Art (1080x1080) - no video
      var videoPathValid = hasVideoContent && hasThumbFile;
      var audioPathValid = hasMp3Content && hasCoverFile && !hasVideoContent;

      if (!videoPathValid && !audioPathValid) {
        if (hasVideoContent && !hasThumbFile) {
          errors.push('Video uploads require a Thumbnail (1920x1080).');
        } else if (hasMp3Content && !hasCoverFile) {
          errors.push('Audio uploads require Cover Art (1080x1080).');
        } else {
          errors.push('Please upload either: Video + Thumbnail, OR Audio + Cover Art.');
        }
      }

      // --- Size checks (400MB) ---
      if (hasContentFile && fileTooBig(contentFile)) {
        errors.push('Video file is too large. Please paste a storage link instead.');
      }

      if (hasThumbFile && fileTooBig(thumbFile)) {
        errors.push('Thumbnail file is larger than 400MB. Please upload a smaller image.');
      }

      if (hasCoverFile && fileTooBig(coverFile)) {
        errors.push('Cover Art file is larger than 400MB. Please upload a smaller image.');
      }

      if (hasMp3File && fileTooBig(mp3File)) {
        errors.push('MP3 file is larger than 400MB. Please paste a storage link instead.');
      }

      // --- TRACK DURATION VALIDATION (Audio Only) ---
      var musicContentType = form.querySelector('input[name="music_content_type"]:checked');
      if (musicContentType && musicContentType.value === 'audio_only') {
        var trackDurationInput = form.querySelector('input[name="track_duration"]');
        if (trackDurationInput) {
          var duration = trackDurationInput.value.trim();
          if (!duration) {
            errors.push('Track Duration is required for audio-only submissions.');
          } else if (!/^(\d{1,2}:)?\d{1,2}:\d{2}$/.test(duration)) {
            errors.push('Track Duration must be in format MM:SS (e.g., 3:45 or 03:45).');
          }
        }
      }

      // --- AI MUSIC CHECKBOX GROUPS ---
      var aiSelect = form.querySelector('[name="ai_music"]');
      if (aiSelect && aiSelect.value === 'yes') {
        // At least one AI-created part:
        var aiPartsChecked = form.querySelectorAll('input[name="ai_music_parts_ai[]"]:checked');
        // At least one human-influenced part:
        var humanPartsChecked = form.querySelectorAll('input[name="ai_music_parts_human[]"]:checked');

        if (aiPartsChecked.length === 0) {
          errors.push('Please select at least one part of the track that was AI created.');
        }
        if (humanPartsChecked.length === 0) {
          errors.push('Please select at least one part of the track that had human influence (or select "None").');
        }
      }

      if (errors.length > 0) {
        e.preventDefault();
        imuUploadShowError(errors.join("\n"));
      }
    });
  })();



  // Conditionals for Creator Form
  document.getElementById('imu-form-creator').addEventListener('change', function(e) {
    // AI content details
    if (e.target.name === 'ai_content') {
      var showAI   = (e.target.value === 'yes');
      var aiBlock  = document.getElementById('ai-content-form');
      if (aiBlock) {
        aiBlock.style.display = showAI ? 'block' : 'none';
        aiBlock.querySelectorAll('input, select').forEach(function(el){
          el.required = showAI;
        });
      }
    }

    // Content monetization (Creator)
    if (e.target.name === 'content_lock') {
      var lockedOpt = document.getElementById('creator-locked-options');
      if (lockedOpt) {
        lockedOpt.style.display = (e.target.value === 'locked') ? 'block' : 'none';
      }
    }
  });
  
  // === VALIDATE CREATOR FORM: file/URL + 400MB caps ===
  (function() {
    var creatorForm = document.getElementById('imu-form-creator');
    if (!creatorForm) return;

    creatorForm.addEventListener('submit', function(e) {
      var errors = [];
      var form   = this;

      function fileTooBig(input) {
        return input &&
               input.files &&
               input.files[0] &&
               input.files[0].size > IMU_MAX_UPLOAD_BYTES;
      }

      // Content file OR URL
      var contentFile = form.querySelector('input[name="content_file"]');
      var contentUrl  = form.querySelector('input[name="content_url"]');
      var thumbFile   = form.querySelector('input[name="thumb_file"]');

      var hasContentFile = contentFile && contentFile.files && contentFile.files.length > 0;
      var hasContentUrl  = contentUrl && contentUrl.value.trim() !== '';

      if (!hasContentFile && !hasContentUrl) {
        errors.push('Please either upload your content file (up to 400MB) or paste a storage link.');
      }

      // Size checks
      if (hasContentFile && fileTooBig(contentFile)) {
        errors.push('File size too big, please upload vid drive link!');
      }

      if (thumbFile && thumbFile.files && thumbFile.files.length > 0 && fileTooBig(thumbFile)) {
        errors.push('Thumbnail file is larger than 400MB. Please upload a smaller image.');
      }

      if (errors.length > 0) {
        e.preventDefault();
        imuUploadShowError(errors.join("\n"));
      }
    });
  })();



  
  // LIVE STREAMER FORM — Conditional Fields + Multi-Date Events
  document.getElementById('imu-form-streamer').addEventListener('change', function(e) {
    const reoccurring = document.getElementById('host-reoccurring');
    const oneoff      = document.getElementById('host-oneoff');

    if (!reoccurring || !oneoff) return;

    if (e.target.id === 'host-reoccurring' || e.target.id === 'host-oneoff') {
      // Enforce ONLY ONE selection at a time
      if (e.target.id === 'host-reoccurring' && e.target.checked) {
        oneoff.checked = false;
      }
      if (e.target.id === 'host-oneoff' && e.target.checked) {
        reoccurring.checked = false;
      }

      const reoccurringFields = document.getElementById('reoccurring-fields');
      const oneoffFields      = document.getElementById('oneoff-fields');

      const showReoccurring = reoccurring.checked;
      const showOneoff      = oneoff.checked;

      if (reoccurringFields) {
        reoccurringFields.style.display = showReoccurring ? 'block' : 'none';
      }
      if (oneoffFields) {
        oneoffFields.style.display = showOneoff ? 'block' : 'none';
      }

      // Required field toggling
      document.querySelectorAll('#reoccurring-fields input, #reoccurring-fields select').forEach(function(el){
        el.required = showReoccurring;
      });

      // For One-Off: require basic event fields + date inputs, but NOT broadcast_auth
      document.querySelectorAll('#oneoff-fields input[type="text"], #oneoff-fields input[type="email"], #oneoff-dates-container input').forEach(function(el){
        el.required = showOneoff;
      });
    }
  });


  // Add another date for One-Off Events
  document.addEventListener('click', function(e) {
    if (e.target && e.target.id === 'add-oneoff-date') {
      const container = document.getElementById('oneoff-dates-container');
      const count = container.querySelectorAll('.oneoff-date-entry').length;
      const newEntry = document.createElement('div');
      newEntry.className = 'oneoff-date-entry';
      newEntry.style = 'margin-bottom:12px;padding:12px;background:rgba(255,255,255,.03);border-radius:8px;';
      newEntry.innerHTML = `
        <div class="imu-field">
          <label>Date of Event <span style="color:#e88">*</span></label>
          <input type="date" name="oneoff_dates[${count}][date]" class="imu-input">
        </div>
      `;
      container.appendChild(newEntry);
    }
  });
  
 // ============================================
  // NFT ACCESS TOGGLE HANDLERS
  // FIX: Show/hide NFT fields based on Yes/No selection
  // FIX: Toggle required attribute on issuer fields
  // ============================================
  
  /**
   * Generic handler for NFT Access Yes/No toggle.
   * Shows/hides the NFT gate fields and toggles required on issuer inputs.
   */
  function setupNftAccessToggle(formPrefix, toggleId, fieldsId) {
    var toggle = document.getElementById(toggleId);
    if (!toggle) return;
    
    toggle.addEventListener('change', function() {
      var showFields = (this.value === 'yes');
      var fieldsContainer = document.getElementById(fieldsId);
      
      if (fieldsContainer) {
        fieldsContainer.style.display = showFields ? 'block' : 'none';
        
        // Toggle required on all issuer fields within this container
        fieldsContainer.querySelectorAll('.nft-issuer-field').forEach(function(input) {
          input.required = showFields;
        });
      }
    });
  }
  
  // Initialize NFT toggle for all three forms
  setupNftAccessToggle('music', 'music-nft-access-toggle', 'music-nft-gate-fields');
  setupNftAccessToggle('creator', 'creator-nft-access-toggle', 'creator-nft-gate-fields');
  setupNftAccessToggle('streamer', 'streamer-nft-access-toggle', 'streamer-nft-gate-fields');


  // ============================================
  // NFT GATE: ADD MORE COLLECTIONS
  // Updated to add nft-issuer-field class and handle required properly
  // ============================================
  document.addEventListener('click', function(e) {
    if (e.target && e.target.matches('[data-add-nft-gate]')) {
      var formType = e.target.getAttribute('data-form') || 'generic';
      var container = document.getElementById('nft-gate-container-' + formType);
      if (!container) return;

      var rows = container.querySelectorAll('.nft-gate-row');
      var index = rows.length;

      // Check if NFT access is enabled (to set required properly)
      var toggleSelect = document.getElementById(formType + '-nft-access-toggle');
      var isRequired = (toggleSelect && toggleSelect.value === 'yes');

      var newRow = document.createElement('div');
      newRow.className = 'nft-gate-row grid-3';
      newRow.style = 'margin-bottom:12px;padding:12px;background:rgba(255,255,255,.03);border-radius:8px;position:relative;';
      newRow.innerHTML = `
        <div class="imu-field">
          <label>Collection Title (optional)</label>
          <input type="text" name="nft_gate[${index}][title]" class="imu-input" placeholder="e.g., Founders Pass">
        </div>
        <div class="imu-field">
          <label>Issuer Address <span style="color:#e88">*</span></label>
          <input type="text" name="nft_gate[${index}][issuer]" class="imu-input nft-issuer-field" ${isRequired ? 'required' : ''} placeholder="r123...">
        </div>
        <div class="imu-field">
          <label>Taxon (leave blank = any)</label>
          <input type="number" name="nft_gate[${index}][taxon]" class="imu-input" placeholder="Any">
        </div>
        <div class="imu-field">
          <label>Min Required</label>
          <input type="number" name="nft_gate[${index}][min]" class="imu-input" value="1" min="1">
        </div>
        <button type="button" class="imu-btn" style="position:absolute;top:8px;right:8px;padding:4px 8px;font-size:12px;" onclick="this.parentElement.remove()">Remove</button>
      `;

      container.appendChild(newRow);
    }
  });

// Live Streamer: Show/hide monetization options
document.getElementById('imu-form-streamer')?.addEventListener('change', function(e) {
  if (e.target.name === 'content_lock') {
    const opts = document.getElementById('streamer-locked-options');
    if (opts) opts.style.display = (e.target.value === 'locked') ? 'block' : 'none';
  }
});

  // ============================================================================
  // AUTO-REGISTRATION: Email Check & Password Validation
  // ============================================================================
  (function() {
    'use strict';
    
    var isLoggedIn = <?php echo is_user_logged_in() ? 'true' : 'false'; ?>;
    var currentUserEmail = '<?php echo esc_js($prefill_email); ?>';
    var ajaxUrl = '<?php echo admin_url("admin-ajax.php"); ?>';
    var checkTimeout = null;
    
    // Track which forms need registration
    var formsNeedingRegistration = {
      music: false,
      creator: false,
      streamer: false
    };
    
    /**
     * Validate email format
     */
    function isValidEmail(email) {
      return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }
    
    /**
     * Check password strength
     */
    function getPasswordStrength(password) {
      var strength = 0;
      if (password.length >= 8) strength++;
      if (password.length >= 12) strength++;
      if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++;
      if (/\d/.test(password)) strength++;
      if (/[^a-zA-Z0-9]/.test(password)) strength++;
      
      if (strength <= 2) return 'weak';
      if (strength <= 3) return 'medium';
      return 'strong';
    }
    
    /**
     * Update password strength bar
     */
    function updatePasswordStrength(formType, password) {
      var bar = document.getElementById(formType + '-pw-strength');
      if (!bar) return;
      
      bar.className = 'imu-password-strength-bar';
      if (password.length > 0) {
        bar.classList.add(getPasswordStrength(password));
      }
    }
    
    /**
     * Check if passwords match
     */
    function checkPasswordMatch(formType) {
      var pw1 = document.getElementById(formType + '-create-password');
      var pw2 = document.getElementById(formType + '-confirm-password');
      var matchEl = document.getElementById(formType + '-pw-match');
      
      if (!pw1 || !pw2 || !matchEl) return true;
      
      if (pw2.value.length === 0) {
        matchEl.textContent = 'Passwords must match';
        matchEl.className = 'imu-password-match';
        return false;
      }
      
      if (pw1.value === pw2.value) {
        matchEl.textContent = '✓ Passwords match';
        matchEl.className = 'imu-password-match match';
        return true;
      } else {
        matchEl.textContent = '✗ Passwords do not match';
        matchEl.className = 'imu-password-match no-match';
        return false;
      }
    }
    
    /**
     * Update required attribute on password fields
     */
    function setPasswordRequired(formType, required) {
      var pw1 = document.getElementById(formType + '-create-password');
      var pw2 = document.getElementById(formType + '-confirm-password');
      
      if (pw1) pw1.required = required;
      if (pw2) pw2.required = required;
    }
    
    /**
     * Check if email exists via AJAX
     */
    function checkEmail(email, formType) {
      var statusEl = document.getElementById(formType + '-email-status');
      var regFields = document.getElementById(formType + '-registration-fields');
      
      // Invalid or empty email
      if (!email || !isValidEmail(email)) {
        if (statusEl) statusEl.style.display = 'none';
        if (regFields) regFields.style.display = 'none';
        formsNeedingRegistration[formType] = false;
        setPasswordRequired(formType, false);
        return;
      }
      
      // If logged in as this user, skip
      if (isLoggedIn && email.toLowerCase() === currentUserEmail.toLowerCase()) {
        if (statusEl) statusEl.style.display = 'none';
        if (regFields) regFields.style.display = 'none';
        formsNeedingRegistration[formType] = false;
        setPasswordRequired(formType, false);
        return;
      }
      
      // Show checking status
      if (statusEl) {
        statusEl.style.display = 'block';
        statusEl.className = 'imu-email-status checking';
        statusEl.textContent = 'Checking email...';
      }
      
      // AJAX request
      var formData = new FormData();
      formData.append('action', 'imu_check_email');
      formData.append('email', email);
      
      fetch(ajaxUrl, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
      })
      .then(function(response) { return response.json(); })
      .then(function(data) {
        if (data.exists) {
          // User exists
          if (statusEl) {
            statusEl.className = 'imu-email-status exists';
            statusEl.innerHTML = '✓ Account found! Your submission will be linked to your existing IMUTV account.';
          }
          if (regFields) regFields.style.display = 'none';
          formsNeedingRegistration[formType] = false;
          setPasswordRequired(formType, false);
        } else {
          // New user - show registration
          if (statusEl) {
            statusEl.className = 'imu-email-status new-user';
            statusEl.innerHTML = '✨ New to IMUTV? Create your account below!';
          }
          if (regFields) regFields.style.display = 'block';
          formsNeedingRegistration[formType] = true;
          setPasswordRequired(formType, true);
        }
      })
      .catch(function(err) {
        console.error('Email check failed:', err);
        if (statusEl) statusEl.style.display = 'none';
      });
    }
    
    /**
     * Set up listeners for a form
     */
    function setupForm(formType) {
      var form = document.getElementById('imu-form-' + formType);
      if (!form) return;
      
      var emailField = form.querySelector('input[name="email"]');
      if (!emailField) return;
      
      // Check email on blur
      emailField.addEventListener('blur', function() {
        if (checkTimeout) clearTimeout(checkTimeout);
        checkEmail(this.value.trim(), formType);
      });
      
      // Check email on input (debounced)
      emailField.addEventListener('input', function() {
        var email = this.value.trim();
        if (checkTimeout) clearTimeout(checkTimeout);
        
        if (email.length > 5 && isValidEmail(email)) {
          checkTimeout = setTimeout(function() {
            checkEmail(email, formType);
          }, 800);
        }
      });
      
      // Password field listeners
      var pw1 = document.getElementById(formType + '-create-password');
      var pw2 = document.getElementById(formType + '-confirm-password');
      
      if (pw1) {
        pw1.addEventListener('input', function() {
          updatePasswordStrength(formType, this.value);
          checkPasswordMatch(formType);
        });
      }
      
      if (pw2) {
        pw2.addEventListener('input', function() {
          checkPasswordMatch(formType);
        });
      }
      
      // Form submit validation
      form.addEventListener('submit', function(e) {
        if (formsNeedingRegistration[formType]) {
          var createPw = document.getElementById(formType + '-create-password');
          var confirmPw = document.getElementById(formType + '-confirm-password');
          
          if (!createPw || !confirmPw) return;
          
          var errors = [];
          
          if (createPw.value.length < 8) {
            errors.push('Password must be at least 8 characters.');
          }
          
          if (createPw.value !== confirmPw.value) {
            errors.push('Passwords do not match.');
          }
          
          if (errors.length > 0) {
            e.preventDefault();
            alert('Please fix the following:\n\n' + errors.join('\n'));
            createPw.focus();
          }
        }
      });
      
      // Check pre-filled email on load (if different from current user)
      if (emailField.value && emailField.value.toLowerCase() !== currentUserEmail.toLowerCase()) {
        setTimeout(function() {
          checkEmail(emailField.value.trim(), formType);
        }, 500);
      }
    }
    
    // Initialize all forms when DOM ready
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function() {
        setupForm('music');
        setupForm('creator');
        setupForm('streamer');
      });
    } else {
      setupForm('music');
      setupForm('creator');
      setupForm('streamer');
    }
    
  })();
  
  // ============================================
  // DYNAMIC PRICING MODE TOGGLE HANDLERS
  // ============================================
  (function() {
    'use strict';
    
    /**
     * Setup pricing mode toggle for a specific form
     */
    function setupPricingModeToggle(formId, prefix) {
      var form = document.getElementById(formId);
      if (!form) return;
      
      var dynamicSection = document.getElementById(prefix + '-dynamic-pricing');
      var staticSection = document.getElementById(prefix + '-static-pricing');
      
      form.addEventListener('change', function(e) {
        if (e.target.name === 'pricing_mode') {
          var isDynamic = (e.target.value === 'dynamic');
          
          if (dynamicSection) {
            dynamicSection.style.display = isDynamic ? 'block' : 'none';
          }
          if (staticSection) {
            staticSection.style.display = isDynamic ? 'none' : 'block';
          }
        }
        
        // Token checkbox enables/disables discount input
        if (e.target.classList.contains('imu-token-checkbox')) {
          var row = e.target.closest('.imu-token-row');
          if (row) {
            var discountInput = row.querySelector('.imu-discount-input');
            if (discountInput) {
              discountInput.disabled = !e.target.checked;
              if (!e.target.checked) {
                discountInput.value = 0;
              }
            }
          }
        }
      });
      
      // Initialize discount inputs based on checkbox state
      form.querySelectorAll('.imu-token-checkbox').forEach(function(checkbox) {
        var row = checkbox.closest('.imu-token-row');
        if (row) {
          var discountInput = row.querySelector('.imu-discount-input');
          if (discountInput) {
            discountInput.disabled = !checkbox.checked;
          }
        }
      });
    }
    
    // Initialize for all forms
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function() {
        setupPricingModeToggle('imu-form-music', 'music');
        setupPricingModeToggle('imu-form-creator', 'creator');
        setupPricingModeToggle('imu-form-streamer', 'streamer');
      });
    } else {
      setupPricingModeToggle('imu-form-music', 'music');
      setupPricingModeToggle('imu-form-creator', 'creator');
      setupPricingModeToggle('imu-form-streamer', 'streamer');
    }
  })();
  
  // ============================================
  // UPDATED CUSTOM TOKEN ADD HANDLERS
  // Now supports per-form custom token wraps
  // ============================================
  document.addEventListener('click', function(e) {
    if (e.target.matches('[data-add-custom-token]')) {
      var formType = e.target.getAttribute('data-form') || '';
      var wrapId = formType ? formType + '-custom-token-wrap' : 'custom-token-wrap';
      var wrap = document.getElementById(wrapId);
      
      // Fallback to generic wrap if form-specific not found
      if (!wrap) {
        wrap = document.getElementById('custom-token-wrap');
      }
      if (!wrap) return;
      
      var nextIndex = parseInt(wrap.dataset.nextIndex || '1', 10);
      var row = document.createElement('div');
      row.className = 'custom-token-row grid-3';
      row.style = 'margin-top:12px;';
      row.innerHTML = `
        <div class="imu-field">
          <label>Ticker</label>
          <input type="text" name="custom_tokens[${nextIndex}][ticker]" class="imu-input" placeholder="e.g. MYTOKEN">
        </div>
        <div class="imu-field">
          <label>Issuer Address</label>
          <input type="text" name="custom_tokens[${nextIndex}][issuer]" class="imu-input" placeholder="r...">
        </div>
        <div class="imu-field">
          <label>Price</label>
          <input type="number" name="custom_tokens[${nextIndex}][price]" class="imu-input" step="0.000001" min="0">
        </div>
      `;
      wrap.appendChild(row);
      wrap.dataset.nextIndex = String(nextIndex + 1);
    }
  });

  // ========== TOKEN DROPDOWN FUNCTIONS ==========
  
  // Toggle dropdown open/closed
  function toggleTokenDropdown(dropdownId) {
    const dropdown = document.getElementById(dropdownId);
    if (!dropdown) return;
    
    // Close all other dropdowns first
    document.querySelectorAll('.imu-token-dropdown.open').forEach(d => {
      if (d.id !== dropdownId) {
        d.classList.remove('open');
        var m = d.querySelector('.imu-token-dropdown-menu');
        if (m) m.style.cssText = '';
      }
    });
    
    dropdown.classList.toggle('open');
    
    // On mobile, force the menu into viewport center using JS
    if (dropdown.classList.contains('open') && window.innerWidth <= 768) {
      var menu = dropdown.querySelector('.imu-token-dropdown-menu');
      if (menu) {
        // Calculate viewport center
        var viewportHeight = window.innerHeight;
        var menuHeight = Math.min(viewportHeight * 0.6, 400);
        var topPosition = window.scrollY + (viewportHeight / 2) - (menuHeight / 2);
        
        // Apply inline styles to override everything
        menu.style.cssText = 'position: absolute !important; ' +
          'top: ' + topPosition + 'px !important; ' +
          'left: 5% !important; ' +
          'right: 5% !important; ' +
          'width: 90% !important; ' +
          'max-height: ' + menuHeight + 'px !important; ' +
          'z-index: 10000 !important; ' +
          'display: block !important; ' +
          'margin: 0 !important;';
        
        // Add backdrop if not present
        var backdrop = document.getElementById('imu-dropdown-backdrop');
        if (!backdrop) {
          backdrop = document.createElement('div');
          backdrop.id = 'imu-dropdown-backdrop';
          backdrop.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; ' +
            'background: rgba(0,0,0,0.7); z-index: 9999;';
          backdrop.onclick = function() {
            dropdown.classList.remove('open');
            menu.style.cssText = '';
            backdrop.remove();
          };
          document.body.appendChild(backdrop);
        }
      }
    } else if (!dropdown.classList.contains('open')) {
      // Closing - clean up
      var menu = dropdown.querySelector('.imu-token-dropdown-menu');
      if (menu) menu.style.cssText = '';
      var backdrop = document.getElementById('imu-dropdown-backdrop');
      if (backdrop) backdrop.remove();
    }
  }
  
  // Close dropdowns when clicking outside
  document.addEventListener('click', function(e) {
    if (!e.target.closest('.imu-token-dropdown')) {
      document.querySelectorAll('.imu-token-dropdown.open').forEach(d => {
        d.classList.remove('open');
        var menu = d.querySelector('.imu-token-dropdown-menu');
        if (menu) menu.style.cssText = '';
      });
      // Clean up backdrop
      var backdrop = document.getElementById('imu-dropdown-backdrop');
      if (backdrop) backdrop.remove();
    }
  });
  
  // Add token from dropdown to the selector
  function addTokenToSelector(item, containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;
    
    // Get token data from the dropdown item
    const ticker = item.dataset.ticker;
    const issuer = item.dataset.issuer || '';
    const display = item.dataset.display || ticker;
    const desc = item.dataset.desc || display;
    const logo = item.dataset.logo || '';
    const trustline = item.dataset.trustline || '';
    const discount = item.dataset.discount || '0';
    
    // Check if token already exists in the form (only check token ROWS, not dropdown items)
    const selector = container.closest('form') || document;
    if (selector.querySelector(`.imu-token-row[data-ticker="${ticker}"]`)) {
      alert(`${ticker} is already in your token list.`);
      return;
    }
    
    // Create logo HTML
    let logoHtml = '';
    if (logo) {
      logoHtml = `<img src="${logo}" class="imu-token-logo" alt="${ticker}">`;
    } else {
      logoHtml = `<div style="width:24px;height:24px;border-radius:50%;background:#333;display:flex;align-items:center;justify-content:center;font-size:8px;font-weight:bold;color:#fff;">${ticker.substring(0, 3)}</div>`;
    }
    
    // Create trustline button HTML
    let trustlineHtml = '';
    if (trustline) {
      trustlineHtml = `<a href="${trustline}" target="_blank" class="imu-trustline-btn" title="Set trustline for ${ticker}">🔗 Trustline</a>`;
    } else {
      trustlineHtml = `<span class="imu-trustline-btn disabled" title="Trustline not configured">🔗</span>`;
    }
    
    // Create the token row HTML
    const rowHtml = `
      <div class="imu-token-row" data-ticker="${ticker}">
        <label>
          <input type="checkbox" name="accepted_tokens[${ticker}][enabled]" value="1" checked class="imu-token-checkbox">
          ${logoHtml}
          <div class="imu-token-info">
            <strong>${ticker}</strong>
            <span>${desc}</span>
          </div>
        </label>
        <input type="hidden" name="accepted_tokens[${ticker}][issuer]" value="${issuer}">
        <div class="imu-discount-wrap">
          <label style="font-size:12px;color:#888;min-width:auto;">Discount:</label>
          <input type="number" name="accepted_tokens[${ticker}][discount]" value="${discount}" min="0" max="99" class="imu-input imu-discount-input">
          <span style="color:#888;">%</span>
        </div>
        ${trustlineHtml}
        <button type="button" class="imu-token-remove-btn" onclick="removeTokenRow(this)" title="Remove token">✕</button>
      </div>
    `;
    
    // Add the row to the container
    container.insertAdjacentHTML('beforeend', rowHtml);
    
    // Mark item as added in dropdown
    item.classList.add('added');
    const check = item.querySelector('.added-check');
    if (check) check.style.display = 'inline';
    
    // Close the dropdown
    const dropdown = item.closest('.imu-token-dropdown');
    if (dropdown) dropdown.classList.remove('open');
  }
  
  // Remove a dynamically added token row
  function removeTokenRow(btn) {
    const row = btn.closest('.imu-token-row');
    if (!row) return;
    
    const ticker = row.dataset.ticker;
    
    // Remove the row
    row.remove();
    
    // Unmark in all dropdowns
    document.querySelectorAll(`.imu-token-dropdown-item[data-ticker="${ticker}"]`).forEach(item => {
      item.classList.remove('added');
      const check = item.querySelector('.added-check');
      if (check) check.style.display = 'none';
    });
  }

  // ========== MUSIC TYPE TOGGLE (Music Video vs Audio Only) ==========
  (function() {
    var musicForm = document.getElementById('imu-form-music');
    if (!musicForm) return;

    var musicTypeRadios = musicForm.querySelectorAll('input[name="music_content_type"]');
    
    musicTypeRadios.forEach(function(radio) {
      radio.addEventListener('change', function() {
        var isVideo = this.value === 'music_video';
        var isAudio = this.value === 'audio_only';
        
        // Show the track details wrapper when any content type is selected
        var trackDetailsWrapper = document.getElementById('music-track-details-wrapper');
        if (trackDetailsWrapper) {
          trackDetailsWrapper.style.display = 'block';
        }
        
        // Upload fields
        var videoFields = document.getElementById('music-video-upload-fields');
        var audioFields = document.getElementById('audio-only-upload-fields');
        var uploadInfo = document.getElementById('music-upload-info');
        
        // Track details fields
        var isrcVideoField = musicForm.querySelector('.isrc-video-field');
        var trackDurationWrap = document.getElementById('track-duration-wrap');
        var trackDurationInput = document.getElementById('track-duration-input');
        var productionInput = document.getElementById('production-input');
        var productionStar = musicForm.querySelector('.production-required-star');
        
        if (isVideo) {
          // Show video upload fields, hide audio fields
          if (videoFields) videoFields.style.display = 'block';
          if (audioFields) audioFields.style.display = 'none';
          if (uploadInfo) uploadInfo.innerHTML = '<strong>Music Video Upload:</strong><br><span style="color:#fff;">Upload your video file + thumbnail image</span>';
          
          // Show ISRC Video field, hide track duration
          if (isrcVideoField) isrcVideoField.style.display = 'block';
          if (trackDurationWrap) trackDurationWrap.style.display = 'none';
          if (trackDurationInput) trackDurationInput.required = false;
          
          // Production is optional for video
          if (productionInput) productionInput.required = false;
          if (productionStar) productionStar.style.display = 'none';
          
        } else if (isAudio) {
          // Show audio upload fields, hide video fields
          if (videoFields) videoFields.style.display = 'none';
          if (audioFields) audioFields.style.display = 'block';
          if (uploadInfo) uploadInfo.innerHTML = '<strong>Audio Only Upload:</strong><br><span style="color:#fff;">Upload your audio file + cover art image</span>';
          
          // Hide ISRC Video field, show track duration
          if (isrcVideoField) isrcVideoField.style.display = 'none';
          if (trackDurationWrap) trackDurationWrap.style.display = 'block';
          if (trackDurationInput) trackDurationInput.required = true;
          
          // Production is required for audio
          if (productionInput) productionInput.required = true;
          if (productionStar) productionStar.style.display = 'inline';
        }
      });
    });
    
    // Real-time track duration format validation
    var trackDurationInput = document.getElementById('track-duration-input');
    if (trackDurationInput) {
      trackDurationInput.addEventListener('input', function() {
        var val = this.value.trim();
        var isValid = !val || /^(\d{1,2}:)?\d{1,2}:\d{2}$/.test(val);
        this.style.borderColor = isValid ? '' : '#ff6b6b';
      });
      
      // Auto-format: add colon after 1-2 digits if followed by more digits
      trackDurationInput.addEventListener('blur', function() {
        var val = this.value.trim();
        // If user entered just digits like "345", convert to "3:45"
        if (/^\d{3,4}$/.test(val)) {
          if (val.length === 3) {
            this.value = val.charAt(0) + ':' + val.slice(1);
          } else if (val.length === 4) {
            this.value = val.slice(0, 2) + ':' + val.slice(2);
          }
        }
      });
    }
  })();

  // ========== TERRITORY AUTHORIZATION - COUNTRY DROPDOWN ==========
  var IMU_COUNTRIES = [
    "Afghanistan", "Albania", "Algeria", "Andorra", "Angola", "Argentina", "Armenia", "Australia",
    "Austria", "Azerbaijan", "Bahamas", "Bahrain", "Bangladesh", "Barbados", "Belarus", "Belgium",
    "Belize", "Benin", "Bhutan", "Bolivia", "Bosnia and Herzegovina", "Botswana", "Brazil", "Brunei",
    "Bulgaria", "Burkina Faso", "Burundi", "Cambodia", "Cameroon", "Canada", "Cape Verde", "Central African Republic",
    "Chad", "Chile", "China", "Colombia", "Comoros", "Congo", "Costa Rica", "Croatia", "Cuba", "Cyprus",
    "Czech Republic", "Denmark", "Djibouti", "Dominica", "Dominican Republic", "Ecuador", "Egypt",
    "El Salvador", "Equatorial Guinea", "Eritrea", "Estonia", "Eswatini", "Ethiopia", "Fiji", "Finland",
    "France", "Gabon", "Gambia", "Georgia", "Germany", "Ghana", "Greece", "Grenada", "Guatemala", "Guinea",
    "Guinea-Bissau", "Guyana", "Haiti", "Honduras", "Hungary", "Iceland", "India", "Indonesia", "Iran",
    "Iraq", "Ireland", "Israel", "Italy", "Ivory Coast", "Jamaica", "Japan", "Jordan", "Kazakhstan", "Kenya",
    "Kiribati", "Kuwait", "Kyrgyzstan", "Laos", "Latvia", "Lebanon", "Lesotho", "Liberia", "Libya",
    "Liechtenstein", "Lithuania", "Luxembourg", "Madagascar", "Malawi", "Malaysia", "Maldives", "Mali",
    "Malta", "Marshall Islands", "Mauritania", "Mauritius", "Mexico", "Micronesia", "Moldova", "Monaco",
    "Mongolia", "Montenegro", "Morocco", "Mozambique", "Myanmar", "Namibia", "Nauru", "Nepal", "Netherlands",
    "New Zealand", "Nicaragua", "Niger", "Nigeria", "North Korea", "North Macedonia", "Norway", "Oman",
    "Pakistan", "Palau", "Palestine", "Panama", "Papua New Guinea", "Paraguay", "Peru", "Philippines",
    "Poland", "Portugal", "Qatar", "Romania", "Russia", "Rwanda", "Saint Kitts and Nevis", "Saint Lucia",
    "Saint Vincent and the Grenadines", "Samoa", "San Marino", "Saudi Arabia", "Senegal", "Serbia",
    "Seychelles", "Sierra Leone", "Singapore", "Slovakia", "Slovenia", "Solomon Islands", "Somalia",
    "South Africa", "South Korea", "South Sudan", "Spain", "Sri Lanka", "Sudan", "Suriname", "Sweden",
    "Switzerland", "Syria", "Taiwan", "Tajikistan", "Tanzania", "Thailand", "Timor-Leste", "Togo", "Tonga",
    "Trinidad and Tobago", "Tunisia", "Turkey", "Turkmenistan", "Tuvalu", "Uganda", "Ukraine",
    "United Arab Emirates", "United Kingdom", "United States", "Uruguay", "Uzbekistan", "Vanuatu",
    "Vatican City", "Venezuela", "Vietnam", "Yemen", "Zambia", "Zimbabwe"
  ];

  // Initialize country lists
  function initCountryLists() {
    document.querySelectorAll('.imu-country-list').forEach(function(list) {
      var target = list.dataset.target;
      var html = '';
      IMU_COUNTRIES.forEach(function(country) {
        html += '<div class="imu-country-item" data-country="' + country + '" onclick="addCountryExclusion(\'' + target + '\', \'' + country + '\')">' + country + '</div>';
      });
      list.innerHTML = html;
    });
  }

  // Toggle country dropdown
  function toggleCountryDropdown(dropdownId) {
    var dropdown = document.getElementById(dropdownId);
    if (!dropdown) return;
    
    // Check if we're closing an existing modal
    var existingModal = document.getElementById('imu-country-modal');
    if (existingModal) {
      existingModal.remove();
      dropdown.classList.remove('open');
      return;
    }
    
    // Close all other country dropdowns
    document.querySelectorAll('.imu-country-dropdown.open').forEach(function(d) {
      if (d.id !== dropdownId) d.classList.remove('open');
    });
    
    dropdown.classList.toggle('open');
    
    // On mobile, create a proper full-viewport modal (like imu-dynamic-pricing does)
    if (dropdown.classList.contains('open') && window.innerWidth <= 768) {
      var target = dropdown.querySelector('.imu-country-list').dataset.target;
      
      // Create modal container - same pattern as imu-trustline-modal
      var modal = document.createElement('div');
      modal.id = 'imu-country-modal';
      modal.style.cssText = 'position:fixed;inset:0;z-index:10000;display:flex;align-items:center;justify-content:center;';
      
      // Backdrop (like imu-modal-backdrop)
      var backdrop = document.createElement('div');
      backdrop.className = 'imu-modal-backdrop';
      backdrop.style.cssText = 'position:absolute;inset:0;background:rgba(0,0,0,0.8);';
      backdrop.onclick = function() { closeModal(); };
      modal.appendChild(backdrop);
      
      // Dialog (like imu-modal-dialog)
      var dialog = document.createElement('div');
      dialog.className = 'imu-modal-dialog';
      dialog.style.cssText = 'position:relative;background:#1a1a2e;border-radius:16px;padding:0;max-width:400px;width:90%;max-height:70vh;display:flex;flex-direction:column;overflow:hidden;color:#fff;';
      
      // Header with close button
      var header = document.createElement('div');
      header.style.cssText = 'display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid rgba(255,255,255,0.1);';
      header.innerHTML = '<span style="color:#d6ba66;font-weight:600;font-size:16px;">Select Countries to Exclude</span>';
      
      var closeBtn = document.createElement('button');
      closeBtn.type = 'button';
      closeBtn.className = 'imu-modal-close';
      closeBtn.innerHTML = '✕';
      closeBtn.style.cssText = 'background:none;border:none;color:#888;font-size:20px;cursor:pointer;padding:0;';
      closeBtn.onclick = function() { closeModal(); };
      header.appendChild(closeBtn);
      dialog.appendChild(header);
      
      // Search input
      var searchWrap = document.createElement('div');
      searchWrap.style.cssText = 'padding:12px 20px;border-bottom:1px solid rgba(255,255,255,0.1);';
      var searchInput = document.createElement('input');
      searchInput.type = 'text';
      searchInput.placeholder = 'Search countries...';
      searchInput.style.cssText = 'width:100%;padding:12px;background:rgba(0,0,0,0.3);border:1px solid rgba(255,255,255,0.2);border-radius:8px;color:#fff;font-size:16px;outline:none;';
      searchInput.oninput = function() {
        var filter = this.value.toLowerCase();
        listWrap.querySelectorAll('.imu-country-modal-item').forEach(function(item) {
          item.style.display = item.dataset.country.toLowerCase().includes(filter) ? 'block' : 'none';
        });
      };
      searchWrap.appendChild(searchInput);
      dialog.appendChild(searchWrap);
      
      // Scrollable country list
      var listWrap = document.createElement('div');
      listWrap.style.cssText = 'flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch;';
      
      IMU_COUNTRIES.forEach(function(country) {
        var item = document.createElement('div');
        item.className = 'imu-country-modal-item';
        item.dataset.country = country;
        item.textContent = country;
        item.style.cssText = 'padding:14px 20px;border-bottom:1px solid rgba(255,255,255,0.05);color:#fff;cursor:pointer;transition:background 0.15s;';
        item.onmouseenter = function() { this.style.background = 'rgba(214,186,102,0.1)'; };
        item.onmouseleave = function() { this.style.background = 'transparent'; };
        item.onclick = function() {
          addCountryExclusion(target, country);
          closeModal();
        };
        listWrap.appendChild(item);
      });
      dialog.appendChild(listWrap);
      
      modal.appendChild(dialog);
      document.body.appendChild(modal);
      
      // Focus search after short delay
      setTimeout(function() { searchInput.focus(); }, 100);
      
      function closeModal() {
        var m = document.getElementById('imu-country-modal');
        if (m) m.remove();
        dropdown.classList.remove('open');
      }
      
    }
    // Desktop: use default CSS dropdown behavior (no JS intervention needed)
  }

  // Filter countries in dropdown
  function filterCountries(input, dropdownId) {
    var filter = input.value.toLowerCase();
    var dropdown = document.getElementById(dropdownId);
    if (!dropdown) return;
    
    dropdown.querySelectorAll('.imu-country-item').forEach(function(item) {
      var country = item.dataset.country.toLowerCase();
      item.style.display = country.includes(filter) ? 'block' : 'none';
    });
  }

  // Add country exclusion
  function addCountryExclusion(target, country) {
    var listEl = document.getElementById(target + '-exclusions-list');
    var inputEl = document.getElementById(target + '-territory-exclusions-input');
    var dropdown = document.getElementById(target + '-country-dropdown');
    
    if (!listEl || !inputEl) return;
    
    // Check if already added
    if (listEl.querySelector('[data-country="' + country + '"]')) {
      return;
    }
    
    // Add tag
    var tag = document.createElement('div');
    tag.className = 'imu-exclusion-tag';
    tag.setAttribute('data-country', country);
    tag.innerHTML = country + ' <button type="button" onclick="removeCountryExclusion(\'' + target + '\', \'' + country + '\')">✕</button>';
    listEl.appendChild(tag);
    
    // Update hidden input
    updateTerritoryExclusions(target);
    
    // Close dropdown
    if (dropdown) dropdown.classList.remove('open');
  }

  // Remove country exclusion
  function removeCountryExclusion(target, country) {
    var listEl = document.getElementById(target + '-exclusions-list');
    if (!listEl) return;
    
    var tag = listEl.querySelector('[data-country="' + country + '"]');
    if (tag) tag.remove();
    
    updateTerritoryExclusions(target);
  }

  // Update hidden input with exclusions
  function updateTerritoryExclusions(target) {
    var listEl = document.getElementById(target + '-exclusions-list');
    var inputEl = document.getElementById(target + '-territory-exclusions-input');
    
    if (!listEl || !inputEl) return;
    
    var countries = [];
    listEl.querySelectorAll('.imu-exclusion-tag').forEach(function(tag) {
      countries.push(tag.dataset.country);
    });
    
    inputEl.value = countries.join(',');
  }

  // Territory worldwide checkbox handlers
  function setupTerritoryToggle(prefix) {
    var checkbox = document.getElementById(prefix + '-territory-worldwide');
    var exclusions = document.getElementById(prefix + '-territory-exclusions');
    
    if (!checkbox || !exclusions) return;
    
    checkbox.addEventListener('change', function() {
      exclusions.style.display = this.checked ? 'none' : 'block';
    });
  }

  // ========== CREATOR MUSIC USAGE FIELDS ==========
  (function() {
    var creatorForm = document.getElementById('imu-form-creator');
    if (!creatorForm) return;
    
    var musicPlayedSelect = document.getElementById('creator-music-played');
    var musicDetailsDiv = document.getElementById('creator-music-details');
    var musicTypeSelect = document.getElementById('creator-music-type');
    var musicFieldsDiv = document.getElementById('creator-music-fields');
    var musicProofWrap = document.getElementById('creator-music-proof-wrap');
    var musicProofLabel = document.getElementById('creator-music-proof-label');
    var musicProofFile = document.getElementById('creator-music-proof-file');
    var musicTrackTitle = document.getElementById('creator-music-track-title');
    var musicArtistName = document.getElementById('creator-music-artist-name');
    
    if (musicPlayedSelect) {
      musicPlayedSelect.addEventListener('change', function() {
        var showDetails = this.value === 'yes';
        if (musicDetailsDiv) musicDetailsDiv.style.display = showDetails ? 'block' : 'none';
        if (musicTypeSelect) musicTypeSelect.required = showDetails;
        
        // Reset fields if No selected
        if (!showDetails) {
          if (musicTypeSelect) musicTypeSelect.value = '';
          if (musicFieldsDiv) musicFieldsDiv.style.display = 'none';
          if (musicProofWrap) musicProofWrap.style.display = 'none';
          if (musicTrackTitle) { musicTrackTitle.value = ''; musicTrackTitle.required = false; }
          if (musicArtistName) { musicArtistName.value = ''; musicArtistName.required = false; }
          if (musicProofFile) { musicProofFile.value = ''; musicProofFile.required = false; }
        }
      });
    }
    
    if (musicTypeSelect) {
      musicTypeSelect.addEventListener('change', function() {
        var val = this.value;
        var showFields = val !== '';
        var needsProof = (val === 'licensed' || val === 'permission_obtained');
        
        if (musicFieldsDiv) musicFieldsDiv.style.display = showFields ? 'block' : 'none';
        if (musicProofWrap) musicProofWrap.style.display = needsProof ? 'block' : 'none';
        
        // Set required states
        if (musicTrackTitle) musicTrackTitle.required = showFields;
        if (musicArtistName) musicArtistName.required = showFields;
        if (musicProofFile) musicProofFile.required = needsProof;
        
        // Update proof label
        if (musicProofLabel) {
          if (val === 'licensed') {
            musicProofLabel.innerHTML = 'Proof of License <span style="color:#e88">*</span>';
          } else if (val === 'permission_obtained') {
            musicProofLabel.innerHTML = 'Proof of Permission <span style="color:#e88">*</span>';
          }
        }
      });
    }
  })();

  // ========== INITIALIZE ON DOM READY ==========
  document.addEventListener('DOMContentLoaded', function() {
    // Initialize country lists
    initCountryLists();
    
    // Setup territory toggles
    setupTerritoryToggle('music');
    setupTerritoryToggle('creator');
    setupTerritoryToggle('streamer');
  });

  // Close country dropdowns when clicking outside (desktop only - mobile uses modal)
  document.addEventListener('click', function(e) {
    // Don't interfere with modal clicks
    if (e.target.closest('#imu-country-modal')) return;
    
    if (!e.target.closest('.imu-country-dropdown')) {
      document.querySelectorAll('.imu-country-dropdown.open').forEach(function(d) {
        d.classList.remove('open');
      });
      // Clean up any stray modal
      var modal = document.getElementById('imu-country-modal');
      if (modal) modal.remove();
    }
  });
</script>

<?php get_footer();