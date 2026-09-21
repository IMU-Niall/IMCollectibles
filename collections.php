<?php
/**
 * Template Name: NFT Collection
 * File: collections.php (IMU Marketplace v3.0)
 * Path: /wp-content/themes/astra/page-templates/collections.php
 * 
 * Two modes:
 * 1. Browse mode: No slug/params → Show all indexed collections
 * 2. Collection mode: Slug or issuer+taxon → Show specific collection NFTs
 */

// ── OG / SEO meta — resolved BEFORE get_header() so wp_head() picks it up ──
// v351: Handles all slug formats. Results cached 6h to avoid per-request VPS calls.
global $imc_og_data;

$_og_slug        = sanitize_text_field(get_query_var('collection_slug'));
$_og_issuer      = sanitize_text_field($_GET['issuer'] ?? '');
$_og_taxon       = isset($_GET['taxon']) ? intval($_GET['taxon']) : null;
$_og_browse_mode = empty($_og_slug) && empty($_og_issuer);

// IMU pretty-slug map (hardcoded, no VPS call needed)
$_og_imu = [
    'guardians'   => ['name' => 'Guardians of the Frequencies',   'desc' => 'The debut NFT collection from the Independent Music Universe. 29 unique Guardians protecting the frequencies of independent music on the XRP Ledger.',   'issuer' => 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR', 'taxon' => 0],
    'frequencies' => ['name' => 'Protectors of the Frequencies',  'desc' => 'An expanding universe of 10,000 Protectors defending the frequencies of independent music. Trade and collect on IMCollectibles.',                      'issuer' => 'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga', 'taxon' => 717825],
    'ledger'      => ['name' => 'Protectors of the Ledger',       'desc' => 'An elite intergalactic force assembled to safeguard independent creativity on the XRP Ledger. Limited edition collection.',                             'issuer' => 'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt', 'taxon' => 1056369418],
    'lasvegas'    => ['name' => 'Protectors of Las Vegas',        'desc' => 'Vegas-themed digital collectibles celebrating the entertainment capital of the world on the XRP Ledger.',                                                'issuer' => 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR', 'taxon' => 777],
    'firepit'     => ['name' => 'Firepit Collection',             'desc' => 'Exclusive Firepit community NFT collection on the XRP Ledger.',                                                                                          'issuer' => 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR', 'taxon' => 666],
];

if ($_og_browse_mode) {
    $imc_og_data = [
        'title'       => 'NFT Collections | IMCollectibles',
        'description' => 'Browse all NFT collections on IMCollectibles. Explore unique music, film, and art NFTs from independent creators on the XRP Ledger.',
        'image'       => defined('IMC_OG_DEFAULT_IMAGE') ? IMC_OG_DEFAULT_IMAGE : '',
        'url'         => esc_url(home_url('/collections/')),
        'type'        => 'website',
    ];
} elseif (!empty($_og_slug) && isset($_og_imu[$_og_slug])) {
    // ── IMU pretty slug ───────────────────────────────────────────────────────
    $_oc    = $_og_imu[$_og_slug];
    $_oimg  = function_exists('imu_get_collection_image')
        ? imu_get_collection_image($_oc['issuer'], (int)$_oc['taxon'], '')
        : '';
    if (empty($_oimg) || strpos($_oimg, 'fallback') !== false) {
        $_oimg = defined('IMC_OG_DEFAULT_IMAGE') ? IMC_OG_DEFAULT_IMAGE : '';
    }
    $imc_og_data = [
        'title'       => esc_attr($_oc['name']) . ' NFT Collection | IMCollectibles',
        'description' => esc_attr(wp_strip_all_tags($_oc['desc'])),
        'image'       => esc_url($_oimg),
        'url'         => esc_url(home_url('/collections/' . $_og_slug . '/')),
        'type'        => 'website',
    ];
} else {
    // ── Dynamic collection: name-slug, issuer+taxon, or rISSUER-TAXON legacy ─
    $_og_ck = 'imc_og_col_' . substr(md5($_og_issuer . ':' . $_og_taxon . ':' . $_og_slug), 0, 16);
    $_og_cached = get_transient($_og_ck);
    if ($_og_cached !== false) {
        $imc_og_data = $_og_cached;
    } else {
        $_oname = ''; $_oimg = ''; $_odesc = ''; $_oissuer = $_og_issuer; $_otaxon = $_og_taxon; $_ourl_slug = $_og_slug;

        // Resolve slug → collection data
        if (!empty($_og_slug)) {
            // Handle legacy rISSUER-TAXON slug
            if (preg_match('/^(r[1-9A-HJ-NP-Za-km-z]{25,34})-(\d+)$/', $_og_slug, $_lm)) {
                $_oissuer   = $_lm[1];
                $_otaxon    = (int)$_lm[2];
                $_ourl_slug = $_og_slug;
            } else {
                // name-taxon slug → Step 1: try VPS collection_by_slug
                $vr = wp_remote_get(
                    'https://metadata.imcollectibles.io/?action=collection_by_slug&slug=' . urlencode($_og_slug),
                    ['timeout' => 4]
                );
                if (!is_wp_error($vr) && wp_remote_retrieve_response_code($vr) === 200) {
                    $vd = json_decode(wp_remote_retrieve_body($vr), true);
                    if (!empty($vd['collection'])) {
                        $_oname   = $vd['collection']['name']        ?? '';
                        $_oimg    = $vd['collection']['image']       ?? '';
                        $_odesc   = $vd['collection']['description'] ?? '';
                        $_oissuer = $vd['collection']['issuer']      ?? '';
                        $_otaxon  = (int)($vd['collection']['taxon'] ?? 0);
                    }
                }

                // Step 2: VPS returned nothing → decompose slug and query wp_imc_collections
                // This covers platform-minted collections not yet indexed on-chain by the VPS.
                if (empty($_oname) && preg_match('/^(.+)-(\d+)$/', $_og_slug, $_sm)) {
                    $_slug_name  = $_sm[1];        // e.g. "be-alright-special-edition"
                    $_slug_taxon = (int)$_sm[2];   // e.g. 1
                    global $wpdb;
                    $ct = $wpdb->prefix . 'imc_collections';
                    if ($wpdb->get_var("SHOW TABLES LIKE '$ct'") === $ct) {
                        $rows = $wpdb->get_results($wpdb->prepare(
                            "SELECT artist_account, collection_name, collection_description, cover_image_ipfs
                             FROM $ct WHERE collection_taxon = %d
                             AND collection_name IS NOT NULL AND collection_name != ''",
                            $_slug_taxon
                        ), ARRAY_A);
                        foreach ($rows as $_row) {
                            $_computed = function_exists('imu_collection_slugify')
                                ? imu_collection_slugify($_row['collection_name'])
                                : strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $_row['collection_name']), '-'));
                            if ($_computed === $_slug_name) {
                                $_oname   = $_row['collection_name'];
                                $_odesc   = $_row['collection_description'] ?? '';
                                $_oissuer = $_row['artist_account'];
                                $_otaxon  = $_slug_taxon;
                                // Use the authoritative image resolver — same as IMU pretty-slug path.
                                // imu_get_collection_image() handles admin overrides, correct
                                // img.php routing, and transient caching. Never build the URL manually.
                                if (function_exists('imu_get_collection_image')) {
                                    $_oimg = imu_get_collection_image($_oissuer, (int)$_otaxon, '');
                                    if (empty($_oimg) || strpos($_oimg, 'fallback') !== false) {
                                        $_oimg = '';
                                    }
                                }
                                break;
                            }
                        }
                    }
                }
            }
        }

        // If we now have issuer+taxon but still no name (legacy rISSUER-TAXON path), hit VPS directly
        if (empty($_oname) && !empty($_oissuer) && $_otaxon !== null) {
            $vr2 = wp_remote_get(
                'https://metadata.imcollectibles.io/?action=collection&issuer=' . urlencode($_oissuer) . '&taxon=' . $_otaxon,
                ['timeout' => 4]
            );
            if (!is_wp_error($vr2) && wp_remote_retrieve_response_code($vr2) === 200) {
                $vd2 = json_decode(wp_remote_retrieve_body($vr2), true);
                if (!empty($vd2['collection'])) {
                    $_oname = $vd2['collection']['name']        ?? '';
                    $_odesc = $vd2['collection']['description'] ?? '';
                    if (empty($_oimg)) {
                        $_raw2  = $vd2['collection']['image'] ?? '';
                        $_oimg  = $_raw2 ? 'https://metadata.imcollectibles.io/img.php?url=' . urlencode($_raw2) : '';
                    }
                }
            }
        }

        // v644: DB fallback for issuer+taxon OG when VPS action=collection returns nothing.
        // Resolves the real card from the same sources the collections body trusts:
        //   • name/description/cover from wp_imc_collections (artist-uploaded cover — the
        //     same table the v235 get_marketplace cover override reads)
        //   • per-listing collection_name + listing cover from wp_imc_listings as a secondary source
        // Purely additive; runs only when the VPS lookup left the name empty. Empty-guarded
        // throughout so it never overrides a value the VPS already provided. Fixes
        // ?issuer=&taxon= share links that previously rendered a doubled title + default image.
        if (empty($_oname) && !empty($_oissuer) && $_otaxon !== null) {
            global $wpdb;

            $_ogct = $wpdb->prefix . 'imc_collections';
            if ($wpdb->get_var("SHOW TABLES LIKE '$_ogct'") === $_ogct) {
                $_ogcr = $wpdb->get_row($wpdb->prepare(
                    "SELECT collection_name, collection_description, cover_image_ipfs
                     FROM $_ogct WHERE artist_account = %s AND collection_taxon = %d LIMIT 1",
                    $_oissuer, (int)$_otaxon
                ), ARRAY_A);
                if ($_ogcr) {
                    if (empty($_oname) && !empty($_ogcr['collection_name']))        $_oname = $_ogcr['collection_name'];
                    if (empty($_odesc) && !empty($_ogcr['collection_description'])) $_odesc = $_ogcr['collection_description'];
                    if (empty($_oimg)  && !empty($_ogcr['cover_image_ipfs'])) {
                        $_oimg = 'https://metadata.imcollectibles.io/img.php?url=' . urlencode($_ogcr['cover_image_ipfs']);
                    }
                }
            }

            // Secondary: the per-listing collection_name is authoritative for display
            // (the body shows this) — use it if the collections row had no name.
            if (empty($_oname)) {
                $_oglt = $wpdb->prefix . 'imc_listings';
                if ($wpdb->get_var("SHOW TABLES LIKE '$_oglt'") === $_oglt) {
                    $_oglr = $wpdb->get_row($wpdb->prepare(
                        "SELECT collection_name, cover_ipfs
                         FROM $_oglt
                         WHERE artist_account = %s AND collection_taxon = %d
                         AND collection_name IS NOT NULL AND collection_name != ''
                         ORDER BY id ASC LIMIT 1",
                        $_oissuer, (int)$_otaxon
                    ), ARRAY_A);
                    if ($_oglr) {
                        if (empty($_oname)) $_oname = $_oglr['collection_name'] ?? '';
                        if (empty($_oimg) && !empty($_oglr['cover_ipfs'])) {
                            $_oimg = 'https://metadata.imcollectibles.io/img.php?url=' . urlencode($_oglr['cover_ipfs']);
                        }
                    }
                }
            }
        }

        // Sanitise image — route all IPFS and Pinata URLs through img.php proxy.
        // Twitter/X crawlers are blocked by Pinata's rate limiter. img.php fetches with
        // our authenticated JWT, caches to disk, and serves correct Content-Type headers.
        if (!empty($_oimg) && strpos($_oimg, 'metadata.imcollectibles.io/img.php') === false) {
            if (strpos($_oimg, 'ipfs://') === 0 || strpos($_oimg, 'mypinata.cloud') !== false || strpos($_oimg, 'ipfs.io') !== false) {
                $_oimg = 'https://metadata.imcollectibles.io/img.php?url=' . urlencode($_oimg);
            }
        }

        // Build clean URL
        if (empty($_ourl_slug) && !empty($_oname) && $_otaxon !== null) {
            $_ourl_slug = function_exists('imu_collection_slugify')
                ? imu_collection_slugify($_oname) . '-' . $_otaxon
                : preg_replace('/[^a-z0-9-]/', '', strtolower(str_replace(' ', '-', $_oname))) . '-' . $_otaxon;
        }

        if (empty($_oname))  $_oname = 'NFT Collection';
        if (empty($_oimg))   $_oimg  = defined('IMC_OG_DEFAULT_IMAGE') ? IMC_OG_DEFAULT_IMAGE : '';
        if (empty($_odesc))  $_odesc = $_oname . ' — NFT collection on the XRP Ledger. Trade and collect exclusive media on IMCollectibles.';

        $imc_og_data = [
            'title'       => ($_oname === 'NFT Collection')
                ? 'NFT Collection | IMCollectibles'
                : esc_attr($_oname) . ' NFT Collection | IMCollectibles',
            'description' => esc_attr(wp_strip_all_tags($_odesc)),
            'image'       => esc_url($_oimg),
            'url'         => esc_url(!empty($_ourl_slug)
                ? home_url('/collections/' . $_ourl_slug . '/')
                : home_url('/collections/?issuer=' . urlencode($_oissuer) . '&taxon=' . $_otaxon)),
            'type'        => 'website',
        ];
        set_transient($_og_ck, $imc_og_data, 6 * HOUR_IN_SECONDS);
    }
}

get_header();

// Get XRPL account
$xrpl_account = function_exists('imc_session_wallet') ? imc_session_wallet() : '';
$nonce = wp_create_nonce('xrpl_marketplace_nonce');

// Parse parameters
$slug = sanitize_text_field(get_query_var('collection_slug'));
$issuer_param = sanitize_text_field($_GET['issuer'] ?? '');
$taxon_param = isset($_GET['taxon']) ? intval($_GET['taxon']) : null;

// Determine mode
$browse_mode = empty($slug) && empty($issuer_param);

// Collection definitions (IMU Collections)
$collections = [
    'guardians' => [
        'issuer' => 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR',
        'taxon' => 0,
        'name' => 'Guardians of the Frequencies',
        'description' => 'The debut NFT collection from the Independent Music Universe. 29 unique Guardians protecting the frequencies of independent music.',
        'fallback_image' => 'https://imcollectibles.io/wp-content/uploads/guardians-fallback.jpg'
    ],
    'frequencies' => [
        'issuer' => 'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga',
        'taxon' => 717825,
        'name' => 'Protectors of the Frequencies',
        'description' => 'An expanding universe of Protectors defending the frequencies of independent music across the metaverse.',
        'fallback_image' => 'https://imcollectibles.io/wp-content/uploads/protectors-fallback.jpg'
    ],
    'ledger' => [
        'issuer' => 'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt',
        'taxon' => 1056369418,
        'name' => 'Protectors of the Ledger',
        'description' => 'An elite, intergalactic force assembled from planets and galaxies far and wide to safeguard the purity and integrity of independent creativity on the XRPL.',
        'fallback_image' => 'https://images.imcollectibles.io/ledger/protector-1.png'
    ],
    'lasvegas' => [
        'issuer' => 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR',
        'taxon' => 777,
        'name' => 'Protectors of Las Vegas',
        'description' => 'Vegas-themed digital collectibles celebrating the entertainment capital of the world.',
        'fallback_image' => 'https://imcollectibles.io/wp-content/uploads/lasvegas-fallback.jpg'
    ],
    'firepit' => [
        'issuer' => 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR',
        'taxon' => 666,
        'name' => 'Firepit Collection',
        'description' => 'Exclusive Firepit community collection.',
        'fallback_image' => '/wp-content/uploads/fallback-nft.svg'
    ]
];

// Build IMU collections map with real images for JavaScript
$imu_collections_js = [];
foreach ($collections as $coll_key => $col) {
    $key = $col['issuer'] . '_' . $col['taxon'];
    $image = $col['fallback_image'];
    
    // Try to get real collection image
    if (function_exists('imu_get_collection_image')) {
        $image = imu_get_collection_image($col['issuer'], $col['taxon'], $col['fallback_image']);
    }
    
    $imu_collections_js[$key] = [
        'name' => $col['name'],
        'image' => $image,
        'description' => $col['description'],
        'slug' => $coll_key,
        'is_imu' => true,
        'priority' => 1
    ];
}
$imu_collections_json = wp_json_encode($imu_collections_js);

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

// Endpoints for JavaScript
$endpoints_json = wp_json_encode([
    'xummProxy'          => home_url('/xumm-proxy.php'),
    'offerHandler'       => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/offer-handler.php',
    'watchlistHandler'   => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/watchlist-handler.php',
    'taskHandler'        => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/task-handler.php',
    'filterHandler'      => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/filter-handler.php',
    'collectionsHandler' => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/collections-handler.php',
    'myNftsHandler'      => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/my-nfts-handler.php',
    'allowlistHandler'   => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/allowlist-handler.php',
    'bithompHandler'     => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/bithomp-handler.php',
    'listingsHandler'    => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/listings-handler.php'
    // R-C3a (08 Sep 2026): 'metadataApi' removed. It pointed at
    // metadata.imcollectibles.io/api/ -> api/index.php -> data/nft_metadata.db,
    // the LEGACY store fed by one cron hardcoded to five collections. The root
    // (/?action=...) is indexer.php -> the universal metadata index.
    // Verified dead: this array is serialised to data-endpoints and parsed into
    // window.xrplMarketplace.endpoints, but the ONLY properties JS ever reads are
    // collectionsHandler, filterHandler, listingsHandler, myNftsHandler,
    // offerHandler, watchlistHandler and xummProxy. Removed so no future loader
    // (CP-C3's Activity/Stats/Holders tabs) can reach for it and silently query
    // a five-collection database.
]);

// ============================================================
// BROWSE MODE - Show all collections
// ============================================================
if ($browse_mode):
?>

<div id="collections-browse-page" class="trading-hub-container"
     data-account="<?php echo esc_attr($xrpl_account); ?>"
     data-nonce="<?php echo esc_attr($nonce); ?>"
     data-endpoints='<?php echo $endpoints_json; ?>'
     data-imu-collections='<?php echo esc_attr($imu_collections_json); ?>'
     data-listing-types='<?php echo esc_attr($listing_type_map_json); ?>'>

    <!-- Marketplace Navigation Header -->
    <nav class="marketplace-header-nav">
        <a href="<?php echo esc_url(home_url('/trading-hub/')); ?>" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
            Trading Hub
        </a>
        <a href="<?php echo esc_url(home_url('/collections/')); ?>" class="nav-link active">
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

    <section class="collections-browse-hero">
        <h1>Browse Collections</h1>
        <p>Discover NFT collections from across the XRP Ledger</p>
    </section>

    <!-- Filter Controls -->
    <section class="collections-filter-section">
        <div class="filter-controls">
            <div class="filter-group">
                <label>Content Type</label>
                <select id="content-type-filter">
                    <option value="all">All Types</option>
                    <option value="audio">🎵 Music</option>
                    <option value="video">🎬 Video</option>
                    <option value="image">🖼️ Images</option>
                    <option value="music_access">🎵 Music Access</option>
                    <option value="album_access">💿 Album Access</option>
                    <option value="musicvideo_access">🎬 Music Video Access</option>
                    <option value="art_access">🎨 Art Access</option>
                    <option value="film_access">🎥 Film Access</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Sort By</label>
                <select id="collections-sort">
                    <option value="shuffle" selected>Shuffle (Random)</option>
                    <option value="priority">Featured First</option>
                    <option value="count-desc">Most NFTs</option>
                    <option value="count-asc">Fewest NFTs</option>
                    <option value="name-asc">Name A-Z</option>
                </select>
            </div>
            <div class="filter-group search-group">
                <input type="text" id="collections-search" placeholder="Search collections...">
            </div>
        </div>
    </section>

    <!-- Collections Grid -->
    <section class="all-collections-section">
        <div id="collections-count" class="collections-count"></div>
        <div id="all-collections-grid" class="collections-browse-grid">
            <div class="browse-collection-card skeleton-card skeleton-shimmer-card"><div class="card-image skeleton-shimmer" style="aspect-ratio:16/10"></div><div class="card-info"><div class="skeleton-text skeleton-shimmer" style="width:65%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:90%;height:12px;margin-top:0.5rem"></div></div></div>
            <div class="browse-collection-card skeleton-card skeleton-shimmer-card"><div class="card-image skeleton-shimmer" style="aspect-ratio:16/10"></div><div class="card-info"><div class="skeleton-text skeleton-shimmer" style="width:65%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:90%;height:12px;margin-top:0.5rem"></div></div></div>
            <div class="browse-collection-card skeleton-card skeleton-shimmer-card"><div class="card-image skeleton-shimmer" style="aspect-ratio:16/10"></div><div class="card-info"><div class="skeleton-text skeleton-shimmer" style="width:65%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:90%;height:12px;margin-top:0.5rem"></div></div></div>
            <div class="browse-collection-card skeleton-card skeleton-shimmer-card"><div class="card-image skeleton-shimmer" style="aspect-ratio:16/10"></div><div class="card-info"><div class="skeleton-text skeleton-shimmer" style="width:65%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:90%;height:12px;margin-top:0.5rem"></div></div></div>
            <div class="browse-collection-card skeleton-card skeleton-shimmer-card"><div class="card-image skeleton-shimmer" style="aspect-ratio:16/10"></div><div class="card-info"><div class="skeleton-text skeleton-shimmer" style="width:65%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:90%;height:12px;margin-top:0.5rem"></div></div></div>
            <div class="browse-collection-card skeleton-card skeleton-shimmer-card"><div class="card-image skeleton-shimmer" style="aspect-ratio:16/10"></div><div class="card-info"><div class="skeleton-text skeleton-shimmer" style="width:65%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:90%;height:12px;margin-top:0.5rem"></div></div></div>
            <div class="browse-collection-card skeleton-card skeleton-shimmer-card"><div class="card-image skeleton-shimmer" style="aspect-ratio:16/10"></div><div class="card-info"><div class="skeleton-text skeleton-shimmer" style="width:65%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:90%;height:12px;margin-top:0.5rem"></div></div></div>
            <div class="browse-collection-card skeleton-card skeleton-shimmer-card"><div class="card-image skeleton-shimmer" style="aspect-ratio:16/10"></div><div class="card-info"><div class="skeleton-text skeleton-shimmer" style="width:65%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:90%;height:12px;margin-top:0.5rem"></div></div></div>
            <div class="browse-collection-card skeleton-card skeleton-shimmer-card"><div class="card-image skeleton-shimmer" style="aspect-ratio:16/10"></div><div class="card-info"><div class="skeleton-text skeleton-shimmer" style="width:65%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:90%;height:12px;margin-top:0.5rem"></div></div></div>
            <div class="browse-collection-card skeleton-card skeleton-shimmer-card"><div class="card-image skeleton-shimmer" style="aspect-ratio:16/10"></div><div class="card-info"><div class="skeleton-text skeleton-shimmer" style="width:65%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:90%;height:12px;margin-top:0.5rem"></div></div></div>
        </div>
        <!-- v331: Prev/Next pagination -->
        <div id="browse-pagination" class="browse-pagination" style="display:none;">
            <button id="browse-prev-btn" class="browse-page-btn" disabled>← Prev</button>
            <span id="browse-page-info" class="browse-page-info"></span>
            <button id="browse-next-btn" class="browse-page-btn">Next →</button>
        </div>
    </section>

</div>

