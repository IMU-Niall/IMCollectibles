<?php
/**
 * The header for Astra Theme, customized for imcollectibles.io
 * Updated: IMCollectibles Unified Header Design v5.0
 * 
 * CHANGES v5.0 (Jan 28, 2026):
 * - Mobile UI improvements:
 *   - NOT LOGGED IN: Wallet icon with red dot (links to signin), no "Connect" button
 *   - LOGGED IN: Wallet icon with green dot (shows address on desktop)
 *   - Search: Clean magnifying glass icon that reveals search bar
 * - Hamburger menu unchanged (already perfect)
 * - Better responsive breakpoints
 *
 * PREVIOUS v4.0:
 * - Hamburger menu replaces "More" dropdown
 * - Gold dividers between sections
 * - OneSignal Push Notifications Integration
 * - HTTP cache headers so wallet-scoped responses are never cached publicly
 *
 * @package Astra
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Production cache headers — short cache for visitors, no cache for logged-in users
if (!headers_sent()) {
    if (is_user_logged_in()) {
        header('Cache-Control: no-cache, private, max-age=0');
        header('Vary: Cookie');
    } else {
        header('Cache-Control: public, max-age=300, s-maxage=600');
        header('Vary: Cookie');
    }
}

// Header version for cache busting
define('IMP_HEADER_VERSION', '5.0.' . time());

// Enforce HTTPS for PWA and service worker compatibility
if (!is_ssl()) {
    wp_redirect('https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
    exit;
}

// Check xrpl_account cookie and logout parameter
$xrpl_cookie = '';
$is_logout = isset($_GET['logout']) && $_GET['logout'] === 'true';
$is_loggedout = isset($_GET['loggedout']) && $_GET['loggedout'] === 'true';

// CRITICAL FIX: Clear cookies server-side on logout
if ($is_logout || $is_loggedout) {
    $clear_options = [
        'expires'  => time() - 3600,
        'path'     => '/',
        'domain'   => 'imcollectibles.io',
        'secure'   => true,
        'httponly' => false,
        'samesite' => 'Lax'
    ];
    setcookie('xrpl_account', '', $clear_options);
    setcookie('xrpl_account', '', time() - 3600, '/');
    // Fix D (Aug 2026): also clear the CANONICAL '.imcollectibles.io' scope. The two lines
    // above only reach the host-only and domain-less copies, so the dotted cookie -- which
    // is what every other writer now sets -- survived this logout path (?loggedout=true,
    // where handle_xaman_logout does not run). Matches page-logout.php's 3-scope teardown.
    setcookie('xrpl_account', '', array_merge($clear_options, ['domain' => '.imcollectibles.io']));
    unset($_COOKIE['xrpl_account']);
    // Session-auth 2a-b: also tear down the httponly session token on this logout path
    // (?loggedout=true landing / any header-level logout that doesn't hit ?logout=true).
    if ( function_exists('imc_session_clear') ) { imc_session_clear(); }
    $xrpl_cookie = '';
} elseif ( defined('IMC_SESSION_TOKEN_ONLY') && IMC_SESSION_TOKEN_ONLY ) {
    // Session-fix A (Aug 2026). Once token-only, the PROVEN httponly session token is the
    // SOLE source of truth for the logged-in chrome, and it is resolved INDEPENDENTLY --
    // the soft xrpl_account cookie is neither required nor consulted.
    //
    // WHY: this branch used to be gated behind `isset($_COOKIE['xrpl_account'])`, so the
    // soft cookie was a PRECONDITION for even consulting the token. A user holding a
    // perfectly valid session token but no soft cookie therefore rendered LOGGED OUT,
    // while /login/ (which reads the soft cookie) bounced them home as 'already logged
    // in' -- two sources of truth pointing opposite ways, and an unrecoverable loop.
    //
    // SPOOF SAFETY IS UNCHANGED (in fact stricter): while token-only we never fall back to
    // the soft cookie, so a spoofed/unproven xrpl_account still resolves to '' => logged-out
    // UI. If the resolver were ever missing we render logged-out rather than trusting the
    // soft cookie -- fail closed, never open.
    $xrpl_cookie = function_exists('imc_session_resolve_wallet') ? imc_session_resolve_wallet() : '';
    // Session self-heal (Aug 2026): an imc_session cookie that is PRESENT but resolves
    // EMPTY is orphaned -- its DB row was pruned (imc-session-auth.php deletes rows past
    // expiry while the 30-day cookie lives on) or removed. Left in place it sticks the
    // user in a permanent logged-out chrome with no way to reconnect. Clear it here so the
    // NEXT load is a clean logged-out state and a fresh login works. Fires ONLY when a
    // cookie is present AND unresolvable -- never a valid session, never a no-cookie
    // visitor -- and clearing a cookie grants no identity, so this is spoof-safe.
    $imc_ck = defined('IMC_SESSION_COOKIE') ? IMC_SESSION_COOKIE : 'imc_session';
    if ( $xrpl_cookie === '' && ! empty( $_COOKIE[ $imc_ck ] ) && function_exists('imc_session_clear') ) {
        imc_session_clear();
    }
} elseif (isset($_COOKIE['xrpl_account']) && preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $_COOKIE['xrpl_account'])) {
    // Pre-flip (IMC_SESSION_TOKEN_ONLY false): behaviour is byte-for-byte as before --
    // identity comes from the soft cookie. Untouched so the flag stays fully reversible.
    $xrpl_cookie = sanitize_text_field($_COOKIE['xrpl_account']);
}

// Short wallet address for display
$wallet_short = $xrpl_cookie ? substr($xrpl_cookie, 0, 6) . '...' . substr($xrpl_cookie, -4) : '';

// v195: T&C acceptance gate — check if signed-in wallet has accepted current version
$imc_tc_accepted = true; // Default true for visitors (no wallet = no gate)
if (!empty($xrpl_cookie)) {
    global $wpdb;
    $tc_table   = $wpdb->prefix . 'imc_tc_acceptance';
    $tc_version = defined('IMC_TC_VERSION') ? IMC_TC_VERSION : '1.0';
    
    // Transient cache first (avoids DB on every page load)
    $tc_cache_key = 'imc_tc_' . md5($xrpl_cookie . '_' . $tc_version);
    $tc_cached = get_transient($tc_cache_key);
    
    if ($tc_cached === 'accepted') {
        $imc_tc_accepted = true;
    } else {
        // Check if table exists then query
        $tc_table_exists = ($wpdb->get_var("SHOW TABLES LIKE '$tc_table'") === $tc_table);
        if ($tc_table_exists) {
            $tc_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $tc_table WHERE wallet_address = %s AND tc_version = %s",
                $xrpl_cookie, $tc_version
            ));
            $imc_tc_accepted = ($tc_count > 0);
            if ($imc_tc_accepted) {
                set_transient($tc_cache_key, 'accepted', 86400);
            }
        } else {
            // Table doesn't exist yet — will be created on first accept
            $imc_tc_accepted = false;
        }
    }
}

// v354: Bypass T&C modal on /terms and /privacy pages so users can read them freely
$_imc_current_page = get_query_var('imc_page', '');
$imc_is_legal_page = in_array($_imc_current_page, ['terms', 'privacy'], true);

// Current page detection for active states
$current_url = home_url($_SERVER['REQUEST_URI']);
$current_path = parse_url($current_url, PHP_URL_PATH);

?><!DOCTYPE html>
<?php astra_html_before(); ?>
<html <?php language_attributes(); ?>>
<head>
<?php astra_head_top(); ?>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">

<?php if (apply_filters('astra_header_profile_gmpg_link', true)) { ?>
    <link rel="profile" href="https://gmpg.org/xfn/11"> 
<?php } ?>

<?php if (!empty($xrpl_cookie)): ?>
<!-- OneSignal Push Notifications SDK (logged-in users only) -->
<script src="https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js" defer></script>
<script>
(function() {
    const CURRENT_ACCOUNT = "<?php echo esc_js($xrpl_cookie); ?>";
    const APP_ID = "<?php echo esc_js(defined('ONESIGNAL_APP_ID') ? ONESIGNAL_APP_ID : '90024f74-cf49-40b8-961e-8594e8e287ce'); ?>";
    
    // Clean up old service workers first
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.getRegistrations().then(function(registrations) {
            registrations.forEach(function(reg) {
                if (reg.active && reg.active.scriptURL.includes('service-worker.js') && !reg.active.scriptURL.includes('OneSignal')) {
                    reg.unregister();
                }
            });
        });
        
        navigator.serviceWorker.register('/OneSignalSDKWorker.js', { scope: '/' })
            .then(function(reg) {
                // service worker registered
            })
            .catch(function(err) {
                console.warn('[Push] Service worker registration failed:', err);
            });
    }
    
    window.OneSignalDeferred = window.OneSignalDeferred || [];
    OneSignalDeferred.push(async function(OneSignal) {
        try {
            await OneSignal.init({
                appId: APP_ID,
                notifyButton: { enable: false },
                serviceWorkerPath: '/OneSignalSDKWorker.js',
                serviceWorkerParam: { scope: '/' },
                promptOptions: {
                    slidedown: {
                        prompts: [{
                            type: "push",
                            autoPrompt: true,
                            text: {
                                actionMessage: "Get notified when your XFT rewards are ready to claim!",
                                acceptButton: "Enable Notifications",
                                cancelButton: "Maybe Later"
                            },
                            delay: { pageViews: 1, timeDelay: 3 }
                        }]
                    }
                },
                welcomeNotification: {
                    title: "Welcome to IMCollectibles! 🎉",
                    message: "You'll now receive alerts when rewards are ready."
                }
            });
            
            try {
                const currentExternalId = OneSignal.User.externalId;
                if (currentExternalId === CURRENT_ACCOUNT) {
                    // already linked
                } else if (currentExternalId) {
                    await OneSignal.logout();
                    await OneSignal.login(CURRENT_ACCOUNT);
                } else {
                    await OneSignal.login(CURRENT_ACCOUNT);
                }
            } catch (loginErr) {
                // login error non-fatal
            }
        } catch (initErr) {
            // silent — push notifications non-critical
        }
    });
})();
</script>
<?php endif; ?>

<!-- PWA Manifest -->
<link rel="manifest" href="<?php echo esc_url(home_url('/manifest.json')); ?>">

<!-- iOS Safari PWA Support -->
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="IMCollectibles">
<link rel="apple-touch-icon" href="<?php echo esc_url(home_url('/wp-content/uploads/icon-192x192.png')); ?>">

<!-- Theme Color -->
<meta name="theme-color" content="#0a0a0f">
<meta name="msapplication-TileColor" content="#0a0a0f">
<!-- v705: declare the site as dark-by-design so dark-mode browsers/OS don't apply
     their OWN auto-darkening transform on top of the custom dark UI (which tinted
     form controls, scrollbars, and system surfaces and broke contrast). -->
<meta name="color-scheme" content="dark">

<!-- Unified Header Styles v5.0 -->
<style>
/* ============================================
   IMCOLLECTIBLES UNIFIED HEADER v5.0
   Mobile-First Improvements
   ============================================ */

