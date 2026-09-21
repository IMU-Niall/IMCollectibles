<?php
/**
 * File: trading-hub.php (IMU Marketplace v4.0)
 * Path: /wp-content/themes/astra/xrpl-nft-marketplace/frontend/trading-hub.php
 * Shortcode: [xrpl_trading_hub]
 * 
 * v4.0 FEATURES:
 * - Marketing banner (14-day rotation from WP admin)
 * - Stats section (Top Collections with 24h/7d/30d tabs via Bithomp)
 * - Featured collections (5 admin-configured)
 * - Recent mints (Bithomp API)
 * - Browse collections (VPS indexed)
 * - IMU Collections (moved to last)
 */

// v576 (LCP): resolve the marketing banner BEFORE get_header() emits <head> so we can
// preload it as the high-priority hero image. get_header() below calls wp_head(); registering
// this action first injects a <link rel=preload fetchpriority=high> so the browser fetches the
// LCP image immediately instead of discovering it late in the body. Purely additive: emits
// nothing when no banner is configured. $current_banner is re-resolved at its original line
// below (a cheap get_option read) and is deterministic, so the preloaded URL always matches.
$imu_lcp_banner = function_exists('imu_get_current_banner') ? imu_get_current_banner() : null;
if (!empty($imu_lcp_banner['image_url'])) {
    add_action('wp_head', function () use ($imu_lcp_banner) {
        echo '<link rel="preload" as="image" href="' . esc_url($imu_lcp_banner['image_url']) . '" fetchpriority="high">' . "\n";
    }, 1);
}

get_header();

// Get connected account
$xrpl_account = function_exists('imc_session_wallet') ? imc_session_wallet() : '';
$nonce = wp_create_nonce('xrpl_marketplace_nonce');

// ============================================================================
// v4.0: GET MARKETING DATA
// ============================================================================
$current_banner = function_exists('imu_get_current_banner') ? imu_get_current_banner() : null;
$featured_collections = function_exists('imu_get_featured_collections') ? imu_get_featured_collections() : [];