<style>
.browse-pagination {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 1.25rem;
    padding: 2rem 1rem 3rem;
    flex-wrap: wrap;
}
.browse-page-btn {
    padding: 0.6rem 1.5rem;
    background: transparent;
    border: 1px solid var(--imu-gold, var(--imu-gold, #d6ba66));
    color: var(--imu-gold, var(--imu-gold, #d6ba66));
    border-radius: 8px;
    cursor: pointer;
    font-size: 0.9rem;
    font-weight: 600;
    transition: background 0.2s, color 0.2s;
}
.browse-page-btn:hover:not(:disabled) {
    background: var(--imu-gold, var(--imu-gold, #d6ba66));
    color: #000;
}
.browse-page-btn:disabled {
    opacity: 0.3;
    cursor: default;
}
.browse-page-info {
    font-size: 0.9rem;
    color: var(--text-secondary, #aaa);
    min-width: 140px;
    text-align: center;
}
.collections-browse-hero {
    text-align: center;
    padding: 3rem 1rem;
}
.health-badge {
    position: absolute;
    top: 8px;
    right: 8px;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 700;
    backdrop-filter: blur(4px);
}
.health-good { background: rgba(16,185,129,0.85); color: #fff; }
.health-ok { background: rgba(245,158,11,0.85); color: #fff; }
.health-low { background: rgba(239,68,68,0.5); color: #fff; }
.card-image { position: relative; }
.collections-browse-hero h1 {
    font-size: 2.5rem;
    margin: 0 0 0.5rem;
    font-family: 'Cinzel Decorative', 'Cinzel', 'Times New Roman', serif;
    font-weight: 700;
    letter-spacing: 0.07em;
    background: linear-gradient(180deg, #ffe066 0%, var(--imu-gold, #d6ba66) 40%, #996515 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    filter: drop-shadow(0 0 18px rgba(212, 175, 55, 0.4));
}
.collections-browse-hero p {
    color: var(--text-secondary);
    font-size: 1.1rem;
    margin: 0;
}
.collections-filter-section {
    padding: 1.5rem;
    border-bottom: 1px solid var(--card-border);
}
.filter-controls {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    align-items: flex-end;
    max-width: 1200px;
    margin: 0 auto;
}
.filter-group {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}
.filter-group label {
    font-size: 0.8rem;
    color: var(--text-muted);
    text-transform: uppercase;
}
.filter-group select,
.filter-group input {
    padding: 0.75rem 2.25rem 0.75rem 1rem;
    background: var(--card-bg);
    border: 1px solid var(--card-border);
    border-radius: var(--radius-md);
    color: var(--text-primary);
    min-width: 150px;
    line-height: 1.4;
    min-height: 46px;
    height: auto;
    font-size: 0.95rem;
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23d6ba66' stroke-width='2.5'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 0.75rem center;
}
.filter-group input {
    background-image: none !important;
    padding-right: 1rem;
}
/* v240: Search input — force dark background so text is readable on all browsers */
.filter-group.search-group {
    flex: 1;
    min-width: 200px;
}
.filter-group.search-group input,
#collections-search {
    width: 100%;
    background: var(--card-bg) !important;
    background-color: #12121a !important;
    color: var(--text-primary) !important;
    border: 1px solid var(--card-border) !important;
    border-radius: var(--radius-md) !important;
    caret-color: #d4af37 !important;
}
#collections-search::placeholder {
    color: rgba(255,255,255,0.35) !important;
}
#collections-search:focus {
    outline: none !important;
    border-color: rgba(212,175,55,0.6) !important;
    box-shadow: 0 0 0 2px rgba(212,175,55,0.15) !important;
    background-color: #1a1a2e !important;
}
/* Kill browser autofill white override */
#collections-search:-webkit-autofill,
#collections-search:-webkit-autofill:focus {
    -webkit-box-shadow: 0 0 0 1000px #12121a inset !important;
    -webkit-text-fill-color: #ffffff !important;
    caret-color: #d4af37 !important;
}
.all-collections-section {
    padding: 2rem 1rem;
    width: 100%;
}
.collections-browse-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(209px, 1fr));
    gap: 1.5rem;
    width: 100%;
    max-width: 100%;
    padding: 0 1.5rem;
}
.browse-collection-card {
    background: linear-gradient(160deg, #08080e 0%, #0e0e16 60%, #141420 100%);
    border: 1px solid rgba(212, 175, 55, 0.15);
    border-radius: 12px;
    overflow: hidden;
    transition: all 0.3s ease;
    text-decoration: none;
    display: block;
    order: 0;
}
.browse-collection-card.image-broken {
    order: 9999;
    opacity: 0.45;
}
.browse-collection-card.image-broken:hover {
    opacity: 0.7;
}
.browse-collection-card:hover {
    transform: translateY(-4px);
    border-color: var(--imu-gold, var(--imu-gold, #d6ba66));
    box-shadow: 0 8px 24px rgba(212, 175, 55, 0.2);
}
.browse-collection-card .card-image {
    aspect-ratio: 1 / 0.95;
    overflow: hidden;
    background: var(--imu-dark, #0d0d1a);
}
.browse-collection-card .card-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s ease;
}
.browse-collection-card:hover .card-image img {
    transform: scale(1.05);
}
.browse-collection-card .card-info {
    padding: 1rem;
}
.browse-collection-card .card-info h4 {
    margin: 0 0 0.5rem;
    font-size: 1rem;
    color: var(--imu-gold, var(--imu-gold, #d6ba66));
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.browse-collection-card .card-stats {
    display: flex;
    gap: 0.75rem;
    font-size: 0.85rem;
    color: var(--text-muted, #888);
}
.browse-collection-card.is-imu {
    border-color: var(--imu-gold, var(--imu-gold, #d6ba66));
}
.browse-collection-card.is-music .card-info h4::before {
    content: '♪ ';
}
.collections-count {
    text-align: center;
    padding: 0 1rem 1rem;
    color: var(--text-muted, #888);
    font-size: 0.9rem;
}
/* Override trading-hub.css restriction - show ALL cards */
.collections-browse-grid .browse-collection-card:nth-child(n+11),
.collections-browse-grid .browse-collection-card:nth-child(n+7) {
    display: block !important;
}
.no-collections-message {
    grid-column: 1 / -1;
    text-align: center;
    padding: 3rem;
    color: var(--text-muted, #888);
}

/* ============================================================
   MINT STATUS BANNER
   ============================================================ */
.mint-status-banner {
    background: linear-gradient(160deg, #08080e 0%, #0e0e16 60%, #141420 100%);
    border: 1px solid rgba(99, 102, 241, 0.3);
    border-radius: 16px;
    padding: 1.25rem 1.5rem;
    margin: 0 1rem 1.5rem;
    max-width: 1200px;
    margin-left: auto;
    margin-right: auto;
}

.mint-status-banner.active {
    border-color: rgba(16, 185, 129, 0.4);
    box-shadow: 0 4px 20px rgba(16, 185, 129, 0.1);
}

.mint-status-banner.sold-out {
    border-color: rgba(239, 68, 68, 0.3);
}

.mint-banner-inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1.5rem;
    flex-wrap: wrap;
}

/* Status Badge */
.mint-badge {
    padding: 0.5rem 1rem;
    border-radius: 9999px;
    font-weight: 600;
    font-size: 0.85rem;
    white-space: nowrap;
}

.mint-badge-live {
    background: rgba(16, 185, 129, 0.2);
    color: #10b981;
    animation: mint-pulse 2s infinite;
}

@keyframes mint-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4); }
    50% { box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
}

.mint-badge-sold-out {
    background: rgba(239, 68, 68, 0.2);
    color: #ef4444;
}

/* Progress Section */
.mint-banner-progress {
    flex: 1;
    min-width: 200px;
    max-width: 400px;
}

.mint-progress-stats {
    display: flex;
    justify-content: space-between;
    margin-bottom: 0.5rem;
    font-size: 0.85rem;
    color: var(--text-muted, #888);
}

.mint-progress-stats strong {
    color: var(--text-primary, #fff);
}

.mint-banner-collected {
    text-align: center;
    color: #00ff00;
    font-weight: 600;
    font-size: 0.85rem;
    margin: 0.4rem 0 0;
}
.mint-banner-collected-none {
    color: #ff3b30;
    font-weight: 500;
}

.mint-progress-percent {
    color: var(--imu-gold, var(--imu-gold, #d6ba66));
}

.mint-progress-bar-wrapper {
    height: 6px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 3px;
    overflow: hidden;
}

.mint-progress-bar {
    height: 100%;
    background: linear-gradient(90deg, var(--imu-gold, var(--imu-gold, #d6ba66)), #10b981);
    border-radius: 3px;
    transition: width 0.5s ease;
}

/* Action Section */
.mint-banner-action {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.mint-price-info {
    text-align: right;
}

.mint-price-label {
    display: block;
    font-size: 0.75rem;
    color: var(--text-muted, #888);
    text-transform: uppercase;
}

.mint-price-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--imu-gold, var(--imu-gold, #d6ba66));
}

.mint-banner-btn {
    background: linear-gradient(135deg, var(--imu-gold, var(--imu-gold, #d6ba66)), #c9a227);
    color: #000;
    border: none;
    padding: 0.75rem 1.5rem;
    border-radius: 10px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    white-space: nowrap;
}

.mint-banner-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(212, 175, 55, 0.4);
}

.mint-secondary-note {
    color: var(--text-muted, #888);
    font-size: 0.9rem;
}

/* Mint Listings Section */
.mint-listings-section {
    padding: 1.5rem 1rem;
    max-width: 1200px;
    margin: 0 auto 2rem;
    border-top: 1px solid var(--card-border, #2a2a4e);
}

.mint-listings-title {
    font-size: 1.25rem;
    margin-bottom: 1.25rem;
    /* colour + font: .imu-heading-gold via trading-hub.css */
}

.mod-ul-badge { display: inline-block; padding: 3px 8px; border-radius: 6px; font-size: 0.7rem; font-weight: 600; background: rgba(212,175,55,0.14); border: 1px solid rgba(212,175,55,0.4); color: var(--gold, #d4af37); margin-left: 6px; }
.imc-mint-ul-teaser { margin: 6px 0 2px; font-size: 0.8rem; color: var(--gold, #d4af37); opacity: 0.9; }
.mint-listings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 1.25rem;
}

/* Responsive */
@media (max-width: 768px) {
    .mint-banner-inner {
        flex-direction: column;
        align-items: stretch;
        gap: 1rem;
    }
    
    .mint-banner-progress {
        max-width: none;
    }
    
    .mint-banner-action {
        justify-content: space-between;
    }
    
    .mint-banner-btn {
        flex: 1;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // L3-3a-ii (Aug 2026): file-scoped cover wrap — the SHARED helper instead of a
    // third inline copy of the same routing logic (host-list copies are how the
    // `ipfs.com` bug family bred). Idempotent: already-proxied img.php URLs and
    // plain https assets pass through untouched. MATCH THE PATH (/ipfs/), NOT A
    // HOST LIST — hosts rot, the path is the invariant.
    function impWrapCover(u) {
        if (!u || typeof u !== 'string') return u;
        if (u.includes('img.php')) return u;
        if (u.startsWith('ipfs://')) {
            return 'https://metadata.imcollectibles.io/img.php?url=' + encodeURIComponent(u) + '&thumb=1';
        }
        if (u.includes('/ipfs/')) {
            const cid = u.replace(/^.*\/ipfs\//, '');
            return 'https://metadata.imcollectibles.io/img.php?url=' + encodeURIComponent('ipfs://' + cid) + '&thumb=1';
        }
        return u;
    }
    const grid        = document.getElementById('all-collections-grid');
    const countDisplay= document.getElementById('collections-count');
    const searchInput = document.getElementById('collections-search');
    const contentFilter = document.getElementById('content-type-filter');
    const sortSelect  = document.getElementById('collections-sort');
    const pagination  = document.getElementById('browse-pagination');
    const prevBtn     = document.getElementById('browse-prev-btn');
    const nextBtn     = document.getElementById('browse-next-btn');
    const pageInfo    = document.getElementById('browse-page-info');

    const pageContainer = document.getElementById('collections-browse-page');
    const endpoints  = JSON.parse(pageContainer.dataset.endpoints || '{}');
    const nonce      = pageContainer.dataset.nonce || '';
    const proxyUrl   = endpoints.myNftsHandler || '/wp-content/themes/astra/xrpl-nft-marketplace/backend/my-nfts-handler.php';

    const LIMIT = 50;
    let currentPage  = 1;
    let totalPages   = 1;
    let totalCount   = 0;
    let isLoading    = false;

    // ── IMU featured data (PHP-injected) ──────────────────────────────────────
    const imuCollections = JSON.parse(pageContainer.dataset.imuCollections || '{}');

    function enrichWithImuData(cols) {
        return cols.map(col => {
            const imuKey = `${col.issuer}_${col.taxon}`;
            if (imuCollections[imuKey]) {
                return Object.assign({}, col, imuCollections[imuKey]);
            }
            return col;
        });
    }

    // ── Skeleton helpers ──────────────────────────────────────────────────────
    const SKELETON = '<div class="browse-collection-card skeleton-card skeleton-shimmer-card"><div class="card-image skeleton-shimmer" style="aspect-ratio:16/10"></div><div class="card-info"><div class="skeleton-text skeleton-shimmer" style="width:65%;height:16px"></div><div class="skeleton-text skeleton-shimmer" style="width:90%;height:12px;margin-top:0.5rem"></div></div></div>';

    function showSkeletons(n = 10) {
        grid.innerHTML = Array.from({length: n}, () => SKELETON).join('');
        if (pagination) pagination.style.display = 'none';
        if (countDisplay) countDisplay.textContent = 'Loading…';
    }

    // ── Card renderer ─────────────────────────────────────────────────────────
    function renderCard(col) {
        const isImu   = col.is_imu   ? 'is-imu'   : '';
        const isMusic = (col.is_music || col.content_type === 'audio') ? 'is-music' : '';
        const href    = col.slug
            ? `/collections/${col.slug}/`
            : `/collections/?issuer=${encodeURIComponent(col.issuer)}&taxon=${col.taxon}`;

        // v396: Route IPFS URLs through VPS img.php proxy (cached, thumbnails, CDN-safe)
        let image = col.image || '';
        // L3-3a (Aug 2026): DB-backed collections use the ?collection= form — the ONLY
        // img.php form that can 302-handoff to the browser on proxy failure (it is
        // $from_store server-side). A dead cover now redirects to origin (and hits the
        // negative-cache fast path) instead of 503ing into the fallback svg.
        // Guards: image NON-EMPTY (empty covers must keep making NO request at all),
        // issuer present, taxon defined — taxon 0 IS VALID, never a truthiness check.
        if (image && col.issuer && col.taxon !== undefined && col.taxon !== null) {
            image = `https://metadata.imcollectibles.io/img.php?collection=${encodeURIComponent(col.issuer)}&taxon=${col.taxon}&thumb=1`;
        } else if (image.startsWith('ipfs://')) {
            image = `https://metadata.imcollectibles.io/img.php?url=${encodeURIComponent(image)}&thumb=1`;
        } else if (image.includes('/ipfs/')) {
            // MATCH THE PATH, NOT THE HOST (Aug 2026).
            //
            // This was two branches testing a HOST LIST: /(ipfs|cloudflare-ipfs)\.com/
            // and 'mypinata.cloud'. The first read `ipfs.com` — A DOMAIN THAT DOES NOT
            // EXIST — so every cover the store serves from ipfs.io matched NOTHING and
            // went into <img src> as a raw gateway URL.
            //
            // ipfs.io sends Cross-Origin-Resource-Policy: same-origin, so the BROWSER
            // refuses to render it cross-origin:
            //     ERR_BLOCKED_BY_RESPONSE.NotSameOrigin
            // ★ The fetch itself is fine — measured: a clean 200 in well under a second from
            //   the server. The block is browser policy, which is why every server-side
            //   test passed while the page showed placeholders.
            //
            // Measured on the live page: the large majority of cards were broken.
            //
            // ⚠ The path is the invariant. A host list is an enumeration of the
            //   gateways known on the day it was written, and the store now serves
            //   covers from ipfs.io, mypinata.cloud, 4everland, nftstorage and more.
            //   This is the same defect isValidStreamUrl carried in IMUP3 until F0a
            //   generalised it to the /ipfs/ PATH — same bug, different file.
            const cid = image.replace(/^.*\/ipfs\//, '');
            image = `https://metadata.imcollectibles.io/img.php?url=${encodeURIComponent('ipfs://' + cid)}&thumb=1`;
        }

        const noImage   = !image;
        const imgSrc    = image || '/wp-content/uploads/fallback-nft.svg';
        const brokenCls = noImage ? 'image-broken' : '';
        const name      = col.name || `Collection #${col.taxon}`;
        const nftCount  = col.indexed_count || col.total_supply || '?';
        const typeLabel = col.content_type === 'audio' ? '♪ Music' : col.content_type === 'video' ? '▶ Video' : '';
        const floorStr  = col.floor_xrp > 0 ? `💰 ${Number(col.floor_xrp).toFixed(2)} XRP` : '';
        const healthPct = col.health_score ? Math.round(col.health_score * 100) : 0;
        const healthCls = healthPct >= 80 ? 'health-good' : healthPct >= 50 ? 'health-ok' : 'health-low';

        return `
            <a href="${href}" class="browse-collection-card ${isImu} ${isMusic} ${brokenCls}">
                <div class="card-image">
                    <img src="${imgSrc}" alt="${name}" loading="lazy"
                         onerror="this.onerror=null;this.src='/wp-content/uploads/fallback-nft.svg';this.closest('.browse-collection-card').classList.add('image-broken');">
                    ${healthPct > 0 ? `<span class="health-badge ${healthCls}">${healthPct}%</span>` : ''}
                </div>
                <div class="card-info">
                    <h4>${name}</h4>
                    <div class="card-stats">
                        <span>📦 ${nftCount} NFTs</span>
                        ${floorStr ? `<span>${floorStr}</span>` : ''}
                        ${typeLabel ? `<span>${typeLabel}</span>` : ''}
                    </div>
                </div>
            </a>`;
    }

    // ── Core fetch: calls get_browse_collections ──────────────────────────────
    async function fetchPage(page, scrollToTop = false) {
        if (isLoading) return;
        isLoading = true;

        const search = searchInput ? searchInput.value.trim() : '';
        const type   = contentFilter ? contentFilter.value : 'all';
        const sort   = sortSelect   ? sortSelect.value   : 'shuffle';

        // Access filters (music_access etc.) handled separately — skip new endpoint
        // v468: album_access added so Album Access selections route through handleAccessFilter
        const accessTypes = ['music_access','album_access','musicvideo_access','art_access','film_access'];
        if (accessTypes.includes(type)) {
            isLoading = false;
            handleAccessFilter(type);
            return;
        }

        // P3-F/G2 forever-scroll: skeletons only on the first page; later pages append
        // beneath the accumulated grid, so we must NOT wipe it with skeletons.
        if (page <= 1) showSkeletons(10);

        try {
            const url = `${proxyUrl}?action=get_browse_collections`
                + `&page=${page}&limit=${LIMIT}`
                + `&search=${encodeURIComponent(search)}`
                + `&type=${encodeURIComponent(type)}`
                + `&sort=${encodeURIComponent(sort)}`
                + `&nonce=${encodeURIComponent(nonce)}`;

            const res  = await fetch(url);
            const data = await res.json();

            if (!data.success) throw new Error(data.error || 'Failed');

            currentPage = data.page;
            totalPages  = data.total_pages;
            totalCount  = data.total;

            const cols = enrichWithImuData(data.collections || []);

            if (cols.length === 0 && page <= 1) {
                grid.innerHTML = '<div class="no-collections-message">No collections found matching your search.</div>';
                if (pagination) pagination.style.display = 'none';
                if (countDisplay) countDisplay.textContent = '0 collections found';
            } else {
                // P3-F/G2 forever-scroll: page 1 replaces, later pages APPEND so the
                // grid grows into one continuous ranked list. Broken-image demotion
                // (server sort Tier2 + CSS order:9999) stays coherent across the whole set.
                const html = cols.map(renderCard).join('');
                if (page <= 1) { grid.innerHTML = html; }
                else { grid.insertAdjacentHTML('beforeend', html); }

                // Count reflects how many are now shown vs the full filtered total.
                const shown = Math.min(currentPage * LIMIT, totalCount);
                if (countDisplay) {
                    countDisplay.textContent = search
                        ? `${totalCount} result${totalCount !== 1 ? 's' : ''} for "${search}" — showing ${shown}`
                        : `Showing ${shown} of ${totalCount} collections`;
                }

                // Pagination bar retired in favour of scroll; keep it hidden.
                if (pagination) pagination.style.display = 'none';
            }

            // P3-F/G2: no scroll-jump on page advance (forever-scroll appends in place).
            if (scrollToTop && page <= 1) window.scrollTo({ top: grid.offsetTop - 120, behavior: 'smooth' });

            console.log(`[Browse] Page ${currentPage}/${totalPages} — ${cols.length} collections (${totalCount} total, dataset: ${data.dataset_size})`);

        } catch (err) {
            console.error('[Browse] fetch error:', err);
            grid.innerHTML = '<div class="no-collections-message">Failed to load collections. Please try again.</div>';
            if (pagination) pagination.style.display = 'none';
            if (countDisplay) countDisplay.textContent = '';
        } finally {
            isLoading = false;
        }
    }

    // ── P3-F/G2 forever-scroll: auto-advance via IntersectionObserver ─────────
    // A sentinel after the grid triggers the next page as it nears the viewport.
    // Prev/Next buttons are retired (bar hidden); we keep the elements so no other
    // reference breaks, but drive paging by scroll instead.
    let scrollSentinel = document.getElementById('browse-scroll-sentinel');
    if (!scrollSentinel && grid && grid.parentNode) {
        scrollSentinel = document.createElement('div');
        scrollSentinel.id = 'browse-scroll-sentinel';
        scrollSentinel.style.cssText = 'width:100%;height:1px;';
        grid.parentNode.insertBefore(scrollSentinel, grid.nextSibling);
    }
    if (scrollSentinel && 'IntersectionObserver' in window) {
        const io = new IntersectionObserver((entries) => {
            if (entries[0].isIntersecting && !isLoading && currentPage < totalPages) {
                fetchPage(currentPage + 1);
            }
        }, { rootMargin: '600px 0px' });
        io.observe(scrollSentinel);
    }

    // ── Filter / sort changes reset to page 1 ─────────────────────────────────
    if (contentFilter) contentFilter.addEventListener('change', () => fetchPage(1));
    if (sortSelect)    sortSelect.addEventListener('change',    () => fetchPage(1));

    // ── Search: debounce 350ms, server-side, reset to page 1 ─────────────────
    let searchTimer;
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => fetchPage(1), 350);
        });
    }

    // ── Access filter handler (listings-handler path, unchanged logic) ─────────
    async function handleAccessFilter(type) {
        const accessToNftType = {
            'music_access': 'music', 'musicvideo_access': 'musicvideo',
            'art_access': 'art',     'film_access': 'film',
            'album_access': 'album'
        };
        const nftType = accessToNftType[type];
        showSkeletons(6);
        if (pagination) pagination.style.display = 'none';

        try {
            const listingsUrl = endpoints.listingsHandler || '/wp-content/themes/astra/xrpl-nft-marketplace/backend/listings-handler.php';
            const res  = await fetch(`${listingsUrl}?action=get_marketplace&type=${nftType}&limit=50&include_sold_out=1&include_paused=1&_=${Date.now()}`);
            const data = await res.json();

            if (data.success && data.data?.listings?.length > 0) {
                const collectionMap = new Map();
                data.data.listings.forEach(listing => {
                    const key = `${listing.artist_account}_${listing.collection_taxon}`;
                    if (!collectionMap.has(key)) {
                        collectionMap.set(key, {
                            issuer: listing.artist_account, taxon: listing.collection_taxon,
                            name: listing.collection_name || listing.nft_name,
                            // v396: Route through VPS img.php proxy for caching + thumbnails
                            image: listing.cover_ipfs ? `https://metadata.imcollectibles.io/img.php?url=${encodeURIComponent(listing.cover_ipfs)}&thumb=1` : '',
                            nft_type: listing.nft_type, artist_name: listing.artist_name || '',
                            total_editions: 0, minted_count: 0, available: 0,
                            min_price: Infinity, has_dynamic: false, min_price_usd: Infinity, status: 'active',
                            token_price: null, has_token_prices: false, // v665: token-agnostic display
                            has_open_edition: false  // v393: Track OE
                        });
                    }
                    const col = collectionMap.get(key);
                    col.total_editions += parseInt(listing.total_editions) || 0;
                    col.minted_count   += parseInt(listing.minted_count)   || 0;
                    col.available      += parseInt(listing.available_editions) || 0;
                    // v393: Detect open editions from API data (active only — closed OEs display as sold out)
                    if ((listing.edition_type === 'open' || listing.is_open_edition) && !listing.open_edition_closed_at) col.has_open_edition = true;
                    const price = parseFloat(listing.price_xrp) || 0;
                    // v448: Check accepted_currencies for real XRP price when price_xrp=0
                    let effectivePrice = price;
                    if (price === 0 && listing.accepted_currencies) {
                        try {
                            const currencies = typeof listing.accepted_currencies === 'string' 
                                ? JSON.parse(listing.accepted_currencies) : listing.accepted_currencies;
                            if (Array.isArray(currencies)) {
                                for (const c of currencies) {
                                    // Only check XRP — other tokens have their own units
                                    if (c.currency === 'XRP' && c.enabled && parseFloat(c.price || 0) > 0) {
                                        effectivePrice = parseFloat(c.price);
                                        break;
                                    }
                                }
                            }
                        } catch(e) {}
                    }
                    // v665: capture the first priced XRPL token so a token-only collection shows
                    // a real price instead of "Free", and flag that token prices exist.
                    let firstTok = null, tokCount = 0;
                    try {
                        const cs = typeof listing.accepted_currencies === 'string'
                            ? JSON.parse(listing.accepted_currencies) : listing.accepted_currencies;
                        if (Array.isArray(cs)) {
                            for (const c of cs) {
                                if (c.enabled === false) continue;
                                const code = String(c.currency || '').toUpperCase();
                                if (!code || code === 'XRP') continue;
                                if (parseFloat(c.price || 0) > 0) {
                                    tokCount++;
                                    if (!firstTok) firstTok = { currency: c.currency, price: parseFloat(c.price) };
                                }
                            }
                        }
                    } catch(e) {}
                    if (firstTok && !col.token_price) col.token_price = firstTok;
                    if (tokCount > 0) col.has_token_prices = true;
                    if (effectivePrice > 0 && effectivePrice < col.min_price) col.min_price = effectivePrice;
                    else if (effectivePrice === 0 && listing.status === 'active' && !firstTok) col.min_price = 0; // v448: Genuinely free
                    if (listing.pricing_mode === 'dynamic') col.has_dynamic = true;
                    const usd = parseFloat(listing.price_usd) || 0;
                    if (usd > 0 && usd < col.min_price_usd) col.min_price_usd = usd;
                    if (listing.status === 'active') col.status = 'active';
                });
                const collections = Array.from(collectionMap.values());
                if (countDisplay) countDisplay.textContent = `${collections.length} ${nftType} collections`;
                const typeIcons = { music:'🎵', album:'💿', musicvideo:'🎬', art:'🎨', film:'🎥' };
                // v351: client-side slugify — must match PHP imu_collection_slugify()
                const slugify = s => s.toLowerCase().trim().replace(/[^a-z0-9\s\-]/g,'').replace(/[\s\-]+/g,'-').replace(/^-+|-+$/g,'') || 'collection';
                grid.innerHTML = collections.map(col => {
                    const href = `/collections/${slugify(col.name || 'collection')}-${col.taxon}/`;
                    // v393: Open editions are never sold out while active
                    const isSoldOut = !col.has_open_edition && col.available <= 0;
                    let priceStr = '';
                    // v665: token-agnostic — a collection with no XRP price but priced XRPL
                    // tokens shows the first token price rather than "Free".
                    const fmtTok = v => (v === Math.floor(v) ? v.toLocaleString('en-US') : String(v));
                    if (col.has_dynamic && col.min_price_usd < Infinity) priceStr = `From $${col.min_price_usd.toFixed(2)} USD`;
                    else if (col.min_price === Infinity && col.token_price) priceStr = `From ${fmtTok(col.token_price.price)} ${col.token_price.currency}`;
                    else if (col.min_price === 0) priceStr = 'Free'; // v448: Free listings
                    else if (col.min_price < Infinity) priceStr = `From ${col.min_price} XRP`;
                    const tokNote = col.has_token_prices ? '<span style="display:block;font-size:0.78em;opacity:0.75;">+ XRPL Token Prices</span>' : '';
                    const imgSrc = impWrapCover(col.image) || '/wp-content/uploads/fallback-nft.svg'; // L3-3a-ii: was never wrapped — mint-DB https covers pass through, ipfs forms get proxied+thumbed
                    // v393: OE counter shows "X minted (???)" instead of "X/0 minted"
                    const mintedStr = (col.has_open_edition && col.total_editions === 0)
                        ? `📦 ${col.minted_count} minted (???)`
                        : `📦 ${col.minted_count}/${col.total_editions} minted`;
                    return `<a href="${href}" class="browse-collection-card is-imu">
                        <div class="card-image">
                            <img src="${imgSrc}" alt="${col.name}" loading="lazy" onerror="this.onerror=null;this.src='/wp-content/uploads/fallback-nft.svg';">
                            <span class="health-badge health-good">${typeIcons[col.nft_type] || '🎵'}</span>
                            ${isSoldOut ? '<span class="health-badge health-low" style="top:36px">SOLD OUT</span>' : ''}
                            ${col.has_open_edition && !isSoldOut ? '<span class="health-badge health-good" style="top:36px">OPEN MINT</span>' : ''}
                        </div>
                        <div class="card-info">
                            <h4>${col.name}</h4>
                            ${col.artist_name ? `<p class="card-artist">${col.artist_name}</p>` : ''}
                            <div class="card-stats">
                                <span>${mintedStr}</span>
                                ${priceStr ? `<span>${priceStr}${tokNote}</span>` : ''}
                            </div>
                        </div>
                    </a>`;
                }).join('');
            } else {
                grid.innerHTML = `<div class="no-collections-message">No ${nftType} access collections found yet.</div>`;
                if (countDisplay) countDisplay.textContent = '';
            }
        } catch (err) {
            console.error('[Browse] Access filter error:', err);
            grid.innerHTML = '<div class="no-collections-message">Failed to load. Please try again.</div>';
        }
    }

    // ── Initial load ──────────────────────────────────────────────────────────
    fetchPage(1);
});
</script>


<?php if (function_exists('imc_xrplto_attribution')) imc_xrplto_attribution(); ?>
<?php
get_footer();
exit; // Important: exit after browse mode
endif;

// ============================================================
// COLLECTION MODE - Show specific collection
// ============================================================

// Determine collection from slug or URL params
if ($slug && isset($collections[$slug])) {
    $collection = $collections[$slug];
    $issuer = $collection['issuer'];
    $taxon = $collection['taxon'];
    $collection_name = $collection['name'];
    $collection_desc = $collection['description'];
    $fallback_image = $collection['fallback_image'];
} elseif ($issuer_param && $taxon_param !== null) {
    // URL params: ?issuer=xxx&taxon=123
    $issuer = $issuer_param;
    $taxon = $taxon_param;
    $collection_name = 'Collection';
    $collection_desc = 'NFT Collection on the XRP Ledger.';
    $fallback_image = '/wp-content/uploads/fallback-nft.svg';
    
    // ──────────────────────────────────────────────────────────────
    // v224: REVERSE-LOOKUP hardcoded + featured collections by issuer+taxon
    // Featured carousel and browse page link via ?issuer=&taxon= which
    // bypasses the slug-based first branch. Match against all known sources.
    // ──────────────────────────────────────────────────────────────
    foreach ($collections as $_slug => $_cdata) {
        if ($_cdata['issuer'] === $issuer && (int)$_cdata['taxon'] === (int)$taxon) {
            $collection_name = $_cdata['name'];
            $collection_desc = $_cdata['description'];
            $fallback_image = $_cdata['fallback_image'];
            break;
        }
    }
    // Also check admin-configured featured collections (WP option)
    if ($collection_name === 'Collection') {
        $admin_featured = function_exists('imu_get_featured_collections') ? imu_get_featured_collections() : [];
        foreach ($admin_featured as $_fc) {
            if (($_fc['issuer'] ?? '') === $issuer && (int)($_fc['taxon'] ?? -1) === (int)$taxon) {
                if (!empty($_fc['name']) && $_fc['name'] !== 'Collection') {
                    $collection_name = $_fc['name'];
                }
                if (!empty($_fc['image']) && strpos($_fc['image'], 'fallback') === false) {
                    $fallback_image = $_fc['image'];
                }
                break;
            }
        }
    }
    
    // ──────────────────────────────────────────────────────────────
    // PRIMARY: Our own wp_imc_collections table (has data before anything on-chain)
    // ──────────────────────────────────────────────────────────────
    $imc_coll_table = $wpdb->prefix . 'imc_collections';
    if ($wpdb->get_var("SHOW TABLES LIKE '$imc_coll_table'") === $imc_coll_table) {
        $imc_col = $wpdb->get_row($wpdb->prepare(
            "SELECT collection_name, collection_description, cover_image_ipfs 
             FROM {$imc_coll_table} 
             WHERE artist_account = %s AND collection_taxon = %d LIMIT 1",
            $issuer, $taxon
        ), ARRAY_A);
        if ($imc_col) {
            if (!empty($imc_col['collection_name']))        $collection_name = $imc_col['collection_name'];
            if (!empty($imc_col['collection_description'])) $collection_desc = $imc_col['collection_description'];
            if (!empty($imc_col['cover_image_ipfs'])) {
                $cid = str_replace('ipfs://', '', $imc_col['cover_image_ipfs']);
                $fallback_image = 'https://metadata.imcollectibles.io/img.php?url=' . urlencode('ipfs://' . $cid);
            }
        }
    }
    
    // ALSO check imc_listings for name + cover if still default
    if ($collection_name === 'Collection') {
        $imc_list_tbl = $wpdb->prefix . 'imc_listings';
        if ($wpdb->get_var("SHOW TABLES LIKE '$imc_list_tbl'") === $imc_list_tbl) {
            $listing_row = $wpdb->get_row($wpdb->prepare(
                "SELECT collection_name, cover_ipfs FROM {$imc_list_tbl} 
                 WHERE artist_account = %s AND collection_taxon = %d 
                 AND collection_name IS NOT NULL AND collection_name != '' LIMIT 1",
                $issuer, $taxon
            ), ARRAY_A);
            if ($listing_row) {
                $collection_name = $listing_row['collection_name'];
                if (!empty($listing_row['cover_ipfs']) && strpos($fallback_image, 'placehold') !== false) {
                    $cid = str_replace('ipfs://', '', $listing_row['cover_ipfs']);
                    $fallback_image = 'https://metadata.imcollectibles.io/img.php?url=' . urlencode('ipfs://' . $cid);
                }
            }
        }
    }
    
    // SECONDARY: VPS metadata (for externally-indexed on-chain collections)
    if ($collection_name === 'Collection') {
        $vps_url = 'https://metadata.imcollectibles.io/?action=collection&issuer=' . urlencode($issuer) . '&taxon=' . $taxon;
        $vps_response = wp_remote_get($vps_url, ['timeout' => 5]);
        if (!is_wp_error($vps_response)) {
            $vps_data = json_decode(wp_remote_retrieve_body($vps_response), true);
            if (!empty($vps_data['success']) && !empty($vps_data['collection'])) {
                $col_data = $vps_data['collection'];
                $collection_name = $col_data['name'] ?: $collection_name;
                $fallback_image = $col_data['image'] ?: $fallback_image;
            }
        }
    }
} elseif ($slug && preg_match('/^(r[1-9A-HJ-NP-Za-km-z]{25,34})-(\d+)$/', $slug, $m)) {
    // Legacy slug format: rISSUER-TAXON — resolve same as ?issuer=&taxon=
    $issuer = $m[1];
    $taxon  = (int)$m[2];
    $collection_name = 'Collection';
    $collection_desc = 'NFT Collection on the XRP Ledger.';
    $fallback_image  = '/wp-content/uploads/fallback-nft.svg';
    foreach ($collections as $_s => $_c) {
        if ($_c['issuer'] === $issuer && (int)$_c['taxon'] === $taxon) {
            $collection_name = $_c['name'];
            $collection_desc = $_c['description'];
            $fallback_image  = $_c['fallback_image'];
            break;
        }
    }
    // Full DB+VPS waterfall same as ?issuer=&taxon= branch
    if ($collection_name === 'Collection') {
        $imc_coll_table = $wpdb->prefix . 'imc_collections';
        if ($wpdb->get_var("SHOW TABLES LIKE '$imc_coll_table'") === $imc_coll_table) {
            $imc_col = $wpdb->get_row($wpdb->prepare(
                "SELECT collection_name, collection_description, cover_image_ipfs FROM {$imc_coll_table} WHERE artist_account = %s AND collection_taxon = %d LIMIT 1",
                $issuer, $taxon
            ), ARRAY_A);
            if ($imc_col) {
                if (!empty($imc_col['collection_name']))        $collection_name = $imc_col['collection_name'];
                if (!empty($imc_col['collection_description'])) $collection_desc = $imc_col['collection_description'];
                if (!empty($imc_col['cover_image_ipfs'])) {
                    $cid = str_replace('ipfs://', '', $imc_col['cover_image_ipfs']);
                    $fallback_image = 'https://metadata.imcollectibles.io/img.php?url=' . urlencode('ipfs://' . $cid);
                }
            }
        }
    }
    if ($collection_name === 'Collection') {
        $vps_r2 = wp_remote_get('https://metadata.imcollectibles.io/?action=collection&issuer=' . urlencode($issuer) . '&taxon=' . $taxon, ['timeout' => 5]);
        if (!is_wp_error($vps_r2)) {
            $vd2 = json_decode(wp_remote_retrieve_body($vps_r2), true);
            if (!empty($vd2['collection']['name'])) $collection_name = $vd2['collection']['name'];
            if (!empty($vd2['collection']['image'])) $fallback_image  = $vd2['collection']['image'];
        }
    }
} elseif ($slug) {
    // v351: name-based slug (name-taxon format) → call VPS collection_by_slug
    $ck_slug = 'imc_colresolve_' . substr(md5($slug), 0, 16);
    $slug_col = get_transient($ck_slug);
    if ($slug_col === false) {
        $sr = wp_remote_get('https://metadata.imcollectibles.io/?action=collection_by_slug&slug=' . urlencode($slug), ['timeout' => 5]);
        if (!is_wp_error($sr) && wp_remote_retrieve_response_code($sr) === 200) {
            $sd = json_decode(wp_remote_retrieve_body($sr), true);
            $slug_col = !empty($sd['collection']) ? $sd['collection'] : null;
        } else {
            $slug_col = null;
        }
        set_transient($ck_slug, $slug_col ?: [], 6 * HOUR_IN_SECONDS);
    }
    if (!empty($slug_col['issuer'])) {
        $issuer          = $slug_col['issuer'];
        $taxon           = (int)$slug_col['taxon'];
        $collection_name = $slug_col['name']        ?: 'Collection';
        $collection_desc = $slug_col['description'] ?: 'NFT Collection on the XRP Ledger.';
        $fallback_image  = $slug_col['image']        ?: '/wp-content/uploads/fallback-nft.svg';
    } else {
        // VPS doesn't know this slug — check wp_imc_collections (platform-minted collections)
        // Slug format: slugify(name)-taxon — extract taxon from end, match name in DB
        $resolved = false;
        if (preg_match('/^(.+)-(\d+)$/', $slug, $_sm)) {
            $_slug_name = $_sm[1]; // e.g. "baby-di-nero"
            $_slug_taxon = (int)$_sm[2]; // e.g. 5
            $imc_ct = $wpdb->prefix . 'imc_collections';
            if ($wpdb->get_var("SHOW TABLES LIKE '$imc_ct'") === $imc_ct) {
                // Get all collections with this taxon, find one whose slugified name matches
                $candidates = $wpdb->get_results($wpdb->prepare(
                    "SELECT artist_account, collection_taxon, collection_name, collection_description, cover_image_ipfs
                     FROM $imc_ct WHERE collection_taxon = %d AND collection_name IS NOT NULL AND collection_name != ''",
                    $_slug_taxon
                ), ARRAY_A);
                foreach ($candidates as $_c) {
                    $_computed = function_exists('imu_collection_slugify')
                        ? imu_collection_slugify($_c['collection_name'])
                        : preg_replace('/[\s\-]+/', '-', strtolower(trim(preg_replace('/[^a-z0-9\s\-]/i', '', $_c['collection_name']))));
                    if ($_computed === $_slug_name) {
                        $issuer          = $_c['artist_account'];
                        $taxon           = $_slug_taxon;
                        $collection_name = $_c['collection_name'];
                        $collection_desc = $_c['collection_description'] ?: 'NFT Collection on the XRP Ledger.';
                        if (!empty($_c['cover_image_ipfs'])) {
                            $_cid = preg_replace('#^ipfs://#', '', $_c['cover_image_ipfs']);
                            $fallback_image = 'https://metadata.imcollectibles.io/img.php?url=' . urlencode('ipfs://' . $_cid) . '&thumb=1';
                        } else {
                            $fallback_image = '/wp-content/uploads/fallback-nft.svg';
                        }
                        // Cache this resolution so VPS isn't hit again unnecessarily
                        set_transient($ck_slug, [
                            'issuer' => $issuer, 'taxon' => $taxon,
                            'name'   => $collection_name, 'description' => $collection_desc,
                            'image'  => $fallback_image,
                        ], 6 * HOUR_IN_SECONDS);
                        $resolved = true;
                        break;
                    }
                }
            }
        }
        if (!$resolved) {
            wp_redirect(home_url('/collections/'));
            exit;
        }
    }
} // end collection mode resolution

// Get collection image (auto-first-NFT or admin override)
$collection_image = function_exists('imu_get_collection_image') 
    ? imu_get_collection_image($issuer, $taxon, $fallback_image)
    : $fallback_image;

// Initialize stats — v360: added volume for IMC collections
$collection_stats = [
    'items' => '-',
    'owners' => '-',
    'floor' => '-',
    'sales' => '-',
    'volume' => '-'
];

// ============================================================================
// CHECK FOR MINT-ON-DEMAND LISTINGS
// ============================================================================
$mint_status = null;
global $wpdb;
$listings_table = $wpdb->prefix . 'imc_listings';

// Check if this collection has active listings (minted on IMC)
if ($wpdb->get_var("SHOW TABLES LIKE '$listings_table'") === $listings_table) {
    // v84: Include pricing_mode and price_usd in query
    // v233: Include launch_type, launch_at, launch_timezone for scheduled mint detection
    // v393: Include edition_type + OE fields for open edition display
    $listings = $wpdb->get_results($wpdb->prepare(
    "SELECT 
        id, nft_name, nft_type, price_xrp, pricing_mode, price_usd, accepted_currencies, ai_generated, ai_mode, ai_platform,
        total_editions, minted_count, reserved_count,
        GREATEST(0, CAST(total_editions AS SIGNED) - CAST(minted_count AS SIGNED) - CAST(COALESCE(reserved_count, 0) AS SIGNED)) as available_editions, 
        cover_ipfs, back_cover_ipfs, media_ipfs, metadata_ipfs, album_tracks_json, ebook_format, status,
        launch_type, launch_at, launch_timezone,
        edition_type, open_edition_ends_at, open_edition_closed_at, created_at, progressive_json
     FROM $listings_table 
     WHERE artist_account = %s 
     AND collection_taxon = %d
     AND status IN ('active', 'sold_out')
     AND is_hidden = 0
     ORDER BY created_at DESC",
    $issuer,
    $taxon
), ARRAY_A);
    
    // v360: FALLBACK — On-chain issuer/taxon may differ from DB values when NFTs
    // are minted via authorized minter flow (on-chain issuer = artist wallet,
    // which may differ from the platform account that created the listing) or when
    // XRPL taxon scrambling produces a different value than stored. If the primary
    // match fails, try matching by collection_name or nft_name (already resolved
    // from VPS/slug). MySQL string comparison is case-insensitive by default.
    if (empty($listings) && !empty($collection_name) && $collection_name !== 'Collection') {
        $listings = $wpdb->get_results($wpdb->prepare(
        "SELECT 
            id, nft_name, nft_type, price_xrp, pricing_mode, price_usd, accepted_currencies, ai_generated, ai_mode, ai_platform,
            total_editions, minted_count, reserved_count,
            GREATEST(0, CAST(total_editions AS SIGNED) - CAST(minted_count AS SIGNED) - CAST(COALESCE(reserved_count, 0) AS SIGNED)) as available_editions, 
            cover_ipfs, back_cover_ipfs, media_ipfs, metadata_ipfs, album_tracks_json, ebook_format, status,
            launch_type, launch_at, launch_timezone,
            edition_type, open_edition_ends_at, open_edition_closed_at, created_at, progressive_json
         FROM $listings_table 
         WHERE (collection_name = %s OR nft_name = %s)
         AND status IN ('active', 'sold_out')
         AND is_hidden = 0
         ORDER BY created_at DESC",
        $collection_name,
        $collection_name
    ), ARRAY_A);
    }
    
    // U6 (Unlockables Master): vault-file count per listing (one aggregate for
    // ALL cards -- active slots only, distinct hashes to mirror U4's dedup).
    if (!empty($listings)) {
        $imc_ul_ids = array_map(fn($l) => intval($l['id']), $listings);
        $imc_ul_in  = implode(',', array_filter($imc_ul_ids));
        $imc_ul_counts = [];
        if ($imc_ul_in !== '') {
            $imc_ul_rows = $wpdb->get_results(
                "SELECT listing_id, COUNT(DISTINCT content_hash) c
                   FROM {$wpdb->prefix}imc_listing_unlockables
                  WHERE listing_id IN ($imc_ul_in) AND status = 'active'
                  GROUP BY listing_id", ARRAY_A) ?: [];
            foreach ($imc_ul_rows as $imc_ul_r) {
                $imc_ul_counts[intval($imc_ul_r['listing_id'])] = intval($imc_ul_r['c']);
            }
        }
        foreach ($listings as &$imc_ul_l) {
            $imc_ul_l['ul_count'] = $imc_ul_counts[intval($imc_ul_l['id'])] ?? 0;
        }
        unset($imc_ul_l);
    }

    if (!empty($listings)) {
        $mint_status = [
            'is_imc_collection' => true,
            'listings' => $listings,
            'total_editions' => 0,
            'minted_count' => 0,
            'available_editions' => 0,
            'min_price' => PHP_FLOAT_MAX,
            'token_price' => null,          // v665: first priced XRPL token (token-only collections)
            'has_token_prices' => false,    // v665: any priced XRPL token in this collection
            'max_price' => 0,
            'min_price_usd' => PHP_FLOAT_MAX,  // v83: Track USD prices too
            'max_price_usd' => 0,
            'has_dynamic' => false,            // v83: Track if any dynamic listings
            'active_count' => 0,
            'has_open_edition' => false,        // v393: Track if any listing is open edition
            'open_edition_ends_at' => null      // v393: Earliest active OE end time (for countdown)
        ];
        
        foreach ($listings as $listing_key => $listing) {
            // v476: Lazy auto-close for expired Open Editions.
            // Mirrors the close logic in mint-on-demand-handler.php:2987, but triggered
            // from the read path so a collection viewed AFTER its OE ended (without
            // anyone trying to mint) still transitions DB state to closed instead of
            // sitting indefinitely with status='active', open_edition_closed_at IS NULL.
            // The WHERE guard "AND open_edition_closed_at IS NULL" prevents double-close
            // races between concurrent page loads.
            if (($listing['edition_type'] ?? 'fixed') === 'open'
                && empty($listing['open_edition_closed_at'])
                && !empty($listing['open_edition_ends_at'])
                && $listing['status'] === 'active'
                && strtotime($listing['open_edition_ends_at']) <= time()) {

                $wpdb->query($wpdb->prepare(
                    "UPDATE $listings_table
                     SET open_edition_closed_at = %s,
                         total_editions = minted_count,
                         status = 'sold_out',
                         updated_at = %s
                     WHERE id = %d AND open_edition_closed_at IS NULL",
                    current_time('mysql'),
                    current_time('mysql'),
                    intval($listing['id'])
                ));

                // Mirror the close in the local listing + parent array so subsequent
                // aggregate calculations + later $mint_status['listings'] consumers
                // (lines 1582, 2514, 2643) see the closed state immediately.
                $listing['open_edition_closed_at'] = current_time('mysql');
                $listing['total_editions']        = $listing['minted_count'];
                $listing['status']                = 'sold_out';
                $listing['available_editions']    = 0;
                $listings[$listing_key]           = $listing;
            }

            $mint_status['total_editions'] += intval($listing['total_editions']);
            $mint_status['minted_count'] += intval($listing['minted_count']);
            
            // v393: Open editions have total_editions=0; use 999999 sentinel like listings-handler
            // Only for ACTIVE open editions — closed OEs (open_edition_closed_at set) display as normal sold-out
            $is_oe = (($listing['edition_type'] ?? 'fixed') === 'open');
            $is_oe_active = $is_oe && empty($listing['open_edition_closed_at']);
            if ($is_oe_active) {
                $mint_status['has_open_edition'] = true;
                $mint_status['available_editions'] += 999999;
                // Track earliest active OE end time for countdown display
                if ($listing['status'] === 'active' && !empty($listing['open_edition_ends_at'])) {
                    if ($mint_status['open_edition_ends_at'] === null || strtotime($listing['open_edition_ends_at']) < strtotime($mint_status['open_edition_ends_at'])) {
                        $mint_status['open_edition_ends_at'] = $listing['open_edition_ends_at'];
                    }
                }
            } else {
                $mint_status['available_editions'] += intval($listing['available_editions']);
            }
            
            if ($listing['status'] === 'active') {
                $mint_status['active_count']++;
                
                // v83: Check pricing mode
                $pricing_mode = $listing['pricing_mode'] ?? 'static';
                
                if ($pricing_mode === 'dynamic' && !empty($listing['price_usd'])) {
                    $mint_status['has_dynamic'] = true;
                    $price_usd = floatval($listing['price_usd']);
                    if ($price_usd > 0) {
                        $mint_status['min_price_usd'] = min($mint_status['min_price_usd'], $price_usd);
                        $mint_status['max_price_usd'] = max($mint_status['max_price_usd'], $price_usd);
                    }
                } else {
                    $price = floatval($listing['price_xrp']);
                    // v448: When price_xrp=0, check accepted_currencies for the real XRP price.
                    // Multi-currency listings (v71+) store the actual price in the JSON,
                    // not in price_xrp. Only treat as "Free" if BOTH sources show 0.
                    if ($price == 0 && !empty($listing['accepted_currencies'])) {
                        $currencies = json_decode($listing['accepted_currencies'], true);
                        if (is_array($currencies)) {
                            foreach ($currencies as $c) {
                                // Only check XRP — other tokens (XFT etc.) have their own units
                                if (($c['currency'] ?? '') === 'XRP' && ($c['enabled'] ?? false) && floatval($c['price'] ?? 0) > 0) {
                                    $price = floatval($c['price']);
                                    break;
                                }
                            }
                        }
                    }
                    // v665: token-priced listings (no XRP price, but priced XRPL tokens) must
                    // NOT be counted as free. Capture the first priced token so the collection
                    // can show a real price, and flag that token prices exist.
                    $imc_tok = imc_first_priced_token($listing);
                    if ($imc_tok && empty($mint_status['token_price'])) {
                        $mint_status['token_price'] = $imc_tok;
                    }
                    if (imc_count_priced_tokens($listing) > 0) {
                        $mint_status['has_token_prices'] = true;
                    }
                    if ($price > 0) {
                        $mint_status['min_price'] = min($mint_status['min_price'], $price);
                        $mint_status['max_price'] = max($mint_status['max_price'], $price);
                    } elseif ($price == 0 && $listing['status'] === 'active' && !$imc_tok) {
                        // Genuinely free — no XRP price AND no priced token anywhere
                        $mint_status['min_price'] = 0;
                    }
                }
            }
        }
        
        // v476: Refresh mint_status['listings'] in case any expired OEs were
        // auto-closed during the loop above. Cheap array reassignment; downstream
        // consumers (lines 1582, 2514, 2643) read accurate post-close state.
        $mint_status['listings'] = $listings;

        // v393: OE progress is undefined (no cap); fixed editions use normal percentage
        $mint_status['progress'] = (!$mint_status['has_open_edition'] && $mint_status['total_editions'] > 0)
            ? round(($mint_status['minted_count'] / $mint_status['total_editions']) * 100) 
            : 0;
        // v393: Open editions are never sold out while active (available=999999)
        $mint_status['is_sold_out'] = !$mint_status['has_open_edition'] && $mint_status['available_editions'] <= 0;
        
        // v234: Detect if ANY active listing is a future-scheduled mint.
        // A collection is "scheduled" if all its active listings haven't launched yet.
        // We show a countdown timer and disable the Mint button until launch time.
        $mint_status['is_scheduled'] = false;
        $mint_status['launch_at']    = null;
        $mint_status['launch_tz']    = 'UTC';
        if (!$mint_status['is_sold_out']) {
            $earliest_active_launch = null;
            $all_active_are_scheduled = true;
            foreach ($listings as $l) {
                if ($l['status'] !== 'active') continue;
                $is_future = ($l['launch_type'] === 'scheduled'
                    && !empty($l['launch_at'])
                    && strtotime($l['launch_at']) > time());
                if (!$is_future) {
                    $all_active_are_scheduled = false; // At least one listing is live now
                    break;
                }
                // Track the soonest launch date
                if ($earliest_active_launch === null || strtotime($l['launch_at']) < strtotime($earliest_active_launch)) {
                    $earliest_active_launch = $l['launch_at'];
                    $mint_status['launch_tz'] = $l['launch_timezone'] ?? 'UTC';
                }
            }
            if ($all_active_are_scheduled && $earliest_active_launch !== null) {
                $mint_status['is_scheduled'] = true;
                $mint_status['launch_at']    = $earliest_active_launch;
            }
        }
        
        // v83: Format price - prefer dynamic USD if available
        if ($mint_status['has_dynamic'] && $mint_status['min_price_usd'] !== PHP_FLOAT_MAX) {
            // Dynamic pricing - show USD
            if ($mint_status['min_price_usd'] === $mint_status['max_price_usd']) {
                $mint_status['price_display'] = '$' . number_format($mint_status['min_price_usd'], 2) . ' USD';
            } else {
                $mint_status['price_display'] = '$' . number_format($mint_status['min_price_usd'], 2) . ' - $' . 
                                                number_format($mint_status['max_price_usd'], 2) . ' USD';
            }
        } elseif ($mint_status['min_price'] === PHP_FLOAT_MAX && !empty($mint_status['token_price'])) {
            // v665: no XRP price anywhere, but the collection IS priced in an XRPL token.
            $mint_status['price_display'] = imc_format_token_amount($mint_status['token_price']['price'])
                                          . ' ' . $mint_status['token_price']['currency'];
        } elseif ($mint_status['min_price'] === PHP_FLOAT_MAX) {
            // No active listings found with a valid price — true sold-out or no listings
            $mint_status['price_display'] = 'Sold Out';
        } elseif ($mint_status['min_price'] == 0 && $mint_status['max_price'] == 0) {
            // v448: All active listings are free (allowlist giveaways, free OEs)
            $mint_status['price_display'] = 'Free';
        } elseif ($mint_status['min_price'] == 0 && $mint_status['max_price'] > 0) {
            // v448: Mix of free + paid listings — show "Free - X.XX XRP"
            $mint_status['price_display'] = 'Free - ' . number_format($mint_status['max_price'], 2) . ' XRP';
        } elseif ($mint_status['min_price'] === $mint_status['max_price']) {
            $mint_status['price_display'] = number_format($mint_status['min_price'], 2) . ' XRP';
        } else {
            $mint_status['price_display'] = number_format($mint_status['min_price'], 2) . ' - ' . 
                                            number_format($mint_status['max_price'], 2) . ' XRP';
        }
        
        // v234: Cover image — imc_collections.cover_image_ipfs is the artist-uploaded
        // collection artwork and always takes priority. imu_get_collection_image() already
        // reads this, so $collection_image is correct if that function ran. We only
        // need the listing cover as an absolute last resort.
        if (empty($collection_image) || strpos($collection_image, 'fallback') !== false || strpos($collection_image, 'placehold') !== false) {
            if (!empty($listings[0]['cover_ipfs'])) {
                $cover_cid = str_replace('ipfs://', '', $listings[0]['cover_ipfs']);
                $collection_image = 'https://metadata.imcollectibles.io/img.php?url=' . urlencode('ipfs://' . $cover_cid);
            }
        }
    }
}

// ============================================================================
// CP-C1 FIX 2 - authoritative description fallback, per FIELD not per BRANCH.
// wp_imc_collections is the only place a platform-minted collection's description
// actually lives, but every existing lookup is trapped behind a branch condition:
//   :1324 runs only on the ?issuer=&taxon= path
//   :1392 runs only when $collection_name is still the literal 'Collection'
//   :1447 runs only when the VPS does NOT know the slug
// A friendly slug the VPS DOES know but holds no description for therefore took
// the generic at :1433, which the suppression below then blanked - so the artist's
// real description was never reached and the block vanished entirely.
// (Root cause upstream: stream-born collections are never enriched, so the store's
// collections.description stays NULL. Fixed properly in CP-S; this makes the theme
// read the source that is already correct.)
// Runs after every branch, so issuer+taxon are resolved whichever path we took.
// ============================================================================
if (!empty($issuer) && $taxon !== null
    && ($collection_desc === '' || $collection_desc === null
        || $collection_desc === 'NFT Collection on the XRP Ledger.')) {
    $imc_ct_fb = $wpdb->prefix . 'imc_collections';
    if ($wpdb->get_var("SHOW TABLES LIKE '$imc_ct_fb'") === $imc_ct_fb) {
        $_desc_fb = $wpdb->get_var($wpdb->prepare(
            "SELECT collection_description FROM {$imc_ct_fb}
             WHERE artist_account = %s AND collection_taxon = %d
               AND collection_description IS NOT NULL AND collection_description != ''
             LIMIT 1",
            $issuer, (int) $taxon
        ));
        if (!empty($_desc_fb)) { $collection_desc = $_desc_fb; }
    }
}

// Clear placeholder description - show nothing if no real description found
// (CP-C1: collapse cleanly, never print filler on an artist's page.)
if ($collection_desc === 'NFT Collection on the XRP Ledger.') {
    $collection_desc = '';
}

// ============================================================
// FETCH STATS — v360: IMC collections use OWN DB, externals use VPS→Bithomp
// ============================================================
// IMC collections have definitive data in imc_listings + imc_purchases.
// Using the VPS indexer for these is slower (crawl lag) and less accurate.
// External collections still need VPS indexer + Bithomp fallback.
// Floor price ALWAYS comes from Bithomp (secondary market data).
// ============================================================

$is_imc = !empty($mint_status) && !empty($mint_status['is_imc_collection']);

if ($is_imc) {
    // ──────────────────────────────────────────────────────────────────────
    // IMC COLLECTION — Query our own WordPress DB tables directly
    // ──────────────────────────────────────────────────────────────────────
    $purchases_table = $wpdb->prefix . 'imc_purchases';
    $has_purchases = ($wpdb->get_var("SHOW TABLES LIKE '$purchases_table'") === $purchases_table);

    // Items: total editions across all listings in this collection
    $collection_stats['items'] = number_format($mint_status['total_editions']);

    // Sales: actual NFTs minted/delivered (not just reserved)
    if ($has_purchases) {
        $listing_ids = array_column($mint_status['listings'], 'id');
        if (!empty($listing_ids)) {
            $ph = implode(',', array_fill(0, count($listing_ids), '%d'));

            // Sales count: NFTs that completed minting
            $sales_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $purchases_table
                     WHERE listing_id IN ($ph)
                       AND mint_status IN ('minted','claimed')",
                    ...$listing_ids
                )
            );
            if ($sales_count > 0) {
                $collection_stats['sales'] = number_format($sales_count);
            }

            // CP-C3-A4 / R-C3g (08 Sep 2026): THIS IS NO LONGER THE OWNERS BOX.
            //
            // COUNT(DISTINCT buyer_account) is unique buyers AT MINT. It is
            // written once and NEVER updated when an NFT is traded,
            // transferred or burned. It equals the holder count only while a
            // collection has never traded - which is why nobody noticed.
            // Measured on a live collection: the two paths disagreed, and both
            // disagreed with the ledger.
            //
            // The hero now takes owners from the VPS instead (?action=collection
            // -> live COUNT(DISTINCT owner), burned excluded, A-1 v475), which
            // is the SAME source the Holders tab reads. Two boxes on one screen
            // disagreeing about 'owners' is worse than either being slightly
            // stale.
            //
            // The buyer count is still computed - it is a genuinely different
            // and useful stat (how many wallets ever minted from this
            // collection) - but it no longer fills the Owners box. If it is
            // ever surfaced it must be labelled 'Minters', never 'Owners'.
            $minter_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT buyer_account) FROM $purchases_table
                     WHERE listing_id IN ($ph)
                       AND mint_status IN ('minted','claimed')",
                    ...$listing_ids
                )
            );
            // NOT assigned to ['owners'] any more - the VPS branch below fills
            // it from the live ledger-backed count.
            $collection_stats['minters'] = $minter_count > 0 ? number_format($minter_count) : '-';

            // Volume: total XRP received for this collection
            // - Include minted/claimed/failed: user paid, platform received XRP
            // - Exclude cancelled: user chose not to complete payment
            // - Exclude price_xrp = 0: manual resends after platform-side failures
            $vol_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT UPPER(price_currency) AS cur,
                            SUM(price_xrp) AS total
                     FROM $purchases_table
                     WHERE listing_id IN ($ph)
                       AND price_xrp > 0
                       AND mint_status != 'cancelled'
                     GROUP BY UPPER(price_currency)",
                    ...$listing_ids
                )
            );
            $xrp_vol = 0.0;
            $xft_vol = 0.0;
            foreach ($vol_rows as $vr) {
                if ($vr->cur === 'XRP') $xrp_vol = (float) $vr->total;
                if ($vr->cur === 'XFT') $xft_vol = (float) $vr->total;
            }
            // CP-C3-B5b: THIS IS NO LONGER THE VOLUME BOX.
            //
            // It sums wp_imc_purchases - MINT REVENUE, i.e. primary sales
            // through IMC - and the Volume box is ruled SECONDARY ONLY.
            //
            // ⚠ It also added XRP and XFT into one number ('XFT counted in
            // XRP-equivalent'), which is a currency conversion presented as a
            // total and violates D14. Both problems disappear with the source.
            //
            // The figure is kept as ['mint_revenue'] because it is genuinely
            // useful and hard to recompute elsewhere - but it must NEVER be
            // labelled 'Volume', and the two currencies must be shown apart if
            // it is ever surfaced.
            $collection_stats['mint_revenue_xrp'] = $xrp_vol;
            $collection_stats['mint_revenue_xft'] = $xft_vol;
            $total_vol = 0;   // deliberately not assigned to ['volume']
            if ($total_vol > 0) {
                if ($total_vol >= 1000000) {
                    $collection_stats['volume'] = number_format($total_vol / 1000000, 1) . 'M XRP';
                } elseif ($total_vol >= 1000) {
                    $collection_stats['volume'] = number_format($total_vol / 1000, 1) . 'K XRP';
                } else {
                    $collection_stats['volume'] = number_format($total_vol, 2) . ' XRP';
                }
            }
        }
    } else {
        // No purchases table yet — fall back to listing counts
        if ($mint_status['minted_count'] > 0) {
            $collection_stats['sales'] = number_format($mint_status['minted_count']);
        }
    }

    // P1E (Aug 2026): floor + stats from OUR store first. One local call replaces
    // the unconditional Bithomp floor fetch (6s) that ran on EVERY platform
    // collection view. Field handling mirrors the external-collection branch
    // below, live since v359. floor_drops is destination- and expiration-correct
    // as of P1b/P1C2-B. Bithomp remains only as the conditional gap-filler below
    // (owners — the one stat the store cannot supply — and any residual floor gap).
    $bithomp_debug = ['url' => '', 'status' => null, 'error' => null];
    $vps_url = 'https://metadata.imcollectibles.io/?action=collection&issuer=' . urlencode($issuer) . '&taxon=' . $taxon;
    $vps_response = wp_remote_get($vps_url, ['timeout' => 5]);
    if (!is_wp_error($vps_response) && wp_remote_retrieve_response_code($vps_response) === 200) {
        $vps_data = json_decode(wp_remote_retrieve_body($vps_response), true);
        if (!empty($vps_data['success']) && !empty($vps_data['collection'])) {
            $col = $vps_data['collection'];

            if ($collection_stats['floor'] === '-' && isset($col['floor_xrp']) && $col['floor_xrp'] > 0) {
                $collection_stats['floor'] = number_format($col['floor_xrp'], 2) . ' XRP';
            }
            if ($collection_stats['items'] === '-') {
                if (isset($col['total_supply']) && $col['total_supply'] > 0) {
                    $collection_stats['items'] = number_format($col['total_supply']);
                } elseif (isset($col['indexed_count']) && $col['indexed_count'] > 0) {
                    $collection_stats['items'] = number_format($col['indexed_count']);
                }
            }
            if ($collection_stats['sales'] === '-' && isset($col['sales_24h']) && $col['sales_24h'] > 0) {
                $collection_stats['sales'] = number_format($col['sales_24h']) . ' (24h)';
            }
            if (($collection_name === 'Collection' || empty($collection_name)) && !empty($col['name'])) {
                $collection_name = $col['name'];
            }
            if (empty($collection_desc) && !empty($col['description'])) {
                $collection_desc = $col['description'];
            }
            if ((empty($collection_image) || strpos($collection_image, 'fallback') !== false) && !empty($col['image'])) {
                $collection_image = $col['image'];
            }
        }
    }

    // ── CP-C3-A4 / R-C3g: OWNERS FROM THE LEDGER-BACKED COUNT ──────────────
    // 🔴 The VPS block further down is inside the `} else {` EXTERNAL branch -
    // it has NEVER run for IMC collections. Removing the WordPress buyer count
    // from ["owners"] without this would leave the box showing "-" on every IMC
    // collection page. Caught in build, not in production.
    //
    // owner_count from ?action=collection is the live COUNT(DISTINCT owner)
    // with burned rows excluded (A-1, v475) - the SAME number the Holders tab
    // shows, so the hero and the tab cannot contradict each other.
    //
    // Measured on a live collection: the WordPress and index counts differ,
    // and both differ from the ledger.
    //
    // Timeout 5s and a silent failure: if the VPS is unreachable the box shows
    // "-" rather than a number that means something else. An honest dash beats
    // a confident wrong answer (D15).
    if (!empty($issuer) && $taxon !== null) {
        $imc_own_r = wp_remote_get(
            "https://metadata.imcollectibles.io/?action=collection&issuer=" . urlencode($issuer) . "&taxon=" . $taxon,
            ["timeout" => 5]
        );
        if (!is_wp_error($imc_own_r) && wp_remote_retrieve_response_code($imc_own_r) === 200) {
            $imc_own_d = json_decode(wp_remote_retrieve_body($imc_own_r), true);
            $imc_own_c = $imc_own_d["collection"] ?? null;
            if (is_array($imc_own_c) && isset($imc_own_c["owner_count"]) && (int)$imc_own_c["owner_count"] > 0) {
                $collection_stats["owners"] = number_format((int)$imc_own_c["owner_count"]);
            }
            // CP-C3-B5b: SECONDARY volume, from the same payload - no extra
            // request. A collection with no secondary sales shows "-", which
            // is the truth; a zero would imply sales worth nothing.
            if (is_array($imc_own_c) && isset($imc_own_c["volume_xrp"])) {
                $v = (float)$imc_own_c["volume_xrp"];
                if ($v > 0) {
                    $collection_stats["volume"] = $v >= 1000000
                        ? number_format($v / 1000000, 1) . "M XRP"
                        : ($v >= 1000 ? number_format($v / 1000, 1) . "K XRP"
                                      : number_format($v, 2) . " XRP");
                }
                $collection_stats["token_sale_count"] = (int)($imc_own_c["token_sale_count"] ?? 0);
            }
        }
    }

} else {
    // ──────────────────────────────────────────────────────────────────────
    // EXTERNAL COLLECTION — VPS indexer primary, Bithomp fallback
    // (unchanged from v359)
    // ──────────────────────────────────────────────────────────────────────
    $vps_url = 'https://metadata.imcollectibles.io/?action=collection&issuer=' . urlencode($issuer) . '&taxon=' . $taxon;
    $vps_response = wp_remote_get($vps_url, ['timeout' => 10]);

    if (!is_wp_error($vps_response) && wp_remote_retrieve_response_code($vps_response) === 200) {
        $vps_data = json_decode(wp_remote_retrieve_body($vps_response), true);

        if (!empty($vps_data['success']) && !empty($vps_data['collection'])) {
            $col = $vps_data['collection'];

            if (isset($col['indexed_count']) && $col['indexed_count'] > 0) {
                $collection_stats['items'] = number_format($col['indexed_count']);
            } elseif (isset($col['total_supply']) && $col['total_supply'] > 0) {
                $collection_stats['items'] = number_format($col['total_supply']);
            }

            if (isset($col['owner_count']) && $col['owner_count'] > 0) {
                $collection_stats['owners'] = number_format($col['owner_count']);
            }

            if (isset($col['floor_xrp']) && $col['floor_xrp'] > 0) {
                $collection_stats['floor'] = number_format($col['floor_xrp'], 2) . ' XRP';
            }
            // CP-C3-B5b: secondary volume, same source as the IMC branch.
            if (isset($col['volume_xrp']) && (float)$col['volume_xrp'] > 0) {
                $v = (float)$col['volume_xrp'];
                $collection_stats['volume'] = $v >= 1000000
                    ? number_format($v / 1000000, 1) . 'M XRP'
                    : ($v >= 1000 ? number_format($v / 1000, 1) . 'K XRP'
                                  : number_format($v, 2) . ' XRP');
            }

            if (isset($col['sales_24h']) && $col['sales_24h'] > 0) {
                $collection_stats['sales'] = number_format($col['sales_24h']) . ' (24h)';
            }

            if ((empty($collection_name) || $collection_name === 'Collection') && !empty($col['name'])) {
                $collection_name = $col['name'];
            }
            if (empty($collection_desc) && !empty($col['description'])) {
                $collection_desc = $col['description'];
            }
            if ((empty($collection_image) || strpos($collection_image, 'placehold') !== false || strpos($collection_image, 'fallback') !== false) && !empty($col['image'])) {
                $collection_image = $col['image'];
            }
        }
    }

    // Bithomp fallback for gaps
    $bithomp_debug = ['url' => '', 'status' => null, 'error' => null];
    if ($collection_stats['owners'] === '-' || $collection_stats['floor'] === '-') {
        $bithomp_token = defined('BITHOMP_API_KEY') ? BITHOMP_API_KEY : '';
        if ($bithomp_token) {
            $bithomp_cid = $issuer . ':' . $taxon;
            $bithomp_url = 'https://bithomp.com/api/v2/nft-collection/' . rawurlencode($bithomp_cid) . '?statistics=true&floorPrice=true&volumes=true';
            $bithomp_debug['url'] = $bithomp_url;

            $bithomp_response = wp_remote_get($bithomp_url, [
                'timeout' => 8,
                'headers' => [
                    'x-bithomp-token' => $bithomp_token,
                    'Accept' => 'application/json'
                ]
            ]);

            if (!is_wp_error($bithomp_response)) {
                $bithomp_status = wp_remote_retrieve_response_code($bithomp_response);
                $bithomp_debug['status'] = $bithomp_status;

                if ($bithomp_status === 200) {
                    $bithomp_data = json_decode(wp_remote_retrieve_body($bithomp_response), true);

                    if (is_array($bithomp_data) && isset($bithomp_data['collection'])) {
                        $bCol = $bithomp_data['collection'];

                        if ($collection_stats['items'] === '-' && isset($bCol['statistics']['nfts'])) {
                            $collection_stats['items'] = number_format($bCol['statistics']['nfts']);
                        }
                        if ($collection_stats['owners'] === '-' && isset($bCol['statistics']['owners'])) {
                            $collection_stats['owners'] = number_format($bCol['statistics']['owners']);
                        }
                        if ($collection_stats['floor'] === '-' && isset($bCol['floorPrices'][0])) {
                            // Only use OPEN (public) floor — private offers are broker-restricted
                            if (isset($bCol['floorPrices'][0]['open']['amount'])) {
                                $floor_drops = intval($bCol['floorPrices'][0]['open']['amount']);
                                if ($floor_drops > 0) {
                                    $collection_stats['floor'] = number_format($floor_drops / 1000000, 2) . ' XRP';
                                }
                            }
                        }
                        if ($collection_stats['sales'] === '-' && isset($bCol['statistics']['all']['tradedNfts']) && $bCol['statistics']['all']['tradedNfts'] > 0) {
                            $collection_stats['sales'] = number_format($bCol['statistics']['all']['tradedNfts']);
                        }
                        if ((empty($collection_name) || $collection_name === 'Collection') && !empty($bCol['name'])) {
                            $collection_name = $bCol['name'];
                        }
                        if (empty($collection_desc) && !empty($bCol['description'])) {
                            $collection_desc = $bCol['description'];
                        }
                    }
                } else {
                    $bithomp_debug['error'] = 'HTTP ' . $bithomp_status;
                }
            } else {
                $bithomp_debug['error'] = $bithomp_response->get_error_message();
            }
        }
    }
} // end IMC vs external stats

// ============================================================================
// CP-C1 FIX 1 - ASSETS must mean "how many NFTs EXIST", identically everywhere.
// It was rendering $collection_stats['items'], which sums listings.total_editions
// = CAPACITY, not reality. Open editions are uncapped and store total_editions = 0
// (see the OE sentinel at ~:1230 and the sum at ~:1630), so a pure-OE collection
// showed 0 with 104 minted, and a mixed collection showed only its fixed listings'
// capacity. For IMC the truthful number is the one the old "Minted" box carried:
// minted/claimed purchases, already computed into ['sales'] on this branch.
// External collections already resolve ['items'] from the store's total_supply /
// indexed_count (~:1901), which is a real count - leave those alone. Their
// ['sales'] is a 24h sale count (~:1907) and must NEVER reach this box.
// ============================================================================
if (!empty($is_imc) && isset($collection_stats['sales']) && $collection_stats['sales'] !== '-') {
    $collection_stats['items'] = $collection_stats['sales'];
}

// Pass stats to JavaScript for client-side Bithomp fallback
// ============================================================================
// v213: RESOLVE COLLECTION NAME + IMAGE — runs INDEPENDENTLY of stats
// The Bithomp stats block only fires when owners/floor are missing.
// But VPS may provide stats while having NO name/image data for external
// collections. This block ensures name+image are always resolved.
// ============================================================================
if ($collection_name === 'Collection' || empty($collection_image) || strpos($collection_image, 'fallback') !== false) {
    // P1g (Aug 2026): the Bithomp name/image fetch that lived here sent
    // assets=true - free-tier-rejected, failing on every invocation - and its
    // role is superseded by the store-first block above (1E) plus the naming
    // arc (P1-OC / P3e). Deleted; the enrich-cover last resort below stays -
    // it reads the cache the (now store-first) NFT loader populates.
    // Last resort for image: use the enrichment cache (populated by NFT loader Bithomp enrichment)
    if (empty($collection_image) || strpos($collection_image, 'fallback') !== false) {
        $enrich_cover_key = 'collection_image_' . md5($issuer . '_' . $taxon);
        $enrich_cover = get_transient($enrich_cover_key);
        if ($enrich_cover && strpos($enrich_cover, 'fallback') === false) {
            $collection_image = $enrich_cover;
        }
    }
}

$stats_json = wp_json_encode($collection_stats);
?>

<!-- Collection Mode Styles (inline for reliability) -->
<style>
#nft-collection-page {
    width: 100%;
    max-width: 100%;
    padding: 0;
    /* Shared hero content column - title, description, actions and the stat row all
       measure from this, so they keep one left edge. 700 -> 900 -> 800 (the sweet
       spot). NOTE: until CP-C2c these rules were unscoped and lost to a later base
       rule, so the column sat at 600px whatever this said - see the media block. */
    --imc-hero-col: 800px;
    /* CP-C2b rev2: the "nudge left" is NOT a shift. Every other section on this page
       (.marketplace-section, .imc-collection-tabs, .imc-section-divider) is full-width
       with padding:2rem, while .collection-hero-inner was a CENTRED 1400px box - so the
       hero started ~200px right of everything below it. Shifting it by a magic number
       swapped one misalignment for another; removing the centring makes the hero share
       the page's left edge by construction. See .collection-hero-inner below. */
}
.collection-hero {
    padding: 2rem;
    background: transparent;
}
.collection-hero-inner {
    display: flex;
    /* CP-C2d: a touch more separation between the cover and the content column. */
    gap: 2.25rem;
    align-items: flex-start; /* v448: flex-start prevents vertical misalignment on multi-line titles */
    /* CP-C2b rev2: no max-width, no auto margins. The hero now fills its padded
       container exactly like .marketplace-section and .imc-collection-tabs do, so the
       cover lines up with the tab bar and the NFT grid below. Content width is still
       governed by --imc-hero-col, so prose never runs the full screen. */
    width: 100%;
}

/* CP-C2b: cover trimmed slightly so the wider 800px content column leads. */
.collection-hero-image {
    width: 180px;
    height: 180px;
    flex-shrink: 0;
    border-radius: 12px;
    overflow: hidden;
    border: 2px solid var(--imu-gold, var(--imu-gold, #d6ba66));
}
.collection-hero-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
/* v513: desktop — wrap the hero title to the content column (matches the
   description's constrained width) and enlarge the cover to sit flush with
   the bottom of the stats row. */
@media (min-width: 769px) {
    /* CP-B (D8): cover left, content right, one left edge. The old auto-margins
       centred the TITLE block while the description stayed flush left, so the two
       visibly disagreed whenever the column sat between 600 and 700px. Removing the
       auto-margins and sharing one width removes the conflict instead of patching it.
       R5: scoped to >=769 so the stacked mobile hero keeps its deliberate centring
       (.collection-hero-inner{text-align:center} in the max-768 block below). */
    #nft-collection-page .collection-hero-info { text-align: left; }
    /* CP-C2c: SCOPED with #nft-collection-page on purpose. These were plain class
       selectors, and a media query adds NO specificity - so the base rule
       .collection-hero-desc{max-width:600px} further down this stylesheet (and the
       identical one in trading-hub.css :899) simply won on source order. The column
       has therefore been stuck at 600px through both the 700 and the 900 change.
       (1,1,0) beats (0,1,0) regardless of order, so the token now actually governs. */
    #nft-collection-page .collection-hero-title { max-width: var(--imc-hero-col, 800px); }
    #nft-collection-page .collection-hero-desc  { max-width: var(--imc-hero-col, 800px); }
    #nft-collection-page .collection-hero-stats { max-width: var(--imc-hero-col, 800px); }
    #nft-collection-page .collection-hero-image { width: 250px; height: 250px; }
}
/* v509: centre the hero cover when the hero stacks on mobile */
@media (max-width: 768px) {
    .collection-hero-image { margin-left: auto; margin-right: auto; }
}
/* CP-C2c: scoped so it beats trading-hub.css :890 (.collection-hero-info{flex:1}).
   flex-basis 0 makes the column take ALL remaining row width, so --imc-hero-col is
   the only thing capping the content inside it. */
#nft-collection-page .collection-hero-info { flex: 1 1 0; min-width: 0; }
.collection-hero-info {
    flex: 1;
    min-width: 0; /* v448: prevent flex overflow from long titles pushing image off-screen */
}
.collection-hero-title {
    font-size: 1.75rem;
    margin: 0 0 0.5rem;
    word-wrap: break-word;
    overflow-wrap: anywhere;
    line-height: 1.3;
}
.collection-creator-link {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    margin: 0 0 0.6rem;
    padding: 7px 14px;
    border: 1px solid var(--imu-gold, var(--imu-gold, #d6ba66));
    border-radius: 10px;
    color: var(--imu-gold, var(--imu-gold, #d6ba66));
    background: rgba(212,175,55,.06);
    text-decoration: none;
    font-size: 0.85rem;
    font-weight: 600;
    transition: background .12s ease, transform .12s ease;
}
.collection-creator-link:hover {
    background: rgba(212,175,55,.14);
    transform: translateY(-1px);
}
.collection-hero-desc {
    color: var(--text-secondary, #aaa);
    margin: 0 0 0.5rem;
    max-width: 600px;
    word-wrap: break-word;
    overflow-wrap: break-word;
    font-size: 0.9rem;
    line-height: 1.5;
}
/* v450: Clamp long descriptions — prevents pushing mint box below fold */
.collection-hero-desc.is-clamped {
    display: -webkit-box;
    -webkit-line-clamp: 4;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.collection-desc-toggle {
    color: var(--imu-gold, var(--imu-gold, #d6ba66));
    cursor: pointer;
    font-size: 0.85rem;
    margin-bottom: 1rem;
    display: none;
    border: none;
    background: none;
    padding: 0;
}
.collection-desc-toggle:hover { text-decoration: underline; }
/* CP-C2: the four boxes become ONE panel with soft internal dividers
   (xrp.cafe pattern). Cells butt together and the border-left does the
   separating, so an empty "-" value can never shift a divider. */
.collection-hero-stats {
    display: flex;
    gap: 0;
    flex-wrap: wrap;
    background: rgba(212, 175, 55, 0.08);
    border: 1px solid rgba(212, 175, 55, 0.2);
    border-radius: 12px;
    overflow: hidden;
}
.collection-stat {
    background: transparent;
    border: 0;
    border-left: 1px solid rgba(212, 175, 55, 0.16);
    border-radius: 0;
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    padding: 0.9rem 0.9rem 0.8rem;
    text-align: center;
    min-width: 100px;
    /* CP-B: equal columns. The boxes used to size to their content, so
       "168 OWNERS" sat narrower than "3.00 XRP FLOOR". flex-basis 0 shares the
       row evenly while min-width keeps the wrap behaviour on narrow screens. */
    flex: 1 1 0;
    transition: all 0.3s ease;
}
.collection-stat:first-child { border-left: 0; }
.collection-stat:hover {
    background: rgba(212, 175, 55, 0.12);
    border-color: rgba(212, 175, 55, 0.4);
    transform: translateY(-2px);
}
/* CP-C2b: labels form a FOOTER ROW. margin-top:auto in a column-flex cell pushes
   every label to the bottom, so all four align on one line even if a value wraps.
   Type is reduced so "176.88 XRP" fits on ONE line at 800px (4 cells ~200px each,
   ~171px usable after padding). white-space:nowrap makes that guarantee explicit. */
.collection-stat-value {
    display: block;
    font-size: 1.15rem;
    font-weight: 700;
    color: var(--imu-gold, var(--imu-gold, #d6ba66));
    line-height: 1.25;
    white-space: nowrap;
}
.collection-stat-label {
    display: block;
    font-size: 0.68rem;
    color: var(--text-muted, #888);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: auto;
    padding-top: 0.4rem;
}

/* Enhanced Loading Visual */
.nft-grid-loading {
    grid-column: 1 / -1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 4rem 2rem;
    gap: 1.5rem;
}
.nft-grid-loading:not(.active) {
    display: none;
}
.collection-loading-visual {
    position: relative;
    width: 80px;
    height: 80px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.loading-pulse-ring {
    position: absolute;
    width: 100%;
    height: 100%;
    border: 2px solid var(--imu-gold, var(--imu-gold, #d6ba66));
    border-radius: 50%;
    animation: pulse-ring 1.5s ease-out infinite;
    opacity: 0;
}
.loading-pulse-ring.delay-1 {
    animation-delay: 0.5s;
}
.loading-pulse-ring.delay-2 {
    animation-delay: 1s;
}
@keyframes pulse-ring {
    0% {
        transform: scale(0.5);
        opacity: 0.8;
    }
    100% {
        transform: scale(1.5);
        opacity: 0;
    }
}
.nft-grid-loading .nft-loading-spinner {
    width: 40px;
    height: 40px;
    border: 3px solid rgba(212, 175, 55, 0.2);
    border-top-color: var(--imu-gold, var(--imu-gold, #d6ba66));
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}
@keyframes spin {
    to { transform: rotate(360deg); }
}
.nft-grid-loading span {
    color: var(--text-secondary, #aaa);
    font-size: 1rem;
}

#nft-collection-page .marketplace-nav {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 2rem;
    border-bottom: 1px solid var(--card-border, #2a2a4e);
    flex-wrap: wrap;
    gap: 1rem;
}
#nft-collection-page .nav-buttons {
    display: flex;
    gap: 0.75rem;
}
#nft-collection-page .nav-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.6rem 1rem;
    background: transparent;
    border: 1px solid var(--imu-gold, var(--imu-gold, #d6ba66));
    color: var(--imu-gold, var(--imu-gold, #d6ba66));
    border-radius: 6px;
    text-decoration: none;
    font-size: 0.9rem;
    cursor: pointer;
    transition: all 0.2s ease;
}
#nft-collection-page .nav-btn:hover {
    background: var(--imu-gold, var(--imu-gold, #d6ba66));
    color: var(--imu-dark, #0d0d1a);
}
/* v510: collection filter controls — side-by-side on desktop, stacked on mobile */
@media (min-width: 769px) {
    #nft-collection-page .filter-controls { display: flex; flex-direction: row; flex-wrap: nowrap; gap: 1rem; align-items: center; }
    #nft-collection-page .filter-controls .filter-select { width: auto; min-width: 190px; flex: 0 1 auto; }
}
#nft-collection-page .filter-select {
    padding: 0.6rem 2.2rem 0.6rem 1rem;
    background: linear-gradient(160deg, #08080e 0%, #0e0e16 60%, #141420 100%);
    border: 1px solid rgba(212, 175, 55, 0.15);
    color: var(--text-primary, #fff);
    border-radius: 6px;
    /* CP-A: kill the native OS widget (it rendered a white panel on a dark page)
       and adopt the drop-rail caret. Gradient is re-declared as layer 2 because
       the `background` shorthand above would otherwise reset background-image. */
    appearance: none;
    -webkit-appearance: none;
    cursor: pointer;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath fill='%23D4AF37' d='M0 0l5 6 5-6z'/%3E%3C/svg%3E"),
                      linear-gradient(160deg, #08080e 0%, #0e0e16 60%, #141420 100%);
    background-repeat: no-repeat, no-repeat;
    background-position: right 0.85rem center, 0 0;
}
#nft-collection-page .filter-select:hover {
    border-color: rgba(212, 175, 55, 0.6);
}
#nft-collection-page .filter-select option {
    background: #15131f;
    color: #e8e8e8;
}
#nft-collection-page .marketplace-section {
    padding: 2rem 2rem 2rem;
    width: 100%;
}
@media (max-width: 768px) {
    #nft-collection-page .marketplace-section {
        padding: 1rem 0;
    }
}
#nft-collection-page .nft-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(209px, 1fr));
    gap: 1.5rem;
    width: 100%;
    padding: 0;
    box-sizing: border-box;
    align-items: start;
}
@media (max-width: 768px) {
    #nft-collection-page .nft-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 0.4rem;
        padding: 0;
        align-items: start;
    }
}
/* NFT Card Styles */
#nft-collection-page .nft-card {
    background: linear-gradient(160deg, #05060f 0%, #080c18 50%, #0a0e1a 100%);
    border: 1px solid rgba(212, 175, 55, 0.15);
    border-radius: 12px;
    overflow: hidden;
    transition: all 0.3s ease;
    display: block;
    color: #fff;
}
#nft-collection-page .nft-card:hover {
    border-color: rgba(212, 175, 55, 0.5);
    transform: translateY(-4px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3), 0 0 15px rgba(212, 175, 55, 0.1);
}
#nft-collection-page .nft-card-image {
    aspect-ratio: 1;
    overflow: hidden;
    background: #0d0d1a;
}
#nft-collection-page .nft-card-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s ease;
}
#nft-collection-page .nft-card:hover .nft-card-image img {
    transform: scale(1.05);
}
#nft-collection-page .nft-card-info {
    padding: 0.38rem 0.6rem 0.35rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    background: transparent;
    border-top: none;
}
/* ============================================================================
   CP-B (D9): collection-page card label - EDITION FIRST, left-aligned.
   CSS ellipsis always truncates the TAIL. On a collection page the tail is the
   edition number and the head is the collection name the visitor already knows,
   so the old single-<h4> label discarded the only information that differed.
   Leading with the serial puts the unique part where truncation cannot reach it.
   These two classes were defined here years ago and wired to no renderer.
   ============================================================================ */
#nft-collection-page .nft-card-label {
    display: flex;
    align-items: baseline;
    gap: 0.35rem;
    width: 100%;
    text-align: left;
}
#nft-collection-page .nft-card-serial {
    font-size: 0.8rem;
    color: #d4af37;
    font-weight: 500;
    white-space: nowrap;
    /* equal-width digits so the name column does not jitter between #1 and #100 */
    font-variant-numeric: tabular-nums;
    flex: 0 0 auto;
    min-width: 2.1rem;
}
#nft-collection-page .nft-card-name {
    font-size: 0.85rem;
    font-weight: 600;
    margin: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 1 1 auto;
    min-width: 0;
    /* R1: the label used to be an <h4>, which picked up the Cinzel gold-gradient
       treatment from trading-hub.css :403 (.nft-card .nft-card-info h4). Moving off
       <h4> escapes that rule, so it is re-declared here to keep the card titles
       looking exactly as they do today. Keep these in step if that rule changes. */
    font-family: 'Cinzel', 'Times New Roman', serif;
    letter-spacing: 0.04em;
    background: linear-gradient(180deg,
        var(--imu-gold-shine, #ffe066) 0%,
        var(--imu-gold, var(--imu-gold, #d6ba66)) 40%,
        var(--imu-gold-dark, #996515) 100%
    );
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    filter: drop-shadow(0 0 10px rgba(212, 175, 55, 0.25));
    color: transparent;
}
#nft-collection-page .card-price-tag {
    position: absolute;
    top: 0;
    right: 0;
    z-index: 10;
    background: linear-gradient(135deg, rgba(0,8,24,0.92), rgba(0,28,56,0.92));
    border: 1px solid rgba(0,200,255,0.65);
    border-top: none;
    border-right: none;
    border-radius: 0 12px 0 8px;
    padding: 5px 11px;
    font-size: 0.78rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    color: #00d4ff;
    white-space: nowrap;
    backdrop-filter: blur(6px);
    box-shadow: inset 0 0 8px rgba(0,212,255,0.1), 0 2px 8px rgba(0,0,0,0.6);
}
/* v521: OWNED badge — top-left gold pill (mirrors .card-price-tag at top-right).
   Rendered by trading.js from the server owner field (immediate) and injected for
   the async on-chain ownership set. Only present on owned cards — others untouched. */
#nft-collection-page .card-owned-tag {
    position: absolute;
    top: 0;
    left: 0;
    z-index: 10;
    display: inline-flex;
    align-items: center;
    gap: 0.25em;
    background: linear-gradient(135deg, rgba(212,175,55,0.96), rgba(176,141,30,0.96));
    border: 1px solid rgba(212,175,55,0.85);
    border-top: none;
    border-left: none;
    border-radius: 0 0 12px 0;
    padding: 5px 11px;
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.05em;
    color: #1a1a2e;
    white-space: nowrap;
    box-shadow: 0 2px 8px rgba(0,0,0,0.5);
}
#nft-collection-page .pagination-controls {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 1rem;
    margin-top: 2rem;
    padding: 1rem;
}
#nft-collection-page .pagination-controls button {
    padding: 0.6rem 1.25rem;
    background: transparent;
    border: 1px solid var(--imu-gold, var(--imu-gold, #d6ba66));
    color: var(--imu-gold, var(--imu-gold, #d6ba66));
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s ease;
}
#nft-collection-page .pagination-controls button:hover:not(:disabled) {
    background: var(--imu-gold, var(--imu-gold, #d6ba66));
    color: var(--imu-dark, #0d0d1a);
}
#nft-collection-page .pagination-controls button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
@media (max-width: 768px) {
    .collection-hero-inner {
        flex-direction: column;
        text-align: center;
    }
    .collection-hero-stats {
        justify-content: center;
    }
    /* CP-C2 (R7 default): the panel wraps to a clean 2x2 on mobile rather than
       compressing four cells into ~70px each. Dividers follow the grid. */
    .collection-stat {
        padding: 0.75rem 1rem;
        min-width: 0;
        flex: 1 1 50%;
    }
    .collection-stat:nth-child(odd)  { border-left: 0; }
    .collection-stat:nth-child(n+3)  { border-top: 1px solid rgba(212, 175, 55, 0.16); }
    .collection-stat-value {
        font-size: 1.1rem;
    }
}

/* Activity Section Styles */
.collection-activity-section {
    max-width: 1400px;
    margin: 2rem auto;
    padding: 0 2rem;
}
.activity-section-title {
    font-size: 1.5rem;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid rgba(212, 175, 55, 0.3);
    /* colour + font: .imu-heading-gold via trading-hub.css */
}
.activity-table-wrapper {
    overflow-x: auto;
    background: rgba(26, 26, 46, 0.5);
    border-radius: 12px;
    border: 1px solid var(--card-border, #2a2a4e);
}
.activity-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 600px;
}
.activity-table th {
    text-align: left;
    padding: 1rem;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted, #888);
    border-bottom: 1px solid var(--card-border, #2a2a4e);
    background: rgba(0, 0, 0, 0.2);
}
.activity-table td {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    vertical-align: middle;
}
.activity-table tbody tr:hover {
    background: rgba(212, 175, 55, 0.05);
}
.activity-table tbody tr:last-child td {
    border-bottom: none;
}
.activity-item-cell {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.activity-item-thumb {
    width: 40px;
    height: 40px;
    border-radius: 6px;
    object-fit: cover;
    background: var(--card-bg, #1a1a2e);
}
.activity-item-name {
    font-weight: 500;
    color: var(--text-primary, #fff);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 120px;
}
.activity-price {
    color: var(--imu-gold, var(--imu-gold, #d6ba66));
    font-weight: 600;
}
.activity-event {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 500;
    text-transform: uppercase;
}
.activity-event.sale {
    background: rgba(76, 175, 80, 0.2);
    color: #4caf50;
}
.activity-event.list {
    background: rgba(33, 150, 243, 0.2);
    color: #2196f3;
}
.activity-event.cancel {
    background: rgba(244, 67, 54, 0.2);
    color: #f44336;
}
.activity-event.transfer {
    background: rgba(156, 39, 176, 0.2);
    color: #9c27b0;
}
.activity-event.mint {
    background: rgba(255, 193, 7, 0.2);
    color: #ffc107;
}
.activity-address {
    font-family: monospace;
    font-size: 0.85rem;
    color: var(--text-secondary, #aaa);
}
.activity-address a {
    color: inherit;
    text-decoration: none;
}
.activity-address a:hover {
    color: var(--imu-gold, var(--imu-gold, #d6ba66));
}
.activity-time {
    font-size: 0.85rem;
    color: var(--text-muted, #888);
    white-space: nowrap;
}
.activity-loading-spinner {
    width: 24px;
    height: 24px;
    border: 3px solid rgba(212, 175, 55, 0.2);
    border-top-color: var(--imu-gold, var(--imu-gold, #d6ba66));
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    display: inline-block;
    margin-right: 0.5rem;
    vertical-align: middle;
}
.activity-empty {
    text-align: center;
    padding: 2rem;
    color: var(--text-muted, #888);
}
@media (max-width: 768px) {
    .collection-activity-section {
        padding: 0 1rem;
    }
    .activity-table th,
    .activity-table td {
        padding: 0.5rem;
        font-size: 0.85rem;
    }
    .activity-item-thumb {
        width: 32px;
        height: 32px;
    }
    .activity-item-name {
        max-width: 80px;
    }
}

/* ============================================================
   CUSTOM.CSS KILL — v345
   custom.css (loaded sitewide) sets nft-card padding:15px and
   nft-image-wrapper height:200px/150px which creates dead space.
   These rules are inline so they load last and win decisively.
   ============================================================ */
#nft-collection-page .nft-card {
    padding: 0 !important;
    min-height: 0 !important;
    height: auto !important;
}
#nft-collection-page .nft-grid .nft-card {
    min-height: 0 !important;
    height: auto !important;
}
#nft-collection-page .nft-card .nft-image-wrapper {
    height: 0 !important;
    padding-bottom: 100% !important;
    max-height: none !important;
    min-height: 0 !important;
}
#nft-collection-page .nft-card .nft-image-wrapper img,
#nft-collection-page .nft-card .nft-image {
    max-height: none !important;
    max-width: none !important;
    width: 100% !important;
    height: 100% !important;
    object-fit: cover !important;
    position: absolute !important;
    top: 0 !important;
    left: 0 !important;
}
</style>

<div id="nft-collection-page" class="trading-hub-container"
     data-account="<?php echo esc_attr($xrpl_account); ?>"
     data-nonce="<?php echo esc_attr($nonce); ?>"
     data-endpoints='<?php echo $endpoints_json; ?>'
     data-issuer="<?php echo esc_attr($issuer); ?>"
     data-taxon="<?php echo esc_attr($taxon); ?>">

    <!-- Marketplace Navigation Header -->
    <nav class="marketplace-header-nav">
        <a href="<?php echo esc_url(home_url('/trading-hub/')); ?>" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
            Trading Hub
        </a>
        <a href="<?php echo esc_url(home_url('/collections/')); ?>" class="nav-link active">
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

    <!-- Collection Hero -->
    <section class="collection-hero">
        <div class="collection-hero-inner">
            <div class="collection-hero-image">
                <img src="<?php echo esc_url($collection_image); ?>" 
                     alt="<?php echo esc_attr($collection_name); ?>"
                     loading="lazy"
                     onerror="this.src='<?php echo esc_url($fallback_image); ?>'">
            </div>
            <div class="collection-hero-info">
                <h1 class="collection-hero-title"><?php echo esc_html($collection_name); ?></h1>
                <?php
                // CP-C1 FIX 3: the creator link and the Collection Breakdown button are one
                // ACTION ROW directly under the title (xrp.cafe pattern). Previously they were
                // two separate blocks straddling the description, and .imc-breakdown-bar used
                // justify-content:center - so after CP-B left-aligned the hero, that button was
                // the single element still centred. Presence is resolved FIRST so the wrapper
                // is never emitted empty (it carries a gap and a margin).
                // v579 kill-switch preserved: define('IMC_CREATOR_LINK_DISABLED', true).
                $imc_creator_html = '';
                if ((!defined('IMC_CREATOR_LINK_DISABLED') || !IMC_CREATOR_LINK_DISABLED) && !empty($issuer) && function_exists('imc_artist_profile_get')) {
                    $imc_creator_prof = imc_artist_profile_get($issuer);
                    $imc_creator_slug = is_array($imc_creator_prof) ? ($imc_creator_prof['slug'] ?? '') : '';
                    if ($imc_creator_slug !== '') {
                        $imc_creator_html = '<a class="collection-creator-link" href="' . esc_url(home_url('/artists/' . $imc_creator_slug)) . '">&#128100; Explore Creator&rsquo;s Collections</a>';
                    }
                }
                $imc_show_breakdown = ($mint_status && !empty($mint_status['is_imc_collection']) && !empty($mint_status['listings']));
                // CP-C2b: presence is resolved HERE but the row is EMITTED below the
                // description - see the render block after it. Resolving up here keeps
                // imc_artist_profile_get() to a single call.
                ?>
                <?php if (!empty($collection_desc)): ?>
                <p class="collection-hero-desc is-clamped" id="collection-desc"><?php echo esc_html($collection_desc); ?></p>
                <button type="button" class="collection-desc-toggle" id="collection-desc-toggle" onclick="
                    var d=document.getElementById('collection-desc'),b=this;
                    d.classList.toggle('is-clamped');
                    b.textContent=d.classList.contains('is-clamped')?'Read more ▾':'Show less ▴';
                ">Read more ▾</button>
                <?php endif; ?>

                <?php /* CP-C2b: the action row now sits BELOW the description, so the
                   hero reads title -> story -> actions -> numbers. */ ?>
                <?php if ($imc_creator_html !== '' || $imc_show_breakdown): ?>
                <div class="collection-hero-actions">
                    <?php echo $imc_creator_html; ?>
                    <?php if ($imc_show_breakdown): ?>
                    <button class="mint-preview-btn imc-breakdown-btn" onclick="imcToggleBreakdown(true)">&#128202; Collection Breakdown</button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <div class="collection-hero-stats">
                    <div class="collection-stat">
                        <span class="collection-stat-value" id="collection-owner-count"><?php echo esc_html($collection_stats['owners']); ?></span>
                        <span class="collection-stat-label">Owners</span>
                    </div>
                    <div class="collection-stat">
                        <span class="collection-stat-value" id="collection-floor"><?php echo esc_html($collection_stats['floor']); ?></span>
                        <span class="collection-stat-label">Floor</span>
                    </div>
                    <?php /* CP-B (R2): the set is now FIXED at four so the row cannot reflow
                       between collections. Volume always renders (honest "-" when we have no
                       secondary sales for it). The old fourth box carried THREE different
                       quantities under two labels - minted count on IMC, a 24h sale count on
                       external (:1907), an all-time traded count from Bithomp (:2008) - so it
                       is replaced by $collection_stats['items'], which both branches already
                       compute (:1809 total_editions / :1901 total_supply) and nothing rendered. */ ?>
                    <div class="collection-stat">
                        <span class="collection-stat-value" id="collection-volume"><?php echo esc_html($collection_stats['volume']); ?></span>
                        <span class="collection-stat-label">Volume</span>
                    </div>
                    <div class="collection-stat">
                        <span class="collection-stat-value" id="collection-item-count"><?php echo esc_html($collection_stats['items']); ?></span>
                        <span class="collection-stat-label">Assets</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php /* CP-C2 (R6): fixed section boundary - the hero ends here. Always
       rendered; it is not tied to whether the collection has listings. */ ?>
    <hr class="imc-section-divider" aria-hidden="true">

    <?php if ($mint_status && $mint_status['is_imc_collection']): ?>
    <!-- Mint Status Banner -->
    <?php
    // ════════════════════════════════════════════════════════════════════
    // v505 PHASE B: DROP BOXES RAIL — replaces the v234 singleton banner.
    // One self-contained box per ACTIVE listing (OE + standard): own state,
    // own progress, own price, own countdown, own preview/mint bound to its
    // own listing id. Closed/sold-out drops are summarised in the Collection
    // Breakdown modal. A fully sold-out collection renders one SOLD OUT box.
    // window.imcFirstListing is still emitted (newest active) — the preview
    // modal cache path and the legacy v251 early-access block depend on it.
    // ════════════════════════════════════════════════════════════════════
    $first_listing = null;
    $first_listing_id = null;
    $drop_boxes = [];
    foreach ($mint_status['listings'] as $l) {
        if ($l['status'] === 'active') {
            if ($first_listing === null) { $first_listing = $l; $first_listing_id = $l['id']; }
            $drop_boxes[] = $l;
        }
    }
    if ($first_listing) {
        echo '<script>window.imcFirstListing = ' . wp_json_encode($first_listing) . ';</script>';
    }
    $rail_count = count($drop_boxes);

    // v649: type-filter counts for the rail filter pills (OE vs Fixed). Server-side,
    // display-only; the filter renders ONLY when the rail actually mixes both types.
    $oe_count = 0; $fixed_count = 0; $nonxrp_count = 0;
    foreach ($drop_boxes as $__cb) {
        if ((($__cb['edition_type'] ?? 'fixed') === 'open') && empty($__cb['open_edition_closed_at'])) { $oe_count++; }
        else { $fixed_count++; }
        if ((($__cb['pricing_mode'] ?? 'static') === 'dynamic') || floatval($__cb['price_xrp'] ?? 0) <= 0) { $nonxrp_count++; }
    }
    $show_type_filter = ($rail_count > 1 && $oe_count > 0 && $fixed_count > 0);
    $show_price_note  = ($nonxrp_count > 0);
    $bx_order = 0; // v650: natural (newest-first) render index for the sort dropdown

    // v619: per-viewer "Collected" counts for the drop-box mint banners (keyed listing_id).
    // Kill-switch: define('IMC_COLLECTED_BADGE_DISABLED', true). Additive, fail-safe (empty => no row).
    $imc_box_collected = [];
    $imc_cc_has_wallet = false;
    if ((!defined('IMC_COLLECTED_BADGE_DISABLED') || !IMC_COLLECTED_BADGE_DISABLED) && $rail_count > 0) {
        $imc_bc_acct = function_exists('imc_session_wallet') ? imc_session_wallet() : '';
        if (preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $imc_bc_acct)) {
            $imc_cc_has_wallet = true;
            global $wpdb;
            $imc_bc_pt = $wpdb->prefix . 'imc_purchases';
            if ($wpdb->get_var("SHOW TABLES LIKE '$imc_bc_pt'") === $imc_bc_pt) {
                $imc_bc_rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT listing_id, COUNT(*) AS n FROM $imc_bc_pt
                     WHERE buyer_account = %s AND mint_status IN ('minted','claimed')
                     GROUP BY listing_id", $imc_bc_acct), ARRAY_A);
                foreach (($imc_bc_rows ?: []) as $imc_bc_r) {
                    $imc_box_collected[intval($imc_bc_r['listing_id'])] = intval($imc_bc_r['n']);
                }
            }
        }
    }
    /* ========================================================================
       M10 -- TRUE MINTED COUNT for the cards.
       listings.minted_count is the edition-number ALLOCATOR (LAST_INSERT_ID(minted_count+1)
       at four sites), not a tally. When M-ED retires a number after a failed mint it stays
       consumed, so the allocator runs AHEAD of the NFTs that exist -- 10 live listings are
       currently over-reporting. The column must NEVER be lowered: dropping
       it below max(edition_number) would make the next mint reissue a live edition.
       COUNT(DISTINCT edition_number) is the only exact source -- SUM(mint_progress) is wrong
       on some listings and a plain row count on others (historical duplicates give 2 rows/number).
       ONE aggregate for every card on the page; falls back to the allocator if it returns nothing.
       ======================================================================== */
    $imc_box_realmint = [];
    if ($rail_count > 0) {
        global $wpdb;
        $imc_rm_ids = array_filter(array_map(fn($b) => intval($b['id']), $drop_boxes));
        if (!empty($imc_rm_ids)) {
            $imc_rm_in = implode(',', $imc_rm_ids);
            $imc_rm_pt = $wpdb->prefix . 'imc_purchases';
            $imc_rm_rows = $wpdb->get_results(
                "SELECT listing_id, COUNT(DISTINCT edition_number) AS n FROM $imc_rm_pt
                 WHERE listing_id IN ($imc_rm_in) AND mint_status IN ('minted','claimed')
                   AND edition_number > 0 GROUP BY listing_id", ARRAY_A);
            foreach (($imc_rm_rows ?: []) as $imc_rm_r) {
                $imc_box_realmint[intval($imc_rm_r['listing_id'])] = intval($imc_rm_r['n']);
            }
        }
    }
    ?>
    <?php
    /* ========================================================================
       M10 -- progressive-pricing card display. READ-ONLY: computes nothing that
       charges. imc_pp_materialize (R13) already writes the CURRENT step price to
       price_xrp / price_usd / accepted_currencies[].price, so the card reads the
       live price instead of deriving it -- no step count, no query, and no third
       copy of the charging math.
         next = current + increment, capped
         bar  = (current - base) / (end - base); end = cap, or on fixed supply
                base + inc*(total-1), whichever is lower. Uncapped open editions
                have no end -> pct null -> text only, no bar.
       ======================================================================== */
    if (!function_exists('imc_box_pp_lanes')) {
    function imc_box_pp_lanes($bx, $is_oe, $total) {
        if (defined('IMC_PROGRESSIVE_PRICING') && !IMC_PROGRESSIVE_PRICING) return [];
        if (empty($bx['progressive_json'])) return [];
        $cfg = json_decode($bx['progressive_json'], true);
        if (!is_array($cfg) || empty($cfg['enabled']) || empty($cfg['increments']) || !is_array($cfg['increments'])) return [];
        $pm = $bx['pricing_mode'] ?? 'static';
        if ($pm !== 'static' && $pm !== 'dynamic') return [];
        $acc = [];
        if (!empty($bx['accepted_currencies'])) { $d = json_decode($bx['accepted_currencies'], true); if (is_array($d)) $acc = $d; }
        $lanes = [];
        foreach ($cfg['increments'] as $pc => $inc) {
            $pc = strtoupper($pc); $inc = (float)$inc;
            if ($inc <= 0) continue;
            $cap = isset($cfg['cap'][$pc]) ? (float)$cfg['cap'][$pc] : null;
            $now = null; $base = null; $label = $pc;
            if ($pc === 'USD') {
                $now  = isset($bx['price_usd']) ? (float)$bx['price_usd'] : null;
                $base = isset($cfg['base']['USD']) ? (float)$cfg['base']['USD'] : $now;
            } else {
                foreach ($acc as $c) {
                    $code = strtoupper($c['currency'] ?? ''); $hex = strtoupper($c['currency_hex'] ?? '');
                    if ($code !== $pc && $hex !== $pc) continue;
                    $now  = isset($c['price']) ? (float)$c['price'] : null;
                    $base = isset($c['base_price']) ? (float)$c['base_price'] : $now;
                    if ($code !== '') $label = $code;
                    break;
                }
                if ($now === null && $pc === 'XRP' && (float)($bx['price_xrp'] ?? 0) > 0) {
                    $now  = (float)$bx['price_xrp'];
                    $base = isset($cfg['base']['XRP']) ? (float)$cfg['base']['XRP'] : $now;
                }
            }
            if ($now === null || $now <= 0 || $base === null || $base <= 0) continue;
            $end = null;
            if (!$is_oe && $total > 1) $end = $base + $inc * ($total - 1);
            if ($cap !== null && $cap > 0) $end = ($end === null) ? $cap : min($end, $cap);
            $next = $now + $inc;
            if ($cap !== null && $cap > 0 && $next > $cap) $next = $cap;
            $capped = ($cap !== null && $cap > 0 && $now >= $cap - 1e-9);
            $pct = null;
            if ($end !== null && $end > $base) $pct = (int) max(0, min(100, round(($now - $base) / ($end - $base) * 100)));
            if ($capped) $pct = 100;
            $lanes[] = ['cur'=>$label,'inc'=>$inc,'now'=>$now,'next'=>$next,'end'=>$end,'pct'=>$pct,'capped'=>$capped,'usd'=>($pc==='USD')];
        }
        return $lanes;
    }
    function imc_box_pp_f($v, $usd = false) {
        $v = (float)$v;
        if ($v >= 1000) { $s = number_format($v, 0); }
        else {
            $s = rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
            if ($s === '') $s = '0';
            $dot = strpos($s, '.');
            if ($dot === false) $s .= '.00';
            elseif (strlen(substr($s, $dot + 1)) < 2) $s = str_pad($s, $dot + 3, '0');
        }
        return $usd ? ('$' . $s) : $s;
    }
    }
    ?>
    <style id="imc-drop-rail-style">
    /* v510: centred flex rail — up to 3 boxes per row on desktop; 1 or 2 boxes sit centred */
    /* v518: asserted at !important — this was the block's ONLY non-asserted critical rule,
       so theme/late-block CSS could defeat display:flex and stack boxes vertically on
       desktop. width:100% guarantees the rail spans its section. flex-basis 340px with
       grow + max-width 380px lets THREE boxes share one desktop row (3×380+gaps exceeds
       the 1200px section, which silently wrapped the third); boxes grow back toward 380.
       Wrap pattern: 1 centred · 2 side-by-side · 3 one row · 4 → 2+2 (via .imc-rail-n4
       container clamp) · 5 → 3+2 · 6 → 3+3. Mobile (≤768px) untouched: stacked 100%. */
    .imc-drop-rail{display:flex !important;flex-flow:row wrap !important;justify-content:center !important;gap:1.25rem !important;width:100% !important;}
    .imc-rail-n4{max-width:820px !important;margin:0 auto !important;}
    /* v512: assert the box's own layout at (0,2,0)+!important — the late v234 block
       sets .mint-banner-inner{gap:1.5rem !important} (0.9rem on mobile), which was the
       residual space above/below the minted line and between every row. */
    .imc-drop-rail .imc-drop-box{display:flex !important;flex-direction:column !important;align-items:center !important;justify-content:flex-start !important;flex-wrap:nowrap !important;gap:.35rem !important;flex:0 0 360px !important;width:360px !important;max-width:360px !important;}
    /* v511: compact rhythm + centring, at (0,2,0)+!important so the late v234
       single-class !important block and any base margins can never reopen the gaps. */
    .imc-drop-rail .mint-banner-status,
    .imc-drop-rail .mint-banner-progress,
    .imc-drop-rail .mint-price-info,
    .imc-drop-rail .mint-countdown{margin:0 !important;}
    .imc-drop-rail .mint-price-info{text-align:center !important;}
    .imc-drop-rail .mint-progress-stats{display:flex !important;justify-content:center !important;gap:.5rem !important;margin:0 !important;}
    .imc-drop-rail .mint-countdown-label{margin:.25rem 0 .1rem !important;}
    .imc-drop-rail .mint-preview-btn,
    .imc-drop-rail .mint-banner-btn{margin:.1rem 0 0 !important;}
    .imc-drop-rail .mint-banner-progress{flex:0 0 auto !important;width:100% !important;max-width:340px !important;}
    .imc-drop-rail .mint-banner-collected{color:#00ff00 !important;}
    .imc-drop-rail .mint-banner-collected-none{color:#ff3b30 !important;}
    /* v512: desktop-only extra compaction (mobile rhythm already right) */
    @media (min-width:769px){
        .imc-drop-rail .imc-drop-box{gap:.25rem !important;padding:1.1rem 1.4rem !important;}
        .imc-drop-rail .mint-countdown-label{margin:.15rem 0 .05rem !important;}
        .imc-drop-rail .mint-preview-btn,.imc-drop-rail .mint-banner-btn{margin:.05rem 0 0 !important;}
    }
    .imc-drop-box .mint-countdown{justify-content:center;}
    .imc-drop-box .mint-countdown-label,.imc-drop-box .mint-countdown-date{text-align:center;}
    .imc-box-title{font-weight:700;font-size:1.05rem;text-align:center;color:#fff;max-width:100%;overflow-wrap:break-word;word-break:break-word;display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden;}
    /* v519: per-card cover at the top of each MINT card — rounded square, centred.
       Safe in this column layout: width is capped so there is no flex-row aspect-ratio
       feedback loop (the bug that sank the dynamic hero cover in v514). */
    .imc-drop-rail .imc-box-cover{width:100%;max-width:190px;margin:.1rem auto .35rem;aspect-ratio:1/1;border-radius:14px;overflow:hidden;background:rgba(255,255,255,.04);}
    .imc-drop-rail .imc-box-cover img{width:100%;height:100%;object-fit:cover;display:block;}
    /* v523: inline preview player below the cover. music/album->audio, musicvideo/film->video.
       preload:none means media loads ONLY on play — never on page load across the rail. */
    .imc-drop-rail .imc-box-player{width:100%;max-width:230px;margin:.1rem auto .4rem;}
    .imc-drop-rail .imc-box-media{width:100%;display:block;border-radius:10px;}
    /* v525: modest native-control theming — dark scheme + gold accent. */
    .imc-drop-rail audio.imc-box-media{height:38px;background:#15131f;border:1px solid rgba(201,168,50,.28);accent-color:#D4AF37;color-scheme:dark;}
    .imc-drop-rail video.imc-box-media{aspect-ratio:1/1;object-fit:cover;background:#000;border:1px solid rgba(201,168,50,.28);accent-color:#D4AF37;color-scheme:dark;}
    /* v527: custom audio control bar — dark + gold, replaces the un-stylable native bar.
       The <audio class="imc-engine"> is the hidden engine; this bar drives it. Video stays native. */
    .imc-drop-rail audio.imc-engine{display:none !important;}
    .imc-drop-rail .imc-player{display:flex;align-items:center;gap:.5rem;width:100%;max-width:240px;margin:0 auto;background:#15131f;border:1px solid rgba(201,168,50,.32);border-radius:999px;padding:.32rem .6rem;box-sizing:border-box;}
    .imc-drop-rail .imc-pp{flex:0 0 auto;width:30px;height:30px;border-radius:50%;border:none;background:linear-gradient(135deg,#D4AF37,#b08d1e);color:#15131f;font-size:.78rem;cursor:pointer;display:flex;align-items:center;justify-content:center;line-height:1;padding:0;}
    .imc-drop-rail .imc-pp:hover{filter:brightness(1.12);}
    /* v530: fully custom seek — track, fill and thumb are all DIVs; the <input> is opacity:0 and
       sits on top purely to capture clicks/drags. With opacity:0 the browser renders NOTHING native
       (no track, no thumb), so the double-bar artefact is impossible. --p drives fill width + thumb. */
    .imc-drop-rail .imc-progress{position:relative;flex:1 1 auto;height:14px;min-width:0;display:flex;align-items:center;cursor:pointer;touch-action:none;--p:0%;}
    .imc-drop-rail .imc-progress::before{content:"";position:absolute;left:0;right:0;top:50%;transform:translateY(-50%);height:4px;border-radius:2px;background:rgba(255,255,255,.16);}
    .imc-drop-rail .imc-progress-fill{position:absolute;left:0;top:50%;transform:translateY(-50%);height:4px;border-radius:2px;background:#D4AF37;width:var(--p);pointer-events:none;}
    .imc-drop-rail .imc-progress-thumb{position:absolute;top:50%;left:var(--p);transform:translate(-50%,-50%);width:12px;height:12px;border-radius:50%;background:#D4AF37;border:2px solid #15131f;box-shadow:0 0 3px rgba(0,0,0,.5);pointer-events:none;}
    .imc-drop-rail .imc-time{flex:0 0 auto;font-size:.68rem;color:#cfc7ad;min-width:62px;text-align:right;font-variant-numeric:tabular-nums;}
    /* v524: album multi-track preview player (next/prev + current track title). */
    .imc-drop-rail .imc-album-player{display:flex;flex-direction:column;gap:.3rem;}
    .imc-drop-rail .imc-album-now{font-size:.78rem;color:#e9e2c9;text-align:center;max-width:230px;margin:0 auto;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .imc-drop-rail .imc-album-nav{display:flex;align-items:center;justify-content:center;gap:.6rem;}
    .imc-drop-rail .imc-album-nav button{background:rgba(201,168,50,.14);border:1px solid rgba(201,168,50,.4);color:#e9c84a;border-radius:8px;padding:.1rem .55rem;font-size:.9rem;cursor:pointer;line-height:1.4;}
    .imc-drop-rail .imc-album-nav button:hover{background:rgba(201,168,50,.28);}
    .imc-drop-rail .imc-album-count{font-size:.72rem;color:#9a937a;min-width:46px;text-align:center;}
    /* CP-C1: retained only as a safety net for any cached markup; the live hero now
       uses .collection-hero-actions below. */
    .imc-breakdown-bar{display:flex;justify-content:center;margin:.4rem 0 .9rem;}
    /* CP-C1 FIX 3: one action row under the title. flex-wrap so a long creator name
       plus the breakdown button drop to two lines rather than overflowing. */
    .collection-hero-actions{display:flex;flex-wrap:wrap;align-items:center;gap:.6rem;margin:.9rem 0 1rem;}

    /* =====================================================================
       CP-C2 - SECTION DIVIDER
       Direct port of the XRPLAYR gold hairline treatment:
         Brush.horizontalGradient(Transparent, IMUGold, Transparent)
       with IMUGold = 0xFFE6CE68 - which IS --imu-gold. Note the fallback is
       var(--imu-gold, #d6ba66), NOT the #d4af37 written across 266 other sites in this theme:
       that value is a decoy and copying it here would spread it further.
       ===================================================================== */
/* CP-T2 (13 Sep 2026): ~308 lines of tab-panel, holders, activity, stat and
       section-divider CSS MOVED OUT of this block, for the same reason CP-T moved
       the tab bar: this style element only renders inside the is_imc_collection
       gate, while the markup it styles renders for EVERY collection.

       CP-T moved only the eight tab-bar rules. That was too narrow - it fixed the
       look of the buttons and nothing else, because three things stayed trapped:
         - the panel-visibility rule (an inactive panel member is display:none).
           It is the ONLY rule that hides a panel. Without it every panel painted
           at once, stacked under the NFT grid, and clicking a tab appeared to do
           nothing. The JS was never at fault: initCollectionTabs() lives in
           trading.js, is NOT gated, and was toggling is-active correctly all
           along - there was simply no rule acting on it.
         - the section divider, hence a default solid white rule instead of the
           gold gradient.
         - every holders, activity, panel and stat rule behind those two tabs.

       All of it now lives in #imc-collection-tabs-style below, emitted
       unconditionally. Rail-only rules (rail, drop, box, breakdown and the hero
       actions) stay here - they belong to the drop rail and are correctly
       IMC-only.
       NOTE: do not paste literal selectors or PHP tags into these comments; both
       have already produced false positives or parse errors in this file. */
    .collection-hero-actions .collection-creator-link{margin:0;}
    /* CP-C2c: the breakdown button now matches "Explore Creator's Collections"
       exactly. It inherits .mint-preview-btn, so every property that class could set
       is restated here. `button` sits in the custom.css colour nuke
       (color:#fff !important) - hence armour on colour only. */
    .collection-hero-actions .imc-breakdown-btn{
        display:inline-flex;align-items:center;gap:7px;margin:0;
        padding:7px 14px;
        border:1px solid var(--imu-gold, var(--imu-gold, #d6ba66));
        border-radius:10px;
        color:var(--imu-gold, var(--imu-gold, #d6ba66)) !important;
        background:rgba(212,175,55,.06);
        text-decoration:none;
        font-size:.85rem;font-weight:600;line-height:1.2;
        box-shadow:none;text-transform:none;letter-spacing:normal;
        transition:background .12s ease, transform .12s ease;
        cursor:pointer;
    }
    .collection-hero-actions .imc-breakdown-btn:hover{
        background:rgba(212,175,55,.14);transform:translateY(-1px);
    }
    @media (max-width: 768px){ .collection-hero-actions{justify-content:center;} }
    .imc-breakdown-overlay{position:fixed;inset:0;background:rgba(0,0,0,.78);z-index:99999;display:flex;align-items:center;justify-content:center;padding:1rem;}
    .imc-breakdown-panel{background:#171a2e;border:1px solid var(--imu-gold, var(--imu-gold, #d6ba66));border-radius:14px;max-width:560px;width:100%;max-height:80vh;overflow:auto;padding:1.25rem;color:#fff;}
    .imc-breakdown-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem;}
    .imc-breakdown-close{background:none;border:none;color:#fff;font-size:1.25rem;cursor:pointer;line-height:1;}
    .imc-breakdown-table{width:100%;border-collapse:collapse;font-size:.92rem;}
    .imc-breakdown-table th,.imc-breakdown-table td{padding:.45rem .5rem;text-align:left;border-bottom:1px solid rgba(255,255,255,.08);}
    @media (max-width:768px){.imc-drop-rail .imc-drop-box{flex-basis:100% !important;width:100% !important;max-width:100% !important;}}
    /* v649: rail type-filter pills (Open Editions vs Fixed) */
    .imc-rail-filter{display:flex;align-items:center;justify-content:center;flex-wrap:wrap;gap:.6rem;margin:0 0 1.1rem;width:100%;}
    .imc-rail-fbtns{display:inline-flex;flex-wrap:wrap;gap:.5rem;}
    .imc-rail-sort{margin-left:auto;display:inline-flex;align-items:center;gap:.45rem;color:#9aa0aa;font-size:.8rem;}
    .imc-rail-sort label{color:#9aa0aa;font-weight:600;white-space:nowrap;}
    .imc-rail-sort-select{background:#15131f;border:1px solid rgba(201,168,50,.28);color:#e8e8e8;font-size:.82rem;font-weight:600;padding:.45rem 2rem .45rem .85rem;border-radius:999px;cursor:pointer;appearance:none;-webkit-appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath fill='%23D4AF37' d='M0 0l5 6 5-6z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right .8rem center;}
    .imc-rail-sort-select:hover{border-color:rgba(212,175,55,.6);}
    .imc-rail-sort-note{width:100%;text-align:center;margin:-.4rem 0 1.1rem;font-size:.76rem;color:#b3a575;display:none;}
    .imc-rail-sort-note.show{display:block;}
    .imc-rail-fbtn{display:inline-flex;align-items:center;gap:.45rem;background:#15131f;border:1px solid rgba(201,168,50,.28);color:#cfcfcf;font-size:.82rem;font-weight:600;letter-spacing:.02em;padding:.5rem 1rem;border-radius:999px;cursor:pointer;transition:border-color .12s,color .12s,background .12s;}
    .imc-rail-fbtn:hover{border-color:rgba(212,175,55,.6);color:#fff;}
    .imc-rail-fbtn.is-active{background:linear-gradient(135deg,#D4AF37,#b08d1e);border-color:#D4AF37;color:#15131f;}
    .imc-rail-fbtn .imc-fbtn-n{font-size:.72rem;font-weight:700;background:rgba(0,0,0,.18);color:inherit;border-radius:999px;padding:.05rem .45rem;min-width:1.2rem;text-align:center;}
    .imc-rail-fbtn.is-active .imc-fbtn-n{background:rgba(21,19,31,.22);}
    .imc-drop-box.imc-hide-type{display:none !important;}
    </style>
    <?php /* CP-A: banner suppressed entirely once no listing is active (D1). */ ?>
    <?php if ($rail_count > 0): ?>
    <section class="mint-status-banner active">
        <?php if ($rail_count > 1): ?>
        <div class="imc-rail-filter" role="group" aria-label="Filter and sort listings">
            <?php if ($show_type_filter): ?>
            <div class="imc-rail-fbtns">
                <button type="button" class="imc-rail-fbtn is-active" data-filter="all" aria-pressed="true">All<span class="imc-fbtn-n"><?php echo (int) $rail_count; ?></span></button>
                <button type="button" class="imc-rail-fbtn" data-filter="oe" aria-pressed="false">Open Editions<span class="imc-fbtn-n"><?php echo (int) $oe_count; ?></span></button>
                <button type="button" class="imc-rail-fbtn" data-filter="fixed" aria-pressed="false">Fixed<span class="imc-fbtn-n"><?php echo (int) $fixed_count; ?></span></button>
            </div>
            <?php endif; ?>
            <div class="imc-rail-sort">
                <label for="imc-rail-sort-select">Sort by</label>
                <select id="imc-rail-sort-select" class="imc-rail-sort-select">
                    <option value="recent" selected>Recently Listed First</option>
                    <option value="price-asc">Price: Low to High</option>
                    <option value="price-desc">Price: High to Low</option>
                    <option value="time">Time Sensitive</option>
                </select>
            </div>
        </div>
        <?php if ($show_price_note): ?><div class="imc-rail-sort-note" id="imc-rail-sort-note">Sorted by XRP price. Listings priced in USD or XRPL tokens are shown at the end.</div><?php endif; ?>
        <?php endif; ?>
        <div class="imc-drop-rail<?php echo $rail_count > 1 ? ' imc-rail-multi imc-rail-n' . min(intval($rail_count), 6) : ''; ?>">
        <?php foreach ($drop_boxes as $bx):
            $bx_id     = intval($bx['id']);
            $bx_title  = $bx['nft_name'] ?? 'NFT';
            $bx_is_oe  = ($bx['edition_type'] ?? '') === 'open' && empty($bx['open_edition_closed_at']);
            $bx_sched  = ($bx['launch_type'] ?? '') === 'scheduled' && !empty($bx['launch_at']) && strtotime($bx['launch_at']) > time();
            $bx_xrp      = floatval($bx['price_xrp'] ?? 0);
            $bx_sortable = ((($bx['pricing_mode'] ?? 'static') !== 'dynamic') && $bx_xrp > 0) ? 1 : 0;
            $bx_ends_at  = !empty($bx['open_edition_ends_at']) ? $bx['open_edition_ends_at'] : '';
            // M10: real NFTs that exist, not the allocator (see the aggregate above).
            $bx_alloc  = intval($bx['minted_count'] ?? 0);
            $bx_minted = $imc_box_realmint[$bx_id] ?? $bx_alloc;
            $bx_total  = intval($bx['total_editions'] ?? 0);
            $bx_pct    = (!$bx_is_oe && $bx_total > 0) ? round($bx_minted / $bx_total * 100) : 0;
            $bx_icons  = ['music' => '🎵', 'musicvideo' => '🎬', 'album' => '💿', 'art' => '🎨', 'film' => '🎥', 'audiobook' => '🎧', 'ebook' => '📖'];
            $bx_icon   = $bx_icons[$bx['nft_type'] ?? 'music'] ?? '🎵';
            $bx_pusd  = !empty($bx['price_usd']) ? floatval($bx['price_usd']) : null;
            $bx_pm = $bx['pricing_mode'] ?? 'fixed';
            // v665: token-agnostic primary price (token-only listings show their real token
            // price instead of a misleading 0.00 XRP), plus the "+ XRPL Token Prices" line.
            $bx_price = imc_primary_display_price($bx);
            $bx_tok_note = imc_token_prices_note($bx);
            // M10: progressive lanes (read-only) + unlockables count (already on the row).
            $bx_pp_lanes = imc_box_pp_lanes($bx, $bx_is_oe, $bx_total);
            $bx_ul_n     = intval($bx['ul_count'] ?? 0);
            // v519: per-card cover thumbnail (img proxy) shown at the top of each MINT card
            // v525: resolve cover like the modal — handles ipfs://, full URL, and raw CID
            // (raw-CID/full-URL album covers were breaking under the old unconditional thumb proxy).
            $bx_cover = '';
            if (!empty($bx['cover_ipfs'])) {
                $cv = $bx['cover_ipfs'];
                if (strpos($cv, 'ipfs://') === 0) {
                    $bx_cover = 'https://metadata.imcollectibles.io/img.php?url=' . rawurlencode($cv) . '&thumb=1';
                } elseif (strpos($cv, 'http') === 0) {
                    $bx_cover = $cv;
                } else {
                    $bx_cover = 'https://metadata.imcollectibles.io/img.php?url=' . rawurlencode('ipfs://' . $cv) . '&thumb=1';
                }
            }
            // v523: inline preview media — same public preview source the modal uses (media_ipfs).
            // M1-f3b: optional back cover (AudioBook). Public and watermarked exactly
            // like the front - holders get the unwatermarked original on the NFT page.
            $bx_back = '';
            if (!empty($bx['back_cover_ipfs'])) {
                $bv = $bx['back_cover_ipfs'];
                if (strpos($bv, 'ipfs://') === 0) {
                    $bx_back = 'https://metadata.imcollectibles.io/img.php?url=' . rawurlencode($bv) . '&thumb=1';
                } elseif (strpos($bv, 'http') === 0) {
                    $bx_back = $bv;
                } else {
                    $bx_back = 'https://metadata.imcollectibles.io/img.php?url=' . rawurlencode('ipfs://' . $bv) . '&thumb=1';
                }
            }
            // M2-d: book type (Novel / Short story / Comic) is the browse-useful eBook fact.
            $bx_book_type = '';
            if (($bx['nft_type'] ?? '') === 'ebook' && !empty($bx['ebook_format'])) {
                $bx_bt_map    = ['novel' => 'Novel', 'short' => 'Short Story', 'comic' => 'Comic', 'magazine' => 'Magazine'];
                $bx_book_type = $bx_bt_map[$bx['ebook_format']] ?? '';
            }
            $bx_type      = $bx['nft_type'] ?? 'music';
            $bx_is_video  = ($bx_type === 'musicvideo' || $bx_type === 'film');
            $bx_media     = !empty($bx['media_ipfs'])
                ? 'https://<your-pinata-gateway>/ipfs/' . preg_replace('#^ipfs://#', '', $bx['media_ipfs']) // AP: our Pinata gateway
                : '';
            // M2-d: an eBook's media_ipfs is its front-cover CID (the NOT NULL satisfier),
            // so it must be excluded here or the card would feed a cover image to <audio>.
            $bx_has_media = ($bx_media !== '' && $bx_type !== 'art' && $bx_type !== 'ebook');
            // v524: album multi-track previews (same shape as the single-NFT album player)
            $bx_tracks = [];
            if ($bx_type === 'album' && !empty($bx['album_tracks_json'])) {
                $atj = json_decode($bx['album_tracks_json'], true);
                if (is_array($atj)) {
                    foreach ($atj as $ti => $tr) {
                        $tcid = '';
                        if (!empty($tr['preview_ipfs']))          $tcid = preg_replace('#^ipfs://#', '', $tr['preview_ipfs']);
                        elseif (!empty($tr['preview']['audio']))  $tcid = preg_replace('#^ipfs://#', '', $tr['preview']['audio']);
                        if ($tcid === '') continue;
                        $bx_tracks[] = ['t' => (string)($tr['title'] ?? ('Track ' . ($ti + 1))), 'u' => 'https://<your-pinata-gateway>/ipfs/' . $tcid]; // AP: our Pinata gateway (ipfs.io stopped serving previews; ours serves audio/* CORS-permissive)
                    }
                }
            }
            $bx_is_album = ($bx_type === 'album' && count($bx_tracks) > 0);
        ?>
            <div class="mint-banner-inner imc-drop-box <?php echo $bx_sched ? 'is-scheduled' : ($bx_is_oe ? 'is-open-edition' : 'is-live'); ?>" data-listing-id="<?php echo $bx_id; ?>" data-drop-type="<?php echo $bx_is_oe ? 'oe' : 'fixed'; ?>" data-order="<?php echo $bx_order++; ?>" data-sched="<?php echo $bx_sched ? 1 : 0; ?>" data-ends="<?php echo esc_attr($bx_ends_at); ?>" data-sort-price="<?php echo $bx_sortable ? number_format($bx_xrp, 6, '.', '') : '0'; ?>" data-price-sortable="<?php echo $bx_sortable; ?>">
                <?php if ($bx_cover || $bx_is_album || $bx_has_media || $bx_ul_n > 0): ?>
                <div class="imc-box-mediacol">
                    <?php if ($bx_cover): ?>
                    <div class="imc-box-cover<?php echo $bx_back ? ' has-back' : ''; ?>"><?php if ($bx_back): ?><img class="imc-box-back" src="<?php echo esc_url($bx_back); ?>" alt="<?php echo esc_attr($bx_title); ?> back cover" loading="lazy" style="display:none;"><button type="button" class="imc-box-flip" aria-label="Show back cover">Back</button><?php endif; ?><img class="imc-box-front" src="<?php echo esc_url($bx_cover); ?>" alt="<?php echo esc_attr($bx_title); ?>" loading="lazy" onerror="this.onerror=null;this.src='/wp-content/uploads/fallback-nft.svg';"><?php if (!empty($bx['ai_generated'])): ?><span class="imc-ai-badge" data-mode="<?php echo esc_attr($bx['ai_mode'] ?? ''); ?>" data-platform="<?php echo esc_attr($bx['ai_platform'] ?? ''); ?>" aria-label="AI generated — hover for details">🤖 AI</span><?php endif; ?></div>
                    <?php endif; ?>
                    <?php if ($bx_is_album): ?>
                    <div class="imc-box-player imc-album-player" data-cur="0" data-tracks="<?php echo esc_attr(wp_json_encode($bx_tracks)); ?>">
                        <div class="imc-album-now"><span class="imc-album-tnum">1</span>. <span class="imc-album-ttitle"><?php echo esc_html($bx_tracks[0]['t']); ?></span></div>
                        <div class="imc-player">
                            <button type="button" class="imc-pp" aria-label="Play">&#9654;</button>
                            <div class="imc-progress">
                                <div class="imc-progress-fill"></div>
                                <div class="imc-progress-thumb"></div>
                            </div>
                            <span class="imc-time">0:00</span>
                            <audio class="imc-box-media imc-album-audio imc-engine" preload="none" src="<?php echo esc_url($bx_tracks[0]['u']); ?>"></audio>
                        </div>
                        <div class="imc-album-nav">
                            <button type="button" class="imc-album-prev" aria-label="Previous track">&#9198;</button>
                            <span class="imc-album-count">1 / <?php echo count($bx_tracks); ?></span>
                            <button type="button" class="imc-album-next" aria-label="Next track">&#9197;</button>
                        </div>
                    </div>
                    <?php elseif ($bx_has_media): ?>
                    <div class="imc-box-player">
                        <?php if ($bx_is_video): ?>
                        <video class="imc-box-media imc-box-video" controls poster="<?php echo esc_url($bx_cover); ?>" style="display:none" preload="none" playsinline controlsList="nodownload noplaybackrate" disablePictureInPicture oncontextmenu="return false" poster="<?php echo esc_url($bx_cover); ?>"><source src="<?php echo esc_url($bx_media); ?>" type="video/mp4"></video>
                        <div class="imc-player imc-video-bar">
                            <button type="button" class="imc-pp imc-video-play" aria-label="Play video">&#9654;</button>
                            <span class="imc-video-label">Play preview</span>
                        </div>
                        <?php else: ?>
                        <div class="imc-player">
                            <button type="button" class="imc-pp" aria-label="Play">&#9654;</button>
                            <div class="imc-progress">
                                <div class="imc-progress-fill"></div>
                                <div class="imc-progress-thumb"></div>
                            </div>
                            <span class="imc-time">0:00</span>
                            <audio class="imc-box-media imc-engine" preload="none"><source src="<?php echo esc_url($bx_media); ?>" type="audio/mpeg"></audio>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($bx_ul_n > 0): ?>
                    <div class="imc-box-ulchip">🔒 <?php echo (int)$bx_ul_n; ?> unlockable<?php echo $bx_ul_n > 1 ? 's' : ''; ?> for holders</div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="imc-box-body">
                    <div class="imc-box-head">
                        <div class="imc-box-title"><?php echo $bx_icon; ?> <?php echo esc_html($bx_title); ?><?php if ($bx_book_type): ?> <span class="imc-book-type"><?php echo esc_html($bx_book_type); ?></span><?php endif; ?></div>
                        <div class="mint-banner-status">
                            <?php if ($bx_sched): ?>
                                <span class="mint-badge mint-badge-scheduled">🚀 MINT LAUNCH</span>
                            <?php elseif ($bx_is_oe): ?>
                                <span class="mint-badge mint-badge-live">🟢 OPEN MINT</span>
                            <?php else: ?>
                                <span class="mint-badge mint-badge-live">🟢 MINT LIVE</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="mint-price-info">
                        <span class="mint-price-label"><?php echo ($bx_pm === 'pwyw') ? '' : ($bx_is_oe ? 'Starting at' : 'Price'); ?></span>
                        <span class="mint-price-value"><?php echo esc_html($bx_price); ?></span><?php echo imc_primary_price_badge($bx); ?>
                        <?php echo $bx_tok_note; ?>
                    </div>
                    <?php if (!empty($bx_pp_lanes)): ?>
                    <div class="imc-box-pp">
                        <?php foreach ($bx_pp_lanes as $ppl): ?>
                        <div class="imc-pp-lane<?php echo $ppl['capped'] ? ' is-capped' : ''; ?>">
                            <div class="imc-pp-head">
                                <span class="imc-pp-cur"><?php echo esc_html($ppl['cur']); ?></span>
                                <?php if ($ppl['capped']): ?>
                                <span class="imc-pp-note">Top price reached</span>
                                <?php else: ?>
                                <span class="imc-pp-note">+<?php echo esc_html(imc_box_pp_f($ppl['inc'], $ppl['usd'])); ?> every mint · next <b><?php echo esc_html(imc_box_pp_f($ppl['next'], $ppl['usd'])); ?></b></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($ppl['pct'] !== null): ?>
                            <div class="imc-pp-track"><div class="imc-pp-fill" style="width:<?php echo (int)$ppl['pct']; ?>%"></div></div>
                            <div class="imc-pp-ends"><span><?php echo esc_html(imc_box_pp_f($ppl['now'], $ppl['usd'])); ?> now</span><span><?php echo esc_html(imc_box_pp_f($ppl['end'], $ppl['usd'])); ?> top</span></div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <div class="imc-box-stats mint-banner-progress">
                        <div class="imc-stat">
                            <b><?php echo $bx_minted; ?><?php if (!$bx_is_oe && $bx_total > 0): ?><i>/<?php echo $bx_total; ?></i><?php endif; ?></b>
                            <span>Minted</span>
                        </div>
                        <?php $bx_coll = isset($imc_box_collected[$bx_id]) ? intval($imc_box_collected[$bx_id]) : 0; if (!empty($imc_cc_has_wallet)): ?>
                        <div class="imc-stat mint-banner-collected<?php echo $bx_coll > 0 ? '' : ' mint-banner-collected-none'; ?>">
                            <b><?php echo $bx_coll; ?></b>
                            <span>Collected</span>
                        </div>
                        <?php endif; ?>
                        <?php
                        /* Third cell: fixed supply -> REMAINING (supply is the urgency);
                           open/scheduled -> the countdown (time is the urgency). */
                        $bx_tmr = $bx_sched ? ($bx['launch_at'] ?? '')
                                : (($bx_is_oe && !empty($bx['open_edition_ends_at'])) ? $bx['open_edition_ends_at'] : '');
                        if (!$bx_is_oe && !$bx_sched && $bx_total > 0): ?>
                        <div class="imc-stat">
                            <b><?php echo intval($bx['available_editions'] ?? max(0, $bx_total - $bx_alloc)); ?></b>
                            <span>Remaining</span>
                        </div>
                        <?php elseif ($bx_tmr !== ''): ?>
                        <div class="imc-stat imc-stat-timer">
                            <div class="mint-countdown imc-box-countdown" <?php echo $bx_sched ? 'data-launch' : 'data-ends'; ?>="<?php echo esc_attr($bx_tmr); ?>">
                            <div class="mint-countdown-unit"><span data-u="d">--</span><em>D</em></div>
                            <div class="mint-countdown-sep">:</div>
                            <div class="mint-countdown-unit"><span data-u="h">--</span><em>H</em></div>
                            <div class="mint-countdown-sep">:</div>
                            <div class="mint-countdown-unit"><span data-u="m">--</span><em>M</em></div>
                            <div class="mint-countdown-sep">:</div>
                            <div class="mint-countdown-unit"><span data-u="s">--</span><em>S</em></div>
                            </div>
                            <span><?php echo $bx_sched ? 'Opens in' : 'Mint closes in'; ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!$bx_is_oe && $bx_total > 0): ?>
                    <div class="mint-progress-bar-wrapper">
                        <div class="mint-progress-bar" style="width: <?php echo $bx_pct; ?>%"></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($bx_sched): ?>
                        <div class="mint-countdown-date">
                            <?php
                            $bx_tz = !empty($bx['launch_timezone']) ? $bx['launch_timezone'] : 'UTC';
                            try {
                                $bx_dt = new DateTime($bx['launch_at'], new DateTimeZone('UTC'));
                                $bx_dt->setTimezone(new DateTimeZone($bx_tz));
                                echo esc_html($bx_dt->format('j M Y · g:i A') . ' ' . $bx_dt->format('T'));
                            } catch (Exception $e) {
                                echo esc_html(date('j M Y · g:i A T', strtotime($bx['launch_at'])));
                            }
                            ?>
                        </div>
                        <div class="mint-scheduled-btns">
                            <button class="mint-banner-btn mint-banner-btn-disabled imc-box-btn-locked" data-listing-id="<?php echo $bx_id; ?>" data-launch="<?php echo esc_attr($bx['launch_at']); ?>" disabled>🔒 Not Yet Live</button>
                        </div>
                    <?php else: ?>
                        <button class="mint-banner-btn" onclick="mintFromListing(<?php echo $bx_id; ?>)" title="Mint <?php echo esc_attr($bx_title); ?>">🛒 Mint Now</button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </section>
    <?php /* CP-C2 (R6): the DYNAMIC section closes with its own divider. This sits
       INSIDE the $rail_count > 0 gate deliberately - outside it, a sold-out
       collection (no rail) would render two dividers back to back. */ ?>
    <hr class="imc-section-divider" aria-hidden="true">
    <?php endif; ?>
    <script>
    (function(){
        var filter = document.querySelector('.imc-rail-filter');
        if (!filter) return;
        var rail = filter.parentElement.querySelector('.imc-drop-rail');
        if (!rail) return;
        var btns = filter.querySelectorAll('.imc-rail-fbtn');
        function apply(type){
            rail.querySelectorAll('.imc-drop-box').forEach(function(b){
                var show = (type === 'all') || (b.getAttribute('data-drop-type') === type);
                b.classList.toggle('imc-hide-type', !show);
            });
        }
        filter.addEventListener('click', function(e){
            var btn = e.target.closest('.imc-rail-fbtn');
            if (!btn) return;
            btns.forEach(function(x){ x.classList.remove('is-active'); x.setAttribute('aria-pressed','false'); });
            btn.classList.add('is-active'); btn.setAttribute('aria-pressed','true');
            apply(btn.getAttribute('data-filter'));
        });

        // v650: Sort dropdown (Recently Listed / Price asc-desc / Time Sensitive).
        // Reorders the rail in place; composes with the type filter (visibility only).
        // Price sorts push USD/token listings to the end; Time = live OEs soonest-first,
        // then scheduled, then fixed (the old v648 default, now opt-in).
        var sortSel = document.getElementById('imc-rail-sort-select');
        var sortNote = document.getElementById('imc-rail-sort-note');
        function rankTime(el){
            if (el.getAttribute('data-sched') === '1') return 1;
            if (el.getAttribute('data-drop-type') === 'oe') return 0;
            return 2;
        }
        function endMs(el){
            var e = el.getAttribute('data-ends');
            if (!e) return Number.MAX_SAFE_INTEGER;
            var t = new Date(String(e).replace(' ', 'T') + 'Z').getTime();
            return isNaN(t) ? Number.MAX_SAFE_INTEGER : t;
        }
        function applySort(mode){
            var boxes = Array.prototype.slice.call(rail.querySelectorAll('.imc-drop-box'));
            boxes.sort(function(a, b){
                var oa = +a.getAttribute('data-order'), ob = +b.getAttribute('data-order');
                if (mode === 'price-asc' || mode === 'price-desc'){
                    var sa = +a.getAttribute('data-price-sortable'), sb = +b.getAttribute('data-price-sortable');
                    if (sa !== sb) return sb - sa;
                    if (sa === 0) return oa - ob;
                    var pa = parseFloat(a.getAttribute('data-sort-price')) || 0,
                        pb = parseFloat(b.getAttribute('data-sort-price')) || 0;
                    if (pa !== pb) return mode === 'price-asc' ? (pa - pb) : (pb - pa);
                    return oa - ob;
                }
                if (mode === 'time'){
                    var ra = rankTime(a), rb = rankTime(b);
                    if (ra !== rb) return ra - rb;
                    if (ra === 0){ var ea = endMs(a), eb = endMs(b); if (ea !== eb) return ea - eb; }
                    return oa - ob;
                }
                return oa - ob;
            });
            boxes.forEach(function(x){ rail.appendChild(x); });
            if (sortNote) sortNote.classList.toggle('show', mode === 'price-asc' || mode === 'price-desc');
        }
        if (sortSel){
            sortSel.addEventListener('change', function(){ applySort(this.value); });
            if (sortSel.value && sortSel.value !== 'recent') applySort(sortSel.value);
        }
    })();
    </script>
    <?php /* =====================================================================
       S1 - #drop-{id} DEEP LINK from the global search dropdown.
       The dropdown links to /collections/?issuer=&taxon=#drop-{listing_id}; this
       scrolls that drop box into view and flashes it once.

       FAILS SILENT BY DESIGN. A drop box exists ONLY for status === 'active'
       (the rail above is built from that filter at :3033), and the lazy
       Open-Edition auto-close at :1612 can flip an 'active' row to sold_out
       between the search query and this page load. When the box is absent the
       page simply renders normally - no scroll, no error, no console noise.

       NOT id="drop-N" ON THE BOX, deliberately: a native anchor jump fires before
       the lazy-loaded covers have laid out and lands in the wrong place. Matching
       on the existing data-listing-id keeps the box markup untouched.

       No conflict with existing hash usage - collections.php has no other
       location.hash / hashchange consumer (the tab bar below switches on
       data-imc-tab clicks, not on the hash).
       ===================================================================== */ ?>
    <script>
    (function () {
        function target() {
            var m = /^#drop-(\d+)$/.exec(window.location.hash || '');
            if (!m) return null;
            return document.querySelector('.imc-drop-box[data-listing-id="' + m[1] + '"]');
        }
        function go() {
            var el = target();
            if (!el) return;
            var reduce = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
            try {
                el.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center' });
            } catch (e) {
                try { el.scrollIntoView(); } catch (e2) {}
            }
            el.classList.add('imc-drop-focus');
            setTimeout(function () { el.classList.remove('imc-drop-focus'); }, 2600);
        }
        // Deferred one frame + 120ms: the rail's sort pass above can reorder the
        // boxes, and the covers are loading="lazy", so an immediate scroll would
        // measure a layout that is about to change.
        function boot() {
            var raf = window.requestAnimationFrame || function (f) { return setTimeout(f, 16); };
            raf(function () { setTimeout(go, 120); });
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', boot);
        } else {
            boot();
        }
        window.addEventListener('hashchange', go);
    })();
    </script>
    <style id="imc-drop-focus-style">
    .imc-drop-rail .imc-drop-box.imc-drop-focus{
        outline:2px solid var(--imu-gold, #d6ba66) !important;
        outline-offset:4px !important;
        animation:imcDropFocusPulse 2.4s ease-out 1;
    }
    @keyframes imcDropFocusPulse{
        0%{box-shadow:0 0 0 0 rgba(214,186,102,.55);}
        70%{box-shadow:0 0 0 18px rgba(214,186,102,0);}
        100%{box-shadow:0 0 0 0 rgba(214,186,102,0);}
    }
    @media (prefers-reduced-motion: reduce){
        .imc-drop-rail .imc-drop-box.imc-drop-focus{animation:none;}
    }
    </style>
    
    <?php endif; ?>

    <?php /* =====================================================================
       CP-C2 - TAB BAR. Hash-routed (#nfts/#activity/#stats/#holders) so every tab
       is deep-linkable with NO rewrite rule and no second template - which is what
       retires the separate stats page. NFTs is live; the other three are visible
       but disabled until CP-C3 fills them (R8 default - remove `disabled` to open).
       Tab content loads on FIRST CLICK, never on page load.
       ===================================================================== */ ?>
    <?php /* CP-T (13 Sep 2026): emitted for EVERY collection, IMC-minted or
       XRPL-wide. These rules previously lived in #imc-drop-rail-style, which only
       renders inside the is_imc_collection gate - so third-party collections got
       this same markup with no styling at all. Same rules, moved verbatim
       (selectors, !important armour and the custom.css note included); the only
       change is where they are emitted from. */ ?>
    <style id="imc-collection-tabs-style">
    #nft-collection-page .imc-collection-tabs{
        display:flex;flex-wrap:wrap;gap:.25rem;align-items:flex-end;
        padding:0 2rem;margin:0 0 .25rem;
        border-bottom:1px solid rgba(212,175,55,.16);
    }
    /* `button` sits in the custom.css colour nuke (color:#fff !important), so the
       tab states need armour. Same tax documented in the master; not new debt. */
    #nft-collection-page .imc-tab{
        appearance:none;-webkit-appearance:none;background:transparent;border:0;
        border-bottom:2px solid transparent;border-radius:0;
        padding:.7rem 1.05rem;font-size:.95rem;font-weight:600;letter-spacing:.02em;
        color:rgba(255,255,255,.55) !important;cursor:pointer;
        transition:color .15s ease,border-color .15s ease;
    }
    #nft-collection-page .imc-tab:hover:not(:disabled){ color:#fff !important; }
    #nft-collection-page .imc-tab.is-active{
        color:var(--imu-gold, var(--imu-gold, #d6ba66)) !important;
        border-bottom-color:var(--imu-gold, var(--imu-gold, #d6ba66));
    }
    #nft-collection-page .imc-tab:disabled{ opacity:.38;cursor:not-allowed; }
    #nft-collection-page .imc-tab-soon{
        font-size:.62rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;
        margin-left:.4rem;opacity:.7;
    }
    #nft-collection-page .imc-tab-empty{
        padding:3.5rem 2rem;text-align:center;color:rgba(255,255,255,.5);
        font-size:.95rem;
    }
    @media (max-width: 768px){
        #nft-collection-page .imc-collection-tabs{ padding:0 1rem;justify-content:center; }
        #nft-collection-page .imc-tab{ padding:.6rem .75rem;font-size:.85rem; }
    }

    /* CP-T2: moved verbatim from #imc-drop-rail-style - panels, holders,
       activity, stats and the section divider. Internal order unchanged; this run
       sits after the tab rules and the two sets share no selector, so relative
       order between them cannot matter. */
    #nft-collection-page .imc-section-divider{
        height:1px;border:0;margin:1.75rem 0;padding:0;
        background:linear-gradient(90deg,transparent 0%,var(--imu-gold, var(--imu-gold, #d6ba66)) 50%,transparent 100%);
        opacity:.75;
    }

    /* ---------- CP-C2 TAB BAR ---------- */
    /* CP-T (13 Sep 2026): the eight .imc-tab* rules MOVED OUT of this block.
       They style the NFTs / Activity / Holders tab row - but this <style> sits
       inside the is_imc_collection gate (opens L3019, closes L3842) - the
       drop-rail block. NOTE: do not write PHP open tags inside these comments;
       PHP parses them even within a CSS comment, which is how the first attempt
       at this edit produced an unterminated if. The tab MARKUP renders
       further down, OUTSIDE that gate, for every collection. So an XRPL-wide
       collection emitted the tab row with no CSS at all and fell back to the
       default browser button look.
       They now live in #imc-collection-tabs-style, emitted unconditionally just
       above the markup they style. #nft-collection-page (L2898) is also outside
       the gate, so the scoping still resolves. Nothing else here moved. */

    /* ---------- CP-C2 PANELS ----------
       display:none is LOAD-BEARING, not cosmetic. The infinite-scroll sentinel
       (trading.js attachNftSentinel) is inserted with nftGrid.after(), i.e. as a
       SIBLING inside .marketplace-section - so it lives inside the NFTs panel.
       display:none removes its layout box and IntersectionObserver reports
       isIntersecting:false for a target with no box, which is what stops a hidden
       grid from paginating. visibility/opacity/height:0 would NOT remove the box.
       The !important guarantees it against any later rule. A second behavioural
       guard lives in the observer callback. */
    #nft-collection-page [data-imc-panel]:not(.is-active){ display:none !important; }
    /* ── CP-C3-A4f · HOLDERS ───────────────────────────────────────────────
       PALETTE: jet black grounds, a FADING GOLD border (the .imc-section-divider
       gradient turned into a border via padding-box/border-box), white/gold
       type, electric neon blue (#4da3ff) for data and hover.
       Gold = identity and chrome. Blue = measurement and interaction.
       🔴 EVERY CELL SETS text-align EXPLICITLY. trading-hub.css carries a
       text-align:center that cascades into grid cells and centred the whole
       list in v948. Do not remove these. */
    #nft-collection-page .imc-holders-statrow{
        display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:0 0 14px;
    }
    /* The fading gold border: two backgrounds, one clipped to the padding box
       (the jet ground) and one to the border box (the gradient). */
    #nft-collection-page .imc-stat-card,
    #nft-collection-page .imc-panel{
        border:1px solid transparent;border-radius:12px;
        background:
            linear-gradient(#08080a,#08080a) padding-box,
            linear-gradient(135deg,rgba(var(--imu-gold-rgb), .55) 0%,rgba(var(--imu-gold-rgb), .10) 45%,rgba(var(--imu-gold-rgb), .30) 100%) border-box;
    }
    #nft-collection-page .imc-stat-card{padding:14px 18px;display:flex;flex-direction:column;
        gap:5px;text-align:left;}
    #nft-collection-page .imc-stat-label{font-size:.68rem;letter-spacing:.11em;
        text-transform:uppercase;color:#8a7f5c;}
    #nft-collection-page .imc-stat-value{font-size:1.5rem;font-weight:700;color:#fff;
        line-height:1.15;font-variant-numeric:tabular-nums;}
    #nft-collection-page .imc-stat-value.imc-stat-blue{color:#4da3ff;}
    #nft-collection-page .imc-holders-bar{display:flex;align-items:center;
        justify-content:space-between;gap:16px;flex-wrap:wrap;margin:0 0 16px;}
    #nft-collection-page .imc-holders-actions{display:flex;gap:10px;}
    #nft-collection-page .imc-holders-toggle,
    #nft-collection-page .imc-holders-btn{
        background:#08080a;border:1px solid rgba(var(--imu-gold-rgb), .32);color:var(--imu-gold, #d6ba66);
        padding:10px 18px;border-radius:9px;cursor:pointer;font-size:.82rem;
        font-family:inherit;letter-spacing:.04em;white-space:nowrap;
        transition:color .15s ease,border-color .15s ease,box-shadow .15s ease;
    }
    #nft-collection-page .imc-holders-toggle:hover,
    #nft-collection-page .imc-holders-btn:hover{
        color:#4da3ff;border-color:rgba(77,163,255,.65);box-shadow:0 0 14px rgba(77,163,255,.18);
    }
    #nft-collection-page .imc-holders-btn.is-verified{border-color:rgba(77,163,255,.7);color:#4da3ff;}
    #nft-collection-page .imc-holders-meta strong{color:#4da3ff;font-weight:600;}
    #nft-collection-page .imc-holders-meta{font-size:.72rem;color:#5f5f5f;
        line-height:1.55;text-align:left;}
    /* ── panels ── */
    #nft-collection-page .imc-panel{padding:18px 20px;margin:0 0 18px;}
    #nft-collection-page .imc-panel-head{display:flex;align-items:baseline;
        justify-content:space-between;gap:12px;margin:0 0 14px;}
    #nft-collection-page .imc-panel-title{font-size:.72rem;letter-spacing:.11em;
        text-transform:uppercase;color:#8a7f5c;}
    #nft-collection-page .imc-panel-range{font-size:.72rem;color:#4da3ff;
        font-variant-numeric:tabular-nums;}
    /* ── the two-column list ── */
    #nft-collection-page .imc-holders-cols{display:grid;grid-template-columns:1fr 1fr;gap:28px;}
    #nft-collection-page .imc-holders-col{min-width:0;}
    #nft-collection-page .imc-holders-col[hidden]{display:none;}
    #nft-collection-page .imc-holders-thead,
    #nft-collection-page .imc-holder-row{
        display:grid;grid-template-columns:2.4rem minmax(0,1fr) 3.6rem 4rem;
        align-items:center;gap:10px;
    }
    #nft-collection-page .imc-holders-thead{
        padding:0 8px 9px;font-size:.66rem;letter-spacing:.11em;text-transform:uppercase;
        color:#8a7f5c;border-bottom:1px solid transparent;
        border-image:linear-gradient(90deg,rgba(var(--imu-gold-rgb), .45),rgba(var(--imu-gold-rgb), .06)) 1;
    }
    #nft-collection-page .imc-holder-row{padding:10px 8px;
        border-bottom:1px solid rgba(255,255,255,.04);transition:background .12s ease;}
    #nft-collection-page .imc-holder-row:hover{background:rgba(77,163,255,.05);}
    #nft-collection-page .imc-holder-rank{color:#55555a;font-size:.78rem;text-align:right;
        font-variant-numeric:tabular-nums;}
    #nft-collection-page .imc-holder-name{overflow:hidden;text-overflow:ellipsis;
        white-space:nowrap;text-align:left;}
    #nft-collection-page .imc-holder-name a,
    #nft-collection-page .imc-holder-name a:link,
    #nft-collection-page .imc-holder-name a:visited{color:#e4e4e4;text-decoration:none;
        font-size:.87rem;transition:color .12s ease;}
    #nft-collection-page .imc-holder-name a:hover{color:#4da3ff;text-decoration:none;}
    #nft-collection-page .imc-holder-name a.imc-holder-named{color:var(--imu-gold, #d6ba66);font-weight:600;}
    #nft-collection-page .imc-holder-name a.imc-holder-named:hover{color:#4da3ff;}
    #nft-collection-page .imc-holder-addr{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
        font-size:.8rem;letter-spacing:-.01em;}
    #nft-collection-page .imc-holder-held{color:#fff;text-align:right;
        font-variant-numeric:tabular-nums;font-size:.88rem;}
    #nft-collection-page .imc-holder-pct{color:#4da3ff;text-align:right;font-size:.8rem;
        font-variant-numeric:tabular-nums;}
    #nft-collection-page .imc-holders-foot{margin:18px 0 0;padding-top:14px;text-align:center;
        font-size:.78rem;color:#7a7a7a;border-top:1px solid rgba(255,255,255,.05);}
    #nft-collection-page .imc-holders-foot strong{color:var(--imu-gold, #d6ba66);font-weight:600;}
    /* ── distribution ── */
    #nft-collection-page .imc-dist-row{display:grid;
        grid-template-columns:6.5rem minmax(0,1fr) 5rem;align-items:center;gap:14px;margin:0 0 12px;}
    #nft-collection-page .imc-dist-row:last-child{margin-bottom:0;}
    #nft-collection-page .imc-dist-label{color:#b4b4b4;font-size:.82rem;text-align:left;}
    #nft-collection-page .imc-dist-track{display:block;height:9px;border-radius:5px;
        background:rgba(255,255,255,.05);overflow:hidden;}
    /* 🔴 display:block IS LOAD-BEARING. The fill is a <span> nested inside the
       track, so it is NOT a grid item and is therefore NOT blockified - as an
       inline box it ignored width AND height, and every bar rendered as an empty
       grey rail from A-4c through v951. The numbers were always right; only the
       fill was missing. */
    #nft-collection-page .imc-dist-fill{display:block;height:100%;border-radius:5px;
        background:linear-gradient(90deg,#1e6fc4 0%,#4da3ff 100%);
        box-shadow:0 0 10px rgba(77,163,255,.35);
        transition:width .45s cubic-bezier(.22,.61,.36,1);}
    #nft-collection-page .imc-dist-val{color:#fff;font-size:.82rem;text-align:right;
        font-variant-numeric:tabular-nums;}
    #nft-collection-page .imc-dist-val span{color:#5f5f5f;font-size:.74rem;}
    #nft-collection-page .imc-holders-chartwrap{position:relative;height:300px;margin:4px 0 6px;}
    #nft-collection-page .imc-holders-chartwrap[hidden]{display:none;}
    #nft-collection-page .imc-holders-chartnote{font-size:.76rem;color:#5f5f5f;
        text-align:center;padding:8px 0 2px;line-height:1.6;}
    @media (max-width:1024px){
        #nft-collection-page .imc-holders-cols{grid-template-columns:1fr;gap:0;}
        #nft-collection-page .imc-holders-col + .imc-holders-col{margin-top:6px;}
        #nft-collection-page .imc-holders-col + .imc-holders-col .imc-holders-thead{display:none;}
    }
    @media (max-width:768px){
        #nft-collection-page .imc-holders-statrow{grid-template-columns:repeat(2,1fr);gap:10px;}
        #nft-collection-page .imc-stat-value{font-size:1.25rem;}
        #nft-collection-page .imc-holders-bar{flex-direction:column;align-items:stretch;}
        #nft-collection-page .imc-holders-actions .imc-holders-toggle,
        #nft-collection-page .imc-holders-actions .imc-holders-btn{flex:1;}
        #nft-collection-page .imc-holders-thead,
        #nft-collection-page .imc-holder-row{grid-template-columns:1.9rem minmax(0,1fr) 3.2rem;gap:8px;}
        #nft-collection-page .imc-holder-pct{display:none;}
        #nft-collection-page .imc-dist-row{grid-template-columns:5rem minmax(0,1fr) 3.8rem;gap:10px;}
        #nft-collection-page .imc-panel{padding:14px;}
        #nft-collection-page .imc-holders-chartwrap{height:240px;}
    }
    /* ── CP-C3-B3 · ACTIVITY ───────────────────────────────────────────────
       Reuses the Holders components wholesale: .imc-panel (fading gold border
       on jet), .imc-panel-head/-title/-range, .imc-holders-btn, .imc-holders-
       foot. Only the row grid is new.
       🔴 EVERY CELL SETS text-align EXPLICITLY - trading-hub.css carries a
       text-align:center that cascades into grid cells (it centred the whole
       holder list in v948). Do not remove these.
       ⚠ `a` is in the colour nuke - anchors re-declare colour AND
       text-decoration. */
    #nft-collection-page .imc-act-thead,
    #nft-collection-page .imc-act-row{
        display:grid;
        grid-template-columns:minmax(0,2.4fr) 5.5rem 4.6rem minmax(0,1fr) minmax(0,1fr) 5.5rem 4.5rem;
        align-items:center;gap:12px;
    }
    #nft-collection-page .imc-act-thead{
        padding:0 10px 10px;font-size:.66rem;letter-spacing:.11em;text-transform:uppercase;
        color:#8a7f5c;border-bottom:1px solid transparent;
        border-image:linear-gradient(90deg,rgba(var(--imu-gold-rgb), .45),rgba(var(--imu-gold-rgb), .06)) 1;
    }
    #nft-collection-page .imc-act-row{
        padding:10px;border-bottom:1px solid rgba(255,255,255,.04);
        transition:background .12s ease;
    }
    #nft-collection-page .imc-act-row:hover{background:rgba(77,163,255,.05);}
    #nft-collection-page .imc-act-item{
        display:flex;align-items:center;gap:10px;min-width:0;text-align:left;
    }
    #nft-collection-page .imc-act-thumb{
        width:34px;height:34px;border-radius:6px;object-fit:cover;flex:0 0 34px;
        background:rgba(255,255,255,.04);
    }
    #nft-collection-page .imc-act-name{
        overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.85rem;color:#e4e4e4;
    }
    #nft-collection-page .imc-act-price{color:#fff;text-align:right;font-size:.88rem;
        font-variant-numeric:tabular-nums;}
    #nft-collection-page .imc-act-price small{color:#8a7f5c;font-size:.72rem;margin-left:3px;}
    #nft-collection-page .imc-act-price .imc-act-nil{color:#55555a;}
    /* CP-C3-D: a genuinely free mint. Gold, because FREE is an IMC fact about
       the drop, not a market measurement - blue is reserved for prices. */
    #nft-collection-page .imc-act-price .imc-act-free{
        color:var(--imu-gold, #d6ba66);font-size:.74rem;letter-spacing:.08em;font-weight:600;
    }
    #nft-collection-page .imc-act-event{text-align:left;}
    #nft-collection-page .imc-act-badge{
        display:inline-block;padding:3px 9px;border-radius:5px;font-size:.68rem;
        letter-spacing:.07em;text-transform:uppercase;border:1px solid transparent;
    }
    #nft-collection-page .imc-act-badge.is-sale{
        color:#4da3ff;border-color:rgba(77,163,255,.4);background:rgba(77,163,255,.08);}
    #nft-collection-page .imc-act-badge.is-mint{
        color:var(--imu-gold, #d6ba66);border-color:rgba(var(--imu-gold-rgb), .4);background:rgba(var(--imu-gold-rgb), .07);}
    #nft-collection-page .imc-act-party{overflow:hidden;text-overflow:ellipsis;
        white-space:nowrap;text-align:left;font-size:.82rem;}
    #nft-collection-page .imc-act-party a,
    #nft-collection-page .imc-act-party a:link,
    #nft-collection-page .imc-act-party a:visited{
        color:#b4b4b4;text-decoration:none;transition:color .12s ease;}
    #nft-collection-page .imc-act-party a:hover{color:#4da3ff;text-decoration:none;}
    #nft-collection-page .imc-act-party a.imc-act-named{color:var(--imu-gold, #d6ba66);}
    #nft-collection-page .imc-act-party a.imc-act-named:hover{color:#4da3ff;}
    #nft-collection-page .imc-act-addr{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
        font-size:.78rem;}
    #nft-collection-page .imc-act-time{color:#7a7a7a;text-align:right;font-size:.78rem;}
    #nft-collection-page .imc-act-exp{text-align:right;}
    #nft-collection-page .imc-act-exp a,
    #nft-collection-page .imc-act-exp a:link,
    #nft-collection-page .imc-act-exp a:visited{
        color:#8a7f5c;text-decoration:none;font-size:.76rem;letter-spacing:.04em;
        transition:color .12s ease;}
    #nft-collection-page .imc-act-exp a:hover{color:#4da3ff;text-decoration:none;}
    @media (max-width:1024px){
        /* Explorer and From go first - the tx link is a power-user affordance
           and the seller matters less than who now holds it. */
        #nft-collection-page .imc-act-thead,
        #nft-collection-page .imc-act-row{
            grid-template-columns:minmax(0,2fr) 5rem 4.4rem minmax(0,1fr) 5rem;gap:10px;}
        #nft-collection-page .imc-act-exp,
        #nft-collection-page .imc-act-thead .imc-act-party:first-of-type,
        #nft-collection-page .imc-act-row .imc-act-party:first-of-type{display:none;}
    }
    @media (max-width:768px){
        #nft-collection-page .imc-act-thead,
        #nft-collection-page .imc-act-row{
            grid-template-columns:minmax(0,1.6fr) 4.6rem 4.2rem 4.4rem;gap:8px;padding-left:4px;padding-right:4px;}
        #nft-collection-page .imc-act-thead .imc-act-party,
        #nft-collection-page .imc-act-row .imc-act-party{display:none;}
        #nft-collection-page .imc-act-thumb{width:28px;height:28px;flex:0 0 28px;}
    }
    /* ── CP-C3-D · ACTIVITY ANALYTICS ──────────────────────────────────────
       Reuses .imc-panel-head/-title/-range, .imc-holders-btn and
       .imc-holders-chartnote wholesale. Only the stat strip, the sub-panel and
       the chart wrapper are new. */
    #nft-collection-page .imc-act-headright{display:flex;align-items:center;gap:14px;}
    #nft-collection-page .imc-act-toggle{padding:7px 14px;font-size:.76rem;}
    #nft-collection-page .imc-act-stats{
        display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:0 0 18px;
    }
    #nft-collection-page .imc-act-stat{
        border:1px solid transparent;border-radius:10px;padding:12px 16px;
        display:flex;flex-direction:column;gap:4px;text-align:left;
        background:
            linear-gradient(#08080a,#08080a) padding-box,
            linear-gradient(135deg,rgba(var(--imu-gold-rgb), .42) 0%,rgba(var(--imu-gold-rgb), .08) 55%,rgba(var(--imu-gold-rgb), .24) 100%) border-box;
    }
    #nft-collection-page .imc-act-stat-label{font-size:.64rem;letter-spacing:.11em;
        text-transform:uppercase;color:#8a7f5c;}
    #nft-collection-page .imc-act-stat-value{font-size:1.15rem;font-weight:700;color:#fff;
        font-variant-numeric:tabular-nums;line-height:1.2;}
    #nft-collection-page .imc-act-stat-value .imc-act-stat-sub{
        color:#4da3ff;font-size:.8rem;font-weight:600;margin-left:6px;}
    #nft-collection-page .imc-act-sub{
        border-top:1px solid rgba(255,255,255,.05);padding:18px 0 4px;margin:0 0 4px;
    }
    #nft-collection-page .imc-act-controls{display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
    #nft-collection-page .imc-act-windows{display:inline-flex;gap:2px;}
    #nft-collection-page .imc-act-win{
        background:transparent;border:1px solid rgba(var(--imu-gold-rgb), .22);color:#8a7f5c;
        padding:4px 9px;border-radius:6px;cursor:pointer;font-size:.68rem;
        font-family:inherit;letter-spacing:.06em;
        transition:color .12s ease,border-color .12s ease,background .12s ease;
    }
    #nft-collection-page .imc-act-win:hover{color:#4da3ff;border-color:rgba(77,163,255,.5);}
    #nft-collection-page .imc-act-win.is-on{
        color:#0a0a0a;background:var(--imu-gold, #d6ba66);border-color:var(--imu-gold, #d6ba66);font-weight:600;
    }
    /* ⚠ A window with too little data is dimmed, NOT hidden - the user learns
       the series is young rather than wondering where the option went. */
    #nft-collection-page .imc-act-win.is-thin{opacity:.35;}
    #nft-collection-page .imc-act-cur{
        background:#08080a;border:1px solid rgba(var(--imu-gold-rgb), .3);color:var(--imu-gold, #d6ba66);
        padding:4px 8px;border-radius:6px;font-size:.7rem;font-family:inherit;cursor:pointer;
    }
    #nft-collection-page .imc-act-cur[hidden]{display:none;}
    #nft-collection-page .imc-act-chartwrap{position:relative;height:280px;margin:4px 0 6px;}
    #nft-collection-page .imc-act-chartwrap[hidden]{display:none;}
    @media (max-width:768px){
        #nft-collection-page .imc-act-stats{grid-template-columns:1fr;gap:8px;}
        #nft-collection-page .imc-act-chartwrap{height:220px;}
        #nft-collection-page .imc-act-headright{width:100%;justify-content:space-between;}
    }
    /* CP-T: .imc-tab-empty moved with the rest - see #imc-collection-tabs-style. */
    @media (max-width: 768px){
        /* CP-T: the two mobile tab rules moved with the rest. */
        #nft-collection-page .imc-section-divider{ margin:1.25rem 0; }
    }
    </style>

    <div class="imc-collection-tabs" role="tablist" aria-label="Collection views">
        <button type="button" class="imc-tab is-active" data-imc-tab="nfts"
                role="tab" aria-selected="true">NFTs</button>
        <button type="button" class="imc-tab" data-imc-tab="activity"
                role="tab" aria-selected="false">Activity</button>
        <?php /* CP-C3 / D54: NO STATS TAB. Everything it would have shown is
           already one click away - top holders, supply, volume, top-10 share,
           recent sales. A summary of visible things is duplication, and four
           tabs where one is thin is worse than three that each earn their
           place. The charts that WOULD have justified it fold into the tab
           that owns the data instead. */ ?>
        <button type="button" class="imc-tab" data-imc-tab="holders"
                role="tab" aria-selected="false">Holders</button>
    </div>

    <?php /* The sort/filter toolbar belongs to the NFTs view - it is a panel member
       so it hides with the grid rather than sitting over Holders or Stats. */ ?>
    <!-- Controls -->
    <div class="marketplace-nav is-active" data-imc-panel="nfts">
        <div class="nav-buttons">
            <a href="<?php echo esc_url(home_url('/collections/')); ?>" class="nav-btn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                All Collections
            </a>
            <button id="my-hub-btn" class="nav-btn">My Hub</button>
        </div>
        
        <div class="filter-controls">
            <select id="nft-sort" class="filter-select">
                <option value="listed">Listed First</option>
                <option value="__mine__">&#11088; My NFTs</option>
                <option value="recent">Recently Added</option>
                <option value="price-asc">Price: Low to High</option>
                <option value="price-desc">Price: High to Low</option>
                <option value="random">Random</option>
            </select>
            <?php
            // v504 Phase A2: Title filter — rendered ONLY for IMC-minted collections
            // holding 2+ distinct artwork titles. Options come straight from the
            // collection's own listings (zero extra queries). External collections
            // and single-title collections never see this control.
            if (!empty($is_imc) && !empty($mint_status['listings'])) {
                $a2_titles = [];
                foreach ($mint_status['listings'] as $a2_lst) {
                    $a2_n = trim((string)($a2_lst['nft_name'] ?? ''));
                    if ($a2_n !== '') $a2_titles[$a2_n] = true;
                }
                $a2_titles = array_keys($a2_titles);
                if (count($a2_titles) >= 2) {
                    sort($a2_titles, SORT_NATURAL | SORT_FLAG_CASE);
                    echo '<select id="nft-title-filter" class="filter-select">';
                    echo '<option value="all">All NFTs</option>';
                    foreach ($a2_titles as $a2_tt) {
                        echo '<option value="' . esc_attr($a2_tt) . '">' . esc_html($a2_tt) . '</option>';
                    }
                    echo '</select>';
                }
            }
            ?>
        </div>
    </div>

    <?php /* The sentinel that drives infinite scroll is inserted by trading.js with
       nftGrid.after(), i.e. as a SIBLING inside this section. Making this section the
       NFTs panel therefore puts the sentinel inside the hidden subtree when another
       tab is active - display:none removes its layout box, so IntersectionObserver
       cannot fire and a background grid can never paginate. */ ?>
    <!-- NFT Grid -->
    <section class="marketplace-section is-active" data-imc-panel="nfts" id="imc-panel-nfts">
        <div id="global-nft-grid" class="nft-grid">
            <div class="nft-grid-loading active skeleton-container" id="nft-loading-indicator">
                <?php for ($i = 0; $i < 10; $i++): ?>
                <div class="nft-card skeleton-card">
                    <div class="nft-image-wrapper skeleton-shimmer"></div>
                    <div class="nft-card-info">
                        <div class="skeleton-text skeleton-shimmer" style="width:<?php echo rand(50,80); ?>%"></div>
                    </div>
                </div>
                <?php endfor; ?>
            </div>
        </div>
        
        <!-- Pagination hidden - using infinite scroll -->
        <div class="pagination-controls" style="display: none;">
            <button id="prev-page" disabled>← Previous</button>
            <span id="page-info">Page 1</span>
            <button id="next-page">Next →</button>
        </div>
    </section>

    <?php /* CP-C2: sibling panels for CP-C3. They are inert containers - no fetch,
       no markup beyond the placeholder - so they cost nothing until filled. Each
       will be populated on FIRST CLICK by its own loader in CP-C3. */ ?>
    <?php /* CP-C3-B3: ACTIVITY.
       Shows secondary-market SALES and IMC MINTS only. Transfers are excluded
       by ruling: a zero-amount move is indistinguishable from another
       marketplace delivering its own mint, so labelling one a "transfer" would
       be a claim we cannot support. Store-wide they are the clear majority of
       rows - an unfiltered feed reads as an airdrop firehose.
       The footer says what is hidden and why. */ ?>
    <div class="marketplace-section" data-imc-panel="activity" id="imc-panel-activity" role="tabpanel">
        <div class="imc-panel">
            <div class="imc-panel-head">
                <span class="imc-panel-title">Recent activity</span>
                <span class="imc-act-headright">
                    <span class="imc-panel-range" id="imc-activity-count"></span>
                    <button type="button" class="imc-holders-btn imc-act-toggle"
                            id="imc-activity-toggle" aria-pressed="false">Change View</button>
                </span>
            </div>
            <div id="imc-activity-listview">
            <div class="imc-act-thead">
                <span class="imc-act-item">Item</span>
                <span class="imc-act-price">Price</span>
                <span class="imc-act-event">Event</span>
                <span class="imc-act-party">From</span>
                <span class="imc-act-party">To</span>
                <span class="imc-act-time">Time</span>
                <span class="imc-act-exp">Explorer</span>
            </div>
            <div id="imc-activity-rows">
                <div class="imc-tab-empty">Loading activity&hellip;</div>
            </div>
            <div class="imc-holders-more">
                <button type="button" class="imc-holders-btn" id="imc-activity-more" hidden>Load more</button>
            </div>
            <div class="imc-holders-foot" id="imc-activity-foot"></div>
            </div>
            <?php /* CP-C3-D ANALYTICS. Same principle as the Holders chart:
               ship it now and let it grow. floor and listing history CANNOT be
               backfilled - nft_offers records when an offer began and never
               when it stopped being active - so the series accrues only from
               the day the snapshot starts writing it. A chart that begins with
               one point is honest; a chart deferred until it looks good is a
               week of history thrown away. */ ?>
            <div id="imc-activity-analytics" hidden>
                <div class="imc-act-stats" id="imc-act-stats"></div>
                <?php /* CP-C3-G: THREE charts, each with its own window selector.
                   ★ WINDOW, not grain. A scatter has no grain - every dot is one
                   sale. Floor and listings are one reading per day by
                   construction, so "daily" is already their only grain; weekly
                   would mean averaging daily floors, a different statistic.
                   ⚠ Every window is offered even when the series is too young to
                   fill it, and the range label states when the data starts - so a
                   short chart reads as "young", not "broken". */ ?>
                <div class="imc-act-sub">
                    <div class="imc-panel-head">
                        <span class="imc-panel-title">Sale prices</span>
                        <span class="imc-act-controls">
                            <select class="imc-act-cur" id="imc-act-cursel" hidden></select>
                            <span class="imc-act-windows" data-chart="scatter">
                                <button type="button" class="imc-act-win" data-days="1">24H</button>
                                <button type="button" class="imc-act-win" data-days="7">7D</button>
                                <button type="button" class="imc-act-win" data-days="30">30D</button>
                                <button type="button" class="imc-act-win is-on" data-days="90">90D</button>
                                <button type="button" class="imc-act-win" data-days="365">1Y</button>
                                <button type="button" class="imc-act-win" data-days="3650">ALL</button>
                            </span>
                        </span>
                    </div>
                    <div class="imc-act-chartwrap"><canvas id="imc-act-scatter"></canvas></div>
                    <div class="imc-holders-chartnote" id="imc-act-scatternote"></div>
                </div>
                <div class="imc-act-sub">
                    <div class="imc-panel-head">
                        <span class="imc-panel-title">Floor price</span>
                        <span class="imc-act-controls">
                            <span class="imc-panel-range" id="imc-act-floorrange"></span>
                            <span class="imc-act-windows" data-chart="floor">
                                <button type="button" class="imc-act-win" data-days="7">7D</button>
                                <button type="button" class="imc-act-win" data-days="30">30D</button>
                                <button type="button" class="imc-act-win" data-days="90">90D</button>
                                <button type="button" class="imc-act-win is-on" data-days="3650">ALL</button>
                            </span>
                        </span>
                    </div>
                    <div class="imc-act-chartwrap"><canvas id="imc-act-floorchart"></canvas></div>
                    <div class="imc-holders-chartnote" id="imc-act-floornote"></div>
                </div>
                <div class="imc-act-sub">
                    <div class="imc-panel-head">
                        <span class="imc-panel-title">Listings over time</span>
                        <span class="imc-act-controls">
                            <span class="imc-panel-range" id="imc-act-listrange"></span>
                            <span class="imc-act-windows" data-chart="listings">
                                <button type="button" class="imc-act-win" data-days="7">7D</button>
                                <button type="button" class="imc-act-win" data-days="30">30D</button>
                                <button type="button" class="imc-act-win" data-days="90">90D</button>
                                <button type="button" class="imc-act-win is-on" data-days="3650">ALL</button>
                            </span>
                        </span>
                    </div>
                    <div class="imc-act-chartwrap"><canvas id="imc-act-listchart"></canvas></div>
                    <div class="imc-holders-chartnote" id="imc-act-listnote"></div>
                </div>
            </div>
        </div>
    </div>
    <?php /* CP-C3-A4 (08 Sep 2026) - HOLDERS.
       Data comes from the VPS (?action=holders); display NAMES come from
       WordPress (imc_holder_names), because the VPS cannot reach the WP
       database. Two-stage on purpose - addresses render immediately and names
       swap in when they arrive, so a slow or failed name lookup never blocks
       the list.
       The minter wallet appears as a normal holder by ruling: it is a true
       stat, and a profile set on that wallet explains itself. NO exception
       logic anywhere - do not add any. */ ?>
    <div class="marketplace-section" data-imc-panel="holders" id="imc-panel-holders" role="tabpanel">
        <?php /* A4f: STATS ACROSS THE TOP, then a two-column list capped at 100.
           The 270px left rail cost the list width it needed and read as a
           vertical list rather than a summary; a horizontal row matches how the
           hero already presents Owners/Floor/Volume/Assets.
           The list shows 1-50 left and 51-100 right and STOPS - no pagination
           state at all. Everything beyond 100 is the CSV, and the footer says so
           rather than letting the list simply end. */ ?>
        <div class="imc-holders-statrow">
            <div class="imc-stat-card">
                <span class="imc-stat-label">Holders</span>
                <span class="imc-stat-value" id="imc-stat-holders">&mdash;</span>
            </div>
            <div class="imc-stat-card">
                <span class="imc-stat-label">NFTs</span>
                <span class="imc-stat-value" id="imc-stat-nfts">&mdash;</span>
            </div>
            <div class="imc-stat-card">
                <span class="imc-stat-label">Top 10 hold</span>
                <span class="imc-stat-value imc-stat-blue" id="imc-stat-top10">&mdash;</span>
            </div>
            <div class="imc-stat-card">
                <span class="imc-stat-label">Hold just one</span>
                <span class="imc-stat-value" id="imc-stat-single">&mdash;</span>
            </div>
        </div>
        <div class="imc-holders-bar">
            <div class="imc-holders-meta" id="imc-holders-meta"></div>
            <div class="imc-holders-actions">
                <button type="button" class="imc-holders-toggle" id="imc-holders-toggle"
                        aria-pressed="false">Change View</button>
                <button type="button" class="imc-holders-btn" id="imc-holders-verify"
                        title="Walk the XRP Ledger now for an authoritative count">Verify live</button>
                <button type="button" class="imc-holders-btn" id="imc-holders-csv">Download CSV</button>
            </div>
        </div>
        <div id="imc-holders-listview">
            <div class="imc-panel">
                <div class="imc-holders-cols">
                    <div class="imc-holders-col">
                        <div class="imc-holders-thead">
                            <span class="imc-holder-rank">#</span>
                            <span class="imc-holder-name">Holder</span>
                            <span class="imc-holder-held">Held</span>
                            <span class="imc-holder-pct">Share</span>
                        </div>
                        <div id="imc-holders-rows-a">
                            <div class="imc-tab-empty">Loading holders&hellip;</div>
                        </div>
                    </div>
                    <div class="imc-holders-col" id="imc-holders-colb" hidden>
                        <div class="imc-holders-thead">
                            <span class="imc-holder-rank">#</span>
                            <span class="imc-holder-name">Holder</span>
                            <span class="imc-holder-held">Held</span>
                            <span class="imc-holder-pct">Share</span>
                        </div>
                        <div id="imc-holders-rows-b"></div>
                    </div>
                </div>
                <div class="imc-holders-foot" id="imc-holders-foot"></div>
            </div>
        </div>
        <div id="imc-holders-chartview" hidden>
            <div class="imc-panel">
                <?php /* CP-C3-H: holders AND supply on one axis. Both are counts,
                   so they share a scale honestly - and the comparison is the whole
                   question this tab exists to answer. Holders rising while supply
                   is flat means ownership is SPREADING; both rising together is
                   just minting. One line alone cannot tell you which. */ ?>
                <div class="imc-panel-head">
                    <span class="imc-panel-title">Holders over time</span>
                    <span class="imc-act-controls">
                        <span class="imc-panel-range" id="imc-holders-range"></span>
                        <span class="imc-act-windows" data-hchart="growth">
                            <button type="button" class="imc-act-win" data-days="7">7D</button>
                            <button type="button" class="imc-act-win" data-days="30">30D</button>
                            <button type="button" class="imc-act-win" data-days="90">90D</button>
                            <button type="button" class="imc-act-win is-on" data-days="3650">ALL</button>
                        </span>
                    </span>
                </div>
                <div class="imc-holders-chartwrap" id="imc-holders-chartwrap">
                    <canvas id="imc-holders-chart"></canvas>
                </div>
                <div class="imc-holders-chartnote" id="imc-holders-chartnote"></div>
            </div>
            <?php /* CP-C3-H: CONCENTRATION. top10_share_pct has been in every
               holders_history response since A-4 and never drawn. It is the single
               best measure of whether whales are accumulating.
               ⚠ Its OWN chart, not a second line above: 8.2% and 481 cannot share
               a y-axis, and a dual axis invites exactly the misreading the
               average-price line caused on Activity. */ ?>
            <div class="imc-panel">
                <div class="imc-panel-head">
                    <span class="imc-panel-title">Top 10 concentration</span>
                    <span class="imc-act-controls">
                        <span class="imc-panel-range" id="imc-conc-range"></span>
                        <span class="imc-act-windows" data-hchart="conc">
                            <button type="button" class="imc-act-win" data-days="7">7D</button>
                            <button type="button" class="imc-act-win" data-days="30">30D</button>
                            <button type="button" class="imc-act-win" data-days="90">90D</button>
                            <button type="button" class="imc-act-win is-on" data-days="3650">ALL</button>
                        </span>
                    </span>
                </div>
                <div class="imc-holders-chartwrap" id="imc-conc-chartwrap">
                    <canvas id="imc-conc-chart"></canvas>
                </div>
                <div class="imc-holders-chartnote" id="imc-conc-note"></div>
            </div>
            <div class="imc-panel">
                <div class="imc-panel-head"><span class="imc-panel-title">Owner distribution</span></div>
                <div id="imc-holders-dist"></div>
            </div>
        </div>    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div id="submission-loading-overlay" class="loading-overlay" style="display: none;">
        <div class="loading-spinner"></div>
        <span>Processing...</span>
    </div>

    <!-- NFT Details Popup -->
    <div id="nft-popup" class="nft-popup" style="display: none;" role="dialog">
        <button class="nft-popup-close">×</button>
        <div class="nft-popup-content">
            <div style="padding: 1rem;">
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
                        <input type="number" id="offer-amount" step="0.000001" min="0.000001" required 
                               style="flex: 1; padding: 0.75rem; background: var(--input-bg); border: 1px solid var(--card-border); border-radius: 6px; color: var(--text-primary); font-size: 1rem;">
                        <select id="offer-currency" style="padding: 0.75rem; background: #1a1a2e; border: 1px solid var(--card-border); border-radius: 6px; color: var(--text-primary);">
                            <option value="XRP">💧 XRP</option>
                            <!-- v86: Tokens loaded dynamically -->
                        </select>
                    </div>
                    <div id="offer-trustline-warning" style="display: none; margin-top: 0.5rem; padding: 0.5rem; background: rgba(255,193,7,0.1); border: 1px solid rgba(255,193,7,0.3); border-radius: 6px; font-size: 0.85rem; color: #ffc107;">
                        ⚠️ <span id="trustline-warning-text">You need a trustline for this token</span>
                        <a href="#" id="set-trustline-link" target="_blank" style="display: block; margin-top: 0.5rem; color: var(--gold);">Set Trustline →</a>
                    </div>
                </div>
                
                <div style="background: var(--imu-dark); border-radius: 8px; padding: 1rem; margin-bottom: 1rem; font-size: 0.9rem;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.25rem;">
                        <span style="color: var(--text-muted);">Net Amount</span>
                        <span id="breakdown-net" style="color: var(--text-primary);">-</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.25rem;">
                        <span style="color: var(--text-muted);">Platform Fee (1.5%)</span>
                        <span id="breakdown-fee" style="color: var(--text-primary);">-</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.25rem;">
                        <span style="color: var(--text-muted);">Royalty</span>
                        <span id="breakdown-royalty" style="color: var(--text-primary);">-</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid var(--card-border);">
                        <span style="color: var(--imu-gold); font-weight: 600;">Total</span>
                        <span id="breakdown-total" style="color: var(--imu-gold); font-weight: 600;">-</span>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;">Submit Offer</button>
            </form>
        </div>
    </div>

    <!-- QR Code Popup -->
    <div id="qr-code-popup" class="nft-popup" style="display: none;" role="dialog">
        <div class="nft-popup-content" style="text-align: center; padding: 2rem;">
            <button class="qr-popup-close nft-popup-close">×</button>
            <h3 style="margin: 0 0 0.5rem;">Sign Transaction</h3>
            <p style="color: var(--text-muted); margin: 0 0 1.5rem;">Scan with Xaman app</p>
            <img id="qr-code-image" src="" alt="QR Code" style="max-width: 220px; border-radius: 8px;">
            <a id="qr-deeplink" href="#" target="_blank" class="btn btn-primary" style="display: block; margin-top: 1.5rem;">Open in Xaman</a>
            <div id="signing-status" style="margin-top: 1rem; color: var(--text-muted);">Waiting for signature...</div>
        </div>
    </div>

    <!-- Compare Popup -->
    <div id="compare-popup" class="nft-popup" style="display: none;" role="dialog">
        <button class="compare-popup-close nft-popup-close">×</button>
        <div class="nft-popup-content" style="max-width: 700px;">
            <h3 style="padding: 1.5rem; margin: 0; border-bottom: 1px solid var(--card-border);">Compare NFTs</h3>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; padding: 1.5rem;">
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

<script>
// v360: Client-side Bithomp stats fetch
// IMC collections: only fetch floor (all other stats come from our DB)
// External collections: fetch any missing stat
(function() {
    const issuer = '<?php echo esc_js($issuer); ?>';
    const taxon = <?php echo intval($taxon); ?>;
    const isIMC = <?php echo $is_imc ? 'true' : 'false'; ?>;
    
    const itemsEl = document.getElementById('collection-item-count');
    const ownersEl = document.getElementById('collection-owner-count');
    const floorEl = document.getElementById('collection-floor');
    
    // IMC: only need floor from Bithomp. External: any missing stat.
    const needsUpdate = isIMC
        ? (floorEl?.textContent === '-')
        : (itemsEl?.textContent === '-') || (ownersEl?.textContent === '-') ||
          (floorEl?.textContent === '-');   // CP-B: #collection-sales retired; #collection-item-count now carries the 4th box
    
    if (needsUpdate) {
        const bithompUrl = (window.xrplMarketplace?.endpoints?.bithompHandler || 
            '/wp-content/themes/astra/xrpl-nft-marketplace/backend/bithomp-handler.php');
        
        fetch(`${bithompUrl}?action=collection&issuer=${encodeURIComponent(issuer)}&taxon=${taxon}&nonce=<?php echo esc_js($nonce); ?>`)
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(data => {
            if (!data.success || !data.collection) return;
            const col = data.collection;
            
            // Floor: always update if missing (both IMC and external)
            if (floorEl?.textContent === '-' && col.floor_xrp > 0) {
                floorEl.textContent = parseFloat(col.floor_xrp).toFixed(2) + ' XRP';
            }
            
            // External only: fill remaining gaps
            if (!isIMC) {
                if (itemsEl?.textContent === '-' && col.nfts) {
                    itemsEl.textContent = col.nfts.toLocaleString();
                }
                if (ownersEl?.textContent === '-' && col.owners) {
                    ownersEl.textContent = col.owners.toLocaleString();
                }
            }
        })
        .catch(err => {
            console.warn('Stats proxy failed:', err.message);
        });
    }
})();

</script>


<!-- 
=====================================================================
IMC PHASE 4: PRODUCTION-READY COLLECTION PAGE UX
=====================================================================

FIXES:
1. ✅ Remove "Available to Mint" cards grid (shows minted NFTs only)
2. ✅ Add Preview button NEXT TO Mint button in header
3. ✅ Fix audio preview (use media_ipfs field)
4. ✅ Fix Mint button styling (IMU gold branding)
5. ✅ Better header layout alignment
6. ✅ Multi-mint: single payment, redirect to claim
7. ✅ "Claim NFTs" button after payment

APPLY: Replace the Phase 3 script block in collections.php with this code
       (Find the script block starting with "IMC Phase 3" or similar)

=====================================================================
-->

<style>
/* =====================================================================
   IMC PHASE 4: REFINED COLLECTION PAGE STYLES
   ===================================================================== */

/* =============================================================
   INTEGRATED MINT HEADER (Aligned with Collection Hero)
   ============================================================= */

.mint-status-banner {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 1rem 1rem !important;
    margin: 0 auto !important;
    max-width: 1200px !important;
}

.mint-status-banner.active {
    border: none !important;
    box-shadow: none !important;
}

.mint-banner-inner {
    background: linear-gradient(135deg, #1a1a2e 0%, #252540 100%) !important;
    border: 1px solid rgba(212, 175, 55, 0.3) !important;
    border-radius: 16px !important;
    padding: 1.25rem 1.5rem !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 1.5rem !important;
    flex-wrap: wrap !important;
}

/* LEFT column: badge + progress bar + price stacked */
.mint-banner-left {
    display: flex !important;
    flex-direction: column !important;
    gap: 0.75rem !important;
    min-width: 220px !important;
}

.mint-price-left {
    border-right: none !important;
    padding-right: 0 !important;
    margin-right: 0 !important;
    text-align: left !important;
}

/* Status Badge */
.mint-badge {
    padding: 0.5rem 1rem !important;
    border-radius: 9999px !important;
    font-weight: 600 !important;
    font-size: 0.85rem !important;
    white-space: nowrap !important;
    display: inline-block !important;
}

.mint-badge-live {
    background: rgba(0, 255, 0, 0.2) !important;
    color: #00ff00 !important;
    animation: imc-pulse 2s infinite !important;
}

@keyframes imc-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(0, 255, 0, 0.4); }
    50% { box-shadow: 0 0 0 8px rgba(0, 255, 0, 0); }
}

/* Progress Section — now inside left column, no own flex grow */
.mint-banner-progress {
    width: 100% !important;
}

.mint-progress-stats {
    display: flex !important;
    justify-content: space-between !important;
    margin-bottom: 0.5rem !important;
    font-size: 0.85rem !important;
    color: #888 !important;
}

.mint-progress-stats strong {
    color: #fff !important;
}

.mint-progress-percent {
    color: #d4af37 !important;
}

.mint-progress-bar-wrapper {
    height: 8px !important;
    background: rgba(255, 255, 255, 0.1) !important;
    border-radius: 4px !important;
    overflow: hidden !important;
}

.mint-progress-bar {
    height: 100% !important;
    background: linear-gradient(90deg, #d4af37, var(--imu-gold, #d6ba66)) !important;
    border-radius: 4px !important;
    transition: width 0.5s ease !important;
    box-shadow: 0 0 10px rgba(212, 175, 55, 0.4) !important;
}

/* RIGHT column: action area */
.mint-banner-action {
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    gap: 0.55rem !important;
    flex: 0 0 auto !important;
}

/* For the non-scheduled live state, override to row */
.mint-banner-action:not(:has(.mint-countdown)) {
    flex-direction: row !important;
    align-items: center !important;
}

.mint-price-info {
    text-align: center !important;
}
    margin-right: 0.5rem !important;
}

.mint-price-label {
    display: block !important;
    font-size: 0.7rem !important;
    color: #888 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
}

.mint-price-value {
    font-size: 1.25rem !important;
    font-weight: 700 !important;
    color: #d4af37 !important;
}

/* Preview Button - Outline Style */
.mint-preview-btn {
    background: transparent !important;
    color: #d4af37 !important;
    border: 2px solid #d4af37 !important;
    padding: 0.65rem 1.25rem !important;
    border-radius: 10px !important;
    font-size: 0.95rem !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    transition: all 0.2s ease !important;
    white-space: nowrap !important;
    display: flex !important;
    align-items: center !important;
    gap: 0.4rem !important;
}

.mint-preview-btn:hover {
    background: rgba(212, 175, 55, 0.15) !important;
    transform: translateY(-2px) !important;
}

/* Mint Button - Gold Filled */
.mint-banner-btn {
    background: linear-gradient(135deg, #d4af37 0%, #c9a227 100%) !important;
    color: #000 !important;
    border: none !important;
    padding: 0.75rem 1.5rem !important;
    border-radius: 10px !important;
    font-size: 1rem !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    transition: all 0.2s ease !important;
    white-space: nowrap !important;
    box-shadow: 0 4px 15px rgba(212, 175, 55, 0.3) !important;
}

.mint-banner-btn:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 25px rgba(212, 175, 55, 0.5) !important;
    background: linear-gradient(135deg, var(--imu-gold, #d6ba66) 0%, #d4af37 100%) !important;
}

/* v234: Disabled state for scheduled mints */
.mint-banner-btn-disabled {
    background: rgba(99, 102, 241, 0.25) !important;
    color: #a5b4fc !important;
    border: 1px solid rgba(99, 102, 241, 0.4) !important;
    box-shadow: none !important;
    cursor: not-allowed !important;
    opacity: 0.85 !important;
}
.mint-banner-btn-disabled:hover {
    transform: none !important;
    box-shadow: none !important;
    background: rgba(99, 102, 241, 0.25) !important;
}

/* v234: Scheduled badge */
.mint-badge-scheduled {
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.3), rgba(139, 92, 246, 0.3)) !important;
    color: #a5b4fc !important;
    border: 1px solid rgba(99, 102, 241, 0.5) !important;
    animation: imc-pulse-indigo 2s infinite !important;
}
@keyframes imc-pulse-indigo {
    0%, 100% { box-shadow: 0 0 0 0 rgba(99, 102, 241, 0.4); }
    50% { box-shadow: 0 0 0 8px rgba(99, 102, 241, 0); }
}

/* v234: Scheduled banner border */
.mint-status-banner.scheduled .mint-banner-inner {
    border-color: rgba(99, 102, 241, 0.4) !important;
    box-shadow: 0 4px 20px rgba(99, 102, 241, 0.15) !important;
}

/* v236: Scheduled right-col action: countdown stacked above date, then buttons row */
.mint-scheduled-btns {
    display: flex !important;
    gap: 0.6rem !important;
    align-items: center !important;
    justify-content: center !important;
}

/* v234: Countdown timer */
.mint-countdown-label {
    font-size: 0.68rem !important;
    text-transform: uppercase !important;
    letter-spacing: 0.1em !important;
    color: #a5b4fc !important;
    font-weight: 700 !important;
}
.mint-countdown {
    display: flex !important;
    align-items: center !important;
    gap: 0.3rem !important;
}
.mint-countdown-unit {
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
}
.mint-countdown-unit span {
    font-size: 1.9rem !important;
    font-weight: 800 !important;
    color: #fff !important;
    line-height: 1 !important;
    font-variant-numeric: tabular-nums !important;
    background: rgba(99, 102, 241, 0.22) !important;
    border: 1px solid rgba(99, 102, 241, 0.35) !important;
    border-radius: 8px !important;
    padding: 0.25rem 0.55rem !important;
    min-width: 3.2rem !important;
    text-align: center !important;
    letter-spacing: -0.02em !important;
}
.mint-countdown-unit em {
    font-size: 0.58rem !important;
    color: #6b7280 !important;
    font-style: normal !important;
    text-transform: uppercase !important;
    letter-spacing: 0.06em !important;
    margin-top: 0.25rem !important;
}
.mint-countdown-sep {
    font-size: 1.6rem !important;
    font-weight: 700 !important;
    color: #6366f1 !important;
    margin-bottom: 1.2rem !important;
    opacity: 0.7 !important;
}
.mint-countdown-date {
    font-size: 0.7rem !important;
    color: #6b7280 !important;
    text-align: center !important;
    white-space: nowrap !important;
    letter-spacing: 0.02em !important;
}

/* =============================================================
   HIDE "AVAILABLE TO MINT" LISTING CARDS
   (The NFT grid will show minted NFTs instead)
   ============================================================= */

.mint-listings-section {
    display: none !important;
}

/* =============================================================
   MODAL SYSTEM - Refined Styling
   ============================================================= */

#imc-mint-modal,
#imc-preview-modal {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    width: 100vw !important;
    height: 100vh !important;
    background: rgba(0, 0, 0, 0.92) !important;
    backdrop-filter: blur(8px) !important;
    -webkit-backdrop-filter: blur(8px) !important;
    display: flex !important;
    justify-content: center !important;
    align-items: center !important;
    z-index: 999999 !important;
    padding: 1rem !important;
    animation: imcFadeIn 0.2s ease-out !important;
}

@keyframes imcFadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.imc-modal-box {
    background: linear-gradient(145deg, #1e1e30, #151520) !important;
    border: 2px solid rgba(212, 175, 55, 0.3) !important;
    border-radius: 16px !important;
    max-width: 440px !important;
    width: 100% !important;
    max-height: 90vh !important;
    overflow-y: auto !important;
    position: relative !important;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.6), 
                0 0 40px rgba(212, 175, 55, 0.15) !important;
    animation: imcSlideUp 0.25s ease-out !important;
}

@keyframes imcSlideUp {
    from { opacity: 0; transform: translateY(30px) scale(0.95); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

.imc-modal-header {
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    padding: 1.25rem 1.5rem !important;
    border-bottom: 1px solid rgba(212, 175, 55, 0.15) !important;
}

.imc-modal-title {
    font-size: 1.1rem !important;
    font-weight: 600 !important;
    color: #d4af37 !important;
    margin: 0 !important;
}

.imc-modal-close {
    background: transparent !important;
    border: none !important;
    color: #666 !important;
    font-size: 1.5rem !important;
    cursor: pointer !important;
    padding: 0.25rem !important;
    line-height: 1 !important;
    transition: color 0.2s !important;
}

.imc-modal-close:hover {
    color: #d4af37 !important;
}

.imc-modal-body {
    padding: 1.5rem !important;
}

/* Quantity Controls */
.imc-qty-row {
    display: flex !important;
    gap: 1rem !important;
    margin-bottom: 1.5rem !important;
}

.imc-qty-group {
    flex: 1 !important;
}

.imc-qty-label {
    display: block !important;
    font-size: 0.8rem !important;
    color: #888 !important;
    margin-bottom: 0.5rem !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
}

.imc-qty-control {
    display: flex !important;
    background: #0d0d15 !important;
    border-radius: 8px !important;
    overflow: hidden !important;
    border: 1px solid rgba(212, 175, 55, 0.2) !important;
}

.imc-qty-btn {
    width: 48px !important;
    height: 48px !important;
    background: #1a1a2e !important;
    border: none !important;
    color: #d4af37 !important;
    font-size: 1.25rem !important;
    cursor: pointer !important;
    transition: all 0.2s !important;
}

.imc-qty-btn:hover {
    background: #d4af37 !important;
    color: #000 !important;
}

.imc-qty-input {
    flex: 1 !important;
    background: #0d0d15 !important;
    border: none !important;
    color: #fff !important;
    font-size: 1.1rem !important;
    font-weight: 600 !important;
    text-align: center !important;
    min-width: 60px !important;
}

.imc-qty-input::-webkit-inner-spin-button,
.imc-qty-input::-webkit-outer-spin-button {
    -webkit-appearance: none !important;
    margin: 0 !important;
}

.imc-total-display {
    background: #0d0d15 !important;
    border: 1px solid rgba(212, 175, 55, 0.3) !important;
    border-radius: 8px !important;
    padding: 0.75rem 1rem !important;
    text-align: center !important;
    height: 48px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
}

.imc-total-amount {
    font-size: 1.2rem !important;
    font-weight: 700 !important;
    color: #d4af37 !important;
}

/* Action Buttons - GOLD BRANDED */
.imc-modal-actions {
    display: flex !important;
    gap: 0.75rem !important;
    margin-top: 1rem !important;
}

.imc-btn-mint {
    flex: 1 !important;
    background: linear-gradient(135deg, #d4af37 0%, #c9a227 100%) !important;
    color: #000 !important;
    border: none !important;
    padding: 1rem !important;
    border-radius: 8px !important;
    font-size: 1rem !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    transition: all 0.2s !important;
}

.imc-btn-mint:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 4px 20px rgba(212, 175, 55, 0.4) !important;
}

.imc-btn-cancel {
    flex: 1 !important;
    background: transparent !important;
    color: #888 !important;
    border: 1px solid rgba(255, 255, 255, 0.15) !important;
    padding: 1rem !important;
    border-radius: 8px !important;
    font-size: 1rem !important;
    cursor: pointer !important;
    transition: all 0.2s !important;
}

.imc-btn-cancel:hover {
    background: rgba(255, 255, 255, 0.05) !important;
    color: #fff !important;
    border-color: rgba(255, 255, 255, 0.25) !important;
}

/* Preview Section */
.imc-preview-section {
    margin-bottom: 1.5rem !important;
    text-align: center !important;
}

.imc-preview-cover {
    width: 200px !important;
    height: 200px !important;
    margin: 0 auto 1rem !important;
    border-radius: 12px !important;
    overflow: hidden !important;
    border: 2px solid rgba(212, 175, 55, 0.3) !important;
    position: relative !important;
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.4) !important;
}

.imc-preview-cover img {
    width: 100% !important;
    height: 100% !important;
    object-fit: cover !important;
}

.imc-preview-btn-overlay {
    position: absolute !important;
    bottom: 0.5rem !important;
    right: 0.5rem !important;
    background: rgba(0, 0, 0, 0.9) !important;
    color: #d4af37 !important;
    border: 1px solid #d4af37 !important;
    padding: 0.4rem 0.8rem !important;
    border-radius: 6px !important;
    font-size: 0.8rem !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    display: flex !important;
    align-items: center !important;
    gap: 0.3rem !important;
    transition: all 0.2s !important;
}

.imc-preview-btn-overlay:hover {
    background: #d4af37 !important;
    color: #000 !important;
}

.imc-track-name {
    font-size: 1rem !important;
    font-weight: 600 !important;
    color: #fff !important;
    margin: 0 !important;
}

/* Media Preview Modal */
.imc-media-container {
    background: #0d0d15 !important;
    border-radius: 12px !important;
    padding: 1rem !important;
    margin-bottom: 1rem !important;
    border: 1px solid rgba(212, 175, 55, 0.15) !important;
}

.imc-audio-preview {
    text-align: center !important;
}

.imc-audio-preview img {
    width: 100% !important;
    max-width: 280px !important;
    border-radius: 8px !important;
    margin-bottom: 1rem !important;
}

.imc-audio-preview audio,
.imc-audio-preview video {
    width: 100% !important;
    border-radius: 8px !important;
}

.imc-preview-note {
    text-align: center !important;
    color: #888 !important;
    font-size: 0.85rem !important;
    margin: 1rem 0 !important;
    padding: 0.75rem !important;
    background: rgba(212, 175, 55, 0.1) !important;
    border-radius: 8px !important;
}

/* Claim NFTs Button */
.imc-claim-section {
    text-align: center !important;
    padding: 2rem !important;
    background: rgba(212, 175, 55, 0.1) !important;
    border-radius: 12px !important;
    margin: 1rem !important;
}

.imc-claim-btn {
    background: linear-gradient(135deg, #d4af37 0%, #c9a227 100%) !important;
    color: #000 !important;
    border: none !important;
    padding: 1rem 2rem !important;
    border-radius: 10px !important;
    font-size: 1.1rem !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    transition: all 0.2s !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 0.5rem !important;
}

.imc-claim-btn:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 25px rgba(212, 175, 55, 0.5) !important;
}

/* Responsive */
@media (max-width: 768px) {
    .mint-banner-inner {
        flex-direction: column !important;
        align-items: stretch !important;
        text-align: center !important;
    }

    .mint-banner-left {
        align-items: center !important;
        min-width: 0 !important;
    }

    .mint-price-left {
        text-align: center !important;
    }

    .mint-banner-action {
        align-items: center !important;
        width: 100% !important;
    }

    /* Scheduled mobile: countdown numbers slightly smaller */
    .mint-countdown-unit span {
        font-size: 1.5rem !important;
        min-width: 2.6rem !important;
    }
}

@media (max-width: 480px) {
    /* Mint banner: compact countdown + stacked buttons so nothing overflows */
    .mint-banner-inner {
        padding: 1rem !important;
        gap: 0.9rem !important;
    }

    .mint-banner-left {
        gap: 0.5rem !important;
    }

    .mint-countdown {
        gap: 0.15rem !important;
    }

    .mint-countdown-unit span {
        font-size: 1.25rem !important;
        min-width: 2.1rem !important;
        padding: 0.2rem 0.3rem !important;
    }

    .mint-countdown-unit em {
        font-size: 0.5rem !important;
    }

    .mint-countdown-sep {
        font-size: 1.2rem !important;
        margin-bottom: 0.9rem !important;
    }

    .mint-countdown-date {
        white-space: normal !important;
        text-align: center !important;
    }

    /* Stack Preview + Not Yet Live buttons vertically on small screens */
    .mint-scheduled-btns {
        flex-direction: column !important;
        width: 100% !important;
        gap: 0.5rem !important;
    }

    /* v325: Override the desktop row rule for the LIVE state (no countdown present).
       Without this, the :not(:has(.mint-countdown)) selector forces flex-direction:row
       even on narrow screens, causing Preview + Mint Now to overflow the container. */
    .mint-banner-action:not(:has(.mint-countdown)) {
        flex-direction: column !important;
        width: 100% !important;
        gap: 0.5rem !important;
    }

    .mint-preview-btn,
    .mint-banner-btn {
        width: 100% !important;
        justify-content: center !important;
    }

    /* Modal fixes */
    .imc-modal-box {
        margin: 0.5rem !important;
        max-width: calc(100% - 1rem) !important;
    }

    .imc-qty-row {
        flex-direction: column !important;
    }

    .imc-modal-actions {
        flex-direction: column !important;
    }
}

/* v342: Zero out Astra article/entry-content side padding so cards reach screen edges */
@media (max-width: 768px) {
    #nft-collection-page,
    body article:has(#nft-collection-page),
    body .entry-content:has(#nft-collection-page) {
        padding-left: 0 !important;
        padding-right: 0 !important;
        margin-left: 0 !important;
        margin-right: 0 !important;
    }
    /* Broad fallback: zero any ancestor within primary content area */
    #primary, #primary article, #primary .entry-content,
    .ast-article-single, .ast-article-post-thumb {
        padding-left: 0 !important;
        padding-right: 0 !important;
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
   M10 — DROP-CARD REDESIGN.  Additive block; delete it to revert the styling.

   ANCHORED AFTER the late block (L3674-4404, 387 !important, last writer for
   18 of the card's 28 elements). Earlier attempts sat 850 lines EARLIER and
   lost every tie by document order. Here, order alone wins.

   LAW 1  base.css:26 / custom.css:111 declare
            div,span,button,label,a,p{color:white!important;font-family:Montserrat!important}
          (0,0,1) but !important — every colour/font rule here must carry !important.
   LAW 2  the card only stacks because .imc-drop-box is PINNED at flex:0 0 360px
          (L2813). Three overrides free it; the rail's native flex-wrap then does
          the 2-up by itself, at whatever width the container happens to be.

   Breakpoint is 769px to meet the native mobile rule at L2877 exactly.
   ═══════════════════════════════════════════════════════════════════════════ */

/* ---- SURFACE: EVERY width. The card skin must NOT sit in a desktop-only query --
   the late block's .mint-banner-inner otherwise paints #1a1a2e->#252540 (navy). ---- */
.imc-drop-rail .imc-drop-box{padding:14px !important;border-radius:16px !important;
  background:linear-gradient(160deg,#1e1b18 0%,#161412 100%) !important;
  border:1px solid rgba(201,168,50,.30) !important;
  box-shadow:inset 0 1px 0 rgba(232,207,122,.10),0 10px 28px rgba(0,0,0,.45) !important;}
.imc-drop-rail .mint-price-value{font-size:1.42rem !important;color:#d4af37 !important;}
.imc-drop-rail .mint-price-label{font-size:.64rem !important;color:#9b968c !important;}

/* ---- structure, all widths (the markup changed, so mobile needs these too) ---- */
/* M1-f3b: drop-box back-cover flip (AudioBook). Both faces are watermarked in public;
   holders get the unwatermarked originals on the NFT page. */
/* M2-d: eBook book-type badge on drop boxes. */
.imc-drop-rail .imc-book-type{display:inline-block;margin-left:6px;padding:1px 7px;
    border:1px solid rgba(201,168,50,.4);border-radius:10px;font-size:11px;
    line-height:1.5;opacity:.9;vertical-align:middle;}
.imc-drop-rail .imc-box-cover.has-back{position:relative;}
.imc-drop-rail .imc-box-flip{position:absolute;bottom:6px;right:6px;z-index:2;
    background:rgba(0,0,0,.7);color:#ece7d8;border:1px solid rgba(201,168,50,.45);
    border-radius:5px;padding:3px 8px;font-size:11px;line-height:1.3;cursor:pointer;}
.imc-drop-rail .imc-box-flip:hover{border-color:#c9a832;}
.imc-drop-rail .imc-box-mediacol{display:flex !important;flex-direction:column !important;gap:8px !important;min-width:0 !important;}
.imc-drop-rail .imc-box-mediacol .imc-box-cover,
.imc-drop-rail .imc-box-mediacol .imc-box-player{width:100% !important;max-width:none !important;margin:0 !important;}
.imc-drop-rail .imc-box-mediacol .imc-player{max-width:none !important;width:100% !important;}
.imc-drop-rail .imc-box-body{display:flex !important;flex-direction:column !important;gap:8px !important;min-width:0 !important;flex:1 1 auto !important;}
.imc-drop-rail .imc-box-head{display:flex !important;align-items:center !important;gap:10px !important;}
.imc-drop-rail .imc-box-head .imc-box-title{flex:1 1 auto !important;min-width:0 !important;margin:0 !important;
  font-size:1.02rem !important;line-height:1.3 !important;-webkit-line-clamp:2 !important;color:#f2efe9 !important;}
.imc-drop-rail .imc-box-head .mint-banner-status{flex:0 0 auto !important;margin:0 !important;}
.imc-drop-rail .imc-box-head .mint-badge{font-size:.68rem !important;padding:.28rem .6rem !important;}

/* ---- stat cells: number over label (impossible before — it was one text node) ---- */
.imc-drop-rail .imc-box-stats{display:flex !important;gap:8px !important;width:100% !important;max-width:none !important;margin:0 !important;}
.imc-drop-rail .imc-stat{flex:1 1 0 !important;min-width:0 !important;margin:0 !important;
  background:rgba(255,255,255,.05) !important;border-radius:10px !important;padding:7px 5px !important;
  display:flex !important;flex-direction:column !important;align-items:center !important;justify-content:center !important;gap:3px !important;text-align:center !important;}
.imc-drop-rail .imc-stat > b{font-size:1.02rem !important;font-weight:800 !important;line-height:1 !important;color:#f2efe9 !important;font-variant-numeric:tabular-nums !important;}
.imc-drop-rail .imc-stat > b > i{font-style:normal !important;font-size:.72rem !important;font-weight:600 !important;color:#9b968c !important;}
.imc-drop-rail .imc-stat > span{font-size:.56rem !important;font-weight:700 !important;letter-spacing:.1em !important;
  text-transform:uppercase !important;color:#9b968c !important;line-height:1 !important;}
.imc-drop-rail .imc-stat.mint-banner-collected > b,
.imc-drop-rail .imc-stat.mint-banner-collected > span{color:#00ff00 !important;}
.imc-drop-rail .imc-stat.mint-banner-collected-none > b,
.imc-drop-rail .imc-stat.mint-banner-collected-none > span{color:#ff3b30 !important;}

/* timer cell — days+hrs only; minutes/seconds keep ticking, just hidden.
   This also removes the ~229px min-content floor the 4 units imposed on the card. */
.imc-drop-rail .imc-stat-timer .mint-countdown{display:flex !important;align-items:baseline !important;justify-content:center !important;gap:.1rem !important;margin:0 !important;}
/* CP-U: was `> *:nth-child(n+4){display:none}` - days+hours by POSITION. The
   tick now sets display per unit, so hiding by position would fight it and win
   (it carried !important). The two-unit width this protected is preserved: the
   tick never shows more than two. */
.imc-drop-rail .imc-stat-timer .mint-countdown .mint-countdown-unit{display:inline-flex;}
/* CP-U: must carry !important to beat `.mint-countdown-unit{display:flex !important}`
   at L4782. Scoped to nothing in particular on purpose - the countdown is the same
   component wherever it renders, and both the rail and the box want this. */
.mint-countdown .imc-cd-hide{display:none !important;}
.imc-drop-rail .imc-stat-timer .mint-countdown-unit{flex-direction:row !important;align-items:baseline !important;gap:1px !important;}
.imc-drop-rail .imc-stat-timer .mint-countdown-unit span{font-size:1.02rem !important;font-weight:800 !important;min-width:0 !important;
  padding:0 !important;background:none !important;border:none !important;color:#f2efe9 !important;}
.imc-drop-rail .imc-stat-timer .mint-countdown-unit em{font-size:.6rem !important;font-weight:700 !important;margin:0 3px 0 1px !important;color:#9b968c !important;}
.imc-drop-rail .imc-stat.imc-stat-timer{flex:1.35 1 0 !important;}
.imc-drop-rail .imc-stat > span{white-space:nowrap !important;}
/* CP-U: the separator is now shown/hidden by the tick (one sep, between the two
   visible units), so it can no longer be blanket-hidden here. */
.imc-drop-rail .imc-stat-timer .mint-countdown-sep{opacity:.45;}

/* CP-U - FINAL HOUR. Colour AND motion, because colour alone is invisible to a
   red-green colourblind creator. prefers-reduced-motion drops the pulse and
   keeps the colour, which still carries the meaning. */
.mint-countdown.is-urgent .mint-countdown-unit span{ color:#ff3b30 !important; }
.mint-countdown.is-urgent .mint-countdown-unit em{ color:#ff6b60 !important; }
.mint-countdown.is-urgent{ animation:imcUrgentPulse 1s ease-in-out infinite; }
@keyframes imcUrgentPulse{ 0%,100%{opacity:1;} 50%{opacity:.55;} }
@media (prefers-reduced-motion: reduce){
    .mint-countdown.is-urgent{ animation:none; }
}

/* ---- progressive pricing ---- */
.imc-drop-rail .imc-box-pp{display:flex !important;flex-direction:column !important;gap:7px !important;
  background:rgba(212,175,55,.06) !important;border:1px solid rgba(201,168,50,.26) !important;border-radius:12px !important;padding:9px 11px !important;}
.imc-drop-rail .imc-pp-head{display:flex !important;align-items:center !important;gap:8px !important;flex-wrap:wrap !important;}
.imc-drop-rail .imc-pp-cur{flex:0 0 auto !important;font-size:.62rem !important;font-weight:800 !important;letter-spacing:.06em !important;
  color:#e8cf7a !important;background:rgba(212,175,55,.16) !important;border-radius:999px !important;padding:2px 8px !important;}
.imc-drop-rail .imc-pp-note{font-size:.7rem !important;color:#9b968c !important;}
.imc-drop-rail .imc-pp-note b{color:#e8cf7a !important;}
.imc-drop-rail .imc-pp-lane.is-capped .imc-pp-note{color:#e8cf7a !important;font-weight:700 !important;}
.imc-drop-rail .imc-pp-track{height:5px !important;border-radius:3px !important;background:rgba(255,255,255,.10) !important;overflow:hidden !important;margin-top:6px !important;}
.imc-drop-rail .imc-pp-fill{height:100% !important;border-radius:3px !important;background:linear-gradient(90deg,#d4af37,#e8cf7a) !important;}
.imc-drop-rail .imc-pp-ends{display:flex !important;justify-content:space-between !important;margin-top:3px !important;}
.imc-drop-rail .imc-pp-ends span{font-size:.6rem !important;color:#9b968c !important;letter-spacing:.04em !important;}

/* ---- play-preview bar: one row, sized to the media column ---- */
.imc-drop-rail .imc-box-mediacol .imc-video-bar{padding:.28rem .5rem !important;gap:.4rem !important;}
.imc-drop-rail .imc-box-mediacol .imc-video-bar .imc-pp{width:22px !important;height:22px !important;font-size:.6rem !important;flex:0 0 auto !important;}
.imc-drop-rail .imc-video-label{font-size:.68rem !important;white-space:nowrap !important;overflow:hidden !important;text-overflow:ellipsis !important;min-width:0 !important;color:#e9e2c9 !important;}

/* ---- unlockables chip: sits under the cover + player, full media-column width ---- */
.imc-drop-rail .imc-box-ulchip{width:100% !important;box-sizing:border-box !important;text-align:center !important;
  font-size:.66rem !important;line-height:1.25 !important;color:#7dd3fc !important;
  background:rgba(125,211,252,.09) !important;border:1px solid rgba(125,211,252,.35) !important;border-radius:10px !important;padding:6px 8px !important;}

/* ---- supply bar + actions ---- */
.imc-drop-rail .imc-box-body .mint-progress-bar-wrapper{width:100% !important;height:5px !important;margin:0 !important;}
.imc-drop-rail .imc-box-body .mint-banner-btn{width:100% !important;margin:1px 0 0 !important;padding:.68rem 1.4rem !important;font-size:.95rem !important;
  border-radius:12px !important;text-align:center !important;display:block !important;}
.imc-drop-rail .imc-box-body .mint-scheduled-btns{width:100% !important;margin:2px 0 0 !important;}
.imc-drop-rail .imc-box-body .mint-countdown-date{color:#9b968c !important;font-size:.66rem !important;margin:0 !important;}

/* ---- LAW 2: unpin the card so the rail's native flex-wrap can go 2-up ---- */
@media (min-width:769px){
  /* FIXED 620px. Two cards + the 22px gap need 1262px, which clears comfortably
     inside the lifted section (1480 - 32 padding = 1448) and still pairs on a
     ~1300px container. 700px was pairing only above ~1450 and read oversized at 100%. */
  .mint-status-banner{max-width:1480px !important;}
  .imc-drop-rail .imc-drop-box{flex:0 0 620px !important;width:620px !important;max-width:100% !important;
    flex-direction:row !important;align-items:stretch !important;flex-wrap:nowrap !important;
    gap:14px !important;text-align:left !important;}
  .imc-drop-rail .imc-box-mediacol{flex:0 0 188px !important;width:auto !important;max-width:none !important;margin:0 !important;}
  .imc-drop-rail .imc-box-body{width:auto !important;text-align:left !important;}
  .imc-drop-rail .imc-box-body .mint-price-info{text-align:left !important;}
  /* the sold-out card has no media column — let its body use the full width */
  .imc-drop-rail .imc-drop-box.is-soldout{flex-direction:column !important;}
  /* per-count rail caps would re-pin the width */
  .imc-rail-n4{max-width:none !important;}
}
/* ---- mobile: the native card is column + align-items:center, so the new
        containers need explicit widths or they collapse to their content. ---- */
@media (max-width:768px){
  /* height trim: the 1/1 cover is the tallest element, so capping the media column
     is what actually shortens the card. Gaps and padding tighten alongside it. */
  .imc-drop-rail .imc-drop-box{padding:12px !important;gap:8px !important;}
  .imc-drop-rail .imc-box-mediacol{width:100% !important;max-width:190px !important;margin:0 auto !important;gap:6px !important;}
  .imc-drop-rail .imc-box-body{width:100% !important;gap:7px !important;}
  .imc-drop-rail .imc-stat{padding:6px 4px !important;}
  .imc-drop-rail .imc-box-pp{padding:7px 9px !important;}
  .imc-drop-rail .imc-box-body .mint-banner-btn{padding:.62rem 1.2rem !important;}
  .imc-drop-rail .imc-box-head{justify-content:center !important;flex-wrap:wrap !important;}
  .imc-drop-rail .imc-box-head .imc-box-title{flex:0 1 auto !important;text-align:center !important;}
  .imc-drop-rail .mint-price-info{text-align:center !important;}
  .imc-drop-rail .imc-box-ulchip{max-width:190px !important;margin:0 auto !important;font-size:.62rem !important;padding:5px 6px !important;}
}
/* ═══════════════════ /M10 ═══════════════════ */
</style>

<script>
/**
 * IMC Phase 4: Production-Ready Collection Page System
 * All functions globally accessible for onclick handlers
 */

// v70: Initialize global xrplMarketplace with endpoints from data attribute
(function initGlobalMarketplace() {
    const pageContainer = document.getElementById('nft-collection-page') || 
                          document.getElementById('collections-browse-page');
    if (pageContainer && pageContainer.dataset.endpoints) {
        window.xrplMarketplace = {
            endpoints: JSON.parse(pageContainer.dataset.endpoints)
        };
    } else {
        // Fallback to default endpoints
        window.xrplMarketplace = {
            endpoints: {
                allowlistHandler: '/wp-content/themes/astra/xrpl-nft-marketplace/backend/allowlist-handler.php',
                mintOnDemand: '/wp-content/themes/astra/xrpl-nft-marketplace/backend/mint-on-demand-handler.php'
            }
        };
    }
})();

// ============================================
// STATE
// ============================================
window.imcMintState = {
    currentListing: null,
    pricePerUnit: 0,
    maxQty: 10,
    pendingMints: 0,
    firstListing: null // Store first listing for header preview button
};

// ============================================
// INITIALIZATION - Store first listing for header preview
// ============================================
(function initFirstListing() {
    // Get first listing ID from data attribute on grid
    const grid = document.getElementById('mod-listings-grid');
    if (grid) {
        const firstCard = grid.querySelector('.mod-listing-card');
        if (firstCard) {
            const listingId = firstCard.dataset.listingId;
            window.imcMintState.firstListing = listingId;
        }
    }
})();

// ============================================
// GLOBAL: Scroll to mint listings (now opens modal directly)
// ============================================
window.scrollToMintListings = function() {
    // Instead of scrolling, open the mint modal for the first listing
    const firstListingId = window.imcMintState.firstListing;
    if (firstListingId) {
        mintFromListing(firstListingId);
    } else {
        // Fallback: try to get from grid
        const grid = document.getElementById('mod-listings-grid');
        if (grid) {
            const firstCard = grid.querySelector('.mod-listing-card');
            if (firstCard && firstCard.dataset.listingId) {
                mintFromListing(firstCard.dataset.listingId);
            }
        }
    }
};

// ============================================
// GLOBAL: Open preview from header button
// ============================================
window.openHeaderPreview = function() {
    const firstListingId = window.imcMintState.firstListing;
    if (firstListingId) {
        openPreviewModal(firstListingId);
    } else {
        showImcToast('No preview available', 'warning');
    }
};

// ============================================
// GLOBAL: Open mint modal for a listing
// ============================================
window.mintFromListing = async function(listingId) {
    // Check wallet
    const account = getCookieValue('xrpl_account');
    if (!account) {
        showImcToast('Please connect your wallet first', 'warning');
        return;
    }
    
    // v69: Check allowlist status before showing mint modal
    try {
        const allowlistUrl = window.xrplMarketplace?.endpoints?.allowlistHandler || 
            '/wp-content/themes/astra/xrpl-nft-marketplace/backend/allowlist-handler.php';
        const checkResp = await fetch(`${allowlistUrl}?action=check_wallet&listing_id=${listingId}&wallet=${account}`);
        const checkData = await checkResp.json();
        
        if (checkData.success) {
            // v505 Phase B: tag benefits with their listing so the purchase modal can
            // reject stale data from another drop (concurrent-listing safety, display-only).
            window.currentAllowlistBenefits = Object.assign({}, checkData.data, { __listingId: listingId });
            
            // Check if listing is exclusive and user is NOT on allowlist
            // v730 (A4/G4): the server requires EXCLUSIVE membership specifically — being on
            // some other list (e.g. a discount) used to pass here, then fail at reserve with
            // a generic error. Front and back now agree.
            if (checkData.data.listing_is_exclusive && !checkData.data.is_exclusive_member) {
                showImcToast('This collection is exclusive to allowlisted wallets only', 'error');
                return;
            }
            
            // Log benefits for debugging
            console.log('Allowlist benefits:', checkData.data);
        }
    } catch (err) {
        console.log('Allowlist check skipped:', err);
        window.currentAllowlistBenefits = null;
    }
    
    // Close any existing modal
    closeImcModal();
    
    // Delegate directly to mint-on-demand Phase 4 purchase flow
    // (has its own qty selector, reserve, pay, mint, claim steps)
    if (window.mintOnDemand && window.mintOnDemand.showPurchaseModal) {
        window.mintOnDemand.showPurchaseModal(listingId);
    } else {
        showImcToast('Mint system not loaded. Refresh and try again.', 'error');
    }
};

// ============================================
// Extract listing data from card element
// ============================================
function extractListingData(card, listingId) {
    const nameEl = card.querySelector('.mod-listing-name');
    const priceEl = card.querySelector('.mod-listing-price');
    const metaEl = card.querySelector('.mod-listing-meta span');
    const imgEl = card.querySelector('.mod-listing-cover img');
    const typeEl = card.querySelector('.mod-listing-type');
    
    const name = nameEl?.textContent?.trim() || 'NFT';
    const priceText = priceEl?.textContent?.replace('XRP', '').trim() || '0';
    const price = parseFloat(priceText) || 0;
    
    const metaText = metaEl?.textContent || '0 of 0';
    const match = metaText.match(/(\d+)\s*of\s*(\d+)/);
    const available = match ? parseInt(match[1]) : 1;
    const total = match ? parseInt(match[2]) : 1;
    
    return {
        id: listingId,
        nft_name: name,
        price_xrp: price,
        available_editions: available,
        total_editions: total,
        minted_count: total - available,
        cover_ipfs: imgEl?.src || null,
        // v468: recognise 💿 (album), 🎨 (art), 🎥 (film) in addition to the pre-existing 🎬/🎵 pair
        nft_type: (() => {
            const t = typeEl?.textContent || '';
            if (t.includes('💿')) return 'album';
            if (t.includes('🎬')) return 'musicvideo';
            if (t.includes('🎨')) return 'art';
            if (t.includes('🎥')) return 'film';
            return 'music';
        })()
    };
}

// ============================================
// Show Mint Modal (XRP.cafe Style with Gold Branding)
// ============================================
function showMintModal(listing) {
    const available = parseInt(listing.available_editions) || 
                      (parseInt(listing.total_editions) - parseInt(listing.minted_count || 0));
    const maxQty = Math.min(available, 10);
    const price = parseFloat(listing.price_xrp) || 0;
    
    window.imcMintState.pricePerUnit = price;
    window.imcMintState.maxQty = maxQty;
    
    // Get cover URL
    let coverUrl = '/wp-content/uploads/fallback-nft.svg';
    if (listing.cover_ipfs) {
        if (listing.cover_ipfs.startsWith('ipfs://')) {
            // v396: Route through VPS img.php proxy for caching
            coverUrl = 'https://metadata.imcollectibles.io/img.php?url=' + encodeURIComponent(listing.cover_ipfs);
        } else if (listing.cover_ipfs.startsWith('http')) {
            coverUrl = listing.cover_ipfs;
        }
    }
    
    // Check for audio/video preview (use media_ipfs field)
    const hasMedia = listing.media_ipfs || listing.audio_ipfs || listing.preview_ipfs;
    const trackName = escHtml(listing.nft_name);
    
    const modal = document.createElement('div');
    modal.id = 'imc-mint-modal';
    modal.onclick = function(e) { if (e.target === modal) closeImcModal(); };
    
    modal.innerHTML = `
        <div class="imc-modal-box">
            <div class="imc-modal-header">
                <h3 class="imc-modal-title">🛒 Mint NFT</h3>
                <button class="imc-modal-close" onclick="closeImcModal()">×</button>
            </div>
            
            <div class="imc-modal-body">
                <div class="imc-preview-section">
                    <div class="imc-preview-cover">
                        <img src="${coverUrl}" alt="${trackName}" 
                             onerror="this.src='/wp-content/uploads/fallback-nft.svg'">
                        ${hasMedia ? `
                            <button class="imc-preview-btn-overlay" onclick="openPreviewModal(${listing.id})">
                                ▶ Preview
                            </button>
                        ` : ''}
                    </div>
                    <h4 class="imc-track-name">${trackName}</h4>
                    ${listing.ul_count > 0 ? `<div class="imc-mint-ul-teaser">🎁 Includes ${listing.ul_count} unlockable file${listing.ul_count > 1 ? 's' : ''} for holders</div>` : ''}
                </div>
                
                <div class="imc-qty-row">
                    <div class="imc-qty-group">
                        <label class="imc-qty-label">Amount to mint</label>
                        <div class="imc-qty-control">
                            <button class="imc-qty-btn" onclick="adjustQty(-1)">−</button>
                            <input type="number" id="imc-qty-input" class="imc-qty-input" 
                                   value="1" min="1" max="${maxQty}" onchange="updateTotal()">
                            <button class="imc-qty-btn" onclick="adjustQty(1)">+</button>
                        </div>
                    </div>
                    
                    <div class="imc-qty-group">
                        <label class="imc-qty-label">Total cost</label>
                        <div class="imc-total-display">
                            <span class="imc-total-amount" id="imc-total-cost">${price.toFixed(2)} XRP</span>
                        </div>
                    </div>
                </div>
                
                <div class="imc-modal-actions">
                    <button class="imc-btn-mint" onclick="executeMint()">🛒 Mint Now</button>
                    <button class="imc-btn-cancel" onclick="closeImcModal()">Cancel</button>
                </div>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
}

// ============================================
// Adjust Quantity
// ============================================
window.adjustQty = function(delta) {
    const input = document.getElementById('imc-qty-input');
    if (!input) return;
    
    const current = parseInt(input.value) || 1;
    const max = window.imcMintState.maxQty || 10;
    
    input.value = Math.max(1, Math.min(max, current + delta));
    updateTotal();
};

// ============================================
// Update Total Cost
// ============================================
window.updateTotal = function() {
    const input = document.getElementById('imc-qty-input');
    const totalEl = document.getElementById('imc-total-cost');
    if (!input || !totalEl) return;
    
    const qty = parseInt(input.value) || 1;
    const price = window.imcMintState.pricePerUnit || 0;
    totalEl.textContent = `${(qty * price).toFixed(2)} XRP`;
};

// ============================================
// Execute Mint - Single Payment, Then Claim
// ============================================
window.executeMint = async function() {
    const input = document.getElementById('imc-qty-input');
    const qty = parseInt(input?.value) || 1;
    const listing = window.imcMintState.currentListing;
    
    if (!listing) {
        showImcToast('No listing selected', 'error');
        return;
    }
    
    // Store pending mints count for redirect
    window.imcMintState.pendingMints = qty;
    
    closeImcModal();
    
    // Use the mint-on-demand system
    if (window.mintOnDemand && window.mintOnDemand.initiatePurchase) {
        // Show progress toast
        showImcToast(`Initiating ${qty} mint${qty > 1 ? 's' : ''}...`, 'info');
        
        // For multiple mints, initiate one purchase (payment)
        // The backend should handle creating multiple pending claims
        try {
            // Single payment for multiple NFTs
            const result = await window.mintOnDemand.initiatePurchase(listing.id, qty);
            
            // After successful payment, redirect to dashboard to claim
            if (result && result.success !== false) {
                showMintSuccessModal(qty);
            }
        } catch (error) {
            console.error('Mint error:', error);
            showImcToast('Mint failed. Please try again.', 'error');
        }
    } else {
        showImcToast('Mint system not loaded. Refresh and try again.', 'error');
    }
};

// ============================================
// Show Success Modal with Claim Button
// ============================================
function showMintSuccessModal(qty) {
    const modal = document.createElement('div');
    modal.id = 'imc-mint-modal';
    modal.onclick = function(e) { if (e.target === modal) closeImcModal(); };
    
    modal.innerHTML = `
        <div class="imc-modal-box">
            <div class="imc-modal-header">
                <h3 class="imc-modal-title">✅ Payment Successful!</h3>
                <button class="imc-modal-close" onclick="closeImcModal()">×</button>
            </div>
            
            <div class="imc-modal-body">
                <div class="imc-claim-section">
                    <p style="font-size: 3rem; margin-bottom: 1rem;">🎉</p>
                    <h3 style="color: #fff; margin-bottom: 0.5rem;">Payment Complete!</h3>
                    <p style="color: #888; margin-bottom: 1.5rem;">
                        You have ${qty} NFT${qty > 1 ? 's' : ''} ready to claim.
                    </p>
                    <p style="color: #d4af37; font-size: 0.9rem; margin-bottom: 1.5rem;">
                        Go to your Dashboard to sign and claim your NFT${qty > 1 ? 's' : ''}.
                    </p>
                    <button class="imc-claim-btn" onclick="goToClaim()">
                        📥 Claim Your NFT${qty > 1 ? 's' : ''}
                    </button>
                </div>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
}

// ============================================
// Redirect to Dashboard for Claiming
// ============================================
window.goToClaim = function() {
    closeImcModal();
    // Redirect to trading hub dashboard
    window.location.href = '/trading-hub-dashboard/#claims';
};

// ============================================
// Open Preview Modal
// ============================================
// v524: album mini-player nav (delegated; preload=none — track loads only on play)
    (function(){
        if (window.__imcAlbumNavBound) return; window.__imcAlbumNavBound = true;
        // v525: single-playback — starting any preview pauses all others ('play' uses capture, it doesn't bubble).
        document.addEventListener('play', function(e){
            var t = e.target;
            if (!t || !t.classList || !t.classList.contains('imc-box-media')) return;
            document.querySelectorAll('.imc-box-media').forEach(function(m){ if (m !== t) { try { m.pause(); } catch(_) {} } });
        }, true);
        document.addEventListener('click', function(e){
            var btn = e.target.closest('.imc-album-prev, .imc-album-next');
            if (!btn) return;
            var player = btn.closest('.imc-album-player'); if (!player) return;
            var tracks; try { tracks = JSON.parse(player.dataset.tracks || '[]'); } catch(_) { return; }
            if (!tracks.length) return;
            var audio = player.querySelector('.imc-album-audio'); if (!audio) return;
            var cur = parseInt(player.dataset.cur || '0', 10) || 0;
            cur = btn.classList.contains('imc-album-next') ? (cur + 1) % tracks.length : (cur - 1 + tracks.length) % tracks.length;
            player.dataset.cur = cur;
            audio.src = tracks[cur].u;
            var tt = player.querySelector('.imc-album-ttitle'); if (tt) tt.textContent = tracks[cur].t;
            var tn = player.querySelector('.imc-album-tnum'); if (tn) tn.textContent = (cur + 1);
            var tc = player.querySelector('.imc-album-count'); if (tc) tc.textContent = (cur + 1) + ' / ' + tracks.length;
            audio.play().catch(function(){});
        });
    })();
// v527: custom audio player controller (delegated; works for single + album engines).
    (function(){
        if (window.__imcPlayerBound) return; window.__imcPlayerBound = true;
        function fmt(s){ if(!isFinite(s)||s<0)s=0; var m=Math.floor(s/60),x=Math.floor(s%60); return m+':'+(x<10?'0':'')+x; }
        function upd(a){
            var pl=a.closest('.imc-player'); if(!pl) return;
            var tm=pl.querySelector('.imc-time'), pp=pl.querySelector('.imc-pp'), pr=pl.querySelector('.imc-progress');
            var v=(isFinite(a.duration)&&a.duration>0)?(a.currentTime/a.duration)*100:0;
            if(pr) pr.style.setProperty('--p', v+'%');
            if(tm){ var d=(isFinite(a.duration)&&a.duration>0)?(' / '+fmt(a.duration)):''; tm.textContent=fmt(a.currentTime)+d; }
            if(pp){ pp.innerHTML = a.paused ? '\u25B6' : '\u23F8'; pl.classList.toggle('playing', !a.paused); }
        }
        // play / pause
        document.addEventListener('click', function(e){
            var pp=e.target.closest('.imc-pp'); if(!pp) return;
            // v913+: video cards -- the bar swaps the cover for the <video> (poster = cover) and plays.
            if (pp.classList.contains('imc-video-play')) {
                var box = pp.closest('.imc-drop-box'); if (!box) return;
                var vid = box.querySelector('video.imc-box-video'); var cov = box.querySelector('.imc-box-cover'); var bar = pp.closest('.imc-video-bar');
                if (vid) { if (cov) cov.style.display = 'none'; if (bar) bar.style.display = 'none'; vid.style.display = ''; try { vid.play(); } catch (err) {} }
                return;
            }
            var pl=pp.closest('.imc-player'); if(!pl) return;
            var a=pl.querySelector('.imc-engine'); if(!a) return;
            if(a.paused) a.play().catch(function(){}); else a.pause();
        });
        // seek by pointer on the progress DIV (no <input>, so nothing native can render)
        function seekTo(pr, x){
            var pl=pr.closest('.imc-player'); var a=pl&&pl.querySelector('.imc-engine');
            if(!a||!isFinite(a.duration)||a.duration<=0) return;
            var r=pr.getBoundingClientRect(); var f=Math.min(1,Math.max(0,(x-r.left)/r.width));
            a.currentTime=f*a.duration; pr.style.setProperty('--p',(f*100)+'%');
        }
        var dragging=null;
        document.addEventListener('pointerdown', function(e){
            var pr=e.target.closest('.imc-progress'); if(!pr) return;
            dragging=pr; seekTo(pr, e.clientX); e.preventDefault();
        });
        document.addEventListener('pointermove', function(e){ if(dragging) seekTo(dragging, e.clientX); });
        document.addEventListener('pointerup', function(){ dragging=null; });
        document.addEventListener('pointercancel', function(){ dragging=null; });
        ['timeupdate','play','pause','loadedmetadata','ended','seeked','emptied'].forEach(function(ev){
            document.addEventListener(ev, function(e){
                var a=e.target;
                if(a && a.classList && a.classList.contains('imc-engine')){ if(ev==='ended') a.currentTime=0; upd(a); }
            }, true);
        });
    })();
    window.openPreviewModal = async function(listingId) {
    closeImcModal();
    
    console.log('Opening preview for listing:', listingId);
    
    // Validate listing ID
    if (!listingId || listingId <= 0) {
        showImcToast('No listing available to preview', 'warning');
        console.error('Invalid listing ID:', listingId);
        return;
    }
    
   let listing = window.imcMintState.currentListing;

// Check if we already have this listing with media data
if (listing && listing.id == listingId && listing.media_ipfs) {
    console.log('Using cached listing data');
    showPreviewModal(listing);
    return;
}

// ✅ NEW: Check for server-rendered first listing data
if (window.imcFirstListing && window.imcFirstListing.id == listingId) {
    console.log('Using server-rendered imcFirstListing data:', window.imcFirstListing);
    listing = window.imcFirstListing;
    window.imcMintState.currentListing = listing;
    showPreviewModal(listing);
    return;
}
    
    // Fetch fresh data from API
    try {
        const handler = window.xrplMarketplace?.endpoints?.mintOnDemand || 
            '<?php echo get_stylesheet_directory_uri(); ?>/xrpl-nft-marketplace/backend/mint-on-demand-handler.php';
        
        console.log('Fetching listing from:', handler + '?action=get_listing&listing_id=' + listingId);
        
        const response = await fetch(`${handler}?action=get_listing&listing_id=${listingId}`);
        
        if (!response.ok) {
            throw new Error(`HTTP error: ${response.status} ${response.statusText}`);
        }
        
        const data = await response.json();
        console.log('API response:', data);
        
        if (data.success && data.data && data.data.listing) {
            listing = data.data.listing;
            listing.id = listingId;
            window.imcMintState.currentListing = listing;
            
            // Check if media exists
            if (!listing.media_ipfs) {
                console.warn('Listing has no media_ipfs:', listing);
                showImcToast('No media file uploaded for this NFT', 'warning');
            }
            
            showPreviewModal(listing);
        } else {
            throw new Error(data.error || 'Listing not found');
        }
    } catch (e) {
        console.error('Preview load error:', e);
        showImcToast('Could not load preview: ' + e.message, 'error');
    }
};

// ============================================
// Show Preview Modal with Audio/Video
// ============================================
function showPreviewModal(listing) {
    // Get media URL - use media_ipfs (the actual DB field name)
    // M2-d: an eBook has no audio or video - its media_ipfs is the cover image,
    // so the modal shows the cover alone rather than a player that cannot play.
    const isEbookListing = (listing.nft_type === 'ebook');
    let mediaUrl = '';
    if (listing.media_ipfs) {
        const cid = listing.media_ipfs.replace('ipfs://', '');
        mediaUrl = 'https://<your-pinata-gateway>/ipfs/' + cid;
    } else if (listing.preview_ipfs) {
        mediaUrl = 'https://<your-pinata-gateway>/ipfs/' + listing.preview_ipfs.replace('ipfs://', '');
    } else if (listing.audio_ipfs) {
        mediaUrl = 'https://<your-pinata-gateway>/ipfs/' + listing.audio_ipfs.replace('ipfs://', '');
    }
    // M2-d: clear it for an eBook so every downstream player check sees no media
    // and the modal falls through to the cover alone.
    if (isEbookListing) mediaUrl = '';
    
    // Get cover URL  
    let coverUrl = '/wp-content/uploads/fallback-nft.svg';
    if (listing.cover_ipfs) {
        if (listing.cover_ipfs.startsWith('ipfs://')) {
            // v396: Route through VPS img.php proxy for caching
            coverUrl = 'https://metadata.imcollectibles.io/img.php?url=' + encodeURIComponent(listing.cover_ipfs);
        } else if (listing.cover_ipfs.startsWith('http')) {
            coverUrl = listing.cover_ipfs;
        } else {
            // v465 BUGFIX C: Bare CID fallback. Album mints (handleAlbumMint,
            // mint.js line ~5192) strip the ipfs:// prefix before persisting
            // cover_ipfs, so the two branches above fall through and the
            // modal shows fallback-nft.svg. Wrap the bare CID back into an
            // ipfs:// URI and route through img.php just like the first branch.
            // Catches any future listing that stores a bare CID too.
            coverUrl = 'https://metadata.imcollectibles.io/img.php?url=' + encodeURIComponent('ipfs://' + listing.cover_ipfs);
        }
    }
    
    // v502: Full NFT-type awareness — music | musicvideo | album | art | film.
    // isVideo now also covers film (was musicvideo-only, which forced film
    // previews into the audio branch). Art renders image-only — no player.
    const nftType  = listing.nft_type || 'music';
    const isVideo  = nftType === 'musicvideo' || nftType === 'film';
    const isArt    = nftType === 'art';
    const typeIcon = ({ music: '🎵', musicvideo: '🎬', album: '💿', art: '🎨', film: '🎥' })[nftType] || '🎵';
    const lockText = ({
        music:      'Full track available after purchase',
        musicvideo: 'Full video available after purchase',
        album:      'Full album available after purchase',
        film:       'Full film available after purchase',
        art:        'Full quality masterpiece unlocked after purchase'
    })[nftType] || 'Full track available after purchase';
    const trackName = escHtml(listing.nft_name);
    
    const modal = document.createElement('div');
    modal.id = 'imc-preview-modal';
    modal.onclick = function(e) { if (e.target === modal) closePreviewModal(); };
    
    modal.innerHTML = `
        <div class="imc-modal-box" style="max-width: 500px;">
            <div class="imc-modal-header">
                <h3 class="imc-modal-title">${typeIcon} ${trackName}</h3>
                <button class="imc-modal-close" onclick="closePreviewModal()">×</button>
            </div>
            
            <div class="imc-modal-body">
                <div class="imc-media-container">
                    ${isVideo && mediaUrl ? `
                        <video controls autoplay style="width: 100%; border-radius: 8px;">
                            <source src="${mediaUrl}" type="video/mp4">
                            Your browser does not support video.
                        </video>
                    ` : isArt ? `
                        <div class="imc-audio-preview">
                            <img src="${coverUrl}" alt="Artwork"
                                 onerror="this.src='/wp-content/uploads/fallback-nft.svg'">
                        </div>
                    ` : mediaUrl ? `
                        <div class="imc-audio-preview">
                            <img src="${coverUrl}" alt="Cover" 
                                 onerror="this.src='/wp-content/uploads/fallback-nft.svg'">
                            <audio controls autoplay style="width: 100%;">
                                <source src="${mediaUrl}" type="audio/mpeg">
                                Your browser does not support audio.
                            </audio>
                        </div>
                    ` : `
                        <div class="imc-audio-preview">
                            <img src="${coverUrl}" alt="Cover"
                                 onerror="this.src='/wp-content/uploads/fallback-nft.svg'">
                            <p style="color: #888; margin-top: 1rem; text-align: center;">No preview available</p>
                        </div>
                    `}
                </div>
                
                <p class="imc-preview-note">🔒 ${lockText}</p>
                
                ${(() => {
                    // v248: Lock mint button if listing is scheduled and not yet live
                    // v251: Early access allowlisted wallets bypass this lock
                    const isScheduledPreview = listing.launch_type === 'scheduled' && listing.launch_at
                        && new Date(listing.launch_at.includes('Z') ? listing.launch_at : listing.launch_at + 'Z') > new Date()
                        && !window.imcEarlyAccessGranted;
                    if (isScheduledPreview) {
                        return `<button class="imc-btn-mint" style="width:100%;opacity:0.5;cursor:not-allowed;filter:grayscale(0.4);" disabled>
                            🔒 Minting Not Yet Live
                        </button>`;
                    }
                    return `<button class="imc-btn-mint" style="width: 100%;"
                            onclick="closePreviewModal(); mintFromListing(${listing.id});">
                        🛒 Mint This NFT
                    </button>`;
                })()}
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
}

// ============================================
// Close Modals
// ============================================
window.closeImcModal = function() {
    const modal = document.getElementById('imc-mint-modal');
    if (modal) modal.remove();
};

window.closePreviewModal = function() {
    const modal = document.getElementById('imc-preview-modal');
    if (modal) {
        const audio = modal.querySelector('audio');
        const video = modal.querySelector('video');
        if (audio) audio.pause();
        if (video) video.pause();
        modal.remove();
    }
};

// ============================================
// Utility Functions
// ============================================
function escHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function getCookieValue(name) {
    // v281: Use regex to find the cookie value — immune to duplicate cookie names.
    // The old split("; name=") approach failed when the same cookie was set multiple
    // times with different domain scopes (e.g. "imcollectibles.io" AND ".imcollectibles.io"),
    // producing 3+ parts and causing parts.length === 2 to always return null.
    const match = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');
    return match ? decodeURIComponent(match[2]) : null;
}

// v281: Deduplicate xrpl_account cookies on every page load.
// Browsers can accumulate multiple cookies with the same name when Set-Cookie is sent
// with different domain scopes (example.com vs .example.com). Duplicates break any
// cookie reader that expects exactly one occurrence (split-based pattern).
// This runs once per page load: reads the canonical value, clears all variants,
// then writes exactly ONE canonical cookie with domain=.imcollectibles.io.
(function deduplicateXrplCookie() {
    var name = 'xrpl_account';
    var m = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');
    var val = m ? decodeURIComponent(m[2]) : null;
    if (!val || !/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/.test(val)) return;

    // Count occurrences — if more than 1, we have duplicates
    var count = (document.cookie.match(new RegExp('(?:^|;)\\s*' + name + '\\s*=', 'g')) || []).length;
    if (count <= 1) return; // Already clean

    // Clear all variants
    var expires = 'expires=Thu, 01 Jan 1970 00:00:00 GMT';
    document.cookie = name + '=; ' + expires + '; path=/';
    document.cookie = name + '=; ' + expires + '; path=/; domain=imcollectibles.io';
    document.cookie = name + '=; ' + expires + '; path=/; domain=.imcollectibles.io';

    // Write exactly one canonical cookie
    var maxAge = 'max-age=' + (86400 * 30);
    document.cookie = name + '=' + encodeURIComponent(val) + '; path=/; domain=.imcollectibles.io; ' + maxAge + '; secure; SameSite=Lax';
    console.log('[IMC] Deduplicated ' + count + ' xrpl_account cookies → 1 canonical');
})();

function showImcToast(message, type = 'info') {
    let container = document.getElementById('imc-toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'imc-toast-container';
        container.style.cssText = 'position: fixed; top: 80px; right: 20px; z-index: 1000000;';
        document.body.appendChild(container);
    }
    
    const colors = {
        success: '#d4af37',
        error: '#ef4444',
        warning: '#f59e0b',
        info: '#3b82f6'
    };
    
    const icons = {
        success: '✓',
        error: '✕',
        warning: '⚠',
        info: 'ℹ'
    };
    
    const toast = document.createElement('div');
    toast.style.cssText = `
        background: ${colors[type] || colors.info};
        color: ${type === 'success' ? '#000' : '#fff'};
        padding: 1rem 1.25rem;
        border-radius: 8px;
        margin-bottom: 0.5rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-weight: 600;
        box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        animation: imcToastIn 0.3s ease-out;
    `;
    toast.innerHTML = `<span style="font-size: 1.25rem;">${icons[type] || icons.info}</span><span>${message}</span>`;
    
    container.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'imcToastOut 0.3s ease-in forwards';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

// Add toast animations
const toastStyle = document.createElement('style');
toastStyle.textContent = `
    @keyframes imcToastIn {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    @keyframes imcToastOut {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(100%); opacity: 0; }
    }
`;
document.head.appendChild(toastStyle);

console.log('✅ IMC Phase 4: Production-ready collection page system loaded');
</script>

<!-- v450: Auto-detect long descriptions and show "Read more" toggle -->
<script>
(function() {
    var desc = document.getElementById('collection-desc');
    var toggle = document.getElementById('collection-desc-toggle');
    if (!desc || !toggle) return;
    // If the text overflows the 4-line clamp, show the toggle button
    if (desc.scrollHeight > desc.clientHeight + 2) {
        toggle.style.display = 'inline-block';
    } else {
        // Description fits within 4 lines — remove clamp and hide toggle
        desc.classList.remove('is-clamped');
    }
})();
</script>

<?php if ($mint_status && $mint_status['is_scheduled'] && $mint_status['launch_at']): ?>
<!-- v234: Scheduled mint countdown ticker -->
<script>
(function() {
    var el = document.getElementById('mint-countdown');
    if (!el) return;
    var launchUtc = el.dataset.launch; // e.g. "2026-03-06 14:00:00" (stored as UTC in DB)
    if (!launchUtc) return;
    // DB stores UTC — ensure JS parses it as UTC (replace space with T and append Z)
    var launchMs = new Date(launchUtc.replace(' ', 'T') + 'Z').getTime();

    function pad(n) { return String(n).padStart(2, '0'); }

    function tick() {
        var now = Date.now();
        var diff = launchMs - now;
        if (diff <= 0) {
            // Launch time has passed — reload page so PHP renders live state
            window.location.reload();
            return;
        }
        var totalSecs = Math.floor(diff / 1000);
        var days  = Math.floor(totalSecs / 86400);
        var hours = Math.floor((totalSecs % 86400) / 3600);
        var mins  = Math.floor((totalSecs % 3600) / 60);
        var secs  = totalSecs % 60;
        document.getElementById('mcd-d').textContent = pad(days);
        document.getElementById('mcd-h').textContent = pad(hours);
        document.getElementById('mcd-m').textContent = pad(mins);
        document.getElementById('mcd-s').textContent = pad(secs);
    }

    tick();
    setInterval(tick, 1000);
})();
</script>
<?php endif; ?>

<?php if ($mint_status && $mint_status['has_open_edition'] && !empty($mint_status['open_edition_ends_at'])): ?>
<!-- v393: Open Edition mint-closes-in countdown ticker -->
<!-- v476: Replaced infinite reload-on-zero with in-place terminal state update.
     Combined with backend lazy auto-close in $mint_status build (line ~1418),
     this prevents the reload loop that occurred when an OE crossed its end time
     while a user had the page open. -->
<script>
(function() {
    var el = document.getElementById('mint-oe-countdown');
    if (!el) return;
    var endsUtc = el.dataset.ends;
    if (!endsUtc) return;
    var endsMs = new Date(endsUtc.replace(' ', 'T') + 'Z').getTime();

    function pad(n) { return String(n).padStart(2, '0'); }

    var oeInterval = null;

    function showClosedState() {
        // Show zeroed countdown + label swap. No reload — backend lazy-close has
        // already updated DB state by the time the user next loads the page.
        var d = document.getElementById('oe-d');
        var h = document.getElementById('oe-h');
        var m = document.getElementById('oe-m');
        var s = document.getElementById('oe-s');
        if (d) d.textContent = '00';
        if (h) h.textContent = '00';
        if (m) m.textContent = '00';
        if (s) s.textContent = '00';
        var label = el.previousElementSibling;
        if (label && label.classList.contains('mint-countdown-label')) {
            label.textContent = 'Mint has closed';
        }
        if (oeInterval) {
            clearInterval(oeInterval);
            oeInterval = null;
        }
    }

    function tick() {
        var now = Date.now();
        var diff = endsMs - now;
        if (diff <= 0) {
            showClosedState();
            return;
        }
        var totalSecs = Math.floor(diff / 1000);
        var days  = Math.floor(totalSecs / 86400);
        var hours = Math.floor((totalSecs % 86400) / 3600);
        var mins  = Math.floor((totalSecs % 3600) / 60);
        var secs  = totalSecs % 60;
        document.getElementById('oe-d').textContent = pad(days);
        document.getElementById('oe-h').textContent = pad(hours);
        document.getElementById('oe-m').textContent = pad(mins);
        document.getElementById('oe-s').textContent = pad(secs);
    }

    tick();
    // Only start the interval if the mint hasn't already closed at first tick —
    // saves a redundant interval that would just call showClosedState() again.
    if (Date.now() < endsMs) {
        oeInterval = setInterval(tick, 1000);
    }
})();
</script>
<?php endif; ?>

<?php if ($mint_status && $mint_status['is_scheduled'] && $mint_status['launch_at']): ?>
<!-- v251: Early Access Allowlist Checker — runs on scheduled pages to unlock mint for eligible wallets -->
<script>
(function() {
    // Only run if user has a wallet connected
    function getCookie(name) {
        const m = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');
        return m ? decodeURIComponent(m[2]) : '';
    }
    const account = getCookie('xrpl_account');
    if (!account) return;

    const listing = window.imcFirstListing;
    if (!listing || !listing.id) return;

    const launchUtc = '<?php echo esc_js($mint_status['launch_at']); ?>';
    const launchMs  = new Date(launchUtc.replace(' ', 'T') + 'Z').getTime();

    const allowlistUrl = '/wp-content/themes/astra/xrpl-nft-marketplace/backend/allowlist-handler.php';

    fetch(`${allowlistUrl}?action=check_wallet&listing_id=${listing.id}&wallet=${encodeURIComponent(account)}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            const b = data.data;
            if (!b.can_early_access) return;

            // Check if we're inside the early access window
            const windowMs = (b.early_access_hours || 24) * 3600 * 1000;
            const now = Date.now();
            if (now < launchMs - windowMs) return; // Too early even for early access
            if (now >= launchMs) return;            // Already past launch — reload handles this

            // ✅ Early access granted — store globally so preview modal and card renders can use it
            window.imcEarlyAccessGranted = true;

            // --- 3a: Fix the banner button ---
            const disabledBtn = document.querySelector('.mint-banner-btn-disabled');
            if (disabledBtn) {
                const parent = disabledBtn.parentElement;
                const activeBtn = document.createElement('button');
                activeBtn.className = 'mint-banner-btn';
                activeBtn.innerHTML = '🛒 Mint Now';
                activeBtn.onclick = function() { window.mintFromListing(listing.id); };
                parent.replaceChild(activeBtn, disabledBtn);

                // Add an early access badge above the button
                const badge = document.createElement('div');
                badge.style.cssText = 'color:#4ade80;font-size:0.85rem;font-weight:600;margin-bottom:0.5rem;text-align:center;';
                badge.textContent = '🟢 Early Access Active';
                parent.insertBefore(badge, activeBtn);
            }

            // --- 3b: Fix listing grid card buttons (JS-rendered) ---
            // Cards are rendered async; poll briefly until they appear
            let attempts = 0;
            const cardPoll = setInterval(function() {
                attempts++;
                const disabledCards = document.querySelectorAll('.mod-listing-card .mod-btn-disabled');
                if (disabledCards.length > 0) {
                    disabledCards.forEach(function(btn) {
                        const card = btn.closest('.mod-listing-card');
                        const cardListingId = card ? card.dataset.listingId : listing.id;
                        btn.disabled = false;
                        btn.classList.remove('mod-btn-disabled');
                        btn.textContent = '🛒 Mint';
                        btn.onclick = function() { window.mintFromListing(parseInt(cardListingId, 10)); };
                    });
                    clearInterval(cardPoll);
                }
                if (attempts > 20) clearInterval(cardPoll); // Give up after 10s
            }, 500);
        })
        .catch(function() {}); // Silent fail — never block the page
})();
</script>
<?php endif; ?>

<?php if ($mint_status && !empty($mint_status['is_imc_collection']) && !empty($mint_status['listings'])): ?>
<!-- v505 Phase B: Collection Breakdown modal — per-drop history (server-rendered) -->
<div id="imc-breakdown-modal" class="imc-breakdown-overlay" style="display:none;" onclick="if(event.target===this)imcToggleBreakdown(false)">
    <div class="imc-breakdown-panel">
        <div class="imc-breakdown-head">
            <h3 style="margin:0;">📊 Collection Breakdown</h3>
            <button class="imc-breakdown-close" onclick="imcToggleBreakdown(false)" aria-label="Close">✕</button>
        </div>
        <table class="imc-breakdown-table">
            <thead><tr><th>Drop</th><th>Minted</th><th>Window</th><th>Status</th></tr></thead>
            <tbody>
            <?php
            $imc_bd_sum = 0;
            // v509: zero-dates ('0000-00-00') parse to year -0001 — treat anything pre-2000 as unknown
            $imc_bd_fmt = function ($s) { $ts = $s ? strtotime($s) : false; return ($ts && $ts > 946684800) ? date('j M Y', $ts) : ''; };
            foreach ($mint_status['listings'] as $bd):
                $bd_oe     = ($bd['edition_type'] ?? '') === 'open';
                $bd_minted = intval($bd['minted_count'] ?? 0);
                $imc_bd_sum += $bd_minted;
                $bd_tot    = intval($bd['total_editions'] ?? 0);
                $bd_sched  = ($bd['launch_type'] ?? '') === 'scheduled' && !empty($bd['launch_at']) && strtotime($bd['launch_at']) > time();
                $bd_status = ($bd['status'] === 'sold_out') ? ($bd_oe ? 'Closed' : 'Sold Out') : ($bd_sched ? 'Scheduled' : 'LIVE');
                $bd_start  = !empty($bd['launch_at']) ? $bd['launch_at'] : ($bd['created_at'] ?? '');
                $bd_end    = $bd_oe ? (!empty($bd['open_edition_closed_at']) ? $bd['open_edition_closed_at'] : ($bd['open_edition_ends_at'] ?? '')) : '';
            ?>
            <tr>
                <td><?php echo esc_html($bd['nft_name'] ?? 'NFT'); ?></td>
                <td><?php echo $bd_minted; echo $bd_oe ? '' : ' / ' . $bd_tot; ?></td>
                <td><?php
                    $bd_s = $imc_bd_fmt($bd_start);
                    $bd_e = $bd_end ? $imc_bd_fmt($bd_end) : ($bd_status === 'LIVE' ? 'now' : '');
                    if ($bd_s && $bd_e)  { echo esc_html($bd_s . ' → ' . $bd_e); }
                    elseif ($bd_e)       { echo esc_html('→ ' . $bd_e); }
                    elseif ($bd_s)       { echo esc_html($bd_s . ' →'); }
                    else                 { echo '—'; }
                ?></td>
                <td><?php echo esc_html($bd_status); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr><td><strong>Total</strong></td><td colspan="3"><strong><?php echo $imc_bd_sum; ?> minted across <?php echo count($mint_status['listings']); ?> drop<?php echo count($mint_status['listings']) === 1 ? '' : 's'; ?></strong></td></tr>
            </tfoot>
        </table>
    </div>
</div>
<script>
function imcToggleBreakdown(show) {
    var m = document.getElementById('imc-breakdown-modal');
    if (m) m.style.display = show ? 'flex' : 'none';
}
</script>

<!-- v505 Phase B: Per-box countdown ticker — one ticker, every .imc-box-countdown.
     OE close (data-ends): v476-style IN-PLACE terminal state, no reload loop.
     Scheduled launch (data-launch): one-time reload at zero (mirrors v234 reveal),
     guarded via sessionStorage so clock skew can never cause a reload loop. -->
<script>
(function () {
    var els = document.querySelectorAll('.imc-box-countdown');
    if (!els.length) return;
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function tick() {
        els.forEach(function (el) {
            if (el.dataset.done) return;
            var isLaunch = !!el.dataset.launch;
            var raw = el.dataset.ends || el.dataset.launch;
            if (!raw) { el.dataset.done = '1'; return; }
            var target = new Date(raw.replace(' ', 'T') + 'Z').getTime();
            if (isNaN(target)) { el.dataset.done = '1'; return; }
            var diff = target - Date.now();
            if (diff <= 0) {
                el.dataset.done = '1';
                if (isLaunch) {
                    var guard = 'imcLaunchReload_' + raw;
                    if (!sessionStorage.getItem(guard)) {
                        sessionStorage.setItem(guard, '1');
                        location.reload();
                    }
                    return;
                }
                // v476-style terminal state, scoped to THIS box only
                var box = el.closest('.imc-drop-box');
                if (box) {
                    var badge = box.querySelector('.mint-badge');
                    if (badge) { badge.className = 'mint-badge mint-badge-sold-out'; badge.textContent = '🔒 MINT CLOSED'; }
                    var mbtn = box.querySelector('.mint-banner-btn:not(.mint-banner-btn-disabled)');
                    if (mbtn) { mbtn.disabled = true; mbtn.classList.add('mint-banner-btn-disabled'); mbtn.textContent = '🔒 Mint Closed'; }
                    el.style.opacity = '0.45';
                }
                return;
            }
            var s = Math.floor(diff / 1000);
            var v = { d: Math.floor(s / 86400), h: Math.floor((s % 86400) / 3600), m: Math.floor((s % 3600) / 60), s: s % 60 };
            ['d', 'h', 'm', 's'].forEach(function (u) {
                var n = el.querySelector('[data-u="' + u + '"]');
                if (n) n.textContent = pad(v[u]);
            });

            /* CP-U (14 Sep 2026): show the two most significant MEANINGFUL units.
               The rail CSS used to hide everything past the 3rd child - days and
               hours only, "minutes/seconds keep ticking, just hidden" - which is
               fine at 2D 05H and useless under an hour, where it read 00D 00H and
               looked like the drop was already open.
               Which units matter changes every second, so CSS position cannot
               decide it; the tick does, and the CSS rule that hid by position is
               gone. Sequence: 2D 05H -> 05H 12M -> 12M 30S -> 45S. */
            var order = ['d', 'h', 'm', 's'];
            var first = order.findIndex(function (u) { return v[u] > 0; });
            if (first === -1) first = 3;                      // under a minute: seconds only
            var show = (first === 3) ? ['s'] : [order[first], order[first + 1]];
            /* ⚠ A CLASS, NOT AN INLINE STYLE. `.mint-countdown-unit` carries
               `display:flex !important` (L4782), and an !important stylesheet
               declaration outranks an inline style - so `unit.style.display='none'`
               was silently ignored and 00D 00H kept showing. The class below is
               matched by a rule with !important of its own, which CAN win. */
            order.forEach(function (u) {
                var n = el.querySelector('[data-u="' + u + '"]');
                if (!n) return;
                var unit = n.parentNode;
                unit.classList.toggle('imc-cd-hide', show.indexOf(u) === -1);
                var sep = unit.nextElementSibling;
                if (sep && sep.classList.contains('mint-countdown-sep')) {
                    sep.classList.toggle('imc-cd-hide', !(u === show[0] && show.length > 1));
                }
            });

            /* Final hour: the box turns urgent. Class on the countdown itself so
               the styling stays with the element the creator is looking at. */
            el.classList.toggle('is-urgent', s < 3600);
        });
    }
    tick();
    setInterval(tick, 1000);
})();
</script>

<!-- v505 Phase B: Per-box early access — each locked scheduled box checks its OWN
     listing's allowlist (same endpoint + window rules as the v251 checker, which is
     left in place and self-no-ops against the rail). -->
<script>
(function () {
    function getCookie(name) {
        var m = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');
        return m ? decodeURIComponent(m[2]) : '';
    }
    var account = getCookie('xrpl_account');
    if (!account) return;
    var locked = document.querySelectorAll('.imc-box-btn-locked[data-listing-id]');
    if (!locked.length) return;
    var allowlistUrl = '/wp-content/themes/astra/xrpl-nft-marketplace/backend/allowlist-handler.php';
    locked.forEach(function (btn) {
        var lid = btn.dataset.listingId;
        var launchUtc = btn.dataset.launch || '';
        var launchMs = launchUtc ? new Date(launchUtc.replace(' ', 'T') + 'Z').getTime() : 0;
        fetch(allowlistUrl + '?action=check_wallet&listing_id=' + encodeURIComponent(lid) + '&wallet=' + encodeURIComponent(account))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) return;
                var b = data.data;
                if (!b.can_early_access) return;
                var windowMs = (b.early_access_hours || 24) * 3600 * 1000;
                var now = Date.now();
                if (launchMs && (now < launchMs - windowMs || now >= launchMs)) return;
                window.imcEarlyAccessGranted = true;
                btn.disabled = false;
                btn.classList.remove('mint-banner-btn-disabled', 'imc-box-btn-locked');
                btn.innerHTML = '🛒 Mint Now';
                btn.onclick = function () { window.mintFromListing(parseInt(lid, 10)); };
                var badge = document.createElement('div');
                badge.style.cssText = 'color:#4ade80;font-size:0.85rem;font-weight:600;margin:0.4rem 0;text-align:center;';
                badge.textContent = '🟢 Early Access Active';
                btn.parentElement.insertBefore(badge, btn);
            })
            .catch(function () {});
    });
})();
</script>
<?php endif; ?>

<!-- v633 Step6 Surface3: AI badge on collection mint boxes (.mod-listing-card) -->
<style>.mod-listing-cover{position:relative;}.imc-box-cover{position:relative;}</style>
<script>
(function imcAiBadgeInit(){
    if (window.__imcAiBadgeInit) return;
    window.__imcAiBadgeInit = true;
    if (!document.getElementById('imc-ai-badge-css')) {
        const st = document.createElement('style');
        st.id = 'imc-ai-badge-css';
        st.textContent =
            '.imc-ai-badge{position:absolute;top:8px;right:8px;z-index:4;display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:800;line-height:1;cursor:help;background:linear-gradient(180deg,#7b5cff,#4a2fd0);color:#fff;box-shadow:0 1px 4px rgba(0,0,0,.4);}' +
            '.imc-ai-badge:hover{filter:brightness(1.08);}' +
            '.imc-ai-tip{position:absolute;z-index:99999;max-width:240px;background:#15151f;border:1px solid #2b2b3d;border-radius:12px;padding:12px 14px;color:#fff;box-shadow:0 10px 30px rgba(0,0,0,.5);font-size:.85rem;pointer-events:none;}' +
            '.imc-ai-tip h4{margin:0 0 4px;font-size:.92rem;color:#b9a6ff;}' +
            '.imc-ai-tip p{margin:0;color:#cfd2dc;line-height:1.4;}' +
            '.imc-ai-tip .tool{margin-top:6px;font-size:.78rem;color:#9aa0aa;}' +
            '.imc-ai-tip .tool b{color:#d6ba66;font-weight:700;}';
        document.head.appendChild(st);
    }
    function hide(){ const t=document.getElementById('imc-ai-tip'); if(t) t.remove(); }
    function show(badge){
        if (document.getElementById('imc-ai-tip')) return;
        const full = (badge.dataset.mode === 'full');
        const platform = badge.dataset.platform || '';
        const tip = document.createElement('div');
        tip.className='imc-ai-tip'; tip.id='imc-ai-tip';
        const h=document.createElement('h4'); h.textContent = full ? '100% AI-Generated' : 'AI-Assisted';
        const pp=document.createElement('p'); pp.textContent = full
            ? 'This artwork was generated entirely using AI.'
            : 'This artwork was created using AI together with human elements.';
        tip.appendChild(h); tip.appendChild(pp);
        if (platform){ const tl=document.createElement('div'); tl.className='tool'; tl.textContent='Tool: '; const bb=document.createElement('b'); bb.textContent=platform; tl.appendChild(bb); tip.appendChild(tl); }
        document.body.appendChild(tip);
        const r=badge.getBoundingClientRect(); const tw=tip.offsetWidth||240;
        tip.style.top=(window.scrollY + r.bottom + 8)+'px';
        tip.style.left=(window.scrollX + Math.max(8, r.right - tw))+'px';
    }
    // Desktop hover
    document.addEventListener('mouseover', function(e){ const b=e.target.closest && e.target.closest('.imc-ai-badge'); if (b) show(b); });
    document.addEventListener('mouseout',  function(e){ const b=e.target.closest && e.target.closest('.imc-ai-badge'); if (b) hide(); });
    // Block card navigation on the badge; toggle tip on touch
    function block(e){
        const b=e.target.closest && e.target.closest('.imc-ai-badge');
        if (!b) return;
        e.stopPropagation(); e.preventDefault();
        if (e.type === 'pointerup'){ if (document.getElementById('imc-ai-tip')) hide(); else show(b); }
    }
    document.addEventListener('pointerdown', block, true);
    document.addEventListener('pointerup',   block, true);
    document.addEventListener('click',       block, true);
    // Dismiss touch tip when tapping elsewhere or scrolling
    document.addEventListener('pointerup', function(e){ if (e.target.closest && e.target.closest('.imc-ai-badge')) return; hide(); });
    window.addEventListener('scroll', hide, { passive:true });
})();
</script>
<script>
/* M1-f3b: drop-box cover flip. Delegated, so boxes rendered later are covered too. */
document.addEventListener('click', function (e) {
    var btn = e.target && e.target.closest ? e.target.closest('.imc-box-flip') : null;
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    var wrap = btn.closest('.imc-box-cover');
    if (!wrap) return;
    var front = wrap.querySelector('.imc-box-front');
    var back  = wrap.querySelector('.imc-box-back');
    if (!front || !back) return;
    var showBack = (back.style.display === 'none');
    back.style.display  = showBack ? '' : 'none';
    front.style.display = showBack ? 'none' : '';
    btn.textContent = showBack ? 'Front' : 'Back';
});
</script>

<?php if (function_exists('imc_xrplto_attribution')) imc_xrplto_attribution(); ?>
<?php get_footer(); ?>