/* v705: pair with <meta name="color-scheme" content="dark">. Tells the browser the
   UI is natively dark so it renders form controls, scrollbars and system surfaces
   in dark WITHOUT layering its own dark-mode auto-tint on top of the custom theme.
   Purely additive — does not restyle any element, only opts out of browser tinting. */
:root { color-scheme: dark; }
:root {
    --imp-gold: var(--imu-gold, #d6ba66);
    --imp-gold-light: #e8d38a;
    --imp-gold-dark: #b89d4a;
    --imp-bg-dark: #0a0a0f;
    --imp-bg-card: #12121a;
    --imp-border: rgba(var(--imu-gold-rgb), 0.15);
    --imp-border-gold: rgba(var(--imu-gold-rgb), 0.4);
    --imp-text: #e8e6e3;
    --imp-text-muted: #8a8a8a;
    --imp-success: #10b981;
    --imp-error: #ef4444;
    --imp-header-height: 70px;
    --imp-logo-size: 42px;
}

body.has-imp-header {
    padding-top: var(--imp-header-height) !important;
}

.imp-top-bar {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    height: var(--imp-header-height) !important;
    background: rgba(10, 10, 15, 0.97) !important;
    backdrop-filter: blur(20px) !important;
    border-bottom: 1px solid var(--imp-border) !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    padding: 0 1rem !important;
    z-index: 99999 !important;
}

/* Logo */
.imp-logo {
    display: flex !important;
    align-items: center !important;
    gap: 0.6rem !important;
    text-decoration: none !important;
    font-weight: 600 !important;
    font-size: 1.1rem !important;
    flex-shrink: 0 !important;
}

.imp-logo img {
    width: var(--imp-logo-size) !important;
    height: var(--imp-logo-size) !important;
    border-radius: 50% !important;
}

.imp-logo:hover {
    color: var(--imp-gold-light) !important;
}

.imp-logo-text {
    display: none !important;
    /* v334 Phase 3: Cinzel Decorative gold gradient — matches sitewide heading standard */
    font-family: 'Cinzel Decorative', 'Cinzel', 'Times New Roman', serif !important;
    font-weight: 700 !important;
    letter-spacing: 0.06em !important;
    background: linear-gradient(180deg,
        #ffe066 0%,
        var(--imu-gold, #d6ba66) 40%,
        #996515 100%
    ) !important;
    -webkit-background-clip: text !important;
    -webkit-text-fill-color: transparent !important;
    background-clip: text !important;
    filter: drop-shadow(0 0 12px rgba(212, 175, 55, 0.4)) !important;
}

@media (min-width: 769px) {
    .imp-logo-text {
        display: inline !important;
    }
}

/* ============================================
   SEARCH BAR
   ============================================ */
.imp-search-wrapper {
    position: relative !important;
    flex: 1 !important;
    max-width: 400px !important;
    margin: 0 1rem !important;
    display: none !important;
}

@media (min-width: 769px) {
    .imp-search-wrapper {
        display: block !important;
    }
}

.imp-search-wrapper.mobile-active {
    display: block !important;
    position: fixed !important;
    top: var(--imp-header-height) !important;
    left: 0 !important;
    right: 0 !important;
    max-width: 100% !important;
    margin: 0 !important;
    padding: 0.75rem 1rem !important;
    background: rgba(10, 10, 15, 0.98) !important;
    border-bottom: 1px solid var(--imp-border) !important;
    z-index: 99998 !important;
}

.imp-search-box {
    display: flex !important;
    align-items: center !important;
    background: var(--imp-bg-card) !important;
    border: 1px solid var(--imp-border) !important;
    border-radius: 50px !important;
    overflow: hidden !important;
    transition: all 0.3s ease !important;
}

.imp-search-box:focus-within {
    border-color: var(--imp-gold) !important;
    box-shadow: 0 0 0 2px rgba(var(--imu-gold-rgb), 0.15) !important;
}

.imp-search-input {
    flex: 1 !important;
    background: transparent !important;
    border: none !important;
    padding: 0.6rem 1rem !important;
    color: var(--imp-text) !important;
    font-size: 0.9rem !important;
    outline: none !important;
    min-width: 0 !important;
}

.imp-search-input::placeholder {
    color: var(--imp-text-muted) !important;
}

.imp-search-btn {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 38px !important;
    height: 38px !important;
    background: transparent !important;
    border: none !important;
    color: var(--imp-gold) !important;
    cursor: pointer !important;
    transition: all 0.2s ease !important;
    flex-shrink: 0 !important;
}

.imp-search-btn:hover {
    color: var(--imp-gold-light) !important;
    background: rgba(var(--imu-gold-rgb), 0.1) !important;
}

/* Search Results Dropdown */
.imp-search-results {
    position: absolute !important;
    top: calc(100% + 8px) !important;
    left: 0 !important;
    width: 100% !important;
    min-width: 280px !important;
    background: var(--imp-bg-card) !important;
    border: 1px solid var(--imp-border-gold) !important;
    border-radius: 12px !important;
    max-height: 400px !important;
    overflow-y: auto !important;
    z-index: 100000 !important;
    display: none !important;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5) !important;
}

.imp-search-results.active {
    display: block !important;
}

.imp-search-results::-webkit-scrollbar { width: 6px !important; }
.imp-search-results::-webkit-scrollbar-thumb { background: var(--imp-gold) !important; border-radius: 3px !important; }

.imp-search-result-item {
    display: flex !important;
    align-items: center !important;
    gap: 0.75rem !important;
    padding: 0.75rem 1rem !important;
    cursor: pointer !important;
    transition: background 0.2s ease !important;
    text-decoration: none !important;
    color: var(--imp-text) !important;
    border-bottom: 1px solid var(--imp-border) !important;
}

.imp-search-result-item:last-child { border-bottom: none !important; }
.imp-search-result-item:hover { background: rgba(var(--imu-gold-rgb), 0.1) !important; }

.imp-search-result-img {
    width: 42px !important;
    height: 42px !important;
    border-radius: 8px !important;
    object-fit: cover !important;
    background: var(--imp-bg-dark) !important;
}

.imp-search-result-info { flex: 1 !important; min-width: 0 !important; }

.imp-search-result-name {
    font-weight: 600 !important;
    color: var(--imp-text) !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    font-size: 0.9rem !important;
}

.imp-search-result-meta {
    font-size: 0.75rem !important;
    color: var(--imp-text-muted) !important;
    display: flex !important;
    gap: 0.5rem !important;
    margin-top: 2px !important;
}

.imp-search-loading,
.imp-search-empty {
    padding: 1.25rem !important;
    text-align: center !important;
    color: var(--imp-text-muted) !important;
    font-size: 0.9rem !important;
}

.imp-search-loading::before {
    content: '' !important;
    display: inline-block !important;
    width: 14px !important;
    height: 14px !important;
    border: 2px solid var(--imp-gold) !important;
    border-top-color: transparent !important;
    border-radius: 50% !important;
    animation: impSearchSpin 0.8s linear infinite !important;
    margin-right: 0.5rem !important;
    vertical-align: middle !important;
}

@keyframes impSearchSpin { to { transform: rotate(360deg); } }

.imp-search-section-label {
    padding: 0.5rem 1rem !important;
    font-size: 0.7rem !important;
    text-transform: uppercase !important;
    color: var(--imp-gold) !important;
    font-weight: 600 !important;
    letter-spacing: 0.5px !important;
    background: rgba(var(--imu-gold-rgb), 0.05) !important;
}

/* ============================================
   RIGHT SIDE CONTROLS
   ============================================ */
.imp-controls {
    display: flex !important;
    align-items: center !important;
    gap: 0.5rem !important;
    flex-shrink: 0 !important;
}

@media (min-width: 769px) {
    .imp-controls {
        gap: 0.75rem !important;
    }
}

/* ============================================
   SEARCH TOGGLE (Mobile) - Clean magnifying glass
   ============================================ */
.imp-search-toggle {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 40px !important;
    height: 40px !important;
    background: transparent !important;
    border: none !important;
    color: var(--imp-gold) !important;
    cursor: pointer !important;
    transition: all 0.2s ease !important;
    border-radius: 50% !important;
    padding: 0 !important;
}

.imp-search-toggle:hover {
    background: rgba(var(--imu-gold-rgb), 0.1) !important;
}

.imp-search-toggle.active {
    background: rgba(var(--imu-gold-rgb), 0.15) !important;
}

@media (min-width: 769px) {
    .imp-search-toggle {
        display: none !important;
    }
}

/* ============================================
   WALLET BUTTON - Icon with status dot
   ============================================ */
.imp-wallet-btn {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    position: relative !important;
    width: 40px !important;
    height: 40px !important;
    background: var(--imp-bg-card) !important;
    border: 1px solid var(--imp-border) !important;
    border-radius: 50% !important;
    color: var(--imp-text-muted) !important;
    cursor: pointer !important;
    transition: all 0.2s ease !important;
    text-decoration: none !important;
}

.imp-wallet-btn:hover {
    border-color: var(--imp-gold) !important;
    color: var(--imp-gold) !important;
}

.imp-wallet-btn.connected {
    color: var(--imp-gold) !important;
    border-color: var(--imp-border-gold) !important;
}

.imp-wallet-btn .wallet-dot {
    position: absolute !important;
    top: 2px !important;
    right: 2px !important;
    width: 10px !important;
    height: 10px !important;
    border-radius: 50% !important;
    background: var(--imp-error) !important;
    border: 2px solid var(--imp-bg-card) !important;
}

.imp-wallet-btn.connected .wallet-dot {
    background: var(--imp-success) !important;
}

/* Desktop: Show address next to wallet icon */
.imp-wallet-address {
    display: none !important;
    color: var(--imp-gold) !important;
    font-size: 0.85rem !important;
    font-weight: 500 !important;
    margin-left: 0.5rem !important;
}

@media (min-width: 769px) {
    .imp-wallet-btn.connected + .imp-wallet-address,
    .imp-wallet-group.connected .imp-wallet-address {
        display: inline !important;
    }
    
    .imp-wallet-group {
        display: flex !important;
        align-items: center !important;
        background: var(--imp-bg-card) !important;
        border: 1px solid var(--imp-border) !important;
        border-radius: 50px !important;
        padding: 0.35rem !important;
        padding-right: 1rem !important;
    }
    
    .imp-wallet-group .imp-wallet-btn {
        border: none !important;
        background: transparent !important;
        width: 32px !important;
        height: 32px !important;
    }
    
    .imp-wallet-group:hover {
        border-color: var(--imp-gold) !important;
    }
}

/* ============================================
   HAMBURGER MENU BUTTON
   ============================================ */
.imp-hamburger {
    display: flex !important;
    flex-direction: column !important;
    justify-content: center !important;
    align-items: center !important;
    width: 40px !important;
    height: 40px !important;
    background: var(--imp-bg-card) !important;
    border: 1px solid var(--imp-border) !important;
    border-radius: 10px !important;
    cursor: pointer !important;
    padding: 0 !important;
    gap: 5px !important;
    transition: all 0.3s !important;
}

.imp-hamburger:hover {
    border-color: var(--imp-gold) !important;
}

.imp-hamburger span {
    display: block !important;
    width: 18px !important;
    height: 2px !important;
    background: var(--imp-gold) !important;
    border-radius: 2px !important;
    transition: all 0.3s !important;
}

.imp-hamburger.active {
    border-color: var(--imp-gold) !important;
}

.imp-create-btn{display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:9px;border:1px solid rgba(212,175,55,.4);background:rgba(212,175,55,.1);color:#d4af37;text-decoration:none;transition:background .15s,border-color .15s}
.imp-create-btn:hover{background:rgba(212,175,55,.22);border-color:#d4af37}
.imp-hamburger.active span:nth-child(1) {
    transform: rotate(45deg) translate(5px, 5px) !important;
}

.imp-hamburger.active span:nth-child(2) {
    opacity: 0 !important;
}

.imp-hamburger.active span:nth-child(3) {
    transform: rotate(-45deg) translate(5px, -5px) !important;
}

/* Custom Gold Scrollbar for Dropdown */
.imp-dropdown-menu::-webkit-scrollbar { width: 8px; }
.imp-dropdown-menu::-webkit-scrollbar-track { background: var(--imp-bg-dark); border-radius: 4px; }
.imp-dropdown-menu::-webkit-scrollbar-thumb { background: linear-gradient(180deg, var(--imp-gold-dark), var(--imp-gold)); border-radius: 4px; }
.imp-dropdown-menu::-webkit-scrollbar-thumb:hover { background: linear-gradient(180deg, var(--imp-gold), var(--imp-gold-light)); }
.imp-dropdown-menu { scrollbar-width: thin; scrollbar-color: var(--imp-gold) var(--imp-bg-dark); }

/* ============================================
   DROPDOWN MENU
   ============================================ */
.imp-dropdown-menu {
    position: fixed !important;
    top: var(--imp-header-height) !important;
    right: 0 !important;
    width: 300px !important;
    max-height: calc(100vh - var(--imp-header-height)) !important;
    overflow-y: auto !important;
    background: var(--imp-bg-card) !important;
    border: 1px solid var(--imp-border-gold) !important;
    border-top: none !important;
    border-radius: 0 0 0 16px !important;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5) !important;
    z-index: 99998 !important;
    opacity: 0 !important;
    visibility: hidden !important;
    transform: translateX(20px) !important;
    transition: all 0.3s ease !important;
}

.imp-dropdown-menu.active {
    opacity: 1 !important;
    visibility: visible !important;
    transform: translateX(0) !important;
}

/* Menu Links */
.imp-dropdown-menu a {
    display: flex !important;
    align-items: center !important;
    gap: 0.75rem !important;
    padding: 0.85rem 1.25rem !important;
    color: var(--imp-text) !important;
    text-decoration: none !important;
    font-size: 0.95rem !important;
    transition: all 0.2s !important;
    border-left: 3px solid transparent !important;
}

.imp-dropdown-menu a:hover,
.imp-dropdown-menu a.active {
    background: rgba(var(--imu-gold-rgb), 0.1) !important;
    color: var(--imp-gold) !important;
    border-left-color: var(--imp-gold) !important;
}

.imp-dropdown-menu a[target="_blank"]::after {
    content: '↗' !important;
    margin-left: auto !important;
    font-size: 0.8rem !important;
    opacity: 0.5 !important;
}

/* Gold Dividers */
.imp-dropdown-menu .divider {
    height: 1px !important;
    background: linear-gradient(90deg, transparent, var(--imp-gold), transparent) !important;
    margin: 0.5rem 1rem !important;
    opacity: 0.4 !important;
}

/* Section Labels */
.imp-dropdown-menu .section-label {
    padding: 0.6rem 1.25rem 0.4rem !important;
    font-size: 0.7rem !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.1em !important;
    color: var(--imp-gold) !important;
    opacity: 0.7 !important;
    pointer-events: none !important;
}

/* Wallet Section in Dropdown */
.imp-dropdown-wallet {
    padding: 1rem 1.25rem !important;
    border-top: 1px solid var(--imp-border-gold) !important;
    margin-top: 0.5rem !important;
}

.imp-dropdown-wallet-status {
    display: flex !important;
    align-items: center !important;
    gap: 0.5rem !important;
    padding: 0.5rem 0 !important;
    margin-bottom: 0.75rem !important;
}

.imp-dropdown-wallet-status .dot {
    width: 8px !important;
    height: 8px !important;
    border-radius: 50% !important;
    background: var(--imp-error) !important;
}

.imp-dropdown-wallet-status.connected .dot {
    background: var(--imp-success) !important;
}

.imp-dropdown-wallet-status .label {
    color: var(--imp-text-muted) !important;
    font-size: 0.85rem !important;
}

.imp-dropdown-wallet-status.connected .label {
    color: var(--imp-gold) !important;
}

.imp-dropdown-wallet .imp-connect-btn,
.imp-dropdown-wallet .imp-disconnect-btn {
    display: block !important;
    width: 100% !important;
    text-align: center !important;
    padding: 0.65rem 1rem !important;
    border-radius: 50px !important;
    font-weight: 600 !important;
    font-size: 0.9rem !important;
    text-decoration: none !important;
    transition: all 0.3s !important;
}

.imp-dropdown-wallet .imp-connect-btn {
    background: linear-gradient(135deg, var(--imp-gold-dark), var(--imp-gold)) !important;
    color: #000 !important;
}

.imp-dropdown-wallet .imp-connect-btn:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 5px 20px rgba(var(--imu-gold-rgb), 0.4) !important;
}

.imp-disconnect-btn {
    background: transparent !important;
    border: 1px solid var(--imp-border) !important;
    color: var(--imp-text-muted) !important;
}

.imp-disconnect-btn:hover {
    border-color: var(--imp-error) !important;
    color: var(--imp-error) !important;
}

/* ============================================
   WALLET POPUP (Account dropdown from icon)
   ============================================ */
.imp-wallet-wrapper {
    position: relative !important;
}

.imp-wallet-group {
    cursor: pointer !important;
}

.imp-wallet-popup {
    position: absolute !important;
    top: calc(100% + 12px) !important;
    right: 0 !important;
    width: 300px !important;
    background: var(--imp-bg-card) !important;
    border: 1px solid var(--imp-border-gold) !important;
    border-radius: 16px !important;
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.6) !important;
    z-index: 100000 !important;
    opacity: 0 !important;
    visibility: hidden !important;
    transform: translateY(-8px) scale(0.97) !important;
    transition: all 0.2s ease !important;
    padding: 1rem !important;
}