// v409: Enrich featured collections with IMC listing data (price, editions, mint progress)
if (!empty($featured_collections)) {
    global $wpdb;
    $fc_listings_table = $wpdb->prefix . 'imc_listings';
    if ($wpdb->get_var("SHOW TABLES LIKE '$fc_listings_table'") === $fc_listings_table) {
        foreach ($featured_collections as &$fc) {
            $fc_listings = $wpdb->get_results($wpdb->prepare(
                "SELECT price_xrp, pricing_mode, price_usd, accepted_currencies, total_editions, minted_count, status, edition_type
                 FROM $fc_listings_table
                 WHERE artist_account = %s AND collection_taxon = %d AND status IN ('active', 'sold_out', 'paused') AND is_hidden = 0
                 ORDER BY created_at DESC",
                $fc['issuer'], $fc['taxon']
            ), ARRAY_A);

            if (!empty($fc_listings)) {
                $fc['is_imc'] = true;
                $fc['total_editions'] = 0;
                $fc['minted_count'] = 0;
                $fc['min_price'] = PHP_FLOAT_MAX;
                $fc['all_sold_out'] = true;
                $fc['has_dynamic'] = false;
                $fc['min_price_usd'] = PHP_FLOAT_MAX;
                foreach ($fc_listings as $fl) {
                    $fc['total_editions'] += (int)$fl['total_editions'];
                    $fc['minted_count'] += (int)$fl['minted_count'];
                    if ($fl['status'] !== 'sold_out') $fc['all_sold_out'] = false;
                    if ($fl['pricing_mode'] === 'dynamic' && (float)$fl['price_usd'] > 0) {
                        $fc['has_dynamic'] = true;
                        $fc['min_price_usd'] = min($fc['min_price_usd'], (float)$fl['price_usd']);
                    } elseif ((float)$fl['price_xrp'] > 0) {
                        $fc['min_price'] = min($fc['min_price'], (float)$fl['price_xrp']);
                    } else {
                        // v665: capture the first priced XRPL token so a token-only collection
                        // shows a real price instead of falling through to 0/Free.
                        $th_tok = imc_first_priced_token($fl);
                        if ($th_tok && empty($fc['token_price'])) { $fc['token_price'] = $th_tok; }
                        // v459: When price_xrp=0, check accepted_currencies for real XRP price
                        if (!empty($fl['accepted_currencies'])) {
                            $ac = is_string($fl['accepted_currencies']) ? json_decode($fl['accepted_currencies'], true) : $fl['accepted_currencies'];
                            if (is_array($ac)) {
                                foreach ($ac as $c) {
                                    if (($c['currency'] ?? '') === 'XRP' && ($c['enabled'] ?? false) && floatval($c['price'] ?? 0) > 0) {
                                        $fc['min_price'] = min($fc['min_price'], floatval($c['price']));
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
                $fc['available'] = max(0, $fc['total_editions'] - $fc['minted_count']);
                if ($fc['min_price'] === PHP_FLOAT_MAX) $fc['min_price'] = 0;
                if ($fc['min_price_usd'] === PHP_FLOAT_MAX) $fc['min_price_usd'] = 0;
            } else {
                $fc['is_imc'] = false;
            }
        }
        unset($fc);
    }
}

// Collection definitions - All 6 IMU Collections
$collections = [
    'guardians' => [
        'name' => 'Guardians of the Frequencies',
        'description' => 'The debut NFT collection. 29 unique Guardians.',
        'issuer' => 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR',
        'taxon' => 0,
        'slug' => 'guardians',
        'fallback' => 'https://imcollectibles.io/wp-content/uploads/2025/12/GOTF.png'
    ],
    'frequencies' => [
        'name' => 'Protectors of the Frequencies',
        'description' => 'An expanding universe defending independent music.',
        'issuer' => 'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga',
        'taxon' => 717825,
        'slug' => 'frequencies',
        'fallback' => 'https://imcollectibles.io/wp-content/uploads/2025/12/POTF.png'
    ],
    'ledger' => [
        'name' => 'Protectors of the Ledger',
        'description' => 'Elite intergalactic force safeguarding the XRPL.',
        'issuer' => 'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt',
        'taxon' => 1056369418,
        'slug' => 'ledger',
        'fallback' => 'https://imcollectibles.io/wp-content/uploads/2025/12/POTL.png'
    ],
    'lasvegas' => [
        'name' => 'Protectors of Las Vegas',
        'description' => 'Vegas-themed Protectors hitting the strip.',
        'issuer' => 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR',
        'taxon' => 777,
        'slug' => 'lasvegas',
        'fallback' => 'https://imcollectibles.io/wp-content/uploads/2025/12/POLV.png'
    ],
    'firepit' => [
        'name' => 'Firepit Protectors',
        'description' => 'Exclusive Firepit community collection.',
        'issuer' => 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR',
        'taxon' => 666,
        'slug' => 'firepit',
        'fallback' => 'https://imcollectibles.io/wp-content/uploads/2025/12/Firepit.png'
    ],
    'special' => [
        'name' => 'Special Edition Protectors',
        'description' => 'Limited edition and collaboration pieces.',
        'issuer' => 'rHsrif6nHTkmyh38W7JmYjairPWhq5P3AH',
        'taxon' => 0,
        'slug' => 'special-edition',
        'fallback' => 'https://imcollectibles.io/wp-content/uploads/2025/12/POTF.png'
    ]
];

// Get collection images (auto-first-NFT with admin override)
foreach ($collections as $key => &$col) {
    if (function_exists('imu_get_collection_image')) {
        $col['image'] = imu_get_collection_image($col['issuer'], $col['taxon'], $col['fallback']);
    } else {
        $col['image'] = $col['fallback'];
    }
}
unset($col); // CRITICAL: Break reference

// Build IMU collections map for JavaScript
$imu_collections_js = [];
foreach ($collections as $slug => $col) {
    $key = $col['issuer'] . '_' . $col['taxon'];
    $imu_collections_js[$key] = [
        'name' => $col['name'],
        'slug' => $col['slug'],
        'image' => $col['image'],
        'fallback' => $col['fallback']
    ];
}
$imu_collections_json = json_encode($imu_collections_js);

// Get stats from VPS - v900: NON-BLOCKING. ?action=stats is ~3.6s (nine COUNT
// scans + health + trading) and logged-in renders bypass LiteSpeed page cache,
// so calling it live blocked EVERY logged-in homepage load. Now: read a short
// transient; on a miss ONE render refreshes under a lock (others use the
// fallback). Only REAL data is cached; a failed refresh is throttled by the 15s
// lock, never cached. Public data only - no session interaction, no per-user branch.
$stats = ['total' => 0, 'collections' => 6];
$imc_stats_cache = get_transient('imc_vps_stats_v1');
if (is_array($imc_stats_cache) && isset($imc_stats_cache['total'], $imc_stats_cache['collections'])) {
    $stats = $imc_stats_cache;
} elseif (!get_transient('imc_vps_stats_lock')) {
    set_transient('imc_vps_stats_lock', 1, 15); // throttle refresh to <=1/15s; lock self-expires
    $stats_response = wp_remote_get('https://metadata.imcollectibles.io/?action=stats', ['timeout' => 5]);
    if (!is_wp_error($stats_response) && wp_remote_retrieve_response_code($stats_response) === 200) {
        $stats_body = json_decode(wp_remote_retrieve_body($stats_response), true);
        if (!empty($stats_body['success']) && !empty($stats_body['stats'])) {
            $stats['total'] = $stats_body['stats']['success'] ?? 0;
            $stats['collections'] = $stats_body['stats']['collections'] ?? 4;
            set_transient('imc_vps_stats_v1', $stats, 5 * MINUTE_IN_SECONDS); // cache REAL data only
        }
    }
}

// ============================================================================
// v4.0: ENDPOINTS - Added bithompHandler
// ============================================================================
$endpoints_json = wp_json_encode([
    'xummProxy'          => home_url('/xumm-proxy.php'),
    'offerHandler'       => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/offer-handler.php',
    'watchlistHandler'   => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/watchlist-handler.php',
    'taskHandler'        => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/task-handler.php',
    'filterHandler'      => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/filter-handler.php',
    'collectionsHandler' => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/collections-handler.php',
    'myNftsHandler'      => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/my-nfts-handler.php',
    'bithompHandler'     => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/bithomp-handler.php',
    'imcStatsHandler'    => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/imc-stats-handler.php',
    // R-C3a: removed - pointed at the LEGACY store, never read.
]);

// Query listing nft_types for access filter (maps issuer_taxon → nft_type)
$listing_type_map = [];
global $wpdb;
$lt_table = $wpdb->prefix . 'imc_listings';
if ($wpdb->get_var("SHOW TABLES LIKE '$lt_table'") === $lt_table) {
    $lt_rows = $wpdb->get_results(
        "SELECT artist_account, collection_taxon, MAX(nft_type) AS nft_type
         FROM $lt_table
         WHERE status IN ('active', 'sold_out', 'paused')
         GROUP BY artist_account, collection_taxon",
        ARRAY_A
    );
    if ($lt_rows) {
        foreach ($lt_rows as $row) {
            $ltkey = $row['artist_account'] . '_' . $row['collection_taxon'];
            $listing_type_map[$ltkey] = $row['nft_type'];
        }
    }
}
$listing_type_map_json = wp_json_encode($listing_type_map);
?>

<div id="xrpl-marketplace-root" class="trading-hub-container"
     data-account="<?php echo esc_attr($xrpl_account); ?>"
     data-nonce="<?php echo esc_attr($nonce); ?>"
     data-endpoints='<?php echo $endpoints_json; ?>'
     data-imu-collections='<?php echo esc_attr($imu_collections_json); ?>'
     data-listing-types='<?php echo esc_attr($listing_type_map_json); ?>'>

    <!-- Loading Overlay -->
    <div id="submission-loading-overlay" class="loading-overlay" style="display: none;">
        <div class="loading-spinner"></div>
        <span>Processing transaction...</span>
    </div>

    <!-- Marketplace Navigation Header -->
    <nav class="marketplace-header-nav">
        <a href="<?php echo esc_url(home_url('/trading-hub/')); ?>" class="nav-link active">
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
        <a href="<?php echo esc_url(home_url('/tasks/')); ?>" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            Tasks
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

    <!-- ========================================================================
         v4.0 SECTION 1: MARKETING BANNER (14-day rotation)
         ======================================================================== -->
    <?php if ($current_banner): ?>
    <section class="th-marketing-banner">
        <?php if (!empty($current_banner['link'])): ?>
        <a href="<?php echo esc_url($current_banner['link']); ?>" class="th-banner-link" target="_blank" rel="noopener">
        <?php endif; ?>
            <img src="<?php echo esc_url($current_banner['image_url']); ?>" 
                 alt="<?php echo esc_attr($current_banner['alt'] ?: 'Marketing Banner'); ?>"
                 class="th-banner-image"
                 loading="eager"
                 fetchpriority="high">
        <?php if (!empty($current_banner['link'])): ?>
        </a>
        <?php endif; ?>
    </section>
    <?php endif; ?>
    

    <!-- ========================================================================
         v4.0 SECTION 2: FEATURED COLLECTIONS (Admin-configured)
         Debug: found <?php echo count($featured_collections); ?> featured collections
         ======================================================================== -->
    <?php if (!empty($featured_collections)): ?>
    <section class="th-featured-section">
        <div class="section-header">
            <h2>Featured Collections</h2>
        </div>
        
        <div class="th-featured-grid">
            <?php foreach ($featured_collections as $fc_idx => $col): ?>
            <div class="th-featured-card" 
                 data-issuer="<?php echo esc_attr($col['issuer']); ?>"
                 data-taxon="<?php echo esc_attr($col['taxon']); ?>"
                 onclick="window.location.href='<?php
                    // v409: Use custom link_url if set, otherwise standard collection page
                    if (!empty($col['link_url'])) {
                        echo esc_url($col['link_url']);
                    } else {
                        // v351: use imu_get_collection_slug() — pretty slug for IMU, name-taxon for others
                        $th_slug = function_exists('imu_get_collection_slug')
                            ? imu_get_collection_slug($col['issuer'], $col['taxon'])
                            : ($col['issuer'] . '-' . (int)$col['taxon']);
                        echo esc_url(home_url('/collections/' . $th_slug . '/'));
                    }
                 ?>'">
                <div class="th-card-image">
                    <img src="<?php echo esc_url($col['image']); ?>" 
                         alt="<?php echo esc_attr($col['name']); ?>"
                         loading="<?php echo $fc_idx < 3 ? 'eager' : 'lazy'; ?>"
                         onerror="var _c=this.closest('.collection-card,.th-featured-card,.mint-collection-card');if(_c){_c.classList.add('imc-card-hidden');_c.style.setProperty('display','none','important');}">
                    <?php if (!empty($col['is_imc']) && !empty($col['all_sold_out'])): ?>
                        <span class="mint-sold-out-badge">SOLD OUT</span>
                    <?php endif; ?>
                </div>
                <div class="th-card-info">
                    <h3><?php echo esc_html($col['name']); ?></h3>
                    <?php $_fc_artist = function_exists('imc_resolve_artist_name') ? imc_resolve_artist_name($col['issuer'], $col['artist_name'] ?? '', '') : ($col['artist_name'] ?? ''); if ($_fc_artist !== ''): ?>
                    <p class="mint-card-artist"><?php echo esc_html($_fc_artist); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($col['is_imc'])): ?>
                    <div class="mint-progress-bar-container" style="margin-top:0.5rem;">
                        <?php if ($col['total_editions'] > 0): 
                            $fc_progress = round(($col['minted_count'] / $col['total_editions']) * 100);
                        ?>
                        <div class="mint-progress-bar-bg">
                            <div class="mint-progress-bar-fill" style="width:<?php echo $fc_progress; ?>%"></div>
                        </div>
                        <span class="mint-progress-text"><?php echo $col['minted_count']; ?>/<?php echo $col['total_editions']; ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="mint-card-footer" style="margin-top:0.4rem;">
                        <?php if (!empty($col['has_dynamic']) && $col['min_price_usd'] > 0): ?>
                            <span class="mint-card-price">$<?php echo number_format($col['min_price_usd'], 2); ?> USD</span>
                        <?php elseif ($col['min_price'] > 0): ?>
                            <span class="mint-card-price"><?php echo $col['min_price']; ?> XRP</span>
                        <?php elseif (!empty($col['token_price'])): ?>
                            <?php // v665: no XRP price -> show the first priced XRPL token ?>
                            <span class="mint-card-price"><?php echo esc_html(imc_format_token_amount($col['token_price']['price']) . ' ' . $col['token_price']['currency']); ?></span>
                        <?php endif; ?>
                        <?php if (!$col['all_sold_out'] && $col['available'] > 0): ?>
                            <span class="mint-available-badge"><?php echo $col['available']; ?> left</span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- ========================================================================
         B3: FEATURED SCHEDULED DROPS
         Replaces the old small-card Coming Soon carousel. Wide featured cards with
         a live countdown; prev/next only when more than one drop is pending.
         Hidden by default - loadScheduledMintsCarousel() reveals it only when there
         are active listings with launch_at in the future.
         ======================================================================== -->
    <section class="featured-drops-section" id="featured-drops-section" style="display:none;">
        <div class="section-header">
            <h2>🚀 Dropping Soon</h2>
        </div>
        <div class="featured-drops-viewport">
            <button class="fd-nav fd-nav-prev slider-prev-drops" aria-label="Previous drop" style="display:none;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
            </button>
        <div class="featured-drops-track" id="featured-drops-track"></div>
            <button class="fd-nav fd-nav-next slider-next-drops" aria-label="Next drop" style="display:none;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
            </button>
    </div>
    </section>

    <!-- ========================================================================
         v4.0 SECTION 3: RECENT MINTS (Bithomp API)
         ======================================================================== -->
    <section class="recent-mints-section">
        <div class="section-header">
            <h2>Latest Dropped</h2>
            <div class="th-header-right">
                <select class="browse-filter-select" id="mints-type-filter">
                    <option value="all">All Types</option>
                    <option value="music">🎵 Music Access</option>
                    <option value="musicvideo">🎬 Music Video Access</option>
                    <option value="album">💿 Album Access</option>
                    <option value="art">🎨 Art Access</option>
                    <option value="film">🎥 Film Access</option>
                    <option value="audiobook">🎧 AudioBook Access</option>
                    <option value="ebook">📖 eBook Access</option>
                </select>
                <a href="<?php echo esc_url(home_url('/recent-mints/')); ?>" class="view-all-link">View All →</a>
                <div class="slider-controls">
                    <button class="slider-btn slider-prev-mints" aria-label="Previous">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
                    </button>
                    <button class="slider-btn slider-next-mints" aria-label="Next">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
                    </button>
                </div>
            </div>
        </div>
        
        <div class="collections-slider-wrapper">
            <div class="collections-slider" id="recent-mints-slider">
                <div class="collection-card skeleton-card skeleton-shimmer-card"><div class="nft-image-wrapper skeleton-shimmer"></div><div class="nft-card-info" style="padding:0.75rem"><div class="skeleton-text skeleton-shimmer" style="width:60%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:40%;height:12px;margin-top:0.5rem"></div></div></div>
                <div class="collection-card skeleton-card skeleton-shimmer-card"><div class="nft-image-wrapper skeleton-shimmer"></div><div class="nft-card-info" style="padding:0.75rem"><div class="skeleton-text skeleton-shimmer" style="width:60%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:40%;height:12px;margin-top:0.5rem"></div></div></div>
                <div class="collection-card skeleton-card skeleton-shimmer-card"><div class="nft-image-wrapper skeleton-shimmer"></div><div class="nft-card-info" style="padding:0.75rem"><div class="skeleton-text skeleton-shimmer" style="width:60%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:40%;height:12px;margin-top:0.5rem"></div></div></div>
                <div class="collection-card skeleton-card skeleton-shimmer-card"><div class="nft-image-wrapper skeleton-shimmer"></div><div class="nft-card-info" style="padding:0.75rem"><div class="skeleton-text skeleton-shimmer" style="width:60%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:40%;height:12px;margin-top:0.5rem"></div></div></div>
                <div class="collection-card skeleton-card skeleton-shimmer-card"><div class="nft-image-wrapper skeleton-shimmer"></div><div class="nft-card-info" style="padding:0.75rem"><div class="skeleton-text skeleton-shimmer" style="width:60%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:40%;height:12px;margin-top:0.5rem"></div></div></div>
            </div>
        </div>
    </section>

    <!-- ========================================================================
         v475 SECTION 2C: OPEN MINTS
         Hidden by default — loadOpenMintsCarousel() reveals it only when
         there are active open editions with future open_edition_ends_at.
         Sits between Recent Mints and Coming Soon per design.
         ======================================================================== -->
    <section class="genre-carousel-section" id="open-mints-carousel-section" style="display:none;">
        <div class="section-header">
            <h2>🟢 Open Mints</h2>
            <div class="th-header-right">
                <a href="<?php echo esc_url(home_url('/recent-mints/')); ?>" class="view-all-link">View All →</a>
                <div class="slider-controls">
                    <button class="slider-btn slider-prev-open" aria-label="Previous">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
                    </button>
                    <button class="slider-btn slider-next-open" aria-label="Next">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
                    </button>
                </div>
            </div>
        </div>
        <div class="collections-slider-wrapper">
            <div class="collections-slider" id="open-mints-slider"></div>
        </div>
    </section>

    <!-- ========================================================================
         v568-uxr SECTION 2C-BIS: FIXED COLLECTIONS
         Fixed-size (non-open-edition) listings only. Hidden by default —
         loadFixedCollectionsCarousel() reveals it only when fixed listings exist.
         Sits between Open Mints and Music Access per design.
         ======================================================================== -->
    <section class="genre-carousel-section" id="fixed-collections-section" style="display:none;">
        <div class="section-header">
            <h2>📦 Fixed Collections</h2>
            <div class="th-header-right">
                <a href="<?php echo esc_url(home_url('/recent-mints/')); ?>" class="view-all-link">View All →</a>
                <div class="slider-controls">
                    <button class="slider-btn slider-prev-fixed" aria-label="Previous">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
                    </button>
                    <button class="slider-btn slider-next-fixed" aria-label="Next">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
                    </button>
                </div>
            </div>
        </div>
        <div class="collections-slider-wrapper">
            <div class="collections-slider" id="fixed-collections-slider"></div>
        </div>
    </section>

    <?php
    // ========================================================================
    // B4: DAILY ROW ROTATION
    // The five Access rows below are captured into $imc_rows and emitted in a
    // day-seeded cyclic order, so the hub reads differently each day while every
    // row still appears. Order only - membership never changes, so no creator
    // loses their row. Section ids, slider controls and per-row cache keys are
    // untouched, and trading.js addresses rows by id, so no JS change is needed.
    // ========================================================================
    $imc_rows = [];
    ?>
    <?php ob_start(); ?>
    <!-- ========================================================================
         v409 SECTION 3B: MUSIC ACCESS NFTs
         ======================================================================== -->
    <section class="genre-carousel-section" id="music-carousel-section" style="display:none;">
        <div class="section-header">
            <h2>🎵 Music Access NFTs</h2>
            <div class="th-header-right">
                <a href="<?php echo esc_url(home_url('/recent-mints/')); ?>" class="view-all-link">View All →</a>
                <div class="slider-controls">
                    <button class="slider-btn slider-prev-music" aria-label="Previous">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
                    </button>
                    <button class="slider-btn slider-next-music" aria-label="Next">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
                    </button>
                </div>
            </div>
        </div>
        <div class="collections-slider-wrapper">
            <div class="collections-slider" id="music-access-slider">
                <div class="collection-card skeleton-card skeleton-shimmer-card"><div class="nft-image-wrapper skeleton-shimmer"></div><div class="nft-card-info" style="padding:0.75rem"><div class="skeleton-text skeleton-shimmer" style="width:60%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:40%;height:12px;margin-top:0.5rem"></div></div></div>
                <div class="collection-card skeleton-card skeleton-shimmer-card"><div class="nft-image-wrapper skeleton-shimmer"></div><div class="nft-card-info" style="padding:0.75rem"><div class="skeleton-text skeleton-shimmer" style="width:60%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:40%;height:12px;margin-top:0.5rem"></div></div></div>
                <div class="collection-card skeleton-card skeleton-shimmer-card"><div class="nft-image-wrapper skeleton-shimmer"></div><div class="nft-card-info" style="padding:0.75rem"><div class="skeleton-text skeleton-shimmer" style="width:60%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:40%;height:12px;margin-top:0.5rem"></div></div></div>
            </div>
        </div>
    </section>
    <?php $imc_rows['music'] = ob_get_clean(); ?>
    <?php ob_start(); ?>
    <!-- ========================================================================
         v409 SECTION 3C: ART ACCESS NFTs
         ======================================================================== -->
    <section class="genre-carousel-section" id="art-carousel-section" style="display:none;">
        <div class="section-header">
            <h2>🎨 Art Access NFTs</h2>
            <div class="th-header-right">
                <a href="<?php echo esc_url(home_url('/recent-mints/')); ?>" class="view-all-link">View All →</a>
                <div class="slider-controls">
                    <button class="slider-btn slider-prev-art" aria-label="Previous">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
                    </button>
                    <button class="slider-btn slider-next-art" aria-label="Next">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
                    </button>
                </div>
            </div>
        </div>
        <div class="collections-slider-wrapper">
            <div class="collections-slider" id="art-access-slider">
                <div class="collection-card skeleton-card skeleton-shimmer-card"><div class="nft-image-wrapper skeleton-shimmer"></div><div class="nft-card-info" style="padding:0.75rem"><div class="skeleton-text skeleton-shimmer" style="width:60%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:40%;height:12px;margin-top:0.5rem"></div></div></div>
                <div class="collection-card skeleton-card skeleton-shimmer-card"><div class="nft-image-wrapper skeleton-shimmer"></div><div class="nft-card-info" style="padding:0.75rem"><div class="skeleton-text skeleton-shimmer" style="width:60%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:40%;height:12px;margin-top:0.5rem"></div></div></div>
                <div class="collection-card skeleton-card skeleton-shimmer-card"><div class="nft-image-wrapper skeleton-shimmer"></div><div class="nft-card-info" style="padding:0.75rem"><div class="skeleton-text skeleton-shimmer" style="width:60%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:40%;height:12px;margin-top:0.5rem"></div></div></div>
            </div>
        </div>
    </section>
    <?php $imc_rows['art'] = ob_get_clean(); ?>
    <?php ob_start(); ?>
    <!-- ========================================================================
         v502 SECTION 3D: VIDEO ACCESS NFTs (Music Video + Film)
         Placed after Art Access (3C) to complete the per-type Access rows.
         Hidden by default; the JS loader `loadVideoAccessCarousel()` in
         trading.js reveals this section when at least one musicvideo- or
         film-type listing exists (two parallel get_marketplace fetches,
         merged client-side — backend untouched). The prev/next classes are
         video-specific so they don't collide with the other slider setups.
         ======================================================================== -->
    <section class="genre-carousel-section" id="video-carousel-section" style="display:none;">
        <div class="section-header">
            <h2>🎬 Video Access NFTs</h2>
            <div class="th-header-right">
                <a href="<?php echo esc_url(home_url('/recent-mints/')); ?>" class="view-all-link">View All →</a>
                <div class="slider-controls">
                    <button class="slider-btn slider-prev-video" aria-label="Previous">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
                    </button>
                    <button class="slider-btn slider-next-video" aria-label="Next">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
                    </button>
                </div>
            </div>
        </div>
        <div class="collections-slider-wrapper">
            <div class="collections-slider" id="video-access-slider">
                <div class="collection-card skeleton-card skeleton-shimmer-card"><div class="nft-image-wrapper skeleton-shimmer"></div><div class="nft-card-info" style="padding:0.75rem"><div class="skeleton-text skeleton-shimmer" style="width:60%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:40%;height:12px;margin-top:0.5rem"></div></div></div>
                <div class="collection-card skeleton-card skeleton-shimmer-card"><div class="nft-image-wrapper skeleton-shimmer"></div><div class="nft-card-info" style="padding:0.75rem"><div class="skeleton-text skeleton-shimmer" style="width:60%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:40%;height:12px;margin-top:0.5rem"></div></div></div>
                <div class="collection-card skeleton-card skeleton-shimmer-card"><div class="nft-image-wrapper skeleton-shimmer"></div><div class="nft-card-info" style="padding:0.75rem"><div class="skeleton-text skeleton-shimmer" style="width:60%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:40%;height:12px;margin-top:0.5rem"></div></div></div>
            </div>
        </div>
    </section>
    <?php $imc_rows['video'] = ob_get_clean(); ?>
    <?php ob_start(); ?>
    <!-- ========================================================================
         v467 SECTION 3B-BIS: ALBUM ACCESS NFTs
         Placed between Music Access (3B) and Art Access (3C) to keep the
         audio-oriented sections grouped. Disc icon (💿) distinguishes albums
         from single-track Music Access (🎵). Hidden by default; the JS loader
         `loadAlbumAccessCarousel()` in trading.js reveals this section when
         at least one album-type listing exists. The prev/next classes are
         album-specific so they don't collide with the music/art slider setup.
         ======================================================================== -->
    <section class="genre-carousel-section" id="album-carousel-section" style="display:none;">
        <div class="section-header">
            <h2>💿 Album Access NFTs</h2>
            <div class="th-header-right">
                <a href="<?php echo esc_url(home_url('/recent-mints/')); ?>" class="view-all-link">View All →</a>
                <div class="slider-controls">
                    <button class="slider-btn slider-prev-album" aria-label="Previous">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
                    </button>
                    <button class="slider-btn slider-next-album" aria-label="Next">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
                    </button>
                </div>
            </div>
        </div>
        <div class="collections-slider-wrapper">
            <div class="collections-slider" id="album-access-slider">
                <div class="collection-card skeleton-card skeleton-shimmer-card"><div class="nft-image-wrapper skeleton-shimmer"></div><div class="nft-card-info" style="padding:0.75rem"><div class="skeleton-text skeleton-shimmer" style="width:60%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:40%;height:12px;margin-top:0.5rem"></div></div></div>
                <div class="collection-card skeleton-card skeleton-shimmer-card"><div class="nft-image-wrapper skeleton-shimmer"></div><div class="nft-card-info" style="padding:0.75rem"><div class="skeleton-text skeleton-shimmer" style="width:60%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:40%;height:12px;margin-top:0.5rem"></div></div></div>
                <div class="collection-card skeleton-card skeleton-shimmer-card"><div class="nft-image-wrapper skeleton-shimmer"></div><div class="nft-card-info" style="padding:0.75rem"><div class="skeleton-text skeleton-shimmer" style="width:60%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:40%;height:12px;margin-top:0.5rem"></div></div></div>
            </div>
        </div>
    </section>
    <?php $imc_rows['album'] = ob_get_clean(); ?>
    <?php ob_start(); ?>
    <!-- ========================================================================
         B2: COMICS & BOOKS ACCESS (eBook + AudioBook combined)
         get_marketplace accepts a single `type` per request, so the loader fires
         two parallel fetches (type=ebook, type=audiobook) and merges them client
         side - backend untouched. Mirrors the Video Access row exactly.
         Hidden by default; revealed only when book listings exist.
         ======================================================================== -->
    <section class="genre-carousel-section" id="books-carousel-section" style="display:none;">
        <div class="section-header">
            <h2>📚 Comics &amp; Books Access NFTs</h2>
            <div class="th-header-right">
                <a href="<?php echo esc_url(home_url('/recent-mints/')); ?>" class="view-all-link">View All →</a>
                <div class="slider-controls">
                    <button class="slider-btn slider-prev-books" aria-label="Previous">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
                    </button>
                    <button class="slider-btn slider-next-books" aria-label="Next">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
                    </button>
                </div>
            </div>
        </div>
        <div class="collections-slider-wrapper">
            <div class="collections-slider" id="books-access-slider">
                <div class="collection-card skeleton-card skeleton-shimmer-card"><div class="nft-image-wrapper skeleton-shimmer"></div><div class="collection-info"><div class="skeleton-line skeleton-shimmer"></div><div class="skeleton-line short skeleton-shimmer"></div></div></div>
                <div class="collection-card skeleton-card skeleton-shimmer-card"><div class="nft-image-wrapper skeleton-shimmer"></div><div class="collection-info"><div class="skeleton-line skeleton-shimmer"></div><div class="skeleton-line short skeleton-shimmer"></div></div></div>
                <div class="collection-card skeleton-card skeleton-shimmer-card"><div class="nft-image-wrapper skeleton-shimmer"></div><div class="collection-info"><div class="skeleton-line skeleton-shimmer"></div><div class="skeleton-line short skeleton-shimmer"></div></div></div>
            </div>
        </div>
    </section>
    <?php $imc_rows['books'] = ob_get_clean(); ?>
    <?php
    // Cyclic shift by day-of-year: each row leads once every five days.
    $imc_row_keys = array_keys($imc_rows);
    $imc_day      = function_exists('wp_date') ? (int) wp_date('z') : (int) date('z');
    $imc_shift    = count($imc_row_keys) ? ($imc_day % count($imc_row_keys)) : 0;
    $imc_order    = array_merge(array_slice($imc_row_keys, $imc_shift), array_slice($imc_row_keys, 0, $imc_shift));
    foreach ($imc_order as $imc_key) {
        echo $imc_rows[$imc_key]; // pre-rendered markup, unchanged
    }
    ?>


    <section class="browse-collections-section">
        <div class="section-header">
            <h2>Browse Collections</h2>
            <div class="th-header-right">
                <!-- v141: Dropdown in header-right matching Recent Mints layout -->
                <select class="browse-filter-select" id="browse-filter-select">
                    <option value="all">All</option>
                    <option value="audio">🎵 Music</option>
                    <option value="video">🎬 Video</option>
                    <option value="image">🖼️ Images</option>
                    <option value="music_access">🎵 Music Access</option>
                    <option value="album_access">💿 Album Access</option>
                    <option value="musicvideo_access">🎬 Music Video Access</option>
                    <option value="art_access">🎨 Art Access</option>
                    <option value="film_access">🎥 Film Access</option>
                </select>
                <a href="<?php echo esc_url(home_url('/collections/')); ?>" class="view-all-link">View All →</a>
                <div class="slider-controls">
                    <button class="slider-btn slider-prev-browse" aria-label="Previous">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
                    </button>
                    <button class="slider-btn slider-next-browse" aria-label="Next">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
                    </button>
                </div>
            </div>
        </div>
        
        <div class="collections-slider-wrapper">
            <div class="collections-slider" id="browse-collections-slider">
                <div class="collection-card skeleton-card skeleton-shimmer-card"><div class="nft-image-wrapper skeleton-shimmer"></div><div class="nft-card-info" style="padding:0.75rem"><div class="skeleton-text skeleton-shimmer" style="width:60%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:40%;height:12px;margin-top:0.5rem"></div></div></div>
                <div class="collection-card skeleton-card skeleton-shimmer-card"><div class="nft-image-wrapper skeleton-shimmer"></div><div class="nft-card-info" style="padding:0.75rem"><div class="skeleton-text skeleton-shimmer" style="width:60%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:40%;height:12px;margin-top:0.5rem"></div></div></div>
                <div class="collection-card skeleton-card skeleton-shimmer-card"><div class="nft-image-wrapper skeleton-shimmer"></div><div class="nft-card-info" style="padding:0.75rem"><div class="skeleton-text skeleton-shimmer" style="width:60%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:40%;height:12px;margin-top:0.5rem"></div></div></div>
                <div class="collection-card skeleton-card skeleton-shimmer-card"><div class="nft-image-wrapper skeleton-shimmer"></div><div class="nft-card-info" style="padding:0.75rem"><div class="skeleton-text skeleton-shimmer" style="width:60%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:40%;height:12px;margin-top:0.5rem"></div></div></div>
                <div class="collection-card skeleton-card skeleton-shimmer-card"><div class="nft-image-wrapper skeleton-shimmer"></div><div class="nft-card-info" style="padding:0.75rem"><div class="skeleton-text skeleton-shimmer" style="width:60%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:40%;height:12px;margin-top:0.5rem"></div></div></div>
            </div>
        </div>
    </section>

    <!-- ========================================================================
         v4.0 SECTION 5: IMU COLLECTIONS (Moved to LAST position)
         ======================================================================== -->
    <section id="collections" class="imu-collections-section">
        <div class="section-header">
            <h2>IMU Collections</h2>
            <div class="th-header-right">
                <a href="https://mint.imcollectibles.io/collection" class="view-all-link" target="_blank" rel="noopener">Mint NFTs →</a>
                <div class="slider-controls">
                    <button class="slider-btn slider-prev" aria-label="Previous">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
                    </button>
                    <button class="slider-btn slider-next" aria-label="Next">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
                    </button>
                </div>
            </div>
        </div>
        
        <div class="collections-slider-wrapper">
            <div class="collections-slider" id="imu-collections-slider">
                <?php foreach ($collections as $key => $col): ?>
                <div class="collection-card" 
                     data-slug="<?php echo esc_attr($col['slug']); ?>"
                     data-issuer="<?php echo esc_attr($col['issuer']); ?>"
                     data-taxon="<?php echo esc_attr($col['taxon']); ?>"
                     onclick="window.location.href='<?php echo esc_url(home_url('/collections/' . $col['slug'] . '/')); ?>'">
                    <div class="nft-image-wrapper">
                        <img src="<?php echo esc_url($col['image']); ?>" 
                             alt="<?php echo esc_attr($col['name']); ?>"
                             loading="lazy"
                             onerror="var _c=this.closest('.collection-card,.th-featured-card,.mint-collection-card');if(_c){_c.classList.add('imc-card-hidden');_c.style.setProperty('display','none','important');}">
                    </div>
                    <h3><?php echo esc_html($col['name']); ?></h3>
                    <p><?php echo esc_html($col['description']); ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    
    
        <!-- ========================================================================
         v255 SECTION 6: PLATFORM STATS (IMC-native DB, no external APIs)
         ======================================================================== -->
    <section class="th-stats-section">
        <div class="section-header">
            <div class="th-stats-header-left">
                <h2>Top Collections</h2>
            </div>
            <a href="<?php echo esc_url(home_url('/stats/')); ?>" class="view-all-link">Full Stats →</a>
        </div>
        
        <div class="th-stats-table-wrapper">
            <table class="th-stats-table">
                <thead>
                    <tr>
                        <th class="th-col-rank">#</th>
                        <th class="th-col-collection">Collection</th>
                        <th class="th-col-volume">Mints</th>
                        <th class="th-col-floor">XRP Vol</th>
                        <th class="th-col-sales">Buyers</th>
                    </tr>
                </thead>
                <tbody id="stats-table-body">
                    <tr class="th-loading-row">
                        <td colspan="5">
                            <div class="nft-loading-spinner"></div>
                            <span>Loading stats...</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <button id="stats-expand-btn" class="btn btn-ghost" style="display: none; width: 100%; margin-top: 1rem;">
            Show Top 10 ▼
        </button>
    </section>

    <!-- ========================================================================
         POPUPS (Unchanged from v3.0)
         ======================================================================== -->

    <!-- My Hub Popup -->
    <div id="my-hub-popup" class="popup" style="display: none;">
        <div class="popup-content" style="max-width: 550px;">
            <button class="popup-close">×</button>
            <h3 style="padding: 1.5rem; margin: 0; border-bottom: 1px solid var(--card-border); color: var(--imu-gold);">My Hub</h3>
            <div style="padding: 1.5rem;">
                <a href="<?php echo esc_url(home_url('/trading-hub-dashboard/')); ?>" class="btn btn-primary" style="width: 100%; margin-bottom: 1.5rem;">View Full Dashboard</a>
                
                <div id="outgoing-offers" style="margin-bottom: 1.5rem;">
                    <h4 style="color: var(--imu-gold); margin: 0 0 0.75rem; font-size: 1rem;">Outgoing Offers</h4>
                    <p style="color: var(--text-muted); margin: 0;">Loading...</p>
                </div>
                
                <div id="incoming-offers">
                    <h4 style="color: var(--imu-gold); margin: 0 0 0.75rem; font-size: 1rem;">Incoming Offers</h4>
                    <p style="color: var(--text-muted); margin: 0;">Loading...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- NFT Details Popup -->
    <div id="nft-popup" class="nft-popup" style="display: none;" role="dialog">
        <button class="nft-popup-close">×</button>
        <div class="nft-popup-content">
            <div class="nft-popup-image-wrapper" style="padding: 1rem;">
                <img id="nft-popup-image" src="" alt="NFT" style="width: 100%; border-radius: 8px;">
            </div>
            <div style="padding: 1.5rem;">
                <h3 id="nft-popup-title" style="margin: 0 0 1rem; font-size: 1.25rem;">NFT Title</h3>
                <div style="margin-bottom: 1rem; font-size: 0.9rem; color: var(--text-secondary);">
                    <p style="margin: 0.25rem 0;"><strong>Issuer:</strong> <span id="nft-popup-issuer"></span></p>
                    <p style="margin: 0.25rem 0;"><strong>Owner:</strong> <span id="nft-popup-owner"></span></p>
                </div>
                <div style="margin-bottom: 1rem;">
                    <button id="toggle-description" class="btn btn-ghost btn-sm" style="width: 100%;">Show Description</button>
                    <p id="nft-popup-description" style="display: none; margin-top: 0.75rem; color: var(--text-secondary); font-size: 0.9rem;"></p>
                </div>
                <div style="margin-bottom: 1.5rem;">
                    <button id="toggle-attributes" class="btn btn-ghost btn-sm" style="width: 100%;">Show Attributes</button>
                    <ul id="nft-popup-attributes" style="display: none; margin-top: 0.75rem; list-style: none; padding: 0;"></ul>
                </div>
                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                    <a id="nft-popup-explorer" href="#" target="_blank" class="btn btn-secondary">View on Explorer</a>
                    <button id="nft-popup-make-offer" class="btn btn-primary">Make Offer</button>
                    <button id="nft-popup-compare" class="btn btn-ghost">Compare</button>
                    <button id="nft-popup-message-owner" class="btn btn-ghost">Message Owner</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Offer Popup -->
    <div id="offer-popup" class="nft-popup" style="display: none;" role="dialog">
        <button class="offer-popup-close nft-popup-close">×</button>
        <div class="nft-popup-content">
            <div style="padding: 1.5rem; text-align: center; border-bottom: 1px solid var(--card-border);">
                <div style="width: 100px; height: 100px; margin: 0 auto 1rem; border-radius: 8px; overflow: hidden;">
                    <img id="offer-popup-image" src="" alt="NFT" style="width: 100%; height: 100%; object-fit: cover;">
                </div>
                <h3 id="offer-popup-title" style="margin: 0;">Make an Offer</h3>
            </div>
            <form id="offer-form" style="padding: 1.5rem;">
                <input type="hidden" id="offer-nft-id" name="nft_id">
                <div style="margin-bottom: 1rem;">
                    <label style="display: block; margin-bottom: 0.5rem; color: var(--text-secondary); font-size: 0.9rem;">Offer Amount</label>
                    <div style="display: flex; gap: 0.5rem;">
                        <input type="number" id="offer-amount" step="0.000001" min="0.000001" required style="flex: 1; padding: 0.75rem; background: var(--input-bg); border: 1px solid var(--card-border); border-radius: 6px; color: var(--text-primary); font-size: 1rem;">
                        <select id="offer-currency" style="padding: 0.75rem; background: #1a1a2e; border: 1px solid var(--card-border); border-radius: 6px; color: var(--text-primary);">
                            <option value="XRP">💧 XRP</option>
                            <!-- v86: Tokens loaded dynamically -->
                        </select>
                    </div>
                </div>
                <div style="background: var(--imu-dark); border-radius: 8px; padding: 1rem; margin-bottom: 1rem; font-size: 0.9rem;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.25rem;"><span style="color: var(--text-muted);">Net Amount</span><span id="breakdown-net">-</span></div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.25rem;"><span style="color: var(--text-muted);">Platform Fee (1.5%)</span><span id="breakdown-fee">-</span></div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.25rem;"><span style="color: var(--text-muted);">Royalty</span><span id="breakdown-royalty">-</span></div>
                    <div style="display: flex; justify-content: space-between; margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid var(--card-border);"><span style="color: var(--imu-gold); font-weight: 600;">Total</span><span id="breakdown-total" style="color: var(--imu-gold); font-weight: 600;">-</span></div>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;">Submit Offer</button>
            </form>
        </div>
    </div>

    <!-- QR Code Popup -->
    <div id="qr-code-popup" class="nft-popup" style="display: none;" data-locked="false" role="dialog">
        <div class="nft-popup-content" style="text-align: center; padding: 2rem;">
            <button class="qr-popup-close nft-popup-close">×</button>
            <h3 style="margin: 0 0 0.5rem;">Sign Transaction</h3>
            <p style="color: var(--text-muted); margin: 0 0 1.5rem;">Scan with Xaman app</p>
            <img id="qr-code-image" src="" alt="QR Code" style="max-width: 220px; border-radius: 8px;">
            <p style="margin: 1rem 0 0; font-size: 0.85rem;"><strong>Offer ID:</strong> <span id="qr-offer-id"></span></p>
            <a id="qr-deeplink" href="#" target="_blank" class="btn btn-primary" style="display: block; margin-top: 1.5rem;">Open in Xaman</a>
            <div id="signing-status" style="margin-top: 1rem; color: var(--text-muted);">Waiting for signature...</div>
        </div>
    </div>

    <!-- Compare Popup -->
    <div id="compare-popup" class="nft-popup" style="display: none;" role="dialog">
        <button class="compare-popup-close nft-popup-close">×</button>
        <div class="nft-popup-content" style="max-width: 700px;">
            <h3 style="padding: 1.5rem; margin: 0; border-bottom: 1px solid var(--card-border);">Compare NFTs</h3>
            <div class="compare-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; padding: 1.5rem;">
                <div id="compare-nft-1" style="background: var(--imu-dark); padding: 1rem; border-radius: 8px; text-align: center;">
                    <p style="color: var(--text-muted); margin: 0;">Select an NFT</p>
                </div>
                <div id="compare-nft-2" style="background: var(--imu-dark); padding: 1rem; border-radius: 8px; text-align: center;">
                    <p style="color: var(--text-muted); margin: 0;">Select another NFT</p>
                </div>
            </div>
            <div style="padding: 0 1.5rem 1.5rem; text-align: center;">
                <button id="clear-compare" class="btn btn-ghost">Clear Comparison</button>
            </div>
        </div>
    </div>

    <!-- Toast Container -->
    <div id="toast-container"></div>
    
    <!-- Confetti -->
    <div id="confetti-container"></div>
</div>

<?php get_footer(); ?>