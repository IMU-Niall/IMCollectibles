<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * IMCollectibles Security Headers
 * Added: April 2026
 * Location: functions.php (prepend after opening <?php)
 *
 * Addresses: 11 missing security headers flagged by scanners.
 * Tuned for IMC's dependency chain: OneSignal push, Twitch/YouTube/
 * DaCast embeds, IPFS NFT media, VPS metadata API, Video.js.
 *
 * KEY DIFFERENCE FROM IMUTV: IMC proxies all XRPL/Xaman calls
 * server-side via xumm-proxy.php — connect-src is much tighter.
 * ═══════════════════════════════════════════════════════════════
 */
/**
 * v665: Parse a listing's accepted_currencies into an array (handles JSON string or array).
 */
function imc_listing_currencies($listing) {
    $raw = $listing['accepted_currencies'] ?? null;
    if (empty($raw)) return [];
    $arr = is_string($raw) ? json_decode($raw, true) : $raw;
    return is_array($arr) ? $arr : [];
}

/**
 * v665: Format a token amount for display -- thousands separated, trailing zeros trimmed.
 * 2000000 -> "2,000,000" | 18182 -> "18,182" | 0.5 -> "0.5"
 */
function imc_format_token_amount($v) {
    $v = (float)$v;
    if ($v == floor($v) && abs($v) < 1e15) return number_format($v, 0);
    return rtrim(rtrim(number_format($v, 6, '.', ','), '0'), '.');
}

/**
 * v665: The first enabled, priced non-XRP token on a listing (JSON order = creator's order).
 * Returns ['currency' => 'XMEME', 'price' => 2000000.0] or null.
 */
function imc_first_priced_token($listing) {
    foreach (imc_listing_currencies($listing) as $c) {
        if (isset($c['enabled']) && !$c['enabled']) continue;
        $code = strtoupper($c['currency'] ?? '');
        if ($code === '' || $code === 'XRP') continue;
        if (floatval($c['price'] ?? 0) > 0) {
            return ['currency' => $c['currency'], 'price' => floatval($c['price'])];
        }
    }
    return null;
}

/**
 * v665: Count of enabled, priced non-XRP tokens on a listing.
 */
function imc_count_priced_tokens($listing) {
    $n = 0;
    foreach (imc_listing_currencies($listing) as $c) {
        if (isset($c['enabled']) && !$c['enabled']) continue;
        $code = strtoupper($c['currency'] ?? '');
        if ($code === '' || $code === 'XRP') continue;
        if (floatval($c['price'] ?? 0) > 0) $n++;
    }
    return $n;
}

/**
 * v665: The effective XRP price -- price_xrp column first, then a priced XRP entry in
 * accepted_currencies (legacy multi-currency rows store it there). 0 when XRP isn't priced.
 */
function imc_effective_xrp_price($listing) {
    $xrp = floatval($listing['price_xrp'] ?? 0);
    if ($xrp > 0) return $xrp;
    foreach (imc_listing_currencies($listing) as $c) {
        if (isset($c['enabled']) && !$c['enabled']) continue;
        if (strtoupper($c['currency'] ?? '') === 'XRP' && floatval($c['price'] ?? 0) > 0) {
            return floatval($c['price']);
        }
    }
    return 0.0;
}

/**
 * v665: TOKEN-AGNOSTIC primary display price for a listing.
 * Resolution order: free -> dynamic USD -> pwyw floor -> XRP -> first priced XRPL token -> Free.
 * A token-only listing shows its real token price instead of a misleading "0.00 XRP"/"Free".
 */
function imc_primary_display_price($listing) {
    $mode = $listing['pricing_mode'] ?? 'static';
    if ($mode === 'free') return 'Free';

    $usd = isset($listing['price_usd']) ? floatval($listing['price_usd']) : 0;
    if ($mode === 'dynamic' && $usd > 0) return '$' . number_format($usd, 2) . ' USD';

    $xrp = imc_effective_xrp_price($listing);
    $tok = imc_first_priced_token($listing);

    if ($mode === 'pwyw') {
        if ($xrp > 0) return imc_pwyw_price_label($xrp);
        if ($tok) return 'Pay What You Want (min ' . imc_format_token_amount($tok['price']) . ' ' . $tok['currency'] . ')';
        return imc_pwyw_price_label(0);
    }

    if ($xrp > 0) return number_format($xrp, 2) . ' XRP';
    if ($tok)     return imc_format_token_amount($tok['price']) . ' ' . $tok['currency'];
    return 'Free';
}

/**
 * v665: Should the "+ XRPL Token Prices" secondary line show? True when priced tokens exist
 * beyond whatever is already displayed as the primary price.
 */
function imc_has_extra_token_prices($listing) {
    $count = imc_count_priced_tokens($listing);
    if ($count === 0) return false;
    // If XRP (or a USD/dynamic price) is the primary, every token is "extra".
    $mode = $listing['pricing_mode'] ?? 'static';
    if ($mode === 'dynamic') return false; // dynamic is XRP-only by design
    if (imc_effective_xrp_price($listing) > 0) return true;
    // Otherwise the first token IS the primary -- extras are the remainder.
    return $count > 1;
}

/**
 * v699 (Phase 5): the token behind a listing's PRIMARY price, but ONLY when that price is a
 * single non-XRP token shown on its own — no XRP, not dynamic-USD, not free, and no
 * "+ XRPL Token Prices" note (the one case Phase 4's note doesn't already cover). Returns
 * ['currency' => 'HORDE', 'issuer' => 'r...'] or null. Multi-token / XRP-priced listings
 * return null because the note already carries their "?" badges.
 */
function imc_primary_price_token($listing) {
    $mode = $listing['pricing_mode'] ?? 'static';
    if ($mode === 'free') return null;
    if ($mode === 'dynamic' && floatval($listing['price_usd'] ?? 0) > 0) return null;
    if (imc_effective_xrp_price($listing) > 0) return null;
    if (imc_has_extra_token_prices($listing)) return null; // the note already shows every "?"
    foreach (imc_listing_currencies($listing) as $c) {
        if (isset($c['enabled']) && !$c['enabled']) continue;
        $code = strtoupper($c['currency'] ?? '');
        if ($code === '' || $code === 'XRP') continue;
        if (floatval($c['price'] ?? 0) > 0) {
            return ['currency' => $c['currency'], 'issuer' => $c['issuer'] ?? ''];
        }
    }
    return null;
}

/**
 * v699 (Phase 5): the "?" trustline/buy badge for a single-token primary price, or '' when the
 * primary isn't a bare token. Reuses the same .imc-token-info styling and .imc-price-info click
 * hook as the card note, and sets the footer-script flag so the delegated handler is emitted.
 */
function imc_primary_price_badge($listing) {
    $t = imc_primary_price_token($listing);
    if (!$t) return '';
    $GLOBALS['imc_token_prices_used'] = true;
    return '<span class="imc-token-info imc-price-info" data-ticker="' . esc_attr($t['currency'])
         . '" data-issuer="' . esc_attr($t['issuer']) . '" title="' . esc_attr('How to pay with ' . $t['currency'])
         . '" style="margin-left:6px;cursor:pointer;vertical-align:middle;">?</span>';
}

/**
 * v666: Every enabled, priced currency on a listing, formatted for display.
 * XRP first (when priced), then the creator's tokens in their saved order.
 * Returns ['10 XRP', '2,000,000 XMEME', '10 RLUSD'].
 */
function imc_all_price_rows($listing) {
    $rows = [];
    $xrp = imc_effective_xrp_price($listing);
    if ($xrp > 0) $rows[] = imc_format_token_amount($xrp) . ' XRP';
    foreach (imc_listing_currencies($listing) as $c) {
        if (isset($c['enabled']) && !$c['enabled']) continue;
        $code = strtoupper($c['currency'] ?? '');
        if ($code === '' || $code === 'XRP') continue;
        if (floatval($c['price'] ?? 0) > 0) {
            $rows[] = imc_format_token_amount($c['price']) . ' ' . $c['currency'];
        }
    }
    return $rows;
}

/**
 * v666: The secondary "+ XRPL Token Prices" line, now a toggle that reveals every accepted
 * price for the listing. Empty string when there is nothing extra to reveal.
 * Self-contained: markup only -- the one delegated click handler is emitted once in the
 * footer by imc_token_prices_toggle_script().
 */
function imc_token_prices_note($listing) {
    if (!imc_has_extra_token_prices($listing)) return '';
    $rows = imc_all_price_rows($listing);
    if (count($rows) < 2) return '';

    $GLOBALS['imc_token_prices_used'] = true;
    static $seq = 0; $seq++;
    $id = 'imc-prices-' . intval($listing['id'] ?? 0) . '-' . $seq;

    // v698 (Phase 4): rebuild the rows here (rather than from the string-only imc_all_price_rows)
    // so each non-XRP token row carries its issuer and a "?" badge that opens the trustline+buy
    // popover. XRP row (if priced) first, then the creator's tokens in saved order — same order
    // as imc_all_price_rows, which is still used above for the count guard.
    $items = '';
    $xrp = imc_effective_xrp_price($listing);
    if ($xrp > 0) {
        $items .= '<span style="display:block;padding:1px 0;">' . esc_html(imc_format_token_amount($xrp) . ' XRP') . '</span>';
    }
    foreach (imc_listing_currencies($listing) as $c) {
        if (isset($c['enabled']) && !$c['enabled']) continue;
        $code = strtoupper($c['currency'] ?? '');
        if ($code === '' || $code === 'XRP') continue;
        if (floatval($c['price'] ?? 0) <= 0) continue;
        $label = imc_format_token_amount($c['price']) . ' ' . $c['currency'];
        $badge = '<span class="imc-token-info imc-price-info" data-ticker="' . esc_attr($c['currency'])
               . '" data-issuer="' . esc_attr($c['issuer'] ?? '') . '" title="' . esc_attr('How to pay with ' . $c['currency'])
               . '" style="margin-left:6px;cursor:pointer;">?</span>';
        $items .= '<span style="display:block;padding:1px 0;">' . esc_html($label) . $badge . '</span>';
    }

    return '<button type="button" class="imc-token-prices-toggle" data-imc-prices-target="' . esc_attr($id) . '"'
         . ' style="display:block;margin:2px 0 0;padding:0;background:none;border:0;font:inherit;font-size:0.78em;opacity:0.75;color:inherit;cursor:pointer;text-align:inherit;">'
         . '+ XRPL Token Prices</button>'
         . '<span class="imc-token-prices-list" id="' . esc_attr($id) . '" style="display:none;font-size:0.78em;opacity:0.85;margin-top:3px;">'
         . $items . '</span>';
}

/**
 * v666: One delegated handler for every "+ XRPL Token Prices" toggle on the page.
 * Emitted once, and only when at least one toggle was actually rendered. Uses capture +
 * preventDefault so a toggle inside a linked card never navigates.
 */
function imc_token_prices_toggle_script() {
    if (empty($GLOBALS['imc_token_prices_used'])) return;
    ?>
<script>
(function(){
    if (window.__imcTokenPricesBound) return;
    window.__imcTokenPricesBound = true;
    document.addEventListener('click', function(e){
        var btn = e.target.closest && e.target.closest('.imc-token-prices-toggle');
        if (!btn) return;
        e.preventDefault(); e.stopPropagation();
        var el = document.getElementById(btn.getAttribute('data-imc-prices-target'));
        if (!el) return;
        var open = el.style.display !== 'none';
        el.style.display = open ? 'none' : 'block';
        btn.textContent = open ? '+ XRPL Token Prices' : '\u2212 Hide Prices';
    }, true);

    // v698 (Phase 4): the "?" badge on each non-XRP token price row opens the trustline+buy
    // popover. Capture-phase + stopPropagation so a click inside a linked card never navigates.
    document.addEventListener('click', function(e){
        var info = e.target.closest && e.target.closest('.imc-price-info');
        if (!info) return;
        e.preventDefault(); e.stopPropagation();
        if (window.imcTrustline && typeof window.imcTrustline.openTokenActions === 'function') {
            window.imcTrustline.openTokenActions(info.getAttribute('data-ticker'), info.getAttribute('data-issuer'), {});
        }
    }, true);
})();
</script>
    <?php
}
add_action('wp_footer', 'imc_token_prices_toggle_script', 99);

function imc_pwyw_price_label($price_xrp) {
    // v660b: "Pay What You Want" price stamp for PWYW listing boxes. Returns PLAIN TEXT (no
    // HTML) so it renders identically in every context it's used -- esc_html'd spans, raw
    // echoes, and JS textContent alike. Each render spot applies its own price styling.
    $floor = floatval($price_xrp);
    return $floor > 0
        ? 'Pay What You Want (min ' . rtrim(rtrim(sprintf('%.6f', $floor), '0'), '.') . ' XRP)'
        : 'Pay What You Want (any amount)';
}

add_action('send_headers', function () {

    // Skip WP admin — plugins/themes set their own headers there
    if (is_admin()) return;

    // ── 1. Strict-Transport-Security (HSTS) ──────────────────
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

    // ── 2. X-Content-Type-Options ────────────────────────────
    header('X-Content-Type-Options: nosniff');

    // ── 3. X-Frame-Options ───────────────────────────────────
    header('X-Frame-Options: SAMEORIGIN');

    // ── 4. Referrer-Policy ───────────────────────────────────
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // ── 5. Permissions-Policy ────────────────────────────────
    header('Permissions-Policy: geolocation=(), payment=(), usb=(), magnetometer=(), gyroscope=(), accelerometer=()');

    // ── 6. X-Permitted-Cross-Domain-Policies ─────────────────
    header('X-Permitted-Cross-Domain-Policies: none');

    // ── 7. Cross-Origin-Opener-Policy ────────────────────────
    // same-origin-allow-popups: allows Xaman deep-link popups
    header('Cross-Origin-Opener-Policy: same-origin-allow-popups');

    // ── 8. Cross-Origin-Resource-Policy ──────────────────────
    header('Cross-Origin-Resource-Policy: cross-origin');

    // ── 9. X-XSS-Protection ─────────────────────────────────
    header('X-XSS-Protection: 1; mode=block');

    // ── 10. Content-Security-Policy ──────────────────────────
    $csp = implode('; ', [
        "default-src 'self'",

        // Scripts: 6 CDN domains + OneSignal
        // No Google Sign-In, no Stripe, no DaCast scripts (iframe-only)
        "script-src 'self' 'unsafe-inline' 'unsafe-eval'"
            . " https://cdnjs.cloudflare.com"
            . " https://cdn.jsdelivr.net"
            . " https://cdn.onesignal.com"
            . " https://code.jquery.com"
            . " https://unpkg.com"
            . " https://vjs.zencdn.net",

        // Styles: Google Fonts + CDN stylesheets (Slick, Dropzone, Video.js)
        "style-src 'self' 'unsafe-inline'"
            . " https://fonts.googleapis.com"
            . " https://cdn.jsdelivr.net"
            . " https://cdnjs.cloudflare.com"
            . " https://vjs.zencdn.net",

        "font-src 'self'"
            . " https://fonts.gstatic.com"
            . " data:",

        // Images: any HTTPS (NFTs from unpredictable IPFS gateways + Pinata + VPS)
        "img-src 'self' https: data: blob:",

        // Frames: DaCast (events only), YouTube, Twitch
        // A5: + blob: -- the U9 vault PDF viewer renders <iframe src="blob:...">;
        // without it the CSP blocks the first vault PDF ever Viewed.
        "frame-src 'self' blob:"
            . " https://iframe.dacast.com"
            . " https://www.youtube.com"
            . " https://player.twitch.tv",

        // Connections: TIGHT — IMC proxies all XRPL/Xaman server-side
        // Only metadata VPS (mint uploads + master stream fetch),
        // WebSocket chat, and OneSignal need direct browser access
        "connect-src 'self'"
            . " https://imcollectibles.io https://*.imcollectibles.io"
            . " wss://chat.imcollectibles.io"
            . " wss://events-chat.imcollectibles.io"
            . " https://*.onesignal.com"
            // Joey / WalletConnect relay (Phase 8.1 — additive; required for any WC wallet, browser->relay)
            . " wss://relay.walletconnect.org https://*.walletconnect.org https://*.walletconnect.com",

        // Media: any HTTPS (NFT audio/video, Pexels hero, VPS master streams)
        "media-src 'self' https: blob:",

        "object-src 'none'",
        "worker-src 'self' blob:",
        "base-uri 'self'",
        "form-action 'self' https://imcollectibles.io",
        "upgrade-insecure-requests",
    ]);
    header("Content-Security-Policy: $csp");

    // ── 11. Cache-Control (authenticated pages) ──────────────
    if (is_user_logged_in()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
    }

}, 1);
require_once get_template_directory() . '/inc/notifications.php';
require_once get_template_directory() . '/inc/onesignal-notifications.php';
// IMU Marketing Admin System (v4.0)
require_once get_stylesheet_directory() . '/inc/marketing-admin.php';

// S1e — IMUP3 Discovery Sections (curation for the IMUP3 Discover home).
// Separate from IMU Marketing > Featured Collections, which is the IMC
// website carousel and is untouched by this.
require_once get_stylesheet_directory() . '/inc/imup3-discovery-admin.php';

// v488: Artist Profiles foundation (display-name resolution helpers + table).
// Inert on load - defines helpers only; nothing in the render/mint/buy path calls them yet.
require_once get_stylesheet_directory() . '/inc/imc-artist-profiles.php';

// User Profiles P0 (Aug 2026): universalises imc_artist_profiles + /user//creators routes.
// DARK/ADDITIVE - schema adds default-null columns; routes inert until page-user.php ships.
require_once get_stylesheet_directory() . '/inc/imc-user-profiles.php';

// Route C / D-53 - the IMUP3 app's own login surface (/app-login), opened in a
// Chrome Custom Tab. Deliberately separate from [joey_login] / [xaman_login],
// which are the WEBSITE's login: two auth surfaces, two files, so neither can
// break the other. Reads the session those flows produce; changes none of them.
// NOT IN THIS REPOSITORY. The IMUP3/XRPLAYR native-app auth surface belongs to
// the mobile application, not the marketplace web app, and is maintained
// separately. The marketplace does not depend on it.
// require_once get_stylesheet_directory() . '/inc/imup3-app-login.php';

// Session-auth 2a (Step 2, Aug 2026): httponly HMAC session tokens (spoof fix groundwork)
$imc_session_auth = get_stylesheet_directory() . '/inc/imc-session-auth.php';
if ( file_exists( $imc_session_auth ) ) {
    require_once $imc_session_auth;
}

// v79: Load IMC Price Oracle for dynamic pricing REST API
$imc_price_oracle = get_stylesheet_directory() . '/xrpl-nft-marketplace/backend/price-oracle.php';
if (file_exists($imc_price_oracle)) {
    require_once $imc_price_oracle;
}

// v86: Load IMC Token Manager (Admin UI for supported tokens)
$imc_token_manager = get_stylesheet_directory() . '/xrpl-nft-marketplace/backend/admin-token-manager.php';
if (file_exists($imc_token_manager)) {
    require_once $imc_token_manager;
}

/**
 * v708 (Step E): DISPLAY-ONLY resolution of a stored offer currency to its ticker.
 *
 * Two upstream forms reach the UI and neither is a ticker:
 *   - Tier B (VPS sell_offers -> SQLite TEXT): the FULL 40-char XRPL wire code.
 *   - Tier A (wp_xumm_offers.currency, VARCHAR(10)): the wire code TRUNCATED to 10
 *     chars, because the XRPL listener writes the raw ledger value into a narrow column.
 *
 * DELIBERATELY NAMED DIFFERENTLY from imc_currency_display() in offer-handler.php.
 * offer-handler.php require_once's wp-load.php, so this file is parsed FIRST; sharing the
 * name would make its own function_exists() guard skip, silently swapping the proven v706
 * endpoint behaviour for this one. Distinct name = that file keeps its own implementation.
 *
 * Truncated codes are only resolved via the Token Manager registry, so a partial like
 * "SCHME" is restored to "SCHMECKLES" ONLY when exactly one registered ticker matches.
 * Anything ambiguous or unrecognised is returned UNCHANGED -- never guessed.
 * Registry is fetched once per request (static) -- nft_loader renders up to 100 cards.
 */
if (!function_exists('imc_offer_currency_display')) {
    function imc_offer_currency_display($code) {
        $code = (string)$code;
        if ($code === '' || strtoupper($code) === 'XRP') {
            return 'XRP';
        }
        // Odd length or non-hex characters => this is already a ticker (SOLO, Xoge, 666, BUT).
        if (strlen($code) % 2 !== 0 || !preg_match('/^[A-Fa-f0-9]+$/', $code)) {
            return $code;
        }
        // Too short to be a wire code. Protects hex-looking tickers (CAFE, BEEF, FADE).
        if (strlen($code) < 8) {
            return $code;
        }
        $trimmed = rtrim($code, '0');
        if ($trimmed === '') {
            return $code;
        }
        if (strlen($trimmed) % 2 !== 0) {
            $trimmed .= '0';
        }
        $ascii = @hex2bin($trimmed);
        if ($ascii === false || $ascii === '' || !preg_match('/^[\x20-\x7E]+$/', $ascii)) {
            return $code; // AMM LP codes, demurrage codes, DEADBEEF-style tickers
        }
        // Full 40-char wire code: the decode is unambiguous (same rule as v706).
        if (strlen($code) === 40) {
            return $ascii;
        }
        // TRUNCATED: only trust the registry, never a bare guess.
        $registry = imc_offer_currency_registry();
        if (in_array($ascii, $registry, true)) {
            return $ascii;
        }
        $hits = array();
        foreach ($registry as $ticker) {
            if (stripos($ticker, $ascii) === 0) {
                $hits[] = $ticker;
            }
        }
        if (count($hits) === 1) {
            return $hits[0]; // "SCHME" -> "SCHMECKLES"
        }
        if (preg_match('/^[A-Za-z0-9$._-]{2,20}$/', $ascii)) {
            return $ascii; // readable partial beats raw hex
        }
        return $code;
    }
}

if (!function_exists('imc_offer_currency_registry')) {
    function imc_offer_currency_registry() {
        static $tickers = null;
        if ($tickers !== null) {
            return $tickers;
        }
        $tickers = array();
        if (function_exists('imc_get_supported_tokens')) {
            foreach (imc_get_supported_tokens('all') as $t) {
                $tk = trim((string)($t['ticker'] ?? ''));
                if ($tk !== '') {
                    $tickers[] = $tk;
                }
            }
        }
        return $tickers;
    }
}

/**
 * Astra functions and definitions
 *
 * @link https://developer.wordpress.org/themes/basics/theme-functions/
 *
 * @package Astra
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * ============================================================================
 * Prevent page caching for wallet-scoped responses.
 * Added: January 22, 2026
 * Issue: Non-logged-in users were seeing logged-in user data due to page caching
 * Root cause: Pages with embedded wallet addresses were being cached publicly
 * ============================================================================
 */
function imu_prevent_page_caching() {
    // Only on frontend, not admin or AJAX
    if (is_admin() || wp_doing_ajax()) {
        return;
    }
    
    // Send HTTP headers to prevent caching (meta tags are NOT enough!)
    if (!headers_sent()) {
        header('Cache-Control: no-cache, no-store, must-revalidate, private, max-age=0');
        header('Pragma: no-cache');
        header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
        header('X-Accel-Expires: 0'); // Nginx proxy cache
        header('Vary: Cookie'); // Critical: Cache varies by user cookie
    }
    
    // Tell WordPress and caching plugins not to cache
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    if (!defined('DONOTCACHEOBJECT')) {
        define('DONOTCACHEOBJECT', true);
    }
    if (!defined('DONOTCACHEDB')) {
        define('DONOTCACHEDB', true);
    }
}
add_action('send_headers', 'imu_prevent_page_caching', 1); // Priority 1 = run first

/**
 * Early cache bypass based on wallet cookie (runs before WordPress fully loads)
 */
function imu_early_cache_bypass() {
    // If user has wallet cookie, ensure no caching
    if (!empty($_COOKIE['xrpl_account'])) {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
    }
}
add_action('init', 'imu_early_cache_bypass', 1);

/**
 * ---------------------------------------------------------------------------
 * ASTRA PARENT-THEME BOOTSTRAP - NOT IN THIS REPOSITORY.
 *
 * The Astra theme (v4.9.0) by Brainstorm Force supplies its own constants and
 * loads its own inc/ tree at this point in functions.php. That code is Astra's,
 * licensed GPLv2-or-later, and is obtained from https://wpastra.com/ - it is not
 * redistributed here, so this repository contains only IM Collectibles code.
 *
 * Nothing below this point depends on those constants; the marketplace code in
 * this file runs on WordPress core APIs.
 * ---------------------------------------------------------------------------
 */

// Custom Shortcodes

define('XAMAN_DEBUG', false); // Production: disabled

function xaman_log($message, $log_type = 'general') {
    // Skip verbose debug logging in production - only log errors/failures
    if (!defined('XAMAN_DEBUG') || !XAMAN_DEBUG) {
        if (stripos($message, 'error') === false && stripos($message, 'failed') === false) {
            return;
        }
    }
    
    $log_dir = __DIR__ . '/logs/';
    $log_file = $log_type === 'trade' ? $log_dir . 'trade.log' : $log_dir . 'xumm-webhook.log';
    if (!file_exists($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    $timestamp = date('[Y-m-d H:i:s]');
    file_put_contents($log_file, "$timestamp $message\n", FILE_APPEND);
}

// Add to /wp-content/themes/astra/functions.php

// Remove the site-wide video
// function add_background_video() {
//     echo '<video autoplay muted loop playsinline class="background-video" id="site-wide-video">';
//     echo '<source src="https://videos.pexels.com/video-files/5884322/5884322-uhd_2560_1440_24fps.mp4" type="video/mp4">';
//     echo 'Your browser does not support the video tag.';
//     echo '</video>';
// }
// add_action('wp_body_open', 'add_background_video');



// Burn To Earn Logic
add_shortcode('burn_to_earn', 'burn_to_earn_shortcode');
function burn_to_earn_shortcode() {
    ob_start();
    ?>
    <div class="burn-to-earn-container">
        <svg style="display: none;">
            <defs>
                <linearGradient id="fireGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" style="stop-color:#FF4500;stop-opacity:1" />
                    <stop offset="100%" style="stop-color:#FFD700;stop-opacity:1" />
                </linearGradient>
            </defs>
        </svg>
        <video id="video-background" autoplay muted loop>
            <source src="https://videos.pexels.com/video-files/2715412/2715412-uhd_3840_2160_30fps.mp4" type="video/mp4">
        </video>
        <div id="video-overlay"></div>
        <div class="banner banner-left"></div>
        <div class="banner banner-right"></div>
        <div class="title-section">
            <br><h1>FREQUENCY FIREPIT</h1>
            <br><br><h55>Are You Prepared To Burn 2 Earn?<br>Burn 10 of your Protector of the Frequencies or Ledger NFTs<br>To receive an exclusive 1 of 1 Frequency Firepit Protector<br>With the rewards of a Guardian!<br></h55>
        <br></div>
        <div id="progress-section" class="progress-section">
            <div class="counter">
                <span id="freq-counter">0</span>
                <p>Frequency Protectors</p>
            </div>
            <div class="counter">
                <span id="ledger-counter">0</span>
                <p>Ledger Protectors</p>
            </div>
        </div>
        <div id="progress-bar-section">
            <div id="progress-bar"><div class="progress-bar-fill"></div></div>
            <p style="text-align: center;">0/10 NFTs burned until next exclusive NFT!</p>
            <button id="design-protector-btn" style="display: none;">Design Your Protector</button>
        </div>
        <div id="burn-row"></div>
        <div id="nft-grid-section">
            <h3>NFT Vault</h3>
            <div class="nft-controls">
                <select id="nft-filter">
                    <option value="all">All Eligible NFTs</option>
                    <option value="frequency">Frequency Protectors</option>
                    <option value="ledger">Ledger Protectors</option>
                </select>
            </div>
            <div id="nft-grid"></div>
        </div>
        <br><br><div id="burn-history-section">
            <h3>Burn History</h3>
            <table id="burn-history-table">
                <thead><tr><th>ID</th><th>Status</th><th>Date</th></tr></thead>
                <tbody></tbody>
            </table>
            <button id="history-toggle">Expand History</button>
        </div>
        <br><br><div id="submission-history-section">
            <h3>Design History</h3>
            <table id="submission-history-table">
                <thead><tr><th>ID</th><th>Status</th><th>Date</th></tr></thead>
                <tbody></tbody>
            </table>
            <button id="submission-toggle">Expand Submissions</button>
        </div>
        <br><div id="design-form" class="design-form">
            <div class="form-content">
                <h3>Design Your Protector</h3>
                <label for="xrpl_account">XRPL Address</label>
                <input type="text" name="xrpl_account" id="xrpl_account" readonly>
                <label for="x_handle">X Handle</label>
                <input type="text" name="x_handle" id="x_handle" placeholder="Enter your X handle">
                <label for="email">Email</label>
                <input type="email" name="email" id="email" placeholder="Enter your email">
                <label for="description">Design Description (max 200 characters)</label>
                <textarea name="description" id="description" maxlength="200" placeholder="Describe your design: features, colors, accessories"></textarea>
                <label>Background Selection</label>
                <div class="background-carousel"></div>
                <label>Reference Image (optional, 5MB max)</label>
                <form id="dropzone-upload" class="dropzone" enctype="multipart/form-data">
                    <button type="button" class="dropzone-upload-btn">Choose Files</button>
                    <p class="error-message"></p>
                </form>
                <div class="form-actions">
                    <button type="submit" class="submit-form">Submit Design</button>
                    <button type="button" class="close-form">Close</button>
                </div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// ── Production console suppressor (enqueued globally, priority 1) ──────────
add_action('wp_enqueue_scripts', 'enqueue_console_suppressor', 1);
function enqueue_console_suppressor() {
    wp_enqueue_script(
        'imc-console-suppressor',
        get_stylesheet_directory_uri() . '/js/console-suppressor.js',
        [],
        filemtime(get_stylesheet_directory() . '/js/console-suppressor.js'),
        false  // load in <head> so it fires before any other script
    );
}

add_action('wp_enqueue_scripts', 'enqueue_burn_to_earn_assets');
function enqueue_burn_to_earn_assets() {
    $post = get_post();
    if ($post && has_shortcode($post->post_content ?? '', 'burn_to_earn')) {
        wp_enqueue_style(
            'animations-style',
            get_stylesheet_directory_uri() . '/css/animations.css',
            [],
            filemtime(get_stylesheet_directory() . '/css/animations.css')
        );
        wp_enqueue_style(
            'nft-style',
            get_stylesheet_directory_uri() . '/css/nft.css',
            ['animations-style'],
            filemtime(get_stylesheet_directory() . '/css/nft.css')
        );
        wp_enqueue_style(
            'burn-to-earn-style',
            get_stylesheet_directory_uri() . '/css/burn-to-earn.css',
            ['nft-style'],
            filemtime(get_stylesheet_directory() . '/css/burn-to-earn.css')
        );
        wp_enqueue_style(
            'dropzone-style',
            'https://cdnjs.cloudflare.com/ajax/libs/dropzone/5.9.3/min/dropzone.min.css',
            [],
            '5.9.3'
        );
        wp_enqueue_script(
            'burn-to-earn-js',
            get_stylesheet_directory_uri() . '/js/burn-to-earn.js',
            ['jquery', 'dropzone', 'canvas-confetti'],
            filemtime(get_stylesheet_directory() . '/js/burn-to-earn.js'),
            true
        );
        wp_enqueue_script('dropzone', 'https://cdnjs.cloudflare.com/ajax/libs/dropzone/5.9.3/min/dropzone.min.js', [], '5.9.3', true);
        wp_enqueue_script('canvas-confetti', 'https://cdn.jsdelivr.net/npm/canvas-confetti@1.5.1/dist/confetti.browser.min.js', [], '1.5.1', true);
        wp_localize_script('burn-to-earn-js', 'xrplMarketplace', [
            'ajax_url'       => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce('xrpl_marketplace_nonce'),
            'user_account'   => function_exists('imc_session_wallet') ? imc_session_wallet() : '',
            'myNftsHandler'  => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/my-nfts-handler.php',
        ]);
        wp_localize_script('burn-to-earn-js', 'xamanNotifications', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('xaman_notifications_nonce'),
            'externalUserId' => function_exists('imc_session_wallet') ? imc_session_wallet() : '',
            'siteUrl' => home_url('/')
        ]);
    }
}

add_action('wp_ajax_get_nonce', 'get_nonce_callback');
add_action('wp_ajax_nopriv_get_nonce', 'get_nonce_callback');

/**
 * CP-C3-A4 (08 Sep 2026) - BULK ACCOUNT -> DISPLAY NAME + SLUG, for the Holders tab.
 *
 * WHY AN AJAX ACTION AT ALL: holders come from the VPS
 * (metadata.imcollectibles.io, SQLite). Display names live HERE, in
 * wp_imc_artist_profiles (MySQL). The VPS cannot reach the WordPress database,
 * so the endpoint can never return names - the browser resolves them in a
 * second call. That is a constraint of the architecture, not a preference.
 *
 * WHY NOT imc_artist_display_map(): that function is exactly right for names and
 * is left untouched for its existing callers, but it returns display_name ONLY.
 * The holders list also needs `slug`, because profile links prefer
 * /user/{slug}/ over /user/{r-address}/ (see page-user.php L124, L1075). Same
 * query shape, one more column, and the same validation regex.
 *
 * ONE TABLE, TWO FRONT DOORS: wp_imc_artist_profiles serves BOTH /user/ and
 * /creators/ - imc-user-profiles.php adds columns to it rather than creating its
 * own. So one lookup covers artists, creators and collectors alike.
 *
 * PUBLIC: holders are public, so nopriv is registered too. Guarded by a hard cap
 * on the account list and per-account format validation - an uncapped IN (...)
 * driven by an anonymous POST body would be an obvious abuse surface.
 *
 * DEGRADES: a missing table returns an empty map, exactly as
 * imc_artist_display_map() does, so the caller falls back to short addresses
 * rather than erroring.
 */

/**
 * R-C3b — XRPL.TO ATTRIBUTION (v2: rendered INSIDE the footer band).
 *
 * WHY IT IS OWED. Measured across the metadata index:
 *   the clear majority of stored metadata was sourced or healed through them
 * ⚠ And not only external collections - a large share of our OWN IMC-minted NFTs were
 * healed through them, so 'external pages only' would have been wrong.
 *
 * ★ WHY 'METADATA' IS LOAD-BEARING. Holders come from Clio, sales and floor from
 * our own stream, mint prices from WordPress. 'Data provided by' would credit
 * them for work they did not do.
 *
 * 🔴 WHY IT MOVED INTO THE FOOTER. v1 rendered a band as a DIRECT CHILD OF BODY,
 * outside every theme wrapper - nothing else on this site sits there. Five
 * builds later the computed style was provably correct (colour, background,
 * opacity, no animation, nothing painted on top, 11.5:1 contrast) and it still
 * read as faint and appeared to fade in and out between screenshots.
 *
 * ★ So: stop fighting an environment we do not understand, and render inside the
 * footer band instead - the same container as .imc-footer-copyright, which is
 * crisply legible at #555, a DARKER colour than anything v1 tried. Templates
 * opt in by setting a flag; footer.php prints it.
 */
if (!function_exists('imc_xrplto_attribution')) {
    function imc_xrplto_attribution() {
        // Sets a flag only. footer.php renders it inside the bottom bar.
        $GLOBALS['imc_show_xrplto_attrib'] = true;
    }
}

if (!function_exists('imc_xrplto_attribution_render')) {
    function imc_xrplto_attribution_render() {
        if (empty($GLOBALS['imc_show_xrplto_attrib'])) { return; }
        // ⚠ base.css and custom.css both carry
        //      body, p, li, a, span, div, ... { color: white !important; }
        // so the colour still needs !important. Everything else inherits the
        // footer's own rendering context, which demonstrably works.
        // ★ Matched to the footer's OWN values, not invented ones:
        //     .imc-footer-copyright  color:#555     font-size:0.78rem
        //     .imc-footer-email a    color:#8a8a8a  font-size:0.78rem
        // Sitting in the same bar, it should read as part of it - not as a
        // louder note bolted alongside.
        //
        // ⚠ 'Metadata assisted by', not 'Collection metadata' - the line now
        // appears on single-NFT, user, artist and my-NFTs pages too, where
        // 'Collection' would be wrong. The word METADATA still does the real
        // work: it scopes the credit to names, images and traits, and away from
        // holders (Clio), sales and floor (our stream) and mint prices
        // (WordPress), none of which is theirs.
        echo '<p class="imc-footer-attrib" style="color:#555 !important;'
           . 'font-size:0.78rem !important;margin:0 !important;">'
           . 'Metadata assisted by '
           . '<a href="https://xrpl.to" target="_blank" rel="noopener nofollow" '
           . 'style="color:#8a8a8a !important;text-decoration:none !important;">XRPL.to</a>'
           . '</p>';
    }
}

add_action('wp_ajax_imc_holder_names',        'imc_holder_names_ajax');
add_action('wp_ajax_nopriv_imc_holder_names', 'imc_holder_names_ajax');
function imc_holder_names_ajax() {
    global $wpdb;
    // CP-C3-D (09 Sep 2026): this handler now resolves TWO things in ONE call -
    // display names for wallets, and MINT PRICES for NFT ids.
    //
    // Why here and not a second endpoint: Activity already fires one request
    // per page to resolve seller+buyer names. Adding a second for prices would
    // double the round trips for the same render. Callers that send no nft_ids
    // get mints:{} and are entirely unaffected.
    //
    // ⚠ wp_imc_purchases lives in WordPress MySQL; recent_sales is on the VPS
    // (SQLite). The endpoint CANNOT return mint prices - the same split that
    // put display names here in A-4.
    $raw = $_POST['accounts'] ?? [];
    if (is_string($raw)) { $raw = explode(',', $raw); }
    if (!is_array($raw)) { $raw = []; }
    // Validate + dedupe, then cap. Same regex as imc_artist_display_map().
    $want = [];
    foreach ($raw as $a) {
        $a = trim((string) $a);
        if ($a !== '' && preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $a)) {
            $want[$a] = true;
        }
        if (count($want) >= 200) { break; }   // hard cap
    }
    // NOTE: no early return on an empty account list any more - a caller may be
    // asking only for mint prices.
    $out = [];
    if ($want && function_exists('imc_artist_profiles_table')) {
        $table = imc_artist_profiles_table();
        if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            $accounts = array_keys($want);
            $ph   = implode(',', array_fill(0, count($accounts), '%s'));
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT artist_account, display_name, slug
                     FROM {$table}
                     WHERE artist_account IN ($ph)",
                    ...$accounts
                ),
                ARRAY_A
            );
            if ($rows) {
                foreach ($rows as $r) {
                    $nm = trim((string) ($r['display_name'] ?? ''));
                    $sg = trim((string) ($r['slug'] ?? ''));
                    // A row with neither a name nor a slug tells the caller nothing.
                    if ($nm === '' && $sg === '') { continue; }
                    $out[$r['artist_account']] = ['name' => $nm, 'slug' => $sg];
                }
            }
        }
    }

    // ---- CP-C3-D: MINT PRICES -------------------------------------------
    // Measured across the purchase table:
    //   * all carry an nftoken_id  -> every mint is linkable
    //   * 0 duplicate nftoken_id             -> one purchase, one price
    //   * price_currency is populated EVEN WHEN price_xrp = 0, which resolves
    //     free-vs-token WITHOUT parsing the accepted_currencies JSON that the
    //     mint box has to (collections.php L1674).
    //
    //   price_xrp > 0                      -> amount + currency
    //   price_xrp = 0 AND currency = XRP   -> FREE
    //   price_xrp = 0 AND currency != XRP  -> UNKNOWN (a handful of rows:
    //        XMEME). Token-priced mints whose amount lives in the JSON.
    //        ⚠ We return free=false with no price so the UI shows a dash.
    //        A handful of rows does not justify a JSON parse, and labelling a PAID
    //        mint FREE is the one error a buyer would actually notice.
    //
    // ⚠ mint_status IN ('minted','claimed') only. cancelled purchases never
    // completed and are already excluded from volume (collections.php L1905).
    //
    // ⚠ D14: a token price is returned in ITS OWN currency, never converted.
    $mints = [];
    $rawIds = $_POST['nft_ids'] ?? [];
    if (is_string($rawIds)) { $rawIds = explode(',', $rawIds); }
    if (is_array($rawIds) && $rawIds) {
        $wantIds = [];
        foreach ($rawIds as $n) {
            $n = strtoupper(trim((string) $n));
            // An NFTokenID is exactly 64 hex characters.
            if (preg_match('/^[0-9A-F]{64}$/', $n)) { $wantIds[$n] = true; }
            if (count($wantIds) >= 200) { break; }   // hard cap, as for accounts
        }
        if ($wantIds) {
            $pt = $wpdb->prefix . 'imc_purchases';
            if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $pt)) === $pt) {
                $ids  = array_keys($wantIds);
                $iph  = implode(',', array_fill(0, count($ids), '%s'));
                $prow = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT nftoken_id, price_xrp, price_currency
                         FROM {$pt}
                         WHERE nftoken_id IN ($iph)
                           AND mint_status IN ('minted','claimed')",
                        ...$ids
                    ),
                    ARRAY_A
                );
                if ($prow) {
                    foreach ($prow as $r) {
                        $px  = (float) ($r['price_xrp'] ?? 0);
                        $cur = strtoupper(trim((string) ($r['price_currency'] ?? '')));
                        if ($px > 0) {
                            $mints[strtoupper($r['nftoken_id'])] =
                                ['price' => $px, 'currency' => $cur ?: 'XRP', 'free' => false];
                        } elseif ($cur === 'XRP' || $cur === '') {
                            $mints[strtoupper($r['nftoken_id'])] =
                                ['price' => 0, 'currency' => 'XRP', 'free' => true];
                        } else {
                            // token-priced, amount not available here
                            $mints[strtoupper($r['nftoken_id'])] =
                                ['price' => null, 'currency' => $cur, 'free' => false];
                        }
                    }
                }
            }
        }
    }
    wp_send_json(['names' => $out, 'mints' => $mints]);
}

function get_nonce_callback() {
    wp_send_json(['nonce' => wp_create_nonce('xrpl_marketplace_nonce')]);
}

// End Of Burn-2-Earn Logic




/**
 * CP-C3-A4: Chart.js for the Holders growth chart on the collection page.
 * Same handle as the rewards enqueue below, so WordPress loads it once even if
 * both conditions were ever true on one request. Footer-loaded; the chart is
 * built lazily on the Change View click, never on page load.
 */
function imc_enqueue_chartjs_for_collections() {
    // B-f (13 Sep 2026) + B-f2 FIX (13 Sep, same day).
    //
    // B-f originally tested is_page_template('page-creator-dashboard.php') alone,
    // reasoning that the meta-title switch below already matches that string. That
    // was WRONG and the charts never loaded: the switch reads
    //     $template_basename = basename($template_slug);
    // so it has already stripped the directory. is_page_template() compares the
    // FULL stored slug, and this theme keeps its templates in page-templates/.
    //
    // Every other call in this file gets it right - trading-hub-dashboard at L1980
    // uses the prefix, and the collections OG block at L5930 hedges with BOTH
    // forms. Hedging is correct: a template can be registered either way depending
    // on where the file physically sits, and testing both costs nothing.
    if (is_page_template('page-templates/collections.php')
        || is_page_template('collections.php')
        || is_page('collections')
        || is_page_template('page-templates/page-creator-dashboard.php')
        || is_page_template('page-creator-dashboard.php')) {
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js', [], '4.4.3', true);
    }
}
add_action('wp_enqueue_scripts', 'imc_enqueue_chartjs_for_collections', 20);

function enqueue_rewards_script() {
    if (is_page('champion-of-frequencies')) { // Correct slug
        // CHANGE: Enqueued external CDNs for Chart.js and particles.js (load in footer with true).
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js', [], '4.4.3', true);
        wp_enqueue_script('particles-js', 'https://cdn.jsdelivr.net/npm/particles.js@2.0.0/particles.min.js', [], '2.0.0', true);
        
        // CHANGE: Added dependencies for rewards.js (loads after Chart/particles), updated version to 1.1 for cache.
        $rewards_js_path = get_template_directory() . '/js/rewards.js';
$rewards_js_ver  = file_exists($rewards_js_path) ? filemtime($rewards_js_path) : '1.4';
wp_enqueue_script('rewards-js', get_template_directory_uri() . '/js/rewards.js', ['chart-js', 'particles-js'], $rewards_js_ver, true);
;
        wp_localize_script('rewards-js', 'xrplMarketplace', [
            'nonce' => wp_create_nonce('xrpl_marketplace_nonce'),
            'ajax_url' => admin_url('admin-ajax.php')
        ]);
    }
}
add_action('wp_enqueue_scripts', 'enqueue_rewards_script');

global $wpdb;
$progress_table = $wpdb->prefix . 'game_progress';

function create_progress_table() {
    global $wpdb;
    $progress_table = $wpdb->prefix . 'game_progress';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $progress_table (
        xrpl_account varchar(255) NOT NULL PRIMARY KEY,
        userdata longtext,
        tasks longtext,
        achievements longtext,
        version varchar(50),
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        songUnlocks longtext,
        superFrenzyCharacters longtext,
        campaignCompletions longtext,
        progress_data longtext,
        last_updated timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) $charset_collate;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
add_action('after_setup_theme', 'create_progress_table');

function create_cooldown_table() {
    global $wpdb;
    $cooldown_table = $wpdb->prefix . 'game_cooldowns';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $cooldown_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        xrpl_account varchar(255) NOT NULL,
        cooldowns_data longtext NOT NULL,
        last_updated timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY xrpl_account (xrpl_account)
    ) $charset_collate;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
add_action('after_setup_theme', 'create_cooldown_table');

function get_progress_nonce() {
    wp_send_json_success(['nonce' => wp_create_nonce('xrpl_marketplace_nonce')]);
}
add_action('wp_ajax_get_progress_nonce', 'get_progress_nonce');
add_action('wp_ajax_nopriv_get_progress_nonce', 'get_progress_nonce');

function fetch_cooldowns() {
    check_ajax_referer('xrpl_marketplace_nonce', '_wpnonce');
    global $wpdb;
    $cooldown_table = $wpdb->prefix . 'game_cooldowns';
    $account = sanitize_text_field($_GET['account']);
    $row = $wpdb->get_row($wpdb->prepare("SELECT cooldowns_data FROM $cooldown_table WHERE xrpl_account = %s", $account), ARRAY_A);
    $cooldowns = $row ? json_decode($row['cooldowns_data'], true) : ['XFT' => 0, 'XMEME' => 0];
    wp_send_json_success(['cooldowns' => $cooldowns]);
}
add_action('wp_ajax_fetch-cooldowns', 'fetch_cooldowns');
add_action('wp_ajax_nopriv_fetch-cooldowns', 'fetch_cooldowns');

function save_progress() {
    check_ajax_referer('xrpl_marketplace_nonce', 'nonce');
    global $wpdb;
    $progress_table = $wpdb->prefix . 'game_progress';
    $data = json_decode(stripslashes($_POST['data']), true);
    // ========================================================================
    // IDENTITY. This write is a $wpdb->replace -- DELETE + INSERT -- so it
    // overwrites the whole row. Taken from ?account= it let any caller wipe
    // another player's save, and let a caller write their own `tasks` JSON,
    // which task-handler.php verify_daily_game_win() reads to decide whether a
    // reward is owed. The wallet now comes from the proven session instead.
    //
    // REJECTS rather than returning empty, unlike the read above: there is no
    // 'nobody' to write a save for. game.html writes localStorage before it
    // calls this (saveUserData, line 306), so a refused sync costs the player
    // nothing locally -- only the server copy stops updating.
    // ========================================================================
    $gp_auth = function_exists('imc_session_require_wallet')
        ? imc_session_require_wallet('')
        : array('ok' => false, 'wallet' => '');
    $account = !empty($gp_auth['ok']) ? (string) $gp_auth['wallet'] : '';

    if ($account === '') {
        wp_send_json_error(['message' => 'Sign in to sync your progress']);
    }
    $wpdb->replace($progress_table, [
        'xrpl_account' => $account,
        'userdata' => json_encode($data['userdata'] ?? []),
        'tasks' => json_encode($data['tasks'] ?? []),
        'achievements' => json_encode($data['achievements'] ?? []),
        'songUnlocks' => json_encode($data['songUnlocks'] ?? []),
        'superFrenzyCharacters' => json_encode($data['superFrenzyCharacters'] ?? []),
        'campaignCompletions' => json_encode($data['campaignCompletions'] ?? []),
        'version' => $data['version'] ?? '1.0',
        'progress_data' => json_encode($data)
    ]);
    if ($wpdb->last_error) {
        error_log("save_progress DB error: " . $wpdb->last_error);
        wp_send_json_error(['message' => 'Database error']);
    }
    wp_send_json_success();
}
add_action('wp_ajax_save_progress', 'save_progress');
add_action('wp_ajax_nopriv_save_progress', 'save_progress');

function load_progress() {
    check_ajax_referer('xrpl_marketplace_nonce', 'nonce');

    global $wpdb;
    $progress_table = $wpdb->prefix . 'game_progress';

    // ========================================================================
    // IDENTITY. A player's saved game is their own data. It comes from the
    // proven wallet session, never from ?account= -- an XRPL address is public,
    // so the parameter proves nothing and supplying someone else's returned
    // their whole save.
    //
    // With no session we return the SAME payload the 'no row yet' branch below
    // already returns, so game.html takes its existing new-player path and keeps
    // playing from localStorage. No new client behaviour, no console errors.
    // ========================================================================
    $gp_auth = function_exists('imc_session_require_wallet')
        ? imc_session_require_wallet('')
        : array('ok' => false, 'wallet' => '');
    $account = !empty($gp_auth['ok']) ? (string) $gp_auth['wallet'] : '';

    if ($account === '') {
        wp_send_json_success([
            'data' => [
                'userData'      => null,
                'tasks'         => [],
                'achievements'  => [],
                'version'       => '1.0',
                'updated_at'    => null
            ]
        ]);
    }

    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM $progress_table WHERE xrpl_account = %s", $account),
        ARRAY_A
    );

    if ($row) {
        // Prefer the full snapshot if present
        $full = json_decode($row['progress_data'] ?? '', true);
        if (!is_array($full) || empty($full)) {
            $full = json_decode($row['userdata'] ?? '', true);
        }

        // Fallback: reconstruct key arrays from individual columns
        if (!is_array($full) || empty($full)) {
            $full = [];
            $full['songUnlocks'] = json_decode($row['songUnlocks'] ?? '[]', true) ?: [];
            $full['superFrenzyCharacters'] = json_decode($row['superFrenzyCharacters'] ?? '[]', true) ?: [];
            $full['campaignCompletions'] = json_decode($row['campaignCompletions'] ?? '[]', true) ?: [];
        }

        // Ensure expected defaults exist
        $defaults = [
            'xp' => 0,
            'level' => 1,
            'lastLogin' => null,
            'streakDays' => 0,
            'songUnlocks' => [],
            'xpBoost' => 0,
            'superFrenzyCharacters' => [],
            'campaignCompletions' => [],
            'xft' => 0
        ];
        $userData = array_merge($defaults, $full ?: []);

        $tasks = json_decode($row['tasks'] ?? '', true) ?: [];
        $achievements = json_decode($row['achievements'] ?? '', true) ?: [];

        wp_send_json_success([
            'data' => [
                'userData'      => $userData,
                'tasks'         => $tasks,
                'achievements'  => $achievements,
                'version'       => $row['version'] ?: '1.0',
                'updated_at'    => $row['updated_at'] ?? ($row['last_updated'] ?? null),
            ]
        ]);
    } else {
        // No row yet; return sane defaults
        wp_send_json_success([
            'data' => [
                'userData'      => null,
                'tasks'         => [],
                'achievements'  => [],
                'version'       => '1.0',
                'updated_at'    => null
            ]
        ]);
    }
}

add_action('wp_ajax_load_progress', 'load_progress');
add_action('wp_ajax_nopriv_load_progress', 'load_progress');

/**
 * Phase D - On-demand metadata refresh (XRPL-wide / external NFTs).
 * Nonce-guarded WordPress -> VPS bridge. Triggers a synchronous reindex of an
 * NFT's current on-chain metadata so dynamic (NFTokenModify) changes reflect
 * immediately on the single-NFT page. A soft per-user(IP)+NFT cooldown sits in
 * front of the VPS reindex endpoint (itself IP rate-limited to 10/min).
 * Returns the freshly cached image_url (resolved, self-busting) + image_proxy.
 */
function imc_refresh_nft() {
    check_ajax_referer('xrpl_marketplace_nonce', 'nonce');

    $nft_id = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $_POST['nft_id'] ?? ''));
    if (strlen($nft_id) !== 64) {
        wp_send_json_error(['message' => 'Invalid NFT ID.']);
    }

    // Soft cooldown: one refresh per user(IP)+NFT per 10 minutes.
    $cd_key = 'imc_refresh_cd_' . md5(($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $nft_id);
    if (get_transient($cd_key)) {
        wp_send_json_error(['message' => 'Just refreshed - please try again in 10+ minutes.']);
    }
    set_transient($cd_key, 1, 600);

    $url  = 'https://metadata.imcollectibles.io/?action=reindex&id=' . urlencode($nft_id);
    $resp = wp_remote_get($url, [
        'timeout'   => 15,
        'sslverify' => true,
        'headers'   => ['Accept' => 'application/json'],
    ]);

    if (is_wp_error($resp)) {
        wp_send_json_error(['message' => 'Refresh service unavailable. Please try again shortly.']);
    }

    $code = (int) wp_remote_retrieve_response_code($resp);
    $data = json_decode(wp_remote_retrieve_body($resp), true);

    if ($code === 429) {
        wp_send_json_error(['message' => 'Refresh limit reached - please try again in 10+ minutes.']);
    }
    if (is_array($data) && !empty($data['busy'])) {
        wp_send_json_error(['message' => 'A refresh is already running - try again in a few seconds.']);
    }
    if ($code !== 200 || !is_array($data) || empty($data['success'])) {
        $emsg = (is_array($data) && !empty($data['error'])) ? $data['error'] : 'Refresh failed. Please try again shortly.';
        wp_send_json_error(['message' => $emsg]);
    }

    $nft = (isset($data['nft']) && is_array($data['nft'])) ? $data['nft'] : [];
    wp_send_json_success([
        'status'      => $data['status'] ?? '',
        'image_url'   => $nft['image_url']   ?? '',
        'image_proxy' => $nft['image_proxy'] ?? '',
    ]);
}
add_action('wp_ajax_imc_refresh_nft', 'imc_refresh_nft');
add_action('wp_ajax_nopriv_imc_refresh_nft', 'imc_refresh_nft');





function enqueue_custom_styles() {
    // Enqueue base.css
    wp_enqueue_style(
        'custom-base',
        get_template_directory_uri() . '/css/base.css',
        array(),
        filemtime(get_template_directory() . '/css/base.css')
    );

    // Enqueue layout.css (depends on base.css)
    wp_enqueue_style(
        'custom-layout',
        get_template_directory_uri() . '/css/layout.css',
        array('custom-base'),
        filemtime(get_template_directory() . '/css/layout.css')
    );

    // Enqueue pages.css (depends on base.css and layout.css)
    wp_enqueue_style(
        'custom-pages',
        get_template_directory_uri() . '/css/pages.css',
        array('custom-base', 'custom-layout'),
        filemtime(get_template_directory() . '/css/pages.css')
    );

    // Enqueue profile.css (depends on base.css)
    wp_enqueue_style(
        'custom-profile',
        get_template_directory_uri() . '/css/profile.css',
        array('custom-base'),
        filemtime(get_template_directory() . '/css/profile.css')
    );

    // Enqueue nft.css (depends on base.css)
    wp_enqueue_style(
        'custom-nft',
        get_template_directory_uri() . '/css/nft.css',
        array('custom-base'),
        filemtime(get_template_directory() . '/css/nft.css')
    );

    // Enqueue animations.css (no dependencies)
    wp_enqueue_style(
        'custom-animations',
        get_template_directory_uri() . '/css/animations.css',
        array(),
        filemtime(get_template_directory() . '/css/animations.css')
    );

    // Enqueue responsive.css (depends on all other styles)
    wp_enqueue_style(
        'custom-responsive',
        get_template_directory_uri() . '/css/responsive.css',
        array('custom-base', 'custom-layout', 'custom-pages', 'custom-profile', 'custom-nft', 'custom-animations'),
        filemtime(get_template_directory() . '/css/responsive.css')
    );
}
add_action('wp_enqueue_scripts', 'enqueue_custom_styles');

function enqueue_video_playback_script() {
    wp_add_inline_script('astra-main-script', "
        window.onload = function() {
            // Slow down page-specific videos
            const pageSpecificVideos = document.querySelectorAll('.home-background-video, .mission-background-video, .how-to-join-background-video, .how-to-mint-background-video, .profile-background-video, .login-background-video, .protectors-background-video, .champion-background-video, .premium-videos-background-video');
            pageSpecificVideos.forEach(video => {
                video.playbackRate = 0.5;
                video.play().catch(function(error) {
                    console.error('Video playback failed:', error);
                });
            });
        };
    ");
}
add_action('wp_enqueue_scripts', 'enqueue_video_playback_script');

function enqueue_xaman_scripts() {
    if (!is_page()) {
        xaman_log("Not profile page, skipping script enqueue");
        return;
    }

$account = function_exists('imc_session_wallet') ? imc_session_wallet() : '';
    xaman_log("In enqueue_xaman_scripts, session wallet = " . ($account ?: 'not set'));

    // Localize script with AJAX URL, nonces, and XRPL account
    // Note: PushEngage removed - OneSignal now handles push notifications via header.php
    $data = [
        'ajax_url' => admin_url('admin-ajax.php', 'https'),
        'nonce' => wp_create_nonce('xaman_notifications_nonce'),
        'prefsNonce' => wp_create_nonce('xaman_notifications_nonce'),
        'testNonce' => wp_create_nonce('xaman_notifications_nonce'),
        'checkNonce' => wp_create_nonce('xaman_notifications_nonce'),
        'siteUrl' => home_url('/')
    ];
    if (!empty($account) && preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        $data['externalUserId'] = $account;
        xaman_log("Localized notification script with account: $account");
    } else {
        xaman_log("No valid xrpl_account cookie found for localization");
    }

    // OneSignal handles notifications now - xaman-notifications.js no longer needed
    // Keeping minimal localization for any legacy code that might reference it
    wp_localize_script('jquery', 'xamanNotifications', $data);
    xaman_log("OneSignal notifications active - legacy xamanNotifications object created for compatibility");

// Note: Service worker registration moved to header.php (OneSignal handles it)
    // This block cleans up old service-worker.js registrations from PushEngage era
    add_action('wp_footer', function() use ($account) {
        ?>
        <script>
            // One-time cleanup: Remove old service-worker.js registrations
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.getRegistrations().then(function(registrations) {
                    registrations.forEach(function(reg) {
                        // Only remove service-worker.js, NOT OneSignal
                        if (reg.active && reg.active.scriptURL.includes('service-worker.js') && !reg.active.scriptURL.includes('OneSignal')) {
                            reg.unregister().then(function() {
                                console.log('[SW Cleanup] Removed old service-worker.js');
                            });
                        }
                    });
                });
            }
        </script>
        <?php
    });
}
add_action('wp_enqueue_scripts', 'enqueue_xaman_scripts');


add_action('wp_ajax_log_js_error', function() {
    $message = isset($_POST['message']) ? sanitize_text_field($_POST['message']) : 'No message provided';
    xaman_log("JavaScript error: $message");
    wp_send_json(['success' => true], 200);
});
add_action('wp_ajax_nopriv_log_js_error', function() {
    $message = isset($_POST['message']) ? sanitize_text_field($_POST['message']) : 'No message provided';
    xaman_log("JavaScript error (no priv): $message");
    wp_send_json(['success' => true], 200);
});



add_action('admin_menu', function() {
    add_menu_page('Marketplace Advertisements', 'Marketplace Ads', 'manage_options', 'marketplace-advertisements', function() {
        include get_template_directory() . '/admin-advertisements.php';
    }, 'dashicons-images-alt2', 30);
});

// AJAX handlers for advertisement management
add_action('wp_ajax_xrpl_save_assets', 'xrpl_save_assets_callback');
add_action('wp_ajax_xrpl_list_assets', 'xrpl_list_assets_callback');
add_action('wp_ajax_xrpl_delete_asset', 'xrpl_delete_asset_callback');

function xrpl_save_assets_callback() {
    if (!current_user_can('manage_options') || !check_ajax_referer('xrpl_advertisement_nonce', 'nonce', false)) {
        wp_send_json_error(['error' => 'Invalid nonce or permissions'], 403);
        exit;
    }

    $assets = get_option('marketplace_assets', ['banner' => null, 'collections' => []]);
    $response = ['success' => true, 'errors' => []];

    // Handle banner
    $banner_id = isset($_POST['banner_image_id']) ? intval($_POST['banner_image_id']) : 0;
    if ($banner_id) {
        $banner_url = wp_get_attachment_url($banner_id);
        if ($banner_url) {
            $assets['banner'] = [
                'id' => $banner_id,
                'url' => $banner_url
            ];
        } else {
            $response['errors'][] = 'Invalid banner image ID.';
        }
    }

    // Handle collections
    $collection_ids = isset($_POST['collection_image_id']) ? array_map('intval', $_POST['collection_image_id']) : [];
    foreach (['guardians', 'frequencies', 'ledger', 'lasvegas'] as $slug) {
        if (!empty($collection_ids[$slug])) {
            $url = wp_get_attachment_url($collection_ids[$slug]);
            if ($url) {
                $assets['collections'][$slug] = [
                    'id' => $collection_ids[$slug],
                    'url' => $url
                ];
            } else {
                $response['errors'][] = "Invalid $slug image ID.";
            }
        }
    }

    if (!empty($response['errors'])) {
        $response['success'] = false;
        $response['error'] = implode(' ', $response['errors']);
    } else {
        update_option('marketplace_assets', $assets);
    }
    wp_send_json($response);
}

function xrpl_list_assets_callback() {
    if (!current_user_can('manage_options') || !check_ajax_referer('xrpl_advertisement_nonce', 'nonce', false)) {
        wp_send_json_error(['error' => 'Invalid nonce or permissions'], 403);
        exit;
    }

    $assets = get_option('marketplace_assets', ['banner' => null, 'collections' => []]);
    wp_send_json_success([
        'banner' => $assets['banner'],
        'collections' => $assets['collections']
    ]);
}

function xrpl_delete_asset_callback() {
    if (!current_user_can('manage_options') || !check_ajax_referer('xrpl_advertisement_nonce', 'nonce', false)) {
        wp_send_json_error(['error' => 'Invalid nonce or permissions'], 403);
        exit;
    }

    $id = intval($_POST['id']);
    $type = sanitize_text_field($_POST['type']);
    $slug = sanitize_text_field($_POST['slug'] ?? '');
    $assets = get_option('marketplace_assets', ['banner' => null, 'collections' => []]);

    if ($type === 'banner' && $assets['banner'] && $assets['banner']['id'] === $id) {
        $assets['banner'] = null;
    } elseif ($type === 'collection' && $slug && isset($assets['collections'][$slug]) && $assets['collections'][$slug]['id'] === $id) {
        unset($assets['collections'][$slug]);
    } else {
        wp_send_json_error(['error' => 'Asset not found'], 404);
        exit;
    }

    update_option('marketplace_assets', $assets);
    wp_send_json_success(['message' => 'Asset deleted']);
}


// Path: /public_html/wp-content/themes/astra/functions.php
function enqueue_chat_styles() {
    if (function_exists('imc_session_wallet') && imc_session_wallet() !== '') {
        wp_enqueue_style('sitewide-chat-style', get_template_directory_uri() . '/css/chat.css', [], '2.3');
    }
}
add_action('wp_enqueue_scripts', 'enqueue_chat_styles');

function enqueue_chat_scripts() {
    if (function_exists('imc_session_wallet') && imc_session_wallet() !== '') {
        wp_enqueue_script('emoji-picker', 'https://unpkg.com/emoji-picker-element@^1.0.0', [], '1.0', true);
        wp_enqueue_script('sitewide-chat', get_template_directory_uri() . '/js/chat.js', ['jquery', 'emoji-picker'], '2.3', true);
        $xrpl_account = is_user_logged_in() ? get_user_meta(get_current_user_id(), 'xrpl_account', true) : (function_exists('imc_session_wallet') ? imc_session_wallet() : '');
        wp_localize_script('sitewide-chat', 'chatConfig', [
            'csrfToken' => wp_create_nonce('chat_nonce'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'xrplMarketplace' => [
                'nonce' => wp_create_nonce('xrpl_marketplace_nonce'),
                'user_account' => $xrpl_account
            ]
        ]);
    }
}
add_action('wp_enqueue_scripts', 'enqueue_chat_scripts');

// v132: Fix emoji-picker ESM module loading — wp_script_add_data doesn't support 'type'
add_filter('script_loader_tag', function($tag, $handle) {
    if ($handle === 'emoji-picker') {
        return str_replace(' src', ' type="module" src', $tag);
    }
    return $tag;
}, 10, 2);

// Set secure cookie flags
add_action('init', function () {
    // v279: Mobile Xaman sign-in — accept ?xrpl_login=RADDRESS from xaman-signin-complete.php.
    // When Xaman's in-app browser (WKWebView / Chrome Custom Tab) opens the return URL,
    // cookie isolation may prevent Set-Cookie from reaching the main browser.
    // Passing the account in the URL parameter lets functions.php set the cookie on
    // the destination page load in the main browser context.
    if (isset($_GET['xrpl_login'])) {
        $login_account = sanitize_text_field($_GET['xrpl_login']);
        // Fix 3 (Aug 2026): CONSUME-ONCE. Before this, ?xrpl_login=<any valid r-addr>
        // minted a real session token on regex alone - a permanent, shareable URL
        // credential that survived logout via history back-nav / tab restore. Now the
        // redirect is honoured ONLY if xaman-signin-complete.php just set a matching
        // ticket (uuid-proven sign, <=300s ago), and the ticket is deleted on use.
        $imc_xlogin_key = 'imc_xlogin_' . md5($login_account);
        $imc_xlogin_ok  = (bool) get_transient($imc_xlogin_key);
        if ($imc_xlogin_ok) { delete_transient($imc_xlogin_key); }
        if ($imc_xlogin_ok && preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $login_account)) {
            $cookie_options = [
                'expires'  => time() + (86400 * 30),
                'path'     => '/',
                'domain'   => '.imcollectibles.io',
                'secure'   => true,
                'httponly' => false,
                'samesite' => 'Lax'
            ];
            setcookie('xrpl_account', $login_account, $cookie_options);
            // Also put it in $_COOKIE so this same request sees it immediately
            $_COOKIE['xrpl_account'] = $login_account;
            // Session-auth 2a-b (Step 2, Aug 2026): this account arrived from
            // xaman-signin-complete.php, which already verified the Xaman signature.
            // It is PROVEN, so mint the httponly session token here too (mobile Xaman
            // redirect path). Xaman only. Guarded + kill-switched.
            if (function_exists('imc_session_mint')) {
                imc_session_mint($login_account, 'xaman');
            }
        }
        // Fix 3 (Aug 2026): strip ?xrpl_login from the URL so it never lingers in
        // history or gets shared. The cookie/token are already set above; the clean
        // reload lands logged-in with a bare URL. Only redirect if headers permit.
        if (!headers_sent()) {
            wp_safe_redirect(remove_query_arg('xrpl_login'));
            exit;
        }
    }

    if (isset($_COOKIE['xrpl_account']) && preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $_COOKIE['xrpl_account'])) {
        $cookie_options = [
            'expires'  => time() + (86400 * 30),
            'path'     => '/',
            'domain'   => '.imcollectibles.io', // ✅ ensure single, shared cookie
            'secure'   => true,
            'httponly' => false,               // JS must read it
            'samesite' => 'Lax'
        ];
        setcookie('xrpl_account', $_COOKIE['xrpl_account'], $cookie_options);

        // Session-auth 2a-b (Step 2, Aug 2026): GRACE-MINT. This runs on every page
        // load with a valid soft cookie. If the user has a soft cookie but no session
        // token yet (desktop Xaman completes via polling and never minted; or a
        // pre-2a session), mint one now so the installed base is carried onto tokens
        // silently, with no re-login, BEFORE the 2b token-only flip.
        // XAMAN ONLY: Joey has no server-side proof, so we never grace-mint a Joey
        // soft cookie (that would bless an unproven identity). Joey mints only once
        // its signed-challenge proof ships (2a-c).
        // 2a-b FIX (Aug 2026): NEVER grace-mint on a logout request. Logout deletes the
        // session row, but this block runs on the SAME ?logout/?loggedout page load and
        // would immediately re-mint a fresh row from the not-yet-cleared soft cookie,
        // leaving a row behind forever. Skip grace-mint whenever a logout is in progress.
        $imc_is_logout = (isset($_GET['logout']) && $_GET['logout'] === 'true')
                      || (isset($_GET['loggedout']) && $_GET['loggedout'] === 'true');
        // 2b: grace-mint carries the installed base onto tokens BEFORE the flip. It mints
        // from the soft cookie with no proof, so it MUST switch off the instant we go
        // token-only -- otherwise a spoofed xrpl_account cookie would be blessed into a real
        // token and the spoof would survive the flip. Gated on the same flag = dies atomically.
        $imc_token_only = defined('IMC_SESSION_TOKEN_ONLY') && IMC_SESSION_TOKEN_ONLY;
        if (!$imc_is_logout
            && !$imc_token_only
            && function_exists('imc_session_mint')
            && empty($_COOKIE[defined('IMC_SESSION_COOKIE') ? IMC_SESSION_COOKIE : 'imc_session'])
            && (($_COOKIE['xrpl_wallet_type'] ?? '') !== 'joey')) {
            imc_session_mint($_COOKIE['xrpl_account'], 'xaman');
        }
    }
});


add_action('wp_footer', 'render_sitewide_chat_ui');
function render_sitewide_chat_ui() {
    if (!function_exists('imc_session_wallet') || imc_session_wallet() === '') return;
    ?>
    <div id="sitewide-chat-toggle" style="position: fixed; bottom: 20px; right: 20px; z-index: 9999;">
        <button id="open-chat-btn" aria-label="Toggle chat window" style="padding: 10px 15px; background-color: #111; border: 2px solid var(--imu-gold, #d6ba66); color: white; border-radius: 50px;">
            💬 <span id="global-unread-badge" class="unread-badge" style="display: none;"></span>
        </button>
    </div>

    <div id="sitewide-chat-container" class="chat-popup-container" style="display: none;" role="dialog" aria-labelledby="chat-header">
        <div class="chat-popup">
            <div class="chat-sidebar" role="navigation" aria-label="Chat list">
                <button id="sidebar-toggle" aria-label="Toggle chat list" style="background: none; border: none; color: var(--imu-gold, #d6ba66); font-size: 20px; padding: 10px; cursor: pointer;">☰</button>
                <input type="text" id="chat-user-search" placeholder="Search by name or XRPL account..." aria-label="Search users" />
                <ul id="chat-user-list" role="listbox" aria-label="Open chats"></ul>
            </div>
            <div class="chat-main">
                <div id="chat-header" role="heading" aria-level="2">Select a conversation</div>
                <div id="chat-messages" role="log" aria-live="polite"></div>
                <div id="typing-indicator" style="display: none; padding: 5px; color: #999;">...</div>
                <form id="chat-form" aria-label="Send message">
                    <div class="input-group">
                        <input type="text" id="chat-input" placeholder="Type your message..." autocomplete="off" aria-label="Message input" />
                        <button type="button" id="emoji-picker-btn" aria-label="Open emoji picker">😊</button>
                        <div id="emoji-picker" style="display: none;"></div>
                        <input type="hidden" name="csrf_token" value="<?php echo wp_create_nonce('chat_nonce'); ?>">
                        <button type="submit" aria-label="Send message">Send</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
}





// 🔧 Shortcode
add_shortcode('xrpl_trading_hub', 'xrpl_trading_hub_shortcode');
function xrpl_trading_hub_shortcode() {
    ob_start();
    include get_stylesheet_directory() . '/xrpl-nft-marketplace/frontend/trading-hub.php';
    return ob_get_clean();
}

// 🎯 Load assets only when the shortcode is present on the page
add_action('wp_enqueue_scripts', 'enqueue_xrpl_marketplace_assets');
function enqueue_xrpl_marketplace_assets() {
    if (!is_page()) return;
    $post = get_post();
    if (!$post || !has_shortcode($post->post_content, 'xrpl_trading_hub')) return;

    $xrpl_account = function_exists('imc_session_wallet') ? imc_session_wallet() : '';
    $has_xrpl_cookie = ($xrpl_account !== '');

    // ✅ Emoji picker (module)
    wp_enqueue_script(
        'emoji-picker',
        'https://unpkg.com/emoji-picker-element@^1.0.0',
        [],
        '1.0.0',
        true
    );

    // ✅ Optional chat (only if XRPL cookie present)
    if ($has_xrpl_cookie) {
        wp_enqueue_script(
            'sitewide-chat',
            get_stylesheet_directory_uri() . '/js/chat.js',
            ['jquery', 'emoji-picker'],
            '2.3.0',
            true
        );
        wp_localize_script('sitewide-chat', 'chatConfig', [
            'csrfToken' => wp_create_nonce('chat_nonce'),
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'xrplMarketplace' => [
                'nonce'        => wp_create_nonce('xrpl_marketplace_nonce'),
                'user_account' => $xrpl_account,
            ],
        ]);
    }

    // ✅ CSS
    wp_enqueue_style(
        'xrpl-trading-style',
        get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/frontend/trading-hub.css',
        [],
        '1.36'
    );

    // ✅ JS
    $deps = ['jquery'];
    if ($has_xrpl_cookie) $deps[] = 'sitewide-chat';

    wp_enqueue_script(
        'xrpl-trading-js',
        get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/frontend/trading.js',
        $deps,
        // CP-C3-A4d: bump this on every trading.js change or browsers and
        // LiteSpeed serve the cached copy - the A-4 holders loader shipped to
        // disk and reached nobody until this moved. All THREE sites must match.
        '1.79',
        true
    );
    
    // v86: Trustline checker utility (v692: filemtime cache-bust so redeploys aren't served stale)
    $imc_tc_fs = get_stylesheet_directory() . '/xrpl-nft-marketplace/frontend/trustline-checker.js';
    wp_enqueue_script(
        'imc-trustline-checker',
        get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/frontend/trustline-checker.js',
        [],
        file_exists($imc_tc_fs) ? filemtime($imc_tc_fs) : '1.0.1',
        true
    );

    // ✅ Localized config & endpoints for the frontend
    wp_localize_script('xrpl-trading-js', 'xrplMarketplace', [
        'ajax_url'     => admin_url('admin-ajax.php'),
        'nonce'        => wp_create_nonce('xrpl_marketplace_nonce'),
        'user_account' => $xrpl_account,
        'endpoints'    => [
            // Core proxy
            'xummProxy'        => home_url('/xumm-proxy.php'),

            // Backend handlers (theme paths)
            'offerHandler'     => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/offer-handler.php',
            'groupOffer'       => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/group-offer-handler.php',
            'tradeHandler'     => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/trade-handler.php',
            'watchlistHandler' => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/watchlist-handler.php',
            'collectionsHandler' => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/collections-handler.php',
            'filterHandler'    => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/filter-handler.php',
            'analyticsHandler' => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/analytics-handler.php',
            'moderationHandler'=> get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/moderation-handler.php',
            'taskHandler'      => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/task-handler.php',
            'mintHandler'      => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/mint-handler.php',
            'nftLoader'        => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/nft-loader-handler.php',
            'listingsHandler'  => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/listings-handler.php',
            'mintOnDemand'     => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/mint-on-demand-handler.php',
        ],
        'ui' => [
            'qrExpiresMinutes' => 5,
        ],
    ]);
}


// Enqueue trading hub assets on the dashboard page template
add_action('wp_enqueue_scripts', 'enqueue_trading_hub_dashboard_assets');

function enqueue_trading_hub_dashboard_assets() {
    // Check if the current page uses the 'Trading Hub Dashboard' template
    if (is_page_template('page-templates/trading-hub-dashboard.php')) {
        // Enqueue CSS (version 1.16 for cache bust)
        wp_enqueue_style('trading-hub-css', get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/frontend/trading-hub.css', array(), '1.36', 'all');
        
        // Enqueue JS (loads in footer for performance) - version 1.16 for cache bust
        wp_enqueue_script('trading-js', get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/frontend/trading.js', array('jquery'), '1.79', true);

        // Localize script with xrplMarketplace data (same as shortcode)
        wp_localize_script('trading-js', 'xrplMarketplace', [
            'ajax_url'     => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('xrpl_marketplace_nonce'),
            'user_account' => function_exists('imc_session_wallet') ? imc_session_wallet() : '',
            'endpoints'    => [
                'xummProxy'        => home_url('/xumm-proxy.php'),
                'offerHandler'     => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/offer-handler.php',
                'groupOffer'       => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/group-offer-handler.php',
                'tradeHandler'     => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/trade-handler.php',
                'watchlistHandler' => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/watchlist-handler.php',
                'filterHandler'    => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/filter-handler.php',
                'analyticsHandler' => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/analytics-handler.php',
                'moderationHandler'=> get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/moderation-handler.php',
                'taskHandler'      => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/task-handler.php',
                'mintHandler'      => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/mint-handler.php',
                'nftLoader'        => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/nft-loader-handler.php',
                'listingsHandler'  => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/listings-handler.php',
                'mintOnDemand'     => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/mint-on-demand-handler.php',
                'collectionsHandler' => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/collections-handler.php',
            ],
            'ui' => [
                'qrExpiresMinutes' => 5,
            ],
        ]);
    }
}





// Enqueue for NFT Collection template
add_action('wp_enqueue_scripts', 'enqueue_collections_assets');
function enqueue_collections_assets() {
    // Load assets for collections page - slug-based, param-based, or template
    $is_collections_page = get_query_var('collection_slug') 
        || is_page_template('page-templates/collections.php')
        || (is_page('collections') && (isset($_GET['issuer']) || empty($_GET)));
    
    if ($is_collections_page) {
        // use stylesheet dir consistently
        wp_enqueue_style(
            'xrpl-trading-style',
            get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/frontend/trading-hub.css',
            [],
            '1.36'
        );
        wp_enqueue_script(
            'xrpl-trading-js',
            get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/frontend/trading.js',
            ['jquery'],
            '1.79',
            true
        );
        wp_localize_script('xrpl-trading-js', 'xrplMarketplace', [
            'ajax_url'     => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('xrpl_marketplace_nonce'),
            'user_account' => function_exists('imc_session_wallet') ? imc_session_wallet() : '',
            'endpoints'    => [
                'xummProxy'          => home_url('/xumm-proxy.php'),
                'groupOffer'         => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/group-offer-handler.php',
                'offerHandler'       => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/offer-handler.php',
                // 🔧 unify the filename so it matches what trading.js expects
                'nftLoader'          => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/nft-loader-handler.php',
                'watchlistHandler'   => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/watchlist-handler.php',
                'taskHandler'        => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/task-handler.php',
                'filterHandler'      => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/filter-handler.php',
                'tradeHandler'       => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/trade-handler.php',
                'collectionsHandler' => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/collections-handler.php',
                'listingsHandler'    => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/listings-handler.php',
                'mintOnDemand'       => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/mint-on-demand-handler.php',
            ],
            'ui' => ['qrExpiresMinutes' => 5],
        ]);
    }
}


// ============================================================================
// MINT-ON-DEMAND ASSETS
// ============================================================================
add_action('wp_enqueue_scripts', 'enqueue_mint_on_demand_assets');
function enqueue_mint_on_demand_assets() {
    // Determine which pages need mint-on-demand assets
    $should_load = false;
    
    // Load on specific page templates
    $templates_to_check = [
        'page-templates/trading-hub.php',
        'page-templates/trading-hub-dashboard.php',
        'page-templates/collections.php',
        'page-templates/page-my-nfts.php'
    ];
    
    foreach ($templates_to_check as $template) {
        if (is_page_template($template)) {
            $should_load = true;
            break;
        }
    }
    
    // Also load on collections pages (slug-based or param-based)
    if (get_query_var('collection_slug') || is_page('collections')) {
        $should_load = true;
    }
    
    // Load on trading hub shortcode pages
    if (is_page()) {
        $post = get_post();
        if ($post && has_shortcode($post->post_content, 'xrpl_trading_hub')) {
            $should_load = true;
        }
    }
    
    if (!$should_load) return;
    
    $xrpl_account = function_exists('imc_session_wallet') ? imc_session_wallet() : '';
    
    // Enqueue mint-on-demand CSS (no hard dependency on trading-hub CSS handle — it varies by page)
    $fe_dir = get_stylesheet_directory() . '/xrpl-nft-marketplace/frontend/';
    $mod_css_ver = file_exists($fe_dir . 'mint-on-demand.css') ? filemtime($fe_dir . 'mint-on-demand.css') : '1.0.34';
    $mod_js_ver  = file_exists($fe_dir . 'mint-on-demand.js')  ? filemtime($fe_dir . 'mint-on-demand.js')  : '1.0.34';

    wp_enqueue_style(
        'mint-on-demand-css',
        get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/frontend/mint-on-demand.css',
        [],
        $mod_css_ver
    );
    
    // v692: Trustline checker utility — loaded here so imcTrustline (check + setTrustline) is
    // available wherever the card + mint popup render (collections / dashboard / my-nfts), not
    // just the [xrpl_trading_hub] shortcode page. filemtime = auto cache-bust on redeploy.
    $tc_js_ver = file_exists($fe_dir . 'trustline-checker.js') ? filemtime($fe_dir . 'trustline-checker.js') : '1.0.1';
    wp_enqueue_script(
        'imc-trustline-checker',
        get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/frontend/trustline-checker.js',
        [],
        $tc_js_ver,
        true
    );

    // Enqueue mint-on-demand JS (self-contained IIFE — only needs jQuery + the trustline checker)
    wp_enqueue_script(
        'mint-on-demand-js',
        get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/frontend/mint-on-demand.js',
        ['jquery', 'imc-trustline-checker'],
        $mod_js_ver,
        true
    );
    
    // Localize with config for mint-on-demand
    wp_localize_script('mint-on-demand-js', 'MOD_CONFIG', [
        'account' => $xrpl_account,
        'nonce' => wp_create_nonce('xrpl_marketplace_nonce'),
        'endpoints' => [
            'listings' => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/listings-handler.php',
            'mintOnDemand' => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/mint-on-demand-handler.php',
            'xummProxy' => home_url('/xumm-proxy.php')
        ]
    ]);
}


// NFT Loader Handler as AJAX action (replaces direct access to nft-loader-handler.php)
add_action('wp_ajax_nft_loader', 'nft_loader_handler');
add_action('wp_ajax_nopriv_nft_loader', 'nft_loader_handler');

function nft_loader_handler() {
    global $wpdb;
    
    $nonce = sanitize_text_field($_REQUEST['nonce'] ?? '');
    if (!wp_verify_nonce($nonce, 'xrpl_marketplace_nonce')) {
        status_header(403);
        wp_send_json(['success' => false, 'error' => 'Invalid nonce']);
    }

    try {
        // Parameters
        $page = max(1, intval($_REQUEST['page'] ?? 1));
        $limit = isset($_REQUEST['limit']) ? max(1, min(100, intval($_REQUEST['limit']))) : 20;
        $offset = isset($_REQUEST['offset']) ? intval($_REQUEST['offset']) : (($page - 1) * $limit);
        $sort = sanitize_text_field($_REQUEST['sort'] ?? 'recent');
        $account = sanitize_text_field($_REQUEST['account'] ?? '');
        $recent = isset($_REQUEST['recent']) && $_REQUEST['recent'] == '1';
        $force = isset($_REQUEST['force']) && $_REQUEST['force'] == 'true';
        
        // NEW: Handle issuer/taxon for collection pages
        $issuer = sanitize_text_field($_REQUEST['issuer'] ?? '');
        $taxon = isset($_REQUEST['taxon']) ? intval($_REQUEST['taxon']) : null;
        // v504 Phase A2: optional NFT-title filter (IMC-minted collections only).
        $nft_title = sanitize_text_field($_REQUEST['title'] ?? '');
        // v522: "My NFTs" owned-only filter — validated XRPL wallet (else ignored → normal path)
        $nft_owner = sanitize_text_field($_REQUEST['owner'] ?? '');
        if ($nft_owner !== '' && !preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $nft_owner)) { $nft_owner = ''; }
        
        // VPS Metadata API
        // R-C3a: the vps_api assignment removed - it was assigned here and NEVER READ across
        // all 957 lines of this function. It pointed at the LEGACY store.
        // D40 (09 Sep 2026): that migration is now DONE.
        // imu_fetch_metadata_batch() POSTs to ?action=batch on the store.
        // NOTHING in this theme calls /api/ any more - the legacy service and
        // its legacy database are unreferenced.
        
        // Featured collections map
        $collections_map = [
            'guardians'   => ['issuer' => 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR', 'taxon' => 0, 'name' => 'Guardians'],
            'frequencies' => ['issuer' => 'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga', 'taxon' => 717825, 'name' => 'Protectors of the Frequencies'],
            'ledger'      => ['issuer' => 'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt', 'taxon' => 1056369418, 'name' => 'Protectors of the Ledger'],
            'lasvegas'    => ['issuer' => 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR', 'taxon' => 777, 'name' => 'Las Vegas'],
            'firepit'     => ['issuer' => 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR', 'taxon' => 666, 'name' => 'Firepit']
        ];
        
        $all_nfts = [];
        $total = 0;

        // ============================================================
        // CASE 1: Specific collection (issuer + taxon provided)
        // This is what collection pages need!
        // ============================================================
        if ($issuer && $taxon !== null) {
            error_log("NFT Loader: Loading collection issuer=$issuer taxon=$taxon");

            // ── v504 Phase A2: TITLE FILTER — DB-authoritative branch ─────────
            // When a title is requested AND this collection has matching platform-
            // minted rows, the grid is sourced and filtered from the SAME
            // authoritative imc_purchases JOIN imc_listings used by the DB merge
            // below, then paginated in PHP. This is the only way to filter
            // correctly across VPS-level pagination. With no title — or no rows
            // (external collections / unknown title) — execution falls through to
            // the untouched normal path below: byte-identical behaviour.
            // NOTE: $cache_key is deliberately NOT set in this branch, so the
            // final response transient (guarded by !empty($cache_key)) is skipped.
            $a2_title_rows = [];
            if ($nft_title !== '') {
                $a2_p = $wpdb->prefix . 'imc_purchases';
                $a2_l = $wpdb->prefix . 'imc_listings';
                $a2_t = $wpdb->prefix . 'imc_listing_tiers';
                if ($wpdb->get_var("SHOW TABLES LIKE '$a2_p'") === $a2_p) {
                    $a2_title_rows = $wpdb->get_results($wpdb->prepare(
                        "SELECT p.nftoken_id, p.edition_number, p.buyer_account, p.tier_id,
                                l.nft_name, l.cover_ipfs AS listing_cover, l.nft_type,
                                l.artist_account, l.collection_taxon, l.transfer_fee,
                                t.cover_ipfs AS tier_cover
                         FROM $a2_p p
                         JOIN $a2_l l ON p.listing_id = l.id
                         LEFT JOIN $a2_t t ON p.tier_id = t.id
                         WHERE l.artist_account = %s
                           AND l.collection_taxon = %d
                           AND l.nft_name = %s
                           AND p.mint_status IN ('minted', 'claimed')
                           AND p.nftoken_id IS NOT NULL
                           AND p.nftoken_id != ''
                         ORDER BY p.edition_number ASC",
                        $issuer, $taxon, $nft_title
                    ), ARRAY_A) ?: [];
                }
            } elseif ($nft_owner !== '') {
                // v522: "My NFTs" — same authoritative purchases JOIN listings, filtered by the
                // connected buyer wallet. Additive parallel branch; the title query above is
                // byte-identical. Reuses the shared build/paginate block below. No cache (like A2).
                $a2_p = $wpdb->prefix . 'imc_purchases';
                $a2_l = $wpdb->prefix . 'imc_listings';
                $a2_t = $wpdb->prefix . 'imc_listing_tiers';
                if ($wpdb->get_var("SHOW TABLES LIKE '$a2_p'") === $a2_p) {
                    $a2_title_rows = $wpdb->get_results($wpdb->prepare(
                        "SELECT p.nftoken_id, p.edition_number, p.buyer_account, p.tier_id,
                                l.nft_name, l.cover_ipfs AS listing_cover, l.nft_type,
                                l.artist_account, l.collection_taxon, l.transfer_fee,
                                t.cover_ipfs AS tier_cover
                         FROM $a2_p p
                         JOIN $a2_l l ON p.listing_id = l.id
                         LEFT JOIN $a2_t t ON p.tier_id = t.id
                         WHERE l.artist_account = %s
                           AND l.collection_taxon = %d
                           AND p.buyer_account = %s
                           AND p.mint_status IN ('minted', 'claimed')
                           AND p.nftoken_id IS NOT NULL
                           AND p.nftoken_id != ''
                         ORDER BY p.edition_number ASC",
                        $issuer, $taxon, $nft_owner
                    ), ARRAY_A) ?: [];
                }
            }

            if (!empty($a2_title_rows)) {
                // Build the FULL filtered set in the exact shape the DB merge
                // constructs (same cover, name, type mapping), then paginate.
                $a2_built = [];
                foreach ($a2_title_rows as $mn) {
                    $cover = $mn['tier_cover'] ?? $mn['listing_cover'] ?? '';
                    $img = '';
                    if ($cover) {
                        $cid = preg_replace('#^ipfs://#', '', $cover);
                        $img = 'https://metadata.imcollectibles.io/img.php?url=' . urlencode('ipfs://' . $cid);
                    }
                    $ed = $mn['edition_number'] ? ' #' . $mn['edition_number'] : '';
                    $a2_built[] = [
                        'NFTokenID'    => $mn['nftoken_id'],
                        'Issuer'       => $issuer,
                        'NFTokenTaxon' => $taxon,
                        'owner'        => $mn['buyer_account'] ?? '',
                        'name'         => ($mn['nft_name'] ?? 'NFT') . $ed,
                        'image'        => $img,
                        'image_url'    => $img,
                        'content_type' => ($mn['nft_type'] === 'musicvideo') ? 'video' : 'audio',
                        'royalty_percent' => round(intval($mn['transfer_fee'] ?? 0) / 1000, 2),
                        'creator_wallet'  => $mn['artist_account'] ?? $issuer
                    ];
                }
                $total = count($a2_built);
                $all_nfts = array_slice($a2_built, $offset, $limit);
                error_log("NFT Loader: A2 title filter matched $total NFTs (DB-authoritative path)");
            } else {
            // ── end Phase A2 branch — everything below is the untouched normal path ──
            
            // Cache key for this collection request
            $cache_key = 'nft_loader_collection_' . md5($issuer . '_' . $taxon . '_' . $offset . '_' . $limit . '_' . $sort);
            
            // v59 FIX: Check cache but DON'T return early - we still need to merge local DB
            $cached_vps_data = false;
            if (!$force) {
                $cached_vps_data = get_transient($cache_key . '_vps');
            }
            
            if ($cached_vps_data !== false) {
                // Use cached VPS data as starting point
                $all_nfts = $cached_vps_data['nfts'] ?? [];
                $total = $cached_vps_data['total'] ?? count($all_nfts);
                error_log("NFT Loader: Got " . count($all_nfts) . " NFTs from VPS transient cache");
            } else {
                // Try VPS metadata first (FREE!)
                $vps_result = imu_fetch_collection_from_vps($issuer, $taxon, $limit, $offset);
                
                if (!empty($vps_result['nfts'])) {
                    $all_nfts = $vps_result['nfts'];
                    $total = $vps_result['total'] ?? count($all_nfts);
                    error_log("NFT Loader: Got " . count($all_nfts) . " NFTs from VPS API");
                    
                    // Cache VPS data for 60 seconds
                    set_transient($cache_key . '_vps', ['nfts' => $all_nfts, 'total' => $total], 60);
                } else {
                    // P1F (Aug 2026): the v208 Bithomp fallback here sent assets=true -
                    // rejected on the free tier - so it was a guaranteed 12s failure on
                    // every store-miss since the plan change. Deleted; the Clio fallback
                    // below is the real empty-store path, and 1g's cascade is the fix
                    // for genuinely-unindexed collections.
                    // Fallback 2: XRPL Clio via xumm-proxy (raw IDs, no metadata)
                    if (empty($all_nfts)) {
                        error_log("NFT Loader: VPS empty, falling back to XRPL Clio");
                        $proxy_url = home_url('/xumm-proxy.php');
                        $url = $proxy_url . "?issuer=" . urlencode($issuer) . "&taxon=$taxon&limit=$limit&offset=$offset&t=" . time();
                        
                        $response = wp_remote_get($url, ['timeout' => 15]);
                        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                            $body = json_decode(wp_remote_retrieve_body($response), true);
                            if (!empty($body['result']['nfts'])) {
                                $all_nfts = $body['result']['nfts'];
                                $total = $body['result']['total'] ?? count($all_nfts);
                            }
                        }
                    }
                }
            }
            

            // v212: Bithomp ENRICHMENT via INDIVIDUAL NFT lookups
            // Uses /v2/nft/{id} — works for ANY NFT regardless of collection indexing
            // Scales max_calls with page size, 24hr cache per NFT, progressive enrichment
            if (!empty($all_nfts) && $issuer && $taxon !== null) {
                $broken_ids = [];
                foreach ($all_nfts as $idx_n => $n) {
                    $n_name = $n["name"] ?? "";
                    $n_img = $n["image"] ?? $n["image_url"] ?? "";
                    if (empty($n_name) || $n_name === "Unnamed NFT" || empty($n_img) || strpos($n_img, "ipfs://") === 0) {
                        $nid = $n["NFTokenID"] ?? $n["nftokenID"] ?? "";
                        if ($nid) $broken_ids[$nid] = $idx_n;
                    }
                }
                
                // v212: Always enrich broken NFTs — removed 30% threshold that blocked
                // small batches (e.g. 5 broken out of 100 = 5% would never get enriched)
                if (count($broken_ids) > 0) {
                    $bithomp_key = defined("BITHOMP_API_KEY") ? BITHOMP_API_KEY : "";
                    if ($bithomp_key) {
                        $fixed = 0;
                        $api_calls = 0;
                        // v212: Scale with page size — first page (25) gets 25, subsequent (100) get 50
                        // Cached lookups are free so only fresh API calls count against limit
                        $max_calls = min($limit, 50);
                        
                        foreach ($broken_ids as $nid => $arr_idx) {
                            // Check individual NFT cache first (24hr TTL — metadata rarely changes)
                            $nft_cache_key = "bh_nft_" . substr(md5($nid), 0, 12);
                            $cached_meta = get_transient($nft_cache_key);
                            
                            if ($cached_meta !== false) {
                                // Use cached Bithomp data — does NOT count against API limit
                                if (!empty($cached_meta["name"])) $all_nfts[$arr_idx]["name"] = $cached_meta["name"];
                                if (!empty($cached_meta["image"])) {
                                    $all_nfts[$arr_idx]["image"] = $cached_meta["image"];
                                    $all_nfts[$arr_idx]["image_url"] = $cached_meta["image"];
                                }
                                $fixed++;
                                continue;
                            }
                            
                            if ($api_calls >= $max_calls) continue; // Hit rate limit, rest will be enriched on next page load
                            
                            // P1d-2 (Aug 2026): individual lookup now hits OUR store with the
                            // bounded fast miss-path instead of Bithomp. The old call sent
                            // assets=true - rejected on the free tier - so it was a guaranteed
                            // 8s miss per NFT. fast write-back means each miss self-heals.
                            $bh_url = "https://metadata.imcollectibles.io/?action=get&id=" . rawurlencode($nid) . "&fetch=fast";
                            $bh_resp = wp_remote_get($bh_url, [
                                "timeout" => 8,
                                "headers" => ["Accept" => "application/json"]
                            ]);
                            $api_calls++;
                            
                            $vf_nft = null;
                            if (!is_wp_error($bh_resp) && wp_remote_retrieve_response_code($bh_resp) === 200) {
                                $vf_j = json_decode(wp_remote_retrieve_body($bh_resp), true);
                                if (!empty($vf_j["success"]) && !empty($vf_j["nft"])
                                    && (empty($vf_j["nft"]["decode_status"]) || $vf_j["nft"]["decode_status"] === "success")) {
                                    $vf_nft = $vf_j["nft"];
                                }
                            }
                            if ($vf_nft) {
                                $bh_meta = $vf_nft["metadata"] ?? [];
                                // img.php proxy first: resolves internally with fallbacks, so
                                // rotted stored URLs (pre-policy rows) can never render broken.
                                $bh_img = $vf_nft["image_proxy"] ?? $vf_nft["image_resolved"] ?? $vf_nft["image_url"] ?? ($bh_meta["image"] ?? "");
                                if (strpos($bh_img, "ipfs://") === 0) {
                                    $bh_img = "https://ipfs.io/ipfs/" . substr($bh_img, 7);
                                } elseif (preg_match("/^(Qm|bafy)/", $bh_img)) {
                                    $bh_img = "https://ipfs.io/ipfs/" . $bh_img;
                                }
                                
                                $enriched_data = [
                                    "name" => $bh_meta["name"] ?? "",
                                    "image" => $bh_img,
                                    "description" => $bh_meta["description"] ?? ""
                                ];
                                
                                // v212: Cache individual result for 24 hours (metadata rarely changes)
                                set_transient($nft_cache_key, $enriched_data, 86400);
                                
                                if (!empty($enriched_data["name"])) $all_nfts[$arr_idx]["name"] = $enriched_data["name"];
                                if (!empty($enriched_data["image"])) {
                                    $all_nfts[$arr_idx]["image"] = $enriched_data["image"];
                                    $all_nfts[$arr_idx]["image_url"] = $enriched_data["image"];
                                }
                                $fixed++;
                            } else {
                                // Cache failures for 1 hour (avoid re-hitting dead NFTs, retry after 1hr)
                                set_transient($nft_cache_key, ["name" => "", "image" => "", "description" => ""], 3600);
                            }
                        }
                        
                        if ($fixed > 0 || $api_calls > 0) {
                            error_log("NFT Loader: Enriched $fixed NFTs via Bithomp individual lookups ($api_calls API calls, max $max_calls)");
                            set_transient($cache_key . "_vps", ["nfts" => $all_nfts, "total" => $total], 120);
                            
                            // v212: Cache first good image as collection cover
                            // This ensures collection pages show a real NFT image, not fallback SVG
                            $col_cover_key = 'collection_image_' . md5($issuer . '_' . $taxon);
                            $existing_cover = get_transient($col_cover_key);
                            if (empty($existing_cover) || strpos($existing_cover, 'fallback') !== false) {
                                foreach ($all_nfts as $cn) {
                                    $cn_img = $cn["image"] ?? $cn["image_url"] ?? "";
                                    if (!empty($cn_img) && strpos($cn_img, "fallback") === false 
                                        && strpos($cn_img, "ipfs://") !== 0) {
                                        set_transient($col_cover_key, $cn_img, 86400);
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
            }
            // ── LOCAL DB MERGE: Platform-minted NFTs (may not be in VPS/XRPL yet) ──
            // v59 FIX: This ALWAYS runs now, even when VPS data was cached
            // v307 FIX: Track VPS result count BEFORE merge. If VPS returned 0 for
            // an offset > 0 request, we're past the real end of the collection.
            // In that case the DB merge must only enrich (no new additions), otherwise
            // ALL platform NFTs get re-appended on the scroll-trigger page request,
            // causing every NFT to appear twice in the grid.
            $vps_nft_count = count($all_nfts);
            $imc_p = $wpdb->prefix . 'imc_purchases';
            $imc_l = $wpdb->prefix . 'imc_listings';
            $imc_t = $wpdb->prefix . 'imc_listing_tiers';
            $has_imc = ($wpdb->get_var("SHOW TABLES LIKE '$imc_p'") === $imc_p);
            
            if ($has_imc) {
                // v59 FIX: Added LEFT JOIN to tiers table for tier-specific covers
                $mod_nfts = $wpdb->get_results($wpdb->prepare(
                    "SELECT p.nftoken_id, p.edition_number, p.buyer_account, p.tier_id,
                            l.nft_name, l.cover_ipfs AS listing_cover, l.nft_type,
                            l.artist_account, l.collection_taxon, l.transfer_fee,
                            t.cover_ipfs AS tier_cover
                     FROM $imc_p p
                     JOIN $imc_l l ON p.listing_id = l.id
                     LEFT JOIN $imc_t t ON p.tier_id = t.id
                     WHERE l.artist_account = %s 
                       AND l.collection_taxon = %d
                       AND p.mint_status IN ('minted', 'claimed')
                       AND p.nftoken_id IS NOT NULL
                       AND p.nftoken_id != ''
                     ORDER BY p.edition_number ASC",
                    $issuer, $taxon
                ), ARRAY_A);
                
                if (!empty($mod_nfts)) {
                    // Build index mapping NFT IDs to their position in $all_nfts
                    $existing_idx = [];
                    foreach ($all_nfts as $idx => $n) {
                        $eid = strtoupper($n['NFTokenID'] ?? $n['nftokenID'] ?? '');
                        if ($eid) $existing_idx[$eid] = $idx;
                    }
                    
                    $added = 0;
                    $enriched = 0;
                    foreach ($mod_nfts as $mn) {
                        $nid = $mn['nftoken_id'];
                        
                        // v130 FIX: Build MOD image URL (Pinata) for this NFT
                        $cover = $mn['tier_cover'] ?? $mn['listing_cover'] ?? '';
                        $img = '';
                        if ($cover) {
                            $cid = preg_replace('#^ipfs://#', '', $cover);
                            $img = 'https://metadata.imcollectibles.io/img.php?url=' . urlencode('ipfs://' . $cid);
                        }
                        
                        $ed = $mn['edition_number'] ? ' #' . $mn['edition_number'] : '';
                        
                        // v309 FIX: Always overwrite VPS image with our authoritative DB cover CID.
                        // The NFT single page does this (patches VPS data with DB cover_ipfs) and
                        // never has image failures. The grid was only patching when VPS had no
                        // "good" image — but VPS stores cloudflare-ipfs.com / ipfs.io URLs which
                        // look valid (start with http) but resolve unreliably. Since we minted and
                        // pinned this NFT ourselves, our cover_ipfs from imc_listings/tiers is the
                        // authoritative source — always use it, same as the single-NFT page does.
                        if (isset($existing_idx[strtoupper($nid)])) {
                            $idx = $existing_idx[strtoupper($nid)];
                            
                            // Always overwrite image with our direct Pinata URL (we pinned it)
                            if ($img) {
                                $all_nfts[$idx]['image'] = $img;
                                $all_nfts[$idx]['image_url'] = $img;
                                $enriched++;
                            }
                            // Enrich name with edition number if missing
                            if ($ed && empty($all_nfts[$idx]['name'])) {
                                $all_nfts[$idx]['name'] = ($mn['nft_name'] ?? 'NFT') . $ed;
                            }
                            // Always set content_type from DB — VPS defaults to 'image' for music NFTs
                            $all_nfts[$idx]['content_type'] = ($mn['nft_type'] === 'musicvideo') ? 'video' : 'audio';
                            continue;
                        }
                        
                        // NFT not in VPS at all — add from MOD data
                        // v307 FIX: Only add new entries for page 1 (offset=0) or when
                        // VPS actually returned results for this offset. If VPS returned
                        // nothing at offset > 0 we are past the end; the DB NFTs were
                        // already served on earlier pages via the enrich path above.
                        if ($vps_nft_count === 0 && $offset > 0) {
                            continue; // past end — enrich-only for this page
                        }
                        $all_nfts[] = [
                            'NFTokenID'    => $nid,
                            'Issuer'       => $issuer,
                            'NFTokenTaxon' => $taxon,
                            'owner'        => $mn['buyer_account'] ?? '',
                            'name'         => ($mn['nft_name'] ?? 'NFT') . $ed,
                            'image'        => $img,
                            'image_url'    => $img,
                            'content_type' => ($mn['nft_type'] === 'musicvideo') ? 'video' : 'audio',
                            'royalty_percent' => round(intval($mn['transfer_fee'] ?? 0) / 1000, 2),
                            'creator_wallet'  => $mn['artist_account'] ?? $issuer
                        ];
                        $added++;
                    }
                    
                    if ($added > 0 || $enriched > 0) {
                        $total = count($all_nfts);
                        error_log("NFT Loader: Merged $added new + enriched $enriched existing MOD NFTs (total: $total)");
                    }
                }
            }
            } // v504 Phase A2: end title-filter else (normal path above untouched)
        }
        
        // ============================================================
        // CASE 2: User's NFTs (account provided)
        // ============================================================
        elseif ($account) {
            error_log("NFT Loader: Loading NFTs for account=$account");
            
            $proxy_url = home_url('/xumm-proxy.php');
            $url = $proxy_url . "?account=" . urlencode($account) . "&t=" . time();
            
            $response = wp_remote_get($url, ['timeout' => 15]);
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                if (!empty($body['result']['account_nfts'])) {
                    // Filter to only our collections
                    $filtered = [];
                    foreach ($body['result']['account_nfts'] as $nft) {
                        $nft_issuer = $nft['Issuer'] ?? '';
                        $nft_taxon = isset($nft['NFTokenTaxon']) ? (int)$nft['NFTokenTaxon'] : -1;
                        
                        foreach ($collections_map as $col) {
                            if ($nft_issuer === $col['issuer'] && $nft_taxon === $col['taxon']) {
                                $filtered[] = $nft;
                                break;
                            }
                        }
                    }
                    $total = count($filtered);
                    $all_nfts = array_slice($filtered, $offset, $limit);
                    
                    // Fire-and-forget trigger_scan for any collection in this wallet
                    // Catches listings on collections not yet in VPS nft_metadata
                    $wt_seen = [];
                    foreach ($filtered as $wt_nft) {
                        $wt_issuer = $wt_nft['Issuer'] ?? ($wt_nft['issuer'] ?? '');
                        $wt_taxon  = isset($wt_nft['NFTokenTaxon']) ? (int)$wt_nft['NFTokenTaxon']
                                   : (isset($wt_nft['taxon']) ? (int)$wt_nft['taxon'] : -1);
                        if (!$wt_issuer || $wt_taxon < 0) continue;
                        $wt_key = $wt_issuer . ':' . $wt_taxon;
                        if (isset($wt_seen[$wt_key])) continue;
                        $wt_seen[$wt_key] = true;
                        $wt_tc_key = 'imc_triggered_scan_' . md5($wt_issuer . '_' . (string)$wt_taxon);
                        if (!get_transient($wt_tc_key)) {
                            $wt_secret = defined('VPS_SHARED_SECRET') ? VPS_SHARED_SECRET : '';
                            $wt_url = 'https://metadata.imcollectibles.io/'
                                    . '?action=trigger_scan'
                                    . '&issuer=' . rawurlencode($wt_issuer)
                                    . '&taxon='  . $wt_taxon
                                    . '&secret=' . rawurlencode($wt_secret);
                            wp_remote_get($wt_url, array('timeout' => 1, 'blocking' => false, 'sslverify' => true));
                            set_transient($wt_tc_key, 1, 900);
                        }
                    }
                }
            }
        }
        
        // ============================================================
        // CASE 3: Recent drops or all featured NFTs
        // ============================================================
        else {
            error_log("NFT Loader: Loading recent/all NFTs");
            
            $cache_key = 'nft_loader_all_' . md5($page . '_' . $limit . '_' . $sort . ($recent ? '_recent' : ''));
            
            // NOTE: We do NOT early-return from the full formatted-response cache here.
            // Offer injection must run on every request to stay current (offers go active/cancelled
            // at any time). The _vps sub-cache below still saves external API calls.
            $cached_all_vps = false;
            if (!$force) {
                $cached_all_vps = get_transient($cache_key . '_vps');
            }
            if ($cached_all_vps !== false) {
                $all_nfts = $cached_all_vps['nfts'] ?? [];
                $total    = $cached_all_vps['total'] ?? count($all_nfts);
            } else {
                // Fetch from each collection
                foreach ($collections_map as $key => $col) {
                    $col_nfts = imu_fetch_collection_from_vps($col['issuer'], $col['taxon'], 50, 0);
                    if (!empty($col_nfts['nfts'])) {
                        $all_nfts = array_merge($all_nfts, $col_nfts['nfts']);
                    }
                }
                // Sort
                if ($sort === 'random') {
                    shuffle($all_nfts);
                }
                $total    = count($all_nfts);
                $all_nfts = array_slice($all_nfts, $offset, $limit);
                set_transient($cache_key . '_vps', ['nfts' => $all_nfts, 'total' => $total], 120);
            }
        }

        // ============================================================
        // TRIGGER_SCAN: Fire-and-forget to VPS for any collection not
        // recently scanned. Runs in background; this call returns instantly.
        // ============================================================
        if (!empty($issuer) && $taxon !== null) {
            $ts_cache_key = 'imc_triggered_scan_' . md5($issuer . '_' . (string)$taxon);
            if (!get_transient($ts_cache_key)) {
                $vps_secret  = defined('VPS_SHARED_SECRET') ? VPS_SHARED_SECRET : '';
                $ts_url      = 'https://metadata.imcollectibles.io/'
                             . '?action=trigger_scan'
                             . '&issuer=' . rawurlencode($issuer)
                             . '&taxon='  . (int)$taxon
                             . '&secret=' . rawurlencode($vps_secret);
                // Fire and forget — don't wait for response
                wp_remote_get($ts_url, array(
                    'timeout'   => 1,   // Instant timeout — just fire the request
                    'blocking'  => false,
                    'sslverify' => true,
                ));
                // Don't re-trigger for 15 minutes
                set_transient($ts_cache_key, 1, 900);
                error_log('NFT Loader: Triggered background offer scan for ' . $issuer . ':' . $taxon);
            }
        }

        // ============================================================
        // COLLECTION LISTING PRESCAN  (v268)
        //
        // Single VPS call replaces multi-step list_ids + sell_offers chain.
        // VPS collection_listings endpoint:
        //   Step 1: DB join (nft_offers ∩ nft_metadata) — all cached listings,
        //           any count, in one SQL query. Sub-millisecond.
        //   Step 2: curl_multi XRPL nft_sell_offers — parallel check for up to
        //           100 uncached indexed NFTs (self-healing backfill).
        //   Covers all indexed NFTs (up to 5000) with no WordPress-side chunking.
        //
        // WordPress cache: 300s (5 min) per collection.
        // Only runs in CASE 1 (collection pages with $issuer + $taxon set).
        // ============================================================
        $prescan_map = array();
        if (!empty($issuer) && $taxon !== null) {
            $ps_cache_key = 'imc_prescan_' . md5($issuer . '_' . (string)$taxon);
            $ps_cached    = get_transient($ps_cache_key);
            if (is_array($ps_cached)) {
                $prescan_map = $ps_cached;
            } else {
                // Single call — VPS does all the heavy lifting internally
                $cl_url  = 'https://metadata.imcollectibles.io/'
                         . '?action=collection_listings'
                         . '&issuer=' . rawurlencode($issuer)
                         . '&taxon='  . (int)$taxon;
                $cl_resp = wp_remote_get($cl_url, array('timeout' => 5, 'sslverify' => true));
                if (!is_wp_error($cl_resp) && wp_remote_retrieve_response_code($cl_resp) === 200) {
                    $cl_body = json_decode(wp_remote_retrieve_body($cl_resp), true);
                    if (!empty($cl_body['success']) && isset($cl_body['listings'])) {
                        foreach ($cl_body['listings'] as $ps_nft_id => $ps_listing) {
                            $ps_key = strtoupper($ps_nft_id);
                            if (!empty($ps_listing['currency']) && $ps_listing['currency'] !== 'XRP') {
                                $ps_amt = (float)(isset($ps_listing['price_value']) ? $ps_listing['price_value'] : 0);
                                $ps_cur = $ps_listing['currency'];
                            } else {
                                $ps_amt = (float)(isset($ps_listing['price_xrp']) ? $ps_listing['price_xrp'] : 0);
                                $ps_cur = 'XRP';
                            }
                            if ($ps_amt > 0 && !empty($ps_listing['offer_id'])) {
                                $prescan_map[$ps_key] = array(
                                    'offer_id' => $ps_listing['offer_id'],
                                    'amount'   => $ps_amt,
                                    'currency' => $ps_cur,
                                );
                            }
                        }
                    }
                    $cl_indexed = isset($cl_body['indexed_db']) ? $cl_body['indexed_db'] : (isset($cl_body['indexed']) ? $cl_body['indexed'] : 0);
                    $cl_scan_status = isset($cl_body['scan_status']) ? $cl_body['scan_status'] : 'unknown';
                    error_log('NFT Loader: Prescan (collection_listings) found '
                        . count($prescan_map) . ' listings (DB cache). '
                        . $cl_indexed . ' indexed in nft_metadata. '
                        . 'Background scan: ' . $cl_scan_status);
                }
                set_transient($ps_cache_key, $prescan_map, 90); // Short TTL: background scan fills DB within 60-90s
            }

            // v332: Augment prescan_map with IMC-DB listings (wp_xumm_offers) for on-page NFTs.
            // VPS collection_listings only knows about external-marketplace offers tracked by the
            // VPS stream. IMC-native offers (created through IMC) live in wp_xumm_offers and are
            // missed by the VPS prescan. We query them here so the pre-format sort can promote
            // IMC-listed NFTs alongside VPS-known listings.
            global $wpdb;
            $ps_offers_table = $wpdb->prefix . 'xumm_offers';
            if (!empty($all_nfts) && $wpdb->get_var("SHOW TABLES LIKE '$ps_offers_table'") === $ps_offers_table) {
                $ps_page_nft_ids = [];
                foreach ($all_nfts as $ps_n) {
                    $ps_nid = strtoupper(
                        isset($ps_n['NFTokenID'])    ? $ps_n['NFTokenID']
                        : (isset($ps_n['nftokenID']) ? $ps_n['nftokenID']
                        : (isset($ps_n['nft_token_id']) ? $ps_n['nft_token_id'] : ''))
                    );
                    if ($ps_nid) $ps_page_nft_ids[] = $ps_nid;
                }
                if (!empty($ps_page_nft_ids)) {
                    $ps_imc_ph   = implode(',', array_fill(0, count($ps_page_nft_ids), '%s'));
                    // v427: Blacklist filter — hide sell offers from blocked wallets
                    $ps_bl_table = $wpdb->prefix . 'xumm_blacklist';
                    $ps_bl_clause = '';
                    if ($wpdb->get_var("SHOW TABLES LIKE '$ps_bl_table'") === $ps_bl_table) {
                        $ps_bl_clause = "AND offerer_account NOT IN (SELECT wallet_address FROM $ps_bl_table WHERE is_active = 1)";
                    }
                    $ps_imc_rows = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT target_nft_id, offer_id, amount, currency
                               FROM $ps_offers_table
                              WHERE offer_type = 'sell'
                                AND status     = 'active'
                                AND offer_id  != ''
                                AND UPPER(target_nft_id) IN ($ps_imc_ph)
                                $ps_bl_clause
                              ORDER BY created_at DESC",
                            ...$ps_page_nft_ids
                        ), ARRAY_A
                    ) ?: [];
                    $ps_imc_added = 0;
                    foreach ($ps_imc_rows as $ps_imc_row) {
                        $ps_imc_key = strtoupper($ps_imc_row['target_nft_id']);
                        if (!isset($prescan_map[$ps_imc_key]) && (float)$ps_imc_row['amount'] > 0) {
                            $prescan_map[$ps_imc_key] = [
                                'offer_id' => $ps_imc_row['offer_id'],
                                'amount'   => (float)$ps_imc_row['amount'],
                                'currency' => $ps_imc_row['currency'] ?: 'XRP',
                            ];
                            $ps_imc_added++;
                        }
                    }
                    if ($ps_imc_added > 0) {
                        error_log('NFT Loader: Augmented prescan_map with ' . $ps_imc_added . ' IMC-DB listings for on-page NFTs');
                    }
                }
            }
            // Surface listed NFTs not on current page to front of grid
            if (!empty($prescan_map)) {
                $ps_page_ids = array();
                foreach ($all_nfts as $ps_n) {
                    $ps_nid = strtoupper(
                        isset($ps_n['NFTokenID'])    ? $ps_n['NFTokenID']
                        : (isset($ps_n['nftokenID']) ? $ps_n['nftokenID']
                        : (isset($ps_n['nft_token_id']) ? $ps_n['nft_token_id'] : ''))
                    );
                    if ($ps_nid) $ps_page_ids[$ps_nid] = true;
                }
                $ps_off_page = array();
                foreach (array_keys($prescan_map) as $ps_lid) {
                    if (!isset($ps_page_ids[$ps_lid])) $ps_off_page[] = $ps_lid;
                }
                if (!empty($ps_off_page) && $nft_title === '') { // v504 Phase A2: never surface non-matching NFTs into a title-filtered view
                    $ps_missing_meta = imu_fetch_metadata_batch($ps_off_page);
                    foreach ($ps_off_page as $ps_lid) {
                        $ps_ml = isset($ps_missing_meta[$ps_lid]) ? $ps_missing_meta[$ps_lid] : array();
                        array_unshift($all_nfts, array(
                            'NFTokenID'    => $ps_lid,
                            'Issuer'       => $issuer,
                            'NFTokenTaxon' => $taxon,
                            'owner'        => '',
                            'name'         => isset($ps_ml['name'])         ? $ps_ml['name']         : '',
                            'image'        => isset($ps_ml['image'])        ? $ps_ml['image']        : '',
                            'image_url'    => isset($ps_ml['image'])        ? $ps_ml['image']        : '',
                            'content_type' => isset($ps_ml['content_type']) ? $ps_ml['content_type'] : 'image',
                        ));
                    }
                    error_log('NFT Loader: Surfaced ' . count($ps_off_page)
                        . ' listed NFTs from outside current page to front');
                }
                // Sort: listed NFTs always appear first
                usort($all_nfts, function ($ps_a, $ps_b) use ($prescan_map) {
                    $id_a = strtoupper(
                        isset($ps_a['NFTokenID'])    ? $ps_a['NFTokenID']
                        : (isset($ps_a['nftokenID']) ? $ps_a['nftokenID']
                        : (isset($ps_a['nft_token_id']) ? $ps_a['nft_token_id'] : ''))
                    );
                    $id_b = strtoupper(
                        isset($ps_b['NFTokenID'])    ? $ps_b['NFTokenID']
                        : (isset($ps_b['nftokenID']) ? $ps_b['nftokenID']
                        : (isset($ps_b['nft_token_id']) ? $ps_b['nft_token_id'] : ''))
                    );
                    $la = isset($prescan_map[$id_a]);
                    $lb = isset($prescan_map[$id_b]);
                    if ($la && !$lb) return -1;
                    if (!$la && $lb) return 1;
                    return 0;
                });
            }
        }


                // ============================================================
        // Format NFTs for frontend
        // ============================================================
        $formatted_nfts = [];
        
        // Get metadata from VPS in batch — ONLY if NFTs don't already have metadata
        // (VPS nfts_by_issuer already returns name/image, so this is only needed for XRPL-direct data)
        $first_nft = $all_nfts[0] ?? [];
        $has_metadata = !empty($first_nft['name']) || !empty($first_nft['image']) || !empty($first_nft['image_url']);
        
        $metadata_batch = [];
        if (!$has_metadata) {
            $nft_ids = array_column($all_nfts, 'NFTokenID');
            if (empty($nft_ids)) {
                $nft_ids = array_column($all_nfts, 'nftokenID');
            }
            if (!empty($nft_ids)) {
                $metadata_batch = imu_fetch_metadata_batch($nft_ids);
            }
        }
        
        foreach ($all_nfts as $nft) {
            $id = $nft['NFTokenID'] ?? $nft['nftokenID'] ?? $nft['nft_token_id'] ?? '';
            if (!$id) continue;
            
            $meta = $metadata_batch[$id] ?? [];
            
            $formatted_nfts[] = [
                'nftokenID' => $id,
                'issuer' => $nft['Issuer'] ?? $nft['issuer'] ?? '',
                'taxon' => (int)($nft['NFTokenTaxon'] ?? $nft['taxon'] ?? 0),
                'owner' => $nft['owner'] ?? $nft['Owner'] ?? '',
                'content_type' => $nft['content_type'] ?? 'image',
                'royalty_percent' => $nft['royalty_percent'] ?? 0,
                'creator_wallet' => $nft['creator_wallet'] ?? ($nft['Issuer'] ?? $nft['issuer'] ?? ''),
                'metadata' => [
                    'name' => $meta['name'] ?? $nft['name'] ?? 'Unnamed NFT',
                    'image' => $meta['image'] ?? $nft['image'] ?? $nft['image_url'] ?? $nft['image_proxy'] ?? '/wp-content/uploads/fallback-nft.svg',
                    'description' => $meta['description'] ?? '',
                    'attributes' => $meta['attributes'] ?? []
                ]
            ];
        }

        // ═══════════════════════════════════════════════════════════════════
        // SECONDARY MARKET LISTING INJECTION  (v266)
        //
        // Two-tier merge — collection cards show price badges regardless of
        // which marketplace created the offer (OnXRP, XRPLMeta, IMC,
        // xrp.cafe, bidds.com, xmagnetic, xpmarket, etc.).
        //
        //  Tier A — wp_xumm_offers (MySQL, this server)
        //    Offers created through IMC. Fastest source, highest confidence.
        //    Priority: HIGHEST — never overwritten by Tier B.
        //
        //  Tier B — VPS sell_offers (DB cache + live XRPL nft_sell_offers)
        //    Smart endpoint: checks nft_offers DB first (XRPL stream cache),
        //    then calls XRPL nft_sell_offers in parallel for any ID not cached.
        //    Results are written back to DB (self-healing backfill). Returns
        //    all-marketplace offers with no historical gap. Timeout: 8s on
        //    first load (curl_multi XRPL); <100ms on subsequent loads (DB hit).
        // ═══════════════════════════════════════════════════════════════════
        if (!empty($formatted_nfts)) {
            $nft_ids_in_page = array_column($formatted_nfts, 'nftokenID');
            $nft_ids_upper   = array_map('strtoupper', $nft_ids_in_page);
            $listing_map     = [];

            // ── Tier A: IMC WordPress DB ─────────────────────────────────
            if (!empty($nft_ids_upper)) {
                $offers_table_name = $wpdb->prefix . 'xumm_offers';
                if ($wpdb->get_var("SHOW TABLES LIKE '$offers_table_name'") === $offers_table_name) {
                    // v280: guard against empty array → prepare() with no placeholders → PHP notice
                    if (empty($nft_ids_upper)) {
                        $tier_a_rows = [];
                    } else {
                        $tier_a_ph = implode(',', array_fill(0, count($nft_ids_upper), '%s'));
                        $tier_a_rows = $wpdb->get_results(
                            $wpdb->prepare(
                                "SELECT target_nft_id, offer_id, amount, currency
                                   FROM $offers_table_name
                                  WHERE offer_type = 'sell'
                                    AND status     = 'active'
                                    AND offer_id  != ''
                                    AND UPPER(target_nft_id) IN ($tier_a_ph)
                                    AND (synced_at IS NULL OR synced_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE))
                                  ORDER BY created_at DESC",
                                ...$nft_ids_upper
                            ),
                            ARRAY_A
                        ) ?: [];
                    }
                    foreach ($tier_a_rows as $row) {
                        $key = strtoupper($row['target_nft_id']);
                        if (!isset($listing_map[$key])) {
                            $listing_map[$key] = [
                                'offer_id' => $row['offer_id'],
                                'amount'   => (float) $row['amount'],
                                'currency' => $row['currency'] ?: 'XRP',
                            ];
                        }
                    }
                    if (!empty($tier_a_rows)) {
                        error_log("NFT Loader: Tier A (IMC DB) found " . count($listing_map) . " listings");
                    }
                }
            }

            // ── Tier B: VPS sell_offers (DB cache + live XRPL fallback) ──
            // Single endpoint that handles everything:
            //   - Checks the nft_offers DB first (XRPL stream data, instant)
            //   - For any ID not in DB, calls XRPL nft_sell_offers in parallel
            //   - Writes XRPL results back to DB for next request (self-healing)
            //
            // This matches xrp.cafe / bidds.com / xmagnetic / xpmarket — they all
            // call nft_sell_offers directly. It returns offers from ANY marketplace
            // (OnXRP, XRPLMeta, IMC, xrp.cafe etc.) with no historical gap.
            //
            // Timeout is 8s to allow curl_multi XRPL calls on first page load.
            // Subsequent loads hit the DB cache and return in <100ms.
            if (!empty($nft_ids_upper)) {
                $b_ids       = array_slice($nft_ids_upper, 0, 100);
                $vps_url_b   = 'https://metadata.imcollectibles.io/'
                             . '?action=sell_offers'
                             . '&ids=' . rawurlencode(implode(',', $b_ids));
                $vps_resp_b  = wp_remote_get($vps_url_b, ['timeout' => 8, 'sslverify' => true]);
                if (!is_wp_error($vps_resp_b) && wp_remote_retrieve_response_code($vps_resp_b) === 200) {
                    $vps_body_b = json_decode(wp_remote_retrieve_body($vps_resp_b), true);
                    if (!empty($vps_body_b['success']) && !empty($vps_body_b['listings'])) {
                        foreach ($vps_body_b['listings'] as $nft_id_b => $listing) {
                            $key = strtoupper($nft_id_b);
                            if (!$key || isset($listing_map[$key])) continue;
                            if (!empty($listing['currency']) && $listing['currency'] !== 'XRP') {
                                $amt = (float)($listing['price_value'] ?? 0);
                                $cur = $listing['currency'];
                            } else {
                                $amt = (float)($listing['price_xrp'] ?? 0);
                                $cur = 'XRP';
                            }
                            if ($amt > 0 && !empty($listing['offer_id'])) {
                                $listing_map[$key] = [
                                    'offer_id' => $listing['offer_id'],
                                    'amount'   => $amt,
                                    'currency' => $cur,
                                ];
                            }
                        }
                    }
                }
                $b_count = count($listing_map);
                error_log("NFT Loader: Tier B (VPS sell_offers) found $b_count listings for "
                    . count($b_ids) . " NFTs");
            }

            // ── Inject into formatted_nfts ────────────────────────────────
            $injected = 0;
            foreach ($formatted_nfts as &$nft) {
                $upper = strtoupper($nft['nftokenID']);
                if (isset($listing_map[$upper])) {
                    $nft['offer_id']       = $listing_map[$upper]['offer_id'];
                    $nft['offer_amount']   = $listing_map[$upper]['amount'];
                    $nft['offer_currency'] = $listing_map[$upper]['currency'];
                    // v708 (Step E): ADDITIVE display field. 'offer_currency' stays byte-identical
                    // (still used for data-* attributes and any downstream logic); this is the
                    // ticker the card renders. One line covers BOTH Tier A and Tier B because
                    // both converge on $listing_map before this loop.
                    $nft['offer_currency_display'] = imc_offer_currency_display($listing_map[$upper]['currency']);
                    $injected++;
                }
            }
            unset($nft);

            if ($injected > 0) {
                error_log("NFT Loader: Injected listing data into $injected of " . count($formatted_nfts) . " NFTs");
            }
        }
        // ── End secondary market injection ──────────────────────────────────

        // ── Price sort (must run AFTER injection so offer_amount is populated) ─
        // price-asc:  listed NFTs first (cheapest → most expensive), unlisted last.
        // price-desc: listed NFTs first (most expensive → cheapest), unlisted last.
        // v332: Also handle 'listed' sort value (JS maps sortState='listed' → sort='listed' or 'price-asc').
        // Robust float cast avoids string-comparison issues with offer_amount.
        if ($sort === 'price-asc' || $sort === 'price-desc' || $sort === 'listed') {
            $asc = ($sort !== 'price-desc');
            // CP-C2b: SECONDARY KEY - edition number ASC.
            // usort is stable on PHP 8+, so the old `return 0` for unlisted-vs-unlisted
            // preserved SOURCE order. When the VPS answers, that source is Clio ==
            // ledger/MINT order, which groups a collection by drop and lets one large
            // drop bury every other listing. Ordering by edition instead puts every
            // drop's #1 together, then every #2, so no listing is suppressed.
            // The edition is parsed from the trailing #N of the on-chain name - the
            // same shape the card label splits on. Names with no trailing number sort
            // last rather than colliding on 0.
            $imc_edition_of = function ($n) {
                $nm = $n['metadata']['name'] ?? '';
                return preg_match('/[#\x{2116}]\s*(\d+)\s*$/u', (string)$nm, $m)
                    ? (int)$m[1] : PHP_INT_MAX;
            };
            usort($formatted_nfts, function ($a, $b) use ($asc, $imc_edition_of) {
                $a_amt    = isset($a['offer_amount']) ? (float)$a['offer_amount'] : 0.0;
                $b_amt    = isset($b['offer_amount']) ? (float)$b['offer_amount'] : 0.0;
                $a_listed = $a_amt > 0;
                $b_listed = $b_amt > 0;
                if ($a_listed && !$b_listed) return -1;
                if (!$a_listed && $b_listed) return 1;
                if (!$a_listed && !$b_listed) {
                    return $imc_edition_of($a) <=> $imc_edition_of($b);
                }
                $diff = $a_amt - $b_amt;
                if ($diff != 0) { return $asc ? ($diff <=> 0) : (-($diff <=> 0)); }
                // Same price: fall through to edition so the order is deterministic.
                return $imc_edition_of($a) <=> $imc_edition_of($b);
            });
            $listed_count = count(array_filter($formatted_nfts, function($n) { return isset($n['offer_amount']) && (float)$n['offer_amount'] > 0; }));
            error_log('NFT Loader: Listed-first sort (' . $sort . '): ' . $listed_count . ' listed NFTs promoted to front of ' . count($formatted_nfts));
        }

        $data = [
            'success' => true,
            'nfts' => $formatted_nfts,
            'total' => $total,
            'page' => $page,
            'per_page' => $limit
        ];

        // Cache for 60 seconds (short for fast new-mint visibility)
        if (!empty($cache_key)) {
            set_transient($cache_key, $data, 60);
        }
        
        error_log("NFT Loader: Returning " . count($formatted_nfts) . " NFTs");
        wp_send_json($data);
        
    } catch (Exception $e) {
        error_log('NFT loader error: ' . $e->getMessage());
        status_header(500);
        wp_send_json([
            'success' => false,
            'error' => 'Failed to load NFTs: ' . $e->getMessage(),
            'nfts' => [],
            'total' => 0
        ]);
    }
    
    wp_die();
}


// ============================================================
// v59: Cache invalidation helper for mint-on-demand
// Called after successful minting to ensure new NFTs appear immediately
// ============================================================
function imc_invalidate_collection_cache($issuer, $taxon) {
    global $wpdb;
    
    // Delete all VPS cache transients for this collection
    // Pattern: _transient_nft_loader_collection_{hash}_vps
    $like_pattern = '_transient_nft_loader_collection_%_vps';
    
    // Get all matching transients
    $transients = $wpdb->get_col($wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} 
         WHERE option_name LIKE %s",
        $like_pattern
    ));
    
    $deleted = 0;
    foreach ($transients as $transient_name) {
        // Extract the actual transient name (remove '_transient_' prefix)
        $key = str_replace('_transient_', '', $transient_name);
        if (delete_transient($key)) {
            $deleted++;
        }
    }
    
    // Also clear any old-style cache keys (backward compatibility)
    $old_pattern = '_transient_nft_loader_collection_%';
    $old_transients = $wpdb->get_col($wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} 
         WHERE option_name LIKE %s 
         AND option_name NOT LIKE %s",
        $old_pattern,
        $like_pattern
    ));
    
    foreach ($old_transients as $transient_name) {
        $key = str_replace('_transient_', '', $transient_name);
        if (delete_transient($key)) {
            $deleted++;
        }
    }
    
    error_log("IMC Cache: Invalidated $deleted collection cache transients for issuer=$issuer taxon=$taxon");
    
    return $deleted;
}


// ============================================================
// NEW HELPER: Fetch collection from VPS metadata cache
// ============================================================
function imu_fetch_collection_from_vps($issuer, $taxon, $limit = 50, $offset = 0) {
    $vps_api = 'https://metadata.imcollectibles.io/';
    
    // Use the indexer's nfts_by_issuer endpoint
    $url = $vps_api . '?action=nfts_by_issuer&issuer=' . urlencode($issuer) . '&taxon=' . $taxon . '&limit=' . $limit . '&offset=' . $offset;
    
    $response = wp_remote_get($url, ['timeout' => 10, 'sslverify' => true]);
    
    if (is_wp_error($response)) {
        error_log("VPS API error: " . $response->get_error_message());
        return ['nfts' => [], 'total' => 0];
    }
    
    if (wp_remote_retrieve_response_code($response) !== 200) {
        return ['nfts' => [], 'total' => 0];
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if (empty($body['success']) || empty($body['nfts'])) {
        return ['nfts' => [], 'total' => 0];
    }
    
    // Transform to standard format
    $nfts = [];
    foreach ($body['nfts'] as $nft) {
        $nfts[] = [
            'NFTokenID' => $nft['nft_token_id'] ?? $nft['nftokenID'],
            'Issuer' => $nft['issuer'],
            'NFTokenTaxon' => $nft['taxon'],
            'owner' => $nft['owner'] ?? '',
            'name' => $nft['name'] ?? 'Unnamed NFT',
            'image' => $nft['image_url'] ?? $nft['image_resolved'] ?? '',
            'image_url' => $nft['image_url'] ?? $nft['image_resolved'] ?? '',
            'image_proxy' => $nft['image_proxy'] ?? ''
        ];
    }
    
    return [
        'nfts' => $nfts,
        'total' => $body['total'] ?? count($nfts)
    ];
}


// ============================================================
// NEW HELPER: Fetch metadata batch from VPS
// ============================================================
/**
 * D40 (09 Sep 2026) — MIGRATED OFF THE LEGACY /api/ SERVICE.
 *
 * 🔴 WHAT /api/ WAS. A second metadata service reading its OWN database
 * (the legacy metadata database), NOT the store:
 *
 *     legacy  small, historical, no longer written
 *     store   the live index
 *
 * ⚠ Its 04:00 cron still runs and writes ZERO. Coverage was a negligible
 * fraction of the store; outside those rows it returned nothing, and one
 * sampled NFT came back with a FALLBACK PLACEHOLDER image.
 *
 * ★★ And it never called imc_out(). /api/ hands out nftstorage.link URLs — a
 * gateway that has been retired — so none of the CP-C4-2 gateway routing
 * reached anything this function hydrated.
 *
 * ✅ WHAT ?action=batch GIVES INSTEAD:
 *   • the live index instead of a stale snapshot
 *   • metadata.image resolved THROUGH imc_out() — our gateway, not a dead one
 *   • burn filtering, F19 issuer repair and the media-recovery passes included
 *   • retires a public READWRITE SQLite handle (the read API)
 *
 * ⚠ TWO REAL DIFFERENCES, both handled below:
 *   1. TRANSPORT — batch takes a POST body {"ids":[...]}, not GET ?ids=a,b,c
 *   2. FILTER — batch defaults to decode_status='success'. We KEEP that default
 *      deliberately. The excluded share is failed rows (no image to show
 *      anyway) and burned rows, which must never surface in a grid. Passing
 *      &any=1 would hydrate burned tokens.
 *
 * ★ The response SHAPE is unchanged — batch already emits nftokenID,
 * metadata.name/.image/.description/.attributes, success and nfts, exactly
 * what the parser below reads.
 */
function imu_fetch_metadata_batch($nft_ids) {
    if (empty($nft_ids)) return [];
    
    $url = 'https://metadata.imcollectibles.io/?action=batch';
    
    $response = wp_remote_post($url, [
        'timeout'   => 10,
        'sslverify' => true,
        'headers'   => ['Content-Type' => 'application/json'],
        'body'      => wp_json_encode(['ids' => array_values(array_slice($nft_ids, 0, 100))]),
    ]);
    
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return [];
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if (empty($body['success']) || empty($body['nfts'])) {
        return [];
    }
    
    $result = [];
    foreach ($body['nfts'] as $nft) {
        // FIX: Normalize to uppercase for consistent lookups
        $id = strtoupper($nft['nftokenID'] ?? '');
        if ($id && preg_match('/^[0-9A-F]{64}$/', $id)) {
            $result[$id] = [
                'name' => $nft['metadata']['name'] ?? 'Unnamed NFT',
                // batch has no assets[]; image_url is its top-level equivalent,
                // imc_out()-resolved identically.
                'image' => $nft['metadata']['image'] ?? $nft['image_url'] ?? '',
                'description' => $nft['metadata']['description'] ?? '',
                'attributes' => $nft['metadata']['attributes'] ?? []
            ];
        }
    }
    
    return $result;
}


// ============================================================
// NEW: Auto Collection Image (first NFT or admin override)
// ============================================================
function imu_get_collection_image($issuer, $taxon, $fallback = '') {
    // Check for admin override first
    $marketplace_assets = get_option('marketplace_assets', ['collections' => []]);
    $slug = imu_get_collection_slug($issuer, $taxon);
    
    if (!empty($marketplace_assets['collections'][$slug]['url'])) {
        return esc_url($marketplace_assets['collections'][$slug]['url']);
    }
    
    // Check transient cache
    $cache_key = 'collection_image_' . md5($issuer . '_' . $taxon);

    // v234: For IMC-minted collections, read cover_image_ipfs from wp_imc_collections.
    // This is the artist-designated collection cover uploaded during collection creation.
    // Must run BEFORE the transient cache — the cache may be stale from before this fix.
    // imc_collections is the authoritative source; imc_listings.cover_ipfs is the per-NFT
    // thumbnail and must NOT be used as the collection cover image.
    global $wpdb;
    $imc_coll_table = $wpdb->prefix . 'imc_collections';
    if ($wpdb->get_var("SHOW TABLES LIKE '$imc_coll_table'") === $imc_coll_table) {
        $imc_cover = $wpdb->get_var($wpdb->prepare(
            "SELECT cover_image_ipfs FROM $imc_coll_table
             WHERE artist_account = %s AND collection_taxon = %d
             AND cover_image_ipfs IS NOT NULL AND cover_image_ipfs != ''
             LIMIT 1",
            $issuer, $taxon
        ));
        if ($imc_cover) {
            $cid = str_replace('ipfs://', '', $imc_cover);
            $image = 'https://metadata.imcollectibles.io/img.php?url=' . urlencode('ipfs://' . $cid);
            set_transient($cache_key, $image, DAY_IN_SECONDS); // Refresh cache with correct value
            return esc_url($image);
        }
    }

    // Check transient cache (for non-IMC / externally-indexed collections)
    $cached = get_transient($cache_key);
    if ($cached !== false && strpos($cached, 'fallback') === false) {
        return $cached;
    }
    
    // Fetch first NFT from VPS
    $vps_api = 'https://metadata.imcollectibles.io/';
    $url = $vps_api . '?action=nfts_by_issuer&issuer=' . urlencode($issuer) . '&taxon=' . $taxon . '&limit=1';
    
    $response = wp_remote_get($url, ['timeout' => 5, 'sslverify' => true]);
    
    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($body['nfts'][0]['image_url'])) {
            $image = $body['nfts'][0]['image_url'];
            // v213: Convert ipfs:// to gateway URL (browsers can't render ipfs:// directly)
            if (strpos($image, 'ipfs://') === 0) {
                $image = 'https://ipfs.io/ipfs/' . substr($image, 7);
            } elseif (preg_match('/^(Qm|bafy)/', $image)) {
                $image = 'https://ipfs.io/ipfs/' . $image;
            }
            set_transient($cache_key, $image, DAY_IN_SECONDS);
            return esc_url($image);
        }
        if (!empty($body['nfts'][0]['image_resolved'])) {
            $image = $body['nfts'][0]['image_resolved'];
            // v213: Same ipfs:// conversion
            if (strpos($image, 'ipfs://') === 0) {
                $image = 'https://ipfs.io/ipfs/' . substr($image, 7);
            } elseif (preg_match('/^(Qm|bafy)/', $image)) {
                $image = 'https://ipfs.io/ipfs/' . $image;
            }
            set_transient($cache_key, $image, DAY_IN_SECONDS);
            return esc_url($image);
        }
        
        // v212: VPS has NFTs but no resolved image — try Bithomp for the first one
        $first_nft_id = $body['nfts'][0]['NFTokenID'] ?? $body['nfts'][0]['nftokenID'] ?? '';
        if ($first_nft_id) {
            $bh_img = imu_bithomp_nft_image($first_nft_id);
            if ($bh_img) {
                set_transient($cache_key, $bh_img, DAY_IN_SECONDS);
                return esc_url($bh_img);
            }
        }
    }
    
    // v212: VPS empty — try Bithomp collection endpoint for cover image
    $bithomp_key = defined('BITHOMP_API_KEY') ? BITHOMP_API_KEY : '';
    if ($bithomp_key) {
        $bh_col_cache = 'bh_col_img_' . substr(md5($issuer . '_' . $taxon), 0, 12);
        $bh_cached = get_transient($bh_col_cache);
        if ($bh_cached !== false) {
            if (!empty($bh_cached)) {
                set_transient($cache_key, $bh_cached, DAY_IN_SECONDS);
                return esc_url($bh_cached);
            }
        } else {
            // P1F (Aug 2026): store-first - the old Bithomp call sent assets=true
            // (free-tier dead = guaranteed 5s miss). action=collection carries the
            // meta-pass cover image.
            $bh_url = 'https://metadata.imcollectibles.io/?action=collection&issuer=' . urlencode($issuer) . '&taxon=' . $taxon;
            $bh_resp = wp_remote_get($bh_url, [
                'timeout' => 5,
                'headers' => ['Accept' => 'application/json']
            ]);
            
            if (!is_wp_error($bh_resp) && wp_remote_retrieve_response_code($bh_resp) === 200) {
                $bh_data = json_decode(wp_remote_retrieve_body($bh_resp), true);
                $bh_img = (!empty($bh_data['success']) && !empty($bh_data['collection'])) ? ($bh_data['collection']['image'] ?? '') : '';
                if ($bh_img) {
                    if (strpos($bh_img, 'ipfs://') === 0) {
                        $bh_img = 'https://ipfs.io/ipfs/' . substr($bh_img, 7);
                    }
                    set_transient($bh_col_cache, $bh_img, DAY_IN_SECONDS);
                    set_transient($cache_key, $bh_img, DAY_IN_SECONDS);
                    return esc_url($bh_img);
                }
            }
            // Cache empty result to avoid repeated failed lookups
            set_transient($bh_col_cache, '', HOUR_IN_SECONDS);
        }
    }
    
    // Use fallback
    $image = $fallback ?: '/wp-content/uploads/fallback-nft.svg';
    set_transient($cache_key, $image, 15 * MINUTE_IN_SECONDS);
    return esc_url($image);
}

/**
 * v212: Helper — fetch single NFT image from Bithomp
 * Used by collection image resolver as a fallback
 */
function imu_bithomp_nft_image($nft_id) {
    $bithomp_key = defined('BITHOMP_API_KEY') ? BITHOMP_API_KEY : '';
    if (!$bithomp_key || !$nft_id) return '';
    
    // Check enrichment cache first (shared with NFT loader)
    $nft_cache_key = "bh_nft_" . substr(md5($nft_id), 0, 12);
    $cached = get_transient($nft_cache_key);
    if ($cached !== false && !empty($cached['image'])) {
        return $cached['image'];
    }
    
    // P1d-2 (Aug 2026): store-first via the bounded fast miss-path. The old
    // Bithomp call sent assets=true (free-tier dead) - a guaranteed 5s miss.
    $bh_url = 'https://metadata.imcollectibles.io/?action=get&id=' . rawurlencode($nft_id) . '&fetch=fast';
    $bh_resp = wp_remote_get($bh_url, [
        'timeout' => 5,
        'headers' => ['Accept' => 'application/json']
    ]);
    
    if (!is_wp_error($bh_resp) && wp_remote_retrieve_response_code($bh_resp) === 200) {
        $bn = json_decode(wp_remote_retrieve_body($bh_resp), true);
        $vfh = (!empty($bn['success']) && !empty($bn['nft'])
                && (empty($bn['nft']['decode_status']) || $bn['nft']['decode_status'] === 'success'))
               ? $bn['nft'] : [];
        $bh_meta = $vfh['metadata'] ?? [];
        // img.php proxy first - resolves internally, never renders broken.
        $bh_img = $vfh['image_proxy'] ?? $vfh['image_resolved'] ?? $vfh['image_url'] ?? ($bh_meta['image'] ?? '');
        if (strpos($bh_img, 'ipfs://') === 0) {
            $bh_img = 'https://ipfs.io/ipfs/' . substr($bh_img, 7);
        } elseif (preg_match('/^(Qm|bafy)/', $bh_img)) {
            $bh_img = 'https://ipfs.io/ipfs/' . $bh_img;
        }
        // Cache for shared use with enrichment system
        set_transient($nft_cache_key, [
            'name' => $bh_meta['name'] ?? '',
            'image' => $bh_img,
            'description' => $bh_meta['description'] ?? ''
        ], 86400);
        return $bh_img;
    }
    return '';
}

// ============================================================================
// COLLECTION SLUG SYSTEM (v351)
// Slug format: {slugify(name)}-{taxon}  e.g. "my-cool-art-42"
// IMU's 5 named collections keep their short registered slugs.
// Two artists with the same name+taxon: VPS returns the higher health_score one.
// ============================================================================

/**
 * Convert a collection name to its slug component.
 * Must produce the exact same output as imc_slugify_name() in indexer.php.
 */
function imu_collection_slugify(string $name): string {
    $s = strtolower(trim($name));
    $s = preg_replace('/[^a-z0-9\s\-]/u', '', $s);
    $s = preg_replace('/[\s\-]+/', '-', $s);
    return trim($s, '-') ?: 'collection';
}

/**
 * Return the URL slug for a collection.
 * Hardcoded pretty slugs for IMU's 5 collections; name+taxon for everything else.
 * Results for dynamic collections cached 24h (name rarely changes).
 */
function imu_get_collection_slug($issuer, $taxon) {
    // Hardcoded pretty slugs for IMU's own collections
    static $imu_pretty = [
        'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR_0'         => 'guardians',
        'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga_717825'     => 'frequencies',
        'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt_1056369418' => 'ledger',
        'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR_777'        => 'lasvegas',
        'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR_666'        => 'firepit',
    ];
    $key = $issuer . '_' . $taxon;
    if (isset($imu_pretty[$key])) return $imu_pretty[$key];

    // Check WP transient cache
    $cache_key = 'imc_slug_' . substr(md5($issuer . '_' . $taxon), 0, 16);
    $cached = get_transient($cache_key);
    if ($cached !== false) return $cached;

    // Try wp_imc_collections table first (fastest, platform-minted)
    global $wpdb;
    $ct = $wpdb->prefix . 'imc_collections';
    if ($wpdb->get_var("SHOW TABLES LIKE '$ct'") === $ct) {
        $name = $wpdb->get_var($wpdb->prepare(
            "SELECT collection_name FROM $ct WHERE artist_account = %s AND collection_taxon = %d AND collection_name IS NOT NULL AND collection_name != '' LIMIT 1",
            $issuer, $taxon
        ));
        if ($name) {
            $slug = imu_collection_slugify($name) . '-' . $taxon;
            set_transient($cache_key, $slug, DAY_IN_SECONDS);
            return $slug;
        }
    }

    // Try VPS (covers all XRPL-indexed collections)
    $vr = wp_remote_get(
        'https://metadata.imcollectibles.io/?action=collection&issuer=' . urlencode($issuer) . '&taxon=' . $taxon,
        ['timeout' => 3]
    );
    if (!is_wp_error($vr) && wp_remote_retrieve_response_code($vr) === 200) {
        $vd   = json_decode(wp_remote_retrieve_body($vr), true);
        $name = $vd['collection']['name'] ?? '';
        if ($name) {
            $slug = imu_collection_slugify($name) . '-' . $taxon;
            set_transient($cache_key, $slug, DAY_IN_SECONDS);
            return $slug;
        }
    }

    // Last resort: issuer-taxon (still a valid parseable format)
    return $issuer . '-' . $taxon;
}


// Public caching removed: these responses are wallet-scoped.
// Previously: header('Cache-Control: max-age=300, public') was caching pages WITH user wallet addresses
// This caused User B to see User A's logged-in state
// Fix Date: January 22, 2026
function allow_cache_for_trading_hub() {
    // DISABLED - Do NOT enable public caching on pages with user-specific data
    // The trading hub embeds user's wallet address in HTML
    return;
}






/**
 * Add custom cron schedule for every minute
 */
add_filter('cron_schedules', function ($schedules) {
    $schedules['every_minute'] = [
        'interval' => 60,
        'display'  => __('Every Minute', 'improtectors'),
    ];
    // v23 FIX: 5-minute schedule for reservation cleanup — ensures expired reservations
    // are released even when no users are minting (e.g. near-sold-out listings)
    $schedules['imc_five_minutes'] = [
        'interval' => 300,
        'display'  => __('Every 5 Minutes (IMC)', 'improtectors'),
    ];
    return $schedules;
});

/**
 * Schedule cron jobs for notifications
 */
add_action('init', function () {
    if (!wp_next_scheduled('improtectors_notification_check')) {
        wp_schedule_event(time(), 'every_minute', 'improtectors_notification_check');
        xaman_log("Scheduled improtectors_notification_check cron job with every_minute interval");
    }
    if (!wp_next_scheduled('improtectors_retry_notifications')) {
        wp_schedule_event(time(), 'hourly', 'improtectors_retry_notifications');
        xaman_log("Scheduled improtectors_retry_notifications cron job with hourly interval");
    }
});

/**
 * Clear cron jobs on theme deactivation
 */
add_action('switch_theme', function () {
    wp_clear_scheduled_hook('improtectors_notification_check');
    wp_clear_scheduled_hook('improtectors_retry_notifications');
    xaman_log("Cleared cron jobs on theme deactivation");
});

/**
 * AJAX handler for refreshing nonce
 */
add_action('wp_ajax_refresh_nonce', 'refresh_nonce_callback');
add_action('wp_ajax_nopriv_refresh_nonce', 'refresh_nonce_callback');
function refresh_nonce_callback() {
    $nonce = wp_create_nonce('xaman_notifications_nonce');
    xaman_log("Refreshed nonce: $nonce");
    wp_send_json_success(['nonce' => $nonce]);
}

/**
 * AJAX handler for refreshing XFT claim nonce
 * Prevents "Security verification failed" on cached/stale pages
 * where the WordPress nonce has expired.
 *
 * Called by frequency-fountain.js before submitting the claim form.
 */
add_action('wp_ajax_refresh_xft_nonce', 'refresh_xft_nonce_callback');
add_action('wp_ajax_nopriv_refresh_xft_nonce', 'refresh_xft_nonce_callback');
function refresh_xft_nonce_callback() {
    $new_nonce = wp_create_nonce('xft_claim');
    xaman_log("Refreshed XFT claim nonce: $new_nonce");
    wp_send_json_success(['nonce' => $new_nonce]);
}

/**
 * AJAX handler for logging JavaScript errors
 */
add_action('wp_ajax_log_js_error', 'log_js_error_callback');
add_action('wp_ajax_nopriv_log_js_error', 'log_js_error_callback');
function log_js_error_callback() {
    $message = sanitize_text_field($_POST['message'] ?? 'Unknown JavaScript error');
    xaman_log("JavaScript error: $message", 'ERROR');
    wp_send_json_success();
}

/**
 * AJAX handler for checking login status
 */
add_action('wp_ajax_check_login_status', 'check_login_status_callback');
add_action('wp_ajax_nopriv_check_login_status', 'check_login_status_callback');
function check_login_status_callback() {
    $nonce = sanitize_text_field($_POST['nonce'] ?? '');
    if (!wp_verify_nonce($nonce, 'xaman_notifications_nonce')) {
        xaman_log("Invalid nonce for check_login_status: $nonce", 'ERROR');
        wp_send_json_error(['message' => 'Invalid nonce'], 403);
    }

    $account = sanitize_text_field($_POST['account'] ?? '');
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        xaman_log("Invalid XRPL account for check_login_status: $account", 'ERROR');
        wp_send_json_error(['message' => 'Invalid XRPL account'], 400);
    }

    $is_logged_in = ($account !== '') && function_exists('imc_session_wallet') && (imc_session_wallet() === $account);
    xaman_log("Login status for $account: " . ($is_logged_in ? 'Logged in' : 'Not logged in'));
    wp_send_json_success(['is_logged_in' => $is_logged_in]);
}


add_shortcode('premium_access', 'premium_access_shortcode');
function premium_access_shortcode($atts, $content = null) {
    xaman_log("Shortcode [premium_access] executed on page: " . get_the_title());

    $account = function_exists('imc_session_wallet') ? imc_session_wallet() : '';
    if ($account === '') {
        xaman_log("No valid XRPL account cookie detected");
        return '<div class="section"><p>Please log in with Xaman Wallet to access this content.</p>' . do_shortcode('[xaman_login]') . '</div>';
    }
    xaman_log("Checking eligibility for account: " . $account);
    $transient_key = 'xaman_nft_' . md5($account);
    $nfts = get_transient($transient_key);

    if ($nfts === false) {
        $url = "https://imcollectibles.io/xumm-proxy.php?account=$account&t=" . time();
        xaman_log("Fetching NFT data from: " . $url);
        $nft_data = @file_get_contents($url);
        if ($nft_data === false) {
            xaman_log("Failed to fetch NFT data for $account");
            return '<p>Error fetching wallet data. Please try again later.</p>';
        }
        $nfts = json_decode($nft_data, true);
        if ($nfts === null) {
            xaman_log("Invalid JSON response for $account");
            return '<p>Invalid wallet data. Please try again later.</p>';
        }
        set_transient($transient_key, $nfts, 300);
        xaman_log("Cached NFT data for $account");
    }

    $has_nft = false;
    if ($nfts && isset($nfts['result']['account_nfts'])) {
        foreach ($nfts['result']['account_nfts'] as $nft) {
            $issuer = $nft['Issuer'];
            $taxon = $nft['NFTokenTaxon'];
            if (
                ($issuer === 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR' && $taxon == 0) ||
                ($issuer === 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR' && $taxon == 777) ||
                ($issuer === 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR' && $taxon == 666) ||
                ($issuer === 'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga' && $taxon == 717825) ||
                ($issuer === 'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt' && $taxon == 1056369418)
            ) {
                $has_nft = true;
                xaman_log("Eligible NFT found for $account: Issuer=$issuer, Taxon=$taxon");
                break;
            }
        }
    }

    if ($has_nft) {
        xaman_log("Account $account is eligible for premium content");
        return do_shortcode($content);
    } else {
        xaman_log("Account $account is not eligible for premium content");
        return '<div class="section"><p>Become a Protector or Guardian to access this content!</p><a href="' . esc_url(home_url('/mint/')) . '" class="btn" style="display:inline-block; padding:10px 20px; background:gold; color:black; text-decoration:none; border-radius:5px;">Mint Now</a></div>';
    }
}



function imu_register_rewrite_rules() {
    add_rewrite_rule(
        '^profile/([^/]+)/?$',
        'index.php?pagename=profile&imu_profile_id=$matches[1]',
        'top'
    );
}
add_action('init', 'imu_register_rewrite_rules');

function imu_register_query_vars($vars) {
    $vars[] = 'imu_profile_id';
    return $vars;
}
add_filter('query_vars', 'imu_register_query_vars');

/**
 * Profiles phase (P5A): the legacy /profile/ page is a retired husk. The XFT claim
 * flow it used to host now lives at /frequency-fountain/. Redirect both bare
 * /profile/ and /profile/{id} there so the ~18 notification/email/share deep links
 * land on the live claim page instead of a dead page. (Post-P6 UX may route genuine
 * identity-intent links to /user/ instead; for now all go to the claim page.)
 */
function imu_profile_legacy_redirect() {
    // Match the retired profile page: bare /profile/ (pagename) or /profile/{id}.
    $is_profile = is_page('profile')
        || get_query_var('imu_profile_id') !== ''
        || (isset($GLOBALS['wp']->request) && preg_match('#^profile(/|$)#', $GLOBALS['wp']->request));
    if ($is_profile) {
        wp_safe_redirect(home_url('/frequency-fountain/'), 301);
        exit;
    }
}
add_action('template_redirect', 'imu_profile_legacy_redirect');


// Add rewrite rule for /collections/{slug}
add_action('init', function() {
    add_rewrite_rule('^collections/([^/]*)/?$', 'index.php?pagename=collections&collection_slug=$matches[1]', 'top');
});

// Add query var
add_filter('query_vars', function($vars) {
    $vars[] = 'collection_slug';
    return $vars;
});


add_filter('template_include', function($template){
    if (get_query_var('collection_slug')) {
        $t = locate_template('page-templates/collections.php');
        if ($t) { return $t; }
    }
    return $template;
});




add_shortcode('not_logged_in', 'not_logged_in_shortcode');
function not_logged_in_shortcode($atts, $content = null) {
    xaman_log("Shortcode [not_logged_in] executed on page: " . get_the_title());
    if (!function_exists('imc_session_wallet') || imc_session_wallet() === '') {
        xaman_log("No XRPL account cookie detected, showing content");
        return do_shortcode($content);
    }
    return '';
}

add_shortcode('xaman_login', 'xaman_login_shortcode');
function xaman_login_shortcode() {
    error_log("xaman_login_shortcode executed on page: " . get_the_title());
    xaman_log("Shortcode [xaman_login] executed on page: " . get_the_title());
    $unique_id = uniqid();
    $button_id = 'xaman-login-btn-' . $unique_id;
    $qr_id = 'xaman-qr-code-' . $unique_id;
    $instruction_id = 'xaman-sign-instruction-' . $unique_id;
    ob_start();
    ?>
    <div class="xaman-login-container" data-instance-id="<?php echo esc_attr($unique_id); ?>" style="text-align:center; padding:20px;">
        <button id="<?php echo esc_attr($button_id); ?>" class="xaman-btn" style="padding:10px 20px; background:#007bff; color:white; border:none; border-radius:5px; cursor:pointer;">Login with Xaman Wallet</button>
        <div id="<?php echo esc_attr($qr_id); ?>" style="text-align:center; margin-top:20px;"></div>
        <p id="<?php echo esc_attr($instruction_id); ?>" style="display:none; margin-top:10px;">Please sign in with Xaman. Waiting for confirmation...</p>
    </div>
    <?php
    $output = ob_get_clean();
    add_action('wp_footer', function() use ($unique_id, $button_id, $qr_id, $instruction_id) {
    ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.querySelector('.xaman-login-container[data-instance-id="<?php echo esc_js($unique_id); ?>"]');
            if (!container) {
                console.error('Xaman login container not found for instance <?php echo esc_js($unique_id); ?>!');
                return;
            }
            const loginBtn = container.querySelector('#<?php echo esc_js($button_id); ?>');
            const qrCodeDiv = container.querySelector('#<?php echo esc_js($qr_id); ?>');
            const instructionDiv = document.querySelector('#<?php echo esc_js($instruction_id); ?>');
            
            if (!loginBtn || !qrCodeDiv || !instructionDiv) {
                console.error('Xaman login elements not found in container for instance <?php echo esc_js($unique_id); ?>!', {
                    loginBtn: loginBtn,
                    qrCodeDiv: qrCodeDiv,
                    instructionDiv: instructionDiv
                });
                return;
            }

            let currentUUID = sessionStorage.getItem('xaman_uuid_<?php echo esc_js($unique_id); ?>');
            const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
            const urlParams = new URLSearchParams(window.location.search);
            const isLogout = urlParams.get('logout') === 'true';
            const isLoggedOut = urlParams.get('loggedout') === 'true';

            // Handle logout or loggedout parameter
            if (isLogout || isLoggedOut) {
                document.cookie = 'xrpl_account=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; domain=.imcollectibles.io; secure; SameSite=Lax';
                document.cookie = 'xrpl_account=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; domain=imcollectibles.io; secure; SameSite=Lax';
                document.cookie = 'xrpl_account=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; secure; SameSite=Lax';
                sessionStorage.clear();
                localStorage.removeItem('userData');
                localStorage.removeItem('gameVersion');
                // Fix 4 (Aug 2026): clear the cross-tab sign-in localStorage keys on
                // logout. xaman-signin-complete.php writes imc_xrpl_account +
                // imc_xrpl_login_ts, and the 'storage' listener below re-sets the soft
                // xrpl_account cookie (and reloads) whenever they change. Without this
                // clear they SURVIVE logout, so a later tab event re-pollutes the stale
                // soft-cookie state. Removing them here kills that re-pollution source.
                localStorage.removeItem('imc_xrpl_account');
                localStorage.removeItem('imc_xrpl_login_ts');
                window.xrpl_account = '';
                window.xrplMarketplace.user_account = '';
                window.xamanNotifications.externalUserId = '';
                console.log('Client-side cleared xrpl_account cookie due to logout/loggedout parameter');
                return;
            }

            // Fix 5b-JS (Aug 2026): the server (token-first PHP above) has ALREADY
            // decided this visitor is logged OUT, or this script would never render.
            // A leftover soft xrpl_account cookie here is STALE - it used to trigger
            // a redirect-to-home that locked users out of the connect page. Now we
            // SELF-HEAL: clear the stale cookie (all scopes) and stay on the page.
            if (document.cookie.includes('xrpl_account=') && !isLogout && !isLoggedOut) {
                console.log('Stale xrpl_account cookie with no session token - clearing (self-heal)');
                var imcExp = '; expires=Thu, 01 Jan 1970 00:00:00 GMT; secure; SameSite=Lax';
                document.cookie = 'xrpl_account=' + imcExp + '; path=/;';
                document.cookie = 'xrpl_account=' + imcExp + '; path=/; domain=.imcollectibles.io;';
                document.cookie = 'xrpl_account=' + imcExp + '; path=/; domain=imcollectibles.io;';
            }

            // Check for existing UUID
            if (currentUUID) {
                console.log('Found stored UUID:', currentUUID);
                loginBtn.style.display = 'none';
                instructionDiv.style.display = 'block';
                instructionDiv.innerHTML = isMobile ? 
                    'Checking sign-in status, please wait...' : 
                    'Checking QR code status, please wait...';
                autoCheckSignIn(currentUUID, qrCodeDiv, instructionDiv);
            }

            loginBtn.addEventListener('click', function() {
                console.log('Button clicked, fetching QR... on page: <?php echo esc_js(get_the_title()); ?>');
                document.cookie = 'xrpl_account=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; domain=.imcollectibles.io; secure; SameSite=Lax';
                fetch('/xumm-proxy.php?signin=true', { 
                    method: 'GET',
                    cache: 'no-store',
                    credentials: 'include'
                })
                .then(response => {
                    console.log('Fetch response status:', response.status);
                    if (!response.ok) throw new Error('Network response was not ok: ' + response.status);
                    return response.json();
                })
                .then(data => {
                    console.log('Signin response data:', data);
                    if (data.error) {
                        if (data.error.includes('Max payloads')) {
                            qrCodeDiv.innerHTML = '<p>Login limit reached. Please try again later or contact support.</p>';
                        } else {
                            qrCodeDiv.innerHTML = '<p>Login failed: ' + data.error + '. <a href="#" onclick="location.reload();">Try again</a></p>';
                        }
                        console.error('Error in signin response:', data.error);
                    } else if (data.qr && data.uuid) {
                        currentUUID = data.uuid;
                        sessionStorage.setItem('xaman_uuid_<?php echo esc_js($unique_id); ?>', currentUUID);
                        console.log('Received and stored UUID: ' + currentUUID);
                        console.log('Deeplink URL: ' + data.deeplink);
                        loginBtn.style.display = 'none';
                        qrCodeDiv.innerHTML = `
                            <img src="${data.qr}" alt="Scan with Xaman Wallet" style="max-width:200px;" />
                            <br>
                            <a href="${data.deeplink}" id="deeplink-btn-<?php echo esc_js($unique_id); ?>" style="display:${isMobile ? 'inline-block' : 'none'}; margin-top:10px; padding:10px 20px; background:#007bff; color:white; text-decoration:none; border-radius:5px;">Open in Xaman</a>
                        `;
                        instructionDiv.style.display = 'block';
                        instructionDiv.innerHTML = isMobile ? 
                            'Click "Open in Xaman" to sign in, then return to this tab.' : 
                            'Scan the QR code with your Xaman app.';
                        autoCheckSignIn(currentUUID, qrCodeDiv, instructionDiv);
                    } else {
                        console.error('No QR or UUID in signin response:', data);
                        qrCodeDiv.innerHTML = '<p>Failed to load QR code. <a href="#" onclick="location.reload();">Try again</a></p>';
                    }
                })
                .catch(error => {
                    console.error('Signin fetch error:', error);
                    qrCodeDiv.innerHTML = '<p>Error loading QR code: ' + error.message + '. <a href="#" onclick="location.reload();">Try again</a></p>';
                });
            });

            function autoCheckSignIn(uuid, qrDiv, instrDiv) {
    console.log('Starting auto-check for UUID:', uuid);
    let attempts = 0;
    const maxAttempts = 180;
    let isRedirecting = false;

    // Immediate check
    fetch('/xumm-proxy.php?check_uuid=' + uuid, { 
        method: 'GET',
        cache: 'no-store',
        credentials: 'include'
    })
    .then(response => {
        console.log('Initial auto-check response status:', response.status);
        if (!response.ok) throw new Error('Network response not ok: ' + response.status);
        return response.json();
    })
    .then(data => {
        console.log('Initial auto-check response:', JSON.stringify(data));
        if (data.signed === true && data.account && data.redirect) {
            isRedirecting = true;
            sessionStorage.removeItem('xaman_uuid_<?php echo esc_js($unique_id); ?>');
            console.log('Sign-in confirmed on initial check, redirecting to:', data.redirect);
            document.cookie = 'xrpl_account=' + data.account + '; path=/; domain=.imcollectibles.io; secure; SameSite=Lax';
            window.location.href = data.redirect;
        }
    })
    .catch(error => {
        console.error('Initial polling error:', error);
    });

    const interval = setInterval(() => {
        if (isRedirecting) return;
        console.log('Polling attempt:', attempts + 1, 'for UUID:', uuid);
        fetch('/xumm-proxy.php?check_uuid=' + uuid, { 
            method: 'GET',
            cache: 'no-store',
            credentials: 'include'
        })
        .then(response => {
            console.log('Polling response status:', response.status);
            if (!response.ok) throw new Error('Network response not ok: ' + response.status);
            return response.json();
        })
        .then(data => {
            console.log('Auto-check response data:', JSON.stringify(data));
            attempts++;
            // Updated instruction text without timer/countdown
            instrDiv.innerHTML = isMobile ? 
                'Waiting for Xaman sign-in...' : 
                'Please sign in with Xaman. Waiting for confirmation...';

            if (data.signed === true && data.account && data.redirect) {
                isRedirecting = true;
                clearInterval(interval);
                sessionStorage.removeItem('xaman_uuid_<?php echo esc_js($unique_id); ?>');
                console.log('Sign-in confirmed, redirecting to:', data.redirect);
                document.cookie = 'xrpl_account=' + data.account + '; path=/; domain=.imcollectibles.io; secure; SameSite=Lax';
                window.location.href = data.redirect;
            } else if (attempts >= maxAttempts) {
                clearInterval(interval);
                sessionStorage.removeItem('xaman_uuid_<?php echo esc_js($unique_id); ?>');
                console.log('Max attempts reached, offering manual retry');
                instrDiv.innerHTML = 'Login timed out. <button onclick="location.reload();" style="padding:10px 20px; background:#007bff; color:white; border:none; border-radius:5px; cursor:pointer;">Try Again</button>';
            } else if (data.error) {
                clearInterval(interval);
                sessionStorage.removeItem('xaman_uuid_<?php echo esc_js($unique_id); ?>');
                instrDiv.innerHTML = 'Login error: ' + data.error + '. <button onclick="location.reload();" style="padding:10px 20px; background:#007bff; color:white; border:none; border-radius:5px; cursor:pointer;">Try Again</button>';
            }
        })
        .catch(error => {
            console.error('Polling error:', error);
            clearInterval(interval);
            sessionStorage.removeItem('xaman_uuid_<?php echo esc_js($unique_id); ?>');
            instrDiv.innerHTML = 'Error checking status: ' + error.message + '. <button onclick="location.reload();" style="padding:10px 20px; background:#007bff; color:white; border:none; border-radius:5px; cursor:pointer;">Try Again</button>';
        });
    }, isMobile ? 500 : 1000);

    if (isMobile) {
        sessionStorage.setItem('xaman_redirect_url_<?php echo esc_js($unique_id); ?>', '/xaman-signin-complete.php?uuid=' + uuid);
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible' && !isRedirecting) {
                console.log('Tab visible, checking UUID:', uuid);
                fetch('/xumm-proxy.php?check_uuid=' + uuid, { 
                    method: 'GET',
                    cache: 'no-store',
                    credentials: 'include'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.signed === true && data.account && data.redirect) {
                        isRedirecting = true;
                        clearInterval(interval);
                        sessionStorage.removeItem('xaman_uuid_<?php echo esc_js($unique_id); ?>');
                        sessionStorage.removeItem('xaman_redirect_url_<?php echo esc_js($unique_id); ?>');
                        console.log('Sign-in confirmed on tab focus, redirecting to:', data.redirect);
                        document.cookie = 'xrpl_account=' + data.account + '; path=/; domain=.imcollectibles.io; secure; SameSite=Lax';
                        window.location.href = data.redirect;
                    }
                })
                .catch(error => {
                    console.error('Focus polling error:', error);
                    instrDiv.innerHTML = 'Error checking status: ' + error.message + '. <button onclick="location.reload();" style="padding:10px 20px; background:#007bff; color:white; border:none; border-radius:5px; cursor:pointer;">Try Again</button>';
                });
            }
        }, { once: true });
    }
}
        });

        // v281: Deduplicate xrpl_account cookies on every page load.
        // Browsers accumulate multiple cookies with the same name when Set-Cookie uses
        // different domain scopes (example.com vs .example.com). This one-time cleanup
        // runs on every WP page via wp_footer and fixes existing affected sessions.
        (function() {
            var name = 'xrpl_account';
            var m = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');
            var val = m ? decodeURIComponent(m[2]) : null;
            if (!val || !/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/.test(val)) return;
            var count = (document.cookie.match(new RegExp('(?:^|;)\\s*' + name + '\\s*=', 'g')) || []).length;
            if (count <= 1) return;
            var exp = 'expires=Thu, 01 Jan 1970 00:00:00 GMT';
            document.cookie = name + '=; ' + exp + '; path=/';
            document.cookie = name + '=; ' + exp + '; path=/; domain=imcollectibles.io';
            document.cookie = name + '=; ' + exp + '; path=/; domain=.imcollectibles.io';
            document.cookie = name + '=' + encodeURIComponent(val) + '; path=/; domain=.imcollectibles.io; max-age=' + (86400*30) + '; secure; SameSite=Lax';
            console.log('[IMC] Deduplicated ' + count + ' xrpl_account cookies on this page');
        })();

        // v279: Cross-tab sign-in detection via localStorage.
        // When xaman-signin-complete.php writes imc_xrpl_account to localStorage,
        // every open tab fires the 'storage' event. If this tab was rendered without
        // a wallet, we set the cookie and reload once so PHP picks it up.
        window.addEventListener('storage', function(e) {
            if (e.key === 'imc_xrpl_account' && e.newValue) {
                var acct = e.newValue;
                if (/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/.test(acct)) {
                    document.cookie = 'xrpl_account=' + acct + '; path=/; domain=.imcollectibles.io; max-age=' + (86400*30) + '; secure; SameSite=Lax';
                    var pageAcct = document.querySelector('[data-account]')?.dataset?.account || '';
                    if (!pageAcct && !sessionStorage.getItem('_imc_storage_reload')) {
                        console.log('[IMC] Cross-tab sign-in — reloading tab to apply session');
                        sessionStorage.setItem('_imc_storage_reload', '1');
                        window.location.reload();
                    }
                }
            }
        });
    </script>
    <?php
});
    return $output;
}


/**
 * Shortcode for conditional login display
 */
add_shortcode('xaman_login_conditional', 'xaman_login_conditional_shortcode');
require_once __DIR__ . '/joey-login.php'; // registers [joey_login] (additive; v563: public + live on /login)
function xaman_login_conditional_shortcode() {
    $log_file = __DIR__ . '/logs/xumm-webhook.log';
    $current_page = $_SERVER['REQUEST_URI'];
    $is_logout = isset($_GET['logout']) && $_GET['logout'] === 'true';
    $is_logged_out = isset($_GET['loggedout']) && $_GET['loggedout'] === 'true';
    xaman_log("xaman_login_conditional executed on page: $current_page, logout: " . ($is_logout ? 'true' : 'false') . ", loggedout: " . ($is_logged_out ? 'true' : 'false'));

    // Session-fix C (Aug 2026): resolve identity from the SAME source of truth as
    // header.php (Fix A). This block previously read ONLY the soft xrpl_account cookie,
    // so a user holding a soft cookie but NO session token was told "You are logged in!"
    // and bounced off /login/ to the home page -- where the header, which follows the
    // PROVEN token, rendered them logged OUT. Two sources of truth pointing opposite
    // ways, and the user could never reach a login button to recover. Token-only now
    // means token or nothing here too, so /login/ and the header always agree.
    //
    // SECURITY: the old else-branch logged json_encode($_COOKIE) to the webhook log,
    // which after the 2b flip writes LIVE httponly imc_session TOKEN VALUES to a file on
    // disk -- anyone with log access could replay one and hijack the session. The cookie
    // dump is removed; the log line keeps its diagnostic value without the secrets.
    $account = '';
    if (!$is_logout && !$is_logged_out) {
        if (defined('IMC_SESSION_TOKEN_ONLY') && IMC_SESSION_TOKEN_ONLY) {
            $account = function_exists('imc_session_resolve_wallet') ? imc_session_resolve_wallet() : '';
            xaman_log($account
                ? "Session token resolved: $account on page: $current_page"
                : "No valid session token on page: $current_page - showing login options");
        } elseif (isset($_COOKIE['xrpl_account']) && preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $_COOKIE['xrpl_account'])) {
            // Pre-flip parity: while the flag is false the soft cookie is identity,
            // exactly as before, so the flip stays fully reversible.
            $account = sanitize_text_field($_COOKIE['xrpl_account']);
            xaman_log("Cookie detected: xrpl_account=$account on page: $current_page");
        } else {
            xaman_log("No valid xrpl_account cookie detected on page: $current_page");
        }
    }

    ob_start();
    if ($account && !$is_logout && !$is_logged_out) {
        // User is logged in and not logging out
        xaman_log("User logged in with account: $account on page: $current_page");
        ?>
        <div class="xaman-logged-in-message" style="text-align: center; padding: 20px; color: white;">
            <p style="font-size: 18px; margin-bottom: 15px;">You are logged in! Explore our marketplace.</p>
            <a href="<?php echo esc_url(home_url('/')); ?>" class="xaman-profile-btn" style="padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; display: inline-block;">Go to Marketplace</a>
        </div>
        <?php
        // Redirect to home if on login page
        if (strpos($current_page, '/login') !== false) {
            xaman_log("Redirecting logged-in user from login page to home");
            ?>
            <script>
                window.location.href = 'https://imcollectibles.io/';
            </script>
            <?php
        }
    } else {
        // User is not logged in or logging out
        xaman_log("User not logged in or logging out, showing login button on page: $current_page");
        echo do_shortcode('[xaman_login]');
        // v563: Joey Wallet login is now LIVE and PUBLIC on /login (renders alongside Xaman for all logged-out visitors).
        echo do_shortcode('[joey_login]'); // Joey live on /login
    }
    return ob_get_clean();
}


/**
 * v565: Tag the /login page with a stable body class. The login page markup has
 * no #login-page id and no page-id-login class, so CSS needs a reliable hook to
 * swap the background video for the site gradient. Detects via is_page('login')
 * with an exact request-path fallback so it fires regardless of WP page config.
 */
add_filter('body_class', 'imc_add_login_body_class');
function imc_add_login_body_class($classes) {
    $path = strtolower(trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/'));
    if ((function_exists('is_page') && is_page('login')) || $path === 'login') {
        $classes[] = 'imc-login-page';
    }
    return $classes;
}


/**
 * Handle logout at init (before any output, to allow setcookie)
 */
add_action('init', 'handle_xaman_logout');

// Fix 5b (Aug 2026): the /login/ page renders identity-dependent output (the
// logged-in bounce) and must NEVER be cacheable by browsers, CDNs or LiteSpeed.
// Without this it was served with 'cache-control: public, max-age=300, s-maxage=600'.
function imc_login_page_nocache() {
    if (function_exists('is_page') && is_page('login')) {
        nocache_headers();
    }
}
add_action('template_redirect', 'imc_login_page_nocache');
function handle_xaman_logout() {
    if (strpos($_SERVER['REQUEST_URI'], '/') !== false && isset($_GET['logout']) && $_GET['logout'] === 'true') {
        xaman_log("Logout requested at init");
        // Session-auth 2a: tear down the httponly session token FIRST, regardless of
        // soft-cookie state, so logout always kills the server-bound session too.
        if ( function_exists('imc_session_clear') ) {
            imc_session_clear();
        }
        $account = isset($_COOKIE['xrpl_account']) ? sanitize_text_field($_COOKIE['xrpl_account']) : '';
        if ($account && preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
            // Clear cookie for multiple paths and domains to ensure compatibility
            $cookie_options = [
                'expires' => time() - 3600,
                'path' => '/',
                'domain' => '.imcollectibles.io',
                'secure' => true,
                'httponly' => false,
                'samesite' => 'Lax'
            ];
            setcookie('xrpl_account', '', $cookie_options); // Primary domain
            setcookie('xrpl_account', '', array_merge($cookie_options, ['domain' => 'imcollectibles.io'])); // Without leading dot
            setcookie('xrpl_account', '', array_merge($cookie_options, ['domain' => ''])); // No domain (current domain)
            unset($_COOKIE['xrpl_account']);
            xaman_log("Cleared xrpl_account cookie for account: $account at init");

            // Clear transients
            delete_transient('xaman_nft_' . md5($account));
            delete_transient('xaman_xft_' . md5($account));
            xaman_log("Cleared transients for account: $account");

            // Prevent caching of logout response
            nocache_headers();

            // Redirect to home page after logout
            wp_safe_redirect(home_url('/?loggedout=true'));
            exit;
        } else {
            xaman_log("Invalid or no account for logout at init: $account", 'ERROR');
            // Redirect even if no valid account to avoid staying on /profile/
            wp_safe_redirect(home_url('/?loggedout=true'));
            exit;
        }
    }
}




/**
 * AJAX handler for saving notification preferences
 */
add_action('wp_ajax_save_notification_prefs', 'handle_save_notification_prefs');
add_action('wp_ajax_nopriv_save_notification_prefs', 'handle_save_notification_prefs');
function handle_save_notification_prefs() {
    global $wpdb;
    $profiles_table = $wpdb->prefix . 'xaman_profiles';
    $subscriptions_table = $wpdb->prefix . 'xaman_subscriptions';

    // Log the request shape only -- never the body, cookies or headers.
    $log_data = [
        'keys'    => array_keys($_POST),
        // SEC: never log cookies or headers -- they carry the live session token.
        'action'  => $_POST['action'] ?? 'none',
        'user_id' => get_current_user_id(),
    ];
    xaman_log("save_notification_prefs request: " . json_encode($log_data));

    if ($_POST['action'] !== 'save_notification_prefs') {
        xaman_log("Invalid action: " . ($_POST['action'] ?? 'none'), 'ERROR');
        wp_send_json_error(['message' => 'Invalid action'], 400);
    }

    $nonce = $_POST['_wpnonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'save_notification_prefs')) {
        xaman_log("Invalid nonce: $nonce", 'ERROR');
        wp_send_json_error(['message' => 'Invalid nonce. Log in again.'], 403);
    }
    xaman_log("Nonce ok: $nonce");

    $account = sanitize_text_field($_POST['account'] ?? '');
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        xaman_log("Invalid account: $account", 'ERROR');
        wp_send_json_error(['message' => 'Invalid account'], 400);
    }

    // Phase 1 Batch 2 (Aug 2026): session-token identity via
    // imc_session_require_wallet(); the posted account must match the
    // session wallet (legacy-cookie fallback stays gated behind
    // IMC_SESSION_TOKEN_ONLY, as on every IMU surface).
    $np_auth = function_exists('imc_session_require_wallet')
        ? imc_session_require_wallet($account)
        : array('ok' => false, 'wallet' => '', 'error' => 'auth');
    if (empty($np_auth['ok'])) {
        xaman_log("save_notification_prefs rejected: " . ((($np_auth['error'] ?? '') === 'mismatch') ? "account mismatch (posted != session)" : "not authenticated"), 'ERROR');
        wp_send_json_error(['message' => (($np_auth['error'] ?? '') === 'mismatch') ? 'Account mismatch. Log in again.' : 'Not authenticated. Log in again.'], 403);
    }
    $account = $np_auth['wallet'];

    $data = [
        'notify_events' => isset($_POST['notify_events']) ? 1 : 0,
        'notify_claims' => isset($_POST['notify_claims']) ? 1 : 0,
        'notify_claims_received' => isset($_POST['notify_claims_received']) ? 1 : 0,
        'notify_messages' => isset($_POST['notify_messages']) ? 1 : 0,
        'email' => sanitize_email($_POST['email'] ?? ''),
    ];
    xaman_log("Data for $account: " . json_encode($data));

    $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $profiles_table WHERE xrpl_account = %s", $account));

    try {
        if ($existing) {
            $result = $wpdb->update($profiles_table, $data, ['xrpl_account' => $account]);
            xaman_log("Update for $account: " . ($result !== false ? "rows $result" : "failed: {$wpdb->last_error}"));
        } else {
            $data['xrpl_account'] = $account;
            $data['created_at'] = current_time('mysql', 1);
            $data['name'] = $account;
            $data['profile_pic_url'] = '/wp-content/uploads/fallback-nft.svg';
            $result = $wpdb->insert($profiles_table, $data);
            xaman_log("Insert for $account: " . ($result !== false ? "id {$wpdb->insert_id}" : "failed: {$wpdb->last_error}"));
        }

        if ($wpdb->last_error) throw new Exception("DB failed: {$wpdb->last_error}");

        // Verify saved data
        $verify = $wpdb->get_row($wpdb->prepare("SELECT notify_events, notify_claims, notify_claims_received, notify_messages, email FROM $profiles_table WHERE xrpl_account = %s", $account));
        xaman_log("Verify for $account: " . json_encode($verify));

        // Check if any notifications enabled
        $enabled = $data['notify_events'] || $data['notify_claims'] || $data['notify_claims_received'] || $data['notify_messages'];
        xaman_log("Notifications enabled for $account: " . ($enabled ? 'yes' : 'no'));

        $needs_subscription = false;
        if ($enabled) {
            $sub_status = false;

           // OneSignal subscription check (replaces PushEngage)
            if (defined('ONESIGNAL_APP_ID')) {
                global $wpdb;
                $onesignal_table = $wpdb->prefix . 'onesignal_subscriptions';
                if ($wpdb->get_var("SHOW TABLES LIKE '$onesignal_table'") === $onesignal_table) {
                    $sub_status = $wpdb->get_var($wpdb->prepare(
                        "SELECT is_active FROM $onesignal_table WHERE xrpl_account = %s",
                        $account
                    ));
                    $sub_status = (bool) $sub_status;
                    xaman_log("OneSignal check for $account: " . ($sub_status ? 'subscribed' : 'not subscribed'));
                } else {
                    xaman_log("OneSignal subscriptions table not found", 'ERROR');
                }
            } else {
                xaman_log("No ONESIGNAL_APP_ID defined", 'ERROR');
            }

            // Native check if no PushEngage sub
            if (!$sub_status) {
                $native_sub = $wpdb->get_var($wpdb->prepare("SELECT subscription FROM $subscriptions_table WHERE xrpl_account = %s", $account));
                $sub_status = !empty($native_sub);
                xaman_log("Native check for $account: " . ($sub_status ? 'subscribed' : 'not subscribed'));
            }

            $needs_subscription = !$sub_status;
            xaman_log("Set needs_subscription for $account: " . ($needs_subscription ? 'true' : 'false'));
        }

        wp_send_json_success(['message' => 'Preferences updated!', 'data' => $data, 'needs_subscription' => $needs_subscription]);
    } catch (Exception $e) {
        xaman_log("Error in save for $account: " . $e->getMessage(), 'ERROR');
        wp_send_json_error(['message' => 'Server error: ' . $e->getMessage()], 500);
    }
}





// =============================================================================
// NFT COUNTING UTILITIES - Consolidated logic to avoid duplication
// =============================================================================

/**
 * Eligible NFT collection definitions - Single source of truth
 * Format: 'key' => ['issuer' => '...', 'taxon' => X, 'reward' => Y]
 */
function get_eligible_nft_collections() {
    return [
        'guardians' => [
            'issuer' => 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR',
            'taxon' => 0,
            'reward' => 500
        ],
        'protectors_freq' => [
            'issuer' => 'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga',
            'taxon' => 717825,
            'reward' => 50
        ],
        'protectors_ledger' => [
            'issuer' => 'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt',
            'taxon' => 1056369418,
            'reward' => 50
        ],
        'protectors_lasVegas' => [
            'issuer' => 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR',
            'taxon' => 777,
            'reward' => 200
        ],
        'protectors_firepit' => [
            'issuer' => 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR',
            'taxon' => 666,
            'reward' => 550
        ]
    ];
}

/**
 * Extract NFT counts from xumm-proxy.php response
 * Uses the pre-calculated counts from the proxy to avoid re-counting
 * 
 * @param array $proxy_response The response from xumm-proxy.php
 * @return array Associative array of counts
 */
function get_nft_counts_from_proxy($proxy_response) {
    $result = $proxy_response['result'] ?? [];
    
    return [
        'guardians' => (int)($result['guardians'] ?? 0),
        'protectors_freq' => (int)($result['frequencies'] ?? 0),  // Note: proxy uses 'frequencies'
        'protectors_ledger' => (int)($result['ledger'] ?? 0),
        'protectors_lasVegas' => (int)($result['lasvegas'] ?? 0),
        'protectors_firepit' => (int)($result['firepit'] ?? 0),
        'total' => (int)($result['total'] ?? 0)
    ];
}

/**
 * Filter NFTs to only eligible collections and return both counts and display NFTs
 * Use this when you need the actual NFT objects (e.g., for profile display)
 * 
 * @param array $nfts Array of NFT objects from account_nfts
 * @return array ['counts' => [...], 'display_nfts' => [...]]
 */
function filter_eligible_nfts($nfts) {
    $collections = get_eligible_nft_collections();
    
    $counts = [
        'guardians' => 0,
        'protectors_freq' => 0,
        'protectors_ledger' => 0,
        'protectors_lasVegas' => 0,
        'protectors_firepit' => 0
    ];
    
    $display_nfts = [];
    
    foreach ($nfts as $nft) {
        $issuer = $nft['Issuer'] ?? 'unknown';
        $taxon = isset($nft['NFTokenTaxon']) ? (int)$nft['NFTokenTaxon'] : -1;
        
        foreach ($collections as $key => $collection) {
            if ($issuer === $collection['issuer'] && $taxon === $collection['taxon']) {
                $counts[$key]++;
                $display_nfts[] = $nft;
                break; // NFT matched, no need to check other collections
            }
        }
    }
    
    return [
        'counts' => $counts,
        'display_nfts' => $display_nfts
    ];
}

/**
 * Check if an NFT belongs to an eligible collection
 * 
 * @param string $issuer NFT issuer address
 * @param int $taxon NFT taxon
 * @return string|false Collection key if eligible, false otherwise
 */
function get_nft_collection_key($issuer, $taxon) {
    $collections = get_eligible_nft_collections();
    
    foreach ($collections as $key => $collection) {
        if ($issuer === $collection['issuer'] && $taxon === $collection['taxon']) {
            return $key;
        }
    }
    
    return false;
}

// =============================================================================
// END NFT COUNTING UTILITIES
// =============================================================================


// Calculate XFT rewards
// =============================================================================
// REPLACE calculate_xft_rewards() at line 2311
// =============================================================================

function calculate_xft_rewards($guardians, $protectors_freq, $protectors_ledger, $protectors_lasVegas, $protectors_firepit, $xft_balance) {
    $protector_total = $protectors_freq + $protectors_ledger;
    
    // 60% reduced rates (40% of original):
    // Protectors: 50 → 20 | Guardians: 500 → 200 | Vegas: 200 → 80 | Firepit: 550 → 220
    $nft_rewards = ($protector_total * 20) + ($guardians * 200) + ($protectors_lasVegas * 80) + ($protectors_firepit * 220);
    
    // XFT holding rewards (60% reduced):
    // 1M+: 600 → 240 | 500k+: 250 → 100 | 100k+: 50 → 20
    $xft_rewards = 0;
    if ($xft_balance >= 1000000) $xft_rewards = 240;
    elseif ($xft_balance >= 500000) $xft_rewards = 100;
    elseif ($xft_balance >= 100000) $xft_rewards = 20;
    
    // Apply active boost if exists
    $multiplier = 1.0;
    $account = function_exists('imc_session_wallet') ? imc_session_wallet() : '';
    if ($account && function_exists('imu_get_active_boost')) {
        $boost = imu_get_active_boost($account, 'fountain');
        if ($boost['active']) {
            $multiplier = $boost['multiplier'];
        }
    }
    
    return round(($nft_rewards + $xft_rewards) * $multiplier, 2);
}


// =============================================================================
// ADD THESE NEW FUNCTIONS right after calculate_xft_rewards()
// =============================================================================

/**
 * Create reward boosts database table
 */
function imu_create_reward_boosts_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'reward_boosts';
    $charset = $wpdb->get_charset_collate();
    
    // Only create if doesn't exist
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
        return true;
    }
    
    $sql = "CREATE TABLE $table (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account VARCHAR(50) NOT NULL,
        boost_type ENUM('fountain', 'game') NOT NULL,
        tier ENUM('bronze', 'silver', 'gold') NOT NULL,
        multiplier DECIMAL(3,2) NOT NULL,
        duration ENUM('week', 'month') NOT NULL,
        points_spent INT NOT NULL,
        activated_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_account_type (account, boost_type),
        INDEX idx_expires (expires_at)
    ) $charset;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    return $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
}
add_action('init', 'imu_create_reward_boosts_table');

/**
 * Get active boost for an account
 * 
 * @param string $account XRPL account address
 * @param string $boost_type 'fountain' or 'game'
 * @return array ['active' => bool, 'multiplier' => float, 'tier' => string, etc.]
 */
function imu_get_active_boost($account, $boost_type) {
    global $wpdb;
    $table = $wpdb->prefix . 'reward_boosts';
    
    // Check table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
        return ['active' => false, 'multiplier' => 1.0];
    }
    
    // Get highest active multiplier for this account/type
    $boost = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table 
         WHERE account = %s AND boost_type = %s AND expires_at > NOW() 
         ORDER BY multiplier DESC LIMIT 1",
        $account, $boost_type
    ), ARRAY_A);
    
    if ($boost) {
        return [
            'active' => true,
            'multiplier' => (float)$boost['multiplier'],
            'tier' => $boost['tier'],
            'duration' => $boost['duration'],
            'expires_at' => $boost['expires_at'],
            'time_remaining' => strtotime($boost['expires_at']) - time()
        ];
    }
    
    return ['active' => false, 'multiplier' => 1.0];
}

/**
 * Activate a boost pass
 * 
 * @param string $account XRPL account
 * @param string $boost_type 'fountain' or 'game'
 * @param string $tier 'bronze', 'silver', or 'gold'
 * @param string $duration 'week' or 'month'
 * @param int $points_spent Points cost for logging
 * @return bool Success/failure
 */
function imu_activate_boost($account, $boost_type, $tier, $duration, $points_spent) {
    global $wpdb;
    $table = $wpdb->prefix . 'reward_boosts';
    
    // Ensure table exists
    imu_create_reward_boosts_table();
    
    // Define multipliers: bronze=1.25, silver=1.5, gold=2.0
    $multipliers = [
        'bronze' => 1.25,
        'silver' => 1.50,
        'gold' => 2.00
    ];
    
    // Define durations
    $durations = [
        'week' => '+7 days',
        'month' => '+30 days'
    ];
    
    if (!isset($multipliers[$tier]) || !isset($durations[$duration])) {
        return false;
    }
    
    $now = current_time('mysql');
    $expires = date('Y-m-d H:i:s', strtotime($now . ' ' . $durations[$duration]));
    
    $result = $wpdb->insert($table, [
        'account' => $account,
        'boost_type' => $boost_type,
        'tier' => $tier,
        'multiplier' => $multipliers[$tier],
        'duration' => $duration,
        'points_spent' => $points_spent,
        'activated_at' => $now,
        'expires_at' => $expires
    ], ['%s', '%s', '%s', '%f', '%s', '%d', '%s', '%s']);
    
    if ($result !== false) {
        // Log activation
        if (function_exists('xaman_log')) {
            xaman_log("Boost activated: $tier $boost_type ($duration) for $account, expires: $expires");
        }
        return true;
    }
    
    return false;
}

/**
 * Get boost status display info (for showing in UI)
 */
function imu_get_boost_status_html($account) {
    $fountain_boost = imu_get_active_boost($account, 'fountain');
    $game_boost = imu_get_active_boost($account, 'game');
    
    $html = '';
    
    if ($fountain_boost['active']) {
        $tier_labels = ['bronze' => '🥉 Bronze', 'silver' => '🥈 Silver', 'gold' => '🥇 Gold'];
        $time_left = human_time_diff(time(), strtotime($fountain_boost['expires_at']));
        $html .= '<div class="boost-badge fountain-boost">';
        $html .= $tier_labels[$fountain_boost['tier']] . ' Fountain Boost Active (' . $fountain_boost['multiplier'] . 'x)';
        $html .= '<span class="boost-expires">Expires in ' . $time_left . '</span>';
        $html .= '</div>';
    }
    
    if ($game_boost['active']) {
        $tier_labels = ['bronze' => '🥉 Bronze', 'silver' => '🥈 Silver', 'gold' => '🥇 Gold'];
        $time_left = human_time_diff(time(), strtotime($game_boost['expires_at']));
        $html .= '<div class="boost-badge game-boost">';
        $html .= $tier_labels[$game_boost['tier']] . ' Game Boost Active (' . $game_boost['multiplier'] . 'x)';
        $html .= '<span class="boost-expires">Expires in ' . $time_left . '</span>';
        $html .= '</div>';
    }
    
    return $html;
}

// =============================================================================
// VPS GAME BOOST API ENDPOINT
// Allows the rewards service to query active game boosts.
// Secured by shared secret (BOOST_API_SECRET in wp-config.php + VPS env).
// =============================================================================
add_action('wp_ajax_get_game_boost', 'imu_get_game_boost_api');
add_action('wp_ajax_nopriv_get_game_boost', 'imu_get_game_boost_api');

function imu_get_game_boost_api() {
    header('Content-Type: application/json');
    
    // Verify shared secret
    $secret = sanitize_text_field($_POST['api_secret'] ?? '');
    $expected = defined('BOOST_API_SECRET') ? BOOST_API_SECRET : '';
    
    if (empty($expected) || !hash_equals($expected, $secret)) {
        xaman_log("Game boost API: Invalid or missing secret");
        http_response_code(403);
        wp_send_json_error(['error' => 'Unauthorized'], 403);
    }
    
    // Validate account
    $account = sanitize_text_field($_POST['account'] ?? '');
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        wp_send_json_error(['error' => 'Invalid account'], 400);
    }
    
    // Validate boost_type
    $boost_type = sanitize_text_field($_POST['boost_type'] ?? 'game');
    if (!in_array($boost_type, ['fountain', 'game'])) {
        wp_send_json_error(['error' => 'Invalid boost type'], 400);
    }
    
    // Look up active boost
    if (!function_exists('imu_get_active_boost')) {
        wp_send_json_error(['error' => 'Boost system not available'], 500);
    }
    
    $boost = imu_get_active_boost($account, $boost_type);
    
    xaman_log("Game boost API: account=$account, type=$boost_type, active=" . ($boost['active'] ? 'YES' : 'NO') . ", multiplier=" . ($boost['multiplier'] ?? 1.0));
    
    wp_send_json_success([
        'active' => $boost['active'],
        'multiplier' => (float)($boost['multiplier'] ?? 1.0),
        'tier' => $boost['tier'] ?? null,
        'expires_at' => $boost['expires_at'] ?? null,
        'time_remaining' => $boost['time_remaining'] ?? null
    ]);
}


function generate_claim_nonce($account) {
    $nonce = bin2hex(random_bytes(16));
    $response = wp_remote_post(defined('IMC_XFT_DISTRIBUTOR_URL') ? IMC_XFT_DISTRIBUTOR_URL : '', [
        'body' => ['action' => 'generate_nonce', 'account' => $account, 'nonce' => $nonce],
        'timeout' => 15,
        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded']
    ]);
    xaman_log("Nonce generation request for $account: " . print_r($response, true));
    if (is_wp_error($response)) {
        xaman_log("Nonce generation WP_Error for $account: " . $response->get_error_message());
        return false;
    }
    if (wp_remote_retrieve_response_code($response) !== 200) {
        xaman_log("Nonce generation failed for $account: HTTP " . wp_remote_retrieve_response_code($response));
        return false;
    }
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($body['status']) && $body['status'] === 'nonce_stored') {
        xaman_log("Nonce successfully stored for $account: $nonce");
        return $nonce;
    }
    xaman_log("Nonce generation response invalid for $account: " . json_encode($body));
    return false;
}

function check_pending_xft_claim($account) {
    $response = wp_remote_post(defined('IMC_XFT_DISTRIBUTOR_URL') ? IMC_XFT_DISTRIBUTOR_URL : '', [
        'body' => ['action' => 'check_pending', 'account' => $account],
        'timeout' => 15,
        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded']
    ]);
    xaman_log("Check pending claim for $account: " . print_r($response, true));
    if (is_wp_error($response)) {
        return ['error' => $response->get_error_message()];
    }
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (wp_remote_retrieve_response_code($response) === 409) {
        return ['pending' => true, 'message' => $body['error'] ?? 'Claim in progress'];
    }
    return ['pending' => false];
}

// Handle XFT claim
function handle_xft_claim() {
    xaman_log("handle_xft_claim triggered with POST: " . json_encode($_POST));
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action']) || $_POST['action'] !== 'handle_xft_claim' || !isset($_POST['claim_xft']) || !isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'xft_claim')) {
        xaman_log("Invalid request or nonce verification failed: " . json_encode($_POST));
        wp_safe_redirect(add_query_arg('error', urlencode('Invalid request'), home_url('/frequency-fountain/')));
        exit;
    }

    global $wpdb;
    $xft_auth = function_exists('imc_session_require_wallet')
        ? imc_session_require_wallet('')
        : array('ok' => false, 'wallet' => '');
    $account = !empty($xft_auth['ok']) ? $xft_auth['wallet'] : '';
    $claim_table = $wpdb->prefix . 'xft_claims';
    $profiles_table = $wpdb->prefix . 'xaman_profiles';
    $lock_key = 'xft_claim_lock_' . md5($account);
    $lock_timeout = 90; // v111: Increased from 30s — VPS XRPL tx can take 45s+ to confirm

    xaman_log("Starting XFT claim for account: $account");

    if (empty($account) || !preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        xaman_log("Invalid or missing account: $account");
        wp_safe_redirect(add_query_arg('error', urlencode('Invalid XRPL account'), home_url('/frequency-fountain/')));
        exit;
    }

    if (get_transient($lock_key)) {
        xaman_log("Claim already in progress for $account");
        wp_safe_redirect(add_query_arg('error', urlencode('Claim already in progress. Please wait.'), home_url('/frequency-fountain/')));
        exit;
    }
    set_transient($lock_key, true, $lock_timeout);
    xaman_log("Lock set for $account");
    
    // Before line 3680, add a pre-check to VPS
$vps_status = wp_remote_post(defined('IMC_XFT_DISTRIBUTOR_URL') ? IMC_XFT_DISTRIBUTOR_URL : '', [
    'body' => ['action' => 'check_status', 'account' => $account],
    'timeout' => 10
]);
// Only proceed if VPS confirms eligible

    $last_claim = $wpdb->get_var($wpdb->prepare("SELECT last_claim FROM $claim_table WHERE xrpl_account = %s", $account));
    $time_left = $last_claim ? (24 * 3600 - (current_time('timestamp', 1) - strtotime($last_claim))) : 0;
    if ($time_left > 0) {
        delete_transient($lock_key);
        xaman_log("Cooldown active for $account: $time_left seconds remaining");
        wp_safe_redirect(add_query_arg('error', urlencode("Please wait " . gmdate("H:i:s", $time_left) . " before claiming again."), home_url('/frequency-fountain/')));
        exit;
    }

    $nonce = generate_claim_nonce($account);
    if (!$nonce) {
        delete_transient($lock_key);
        xaman_log("Failed to generate nonce for $account");
        wp_safe_redirect(add_query_arg('error', urlencode('Failed to generate claim nonce'), home_url('/frequency-fountain/')));
        exit;
    }
    xaman_log("Generated nonce for $account: $nonce");

    // Fetch NFTs with retry
    $transient_key = 'xaman_nft_' . md5($account);
    $nfts = false;
    $nft_attempts = 0;
    $max_attempts = 2;
    while ($nft_attempts < $max_attempts && $nfts === false) {
        $url = "https://imcollectibles.io/xumm-proxy.php?account=$account&t=" . time();
        $response = wp_remote_get($url, ['timeout' => 120]);
        if (is_wp_error($response)) {
            xaman_log("NFT fetch attempt $nft_attempts failed for $account: " . $response->get_error_message());
        } else {
            $nft_data = wp_remote_retrieve_body($response);
            $nfts = json_decode($nft_data, true) ?: [];
            if (isset($nfts['result']['account_nfts'])) {
                // Check for error status indicating partial/failed data
                if (isset($nfts['result']['status']) && $nfts['result']['status'] === 'error') {
                    xaman_log("NFT fetch returned error status for $account: " . ($nfts['result']['error'] ?? 'Unknown'));
                    $nfts = false;
                } else {
                    set_transient($transient_key, $nfts, HOUR_IN_SECONDS);
                    xaman_log("Fetched and cached NFT data for $account, attempt $nft_attempts");
                }
            } else {
                $nfts = false;
                xaman_log("Invalid NFT data for $account, attempt $nft_attempts: " . $nft_data);
            }
        }
        $nft_attempts++;
        if ($nfts === false && $nft_attempts < $max_attempts) {
            sleep(1);
        }
    }
    if ($nfts === false) {
        delete_transient($lock_key);
        xaman_log("Failed to fetch NFT data for $account after $max_attempts attempts");
        wp_safe_redirect(add_query_arg('error', urlencode('Failed to fetch NFT data'), home_url('/frequency-fountain/')));
        exit;
    }

// Use pre-calculated counts from proxy response (no re-counting needed!)
    $guardians = 0;
    $protectors_firepit = 0;
    $protectors_freq = 0;
    $protectors_ledger = 0;
    $protectors_lasVegas = 0;
    
    if (isset($nfts['result']['guardians'])) {
        // Proxy already counted - use those values directly
        $proxy_counts = get_nft_counts_from_proxy($nfts);
        $guardians = $proxy_counts['guardians'];
        $protectors_freq = $proxy_counts['protectors_freq'];
        $protectors_ledger = $proxy_counts['protectors_ledger'];
        $protectors_lasVegas = $proxy_counts['protectors_lasVegas'];
        $protectors_firepit = $proxy_counts['protectors_firepit'];
        
        xaman_log("NFT counts in claim for $account (from proxy) - Guardians: $guardians, Freq: $protectors_freq, Ledger: $protectors_ledger, Vegas: $protectors_lasVegas, Firepit: $protectors_firepit");
    } elseif (isset($nfts['result']['account_nfts'])) {
        // Fallback: calculate locally if proxy doesn't return counts
        $filtered = filter_eligible_nfts($nfts['result']['account_nfts']);
        $counts = $filtered['counts'];
        
        $guardians = $counts['guardians'];
        $protectors_freq = $counts['protectors_freq'];
        $protectors_ledger = $counts['protectors_ledger'];
        $protectors_lasVegas = $counts['protectors_lasVegas'];
        $protectors_firepit = $counts['protectors_firepit'];
        
        xaman_log("NFT counts in claim for $account (local fallback) - Guardians: $guardians, Freq: $protectors_freq, Ledger: $protectors_ledger, Vegas: $protectors_lasVegas, Firepit: $protectors_firepit");
    } else {
        xaman_log("No NFTs found for $account");
    }
    // Fetch XFT balance with retry
    $xft_balance = 0;
    $xft_attempts = 0;
    $max_attempts = 3;
    $xrpl_api_url = "https://s1.ripple.com:51234/";
    $request = ['method' => 'account_lines', 'params' => [['account' => $account, 'ledger_index' => 'current']]];
    while ($xft_attempts < $max_attempts && $xft_balance == 0) {
        $response = wp_remote_post($xrpl_api_url, [
            'body' => json_encode($request),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 20
        ]);
        if (is_wp_error($response)) {
            xaman_log("XFT balance fetch attempt $xft_attempts failed for $account: " . $response->get_error_message());
        } elseif (wp_remote_retrieve_response_code($response) !== 200) {
            xaman_log("XFT balance fetch attempt $xft_attempts failed for $account: HTTP " . wp_remote_retrieve_response_code($response));
        } else {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($body['result']['lines'])) {
                foreach ($body['result']['lines'] as $line) {
                    if ($line['account'] === 'rGpnoqYLzWytxwQhhz715nRbqyCHM7zhxt' && $line['currency'] === 'XFT') {
                        $xft_balance = floatval($line['balance']);
                        xaman_log("Fetched XFT balance for $account, attempt $xft_attempts: $xft_balance");
                        break;
                    }
                }
            } else {
                xaman_log("Invalid XFT balance response for $account, attempt $xft_attempts: " . wp_remote_retrieve_body($response));
            }
        }
        $xft_attempts++;
        if ($xft_balance == 0 && $xft_attempts < $max_attempts) {
            sleep(1);
        }
    }
    if ($xft_balance == 0 && ($guardians + $protectors_freq + $protectors_ledger + $protectors_lasVegas + $protectors_firepit) == 0) {
        delete_transient($lock_key);
        xaman_log("No XFT balance or NFTs for $account after $max_attempts attempts");
        wp_safe_redirect(add_query_arg('error', urlencode('No rewards available to claim'), home_url('/frequency-fountain/')));
        exit;
    }
    set_transient('xaman_xft_' . md5($account), $xft_balance, HOUR_IN_SECONDS);
    xaman_log("Cached XFT balance for $account: $xft_balance");

    $pending_check = check_pending_xft_claim($account);
    if ($pending_check['pending']) {
        delete_transient($lock_key);
        xaman_log("Pending claim detected for $account: " . $pending_check['message']);
        wp_safe_redirect(add_query_arg('error', urlencode($pending_check['message']), home_url('/frequency-fountain/')));
        exit;
    }

    $rewards = calculate_xft_rewards($guardians, $protectors_freq, $protectors_ledger, $protectors_lasVegas, $protectors_firepit, $xft_balance);
    xaman_log("Calculated rewards for $account: $rewards (NFTs=" . (($protectors_freq + $protectors_ledger) * 50 + $protectors_lasVegas * 200 + $protectors_firepit * 550 + $guardians * 500) . ", XFT=" . ($xft_balance >= 1000000 ? 600 : ($xft_balance >= 500000 ? 250 : ($xft_balance >= 100000 ? 50 : 0))) . ")");

    if ($rewards <= 0) {
        delete_transient($lock_key);
        xaman_log("No rewards available for $account");
        wp_safe_redirect(add_query_arg('error', urlencode('No rewards available to claim'), home_url('/frequency-fountain/')));
        exit;
    }

    $response = wp_remote_post(defined('IMC_XFT_DISTRIBUTOR_URL') ? IMC_XFT_DISTRIBUTOR_URL : '', [
        'body' => ['account' => $account, 'amount' => $rewards, 'nonce' => $nonce],
        'timeout' => 30,
        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded']
    ]);
    xaman_log("Claim request sent for $account: account=$account, amount=$rewards, nonce=$nonce");

    if (is_wp_error($response)) {
        delete_transient($lock_key);
        xaman_log("WP_Error sending XFT for $account: " . $response->get_error_message());
       wp_safe_redirect(add_query_arg('error', urlencode('Failed to send XFT: ' . $response->get_error_message()), home_url('/frequency-fountain/')));
        exit;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = json_decode(wp_remote_retrieve_body($response), true);
    xaman_log("VPS response for $account - Code: $response_code, Body: " . json_encode($response_body));

    if ($response_code === 200 && isset($response_body['tx_result']) && $response_body['tx_result'] === 'tesSUCCESS') {
        $wpdb->replace($claim_table, ['xrpl_account' => $account, 'last_claim' => current_time('mysql', 1)]);
        send_notification($account, 'claim_success', ['rewards' => $rewards]); // Updated to 'claim_success'
        delete_transient($lock_key);
        xaman_log("Successfully claimed $rewards XFT for $account");
        wp_safe_redirect(add_query_arg('success', urlencode($response_body['message'] ?? 'XFT claimed successfully'), home_url('/frequency-fountain/')));
    } elseif ($response_code === 429) {
        delete_transient($lock_key);
        xaman_log("Cooldown active for $account: " . ($response_body['error'] ?? 'Rate limit exceeded'));
        wp_safe_redirect(add_query_arg('error', urlencode($response_body['error'] ?? 'Please wait before claiming again.'), home_url('/frequency-fountain/')));
    } elseif ($response_code === 409) {
        delete_transient($lock_key);
        xaman_log("Pending claim for $account: " . ($response_body['error'] ?? 'Claim in progress'));
        wp_safe_redirect(add_query_arg('error', urlencode($response_body['error'] ?? 'Claim already in progress. Please wait.'), home_url('/frequency-fountain/')));
    } else {
        delete_transient($lock_key);
        xaman_log("Failed to send XFT for $account: Code=$response_code, Body=" . json_encode($response_body));
        wp_safe_redirect(add_query_arg('error', urlencode('Failed to send XFT: ' . ($response_body['error'] ?? 'Unknown error')), home_url('/frequency-fountain/')));
    }
    exit;
}
add_action('admin_post_handle_xft_claim', 'handle_xft_claim');
add_action('admin_post_nopriv_handle_xft_claim', 'handle_xft_claim');


// Add admin submenu under Tools for clearing XFT claim transients
function add_clear_xft_transients_menu() {
    add_submenu_page(
        'tools.php',
        'Clear XFT Claim Transients',
        'Clear XFT Transients',
        'manage_options',
        'clear-xft-transients',
        'render_clear_xft_transients_page'
    );
}
add_action('admin_menu', 'add_clear_xft_transients_menu');

// Render the admin page
function render_clear_xft_transients_page() {
    ?>
    <div class="wrap">
        <h1>Clear XFT Claim Transients</h1>
        <p>Use this tool to clear stuck claim transients for users experiencing the "Claim Already In Process" error. Enter the user's XRPL account address below.</p>
        <?php
        // Display success or error messages
        if (isset($_GET['message'])) {
            $message = sanitize_text_field($_GET['message']);
            $status = isset($_GET['status']) && $_GET['status'] === 'error' ? 'error' : 'updated';
            echo '<div id="message" class="' . esc_attr($status) . ' notice is-dismissible"><p>' . esc_html(urldecode($message)) . '</p></div>';
        }
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="clear_xft_transients">
            <?php wp_nonce_field('clear_xft_transients_nonce', 'clear_xft_transients_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="xrpl_account">XRPL Account Address</label></th>
                    <td>
                        <input type="text" id="xrpl_account" name="xrpl_account" class="regular-text" placeholder="Enter XRPL address (e.g., r...)">
                        <p class="description">Enter the user's XRP address (starts with 'r', 25-34 characters).</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Clear Transients'); ?>
        </form>
    </div>
    <?php
}

// Handle form submission
function handle_clear_xft_transients() {
    if (!current_user_can('manage_options') || !isset($_POST['clear_xft_transients_nonce']) || !wp_verify_nonce($_POST['clear_xft_transients_nonce'], 'clear_xft_transients_nonce')) {
        wp_die('You do not have permission to perform this action or the nonce is invalid.');
    }

    $xrpl_account = sanitize_text_field($_POST['xrpl_account'] ?? '');
    $redirect_url = admin_url('tools.php?page=clear-xft-transients');

    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $xrpl_account)) {
        wp_safe_redirect(add_query_arg([
            'message' => urlencode('Invalid XRPL address provided.'),
            'status' => 'error'
        ], $redirect_url));
        exit;
    }

    $lock_key = 'xft_claim_lock_' . md5($xrpl_account);
    $nft_transient_key = 'xaman_nft_' . md5($xrpl_account);
    $xft_transient_key = 'xaman_xft_' . md5($xrpl_account);

    $cleared = false;
    $messages = [];

    // Clear claim lock transient
    if (delete_transient($lock_key)) {
        $messages[] = "Claim lock transient cleared for address: $xrpl_account";
        xaman_log("Admin cleared transient $lock_key for XRPL address: $xrpl_account");
        $cleared = true;
    } else {
        $messages[] = "No claim lock transient found for address: $xrpl_account";
        xaman_log("No transient found for $lock_key when clearing for XRPL address: $xrpl_account");
    }

    // Optionally clear NFT and XFT transients
    if (delete_transient($nft_transient_key)) {
        $messages[] = "NFT transient cleared for address: $xrpl_account";
        xaman_log("Admin cleared transient $nft_transient_key for XRPL address: $xrpl_account");
        $cleared = true;
    }
    if (delete_transient($xft_transient_key)) {
        $messages[] = "XFT balance transient cleared for address: $xrpl_account";
        xaman_log("Admin cleared transient $xft_transient_key for XRPL address: $xrpl_account");
        $cleared = true;
    }

    $message = $cleared ? implode('. ', $messages) : 'No transients found to clear for address: ' . $xrpl_account;
    $status = $cleared ? 'updated' : 'error';

    wp_safe_redirect(add_query_arg([
        'message' => urlencode($message),
        'status' => $status
    ], $redirect_url));
    exit;
}
add_action('admin_post_clear_xft_transients', 'handle_clear_xft_transients');

// Prevent caching on premium pages
function prevent_premium_page_cache() {
    $premium_pages = ['protector-trading-hub', 'premium-videos', 'champion-of-frequencies'];
    if (is_page($premium_pages)) {
        define('DONOTCACHEPAGE', true);
        xaman_log("Cache prevention enabled for page: " . get_the_title());
    }
}
add_action('wp', 'prevent_premium_page_cache');



// Redirect logo to /home/
function redirect_logo_to_home() {
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            const logoLink = document.querySelector('.site-logo a');
            if (logoLink) {
                logoLink.addEventListener('click', function(e) {
                    e.preventDefault();
                    window.location.href = '<?php echo esc_url(home_url('/home/')); ?>';
                });
            } else {
                console.log('Logo link (.site-logo a) not found on this page.');
            }
        });
    </script>
    <?php
}
add_action('wp_footer', 'redirect_logo_to_home');

// Remove Astra's default primary menu rendering (keep this)
function remove_astra_primary_navigation() {
    remove_action('astra_masthead', 'astra_primary_navigation', 10);
    remove_action('astra_header', 'astra_primary_navigation', 10);
    remove_action('astra_masthead_content', 'astra_primary_navigation', 10);
    xaman_log("remove_astra_primary_navigation executed");
    add_action('wp_head', function() {
        ?>
        <style>
            .main-navigation { display: none !important; }
        </style>
        <?php
        xaman_log("Added CSS to hide .main-navigation on page: " . get_the_title());
    });
}
add_action('after_setup_theme', 'remove_astra_primary_navigation');

// Prevent header.php from loading by overriding get_header
function override_default_header() {
    // Do nothing, letting custom HTML blocks handle the header
    xaman_log("Default header.php suppressed on page: " . get_the_title());
}
add_action('get_header', 'override_default_header', 1);

// Add click support for dropdown menu (keep this for your custom HTML dropdowns)
function add_dropdown_click_support() {
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const dropdowns = document.querySelectorAll('.dropdown');
        dropdowns.forEach(dropdown => {
            const dropbtn = dropdown.querySelector('.dropbtn');
            const content = dropdown.querySelector('.dropdown-content');
            let isOpen = false;
            dropbtn.addEventListener('click', function(e) {
                e.preventDefault();
                isOpen = !isOpen;
                content.style.display = isOpen ? 'block' : 'none';
            });
            document.addEventListener('click', function(e) {
                if (!dropdown.contains(e.target) && isOpen) {
                    isOpen = false;
                    content.style.display = 'none';
                }
            });
            dropdown.addEventListener('mouseleave', function() {
                if (!isOpen) {
                    content.style.display = '';
                }
            });
        });
    });
    </script>
    <?php
}
add_action('wp_footer', 'add_dropdown_click_support');


// Debug cookie status
// Debug cookie status — only active when WP_DEBUG is enabled
if (defined('WP_DEBUG') && WP_DEBUG) {
}




// Add admin menu for push notification testing
add_action('admin_menu', function () {
    add_menu_page(
        'Push Notification Tester',
        'Push Tester',
        'manage_options',
        'push-notification-tester',
        'render_push_notification_tester_page',
        'dashicons-email-alt',
        100
    );
});

function render_push_notification_tester_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized access');
    }

    global $wpdb;
    $message = '';
    $error = '';
    $send_nonce = wp_create_nonce('send_test_notification');
    $check_nonce = wp_create_nonce('check_subscription_status');

    // Handle form submission
    if (isset($_POST['submit_test_notification']) && wp_verify_nonce($_POST['_wpnonce'], 'send_test_notification')) {
        $account = sanitize_text_field($_POST['xrpl_account'] ?? '');
        $title = sanitize_text_field($_POST['notification_title'] ?? 'Test Notification');
        $notification_message = sanitize_text_field($_POST['notification_message'] ?? 'This is a test notification.');
        $url = sanitize_text_field($_POST['notification_url'] ?? home_url('/'));

        xaman_log("Test notification requested for account: $account, title: $title, message: $notification_message, url: $url");

        if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
            $error = 'Invalid XRPL account format.';
            xaman_log("Invalid XRPL account format: $account", 'ERROR');
        } else {
            $sent = send_push_notification($account, $title, $notification_message, $url);
            if ($sent) {
                $message = "Test notification sent to $account.";
                xaman_log("Test notification sent successfully to $account");
            } else {
                $error = "Failed to send test notification to $account. Check logs for details.";
                xaman_log("Failed to send test notification to $account", 'ERROR');
            }
        }
    }

    // Handle subscription check
    if (isset($_POST['check_subscription']) && wp_verify_nonce($_POST['_wpnonce'], 'check_subscription_status')) {
        $account = sanitize_text_field($_POST['xrpl_account'] ?? '');
        xaman_log("Checking subscription status for account: $account");

        if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
            $error = 'Invalid XRPL account format.';
            xaman_log("Invalid XRPL account format for subscription check: $account", 'ERROR');
        } elseif (!defined('ONESIGNAL_APP_ID')) {
            $error = 'OneSignal App ID not defined.';
            xaman_log("OneSignal App ID not defined for subscription check", 'ERROR');
        } else {
            // Check OneSignal subscription in local database
            global $wpdb;
            $onesignal_table = $wpdb->prefix . 'onesignal_subscriptions';
            if ($wpdb->get_var("SHOW TABLES LIKE '$onesignal_table'") === $onesignal_table) {
                $sub = $wpdb->get_row($wpdb->prepare(
                    "SELECT is_active, onesignal_player_id FROM $onesignal_table WHERE xrpl_account = %s",
                    $account
                ), ARRAY_A);
                if ($sub && $sub['is_active']) {
                    $subscription_status = 'Subscribed';
                    $message = "Subscription status for $account: Subscribed (OneSignal)";
                } else {
                    $subscription_status = 'Not subscribed';
                    $message = "Subscription status for $account: Not subscribed";
                }
                xaman_log("OneSignal subscription status for $account: $subscription_status");
            } else {
                $error = 'OneSignal subscriptions table not found.';
                xaman_log("OneSignal subscriptions table not found for subscription check", 'ERROR');
            }
        }
    }

    // Get recent logs
    $logs = [];
    if (file_exists(WP_CONTENT_DIR . '/xaman-notifications.log')) {
        $log_content = file_get_contents(WP_CONTENT_DIR . '/xaman-notifications.log');
        $log_lines = array_slice(array_filter(explode("\n", $log_content)), -20); // Increased to 20 for more context
        $logs = array_reverse($log_lines);
    }
    ?>
    <div class="wrap">
        <h1>Push Notification Tester</h1>
        <?php if ($message) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html($message); ?></p></div>
        <?php endif; ?>
        <?php if ($error) : ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div>
        <?php endif; ?>

        <h2>Send Test Notification</h2>
        <form id="send-test-notification-form" method="post" action="">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($send_nonce); ?>">
            <table class="form-table">
                <tr>
                    <th><label for="xrpl_account">XRPL Account</label></th>
                    <td><input type="text" name="xrpl_account" id="xrpl_account" class="regular-text" value="rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga" required></td>
                </tr>
                <tr>
                    <th><label for="notification_title">Notification Title</label></th>
                    <td><input type="text" name="notification_title" id="notification_title" class="regular-text" value="Test Notification"></td>
                </tr>
                <tr>
                    <th><label for="notification_message">Notification Message</label></th>
                    <td><input type="text" name="notification_message" id="notification_message" class="regular-text" value="This is a test notification."></td>
                </tr>
                <tr>
                    <th><label for="notification_url">Notification URL</label></th>
                    <td><input type="url" name="notification_url" id="notification_url" class="regular-text" value="<?php echo esc_url(home_url('/')); ?>"></td>
                </tr>
            </table>
            <?php submit_button('Send Test Notification', 'primary', 'submit_test_notification'); ?>
        </form>

        <h2>Check Subscription Status</h2>
        <form id="check-subscription-form" method="post" action="">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($check_nonce); ?>">
            <table class="form-table">
                <tr>
                    <th><label for="check_xrpl_account">XRPL Account</label></th>
                    <td><input type="text" name="xrpl_account" id="check_xrpl_account" class="regular-text" value="rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga" required></td>
                </tr>
            </table>
            <?php submit_button('Check Subscription', 'secondary', 'check_subscription'); ?>
        </form>
        <?php if (isset($subscription_status)) : ?>
            <p><strong>Subscription Status:</strong> <?php echo esc_html($subscription_status); ?></p>
        <?php endif; ?>

        <h2>Recent Logs</h2>
        <pre style="background: #f8f8f8; padding: 10px; max-height: 400px; overflow-y: auto;">
            <?php foreach ($logs as $log) : ?>
                <?php echo esc_html($log); ?><br>
            <?php endforeach; ?>
        </pre>
    </div>
    <script>
        jQuery(document).ready(function($) {
            // Add dismissible notice handling
            $('.notice.is-dismissible').append('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>');
            $(document).on('click', '.notice-dismiss', function() {
                $(this).parent().remove();
            });

            $('#send-test-notification-form').on('submit', function(e) {
                e.preventDefault();
                console.log('Send Test Notification form submitted', $(this).serialize());
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php', 'https'); ?>',
                    type: 'POST',
                    data: {
                        action: 'send_test_notification',
                        nonce: '<?php echo esc_attr($send_nonce); ?>',
                        xrpl_account: $('#xrpl_account').val(),
                        notification_title: $('#notification_title').val(),
                        notification_message: $('#notification_message').val(),
                        notification_url: $('#notification_url').val()
                    },
                    beforeSend: function() {
                        $('.wrap').prepend('<div class="notice notice-info is-dismissible"><p>Sending test notification...</p></div>');
                    },
                    success: function(response) {
                        console.log('Send test notification response:', response);
                        $('.notice.notice-info').remove();
                        if (response.success) {
                            $('.wrap').prepend('<div class="notice notice-success is-dismissible"><p>' + response.data.message + '</p></div>');
                        } else {
                            $('.wrap').prepend('<div class="notice notice-error is-dismissible"><p>' + (response.data.message || 'Failed to send test notification.') + '</p></div>');
                        }
                    },
                    error: function(xhr) {
                        console.error('Send test notification error:', xhr.status, xhr.statusText, xhr.responseText);
                        $('.notice.notice-info').remove();
                        $('.wrap').prepend('<div class="notice notice-error is-dismissible"><p>Failed to send test notification: ' + (xhr.responseText || 'Server error') + '</p></div>');
                    }
                });
            });

            $('#check-subscription-form').on('submit', function(e) {
                e.preventDefault();
                console.log('Check Subscription form submitted', $(this).serialize());
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php', 'https'); ?>',
                    type: 'POST',
                    data: {
                        action: 'check_subscription_status',
                        nonce: '<?php echo esc_attr($check_nonce); ?>',
                        xrpl_account: $('#check_xrpl_account').val()
                    },
                    beforeSend: function() {
                        $('.wrap').prepend('<div class="notice notice-info is-dismissible"><p>Checking subscription status...</p></div>');
                    },
                    success: function(response) {
                        console.log('Check subscription response:', response);
                        $('.notice.notice-info').remove();
                        if (response.success) {
                            $('.wrap').prepend('<div class="notice notice-success is-dismissible"><p>Subscription status: ' + (response.data.is_subscribed ? 'Subscribed' : 'Not subscribed') + '</p></div>');
                        } else {
                            $('.wrap').prepend('<div class="notice notice-error is-dismissible"><p>' + (response.data.message || 'Failed to check subscription status.') + '</p></div>');
                        }
                    },
                    error: function(xhr) {
                        console.error('Check subscription error:', xhr.status, xhr.statusText, xhr.responseText);
                        $('.notice.notice-info').remove();
                        $('.wrap').prepend('<div class="notice notice-error is-dismissible"><p>Failed to check subscription status: ' + (xhr.responseText || 'Server error') + '</p></div>');
                    }
                });
            });
        });
    </script>
    <?php
}

// Add AJAX handler for test notification
add_action('wp_ajax_send_test_notification', 'send_test_notification_callback');
function send_test_notification_callback() {
    if (!current_user_can('manage_options')) {
        xaman_log("Unauthorized access to send_test_notification", 'ERROR');
        wp_send_json_error(['message' => 'Unauthorized access'], 403);
        wp_die();
    }

    $nonce = sanitize_text_field($_POST['nonce'] ?? '');
    if (!wp_verify_nonce($nonce, 'send_test_notification')) {
        xaman_log("Invalid nonce for send_test_notification: $nonce", 'ERROR');
        wp_send_json_error(['message' => 'Invalid nonce'], 403);
        wp_die();
    }

    $account = sanitize_text_field($_POST['xrpl_account'] ?? '');
    $title = sanitize_text_field($_POST['notification_title'] ?? 'Test Notification');
    $message = sanitize_text_field($_POST['notification_message'] ?? 'This is a test notification.');
    $url = sanitize_text_field($_POST['notification_url'] ?? home_url('/'));

    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        xaman_log("Invalid XRPL account format: $account", 'ERROR');
        wp_send_json_error(['message' => 'Invalid XRPL account format'], 400);
        wp_die();
    }

    $sent = send_push_notification($account, $title, $message, $url);
    if ($sent) {
        xaman_log("Test notification sent successfully to $account");
        wp_send_json_success(['message' => "Test notification sent to $account."]);
    } else {
        xaman_log("Failed to send test notification to $account", 'ERROR');
        wp_send_json_error(['message' => "Failed to send test notification to $account. Check logs for details."]);
    }
    wp_die();
}






// Handle footer email subscription
function handle_footer_email_subscription() {
    if (isset($_POST['subscribe_submit']) && wp_verify_nonce($_POST['footer_email_nonce'], 'footer_email_subscribe')) {
        // Processing moved to footer.php, just redirect here
        wp_redirect(add_query_arg('subscribed', '1', get_permalink()));
        exit;
    }
}
add_action('template_redirect', 'handle_footer_email_subscription');

// IMU Events removed — redirect /events/ and /live-events/ to homepage
add_action('template_redirect', function() {
    if (is_page(['events', 'live-events'])) {
        wp_redirect(home_url('/'), 301);
        exit;
    }
});

function add_custom_footer_styles() {
    // CP-C4-1 (09 Sep 2026) — THE DUPLICATE custom.css ENQUEUE.
    //
    // 🔴 This function used to enqueue custom.css under its OWN handle
    // ('custom-footer-style') purely so wp_add_inline_style had something to
    // attach to. custom_theme_styles() enqueues the SAME 190KB file under
    // 'custom-style', and imp_cache_bust_styles() (priority 100) dequeues
    // 'custom-style' ONLY - it never knew about this handle. So both loaded.
    //
    // ★★ It also explains the standing console warning. The preload asks for
    // custom.css?ver=<version>; this handle enqueued the file with NO version,
    // and being registered first it won. Two different URLs, so the preloaded
    // copy was genuinely never used. One duplicate, two symptoms.
    //
    // ⚠ THE HOOK PRIORITY IS LOAD-BEARING - see the add_action below.
    wp_add_inline_style('custom-style', '
        /* Enhanced dot removal - target all possible sources */
        .dropdown-content a::before,
        .dropdown-content a::after,
        .dropdown-content .menu-item::before,
        .dropdown-content .menu-item::after,
        .main-navigation .menu-item::before,
        .main-navigation .menu-item::after,
        .main-navigation ul::before,
        .main-navigation ul::after,
        .main-navigation .sub-menu::before,
        .main-navigation .sub-menu::after {
            content: none !important;
            display: none !important;
            background: none !important;
        }
        .main-navigation ul,
        .main-navigation .menu-item,
        .main-navigation .sub-menu,
        .dropdown-content .menu-item {
            list-style: none !important;
        }
    ');
    xaman_log("Footer and enhanced menu styles enqueued for page: " . get_the_title());
}
// ⚠ PRIORITY 101, NOT THE DEFAULT 10. Two reasons, both silent if ignored:
//
//   1. At priority 10 this ran BEFORE custom_theme_styles() registered
//      'custom-style' (registration order, same priority), so
//      wp_add_inline_style() would attach to nothing and FAIL SILENTLY.
//
//   2. imp_cache_bust_styles() at priority 100 DEREGISTERS and re-registers
//      'custom-style', which DISCARDS any inline style already attached.
//
// 101 is the only safe slot: after the re-register, when the handle
// definitively exists and will not be replaced again.
//
// ⚠ Neither failure raises a PHP error. The symptom would be the nav dropdown
// silently losing its styling, so verify with rendered output, not php -l.
add_action('wp_enqueue_scripts', 'add_custom_footer_styles', 101);


// ============================================================================
// REST API: Newsletter subscription endpoint for VPS pages
// POST /wp-json/imc/v1/subscribe { email: "...", source: "vps-mint" }
// ============================================================================
add_action('rest_api_init', function() {
    register_rest_route('imc/v1', '/subscribe', [
        'methods'             => 'POST',
        'callback'            => 'imc_rest_newsletter_subscribe',
        'permission_callback' => '__return_true', // Public endpoint
    ]);
});

function imc_rest_newsletter_subscribe($request) {
    $email  = sanitize_email($request->get_param('email'));
    $source = sanitize_text_field($request->get_param('source') ?? 'unknown');

    if (!is_email($email)) {
        return new WP_REST_Response(['success' => false, 'error' => 'Invalid email'], 400);
    }

    $to      = 'support@imcollectibles.io';
    $subject = 'New Newsletter Subscription (' . $source . ')';
    $message = "A new user has subscribed:\n\nEmail: $email\nSource: $source\nTime: " . current_time('mysql');
    $headers = ['Content-Type: text/plain; charset=UTF-8'];

    if (wp_mail($to, $subject, $message, $headers)) {
        return new WP_REST_Response(['success' => true], 200);
    }
    return new WP_REST_Response(['success' => false, 'error' => 'Mail failed'], 500);
}

// Disable default REST API cookie nonce check for the verify-rewards endpoint (we handle nonce via header inside the callback)
add_filter('rest_authentication_errors', function($result) {
    if (!empty($result)) {
        return $result;
    }
    // Check if the request is to our specific endpoint
    if (strpos($_SERVER['REQUEST_URI'], '/wp-json/xrpl/v1/verify-rewards') !== false) {
        // Skip default authentication (allow the request; we validate manually inside)
        return true;
    }
    return $result;
}, 99);





// Enqueue custom CSS for header and dropdown menu
function custom_theme_styles() {
    wp_enqueue_style('custom-style', get_template_directory_uri() . '/custom.css', array(), '1.0.0', 'all');
}
add_action('wp_enqueue_scripts', 'custom_theme_styles');


/**
 * IMProtectors Header Cache Busting
 * Add this to the END of your functions.php file
 * 
 * This ensures all users see the latest header styles
 */

// Header version - increment this when making changes
define('IMP_HEADER_CSS_VERSION', '2.0.1');

/**
 * Force cache busting for custom.css
 * This adds a version query string to prevent cached old versions
 */
function imp_cache_bust_styles() {
    // Remove any existing custom.css enqueue
    wp_dequeue_style('custom-style');
    wp_deregister_style('custom-style');
    
    // Re-enqueue with version for cache busting
    wp_enqueue_style(
        'custom-style', 
        get_template_directory_uri() . '/custom.css', 
        array(), 
        IMP_HEADER_CSS_VERSION . '.' . filemtime(get_template_directory() . '/custom.css'),
        'all'
    );
}
add_action('wp_enqueue_scripts', 'imp_cache_bust_styles', 100);

/**
 * Add cache control headers to prevent browser caching during updates
 * Remove or comment out this function after all users have updated
 */
function imp_no_cache_headers() {
    if (!is_admin()) {
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
}
// Uncomment the line below to force no-cache (use temporarily during rollout)
// add_action('send_headers', 'imp_no_cache_headers');

/**
 * CP-C4-1b (09 Sep 2026) — PRELOAD HINT RETIRED.
 *
 * 🔴 LiteSpeed COMBINES every stylesheet into one hashed file and loads that:
 *   <link rel=preload data-optimized="2" onload="this.rel='stylesheet'"
 *         href=".../litespeed/css/4e6adabeb12cfd716a045497d5669268.css">
 *
 * The browser therefore NEVER requests custom.css directly, so this preload
 * downloaded 190KB that was discarded on every single page view - and the
 * standing console warning was the browser telling us exactly that:
 *   "custom.css?ver=... was preloaded using link preload but not used
 *    within a few seconds from the window's load event."
 *
 * ★ LiteSpeed already does the job properly, with the same
 * preload-then-promote-to-stylesheet pattern this was reaching for.
 *
 * ⚠ imp_cache_bust_styles() (priority 100) MUST STAY. It sets the version
 * WordPress hands to LiteSpeed, which is how the combined file invalidates
 * when custom.css changes. Removing it would leave stale CSS served forever.
 *
 * IMP_HEADER_CSS_VERSION is still used by imp_cache_bust_styles() and the
 * admin notice - the constant stays.
 */
// function imp_preload_header_styles() {
//     $version = IMP_HEADER_CSS_VERSION . '.' . filemtime(get_template_directory() . '/custom.css');
//     echo '<link rel="preload" href="' . esc_url(get_template_directory_uri() . '/custom.css?ver=' . $version) . '" as="style">' . "\n";
// }
// add_action('wp_head', 'imp_preload_header_styles', 1);

// ============================================================================
// OPENGRAPH + SEO META TAGS
// v152 Phase 1: Dynamic OG, Twitter Card, and meta description for all pages
// ============================================================================

/**
 * Default OG image path — update this when uploading the branded 1200x630 image
 * Upload to: /wp-content/uploads/og-default.png (1200x630, no transparency)
 */
define('IMC_PINATA_GW', 'https://<your-pinata-gateway>/ipfs/');
define('IMC_OG_DEFAULT_IMAGE', 'https://imcollectibles.io/wp-content/uploads/og-default.png');
define('IMC_FALLBACK_IMAGE', '/wp-content/uploads/fallback-nft.svg');
define('IMC_SITE_NAME', 'IMCollectibles');
define('IMC_DEFAULT_DESCRIPTION', 'The entertainment NFT marketplace on the XRP Ledger. Mint, trade, and collect music, film, and art NFTs with true media protection.');

// ── T&C Version ───────────────────────────────────────────────────────────────
// Bump this value to re-prompt ALL connected wallets to accept updated T&C.
// The imc-tc-handler.php uses if(!defined()) so this always takes precedence.
define('IMC_TC_VERSION', '1.1');

// ── v411: OG IMAGE NORMALISER ─────────────────────────────────────────────────
// Converts any raw IPFS reference, img.php proxy URL, or dead gateway URL into
// a direct, fast, crawlable HTTPS URL using our dedicated Pinata gateway.
// Social crawlers (Twitter/X, Discord, Telegram, iMessage) have ~5s timeouts.
// img.php proxy adds latency on cold cache (must fetch from Pinata first);
// direct Pinata dedicated gateway URLs respond instantly and don't rate-limit.
function imc_normalise_og_image(string $img): string {
    if (empty($img)) return defined('IMC_OG_DEFAULT_IMAGE') ? IMC_OG_DEFAULT_IMAGE : '';

    $pinata = defined('IMC_PINATA_GW') ? IMC_PINATA_GW : 'https://<your-pinata-gateway>/ipfs/';

    // img.php proxy URL → extract the underlying CID and use Pinata directly.
    // e.g. https://metadata.imcollectibles.io/img.php?url=ipfs%3A%2F%2FQm...
    if (strpos($img, 'metadata.imcollectibles.io/img.php') !== false) {
        $qs = parse_url($img, PHP_URL_QUERY);
        parse_str($qs ?? '', $params);
        if (!empty($params['url'])) {
            return imc_normalise_og_image(urldecode($params['url']));
        }
        // ?nft=TOKEN_ID variant — can't resolve without DB; return proxy as-is
        return $img;
    }

    // Dead/unreliable gateways → swap to Pinata
    $bad_gateways = [
        'https://cloudflare-ipfs.com/ipfs/',
        'https://ipfs.io/ipfs/',
        'https://gateway.ipfs.io/ipfs/',
        'https://dweb.link/ipfs/',
        'https://nftstorage.link/ipfs/',
    ];
    foreach ($bad_gateways as $gw) {
        if (strpos($img, $gw) === 0) {
            return $pinata . substr($img, strlen($gw));
        }
    }

    // Already a direct Pinata or other https URL — pass through
    if (strpos($img, 'https://') === 0 || strpos($img, 'http://') === 0) {
        return $img;
    }

    // ipfs://CID → Pinata
    if (strpos($img, 'ipfs://') === 0) {
        return $pinata . substr($img, 7);
    }

    // Bare CID (Qm… or bafy…) → Pinata
    if (preg_match('/^(Qm[1-9A-HJ-NP-Za-km-z]{44}|bafy[a-zA-Z0-9]{50,})/', $img)) {
        return $pinata . $img;
    }

    // Relative path → prefix site URL
    if (strpos($img, '/') === 0) {
        return rtrim(home_url(), '/') . $img;
    }

    return defined('IMC_OG_DEFAULT_IMAGE') ? IMC_OG_DEFAULT_IMAGE : '';
}

function imc_output_og_seo_meta() {
    global $imc_og_data;

    // ── Collection OG data — safety-net resolver (fires if template didn't pre-set it) ─
    // v351: handles all slug formats — IMU pretty slugs, name-taxon slugs, issuer-taxon slugs
    if (empty($imc_og_data)) {
        // IMU pretty slug → issuer+taxon reverse map
        static $_fn_imu = [
            'guardians'   => ['issuer' => 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR', 'taxon' => 0,          'name' => 'Guardians of the Frequencies',   'desc' => 'The debut NFT collection from the Independent Music Universe. 29 unique Guardians protecting the frequencies of independent music on the XRP Ledger.'],
            'frequencies' => ['issuer' => 'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga', 'taxon' => 717825,     'name' => 'Protectors of the Frequencies',  'desc' => 'An expanding universe of 10,000 Protectors defending the frequencies of independent music on the XRP Ledger.'],
            'ledger'      => ['issuer' => 'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt', 'taxon' => 1056369418, 'name' => 'Protectors of the Ledger',       'desc' => 'An elite intergalactic force assembled to safeguard independent creativity on the XRP Ledger.'],
            'lasvegas'    => ['issuer' => 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR', 'taxon' => 777,        'name' => 'Protectors of Las Vegas',        'desc' => 'Vegas-themed digital collectibles celebrating the entertainment capital of the world on the XRP Ledger.'],
            'firepit'     => ['issuer' => 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR', 'taxon' => 666,        'name' => 'Firepit Collection',             'desc' => 'Exclusive Firepit community NFT collection on the XRP Ledger.'],
        ];

        $col_slug   = sanitize_text_field(get_query_var('collection_slug'));
        $col_issuer = sanitize_text_field($_GET['issuer'] ?? '');
        $col_taxon  = isset($_GET['taxon']) ? intval($_GET['taxon']) : null;

        // Resolve rISSUER-TAXON legacy slug format
        if (!empty($col_slug) && empty($col_issuer) &&
            preg_match('/^(r[1-9A-HJ-NP-Za-km-z]{25,34})-(\d+)$/', $col_slug, $_m)) {
            $col_issuer = $_m[1];
            $col_taxon  = (int)$_m[2];
            $col_slug   = '';
        }

        // Path A: hardcoded IMU pretty slug
        if (!empty($col_slug) && isset($_fn_imu[$col_slug])) {
            $_fo   = $_fn_imu[$col_slug];
            $_fimg = function_exists('imu_get_collection_image')
                ? imu_get_collection_image($_fo['issuer'], (int)$_fo['taxon'], '')
                : '';
            if (empty($_fimg) || strpos($_fimg, 'fallback') !== false) $_fimg = IMC_OG_DEFAULT_IMAGE;
            $imc_og_data = [
                'title'       => esc_attr($_fo['name']) . ' NFT Collection | IMCollectibles',
                'description' => esc_attr($_fo['desc']),
                'image'       => esc_url($_fimg),
                'url'         => esc_url(home_url('/collections/' . $col_slug . '/')),
                'type'        => 'website',
            ];

        // Path B: name-based slug (name-taxon) or issuer+taxon param → VPS lookup
        } elseif (!empty($col_slug) || (!empty($col_issuer) && $col_taxon !== null)) {
            $_fn_ck = 'imc_og_col_' . substr(md5($col_issuer . ':' . $col_taxon . ':' . $col_slug), 0, 16);
            $_fn_cached = get_transient($_fn_ck);
            if ($_fn_cached !== false) {
                $imc_og_data = $_fn_cached;
            } else {
                $_fn_name = ''; $_fn_img = ''; $_fn_desc = ''; $_fn_issuer = $col_issuer; $_fn_taxon = $col_taxon; $_fn_url_slug = $col_slug;

                // If we have issuer+taxon, look up directly
                if ($_fn_issuer && $_fn_taxon !== null) {
                    $vr = wp_remote_get(
                        'https://metadata.imcollectibles.io/?action=collection&issuer=' . urlencode($_fn_issuer) . '&taxon=' . $_fn_taxon,
                        ['timeout' => 4]
                    );
                    if (!is_wp_error($vr) && wp_remote_retrieve_response_code($vr) === 200) {
                        $vd = json_decode(wp_remote_retrieve_body($vr), true);
                        if (!empty($vd['collection'])) {
                            $_fn_name  = $vd['collection']['name']        ?? '';
                            $_fn_img   = $vd['collection']['image']       ?? '';
                            $_fn_desc  = $vd['collection']['description'] ?? '';
                            if (!$_fn_url_slug && $_fn_name) {
                                $_fn_url_slug = imu_collection_slugify($_fn_name) . '-' . $_fn_taxon;
                            }
                        }
                    }

                // If we only have a name-based slug, call collection_by_slug
                } elseif (!empty($_fn_url_slug)) {
                    $vr = wp_remote_get(
                        'https://metadata.imcollectibles.io/?action=collection_by_slug&slug=' . urlencode($_fn_url_slug),
                        ['timeout' => 4]
                    );
                    if (!is_wp_error($vr) && wp_remote_retrieve_response_code($vr) === 200) {
                        $vd = json_decode(wp_remote_retrieve_body($vr), true);
                        if (!empty($vd['collection'])) {
                            $_fn_name   = $vd['collection']['name']        ?? '';
                            $_fn_img    = $vd['collection']['image']       ?? '';
                            $_fn_desc   = $vd['collection']['description'] ?? '';
                            $_fn_issuer = $vd['collection']['issuer']      ?? '';
                            $_fn_taxon  = (int)($vd['collection']['taxon'] ?? 0);
                        }
                    }
                }

                // DB fallback for platform-minted collections.
                // Two cases need this:
                //   (a) issuer+taxon already known but VPS had no name
                //   (b) name-taxon slug where VPS collection_by_slug returned empty
                //       → _fn_issuer is still '' so we decompose slug instead
                if (empty($_fn_name)) {
                    global $wpdb;
                    $ct = $wpdb->prefix . 'imc_collections';
                    if ($wpdb->get_var("SHOW TABLES LIKE '$ct'") === $ct) {

                        if (!empty($_fn_issuer) && $_fn_taxon !== null) {
                            // Case (a): have issuer+taxon, query directly
                            $r = $wpdb->get_row($wpdb->prepare(
                                "SELECT collection_name, collection_description, cover_image_ipfs
                                 FROM $ct WHERE artist_account = %s AND collection_taxon = %d LIMIT 1",
                                $_fn_issuer, $_fn_taxon
                            ), ARRAY_A);
                            if ($r) {
                                $_fn_name = $r['collection_name'] ?? '';
                                $_fn_desc = $r['collection_description'] ?? '';
                                if (empty($_fn_img) && !empty($r['cover_image_ipfs'])) {
                                    $_fn_img = 'https://metadata.imcollectibles.io/img.php?url='
                                             . urlencode($r['cover_image_ipfs']);
                                }
                            }

                        } elseif (!empty($_fn_url_slug) && preg_match('/^(.+)-(\d+)$/', $_fn_url_slug, $_fsm)) {
                            // Case (b): decompose slug → match by taxon + slugified name
                            $_fsn = $_fsm[1]; $_fst = (int)$_fsm[2];
                            $rows = $wpdb->get_results($wpdb->prepare(
                                "SELECT artist_account, collection_name, collection_description, cover_image_ipfs
                                 FROM $ct WHERE collection_taxon = %d
                                 AND collection_name IS NOT NULL AND collection_name != ''",
                                $_fst
                            ), ARRAY_A);
                            foreach ($rows as $_fr) {
                                $_fc = function_exists('imu_collection_slugify')
                                    ? imu_collection_slugify($_fr['collection_name'])
                                    : strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $_fr['collection_name']), '-'));
                                if ($_fc === $_fsn) {
                                    $_fn_name   = $_fr['collection_name'];
                                    $_fn_desc   = $_fr['collection_description'] ?? '';
                                    $_fn_issuer = $_fr['artist_account'];
                                    $_fn_taxon  = $_fst;
                                    // Use the authoritative image resolver — never build manually.
                                    // imu_get_collection_image handles admin overrides, img.php routing,
                                    // and transient caching identically to the IMU pretty-slug path.
                                    if (function_exists('imu_get_collection_image')) {
                                        $_fn_img = imu_get_collection_image($_fn_issuer, (int)$_fn_taxon, '');
                                        if (empty($_fn_img) || strpos($_fn_img, 'fallback') !== false) {
                                            $_fn_img = '';
                                        }
                                    }
                                    break;
                                }
                            }
                        }
                    }
                }

                // Sanitise image — route all IPFS/Pinata URLs through img.php proxy.
                // Twitter/X crawlers are blocked by Pinata's rate limiter.
                if (!empty($_fn_img) && strpos($_fn_img, 'metadata.imcollectibles.io/img.php') === false) {
                    if (strpos($_fn_img, 'ipfs://') === 0 || strpos($_fn_img, 'mypinata.cloud') !== false || strpos($_fn_img, 'ipfs.io') !== false) {
                        $_fn_img = 'https://metadata.imcollectibles.io/img.php?url=' . urlencode($_fn_img);
                    }
                }

                if (empty($_fn_name))  $_fn_name = 'NFT Collection';
                if (empty($_fn_img))   $_fn_img  = IMC_OG_DEFAULT_IMAGE;
                if (empty($_fn_desc))  $_fn_desc = $_fn_name . ' — NFT collection on the XRP Ledger. Trade and collect exclusive media on IMCollectibles.';

                $imc_og_data = [
                    'title'       => esc_attr($_fn_name) . ' NFT Collection | IMCollectibles',
                    'description' => esc_attr(wp_strip_all_tags($_fn_desc)),
                    'image'       => esc_url($_fn_img),
                    'url'         => esc_url(home_url('/collections/' . ($_fn_url_slug ?: '?issuer=' . urlencode($_fn_issuer) . '&taxon=' . $_fn_taxon) . '/')),
                    'type'        => 'website',
                ];
                set_transient($_fn_ck, $imc_og_data, 6 * HOUR_IN_SECONDS);
            }

        // Path C: Browse mode
        } elseif (
            is_page_template('page-templates/collections.php') ||
            is_page_template('collections.php')
        ) {
            $imc_og_data = [
                'title'       => 'NFT Collections | IMCollectibles',
                'description' => 'Browse all NFT collections on IMCollectibles. Unique music, film, and art NFTs from independent creators on the XRP Ledger.',
                'image'       => IMC_OG_DEFAULT_IMAGE,
                'url'         => esc_url(home_url('/collections/')),
                'type'        => 'website',
            ];
        }
    }

    // If a template already set specific OG data (e.g. NFT single page, collection)
    if (!empty($imc_og_data)) {
        $title       = $imc_og_data['title'];
        $description = $imc_og_data['description'];
        $image       = $imc_og_data['image'];
        $url         = $imc_og_data['url'];
        $type        = $imc_og_data['type'] ?? 'website';
    } else {
        // Determine page context from template
        $template_slug     = get_page_template_slug(); // full relative path
        $template_basename = basename($template_slug);
        $page_title        = wp_get_document_title();
        $url               = esc_url(home_url($_SERVER['REQUEST_URI']));
        $image             = IMC_OG_DEFAULT_IMAGE;
        $type              = 'website';

        // Handle pages loaded via imc_page query var (no WP page slug available)
        $imc_page_var = get_query_var('imc_page');
        if (empty($imc_page_var)) {
            $uri = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/');
            $site_path = trim(parse_url(home_url(), PHP_URL_PATH) ?? '', '/');
            if ($site_path && strpos($uri, $site_path) === 0) {
                $uri = trim(substr($uri, strlen($site_path)), '/');
            }
            $page_map = ['mint' => 'mint', 'create' => 'mint', 'tasks' => 'tasks',
                         'redeem' => 'redeem', 'terms' => 'terms', 'privacy' => 'privacy'];
            $imc_page_var = $page_map[$uri] ?? '';
        }
        if ($imc_page_var === 'mint') {
            $title       = 'Create an NFT | IMCollectibles';
            $description = 'Mint music, film, and art NFTs on the XRP Ledger. Full XLS-24d compliance, PRO royalty reporting, and VPS-protected master file delivery.';
            $url         = esc_url(home_url('/mint/'));
        } elseif ($imc_page_var === 'tasks') {
            $title       = 'Tasks & Rewards | IMCollectibles';
            $description = 'Complete tasks and earn XFT token rewards in the IMCollectibles ecosystem. Engage with the community and grow your collection.';
            $url         = esc_url(home_url('/tasks/'));
        } elseif ($imc_page_var === 'redeem') {
            $title       = 'Redeem | IMCollectibles';
            $description = 'Redeem your IMU tokens and vouchers for exclusive NFTs and rewards on the IMCollectibles marketplace.';
            $url         = esc_url(home_url('/redeem/'));
        } elseif ($imc_page_var === 'terms') {
            $title       = 'Terms of Service | IMCollectibles';
            $description = 'Read the IMCollectibles Terms of Service. Governing your use of the entertainment NFT marketplace on the XRP Ledger.';
            $url         = esc_url(home_url('/terms/'));
        } elseif ($imc_page_var === 'privacy') {
            $title       = 'Privacy Policy | IMCollectibles';
            $description = 'IMCollectibles Privacy Policy. How we collect, use, and protect your data on the entertainment NFT marketplace.';
            $url         = esc_url(home_url('/privacy/'));
        }

        // Page-specific SEO descriptions (150-160 chars, front-loaded keywords)
        switch ($template_basename) {
            case 'trading-hub.php':
                $description = 'Discover, trade, and collect entertainment NFTs on the XRP Ledger. Music, film, and art with true media protection.';
                $title = 'IMCollectibles — Entertainment NFT Marketplace on the XRP Ledger';
                break;

            case 'collections.php': // matches both 'collections.php' and 'page-templates/collections.php'
                $description = 'Browse all NFT collections on IMCollectibles. Unique music, film, and art NFTs from independent creators on the XRP Ledger.';
                $title = 'NFT Collections | IMCollectibles';
                $url   = esc_url(home_url('/collections/'));
                break;

            case 'page-terms.php':
                $title = 'Terms of Service | IMCollectibles';
                $description = 'Read the IMCollectibles Terms of Service governing your use of the entertainment NFT marketplace on the XRP Ledger.';
                $url   = esc_url(home_url('/terms/'));
                break;

            case 'page-privacy.php':
                $title = 'Privacy Policy | IMCollectibles';
                $description = 'IMCollectibles Privacy Policy — how we collect, use, and protect your data on the entertainment NFT marketplace.';
                $url   = esc_url(home_url('/privacy/'));
                break;

            case 'page-mint.php':
                $description = 'Mint your own NFTs on the XRP Ledger. Upload music, videos, and art with built-in media protection and creator royalties.';
                $title = 'Create NFT | IMCollectibles';
                break;

            case 'page-my-nfts.php':
                $description = 'View and manage your NFT collection on IMCollectibles. Trade, list, and showcase your digital assets on the XRP Ledger.';
                $title = 'My NFTs | IMCollectibles';
                break;

            case 'page-stats.php':
                $description = 'Track top NFT collections, trading volume, and market data across the IMCollectibles marketplace on the XRP Ledger.';
                $title = 'Stats & Leaderboard | IMCollectibles';
                break;

            case 'trading-hub-dashboard.php':
                $description = 'Manage your NFT offers, track sales, and monitor your trading activity on the IMCollectibles marketplace dashboard.';
                $title = 'Dashboard | IMCollectibles';
                break;

            case 'page-creator-dashboard.php':
                $description = 'Manage your NFT listings, track sales, and monitor your earnings as an IMCollectibles creator on the XRP Ledger.';
                $title = 'Creator Dashboard | IMCollectibles';
                break;

            case 'page-tasks.php':
                $description = 'Complete tasks and earn XFT token rewards in the IMCollectibles ecosystem. Engage with the community and grow your collection.';
                $title = 'Tasks & Rewards | IMCollectibles';
                break;

            case 'page-recent-mints.php':
                $description = 'Discover the latest NFT drops on IMCollectibles. Fresh music, film, and art minted by independent creators on the XRP Ledger.';
                $title = 'New Drops | IMCollectibles';
                break;

            case 'page-redeem.php':
                $description = 'Redeem your IMU tokens and vouchers for exclusive NFTs and rewards on the IMCollectibles marketplace.';
                $title = 'Redeem | IMCollectibles';
                break;

            case 'page-frequency-fountain.php':
                $description = 'Claim daily XFT token rewards from the Frequency Fountain. Hold IMU NFTs to earn tokens on the XRP Ledger.';
                $title = 'Frequency Fountain | IMCollectibles';
                break;

            default:
                $description = IMC_DEFAULT_DESCRIPTION;
                $title = $page_title;
                break;
        }

        // If homepage (front page) — override regardless of template slug
        if (is_front_page() || is_home()) {
            $description = 'Discover, trade, and collect entertainment NFTs on the XRP Ledger. Music, film, and art with true media protection.';
            $title = 'IMCollectibles — Entertainment NFT Marketplace on the XRP Ledger';
        }
    }

    // Sanitize all values
    $title       = esc_attr(wp_strip_all_tags($title));
    $description = esc_attr(wp_strip_all_tags($description));
    // v411: Normalise OG image — convert img.php proxy, dead gateways, ipfs:// to direct Pinata URL
    $image       = esc_url(function_exists('imc_normalise_og_image') ? imc_normalise_og_image($image) : $image);
    $url         = esc_url($url);

    // Output meta tags
    echo "\n<!-- IMCollectibles SEO & OpenGraph Meta Tags -->\n";

    // Basic SEO
    echo '<meta name="description" content="' . $description . '">' . "\n";

    // OpenGraph (Facebook, Discord, Telegram, LinkedIn, iMessage, WhatsApp)
    echo '<meta property="og:title" content="' . $title . '">' . "\n";
    echo '<meta property="og:description" content="' . $description . '">' . "\n";
    echo '<meta property="og:image" content="' . $image . '">' . "\n";
    echo '<meta property="og:image:width" content="1200">' . "\n";
    echo '<meta property="og:image:height" content="630">' . "\n";
    echo '<meta property="og:image:alt" content="' . $title . '">' . "\n";
    echo '<meta property="og:url" content="' . $url . '">' . "\n";
    echo '<meta property="og:type" content="' . esc_attr($type) . '">' . "\n";
    echo '<meta property="og:site_name" content="' . esc_attr(IMC_SITE_NAME) . '">' . "\n";
    echo '<meta property="og:locale" content="en_US">' . "\n";
    // Article section (for NFT single pages)
    if (!empty($imc_og_data['section'])) {
        echo '<meta property="article:section" content="' . esc_attr($imc_og_data['section']) . '">' . "\n";
    }

    // Twitter Card (falls back to OG if twitter-specific not set)
    echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
    echo '<meta name="twitter:title" content="' . $title . '">' . "\n";
    echo '<meta name="twitter:description" content="' . $description . '">' . "\n";
    echo '<meta name="twitter:image" content="' . $image . '">' . "\n";
    echo '<meta name="twitter:site" content="@IMUniverseTV">' . "\n";

    // Canonical URL — prevents duplicate content penalties
    echo '<link rel="canonical" href="' . $url . '">' . "\n";

    echo "<!-- / IMCollectibles Meta Tags -->\n\n";
}
add_action('wp_head', 'imc_output_og_seo_meta', 2);

// Disable Yoast SEO's OG/Twitter output — IMCollectibles handles all OG tags itself.
// Yoast fires at priority 1 so without this its empty/generic tags appear first and
// Twitter/X picks them up before our correct tags at priority 2.
add_filter('wpseo_og_enabled', '__return_false');

// v406: Fix Share → Copy Link on mobile browsers.
// Problem: WP core outputs <link rel="canonical" href="/collections/"> (the WP page URL)
// AND Yoast outputs its own canonical — both point to the base /collections/ page.
// Mobile share sheets (Android Chrome, iOS Safari) use the canonical URL for "Copy Link",
// so sharing a specific collection copies /collections/ instead of /collections/slug/.
// Fix: Remove WP core's canonical and override Yoast's canonical on dynamic pages
// where $imc_og_data provides the correct URL (collections, NFT singles).
add_action('wp_head', function() {
    global $imc_og_data;
    if (!empty($imc_og_data['url'])) {
        // Remove WP core's rel_canonical (fires at priority 10)
        remove_action('wp_head', 'rel_canonical');
    }
}, 1); // Priority 1 — before anything outputs

// Also override Yoast's canonical URL so it uses our correct slug URL
add_filter('wpseo_canonical', function($canonical) {
    global $imc_og_data;
    if (!empty($imc_og_data['url'])) {
        return $imc_og_data['url'];
    }
    return $canonical;
});

// Override the <title> tag for dynamic collection/NFT pages.
// Without this, WordPress outputs "Collections - IMCollectibles" for ALL collection URLs
// because it only sees the "Collections" WP page, not the dynamic content within it.
// Twitter/X reads <title> as a fallback when og:title is missing or appears after it.
add_filter('pre_get_document_title', 'imc_dynamic_page_title', 10);
add_filter('wpseo_title', 'imc_dynamic_page_title', 10); // also override Yoast's <title>
function imc_dynamic_page_title($title) {
    global $imc_og_data;
    // $imc_og_data is set before get_header() in collection/NFT templates — use it directly
    if (!empty($imc_og_data['title'])) {
        return wp_strip_all_tags($imc_og_data['title']);
    }
    return $title;
}

// ============================================================================
// H2: CONSOLE.LOG PRODUCTION GATE
// Silences console.log in production. console.warn and console.error remain active.
// When WP_DEBUG is true, all logging works normally for development.
// ============================================================================
function imc_console_log_gate() {
    $debug = (defined('WP_DEBUG') && WP_DEBUG) ? 'true' : 'false';
    echo '<script>window.IMC_DEBUG=' . $debug . ';if(!window.IMC_DEBUG){console.log=function(){}}</script>' . "\n";
}
add_action('wp_head', 'imc_console_log_gate', 1);

// ============================================================================
// BROWSER TAB TITLE — "Page Name - IMC"
// ============================================================================
add_filter('document_title_separator', function() {
    return '-';
});

add_filter('document_title_parts', function($title_parts) {
    if (isset($title_parts['site'])) {
        $title_parts['site'] = 'IMC';
    }
    if (isset($title_parts['tagline'])) {
        unset($title_parts['tagline']);
    }
    return $title_parts;
});

/**
 * Clear any WordPress object cache when header is updated
 * Call this function once after deploying header update
 */
function imp_clear_all_caches() {
    // Clear WordPress object cache
    wp_cache_flush();
    
    // Clear transients
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_transient_%'");
    
    // If using popular caching plugins, clear them too
    // WP Super Cache
    if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
    }
    
    // W3 Total Cache
    if (function_exists('w3tc_flush_all')) {
        w3tc_flush_all();
    }
    
    // LiteSpeed Cache
    if (class_exists('LiteSpeed_Cache_API')) {
        LiteSpeed_Cache_API::purge_all();
    }
    
    // WP Fastest Cache
    if (function_exists('wpfc_clear_all_cache')) {
        wpfc_clear_all_cache(true);
    }
    
    return true;
}

/**
 * Admin notice to clear caches after header update
 */
function imp_header_update_notice() {
    $dismissed = get_option('imp_header_notice_dismissed', '');
    if ($dismissed === IMP_HEADER_CSS_VERSION) {
        return;
    }
    ?>
    <div class="notice notice-warning is-dismissible" id="imp-header-notice">
        <p><strong>IMProtectors Header Updated!</strong> Please clear all caches to ensure users see the new header.</p>
        <p>
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?imp_clear_cache=1'), 'imp_clear_cache'); ?>" class="button button-primary">Clear All Caches Now</a>
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?imp_dismiss_notice=1'), 'imp_dismiss_notice'); ?>" class="button">Dismiss</a>
        </p>
    </div>
    <?php
}
add_action('admin_notices', 'imp_header_update_notice');

/**
 * Handle cache clearing and notice dismissal
 */
function imp_handle_admin_actions() {
    if (isset($_GET['imp_clear_cache']) && wp_verify_nonce($_GET['_wpnonce'], 'imp_clear_cache')) {
        imp_clear_all_caches();
        update_option('imp_header_notice_dismissed', IMP_HEADER_CSS_VERSION);
        wp_redirect(admin_url('?cache_cleared=1'));
        exit;
    }
    
    if (isset($_GET['imp_dismiss_notice']) && wp_verify_nonce($_GET['_wpnonce'], 'imp_dismiss_notice')) {
        update_option('imp_header_notice_dismissed', IMP_HEADER_CSS_VERSION);
        wp_redirect(admin_url());
        exit;
    }
    
    if (isset($_GET['cache_cleared'])) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>All caches cleared successfully!</p></div>';
        });
    }
}
add_action('admin_init', 'imp_handle_admin_actions');

// Add to functions.php - Admin function to clear all NFT caches
function clear_all_nft_transients_for_airdrop() {
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%xaman_nft_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%xaman_xft_%'");
}



/**
 * FREQUENCY FOUNTAIN - DEBUG VERSION
 * 
 * Replace your current fountain PHP code with this to debug.
 * This adds logging to trace exactly what's happening.
 */

// ============================================
// ENQUEUE FREQUENCY FOUNTAIN ASSETS
// ============================================
function enqueue_frequency_fountain_assets() {
    // Only load on profile page
    if (!is_page()) return;

    // Google Fonts for the fountain
    wp_enqueue_style(
        'frequency-fountain-fonts',
        'https://fonts.googleapis.com/css2?family=Cinzel+Decorative:wght@400;700&family=Cinzel:wght@400;700&family=Montserrat:wght@400;500;600;700&family=Orbitron:wght@400;700&display=swap',
        [],
        null
    );

    // Fountain CSS
    wp_enqueue_style(
        'frequency-fountain-css',
        get_template_directory_uri() . '/css/frequency-fountain.css',
        [],
        filemtime(get_template_directory() . '/css/frequency-fountain.css')
    );

    // Canvas Confetti library
    wp_enqueue_script(
        'canvas-confetti',
        'https://cdn.jsdelivr.net/npm/canvas-confetti@1.5.1/dist/confetti.browser.min.js',
        [],
        '1.5.1',
        true
    );

    // Fountain JavaScript
    wp_enqueue_script(
        'frequency-fountain-js',
        get_template_directory_uri() . '/js/frequency-fountain.js',
        ['canvas-confetti'],
        filemtime(get_template_directory() . '/js/frequency-fountain.js'),
        true
    );
}
add_action('wp_enqueue_scripts', 'enqueue_frequency_fountain_assets');


// ============================================
// INTERCEPT AJAX CLAIMS - THIS MUST RUN FIRST
// ============================================
function check_for_ajax_claim() {
    // DEBUG: Log that this function is being called
    xaman_log("FOUNTAIN DEBUG: check_for_ajax_claim() called");
    xaman_log("FOUNTAIN DEBUG: POST data = " . json_encode($_POST));
    
    // If this is an AJAX claim, use the AJAX handler instead
    if (isset($_POST['ajax_claim']) && $_POST['ajax_claim'] === '1') {
        xaman_log("FOUNTAIN DEBUG: ajax_claim=1 detected, calling handle_ajax_xft_claim()");
        handle_ajax_xft_claim();
        exit;
    }
    
    xaman_log("FOUNTAIN DEBUG: Not an AJAX claim, continuing to normal handler");
}

// CRITICAL: Priority 5 runs BEFORE the default priority 10
add_action('admin_post_handle_xft_claim', 'check_for_ajax_claim', 5);
add_action('admin_post_nopriv_handle_xft_claim', 'check_for_ajax_claim', 5);


// ============================================
// AJAX CLAIM HANDLER
// ============================================
function handle_ajax_xft_claim() {
    xaman_log("FOUNTAIN DEBUG: handle_ajax_xft_claim() started");
    
    // CRITICAL: Set JSON header FIRST, before any output
    header('Content-Type: application/json');
    
    // Clear any previous output
    if (ob_get_level()) {
        ob_clean();
    }
    
    // Verify nonce
    $nonce_valid = isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'xft_claim');
    xaman_log("FOUNTAIN DEBUG: Nonce check = " . ($nonce_valid ? 'PASSED' : 'FAILED'));
    xaman_log("FOUNTAIN DEBUG: Received nonce = " . ($_POST['_wpnonce'] ?? 'not set'));
    
    if (!$nonce_valid) {
        echo json_encode([
            'success' => false,
            'error' => 'Security verification failed. Please refresh and try again.',
            'debug' => 'nonce_failed'
        ]);
        exit;
    }

    global $wpdb;
    $xft_auth = function_exists('imc_session_require_wallet')
        ? imc_session_require_wallet('')
        : array('ok' => false, 'wallet' => '');
    $account = !empty($xft_auth['ok']) ? $xft_auth['wallet'] : '';
    $claim_table = $wpdb->prefix . 'xft_claims';
    $lock_key = 'xft_claim_lock_' . md5($account);
    $lock_timeout = 90; // v111: Match form handler — VPS XRPL tx can take 45s+ to confirm

    xaman_log("FOUNTAIN DEBUG: Processing claim for account: $account");

    // Validate account
    if (empty($account) || !preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid XRPL account. Please reconnect your wallet.',
            'debug' => 'invalid_account'
        ]);
        exit;
    }

    // Check for concurrent claims
    if (get_transient($lock_key)) {
        echo json_encode([
            'success' => false,
            'error' => 'Claim already in progress. Please wait for it to complete.',
            'debug' => 'locked'
        ]);
        exit;
    }
    
    // Set processing lock
    set_transient($lock_key, true, $lock_timeout);

    // Check cooldown
    $last_claim = $wpdb->get_var($wpdb->prepare(
        "SELECT last_claim FROM $claim_table WHERE xrpl_account = %s", 
        $account
    ));
    
    $time_left = $last_claim ? (24 * 3600 - (current_time('timestamp', 1) - strtotime($last_claim))) : 0;
    
    if ($time_left > 0) {
        delete_transient($lock_key);
        echo json_encode([
            'success' => false,
            'cooldown' => true,
            'time_left' => $time_left,
            'error' => "Fountain is recharging. Please wait " . gmdate("H:i:s", $time_left) . " before claiming again.",
            'debug' => 'cooldown'
        ]);
        exit;
    }

    // Generate nonce for VPS
    if (!function_exists('generate_claim_nonce')) {
        delete_transient($lock_key);
        echo json_encode([
            'success' => false,
            'error' => 'Server configuration error: generate_claim_nonce not found',
            'debug' => 'missing_function'
        ]);
        exit;
    }
    
    $nonce = generate_claim_nonce($account);
    if (!$nonce) {
        delete_transient($lock_key);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to initialize claim. Please try again.',
            'debug' => 'nonce_generation_failed'
        ]);
        exit;
    }

    xaman_log("FOUNTAIN DEBUG: Generated VPS nonce: $nonce");

    // Fetch NFT data
    // ---------------------------------------------------------------
    // FOUNTAIN FIX (B1/B2) - do not pay out on an unverified holding read.
    //
    // The proxy walks account_nfts page by page. If a page fails (a rate
    // limit is an HTTP response, not a transport error) it stops early and
    // - before the A-pack fix - still reported status 'success' with the
    // counts it had. Rare at baseline, materially higher during upstream
    // incidents. A user then received the XFT tier bonus alone, and
    // last_claim locked them out for 24h.
    //
    // Rule: a read is usable ONLY if it is complete. Anything else refuses
    // the claim - no payout, no last_claim write, no cached failure.
    // Refusing costs the user a retry; paying costs them a day.
    // ---------------------------------------------------------------
    $transient_key = 'xaman_nft_' . md5($account);
    $nfts = get_transient($transient_key);
    $nft_read_ok = false;

    if (is_array($nfts) && isset($nfts['result'])) {
        // Cached reads are only ever written below, so they are complete by
        // construction. The status check is belt-and-braces for any cache
        // written by another consumer before this fix shipped.
        $nft_read_ok = (($nfts['result']['status'] ?? 'success') !== 'error')
                       && (($nfts['result']['complete'] ?? true) !== false);
        if (!$nft_read_ok) {
            delete_transient($transient_key);
            $nfts = false;
        }
    }

    if (!$nft_read_ok) {
        $url = "https://imcollectibles.io/xumm-proxy.php?account=$account&t=" . time();

        // Two attempts. The proxy retries internally per page; this covers
        // the case where the whole proxy call fails or returns incomplete.
        for ($nft_try = 1; $nft_try <= 2; $nft_try++) {
            $response = wp_remote_get($url, ['timeout' => 120]);

            if (is_wp_error($response)) {
                xaman_log("FOUNTAIN: NFT fetch attempt $nft_try WP_Error for $account: " . $response->get_error_message());
            } else {
                $decoded = json_decode(wp_remote_retrieve_body($response), true);
                if (is_array($decoded) && isset($decoded['result']['guardians'])) {
                    $complete = (($decoded['result']['status'] ?? 'success') !== 'error')
                                && (($decoded['result']['complete'] ?? true) !== false);
                    if ($complete) {
                        $nfts = $decoded;
                        $nft_read_ok = true;
                        set_transient($transient_key, $nfts, HOUR_IN_SECONDS);
                        break;
                    }
                    xaman_log("FOUNTAIN: NFT fetch attempt $nft_try INCOMPLETE for $account: " . json_encode($decoded['result']));
                } else {
                    xaman_log("FOUNTAIN: NFT fetch attempt $nft_try malformed for $account");
                }
            }

            if ($nft_try < 2) { sleep(2); }
        }
    }

    if (!$nft_read_ok) {
        // Refuse. Nothing is written, nothing is cached, no cooldown starts.
        delete_transient($lock_key);
        xaman_log("FOUNTAIN: REFUSED claim for $account - holdings could not be verified");
        echo json_encode([
            'success' => false,
            'error'   => 'We could not verify your holdings just now. Nothing has been claimed - please try again in a moment.',
            'debug'   => 'nft_read_failed'
        ]);
        exit;
    }

    // Get NFT counts
    $counts = [
        'guardians' => 0,
        'protectors_freq' => 0,
        'protectors_ledger' => 0,
        'protectors_lasVegas' => 0,
        'protectors_firepit' => 0
    ];

    if (isset($nfts['result']['guardians'])) {
    $counts['guardians'] = intval($nfts['result']['guardians'] ?? 0);
    $counts['protectors_freq'] = intval($nfts['result']['frequencies'] ?? 0);
    $counts['protectors_ledger'] = intval($nfts['result']['ledger'] ?? 0);
    $counts['protectors_lasVegas'] = intval($nfts['result']['lasvegas'] ?? 0);
    $counts['protectors_firepit'] = intval($nfts['result']['firepit'] ?? 0);
    } elseif (isset($nfts['result']['account_nfts']) && function_exists('filter_eligible_nfts')) {
        $filtered = filter_eligible_nfts($nfts['result']['account_nfts']);
        $counts = $filtered['counts'];
    }

    // ---------------------------------------------------------------
    // FOUNTAIN FIX (B4) - the balance read was a single attempt with no
    // retry, so one bad response silently dropped the XFT tier bonus
    // (up to 240/day). `peer` asks the node for this one trustline, which
    // also removes any dependence on where XFT sits in a long line list.
    // ---------------------------------------------------------------
    $xft_balance = 0;
    $xft_read_ok = false;
    $xrpl_api_url = "https://s1.ripple.com:51234/";
    $request = [
        'method' => 'account_lines', 
        'params' => [[
            'account' => $account, 
            'peer' => 'rGpnoqYLzWytxwQhhz715nRbqyCHM7zhxt',
            'ledger_index' => 'validated'
        ]]
    ];

    for ($xft_try = 1; $xft_try <= 3; $xft_try++) {
        $response = wp_remote_post($xrpl_api_url, [
            'body' => json_encode($request),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 20
        ]);

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($body['result']['lines'])) {
                // A present-but-empty lines array is a valid answer: no trustline.
                $xft_read_ok = true;
                foreach ($body['result']['lines'] as $line) {
                    if ($line['account'] === 'rGpnoqYLzWytxwQhhz715nRbqyCHM7zhxt' && $line['currency'] === 'XFT') {
                        $xft_balance = floatval($line['balance']);
                        break;
                    }
                }
                break;
            }
        }

        xaman_log("FOUNTAIN: XFT balance attempt $xft_try failed for $account");
        if ($xft_try < 3) { sleep(1); }
    }

    if (!$xft_read_ok) {
        // Same rule as the NFT read: never pay on an unverified figure.
        delete_transient($lock_key);
        xaman_log("FOUNTAIN: REFUSED claim for $account - XFT balance could not be verified");
        echo json_encode([
            'success' => false,
            'error'   => 'We could not verify your XFT balance just now. Nothing has been claimed - please try again in a moment.',
            'debug'   => 'xft_read_failed'
        ]);
        exit;
    }

    // Check if user has any rewards
    $total_nfts = $counts['guardians'] + $counts['protectors_freq'] + $counts['protectors_ledger'] + 
                  $counts['protectors_lasVegas'] + $counts['protectors_firepit'];
    
    if ($xft_balance == 0 && $total_nfts == 0) {
        delete_transient($lock_key);
        echo json_encode([
            'success' => false,
            'error' => 'No XFT or eligible NFTs found. Hold NFTs or XFT to earn rewards!',
            'debug' => 'no_holdings'
        ]);
        exit;
    }

    // Calculate rewards
    if (!function_exists('calculate_xft_rewards')) {
        delete_transient($lock_key);
        echo json_encode([
            'success' => false,
            'error' => 'Server configuration error: calculate_xft_rewards not found',
            'debug' => 'missing_function'
        ]);
        exit;
    }

    $rewards = calculate_xft_rewards(
        $counts['guardians'], 
        $counts['protectors_freq'], 
        $counts['protectors_ledger'],
        $counts['protectors_lasVegas'],
        $counts['protectors_firepit'],
        $xft_balance
    );

    xaman_log("FOUNTAIN DEBUG: Calculated rewards: $rewards XFT");

    if ($rewards <= 0) {
        delete_transient($lock_key);
        echo json_encode([
            'success' => false,
            'error' => 'No rewards available to claim at this time.',
            'debug' => 'zero_rewards'
        ]);
        exit;
    }

    // Send to VPS
    xaman_log("FOUNTAIN DEBUG: Sending to VPS - account=$account, amount=$rewards, nonce=$nonce");
    
    $response = wp_remote_post(defined('IMC_XFT_DISTRIBUTOR_URL') ? IMC_XFT_DISTRIBUTOR_URL : '', [
        'body' => [
            'account' => $account, 
            'amount' => $rewards, 
            'nonce' => $nonce
        ],
        'timeout' => 120,   // D-pair: was 45s, BELOW the VPS worst case of ~85s,
                            // so WordPress abandoned claims that then completed
                            // on the box - settled on-ledger, pending reconciliation in
                            // wp_xft_claims. Moves with fastcgi_read_timeout
                            // 120s on the xft vhost; the lower of the two binds.
        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded']
    ]);

    if (is_wp_error($response)) {
        delete_transient($lock_key);
        xaman_log("FOUNTAIN DEBUG: VPS request failed: " . $response->get_error_message());
        echo json_encode([
            'success' => false,
            'error' => 'Network error connecting to rewards server. Please try again.',
            'debug' => 'vps_network_error'
        ]);
        exit;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = json_decode(wp_remote_retrieve_body($response), true);

    xaman_log("FOUNTAIN DEBUG: VPS response - Code: $response_code, Body: " . json_encode($response_body));

    // Handle VPS response
    if ($response_code === 200 && isset($response_body['tx_result']) && $response_body['tx_result'] === 'tesSUCCESS') {
        // Success! Update database
        $wpdb->replace($claim_table, [
            'xrpl_account' => $account, 
            'last_claim' => current_time('mysql', 1)
        ]);

        // Send notification
        if (function_exists('send_notification')) {
            send_notification($account, 'claim_success', ['rewards' => $rewards]);
        }

        delete_transient($lock_key);
        
        // Clear cached balances
        delete_transient('xaman_xft_' . md5($account));
        delete_transient('xaman_nft_' . md5($account));

        xaman_log("FOUNTAIN DEBUG: SUCCESS - Claimed $rewards XFT for $account");
        
        echo json_encode([
            'success' => true,
            'amount' => $rewards,
            'streak' => 1,
            'next_claim' => 86400,
            'message' => "Successfully claimed $rewards XFT!"
        ]);
        exit;

    } elseif ($response_code === 429) {
        delete_transient($lock_key);
        
        $time_remaining = 86400;
        if (isset($response_body['error']) && preg_match('/(\d+)\s*seconds/', $response_body['error'], $matches)) {
            $time_remaining = intval($matches[1]);
        }

        echo json_encode([
            'success' => false,
            'cooldown' => true,
            'time_left' => $time_remaining,
            'error' => $response_body['error'] ?? 'Please wait before claiming again.',
            'debug' => 'vps_cooldown'
        ]);
        exit;

    } elseif ($response_code === 409) {
        delete_transient($lock_key);
        echo json_encode([
            'success' => false,
            'error' => 'A claim is already being processed. Please wait a moment and try again.',
            'debug' => 'vps_pending'
        ]);
        exit;

    } else {
        delete_transient($lock_key);
        xaman_log("FOUNTAIN DEBUG: VPS error - Code: $response_code, Error: " . ($response_body['error'] ?? 'Unknown'));
        echo json_encode([
            'success' => false,
            'error' => $response_body['error'] ?? 'Failed to process claim. Please try again.',
            'debug' => 'vps_error',
            'vps_code' => $response_code
        ]);
        exit;
    }
}


/**
 * Email Notification System - WordPress Side
 * 
 * Add this to functions.php after the existing notification handlers
 * 
 * Features:
 * - Save email notification preferences
 * - REST API endpoint for VPS to trigger email sends
 * - Uses WordPress wp_mail() (the web host SMTP)
 */

// ============================================
// AJAX Handler: Save Email Notification Preferences
// ============================================
add_action('wp_ajax_save_email_notifications', 'handle_save_email_notifications');
add_action('wp_ajax_nopriv_save_email_notifications', 'handle_save_email_notifications');

function handle_save_email_notifications() {
    global $wpdb;
    $profiles_table = $wpdb->prefix . 'xaman_profiles';
    
    // Verify nonce
    $nonce = $_POST['_wpnonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'save_email_notifications')) {
        wp_send_json_error(['message' => 'Invalid nonce. Please refresh and try again.'], 403);
    }
    
    // Validate account
    $account = sanitize_text_field($_POST['account'] ?? '');
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        wp_send_json_error(['message' => 'Invalid account'], 400);
    }
    
    // Phase 1 Batch 2 (Aug 2026): session-token identity; posted account
    // must match the session wallet (gated legacy fallback, as everywhere).
    $en_auth = function_exists('imc_session_require_wallet')
        ? imc_session_require_wallet($account)
        : array('ok' => false, 'wallet' => '', 'error' => 'auth');
    if (empty($en_auth['ok'])) {
        wp_send_json_error(['message' => (($en_auth['error'] ?? '') === 'mismatch') ? 'Account mismatch. Please log in again.' : 'Session invalid. Please log in again.'], 403);
    }
    $account = $en_auth['wallet'];
    
    // Get data
    $notify_email = isset($_POST['notify_email']) ? 1 : 0;
    $email = sanitize_email($_POST['email'] ?? '');
    
    // If enabling, require valid email
    if ($notify_email && !is_email($email)) {
        wp_send_json_error(['message' => 'Please enter a valid email address'], 400);
    }
    
    // Update database
    $data = [
        'notify_email' => $notify_email,
        'email' => $email
    ];
    
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $profiles_table WHERE xrpl_account = %s",
        $account
    ));
    
    if ($existing) {
        $result = $wpdb->update($profiles_table, $data, ['xrpl_account' => $account]);
    } else {
        $data['xrpl_account'] = $account;
        $data['created_at'] = current_time('mysql', 1);
        $data['name'] = $account;
        $result = $wpdb->insert($profiles_table, $data);
    }
    
    if ($result === false) {
        wp_send_json_error(['message' => 'Database error: ' . $wpdb->last_error], 500);
    }
    
    xaman_log("Email prefs saved for $account: notify_email=$notify_email, email=$email");
    
    wp_send_json_success([
        'message' => 'Email preferences saved',
        'notify_email' => $notify_email,
        'email' => $email
    ]);
}


// ============================================
// REST API: Get users to notify (for VPS)
// ============================================
add_action('rest_api_init', function() {
    
    // Get users with email notifications enabled who should be notified
    register_rest_route('improtectors/v1', '/notify-email', [
        'methods' => 'POST',
        'callback' => 'imp_send_email_notification',
        'permission_callback' => 'imp_verify_notification_secret'
    ]);
    
    // Get notification preferences for a user
    register_rest_route('improtectors/v1', '/notification-prefs/(?P<account>r[1-9A-HJ-NP-Za-km-z]{25,34})', [
        'methods' => 'GET',
        'callback' => 'imp_get_notification_prefs',
        'permission_callback' => 'imp_verify_notification_secret'
    ]);
    
    // Batch get users with email enabled
    register_rest_route('improtectors/v1', '/email-subscribers', [
        'methods' => 'POST',
        'callback' => 'imp_get_email_subscribers',
        'permission_callback' => 'imp_verify_notification_secret'
    ]);
});

// Verify the secret from VPS
function imp_verify_notification_secret($request) {
    $secret = $request->get_header('X-Notification-Secret') ?? $request->get_param('secret') ?? '';
    // SEC (20 Sep 2026): FAIL CLOSED. The old fallback carried a default
    // credential. An empty configured value now fails closed.
    // NOTIFICATION_SECRET is defined in wp-config and the VPS caller reads its
    // own value from its own configuration, itself fail-closed to '',
    // so this branch is never taken in production -- removing the literal is a
    // no-op at runtime and stops distributing the string.
    $expected = defined('NOTIFICATION_SECRET') ? NOTIFICATION_SECRET : '';
    if ($expected === '' || $secret === '') { return false; }
    return $secret === $expected;
}

// Get notification preferences for a single user
function imp_get_notification_prefs($request) {
    global $wpdb;
    $account = $request['account'];
    $profiles_table = $wpdb->prefix . 'xaman_profiles';
    
    $profile = $wpdb->get_row($wpdb->prepare(
        "SELECT notify_email, email, notify_claims, notify_claims_received, notify_events, notify_messages 
         FROM $profiles_table WHERE xrpl_account = %s",
        $account
    ), ARRAY_A);
    
    if (!$profile) {
        return new WP_REST_Response([
            'found' => false,
            'notify_email' => false,
            'email' => null
        ], 200);
    }
    
    return new WP_REST_Response([
        'found' => true,
        'notify_email' => (bool) $profile['notify_email'],
        'email' => $profile['email'] ?: null,
        'notify_claims' => (bool) ($profile['notify_claims'] ?? 1),
        'notify_claims_received' => (bool) ($profile['notify_claims_received'] ?? 1)
    ], 200);
}

// Get all users with email notifications enabled (for batch sending)
function imp_get_email_subscribers($request) {
    global $wpdb;
    $profiles_table = $wpdb->prefix . 'xaman_profiles';
    
    // Get accounts to filter (optional)
    $accounts = $request->get_param('accounts') ?? [];
    
    $query = "SELECT xrpl_account, email, name FROM $profiles_table 
              WHERE notify_email = 1 AND email IS NOT NULL AND email != ''";
    
    if (!empty($accounts) && is_array($accounts)) {
        $placeholders = implode(',', array_fill(0, count($accounts), '%s'));
        $query .= $wpdb->prepare(" AND xrpl_account IN ($placeholders)", ...$accounts);
    }
    
    $results = $wpdb->get_results($query, ARRAY_A);
    
    return new WP_REST_Response([
        'success' => true,
        'subscribers' => $results,
        'count' => count($results)
    ], 200);
}

// Send email notification
function imp_send_email_notification($request) {
    $account = sanitize_text_field($request->get_param('account'));
    $type = sanitize_text_field($request->get_param('type')); // fountain_ready, game_ready, claim_success
    $data = $request->get_param('data') ?? [];
    
    if (!preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $account)) {
        return new WP_REST_Response(['error' => 'Invalid account'], 400);
    }
    
    // Get user's email
    global $wpdb;
    $profiles_table = $wpdb->prefix . 'xaman_profiles';
    $profile = $wpdb->get_row($wpdb->prepare(
        "SELECT email, name, notify_email FROM $profiles_table WHERE xrpl_account = %s",
        $account
    ));
    
    if (!$profile || !$profile->notify_email || !$profile->email) {
        return new WP_REST_Response([
            'success' => false, 
            'error' => 'User has no email or email notifications disabled'
        ], 200);
    }
    
    $email = $profile->email;
    $name = $profile->name ?: 'Protector';
    
    // Build email based on type
    $subject = '';
    $message = '';
    
    switch ($type) {
        case 'fountain_ready':
            $subject = '🎁 Your XFT Rewards Are Ready!';
            $message = imp_build_email_template($name, 'fountain_ready', [
                'title' => 'Your Frequency Fountain rewards are ready!',
                'body' => 'Your 24-hour cooldown has expired. Visit the Frequency Fountain to claim your XFT tokens.',
                'cta_text' => 'Claim XFT Now',
                'cta_url' => 'https://imcollectibles.io/frequency-fountain/'
            ]);
            break;
            
        case 'game_ready':
            $currencies = $data['currencies'] ?? 'XFT & XMEME';
            $subject = '🎮 Your Game Rewards Are Ready!';
            $message = imp_build_email_template($name, 'game_ready', [
                'title' => "Your {$currencies} rewards are ready!",
                'body' => 'Your Champions of Frequencies cooldown has expired. Play now to claim your rewards!',
                'cta_text' => 'Play Now',
                'cta_url' => 'https://imcollectibles.io/champion-of-frequencies/'
            ]);
            break;
            
        case 'claim_success':
            $amount = $data['amount'] ?? '?';
            $currency = $data['currency'] ?? 'XFT';
            $subject = "🎉 You received {$amount} {$currency}!";
            $message = imp_build_email_template($name, 'claim_success', [
                'title' => "You received {$amount} {$currency}!",
                'body' => "Your claim was successful. The tokens have been sent to your wallet. Come back in 24 hours to claim again!",
                'cta_text' => 'View Profile',
                'cta_url' => 'https://imcollectibles.io/'
            ]);
            break;
            
        default:
            return new WP_REST_Response(['error' => 'Invalid notification type'], 400);
    }
    
    // Send email
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: IMProtectors <noreply@imcollectibles.io>'
    ];
    
    $sent = wp_mail($email, $subject, $message, $headers);
    
    if ($sent) {
        xaman_log("Email sent to $email ($account): $type");
        return new WP_REST_Response([
            'success' => true,
            'email' => $email,
            'type' => $type
        ], 200);
    } else {
        xaman_log("Email FAILED to $email ($account): $type");
        return new WP_REST_Response([
            'success' => false,
            'error' => 'wp_mail failed'
        ], 200);
    }
}

// Email template builder
function imp_build_email_template($name, $type, $content) {
    $logo_url = 'https://imcollectibles.io/wp-content/uploads/2025/08/Protectors-Logo.png';
    
    return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
    </head>
    <body style="margin: 0; padding: 0; background-color: #1a1a2e; font-family: Arial, sans-serif;">
        <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #1a1a2e; padding: 20px;">
            <tr>
                <td align="center">
                    <table width="600" cellpadding="0" cellspacing="0" style="max-width: 600px; background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); border: 1px solid #d4af37; border-radius: 12px; overflow: hidden;">
                        <!-- Header -->
                        <tr>
                            <td style="padding: 30px; text-align: center; border-bottom: 1px solid rgba(212, 175, 55, 0.3);">
                                <img src="' . $logo_url . '" alt="IMProtectors" style="width: 80px; height: auto; margin-bottom: 10px;">
                                <h1 style="color: #d4af37; margin: 0; font-size: 24px;">IMProtectors</h1>
                            </td>
                        </tr>
                        
                        <!-- Content -->
                        <tr>
                            <td style="padding: 30px;">
                                <p style="color: #ccc; font-size: 16px; margin: 0 0 10px 0;">Hey ' . esc_html($name) . '! 👋</p>
                                
                                <h2 style="color: #d4af37; font-size: 22px; margin: 20px 0 15px 0;">' . esc_html($content['title']) . '</h2>
                                
                                <p style="color: #aaa; font-size: 14px; line-height: 1.6; margin: 0 0 25px 0;">
                                    ' . esc_html($content['body']) . '
                                </p>
                                
                                <!-- CTA Button -->
                                <table width="100%" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td align="center">
                                            <a href="' . esc_url($content['cta_url']) . '" style="
                                                display: inline-block;
                                                padding: 15px 40px;
                                                background: linear-gradient(135deg, #d4af37 0%, #b8860b 100%);
                                                color: #000;
                                                text-decoration: none;
                                                font-weight: bold;
                                                font-size: 16px;
                                                border-radius: 8px;
                                            ">' . esc_html($content['cta_text']) . '</a>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                        
                        <!-- Footer -->
                        <tr>
                            <td style="padding: 20px 30px; border-top: 1px solid rgba(212, 175, 55, 0.3); text-align: center;">
                                <p style="color: #666; font-size: 12px; margin: 0 0 10px 0;">
                                    You received this email because you enabled email notifications on IMProtectors.
                                </p>
                                <p style="color: #666; font-size: 12px; margin: 0;">
                                    <a href="https://imcollectibles.io/" style="color: #d4af37;">Manage preferences</a>
                                </p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>';
}


// ============================================
// Database Migration: Add notify_email column
// ============================================
add_action('init', function() {
    global $wpdb;
    $profiles_table = $wpdb->prefix . 'xaman_profiles';
    
    // Check if column exists
    $columns = $wpdb->get_results("SHOW COLUMNS FROM $profiles_table LIKE 'notify_email'");
    
    if (empty($columns)) {
        $wpdb->query("ALTER TABLE $profiles_table ADD COLUMN notify_email TINYINT(1) DEFAULT 0");
        xaman_log("Added notify_email column to profiles table");
    }
});


/**
 * NFT Single Page Rewrite Rules
 * Add this to your functions.php
 */

// Register NFT page rewrite rule
function imu_nft_rewrite_rules() {
    // Match /nft/{nft_id}/ where nft_id is hexadecimal
    add_rewrite_rule(
        '^nft/([A-Fa-f0-9]+)/?$',
        'index.php?pagename=nft-single&nft_id=$matches[1]',
        'top'
    );
}
add_action('init', 'imu_nft_rewrite_rules');

// Add nft_id as a query var
function imu_nft_query_vars($vars) {
    $vars[] = 'nft_id';
    return $vars;
}
add_filter('query_vars', 'imu_nft_query_vars');

// Redirect to NFT template
function imu_nft_template_redirect() {
    $nft_id = get_query_var('nft_id');
    if (!empty($nft_id)) {
        // Load the NFT single template
        $template = locate_template('page-nft-single.php');
        if ($template) {
            include($template);
            exit;
        }
    }
}
add_action('template_redirect', 'imu_nft_template_redirect');

/**
 * v707 (Step D): load the trustline checker on the NFT single page.
 *
 * /nft/{id} is reached through the rewrite above (pagename=nft-single), which matches
 * NEITHER existing enqueue path: enqueue_xrpl_marketplace_assets() requires the
 * [xrpl_trading_hub] shortcode in the page content, and enqueue_mint_on_demand_assets()
 * checks a template list that does not include page-nft-single.php. So window.imcTrustline
 * was undefined there, which silently meant:
 *   - loadOfferTokens() got [] and the Make Offer currency dropdown stayed XRP-only
 *   - loadListingTokens() Step 1 got [] so only wallet-held tokens appeared (no platform tokens)
 *   - checkOfferTrustline()/checkListingTrustline() bailed at their guard (fail-open)
 * Server-side gates in create_sell_offer (v653/v653b) were and remain the real enforcement;
 * this restores the client-side affordances only.
 *
 * trustline-checker.js is self-contained: it calls /wp-admin/admin-ajax.php directly
 * (imc_check_trustline, imc_build_trustset, imc_get_tokens -- all registered
 * unconditionally via price-oracle.php + admin-token-manager.php), needs no jQuery, and
 * guards every optional global it touches. So enqueueing it alone is sufficient and safe.
 * wp_enqueue_script() is idempotent per handle, so this cannot double-load if another
 * path ever covers this route. filemtime = auto cache-bust on redeploy.
 */
function imc_nft_single_enqueue_trustline() {
    if (!get_query_var('nft_id')) {
        return;
    }
    $fe_dir = get_stylesheet_directory() . '/xrpl-nft-marketplace/frontend/';
    $tc_fs  = $fe_dir . 'trustline-checker.js';
    if (!file_exists($tc_fs)) {
        return;
    }
    wp_enqueue_script(
        'imc-trustline-checker',
        get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/frontend/trustline-checker.js',
        [],
        filemtime($tc_fs),
        true
    );
}
add_action('wp_enqueue_scripts', 'imc_nft_single_enqueue_trustline');

// === Artist profile pages: /artists/{slug} (Phase 4) ===
function imu_artist_rewrite_rules() {
    add_rewrite_rule('^artists/?$', 'index.php?artist_dir=1', 'top');
    add_rewrite_rule('^artists/([^/]+)/?$', 'index.php?artist_slug=$matches[1]', 'top');
}
add_action('init', 'imu_artist_rewrite_rules');

function imu_artist_query_vars($vars) {
    $vars[] = 'artist_slug';
    $vars[] = 'artist_dir';
    return $vars;
}
add_filter('query_vars', 'imu_artist_query_vars');

function imu_artist_template_redirect() {
    $slug   = get_query_var('artist_slug');
    $is_dir = (get_query_var('artist_dir') === '1');
    if (empty($slug) && !$is_dir) {
        $uri = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/');
        $site_path = trim(parse_url(home_url(), PHP_URL_PATH) ?? '', '/');
        if ($site_path && strpos($uri, $site_path) === 0) {
            $uri = trim(substr($uri, strlen($site_path)), '/');
        }
        if ($uri === 'artists') {
            $is_dir = true;
        } elseif (strpos($uri, 'artists/') === 0) {
            $slug = sanitize_title(substr($uri, strlen('artists/')));
        }
    }
    // Directory listing: /artists
    if ($is_dir && empty($slug)) {
        $tpl = locate_template('page-artists.php');
        if ($tpl && file_exists($tpl)) {
            global $wp_query;
            $wp_query->is_404 = false;
            status_header(200);
            include $tpl;
            exit;
        }
        return;
    }
    // Single artist: /artists/{slug}
    if (empty($slug)) return;
    $tpl = locate_template('page-artist.php');
    if ($tpl && file_exists($tpl)) {
        global $wp_query;
        $wp_query->is_404 = false;
        status_header(200);
        set_query_var('artist_slug', $slug);
        include $tpl;
        exit;
    }
}
add_action('template_redirect', 'imu_artist_template_redirect', 1);


// ===========================================================================
// UNIFIED IMC PAGE ROUTER
// Handles /mint/, /tasks/, /redeem/, /create/ — NO WordPress page required.
// Uses imc_page query var (registered above) to load the correct template.
// Priority 1 = fires before WordPress 404 logic.
// ===========================================================================
function imu_imc_page_template_redirect() {
    $imc_page = get_query_var('imc_page');

    // Fallback: query var may be empty if rewrite rules haven't been flushed.
    // Secondary check via REQUEST_URI so the pages always work.
    if (empty($imc_page)) {
        $uri = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/');
        $site_path = trim(parse_url(home_url(), PHP_URL_PATH) ?? '', '/');
        if ($site_path && strpos($uri, $site_path) === 0) {
            $uri = trim(substr($uri, strlen($site_path)), '/');
        }
        $map = ['mint' => 'mint', 'create' => 'mint', 'tasks' => 'tasks',
                'redeem' => 'redeem', 'terms' => 'terms', 'privacy' => 'privacy'];
        $imc_page = $map[$uri] ?? '';
    }

    if (empty($imc_page)) return;

    $template_map = [
        'mint'    => [
            locate_template('page-mint.php'),
            get_stylesheet_directory() . '/xrpl-nft-marketplace/frontend/page-mint.php',
        ],
        'tasks'   => [locate_template('page-tasks.php')],
        'redeem'  => [locate_template('page-redeem.php')],
        'terms'   => [locate_template('page-terms.php')],
        'privacy' => [locate_template('page-privacy.php')],
    ];

    $candidates = $template_map[$imc_page] ?? [];
    foreach ($candidates as $tpl) {
        if ($tpl && file_exists($tpl)) {
            // Tell WordPress this is NOT a 404
            global $wp_query;
            $wp_query->is_404 = false;
            status_header(200);
            include $tpl;
            exit;
        }
    }
}
add_action('template_redirect', 'imu_imc_page_template_redirect', 1);


/**
 * Auto-flush rewrite rules when the theme is switched/activated.
 * This ensures /mint/, /tasks/, /redeem/ routes work immediately.
 * Also triggered by updating the functions.php file.
 */
function imc_flush_rewrite_on_activate() {
    imu_mint_rewrite_rules(); // includes terms/privacy/tasks/redeem
    imu_nft_rewrite_rules();
    imu_artist_rewrite_rules();
    flush_rewrite_rules(false);
}
add_action('after_switch_theme', 'imc_flush_rewrite_on_activate');

// Safety net: flush once on first load after deploy (clears itself after one run)
function imc_maybe_flush_rewrites() {
    if (get_option('imc_rewrites_flushed_v276') !== '1') {
        flush_rewrite_rules(false);
        update_option('imc_rewrites_flushed_v276', '1', false);
    }
}
add_action('init', 'imc_maybe_flush_rewrites', 999);


/**
 * NFT Metadata AJAX Proxy Handler
 * Add this to your functions.php
 * 
 * This provides a WordPress-based proxy for fetching NFT metadata
 * from the VPS, avoiding any CORS issues for frontend JavaScript.
 */

// Register AJAX handlers
add_action('wp_ajax_get_nft_metadata', 'imu_get_nft_metadata_handler');
add_action('wp_ajax_nopriv_get_nft_metadata', 'imu_get_nft_metadata_handler');

function imu_get_nft_metadata_handler() {
    // Get NFT ID from request
    $nft_id = isset($_GET['nft_id']) ? sanitize_text_field($_GET['nft_id']) : '';
    
    // Validate NFT ID (should be 64 hex characters)
    if (empty($nft_id) || !preg_match('/^[0-9A-Fa-f]{64}$/', $nft_id)) {
        wp_send_json([
            'success' => false,
            'error' => 'Invalid NFT ID format'
        ]);
        return;
    }
    
    // Fetch from VPS (P1d: fetch=fast - bounded miss-path, see page-nft-single)
    $vps_url = 'https://metadata.imcollectibles.io/?action=get&id=' . urlencode($nft_id) . '&fetch=fast';
    
    $response = wp_remote_get($vps_url, [
        'timeout' => 15,
        'sslverify' => true,
        'headers' => [
            'Accept' => 'application/json'
        ]
    ]);
    
    if (is_wp_error($response)) {
        wp_send_json([
            'success' => false,
            'error' => 'Failed to fetch from VPS: ' . $response->get_error_message()
        ]);
        return;
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    
    if ($status_code !== 200) {
        wp_send_json([
            'success' => false,
            'error' => 'VPS returned status ' . $status_code
        ]);
        return;
    }
    
    $data = json_decode($body, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        wp_send_json([
            'success' => false,
            'error' => 'Invalid JSON response from VPS'
        ]);
        return;
    }
    
    // P1d GUARD: fast answers any-mode. Unknown JS consumers expect the historic
    // contract (miss => success:false), so a not-yet-decoded row keeps that shape
    // - with the ledger facts attached additively for consumers that can use them.
    if (!empty($data['nft']['decode_status']) && $data['nft']['decode_status'] !== 'success') {
        wp_send_json([
            'success'       => false,
            'error'         => 'NFT pending decode',
            'decode_status' => $data['nft']['decode_status'],
            'nft'           => $data['nft']
        ]);
        return;
    }
    // Pass through the VPS response
    wp_send_json($data);
}


// Register My NFTs page rewrite rule
function imu_my_nfts_rewrite_rules() {
    add_rewrite_rule(
        '^my-nfts/?$',
        'index.php?pagename=my-nfts',
        'top'
    );
}
add_action('init', 'imu_my_nfts_rewrite_rules');

// Template redirect for /my-nfts URL
function imu_my_nfts_template_redirect() {
    $request_uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    
    if ($request_uri === 'my-nfts') {
        // Load the My NFTs template
        $template = locate_template('page-my-nfts.php');
        if (!$template) {
            // Try page-templates subdirectory
            $template = locate_template('page-templates/page-my-nfts.php');
        }
        if ($template) {
            include $template;
            exit;
        }
    }
}
add_action('template_redirect', 'imu_my_nfts_template_redirect', 5);


// Register IMC page rewrite rules — all three custom pages in one function
// Uses imc_page query var so NO WordPress pages need to exist with these slugs.
function imu_mint_rewrite_rules() {
    add_rewrite_rule('^mint/?$',    'index.php?imc_page=mint',    'top');
    add_rewrite_rule('^create/?$',  'index.php?imc_page=mint',    'top'); // alias for /mint/
    add_rewrite_rule('^tasks/?$',   'index.php?imc_page=tasks',   'top');
    add_rewrite_rule('^redeem/?$',  'index.php?imc_page=redeem',  'top');
    add_rewrite_rule('^terms/?$',   'index.php?imc_page=terms',   'top');
    add_rewrite_rule('^privacy/?$', 'index.php?imc_page=privacy', 'top');
}
add_action('init', 'imu_mint_rewrite_rules');

// Register imc_page as a recognised query var
add_filter('query_vars', function($vars) {
    if (!in_array('imc_page', $vars)) $vars[] = 'imc_page';
    return $vars;
}, 5);

// imu_mint_template_redirect removed — now handled by imu_imc_page_template_redirect above

// Enqueue Mint page assets

// Enqueue Mint page assets
function imu_mint_enqueue_assets() {
    $request_uri = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/');
    
    // Remove site subdirectory if present
    $site_path = trim(parse_url(home_url(), PHP_URL_PATH) ?? '', '/');
    if ($site_path && strpos($request_uri, $site_path) === 0) {
        $request_uri = trim(substr($request_uri, strlen($site_path)), '/');
    }
    
    if ($request_uri !== 'mint') {
        return;
    }

    // v711 (Step I): the artist-side trustline check needs window.imcTrustline here.
    // Buyers pay the ARTIST DIRECTLY (mint-on-demand-handler Destination = artist_account),
    // so an artist who prices a listing in a token they cannot receive breaks every purchase
    // with tecNO_LINE. This is the same self-contained script Step D added to /nft/{id}:
    // it calls /wp-admin/admin-ajax.php directly, needs no jQuery, and guards every optional
    // global. wp_enqueue_script is idempotent per handle, so this cannot double-load.
    $imc_tl_fs = get_stylesheet_directory() . '/xrpl-nft-marketplace/frontend/trustline-checker.js';
    if (file_exists($imc_tl_fs)) {
        wp_enqueue_script(
            'imc-trustline-checker',
            get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/frontend/trustline-checker.js',
            [],
            filemtime($imc_tl_fs),
            true
        );
    }

    $xrpl_account = function_exists('imc_session_wallet') ? imc_session_wallet() : '';
    $has_xrpl_cookie = ($xrpl_account !== '');

    // Auto-bust cache using file modification time
    $fe_dir = get_stylesheet_directory() . '/xrpl-nft-marketplace/frontend/';
    $mint_css_ver = file_exists($fe_dir . 'mint.css') ? filemtime($fe_dir . 'mint.css') : '1.0.33';
    $mod_css_ver  = file_exists($fe_dir . 'mint-on-demand.css') ? filemtime($fe_dir . 'mint-on-demand.css') : '1.0.33';
    $mod_js_ver   = file_exists($fe_dir . 'mint-on-demand.js') ? filemtime($fe_dir . 'mint-on-demand.js') : '1.0.33';
    $mint_js_ver  = file_exists($fe_dir . 'mint.js') ? filemtime($fe_dir . 'mint.js') : '1.0.33';

    // CSS - Trading Hub base styles + Mint specific
    wp_enqueue_style(
        'xrpl-trading-style',
        get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/frontend/trading-hub.css',
        [],
        '1.36'
    );
    
    wp_enqueue_style(
        'xrpl-mint-style',
        get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/frontend/mint.css',
        ['xrpl-trading-style'],
        $mint_css_ver
    );
    
    // =========================================================================
    // CRITICAL: Load mint-on-demand CSS (required for fee payment modal)
    // =========================================================================
    wp_enqueue_style(
        'mint-on-demand-css',
        get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/frontend/mint-on-demand.css',
        ['xrpl-mint-style'],
        $mod_css_ver
    );

    // =========================================================================
    // CRITICAL: Load mint-on-demand JS BEFORE mint.js
    // This provides window.mintOnDemand.createListing() and showFeePaymentModal()
    // =========================================================================
    wp_enqueue_script(
        'mint-on-demand-js',
        get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/frontend/mint-on-demand.js',
        ['jquery'],
        $mod_js_ver,
        true
    );
    
    // Localize mint-on-demand with config
    wp_localize_script('mint-on-demand-js', 'MOD_CONFIG', [
        'account' => $xrpl_account,
        'nonce' => wp_create_nonce('xrpl_marketplace_nonce'),
        'endpoints' => [
            'listings' => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/listings-handler.php',
            'mintOnDemand' => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/mint-on-demand-handler.php',
            'xummProxy' => home_url('/xumm-proxy.php'),
            'authMinter' => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/authorized-minter-handler.php'
        ]
    ]);

    // JS - Mint wizard (MUST depend on mint-on-demand-js!)
    wp_enqueue_script(
        'xrpl-mint-js',
        get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/frontend/mint.js',
        ['jquery', 'mint-on-demand-js'],
        $mint_js_ver,
        true
    );

    // Localize script with config
    wp_localize_script('xrpl-mint-js', 'xrplMarketplace', [
        'ajax_url'     => admin_url('admin-ajax.php'),
        'nonce'        => wp_create_nonce('xrpl_marketplace_nonce'),
        'user_account' => $xrpl_account,
        'endpoints'    => [
            'xummProxy'      => home_url('/xumm-proxy.php'),
            'mintHandler'    => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/mint-handler.php',
            'uploadHandler'  => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/upload-handler.php',
            // R-C3a: removed - see collections.php. Pointed at the LEGACY store
            // and was never read by any script.
            'authMinter'     => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/authorized-minter-handler.php',
            'listings'       => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/listings-handler.php',
            'mintOnDemand'   => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/mint-on-demand-handler.php'
        ],
    ]);
}
add_action('wp_enqueue_scripts', 'imu_mint_enqueue_assets', 20);

// Also register shortcode for flexibility
add_shortcode('xrpl_mint_wizard', 'xrpl_mint_wizard_shortcode');
function xrpl_mint_wizard_shortcode() {
    ob_start();
    $template = get_stylesheet_directory() . '/xrpl-nft-marketplace/frontend/page-mint.php';
    if (file_exists($template)) {
        include $template;
    } else {
        echo '<p>Mint wizard template not found.</p>';
    }
    return ob_get_clean();
}

// ============================================================================
// IMC ROYALTY PLAY TRACKING — AJAX handler for NFT master file plays
// Cross-database: queries IMUTV DB for wallet lookup + play_events writes.
// Credentials defined in wp-config.php as IMUTV_DB_* constants.
// Only eligible users (IMUTV account + connected wallet) earn royalties.
// Called by JS play tracker on page-nft-single.php (master plays only).
// ============================================================================

/**
 * Get a mysqli connection to the IMUTV database.
 * Uses constants from wp-config.php for security.
 */
function imc_get_imutv_db() {
    static $conn = null;
    if ( $conn && $conn->ping() ) return $conn;

    if ( ! defined( 'IMUTV_DB_NAME' ) || ! defined( 'IMUTV_DB_USER' ) ) {
        return null;
    }

    $conn = new mysqli(
        IMUTV_DB_HOST,
        IMUTV_DB_USER,
        IMUTV_DB_PASS,
        IMUTV_DB_NAME
    );

    if ( $conn->connect_error ) {
        error_log( '[IMC Royalty] IMUTV DB connection failed: ' . $conn->connect_error );
        return null;
    }

    $conn->set_charset( 'utf8mb4' );
    return $conn;
}

add_action( 'wp_ajax_imc_royalty_play',        'imc_royalty_play_handler' );
add_action( 'wp_ajax_nopriv_imc_royalty_play', 'imc_royalty_play_handler' );

function imc_royalty_play_handler() {
    // Validate nonce
    if ( ! check_ajax_referer( 'imc_royalty_nonce', '_nonce', false ) ) {
        wp_send_json_error( 'Invalid nonce', 403 );
    }

    // Resolve wallet → WP user_id (cross-check IMUTV user base)
    $rp_auth = function_exists('imc_session_require_wallet')
        ? imc_session_require_wallet('')
        : array('ok' => false, 'wallet' => '');
    $wallet = !empty($rp_auth['ok']) ? $rp_auth['wallet'] : '';
    if ( ! $wallet ) {
        wp_send_json_error( 'No wallet', 401 );
    }

    // Connect to IMUTV database
    $imutv = imc_get_imutv_db();
    if ( ! $imutv ) {
        wp_send_json_error( 'IMUTV database unavailable', 500 );
    }

    $prefix = defined( 'IMUTV_DB_PREFIX' ) ? IMUTV_DB_PREFIX : 'wp_';
    $usermeta_table    = $prefix . 'usermeta';
    $play_events_table = $prefix . 'imup3_play_events';

    // Lookup: wallet → IMUTV user_id
    $stmt = $imutv->prepare(
        "SELECT user_id FROM `$usermeta_table`
         WHERE meta_key = 'xrpl_address' AND meta_value = ? LIMIT 1"
    );
    $stmt->bind_param( 's', $wallet );
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $user_id = $row ? (int) $row['user_id'] : 0;
    $stmt->close();

    if ( ! $user_id ) {
        wp_send_json_error( 'No IMU account linked to this wallet', 401 );
    }

    $action = sanitize_text_field( $_POST['play_action'] ?? '' );

    // ── START ──
    if ( $action === 'start' ) {
        $nft_id   = sanitize_text_field( $_POST['nft_id'] ?? '' );
        $title    = sanitize_text_field( $_POST['title'] ?? '' );
        $category = sanitize_text_field( $_POST['category'] ?? 'music' );

        if ( ! $nft_id ) {
            wp_send_json_error( 'nft_id required', 400 );
        }

        $session_id = wp_generate_uuid4();
        $now = time();

        $stmt = $imutv->prepare(
            "INSERT INTO `$play_events_table`
             (user_id, track_id, title, source, category, started_at, duration_played,
              completed, nft_id, user_owns_nft, session_id, device_type, app_version)
             VALUES (?, ?, ?, 'xrpl_nft_master', ?, ?, 0, 0, ?, 1, ?, 'imc_website', 'imc-1.0')"
        );
        $stmt->bind_param( 'isssiis',
            $user_id, $nft_id, $title, $category, $now, $nft_id, $session_id
        );
        $stmt->execute();
        $play_id = $imutv->insert_id;
        $stmt->close();

        if ( ! $play_id ) {
            wp_send_json_error( 'Insert failed', 500 );
        }

        wp_send_json_success( [ 'play_id' => $play_id ] );
    }

    // ── HEARTBEAT ──
    if ( $action === 'heartbeat' ) {
        $play_id  = (int) ( $_POST['play_id'] ?? 0 );
        $duration = (int) ( $_POST['duration'] ?? 0 );

        if ( ! $play_id ) {
            wp_send_json_error( 'play_id required', 400 );
        }

        // Verify this play belongs to this user + get started_at
        $stmt = $imutv->prepare(
            "SELECT user_id, started_at FROM `$play_events_table` WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param( 'i', $play_id );
        $stmt->execute();
        $result = $stmt->get_result();
        $play = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if ( ! $play || (int) $play['user_id'] !== $user_id ) {
            wp_send_json_error( 'Forbidden', 403 );
        }

        // Clamp duration: max = elapsed + 60s tolerance, hard cap 4h
        $elapsed  = time() - (int) $play['started_at'];
        $duration = min( $duration, $elapsed + 60, 14400 );
        $duration = max( $duration, 0 );

        $stmt = $imutv->prepare(
            "UPDATE `$play_events_table` SET duration_played = ? WHERE id = ?"
        );
        $stmt->bind_param( 'ii', $duration, $play_id );
        $stmt->execute();
        $stmt->close();

        wp_send_json_success( [ 'ok' => true ] );
    }

    // ── END ──
    if ( $action === 'end' ) {
        $play_id  = (int) ( $_POST['play_id'] ?? 0 );
        $duration = (int) ( $_POST['duration'] ?? 0 );

        if ( ! $play_id ) {
            wp_send_json_error( 'play_id required', 400 );
        }

        // Verify ownership + get started_at
        $stmt = $imutv->prepare(
            "SELECT user_id, started_at FROM `$play_events_table` WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param( 'i', $play_id );
        $stmt->execute();
        $result = $stmt->get_result();
        $play = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if ( ! $play || (int) $play['user_id'] !== $user_id ) {
            wp_send_json_error( 'Forbidden', 403 );
        }

        // Clamp duration
        $elapsed  = time() - (int) $play['started_at'];
        $duration = min( $duration, $elapsed + 60, 14400 );
        $duration = max( $duration, 0 );
        $now = time();
        $completed = ( $duration > 30 ) ? 1 : 0;

        $stmt = $imutv->prepare(
            "UPDATE `$play_events_table`
             SET duration_played = ?, ended_at = ?, completed = ?
             WHERE id = ?"
        );
        $stmt->bind_param( 'iiii', $duration, $now, $completed, $play_id );
        $stmt->execute();
        $stmt->close();

        wp_send_json_success( [ 'ok' => true ] );
    }

    wp_send_json_error( 'Invalid action', 400 );
}