.imp-wallet-popup.active {
    opacity: 1 !important;
    visibility: visible !important;
    transform: translateY(0) scale(1) !important;
}

.imp-wallet-popup-arrow {
    position: absolute !important;
    top: -6px !important;
    right: 20px !important;
    width: 12px !important;
    height: 12px !important;
    background: var(--imp-bg-card) !important;
    border: 1px solid var(--imp-border-gold) !important;
    border-right: none !important;
    border-bottom: none !important;
    transform: rotate(45deg) !important;
}

.imp-wallet-popup-status {
    display: flex !important;
    align-items: center !important;
    gap: 0.5rem !important;
    margin-bottom: 0.75rem !important;
}

.imp-wallet-popup-status .dot {
    width: 8px !important;
    height: 8px !important;
    border-radius: 50% !important;
    background: var(--imp-error) !important;
    flex-shrink: 0 !important;
}

.imp-wallet-popup-status .dot.connected {
    background: var(--imp-success) !important;
}

.imp-wallet-popup-status .status-text {
    font-size: 0.8rem !important;
    font-weight: 600 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.05em !important;
    color: var(--imp-text-muted) !important;
}

.imp-wallet-popup-address {
    display: flex !important;
    align-items: center !important;
    gap: 0.5rem !important;
    padding: 0.65rem 0.85rem !important;
    background: var(--imp-bg-dark) !important;
    border: 1px solid var(--imp-border) !important;
    border-radius: 10px !important;
    cursor: pointer !important;
    transition: all 0.2s !important;
    margin-bottom: 0.75rem !important;
    position: relative !important;
}

.imp-wallet-popup-address:hover {
    border-color: var(--imp-gold) !important;
}

.imp-wallet-popup-address .address-text {
    font-family: 'SF Mono', 'Fira Code', 'Cascadia Code', Consolas, 'Courier New', monospace !important;
    font-size: 0.75rem !important;
    color: var(--imp-gold) !important;
    word-break: break-all !important;
    flex: 1 !important;
    line-height: 1.4 !important;
}

.imp-wallet-popup-address .copy-icon {
    color: var(--imp-text-muted) !important;
    flex-shrink: 0 !important;
    transition: color 0.2s !important;
}

.imp-wallet-popup-address:hover .copy-icon {
    color: var(--imp-gold) !important;
}

.imp-wallet-popup-address .copy-toast {
    position: absolute !important;
    top: -28px !important;
    right: 8px !important;
    background: var(--imp-gold) !important;
    color: #000 !important;
    font-size: 0.7rem !important;
    font-weight: 700 !important;
    padding: 3px 10px !important;
    border-radius: 6px !important;
    opacity: 0 !important;
    transition: opacity 0.2s !important;
    pointer-events: none !important;
}

.imp-wallet-popup-address .copy-toast.show {
    opacity: 1 !important;
}

.imp-wallet-popup-link {
    display: block !important;
    padding: 0.6rem 0.85rem !important;
    color: var(--imp-text) !important;
    text-decoration: none !important;
    font-size: 0.9rem !important;
    border-radius: 8px !important;
    transition: all 0.2s !important;
}

.imp-wallet-popup-link:hover {
    background: rgba(var(--imu-gold-rgb), 0.1) !important;
    color: var(--imp-gold) !important;
}

.imp-wallet-popup-divider {
    height: 1px !important;
    background: linear-gradient(90deg, transparent, var(--imp-gold), transparent) !important;
    margin: 0.5rem 0 !important;
    opacity: 0.3 !important;
}

.imp-wallet-popup-info {
    font-size: 0.85rem !important;
    color: var(--imp-text-muted) !important;
    line-height: 1.5 !important;
    margin: 0 0 1rem 0 !important;
}

.imp-wallet-popup-connect {
    display: block !important;
    width: 100% !important;
    text-align: center !important;
    padding: 0.7rem 1rem !important;
    background: linear-gradient(135deg, var(--imp-gold-dark), var(--imp-gold)) !important;
    color: #000 !important;
    font-weight: 700 !important;
    font-size: 0.9rem !important;
    border-radius: 50px !important;
    text-decoration: none !important;
    transition: all 0.3s !important;
}

.imp-wallet-popup-connect:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 5px 20px rgba(var(--imu-gold-rgb), 0.4) !important;
    color: #000 !important;
}

.imp-wallet-popup-disconnect {
    display: block !important;
    width: 100% !important;
    text-align: center !important;
    padding: 0.6rem 1rem !important;
    background: transparent !important;
    border: 1px solid var(--imp-border) !important;
    color: var(--imp-text-muted) !important;
    font-weight: 600 !important;
    font-size: 0.85rem !important;
    border-radius: 50px !important;
    text-decoration: none !important;
    transition: all 0.3s !important;
}

.imp-wallet-popup-disconnect:hover {
    border-color: var(--imp-error) !important;
    color: var(--imp-error) !important;
}

/* Mobile: popup takes more width */
@media (max-width: 768px) {
    .imp-wallet-popup {
        right: -40px !important;
        width: 280px !important;
    }
    .imp-wallet-popup-arrow {
        right: 55px !important;
    }
}

/* Backdrop overlay when menu open */
.imp-backdrop {
    position: fixed !important;
    top: var(--imp-header-height) !important;
    left: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    background: rgba(0, 0, 0, 0.5) !important;
    z-index: 99997 !important;
    opacity: 0 !important;
    visibility: hidden !important;
    transition: all 0.3s !important;
}

.imp-backdrop.active {
    opacity: 1 !important;
    visibility: visible !important;
}

/* ============================================
   MOBILE RESPONSIVE
   ============================================ */
@media (max-width: 480px) {
    .imp-top-bar {
        padding: 0 0.75rem !important;
    }
    
    .imp-controls {
        gap: 0.35rem !important;
    }
    
    .imp-dropdown-menu {
        width: 100% !important;
        border-radius: 0 !important;
        border-left: none !important;
        border-right: none !important;
    }
    
    .imp-search-results {
        border-radius: 0 0 12px 12px !important;
        max-height: 60vh !important;
    }
}

/* Hide Astra's default header */
.ast-header-html-1,
.ast-builder-menu-1,
.ast-main-header-wrap,
.ast-main-header-bar-alignment,
.ast-mobile-header-wrap,
.main-header-bar,
#ast-mobile-header,
.site-header {
    display: none !important;
}
</style>

<?php wp_head(); ?>
<?php astra_head_bottom(); ?>
</head>

<body <?php astra_schema_body(); ?> <?php body_class('has-imp-header'); ?>>
<?php astra_body_top(); ?>
<?php wp_body_open(); ?>

<a class="skip-link screen-reader-text" href="#content" title="<?php echo esc_attr(astra_default_strings('string-header-skip-link', false)); ?>">
    <?php echo esc_html(astra_default_strings('string-header-skip-link', false)); ?>
</a>

<div <?php echo wp_kses_post(astra_attr('site', array('id' => 'page', 'class' => 'hfeed site'))); ?>>
    <?php astra_header_before(); ?>

    <!-- ============================================
         IMCOLLECTIBLES UNIFIED HEADER v5.0
         Mobile-First Design
         ============================================ -->
    <div class="imp-top-bar">
        <!-- Logo -->
        <a href="<?php echo esc_url(home_url('/')); ?>" class="imp-logo">
            <img src="<?php echo esc_url(get_template_directory_uri()); ?>/images/protectors-logo.png" alt="<?php bloginfo('name'); ?>" onerror="this.src='https://imcollectibles.io/wp-content/uploads/2026/07/3.png'">
            <span class="imp-logo-text">IMCollectibles</span>
        </a>

        <!-- Search Bar (Desktop only - centered) -->
        <div class="imp-search-wrapper" id="imp-search-wrapper">
            <div class="imp-search-box">
                <input type="text" 
                       id="imp-search-input" 
                       class="imp-search-input" 
                       placeholder="Search drops, collections, creators or paste NFT ID..." 
                       autocomplete="off"
                       aria-label="Search">
                <button type="button" id="imp-search-btn" class="imp-search-btn" aria-label="Search">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"></circle>
                        <path d="M21 21l-4.35-4.35"></path>
                    </svg>
                </button>
            </div>
            <div class="imp-search-results" id="imp-search-results"></div>
        </div>

        <?php
        // P3-C2: creator flag computed once (reused by the "+" button, the wallet
        // dropdown NFT Manager entry, and the menu NFT Manager entry).
        $imc_is_creator = false;
        if ($xrpl_cookie) {
            global $wpdb;
            $imc_lt = $wpdb->prefix . 'imc_listings';
            $imc_is_creator = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $imc_lt WHERE artist_account = %s LIMIT 1", $xrpl_cookie
            )) > 0;
        }
        ?>
        <!-- Right Side Controls -->
        <div class="imp-controls">
            <!-- Search Toggle (Mobile only) - Clean magnifying glass -->
            <button type="button" class="imp-search-toggle" id="imp-search-toggle" aria-label="Search">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <circle cx="11" cy="11" r="7"></circle>
                    <path d="M21 21l-4.35-4.35"></path>
                </svg>
            </button>
            
            <!-- Wallet Button with Status Dot + Account Popup -->
            <div class="imp-wallet-wrapper" id="imp-wallet-wrapper">
                <?php if ($xrpl_cookie): ?>
                    <!-- Connected: Wallet icon with green dot + address on desktop -->
                    <div class="imp-wallet-group connected" onclick="impToggleWalletPopup(event)">
                        <div class="imp-wallet-btn connected" title="<?php echo esc_attr($xrpl_cookie); ?>">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="2" y="6" width="20" height="12" rx="2"/>
                                <path d="M16 12h.01"/>
                                <path d="M2 10h20"/>
                            </svg>
                            <span class="wallet-dot"></span>
                        </div>
                        <span class="imp-wallet-address"><?php echo esc_html($wallet_short); ?></span>
                    </div>
                <?php else: ?>
                    <!-- Not Connected: Wallet icon with red dot -->
                    <div class="imp-wallet-btn" onclick="impToggleWalletPopup(event)" title="Connect Wallet">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="2" y="6" width="20" height="12" rx="2"/>
                            <path d="M16 12h.01"/>
                            <path d="M2 10h20"/>
                        </svg>
                        <span class="wallet-dot"></span>
                    </div>
                <?php endif; ?>
                
                <!-- Account Popup -->
                <div class="imp-wallet-popup" id="imp-wallet-popup">
                    <div class="imp-wallet-popup-arrow"></div>
                    <?php if ($xrpl_cookie): ?>
                        <div class="imp-wallet-popup-status">
                            <span class="dot connected"></span>
                            <span class="status-text">Connected</span>
                        </div>
                        <div class="imp-wallet-popup-address" onclick="impCopyWallet(event)" title="Click to copy full address">
                            <span class="address-text"><?php echo esc_html($xrpl_cookie); ?></span>
                            <svg class="copy-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                                <path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>
                            </svg>
                            <span class="copy-toast" id="imp-copy-toast">Copied!</span>
                        </div>
                        <style>
                        #impWbRow{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 10px;margin:6px 0;background:rgba(212,175,55,.08);border:1px solid rgba(212,175,55,.25);border-radius:8px;cursor:pointer;font-size:.85rem}
                        #impWbRow .wb-avail b{color:#d4af37;font-weight:700}
                        #impWbRow .wb-chev{color:#d4af37}
                        #impWbPanel{max-height:220px;overflow-y:auto;margin:0 0 6px;padding:8px 10px;background:#141420;border:1px solid #2b2b40;border-radius:8px;font-size:.8rem}
                        #impWbPanel .wb-line{display:flex;justify-content:space-between;gap:10px;padding:3px 0;border-bottom:1px solid rgba(255,255,255,.05)}
                        #impWbPanel .wb-line:last-child{border-bottom:none}
                        #impWbPanel .wb-muted{color:#8a8aa4}
                        </style>
                        <div class="imp-wallet-balance" id="impWbRow" onclick="impToggleHoldings(event)">
                            <span class="wb-avail">Available: <b id="impWbXrp">&hellip;</b></span>
                            <span class="wb-chev" id="impWbChev">&#9662;</span>
                        </div>
                        <div class="imp-wallet-holdings" id="impWbPanel" style="display:none"></div>
                        <a href="<?php echo esc_url(home_url('/trading-hub-dashboard/')); ?>" class="imp-wallet-popup-link">📊 Offers Hub</a>
                        <a href="<?php echo esc_url(home_url('/user/' . $xrpl_cookie)); ?>" class="imp-wallet-popup-link">👤 My Profile</a>
                        <?php if ($imc_is_creator): ?><a href="<?php echo esc_url(home_url('/creator-dashboard/')); ?>" class="imp-wallet-popup-link">🎨 NFT Manager</a><?php endif; ?>
                        <div class="imp-wallet-popup-divider"></div>
                        <a href="<?php echo esc_url(home_url('/?logout=true')); ?>" class="imp-wallet-popup-disconnect">Disconnect Wallet</a>
                    <?php else: ?>
                        <div class="imp-wallet-popup-status">
                            <span class="dot"></span>
                            <span class="status-text">Not Connected</span>
                        </div>
                        <p class="imp-wallet-popup-info">Connect your Xaman wallet to mint, trade, and collect NFTs.</p>
                        <a href="<?php echo esc_url(home_url('/login/?redirect=' . urlencode($current_url))); ?>" class="imp-wallet-popup-connect">Connect Wallet</a>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ($xrpl_cookie): ?>
            <!-- P3-C2: quick Create an NFT (logged-in only) -->
            <a class="imp-create-btn" href="<?php echo esc_url(home_url('/mint/')); ?>" title="Create an NFT" aria-label="Create an NFT">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
            </a>
            <?php endif; ?>
            
            <!-- Hamburger Menu Toggle -->
            <button class="imp-hamburger" id="imp-hamburger" onclick="impToggleMenu()" aria-label="Toggle menu">
                <span></span><span></span><span></span>
            </button>
        </div>
    </div>

    <!-- Backdrop -->
    <div class="imp-backdrop" id="imp-backdrop" onclick="impCloseMenu()"></div>

    <!-- Dropdown Menu -->
    <div class="imp-dropdown-menu" id="imp-dropdown-menu">
        <!-- Primary Navigation -->
        <a href="<?php echo esc_url(home_url('/')); ?>"<?php echo is_front_page() ? ' class="active"' : ''; ?>>🏠 Home</a>
        <?php if ($xrpl_cookie): ?><a href="<?php echo esc_url(home_url('/user/' . $xrpl_cookie)); ?>"<?php echo strpos($current_path, '/user/') !== false ? ' class="active"' : ''; ?>>👤 My Profile</a><?php endif; ?>
        <a href="<?php echo esc_url(home_url('/trading-hub-dashboard/')); ?>"<?php echo strpos($current_path, '/trading-hub-dashboard') !== false ? ' class="active"' : ''; ?>>📊 Offers Hub</a>
        <a href="<?php echo esc_url(home_url('/tasks/')); ?>"<?php echo strpos($current_path, '/tasks') !== false ? ' class="active"' : ''; ?>>✅ Tasks</a>
        <a href="<?php echo esc_url(home_url('/redeem/')); ?>"<?php echo strpos($current_path, '/redeem') !== false ? ' class="active"' : ''; ?>>🎁 Redeem</a>
        
        <div class="divider"></div>
        
        <!-- Collectibles Section -->
        <div class="section-label">Collectibles</div>
        <a href="<?php echo esc_url(home_url('/mint/')); ?>"<?php echo strpos($current_path, '/mint') !== false ? ' class="active"' : ''; ?>>➕ Create an NFT</a>
        <?php if ($imc_is_creator): ?><a href="<?php echo esc_url(home_url('/creator-dashboard/')); ?>"<?php echo strpos($current_path, '/creator-dashboard') !== false ? ' class="active"' : ''; ?>>🎨 NFT Manager</a><?php endif; ?>
        <a href="<?php echo esc_url(home_url('/recent-mints/')); ?>"<?php echo strpos($current_path, '/recent-mints') !== false ? ' class="active"' : ''; ?>>🆕 New Drops</a>
        <a href="<?php echo esc_url(home_url('/collections/')); ?>"<?php echo strpos($current_path, '/collections') !== false ? ' class="active"' : ''; ?>>📁 Collections</a>
        <a href="<?php echo esc_url(home_url('/artists/')); ?>"<?php echo strpos($current_path, '/artists') !== false ? ' class="active"' : ''; ?>>🎨 Creators</a>
        <a href="<?php echo esc_url(home_url('/stats/')); ?>"<?php echo strpos($current_path, '/stats') !== false ? ' class="active"' : ''; ?>>🏆 Stats</a>
        
        <div class="divider"></div>
        
        <!-- IMU Ecosystem -->
        <div class="section-label">IMU Ecosystem</div>
        <a href="<?php echo esc_url(home_url('/frequency-fountain/')); ?>"<?php echo strpos($current_path, '/frequency-fountain') !== false ? ' class="active"' : ''; ?>>⛲ Frequency Fountain</a>
        <a href="https://mint.imcollectibles.io/collection">🛡️ View Collections</a>
        <a href="<?php echo esc_url(home_url('/burn-to-earn/')); ?>"<?php echo strpos($current_path, '/burn-to-earn') !== false ? ' class="active"' : ''; ?>>🔥 Burn 2 Earn</a>
        <a href="<?php echo esc_url(home_url('/champion-of-frequencies/')); ?>"<?php echo strpos($current_path, '/champion') !== false ? ' class="active"' : ''; ?>>🎮 IMU Gaming</a>
        <a href="https://imutv.tv/ecosystem" target="_blank">📺 IMUTV</a>
        <a href="https://imutv.tv/ecosystem" target="_blank">🎵 IMUP3</a>
        <?php /* DISABLED: IMC Page Cleanup (2026-05) <a href="https://airdrop.imcollectibles.io/claim/">🎁 IMU Airdrop</a> */ ?>
        <?php /* DISABLED: IMC Page Cleanup (2026-05) <a href="...imutv-rewards-visualizer/">📈 IMU Rewards Visualizer</a> */ ?>
        
        <!-- Wallet Section in Dropdown -->
        <div class="imp-dropdown-wallet">
            <?php if ($xrpl_cookie): ?>
                <div class="imp-dropdown-wallet-status connected">
                    <span class="dot"></span>
                    <span class="label"><?php echo esc_html($wallet_short); ?></span>
                </div>
                <a href="<?php echo esc_url(home_url('/?logout=true')); ?>" class="imp-disconnect-btn">Disconnect Wallet</a>
            <?php else: ?>
                <div class="imp-dropdown-wallet-status">
                    <span class="dot"></span>
                    <span class="label">Not Connected</span>
                </div>
                <a href="<?php echo esc_url(home_url('/login/?redirect=' . urlencode($current_url))); ?>" class="imp-connect-btn">Connect Wallet</a>
            <?php endif; ?>
        </div>
    </div>

    <script>
    // Toggle menu
    function impToggleMenu() {
        const hamburger = document.getElementById('imp-hamburger');
        const menu = document.getElementById('imp-dropdown-menu');
        const backdrop = document.getElementById('imp-backdrop');
        
        // Close wallet popup when opening menu
        impCloseWalletPopup();
        
        if (hamburger && menu && backdrop) {
            const isActive = menu.classList.contains('active');
            
            if (isActive) {
                impCloseMenu();
            } else {
                hamburger.classList.add('active');
                menu.classList.add('active');
                backdrop.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }
    }

    // Close menu
    function impCloseMenu() {
        const hamburger = document.getElementById('imp-hamburger');
        const menu = document.getElementById('imp-dropdown-menu');
        const backdrop = document.getElementById('imp-backdrop');
        
        if (hamburger) hamburger.classList.remove('active');
        if (menu) menu.classList.remove('active');
        if (backdrop) backdrop.classList.remove('active');
        document.body.style.overflow = '';
    }

    // Close on link click
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.imp-dropdown-menu a').forEach(function(link) {
            link.addEventListener('click', function() {
                if (!this.hasAttribute('target')) {
                    impCloseMenu();
                }
            });
        });
    });

    // Close on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            impCloseMenu();
            impCloseWalletPopup();
            // Also close search
            const searchWrapper = document.getElementById('imp-search-wrapper');
            const searchToggle = document.getElementById('imp-search-toggle');
            if (searchWrapper) searchWrapper.classList.remove('mobile-active');
            if (searchToggle) searchToggle.classList.remove('active');
        }
    });

    // ============================================
    // WALLET POPUP (Phase 1)
    // ============================================
    function impToggleWalletPopup(e) {
        if (e) e.stopPropagation();
        const popup = document.getElementById('imp-wallet-popup');
        if (!popup) return;
        
        const isActive = popup.classList.contains('active');
        
        // Close hamburger menu if open
        impCloseMenu();
        
        if (isActive) {
            impCloseWalletPopup();
        } else {
            popup.classList.add('active');
            if (typeof impWbLoad === 'function') impWbLoad(); /* P2I: lazy balance fetch, once */
        }
    }
    
    function impCloseWalletPopup() {
        const popup = document.getElementById('imp-wallet-popup');
        if (popup) popup.classList.remove('active');
    }

    /* P2I (Aug 2026): session-scoped wallet balances in the dropdown.
       Fetch-once per page view; the handler resolves the account from the
       session token server-side and caches for 60s. Row hides on failure. */
    let impWbLoaded = false;
    function impWbLoad() {
        if (impWbLoaded) return;
        const row = document.getElementById('impWbRow');
        if (!row) return; /* logged-out popup has no row */
        impWbLoaded = true;
        const wbEp = '<?php echo esc_js(get_stylesheet_directory_uri()); ?>/xrpl-nft-marketplace/backend/offer-handler.php';
        const wbNonce = '<?php echo esc_js(wp_create_nonce('xrpl_marketplace_nonce')); ?>';
        fetch(wbEp + '?action=wallet_balances&nonce=' + encodeURIComponent(wbNonce))
            .then(r => r.json())
            .then(d => {
                if (!d || !d.success || !d.xrp) { row.style.display = 'none'; return; }
                const fmt = v => Number(v).toLocaleString(undefined, {maximumFractionDigits: 2});
                const el = document.getElementById('impWbXrp');
                if (el) el.textContent = fmt(d.xrp.available) + ' XRP';
                const panel = document.getElementById('impWbPanel');
                if (!panel) return;
                let h = '';
                h += '<div class="wb-line"><span class="wb-muted">Balance</span><span>' + fmt(d.xrp.balance) + ' XRP</span></div>';
                h += '<div class="wb-line"><span class="wb-muted">Reserve</span><span>' + fmt(d.xrp.reserve) + ' XRP</span></div>';
                (d.tokens || []).forEach(t => {
                    h += '<div class="wb-line"><span>' + impWbEsc(t.ticker || t.currency) + '</span><span>' + fmt(t.balance) + '</span></div>';
                });
                if (d.scam_lines > 0) {
                    h += '<div class="wb-line"><span class="wb-muted">&#9888; ' + d.scam_lines + ' flagged line' + (d.scam_lines === 1 ? '' : 's') + ' hidden</span><span></span></div>';
                }
                panel.innerHTML = h;
            })
            .catch(() => { row.style.display = 'none'; });
    }
    function impWbEsc(s) { return String(s || '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
    function impToggleHoldings(e) {
        if (e) e.stopPropagation();
        const p = document.getElementById('impWbPanel');
        const c = document.getElementById('impWbChev');
        if (!p) return;
        const open = p.style.display !== 'none';
        p.style.display = open ? 'none' : '';
        if (c) c.innerHTML = open ? '&#9662;' : '&#9652;';
    }
    
    function impCopyWallet(e) {
        if (e) e.stopPropagation();
        const fullAddress = '<?php echo esc_js($xrpl_cookie); ?>';
        if (!fullAddress) return;
        
        navigator.clipboard.writeText(fullAddress).then(function() {
            const toast = document.getElementById('imp-copy-toast');
            if (toast) {
                toast.classList.add('show');
                setTimeout(function() { toast.classList.remove('show'); }, 1500);
            }
        }).catch(function() {
            // Fallback for older browsers
            const ta = document.createElement('textarea');
            ta.value = fullAddress;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            const toast = document.getElementById('imp-copy-toast');
            if (toast) {
                toast.classList.add('show');
                setTimeout(function() { toast.classList.remove('show'); }, 1500);
            }
        });
    }
    
    // Close wallet popup on click outside
    document.addEventListener('click', function(e) {
        const wrapper = document.getElementById('imp-wallet-wrapper');
        if (wrapper && !wrapper.contains(e.target)) {
            impCloseWalletPopup();
        }
    });

    // ============================================
    // GLOBAL SEARCH FUNCTIONALITY
    // ============================================
    (function() {
        const searchWrapper = document.getElementById('imp-search-wrapper');
        const searchInput = document.getElementById('imp-search-input');
        const searchBtn = document.getElementById('imp-search-btn');
        const searchResults = document.getElementById('imp-search-results');
        const searchToggle = document.getElementById('imp-search-toggle');
        
        if (!searchInput || !searchResults) return;
        
        let searchTimeout = null;
        let lastQuery = '';
        
        const searchEndpoint = '<?php echo esc_js(get_stylesheet_directory_uri()); ?>/xrpl-nft-marketplace/backend/bithomp-handler.php';
        const nonce = '<?php echo esc_js(wp_create_nonce('xrpl_marketplace_nonce')); ?>';
        
        // Mobile toggle
        if (searchToggle) {
            searchToggle.addEventListener('click', function() {
                const isActive = searchWrapper.classList.contains('mobile-active');
                if (isActive) {
                    searchWrapper.classList.remove('mobile-active');
                    searchToggle.classList.remove('active');
                } else {
                    searchWrapper.classList.add('mobile-active');
                    searchToggle.classList.add('active');
                    setTimeout(() => searchInput.focus(), 100);
                }
            });
        }
        
        // Debounced search
        searchInput.addEventListener('input', function() {
            const query = this.value.trim();
            
            if (searchTimeout) clearTimeout(searchTimeout);
            
            if (query.length < 2) {
                hideResults();
                return;
            }
            
            searchTimeout = setTimeout(() => performSearch(query), 300);
        });
        
        // Enter key
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                const query = this.value.trim();
                if (query.length >= 2) performSearch(query);
            } else if (e.key === 'Escape') {
                hideResults();
                this.blur();
                searchWrapper.classList.remove('mobile-active');
                if (searchToggle) searchToggle.classList.remove('active');
            }
        });
        
        // Search button click
        if (searchBtn) {
            searchBtn.addEventListener('click', function() {
                const query = searchInput.value.trim();
                if (query.length >= 2) performSearch(query);
            });
        }
        
        // Click outside to close
        document.addEventListener('click', function(e) {
            if (!searchWrapper.contains(e.target) && !searchToggle?.contains(e.target)) {
                hideResults();
                searchWrapper.classList.remove('mobile-active');
                if (searchToggle) searchToggle.classList.remove('active');
            }
        });
        
        function showLoading() {
            searchResults.innerHTML = '<div class="imp-search-loading">Searching...</div>';
            searchResults.classList.add('active');
        }
        
        function hideResults() {
            searchResults.classList.remove('active');
            lastQuery = '';
        }
        
        function showEmpty(query) {
            searchResults.innerHTML = `<div class="imp-search-empty">No results found for "${escapeHtml(query)}"</div>`;
            searchResults.classList.add('active');
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // S1: escapeHtml() round-trips through textContent -> innerHTML, which encodes
        // & < > but leaves " and ' INTACT. That is correct for a TEXT NODE and unsafe
        // for an ATTRIBUTE VALUE -- a name or URL containing a double quote would break
        // out of src="..." / href="...". Every attribute interpolation in this dropdown
        // uses escapeAttr; text nodes keep escapeHtml.
        function escapeAttr(text) {
            return String(text === null || text === undefined ? '' : text)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        // L3-3d (Aug 2026): search results arrive with RAW gateway URLs. ipfs.io sends
        // Cross-Origin-Resource-Policy: same-origin, the browser refuses the image, and
        // the onerror was silently swapping in the fallback svg — search thumbnails were
        // quietly broken for every XRPL-wide result. SELF-CONTAINED ON PURPOSE:
        // trading.js (imcProxyImage) only loads on hub/shortcode pages, and this header
        // renders on EVERY page — referencing it here would ReferenceError site-wide.
        // MATCH THE PATH (/ipfs/), NOT A HOST LIST. Idempotent on img.php URLs.
        const impSearchImg = (u) => {
            if (!u || typeof u !== 'string') return u;
            if (u.includes('img.php')) return u;
            if (u.startsWith('ipfs://')) return 'https://metadata.imcollectibles.io/img.php?url=' + encodeURIComponent(u) + '&thumb=1';
            if (u.includes('/ipfs/')) return 'https://metadata.imcollectibles.io/img.php?url=' + encodeURIComponent('ipfs://' + u.replace(/^.*\/ipfs\//, '')) + '&thumb=1';
            return u;
        };
        
        async function performSearch(query) {
            if (query === lastQuery) return;
            lastQuery = query;
            
            showLoading();
            
            try {
                const url = `${searchEndpoint}?action=search&q=${encodeURIComponent(query)}&limit=8&nonce=${encodeURIComponent(nonce)}`;
                const response = await fetch(url);
                const data = await response.json();
                
                if (!data.success) {
                    showEmpty(query);
                    return;
                }
                
                renderResults(data);
                
            } catch (error) {
                console.error('Search error:', error);
                searchResults.innerHTML = '<div class="imp-search-empty">Search failed. Please try again.</div>';
                searchResults.classList.add('active');
            }
        }
        
        function renderResults(data) {
            let html = '';
            
            if (data.nft) {
                html += '<div class="imp-search-section-label">NFT Found</div>';
                html += renderNftItem(data.nft);
            }
            
            if (data.listings && data.listings.length > 0) {
                html += '<div class="imp-search-section-label">Drops</div>';
                data.listings.forEach(d => { html += renderListingItem(d); });
            }
            
            if (data.collections && data.collections.length > 0) {
                html += '<div class="imp-search-section-label">Collections</div>';
                data.collections.forEach(col => {
                    html += renderCollectionItem(col);
                });
            }
            
            if (data.users && data.users.length > 0) {
                const creators = data.users.filter(u => u.tag === 'Creator');
                const collectors = data.users.filter(u => u.tag !== 'Creator');
                if (creators.length > 0) {
                    html += '<div class="imp-search-section-label">Creators</div>';
                    creators.forEach(u => { html += renderUserItem(u); });
                }
                if (collectors.length > 0) {
                    html += '<div class="imp-search-section-label">Users</div>';
                    collectors.forEach(u => { html += renderUserItem(u); });
                }
            }
            
            if (!html) {
                showEmpty(data.query);
                return;
            }
            
            searchResults.innerHTML = html;
            searchResults.classList.add('active');
        }
        
        function renderNftItem(nft) {
            const url = `<?php echo esc_url(home_url('/nft/')); ?>${encodeURIComponent(nft.nftokenID)}/`;
            return `
                <a href="${url}" class="imp-search-result-item">
                    <img src="${escapeAttr(impSearchImg(nft.image))}" alt="" class="imp-search-result-img" 
                         onerror="this.src='/wp-content/uploads/fallback-nft.svg'">
                    <div class="imp-search-result-info">
                        <div class="imp-search-result-name">${escapeHtml(nft.name)}</div>
                        <div class="imp-search-result-meta">
                            ${nft.collection ? `<span>📁 ${escapeHtml(nft.collection)}</span>` : ''}
                            <span>🔗 ${nft.nftokenID.slice(0, 8)}...${nft.nftokenID.slice(-6)}</span>
                        </div>
                    </div>
                </a>
            `;
        }
        
        function renderUserItem(u) {
            const url = `<?php echo esc_url(home_url('/user/')); ?>${encodeURIComponent(u.slug || u.account)}/`;
            const tagColor = u.tag === 'Creator' ? '#d6ba66' : '#8a8aa0';
            const pfp = u.pfp ? escapeAttr(u.pfp) : '/wp-content/uploads/fallback-nft.svg';
            return `
                <a href="${url}" class="imp-search-result-item">
                    <img src="${pfp}" alt="" class="imp-search-result-img" style="border-radius:50%"
                         onerror="this.src='/wp-content/uploads/fallback-nft.svg'">
                    <div class="imp-search-result-info">
                        <div class="imp-search-result-name">${escapeHtml(u.name)}</div>
                        <div class="imp-search-result-meta">
                            <span style="color:${tagColor}">${u.tag === 'Creator' ? '🎨' : '👤'} ${escapeHtml(u.tag)}</span>
                        </div>
                    </div>
                </a>
            `;
        }
        
        // S1: a platform drop from wp_imc_listings. `url` is built server-side and
        // already carries the #drop-{id} fragment, so it is attribute-escaped, not
        // URL-encoded again. Meta is capped at TWO spans on purpose:
        // .imp-search-result-meta is flex with no wrap, so a third span would overflow
        // the 280px dropdown instead of wrapping.
        function renderListingItem(d) {
            const sold  = (d.status === 'sold_out');
            const price = d.dynamic ? 'Dynamic' : (d.price_xrp > 0 ? (d.price_xrp + ' XRP') : 'Free');
            const right = sold ? '\u26d4 Sold Out' : (d.is_oe ? ('\u267e\ufe0f ' + price) : ('\ud83d\udcb0 ' + price));
            const rightColor = sold ? '#8a8aa0' : '#4ade80';
            return `
                <a href="${escapeAttr(d.url)}" class="imp-search-result-item">
                    <img src="${escapeAttr(impSearchImg(d.image))}" alt="" class="imp-search-result-img"
                         onerror="this.src='/wp-content/uploads/fallback-nft.svg'">
                    <div class="imp-search-result-info">
                        <div class="imp-search-result-name">${escapeHtml(d.name)}</div>
                        <div class="imp-search-result-meta">
                            ${d.artist ? `<span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">\ud83c\udfa8 ${escapeHtml(d.artist)}</span>` : ''}
                            <span style="color:${rightColor};flex:none">${escapeHtml(right)}</span>
                        </div>
                    </div>
                </a>
            `;
        }
        
        function renderCollectionItem(col) {
            const url = `<?php echo esc_url(home_url('/collections/')); ?>?issuer=${encodeURIComponent(col.issuer)}&taxon=${col.taxon}`;
            return `
                <a href="${url}" class="imp-search-result-item">
                    <img src="${escapeAttr(impSearchImg(col.image))}" alt="" class="imp-search-result-img"
                         onerror="this.src='/wp-content/uploads/fallback-nft.svg'">
                    <div class="imp-search-result-info">
                        <div class="imp-search-result-name">${escapeHtml(col.name)}</div>
                        <div class="imp-search-result-meta">
                            <span>📦 ${col.nfts || '?'} NFTs</span>
                            ${col.floor_xrp > 0 ? `<span>💰 ${col.floor_xrp} XRP</span>` : ''}
                        </div>
                    </div>
                </a>
            `;
        }
    })();
    </script>

    <?php astra_header_after(); ?>

    <?php // ── v195: T&C Acceptance Modal (only for signed-in wallets that haven't accepted, not on legal pages) ── ?>
    <?php if (!empty($xrpl_cookie) && !$imc_tc_accepted && !$imc_is_legal_page): ?>
    <style>
    .imc-tc-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.85);
        backdrop-filter: blur(6px);
        -webkit-backdrop-filter: blur(6px);
        z-index: 999999;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }
    .imc-tc-modal {
        background: linear-gradient(145deg, #12121a, #1a1a2e);
        border: 1px solid rgba(var(--imu-gold-rgb), 0.3);
        border-radius: 16px;
        max-width: 480px;
        width: 100%;
        padding: 2.5rem 2rem 2rem;
        text-align: center;
        box-shadow: 0 20px 60px rgba(0,0,0,0.5);
        animation: imcTcSlideIn 0.3s ease-out;
    }
    @keyframes imcTcSlideIn {
        from { opacity: 0; transform: translateY(20px) scale(0.97); }
        to   { opacity: 1; transform: translateY(0) scale(1); }
    }
    .imc-tc-modal .tc-logo {
        width: 60px; height: 60px;
        margin: 0 auto 1rem;
        border-radius: 50%;
    }
    .imc-tc-modal h2 {
        color: var(--imu-gold, #d6ba66); font-size: 1.35rem;
        margin: 0 0 0.5rem; font-weight: 700;
    }
    .imc-tc-modal .tc-subtitle {
        color: #999; font-size: 0.9rem;
        margin: 0 0 1.5rem; line-height: 1.5;
    }
    .imc-tc-modal .tc-wallet {
        background: rgba(var(--imu-gold-rgb), 0.08);
        border: 1px solid rgba(var(--imu-gold-rgb), 0.15);
        border-radius: 8px; padding: 0.5rem 1rem;
        color: var(--imu-gold, #d6ba66); font-family: monospace;
        font-size: 0.85rem; margin-bottom: 1.5rem;
        display: inline-block;
    }
    .imc-tc-checkbox {
        display: flex; align-items: flex-start;
        gap: 0.75rem; text-align: left;
        margin-bottom: 1.5rem; padding: 0 0.5rem;
    }
    .imc-tc-checkbox input[type="checkbox"] {
        width: 20px; height: 20px; min-width: 20px;
        margin-top: 2px; accent-color: var(--imu-gold, #d6ba66); cursor: pointer;
    }
    .imc-tc-checkbox label {
        color: #e8e6e3; font-size: 0.9rem;
        line-height: 1.5; cursor: pointer;
    }
    .imc-tc-checkbox label a {
        color: var(--imu-gold, #d6ba66); text-decoration: underline;
        text-underline-offset: 2px;
    }
    .imc-tc-checkbox label a:hover { color: #e8d38a; }
    .imc-tc-accept-btn {
        width: 100%; padding: 0.85rem 1.5rem;
        background: linear-gradient(135deg, var(--imu-gold, #d6ba66), #b89d4a);
        color: #0a0a0f; border: none; border-radius: 10px;
        font-size: 1rem; font-weight: 700;
        cursor: not-allowed; opacity: 0.4;
        transition: all 0.2s ease;
    }
    .imc-tc-accept-btn.enabled {
        cursor: pointer; opacity: 1;
    }
    .imc-tc-accept-btn.enabled:hover {
        background: linear-gradient(135deg, #e8d38a, var(--imu-gold, #d6ba66));
        transform: translateY(-1px);
        box-shadow: 0 4px 15px rgba(var(--imu-gold-rgb), 0.3);
    }
    .imc-tc-accept-btn:disabled {
        cursor: not-allowed; opacity: 0.4;
    }
    .imc-tc-error {
        color: #ef4444; font-size: 0.85rem;
        margin-top: 0.75rem; display: none;
    }
    </style>

    <div class="imc-tc-overlay" id="imc-tc-overlay">
        <div class="imc-tc-modal">
            <img src="<?php echo esc_url(get_stylesheet_directory_uri()); ?>/images/protectors-logo.png"
                 alt="IMCollectibles" class="tc-logo"
                 onerror="this.style.display='none'">
            <h2>Terms &amp; Conditions</h2>
            <p class="tc-subtitle">
                Before using the IMCollectibles marketplace, please review and accept our Terms &amp; Conditions.
            </p>
            <div class="tc-wallet"><?php echo esc_html($wallet_short); ?></div>

            <div class="imc-tc-checkbox">
                <input type="checkbox" id="imc-tc-check">
                <label for="imc-tc-check">
                    I have read and agree to the
                    <a href="<?php echo esc_url(home_url('/terms/')); ?>" target="_blank" rel="noopener">Terms &amp; Conditions</a>
                    and
                    <a href="<?php echo esc_url(home_url('/privacy/')); ?>" target="_blank" rel="noopener">Privacy Policy</a>
                </label>
            </div>

            <button type="button" class="imc-tc-accept-btn" id="imc-tc-accept-btn" disabled>
                Continue to Marketplace
            </button>
            <div class="imc-tc-error" id="imc-tc-error"></div>
        </div>
    </div>

    <script>
    (function() {
        const cb  = document.getElementById('imc-tc-check');
        const btn = document.getElementById('imc-tc-accept-btn');
        const ov  = document.getElementById('imc-tc-overlay');
        const err = document.getElementById('imc-tc-error');
        const wallet   = '<?php echo esc_js($xrpl_cookie); ?>';
        let   tcNonce  = '<?php echo esc_js(wp_create_nonce("xrpl_marketplace_nonce")); ?>';
        const endpoint = '<?php echo esc_js(get_stylesheet_directory_uri()); ?>/xrpl-nft-marketplace/backend/imc-tc-handler.php';
        const ajaxUrl  = '<?php echo esc_js(admin_url("admin-ajax.php")); ?>';

        // Toggle button
        cb.addEventListener('change', function() {
            btn.disabled = !this.checked;
            btn.classList.toggle('enabled', this.checked);
            err.style.display = 'none';
        });

        // Fetch a fresh nonce from the server
        async function refreshTcNonce() {
            try {
                const r = await fetch(ajaxUrl + '?action=get_nonce');
                const d = await r.json();
                if (d.nonce) { tcNonce = d.nonce; return true; }
            } catch (e) {}
            return false;
        }

        // Send accept_tc with the current nonce
        async function sendAccept() {
            const fd = new FormData();
            fd.append('action', 'accept_tc');
            fd.append('wallet', wallet);
            fd.append('nonce', tcNonce);
            const resp = await fetch(endpoint, { method: 'POST', body: fd });
            return resp.json();
        }

        // Accept
        btn.addEventListener('click', async function() {
            if (!cb.checked) return;
            btn.disabled = true;
            btn.textContent = 'Processing...';
            err.style.display = 'none';

            try {
                let data = await sendAccept();

                // Nonce expired — refresh silently and retry once
                if (!data.success && data.error === 'Invalid nonce') {
                    const refreshed = await refreshTcNonce();
                    if (refreshed) {
                        data = await sendAccept();
                    }
                }

                if (data.success) {
                    ov.style.transition = 'opacity 0.3s ease';
                    ov.style.opacity = '0';
                    setTimeout(() => { ov.remove(); document.body.style.overflow = ''; }, 300);
                } else if (data.error === 'Invalid nonce') {
                    // Still failing after refresh — reload the page to get a fresh nonce
                    err.textContent = 'Session expired — reloading...';
                    err.style.display = 'block';
                    setTimeout(() => { window.location.reload(); }, 1500);
                } else {
                    throw new Error(data.error || 'Failed to record acceptance');
                }
            } catch (e) {
                err.textContent = e.message + ' — please try again.';
                err.style.display = 'block';
                btn.disabled = false;
                btn.textContent = 'Continue to Marketplace';
                btn.classList.add('enabled');
            }
        });

        // Lock scroll while modal visible
        document.body.style.overflow = 'hidden';
    })();
    </script>
    <?php endif; ?>

    <?php astra_content_before(); ?>
    <div id="content" class="site-content">
        <div class="ast-container">
        <?php astra_content_top(); ?>