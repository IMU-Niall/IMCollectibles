<?php
/**
 * Template Name: My NFTs
 * File: page-my-nfts.php
 * Path: /wp-content/themes/astra/page-templates/page-my-nfts.php
 * 
 * Description: Shows all NFTs owned by the connected user, grouped by collection.
 * Styled to match trading-hub pages with IMUTV brand theme.
 * 
 * Flow:
 * 1. User lands on page → sees login prompt if not connected
 * 2. Connected → sees collection cards with owned counts
 * 3. Click collection → expands to show owned NFTs
 * 4. Click NFT → goes to single NFT page (where they can list for sale)
 */

get_header();

// Get connected account from cookie
$xrpl_account = function_exists('imc_session_wallet') ? imc_session_wallet() : '';
$nonce = wp_create_nonce('xrpl_marketplace_nonce');

// Collection definitions - All marketplace collections
$collections = [
    'guardians' => [
        'key' => 'guardians',
        'name' => 'Guardians of the Frequencies',
        'short_name' => 'Guardians',
        'description' => 'The debut NFT collection. 29 unique Guardians.',
        'issuer' => 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR',
        'taxon' => 0,
        'slug' => 'guardians',
        'fallback' => 'https://images.imcollectibles.io/guardians/guardian-1.png'
    ],
    'frequencies' => [
        'key' => 'frequencies',
        'name' => 'Protectors of the Frequencies',
        'short_name' => 'Frequencies',
        'description' => 'An expanding universe defending independent music.',
        'issuer' => 'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga',
        'taxon' => 717825,
        'slug' => 'frequencies',
        'fallback' => 'https://images.imcollectibles.io/frequencies/protector-1.png'
    ],
    'ledger' => [
        'key' => 'ledger',
        'name' => 'Protectors of the Ledger',
        'short_name' => 'Ledger',
        'description' => 'Elite intergalactic force safeguarding the XRPL.',
        'issuer' => 'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt',
        'taxon' => 1056369418,
        'slug' => 'ledger',
        'fallback' => 'https://images.imcollectibles.io/ledger/protector-1.png'
    ],
    'lasvegas' => [
        'key' => 'lasvegas',
        'name' => 'Protectors of Las Vegas',
        'short_name' => 'Las Vegas',
        'description' => 'Vegas-themed Protectors hitting the strip.',
        'issuer' => 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR',
        'taxon' => 777,
        'slug' => 'lasvegas',
        'fallback' => 'https://imcollectibles.io/wp-content/uploads/lasvegas-fallback.jpg'
    ],
    'firepit' => [
        'key' => 'firepit',
        'name' => 'Firepit Protectors',
        'short_name' => 'Firepit',
        'description' => 'Exclusive Firepit community collection.',
        'issuer' => 'rPSHTgpjS1BUmEWPYB7QwPkhPrm3ihJ5cR',
        'taxon' => 666,
        'slug' => 'firepit',
        'fallback' => 'https://imcollectibles.io/wp-content/uploads/2025/04/firepit-logo.png'
    ],
    'special' => [
        'key' => 'special',
        'name' => 'Special Edition',
        'short_name' => 'Special',
        'description' => 'Limited edition and collaboration pieces.',
        'issuer' => 'rHsrif6nHTkmyh38W7JmYjairPWhq5P3AH',
        'taxon' => 0,
        'slug' => 'special-edition',
        'fallback' => 'https://imcollectibles.io/wp-content/uploads/2025/03/IMU-Special.png'
    ]
];

// Get proper collection images using imu_get_collection_image() - SAME AS trading-hub.php
foreach ($collections as $key => &$col) {
    if (function_exists('imu_get_collection_image')) {
        $col['image'] = imu_get_collection_image($col['issuer'], $col['taxon'], $col['fallback']);
    } else {
        $col['image'] = $col['fallback'];
    }
}
unset($col); // CRITICAL: Break reference to prevent last element being overwritten

// JSON encode collections for JavaScript
$collections_json = wp_json_encode($collections);

// Endpoints for JavaScript
$endpoints_json = wp_json_encode([
    'xummProxy'          => home_url('/xumm-proxy.php'),
    'offerHandler'       => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/offer-handler.php',
    'myNftsHandler'      => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/my-nfts-handler.php',
    'filterHandler'      => get_stylesheet_directory_uri() . '/xrpl-nft-marketplace/backend/filter-handler.php',
]);
?>

<div id="my-nfts-page" class="trading-hub-container marketplace-page"
     data-account="<?php echo esc_attr($xrpl_account); ?>"
     data-nonce="<?php echo esc_attr($nonce); ?>"
     data-endpoints='<?php echo $endpoints_json; ?>'
     data-collections='<?php echo esc_attr($collections_json); ?>'>

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
        <a href="<?php echo esc_url(home_url('/tasks/')); ?>" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            Tasks
        </a>
        <a href="<?php echo esc_url(home_url('/burn-to-earn/')); ?>" class="nav-link">🔥 Burn to Earn</a>
        <a href="<?php echo esc_url(home_url('/trading-hub-dashboard/')); ?>" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
            Dashboard
        </a>
        <a href="<?php echo esc_url(home_url('/my-nfts/')); ?>" class="nav-link active">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg>
            My NFTs
        </a>
    </nav>

    <!-- Page Header -->
    <header class="marketplace-header my-nfts-header">
        <h1>🖼️ My NFTs</h1>
        <p class="header-subtitle">Your collected NFTs across all marketplace collections</p>
        
        <?php if ($xrpl_account): ?>
            <div class="my-nfts-stats" id="my-nfts-stats">
                <div class="stat-item">
                    <span class="stat-value" id="total-nfts-count">-</span>
                    <span class="stat-label">Total NFTs</span>
                </div>
                <div class="stat-item">
                    <span class="stat-value" id="collections-count">-</span>
                    <span class="stat-label">Collections</span>
                </div>
            </div>
        <?php endif; ?>
    </header>

    <!-- Main Content -->
    <main class="my-nfts-content">
        <?php if (!$xrpl_account): ?>
            <!-- Not Connected State -->
            <div class="not-connected-state">
                <div class="connect-prompt-card">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="prompt-icon">
                        <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                        <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
                        <line x1="12" y1="22.08" x2="12" y2="12"></line>
                    </svg>
                    <h2>Connect to View Your NFTs</h2>
                    <p>Sign in with your Xaman wallet to see your NFT collection.</p>
                    <button id="connect-wallet-btn" class="btn btn-primary btn-lg">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 20px; height: 20px; margin-right: 8px;">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                        </svg>
                        Connect with Xaman
                    </button>
                </div>
            </div>
        <?php else: ?>
            <!-- IMU Collections Section -->
            <!-- v285: Access NFTs filter bar -->
            <div class="my-nfts-filter-bar" id="my-nfts-filter-bar">
                <button class="my-nfts-filter-tab" data-filter="all" onclick="setNftFilter('all')">
                    🌐 All NFTs <span class="tab-count" id="tab-count-all">0</span>
                </button>
                <button class="my-nfts-filter-tab active" data-filter="access" onclick="setNftFilter('access')">
                    🎟 Access NFTs <span class="tab-count" id="tab-count-access">0</span>
                </button>
                <button class="my-nfts-filter-tab" data-filter="imu" onclick="setNftFilter('imu')" style="display:none;">
                    🛡️ IMU Collections <span class="tab-count" id="tab-count-imu">0</span>
                </button>
                <button class="my-nfts-filter-tab" data-filter="other" onclick="setNftFilter('other')" style="display:none;">
                    🔮 Other <span class="tab-count" id="tab-count-other">0</span>
                </button>
            </div>

            <!-- v305: Access NFTs — collection card view, then drill-down to individual NFTs -->
            <section id="access-section" class="collections-section" style="display:none;">
                <!-- v534/P6C: Access NFT type sub-filters -->
                <div class="access-type-filter-bar" id="access-type-filter-bar">
                    <button class="access-type-tab active" data-type="all" onclick="setAccessType('all')">All</button>
                    <button class="access-type-tab" data-type="art" onclick="setAccessType('art')">🎨 Art</button>
                    <button class="access-type-tab" data-type="music" onclick="setAccessType('music')">🎵 Music</button>
                    <button class="access-type-tab" data-type="musicvideo" onclick="setAccessType('musicvideo')">🎬 Music Video</button>
                    <button class="access-type-tab" data-type="album" onclick="setAccessType('album')">💿 Album</button>
                    <button class="access-type-tab" data-type="film" onclick="setAccessType('film')">🎥 Film</button>
                </div>
                <!-- Collection card grid (default view when Access tab selected) -->
                <div id="access-collections-grid" class="my-collections-grid">
                    <div class="section-loading"><div class="loading-spinner"></div><p>Loading your Access NFTs…</p></div>
                </div>
                <!-- Expanded individual NFT view for a single listing -->
                <div id="access-expanded-view" class="expanded-collection-view" style="display:none;">
                    <button id="access-back-btn" class="back-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M19 12H5M12 19l-7-7 7-7"/>
                        </svg>
                        Back to Access Collections
                    </button>
                    <h2 id="access-expanded-title" class="expanded-title"></h2>
                    <div id="access-expanded-grid" class="nft-grid"></div>
                </div>
            </section>

            <!-- v335 Phase 4A: Unified collections grid — IMU first, then other, images before broken -->
            <section id="imu-section" class="collections-section">
                <div id="my-collections-grid" class="my-collections-grid">
                    <div class="loading-state">
                        <div class="loading-spinner"></div>
                        <p>Loading your NFTs from the XRPL ledger...</p>
                    </div>
                </div>
            </section>
            
            <!-- Hidden stub kept for JS compatibility (otherSection references) -->
            <section id="other-section" class="collections-section" style="display:none;">
                <div id="other-collections-grid" class="my-collections-grid"></div>
            </section>
            
            <!-- Expanded Collection View (hidden by default) -->
            <div id="expanded-collection-view" class="expanded-collection-view" style="display: none;">
                <button id="back-to-collections" class="back-btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M19 12H5M12 19l-7-7 7-7"/>
                    </svg>
                    Back to Collections
                </button>
                <h2 id="expanded-collection-title" class="expanded-title"></h2>
                <div id="expanded-nft-grid" class="nft-grid">
                    <!-- NFTs will be loaded here -->
                </div>
            </div>
            
            <!-- Empty State (shown when no NFTs) -->
            <div id="empty-state" class="empty-state" style="display: none;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="empty-icon">
                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                    <circle cx="8.5" cy="8.5" r="1.5"></circle>
                    <polyline points="21 15 16 10 5 21"></polyline>
                </svg>
                <h2>No NFTs Found</h2>
                <p>You don't own any NFTs yet.</p>
                <a href="<?php echo esc_url(home_url('/collections/')); ?>" class="btn btn-primary">
                    Browse Collections
                </a>
            </div>
        <?php endif; ?>
    </main>
</div>

<!-- Toast Container -->
<div id="toast-container" aria-live="polite"></div>

<style>
/* ============================================================
   MY NFTS PAGE STYLES
   ============================================================ */

/* FORCE FULL WIDTH - Override any theme constraints */
#my-nfts-page {
    width: 100vw !important;
    max-width: 100vw !important;
    margin-left: calc(-50vw + 50%) !important;
    margin-right: calc(-50vw + 50%) !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
    box-sizing: border-box !important;
}

#my-nfts-page .my-nfts-content {
    width: 100% !important;
    max-width: 100% !important;
    padding: 1rem 2rem 3rem !important;
    box-sizing: border-box !important;
}

#my-nfts-page .collections-section,
#my-nfts-page .my-collections-grid,
#my-nfts-page .expanded-collection-view {
    width: 100% !important;
    max-width: 100% !important;
}

/* Header */
.my-nfts-header {
    text-align: center;
    padding: 2rem 1rem 1rem;
}

.my-nfts-header h1 {
    font-size: clamp(1.75rem, 5vw, 2.5rem);
    margin-bottom: 0.5rem;
    font-family: 'Cinzel Decorative', 'Cinzel', 'Times New Roman', serif;
    font-weight: 700;
    letter-spacing: 0.07em;
    background: linear-gradient(180deg, #ffe066 0%, var(--imu-gold, var(--imu-gold, #d6ba66)) 40%, #996515 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    filter: drop-shadow(0 0 18px rgba(212, 175, 55, 0.4));
}

.header-subtitle {
    color: var(--text-secondary, rgba(255,255,255,0.75));
    font-size: 1rem;
    margin-bottom: 1.5rem;
}

.my-nfts-stats {
    display: flex;
    justify-content: center;
    gap: 2rem;
    margin-top: 1rem;
}

.my-nfts-stats .stat-item {
    text-align: center;
}

.my-nfts-stats .stat-value {
    display: block;
    font-size: 2rem;
    font-weight: 700;
    color: var(--imu-gold, var(--imu-gold, #d6ba66));
}

.my-nfts-stats .stat-label {
    font-size: 0.85rem;
    color: var(--text-muted, rgba(255,255,255,0.45));
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

/* Content Area - FULL WIDTH */
.my-nfts-content {
    padding: 1rem clamp(1rem, 4vw, 3rem) 3rem;
    max-width: 100%;
    margin: 0 auto;
    width: 100%;
    box-sizing: border-box;
}

/* Collections Section - FULL WIDTH */
.my-nfts-filter-bar {
    display: flex;
    gap: 8px;
    margin: 0 0 18px 0;
    flex-wrap: wrap;
}
.my-nfts-filter-tab {
    padding: 8px 18px;
    border-radius: 20px;
    border: 1px solid rgba(201,168,76,0.35);
    background: rgba(0,0,0,0.3);
    color: #ccc;
    cursor: pointer;
    font-size: 13px;
    font-weight: 500;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 6px;
}
.my-nfts-filter-tab:hover { border-color: var(--gold, #c9a84c); color: #fff; }
.my-nfts-filter-tab.active {
    background: rgba(201,168,76,0.18);
    border-color: var(--gold, #c9a84c);
    color: var(--gold, #c9a84c);
    font-weight: 600;
}
.my-nfts-filter-tab .tab-count {
    background: rgba(201,168,76,0.25);
    border-radius: 10px;
    padding: 1px 7px;
    font-size: 11px;
}
.my-nfts-filter-tab.active .tab-count {
    background: rgba(201,168,76,0.4);
}
/* Access NFTs tab golden accent */
.my-nfts-filter-tab[data-filter="access"].active {
    background: rgba(201,168,76,0.22);
    box-shadow: 0 0 12px rgba(201,168,76,0.2);
}
/* v534/P6C: Access NFT type sub-filter */
.access-type-filter-bar {
    display: flex;
    gap: 6px;
    margin: 0 0 14px 0;
    flex-wrap: wrap;
}
.access-type-tab {
    padding: 5px 13px;
    border-radius: 16px;
    border: 1px solid rgba(201,168,76,0.28);
    background: rgba(0,0,0,0.3);
    color: #bbb;
    cursor: pointer;
    font-size: 12px;
    font-weight: 500;
    transition: all 0.2s;
}
.access-type-tab:hover { border-color: var(--gold, #c9a84c); color: #fff; }
.access-type-tab.active {
    background: rgba(201,168,76,0.18);
    border-color: var(--gold, #c9a84c);
    color: var(--gold, #c9a84c);
    font-weight: 600;
}

/* v414/P1: "Received" badge for NFTs received via transfer/trade/airdrop */
.nft-badge {
    position: absolute;
    top: 8px;
    right: 8px;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    line-height: 1.6;
    z-index: 2;
    pointer-events: none;
}
.nft-badge-received {
    background: rgba(76,175,80,0.85);
    color: #fff;
    backdrop-filter: blur(4px);
}

/* v305: Access tab uses my-collections-grid layout — no separate grid rule needed */
.collection-card-artist {
    font-size: 0.8rem;
    color: #888;
    margin: 0.1rem 0 0.4rem;
}

.collections-section {
    margin-bottom: 2.5rem;
    width: 100%;
}

.section-header {
    font-size: 1.35rem;
    /* v335 Phase 3 gold: Cinzel Decorative gradient */
    font-family: 'Cinzel Decorative', 'Cinzel', 'Times New Roman', serif;
    font-weight: 700;
    letter-spacing: 0.07em;
    background: linear-gradient(180deg, #ffe066 0%, var(--imu-gold, var(--imu-gold, #d6ba66)) 40%, #996515 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    filter: drop-shadow(0 0 16px rgba(212, 175, 55, 0.35));
    margin-bottom: 1.25rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid rgba(var(--imu-gold-rgb), 0.15);
    width: 100%;
}

/* Not Connected State */
.not-connected-state {
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 50vh;
    padding: 2rem;
}

.connect-prompt-card {
    background: var(--card-bg, rgba(18, 19, 26, 0.9));
    border: 1px solid var(--card-border, rgba(var(--imu-gold-rgb), 0.12));
    border-radius: 16px;
    padding: 3rem 2rem;
    text-align: center;
    max-width: 400px;
}

.connect-prompt-card .prompt-icon {
    width: 64px;
    height: 64px;
    stroke: var(--imu-gold, var(--imu-gold, #d6ba66));
    margin-bottom: 1.5rem;
}

.connect-prompt-card h2 {
    color: var(--text-primary, #fff);
    margin-bottom: 0.75rem;
    font-size: 1.5rem;
}

.connect-prompt-card p {
    color: var(--text-secondary, rgba(255,255,255,0.75));
    margin-bottom: 1.5rem;
}

/* Collections Grid - FULL WIDTH RESPONSIVE */
.my-collections-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 1.25rem;
    width: 100%;
}

@media (min-width: 1400px) {
    .my-collections-grid {
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    }
}

@media (min-width: 1800px) {
    .my-collections-grid {
        grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    }
}

/* Collection Card */
.my-collection-card {
    background: var(--card-bg, rgba(18, 19, 26, 0.9));
    border: 1px solid var(--card-border, rgba(var(--imu-gold-rgb), 0.12));
    border-radius: 12px;
    overflow: hidden;
    transition: all 0.25s ease;
    cursor: pointer;
}

.my-collection-card:not(.empty):hover {
    border-color: var(--imu-gold, var(--imu-gold, #d6ba66));
    transform: translateY(-4px);
    box-shadow: 0 0 30px rgba(var(--imu-gold-rgb), 0.25);
}

.my-collection-card.empty {
    opacity: 0.6;
    cursor: default;
}

.collection-card-image {
    position: relative;
    aspect-ratio: 1;
    overflow: hidden;
}

.collection-card-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.4s ease;
}

.my-collection-card:not(.empty):hover .collection-card-image img {
    transform: scale(1.05);
}

.collection-badge {
    position: absolute;
    top: 12px;
    right: 12px;
    background: var(--imu-gold, var(--imu-gold, #d6ba66));
    color: var(--imu-black, #0a0b0e);
    font-weight: 700;
    font-size: 0.9rem;
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
}

.collection-card-info {
    padding: 1rem 1.25rem;
}

.collection-card-info h3 {
    font-size: 1.1rem;
    /* v335 Phase 3 gold: Cinzel gradient */
    font-family: 'Cinzel', 'Times New Roman', serif;
    font-weight: 700;
    letter-spacing: 0.04em;
    background: linear-gradient(180deg, #ffe066 0%, var(--imu-gold, var(--imu-gold, #d6ba66)) 40%, #996515 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    filter: drop-shadow(0 0 10px rgba(212, 175, 55, 0.25));
    margin-bottom: 0.25rem;
}

.collection-card-info p {
    font-size: 0.85rem;
    color: var(--text-muted, rgba(255,255,255,0.45));
    margin: 0;
}

.view-collection-btn,
.browse-collection-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    width: 100%;
    padding: 0.85rem;
    background: transparent;
    border: none;
    border-top: 1px solid var(--card-border, rgba(var(--imu-gold-rgb), 0.12));
    color: var(--imu-gold, var(--imu-gold, #d6ba66));
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s ease;
    text-decoration: none;
}

.view-collection-btn:hover {
    background: rgba(var(--imu-gold-rgb), 0.1);
}

.view-collection-btn svg {
    width: 18px;
    height: 18px;
}

.browse-collection-btn {
    color: var(--text-muted, rgba(255,255,255,0.45));
}

.browse-collection-btn:hover {
    color: var(--text-secondary, rgba(255,255,255,0.75));
    background: rgba(255,255,255,0.05);
}

/* Expanded Collection View */
.expanded-collection-view {
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.back-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    background: transparent;
    border: 1px solid var(--card-border, rgba(var(--imu-gold-rgb), 0.12));
    color: var(--text-secondary, rgba(255,255,255,0.75));
    padding: 0.6rem 1.25rem;
    border-radius: 8px;
    font-size: 0.9rem;
    cursor: pointer;
    transition: all 0.15s ease;
    margin-bottom: 1.5rem;
}

.back-btn:hover {
    border-color: var(--imu-gold, var(--imu-gold, #d6ba66));
    color: var(--imu-gold, var(--imu-gold, #d6ba66));
}

.back-btn svg {
    width: 18px;
    height: 18px;
}

.expanded-title {
    font-size: 1.5rem;
    /* v335 Phase 3 gold: Cinzel Decorative */
    font-family: 'Cinzel Decorative', 'Cinzel', 'Times New Roman', serif;
    font-weight: 700;
    letter-spacing: 0.07em;
    background: linear-gradient(180deg, #ffe066 0%, var(--imu-gold, var(--imu-gold, #d6ba66)) 40%, #996515 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    filter: drop-shadow(0 0 16px rgba(212, 175, 55, 0.35));
    margin-bottom: 1.5rem;
}

/* NFT Grid in Expanded View - FORCE VISIBILITY */
#my-nfts-page .expanded-collection-view .nft-grid,
.expanded-collection-view .nft-grid {
    display: grid !important;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)) !important;
    gap: 1.25rem !important;
    width: 100% !important;
    min-height: 200px !important;
}

/* Force NFT cards to be visible */
#my-nfts-page .nft-card,
.expanded-collection-view .nft-card {
    display: block !important;
    visibility: visible !important;
    opacity: 1 !important;
    background: rgba(18, 19, 26, 0.95) !important;
    border: 1px solid rgba(var(--imu-gold-rgb), 0.2) !important;
    border-radius: 12px !important;
    overflow: hidden !important;
    min-height: 250px !important;
    position: relative !important;
}

.expanded-collection-view .nft-card-link {
    display: block !important;
    text-decoration: none !important;
    color: inherit !important;
}

.expanded-collection-view .nft-card-image {
    width: 100% !important;
    aspect-ratio: 1 !important;
    min-height: 150px !important;
    overflow: hidden !important;
    background: rgba(30, 30, 45, 0.5) !important;
}

.expanded-collection-view .nft-card-image img {
    width: 100% !important;
    height: 100% !important;
    object-fit: cover !important;
    display: block !important;
}

.expanded-collection-view .nft-card-info {
    padding: 0.875rem 1rem !important;
    background: rgba(18, 19, 26, 0.9) !important;
}

.expanded-collection-view .nft-card-info h4 {
    font-size: 0.95rem !important;
    color: #fff !important;
    margin: 0 0 0.25rem 0 !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}

.expanded-collection-view .nft-card-action {
    font-size: 0.8rem !important;
    color: var(--imu-gold, var(--imu-gold, #d6ba66)) !important;
    display: block !important;
    opacity: 1 !important;
}

.nft-card {
    background: var(--card-bg, rgba(18, 19, 26, 0.9));
    border: 1px solid var(--card-border, rgba(var(--imu-gold-rgb), 0.12));
    border-radius: 12px;
    overflow: hidden;
    transition: all 0.25s ease;
}

.nft-card:hover {
    border-color: var(--imu-gold, var(--imu-gold, #d6ba66));
    transform: translateY(-3px);
    box-shadow: 0 0 30px rgba(var(--imu-gold-rgb), 0.25);
}

.nft-card-link {
    display: block;
    text-decoration: none;
    color: inherit;
    pointer-events: auto; /* v304: ensure link is always clickable */
    cursor: pointer;
}

.nft-card-image {
    position: relative; /* v414/P1: positioning context for badge overlay */
    aspect-ratio: 1;
    overflow: hidden;
}

.nft-card-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.4s ease;
}

.nft-card:hover .nft-card-image img {
    transform: scale(1.05);
}

.nft-card-info {
    padding: 0.875rem 1rem;
}

.nft-card-info h4 {
    font-size: 0.95rem;
    color: var(--text-primary, #fff);
    margin-bottom: 0.25rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.nft-card-action {
    font-size: 0.8rem;
    color: var(--imu-gold, var(--imu-gold, #d6ba66));
    opacity: 0;
    transition: opacity 0.15s ease;
}

.nft-card:hover .nft-card-action {
    opacity: 1;
}

/* Empty State */
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 40vh;
    text-align: center;
    padding: 2rem;
}

.empty-icon {
    width: 80px;
    height: 80px;
    stroke: var(--text-muted, rgba(255,255,255,0.45));
    margin-bottom: 1.5rem;
}

.empty-state h2 {
    color: var(--text-primary, #fff);
    margin-bottom: 0.5rem;
}

.empty-state p {
    color: var(--text-secondary, rgba(255,255,255,0.75));
    margin-bottom: 1.5rem;
}

/* Loading State */
.loading-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 4rem 2rem;
    grid-column: 1 / -1;
}

.loading-spinner {
    width: 48px;
    height: 48px;
    border: 3px solid var(--card-border, rgba(var(--imu-gold-rgb), 0.12));
    border-top-color: var(--imu-gold, var(--imu-gold, #d6ba66));
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    margin-bottom: 1rem;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

@keyframes shimmer {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

.loading-state p {
    color: var(--text-muted, rgba(255,255,255,0.45));
}

/* Error State */
.error-state {
    text-align: center;
    padding: 3rem;
    grid-column: 1 / -1;
}

.error-state p {
    color: var(--text-secondary, rgba(255,255,255,0.75));
    margin-bottom: 1rem;
}

/* Buttons */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.75rem 1.5rem;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.95rem;
    text-decoration: none;
    transition: all 0.15s ease;
    cursor: pointer;
    border: none;
}

.btn-primary {
    background: linear-gradient(135deg, var(--imu-gold, var(--imu-gold, #d6ba66)) 0%, var(--imu-gold-secondary, #d4af37) 100%);
    color: var(--imu-black, #0a0b0e);
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 0 30px rgba(var(--imu-gold-rgb), 0.25);
}

.btn-secondary {
    background: transparent;
    border: 1px solid var(--card-border, rgba(var(--imu-gold-rgb), 0.12));
    color: var(--text-primary, #fff);
}

.btn-secondary:hover {
    border-color: var(--imu-gold, var(--imu-gold, #d6ba66));
    color: var(--imu-gold, var(--imu-gold, #d6ba66));
}

.btn-lg {
    padding: 1rem 2rem;
    font-size: 1rem;
}

/* Responsive */
@media (max-width: 768px) {
    .my-nfts-stats {
        gap: 1.5rem;
    }
    
    .my-nfts-stats .stat-value {
        font-size: 1.5rem;
    }
    
    .my-collections-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 0.75rem;
    }
    
    .collection-card-info {
        padding: 0.65rem 0.75rem;
    }
    
    .collection-card-info h3 {
        font-size: 0.85rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .collection-card-info p {
        font-size: 0.75rem;
    }
    
    .collection-badge {
        font-size: 0.75rem;
        padding: 0.25rem 0.5rem;
        top: 8px;
        right: 8px;
    }
    
    /* NFT grid - 2 columns, minimal gap, bigger cards */
    .expanded-collection-view .nft-grid {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 0.5rem !important;
        padding: 0 0.25rem !important;
    }
    
    /* Taller cards */
    .expanded-collection-view .nft-card {
        min-height: 300px !important;
    }
    
    /* Taller image - portrait aspect ratio */
    .expanded-collection-view .nft-card-image {
        aspect-ratio: 3 / 4 !important;
        min-height: 200px !important;
    }
    
    .expanded-collection-view .nft-card-info {
        padding: 0.6rem 0.5rem !important;
    }
    
    .expanded-collection-view .nft-card-info h4 {
        font-size: 0.8rem !important;
    }
}
</style>

<script>
(function() {
    'use strict';
    
    const container = document.getElementById('my-nfts-page');
    if (!container) return;
    
    const account = container.dataset.account;
    const nonce = container.dataset.nonce;
    const endpoints = JSON.parse(container.dataset.endpoints || '{}');
    const collections = JSON.parse(container.dataset.collections || '{}');
    const homeUrl = '<?php echo esc_url(home_url()); ?>';
    
    // Handle connect button for non-logged in users
    if (!account) {
        // v279: Mobile cookie fix — if the page was rendered without the cookie
        // (e.g. user came back to this tab after signing in via a new Xaman tab),
        // detect the now-present cookie and reload so PHP re-renders with the account.
        const cookieAccount = document.cookie.split('; ')
            .find(r => r.startsWith('xrpl_account='))?.split('=')[1];
        if (cookieAccount && /^r[1-9A-HJ-NP-Za-km-z]{25,34}$/.test(cookieAccount)) {
            // Cookie is present but page was rendered without it — reload once
            if (!sessionStorage.getItem('_imc_cookie_reload')) {
                sessionStorage.setItem('_imc_cookie_reload', '1');
                window.location.reload();
                return;
            }
            sessionStorage.removeItem('_imc_cookie_reload');
        }

        const connectBtn = document.getElementById('connect-wallet-btn');
        if (connectBtn) {
            connectBtn.addEventListener('click', () => {
                window.location.href = homeUrl + '/trading-hub/?login=1';
            });
        }
        return;
    }
    // Clear any stale reload guard once logged in
    sessionStorage.removeItem('_imc_cookie_reload');
    
    // State
    let userNfts = [];
    let groupedNfts = {};
    let currentCollection = null;
    
    // Elements
    const collectionsGrid = document.getElementById('my-collections-grid');
    const expandedView = document.getElementById('expanded-collection-view');
    const expandedTitle = document.getElementById('expanded-collection-title');
    const expandedGrid = document.getElementById('expanded-nft-grid');
    const backBtn = document.getElementById('back-to-collections');
    const emptyState = document.getElementById('empty-state');
    const totalCount = document.getElementById('total-nfts-count');
    const collectionsCount = document.getElementById('collections-count');
    
    // Toast function
    // ── v285 FIX: ACCESS NFTs FILTER ────────────────────────────────────────
    // _imcPurchaseIds: flat Set of nftokenID (uppercase) bought on our platform.
    // _imcNftData: Map nftokenID → {name, image} — populated when Access tab opens.
    let _imcPurchaseIds = new Set();
    let _imcNftObjects  = []; // full nft objects with is_imc_purchase=true

    const _tabCounts = { all: 0, access: 0, imu: 0, other: 0 };

    function updateTabCounts() {
        document.getElementById('tab-count-all').textContent    = _tabCounts.all;
        document.getElementById('tab-count-access').textContent = _tabCounts.access;
        document.getElementById('tab-count-imu').textContent    = _tabCounts.imu;
        document.getElementById('tab-count-other').textContent  = _tabCounts.other;
        const accessTab = document.querySelector('[data-filter="access"]');
        if (accessTab) accessTab.style.display = _tabCounts.access === 0 ? 'none' : '';
    }

    let _activeFilter = 'access';
    let _accessTypeFilter = 'all'; // v534/P6C
    let _accessMetaLoaded = false; // only fetch metadata once

    window.setNftFilter = function(filter) {
        _activeFilter = filter;
        document.querySelectorAll('.my-nfts-filter-tab').forEach(t => {
            t.classList.toggle('active', t.dataset.filter === filter);
        });
        applyNftFilter();
    };

    function applyNftFilter() {
        // v335 Phase 4A: IMU + Other are now a single unified grid.
        // The 'imu' and 'other' tabs are hidden; only 'all' and 'access' are active.
        const accessSection = document.getElementById('access-section');
        const imuSection    = document.getElementById('imu-section');
        const expandedView  = document.getElementById('expanded-collection-view');

        if (_activeFilter === 'access') {
            if (imuSection)    imuSection.style.display    = 'none';
            if (expandedView)  expandedView.style.display  = 'none';
            if (accessSection) {
                accessSection.style.display = 'block';
                const accExp = document.getElementById('access-expanded-view');
                const accCol = document.getElementById('access-collections-grid');
                if (accExp) accExp.style.display = 'none';
                if (accCol) accCol.style.display = '';
            }
            renderAccessNfts();
        } else {
            // 'all' (or legacy imu/other) — show unified grid
            if (accessSection) accessSection.style.display = 'none';
            const hasNfts = Object.values(groupedNfts || {}).some(g => g.length > 0)
                         || Object.keys(window.otherGroupedNfts || {}).length > 0;
            if (imuSection) imuSection.style.display = hasNfts ? 'block' : 'none';
        }
    }

    // v305: Access tab — collection card view
    // Groups owned IMC purchase NFTs by listing_id, shows one card per listing.
    // Clicking a collection card opens an expanded grid of only that listing's NFTs.

    let _accessListingMap = new Map();   // listing_id (int) → { listing meta, nfts[] }
    let _accessExpandedId = null;        // currently expanded listing_id (or null)

    function renderAccessCollections() {
        const grid = document.getElementById('access-collections-grid');
        if (!grid) return;

        // Build the listing map from _imcNftObjects
        _accessListingMap.clear();
        for (const nft of _imcNftObjects) {
            const li   = nft.imc_listing || {};
            if (_accessTypeFilter !== 'all' && (li.nft_type || '') !== _accessTypeFilter) continue;
            const lid  = li.listing_id || 0;
            const key  = String(lid || ('nolisting_' + nft.nftokenID));
            if (!_accessListingMap.has(key)) {
                _accessListingMap.set(key, {
                    listing_id:   lid,
                    nft_name:     li.nft_name     || 'Access NFT',
                    cover_ipfs:   li.cover_ipfs   || '',
                    artist_name:  li.artist_name  || '',
                    nfts: []
                });
            }
            _accessListingMap.get(key).nfts.push(nft);
        }

        if (_accessListingMap.size === 0) {
            grid.innerHTML = '<p class="section-empty-msg">No Access NFTs found.</p>';
            return;
        }

        const fallback = '/wp-content/uploads/fallback-nft.svg';
        let html = '';
        for (const [key, col] of _accessListingMap) {
            const cid = (col.cover_ipfs || '').replace('ipfs://', '');
            // MN (Aug 2026): &thumb=1 ADDED. Without it img.php caches the ORIGIN
            // IMAGE UNRESIZED - the resize at the image proxy is gated on $thumb, and
            // the cache write stores whatever it was handed.
            //
            // Measured: the uncapped image requests observed
            // came from this page (referer imcollectibles.io, form `?url=`), and the
            // the full-size cache grows without bound - with individual
            // entries very large. There is no other full-size consumer.
            //
            // \u26a0 This is a 400px-class GRID CARD. Full size was never wanted here.
            //
            // \u26a0 `?url=` IS KEPT DELIBERATELY. `?nft=` is the safer form (G3: `?url=`
            //   can 503), but nftokenID would serve THAT NFT's own image, and this
            //   card must show the LISTING COVER (col.cover_ipfs). Changing the form
            //   here would change WHICH IMAGE is displayed, which is not this fix.
            //   The `?url=` 503 risk is tracked separately.
            const img = cid
                ? `https://metadata.imcollectibles.io/img.php?url=${encodeURIComponent('ipfs://'+cid)}&thumb=1`
                : fallback;
            const count   = col.nfts.length;
            const keyEsc  = escapeHtml(String(key));
            const nameEsc = escapeHtml(col.nft_name);
            const artEsc  = escapeHtml(col.artist_name);
            html += `
                <div class="my-collection-card" data-access-key="${keyEsc}"
                     style="cursor:pointer;" onclick="showAccessCollection('${keyEsc}')">
                    <div class="collection-card-image">
                        <img src="${img}" alt="${nameEsc}" loading="lazy"
                             onerror="this.src='${fallback}'">
                    </div>
                    <div class="collection-card-info">
                        <h3 class="collection-card-name">${nameEsc}</h3>
                        ${artEsc ? `<p class="collection-card-artist">${artEsc}</p>` : ''}
                        <span class="collection-badge">${count} owned</span>
                    </div>
                </div>`;
        }
        grid.innerHTML = html;
    }

    async function showAccessCollection(key) {
        _accessExpandedId = key;
        const col  = _accessListingMap.get(key);
        if (!col) return;

        const collectionsGrid = document.getElementById('access-collections-grid');
        const expandedView    = document.getElementById('access-expanded-view');
        const expandedTitle   = document.getElementById('access-expanded-title');
        const expandedGrid    = document.getElementById('access-expanded-grid');
        if (!expandedView || !expandedGrid) return;

        if (collectionsGrid) collectionsGrid.style.display = 'none';
        expandedView.style.display = 'block';
        if (expandedTitle) expandedTitle.textContent = col.nft_name + (col.artist_name ? ' — ' + col.artist_name : '');

        // Show loading skeletons
        expandedGrid.innerHTML = col.nfts.map(() => `
            <div class="nft-card loading-card">
                <div class="nft-card-link">
                    <div class="nft-card-image">
                        <div style="width:100%;height:200px;background:linear-gradient(90deg,#1a1a2e 25%,#2a2a4e 50%,#1a1a2e 75%);background-size:200% 100%;animation:shimmer 1.5s infinite;border-radius:8px;"></div>
                    </div>
                    <div class="nft-card-info"><h4 style="background:#2a2a4e;height:1.2em;border-radius:4px;width:60%;"></h4></div>
                </div>
            </div>`).join('');
        window.scrollTo({ top: 0, behavior: 'smooth' });

        // Fetch metadata for NFTs in this collection that don't have it yet
        const needMeta = col.nfts.filter(n => !n.metadata);
        if (needMeta.length > 0 && !_accessMetaLoaded) {
            try {
                const ids  = col.nfts.map(n => n.nftokenID).filter(Boolean);
                const resp = await fetch(
                    `${endpoints.myNftsHandler}?action=get_metadata&nonce=${encodeURIComponent(nonce)}`,
                    { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ids }) }
                );
                if (resp.ok) {
                    const data = await resp.json();
                    if (data.success && data.metadata) {
                        col.nfts.forEach(n => {
                            const m = data.metadata[n.nftokenID.toUpperCase()] || data.metadata[n.nftokenID];
                            if (m) n.metadata = m;
                        });
                    }
                }
            } catch(e) { console.warn('Access metadata fetch failed:', e); }
            _accessMetaLoaded = true;
        }

        // Render individual NFT cards
        const fallback = '/wp-content/uploads/fallback-nft.svg';
        let html = '';
        for (const nft of col.nfts) {
            const m     = nft.metadata || {};
            // Prefer metadata image; fall back to collection cover
            const cid   = (col.cover_ipfs || '').replace('ipfs://', '');
            // MN: &thumb=1 ADDED - same reasoning as the collection card above.
            // \u26a0 `?url=` kept for the same reason: this is the listing COVER used as
            //   a fallback when the NFT has no metadata image of its own, so it must
            //   stay keyed on cover_ipfs rather than nft.nftokenID.
            const image = m.image || (cid ? `https://metadata.imcollectibles.io/img.php?url=${encodeURIComponent('ipfs://'+cid)}&thumb=1` : fallback);
            const name  = m.name  || col.nft_name || 'Access NFT';
            const link  = `${homeUrl}/nft/${nft.nftokenID}/`;
            html += `
                <div class="nft-card" data-nft-id="${escapeHtml(nft.nftokenID)}"
                     style="cursor:pointer;" onclick="window.location.href='${link}'">
                    <a href="${link}" class="nft-card-link" tabindex="-1">
                        <div class="nft-card-image">
                            <img src="${escapeHtml(image)}" alt="${escapeHtml(name)}" loading="lazy"
                                 onerror="this.src='${fallback}'">
                            ${nft.is_received_transfer ? '<span class="nft-badge nft-badge-received" title="Received via transfer">Received</span>' : ''}
                        </div>
                        <div class="nft-card-info">
                            <h4>${escapeHtml(name)}</h4>
                            <span class="nft-card-action">View NFT →</span>
                        </div>
                    </a>
                </div>`;
        }
        expandedGrid.innerHTML = html;
    }

    // Back button for access expanded view
    const accessBackBtn = document.getElementById('access-back-btn');
    if (accessBackBtn) {
        accessBackBtn.addEventListener('click', () => {
            _accessExpandedId = null;
            document.getElementById('access-expanded-view').style.display  = 'none';
            document.getElementById('access-collections-grid').style.display = '';
        });
    }

    // Expose for onclick handlers
    window.showAccessCollection = showAccessCollection;

    function renderAccessNfts() {
        renderAccessCollections();
    }

    // v534/P6C: Access NFT type sub-filter
    window.setAccessType = function(type) {
        _accessTypeFilter = type;
        document.querySelectorAll('.access-type-tab').forEach(t => {
            t.classList.toggle('active', t.dataset.type === type);
        });
        renderAccessCollections();
    };

    // v534/P6A+B: FAST Access load (DB-only) — renders instantly, before the XRPL fetch
    async function loadAccessNftsFast() {
        try {
            const res = await fetch(`${endpoints.myNftsHandler}?action=get_access_nfts&account=${encodeURIComponent(account)}&nonce=${encodeURIComponent(nonce)}`);
            const data = await res.json();
            if (!data.success || !Array.isArray(data.access_nfts)) return;
            _imcPurchaseIds.clear();
            _imcNftObjects = [];
            data.access_nfts.forEach(n => {
                if (n && n.nftokenID) {
                    _imcPurchaseIds.add(String(n.nftokenID).toUpperCase());
                    _imcNftObjects.push(n);
                }
            });
            _tabCounts.access = _imcNftObjects.length;
            updateTabCounts();
            if (_activeFilter === 'access') renderAccessNfts();
        } catch (e) {
            console.warn('[IMC] fast access load failed; background load will populate:', e);
        }
    }
    // ── END ACCESS NFTs FILTER ───────────────────────────────────────────────

    function showToast(message, type = 'info') {
        const toastContainer = document.getElementById('toast-container');
        if (!toastContainer) return;
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;
        toastContainer.appendChild(toast);
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    }
    
    // Escape HTML
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Re-sort the unified collections grid after async image fetches resolve.
    // Order: IMU-with-image → IMU-without → Other-with-image → Other-without.
    function _resortUnifiedGrid() {
        const grid = document.getElementById('my-collections-grid');
        if (!grid) return;
        const cards = Array.from(grid.querySelectorAll('.my-collection-card'));
        cards.sort((a, b) => {
            const aImu = a.dataset.isImu === 'true' ? 0 : 1;
            const bImu = b.dataset.isImu === 'true' ? 0 : 1;
            const aImg = a.dataset.hasImage === 'true' ? 0 : 1;
            const bImg = b.dataset.hasImage === 'true' ? 0 : 1;
            // Primary: IMU before Other. Secondary: has-image before broken.
            if (aImu !== bImu) return aImu - bImu;
            return aImg - bImg;
        });
        cards.forEach(card => grid.appendChild(card)); // re-order in DOM (no flicker)
    }

    // Fetch user's NFTs from XRPL
    async function loadMyNfts() {
        try {
            const res = await fetch(`${endpoints.myNftsHandler}?action=get_my_nfts&account=${encodeURIComponent(account)}&nonce=${encodeURIComponent(nonce)}`);
            const data = await res.json();
            
            if (!data.success) {
                throw new Error(data.error || 'Failed to load NFTs');
            }
            
            userNfts = data.nfts || [];
            groupedNfts = data.grouped || {};

            // v285 FIX: Build Access NFTs set + object array for dedicated grid
            _imcPurchaseIds.clear();
            _imcNftObjects  = [];
            _accessMetaLoaded = false; // reset so metadata refetches on re-open
            let accessCount = 0;
            userNfts.forEach(n => {
                if (n.is_imc_purchase && n.nftokenID) {
                    _imcPurchaseIds.add(n.nftokenID.toUpperCase());
                    _imcNftObjects.push(n);
                    accessCount++;
                }
            });
            const imuCount = Object.values(groupedNfts).reduce((s,g) => s + (Array.isArray(g) ? g.length : 0), 0);
            _tabCounts.all    = userNfts.length;
            _tabCounts.access = accessCount;
            _tabCounts.imu    = imuCount;
            _tabCounts.other  = userNfts.length - imuCount;
            updateTabCounts();
            
            // Store other collections data
            window.otherGroupedNfts = data.other_grouped || {};
            window.otherCollectionsMeta = data.other_collections || {};
            
            // Update stats - include other collections
            if (totalCount) totalCount.textContent = data.total_count || 0;
            if (collectionsCount) {
                const imuActive = Object.values(groupedNfts).filter(g => g.length > 0).length;
                const otherActive = Object.keys(window.otherGroupedNfts).length;
                collectionsCount.textContent = imuActive + otherActive;
            }
            
            renderCollectionCards();
            // v534/P6B: authoritative XRPL data is in — refresh Access if it's the active tab
            if (_activeFilter === 'access') renderAccessNfts();
            
        } catch (err) {
            console.error('Failed to load NFTs:', err);
            showToast('Failed to load NFTs: ' + err.message, 'error');
            collectionsGrid.innerHTML = `
                <div class="error-state">
                    <p>Failed to load your NFTs. Please try again.</p>
                    <button onclick="location.reload()" class="btn btn-secondary">Retry</button>
                </div>
            `;
        }
    }
    
    // Fetch images for other collections from VPS (for cards that don't have images)
    async function fetchOtherCollectionImages() {
        const cards = document.querySelectorAll('.my-collection-card[data-is-imu="false"][data-has-image="false"]');
        if (cards.length === 0) return;
        
        // Collect first NFT IDs for cards that need images
        const nftIds = [];
        const cardMap = {};
        
        cards.forEach(card => {
            const firstNftId = card.dataset.firstNft;
            if (firstNftId) {
                nftIds.push(firstNftId);
                cardMap[firstNftId] = card;
            }
        });
        
        if (nftIds.length === 0) return;
        
        console.log('Fetching metadata for', nftIds.length, 'other collection first NFTs');
        
        // v283: If ?claimed= is in the URL, flag those NFT IDs for retry.
        // A freshly claimed NFT may not be indexed by VPS yet — retry metadata
        // fetch up to 4 times with 3-second intervals to get the image.
        const claimedParam = new URLSearchParams(window.location.search).get('claimed');
        const claimedIds = claimedParam && claimedParam.length > 10
            ? [claimedParam.toUpperCase()]
            : [];

        try {
            // Batch fetch metadata from VPS via WordPress proxy
            const response = await fetch(`${endpoints.myNftsHandler}?action=get_metadata&nonce=${encodeURIComponent(nonce)}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ids: nftIds })
            });
            
            if (response.ok) {
                const data = await response.json();
                if (data.success && data.metadata) {
                    // Update cards with fetched metadata
                    for (const [nftId, meta] of Object.entries(data.metadata)) {
                        const card = cardMap[nftId];
                        if (card && meta) {
                            // Update image if we got one
                            if (meta.image) {
                                const img = card.querySelector('.collection-card-image img');
                                if (img) {
                                    img.src = meta.image;
                                }
                            }
                            
                            // Update name if still "Unknown Collection"
                            const nameEl = card.querySelector('.collection-card-info h3');
                            if (nameEl && nameEl.textContent === 'Unknown Collection' && meta.name) {
                                // Try to extract collection name from NFT name
                                // e.g. "Cloned Ape #123" -> "Cloned Ape"
                                let colName = meta.name;
                                const hashMatch = meta.name.match(/^(.+?)[\s#]+\d+$/);
                                if (hashMatch) {
                                    colName = hashMatch[1].trim();
                                }
                                nameEl.textContent = colName;
                            }
                        }
                    }
                }
            }
        } catch (err) {
            console.warn('Failed to fetch other collection images:', err);
        }

        // Re-sort grid after images arrive: working-image cards float above broken ones
        _resortUnifiedGrid();

        // v285: Re-apply filter after render so Access tab stays consistent
        // Only re-trigger if user is currently on access filter
        if (_activeFilter !== 'all') applyNftFilter();

        // v283: Retry metadata for freshly-claimed NFTs (VPS may not have indexed yet).
        // Attempts up to 4 times with 3-second gaps — total max wait 12 seconds.
        // Only retries cards that still show the fallback/unavailable state.
        if (claimedIds.length > 0) {
            let retryCount = 0;
            const maxRetries = 4;
            const retryDelay = 3000;

            const retryClaimedImages = async () => {
                if (retryCount >= maxRetries) return;
                retryCount++;

                try {
                    const retryResp = await fetch(`${endpoints.myNftsHandler}?action=get_metadata&nonce=${encodeURIComponent(nonce)}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ ids: claimedIds })
                    });
                    if (!retryResp.ok) return;
                    const retryData = await retryResp.json();
                    if (!retryData.success || !retryData.metadata) return;

                    let allResolved = true;
                    for (const [nftId, meta] of Object.entries(retryData.metadata)) {
                        if (!meta?.image) { allResolved = false; continue; }
                        // Find cards that still show the SVG fallback
                        document.querySelectorAll('.collection-card-image img, .nft-card-image img').forEach(img => {
                            if (img.src.includes('fallback-nft.svg') || img.src === '' || !img.complete) {
                                const card = img.closest('[data-nft-id]');
                                if (card && card.dataset.nftId?.toUpperCase() === nftId.toUpperCase()) {
                                    img.src = meta.image;
                                }
                            }
                        });
                    }
                    if (!allResolved) setTimeout(retryClaimedImages, retryDelay);
                } catch (e) { /* silent — retry is best-effort */ }
            };

            setTimeout(retryClaimedImages, retryDelay);
        }
    }
    
    // Render collection cards
    // v335 Phase 4A: Unified grid — IMU collections first (with working images before broken),
    // then other collections (same order). Single grid; no separate section headers.
    function renderCollectionCards() {
        const imuSection  = document.getElementById('imu-section');
        const otherSection = document.getElementById('other-section'); // hidden stub

        const hasImuNfts   = Object.values(groupedNfts).some(g => g.length > 0);
        const hasOtherNfts = Object.keys(window.otherGroupedNfts || {}).length > 0;

        if (!hasImuNfts && !hasOtherNfts) {
            collectionsGrid.style.display = 'none';
            if (imuSection)   imuSection.style.display   = 'none';
            if (otherSection) otherSection.style.display = 'none';
            emptyState.style.display = 'flex';
            return;
        }

        emptyState.style.display = 'none';

        // Helper: build a single collection card HTML string
        function buildCard(key, name, previewImage, count, isImu, firstNftId, hasImage) {
            const isImuStr  = isImu ? 'true' : 'false';
            const hasImgStr = hasImage ? 'true' : 'false';
            return `
                <div class="my-collection-card"
                     data-collection="${escapeHtml(key)}"
                     data-count="${count}"
                     data-is-imu="${isImuStr}"
                     data-first-nft="${escapeHtml(firstNftId)}"
                     data-has-image="${hasImgStr}">
                    <div class="collection-card-image">
                        <img src="${escapeHtml(previewImage)}"
                             alt="${escapeHtml(name)}"
                             loading="lazy"
                             onerror="this.closest('.my-collection-card').dataset.hasImage='false';this.src='/wp-content/uploads/fallback-nft.svg'">
                        <div class="collection-badge">${count}</div>
                    </div>
                    <div class="collection-card-info">
                        <h3>${escapeHtml(name)}</h3>
                        <p>${count} NFT${count > 1 ? 's' : ''} owned</p>
                    </div>
                    <button class="view-collection-btn"
                            data-collection="${escapeHtml(key)}"
                            data-is-imu="${isImuStr}">
                        View Collection
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M5 12h14M12 5l7 7-7 7"/>
                        </svg>
                    </button>
                </div>`;
        }

        // ── Build IMU cards ──────────────────────────────────────────────────
        const imuWithImage    = [];
        const imuWithoutImage = [];
        for (const [key, col] of Object.entries(collections)) {
            const nfts  = groupedNfts[key] || [];
            const count = nfts.length;
            if (count === 0) continue;
            let previewImage = col.image;
            if (nfts[0]?.metadata?.image) previewImage = nfts[0].metadata.image;
            const hasImage = !!(previewImage && !previewImage.includes('fallback-nft.svg'));
            const html = buildCard(key, col.short_name || col.name, previewImage, count, true, nfts[0]?.nftokenID || '', hasImage);
            if (hasImage) imuWithImage.push(html); else imuWithoutImage.push(html);
        }

        // ── Build Other cards ────────────────────────────────────────────────
        const otherGrouped = window.otherGroupedNfts   || {};
        const otherMeta    = window.otherCollectionsMeta || {};
        const otherWithImage    = [];
        const otherWithoutImage = [];
        for (const [key, nfts] of Object.entries(otherGrouped)) {
            const count   = nfts.length;
            const meta    = otherMeta[key] || {};
            const colName = meta.name  || 'Unknown Collection';
            const img     = meta.image || '/wp-content/uploads/fallback-nft.svg';
            const hasImage = !!meta.image;
            const html = buildCard(key, colName, img, count, false, nfts[0]?.nftokenID || '', hasImage);
            if (hasImage) otherWithImage.push(html); else otherWithoutImage.push(html);
        }

        // ── Merge: IMU-with-image → IMU-without-image → Other-with-image → Other-without-image ──
        const allHtml = [
            ...imuWithImage,
            ...imuWithoutImage,
            ...otherWithImage,
            ...otherWithoutImage
        ].join('');

        collectionsGrid.innerHTML = allHtml;
        collectionsGrid.style.display = 'grid';
        if (imuSection) imuSection.style.display = 'block';

        // Fetch real images/names for other-collection cards that don't have them yet
        if (otherWithoutImage.length > 0 || Object.keys(otherGrouped).length > 0) {
            fetchOtherCollectionImages();
        }

        // Click handlers — skip access-tab cards (they have data-access-key)
        document.querySelectorAll('.view-collection-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const key   = btn.dataset.collection;
                const isImu = btn.dataset.isImu === 'true';
                showExpandedCollection(key, isImu);
            });
        });
        document.querySelectorAll('.my-collection-card:not([data-access-key])').forEach(card => {
            card.addEventListener('click', () => {
                const key   = card.dataset.collection;
                const isImu = card.dataset.isImu === 'true';
                showExpandedCollection(key, isImu);
            });
        });
    }
    
    // Show expanded collection view - handles both IMU and Other
    // LAZY LOADING: Fetches metadata via WordPress proxy (avoids CORS)
    async function showExpandedCollection(collectionKey, isImu) {
        console.log('showExpandedCollection called:', { collectionKey, isImu });
        currentCollection = collectionKey;
        
        let nfts, colName;
        
        if (isImu) {
            const col = collections[collectionKey];
            nfts = groupedNfts[collectionKey] || [];
            colName = col?.name || 'Collection';
            console.log('IMU collection:', { col, nftsCount: nfts.length });
        } else {
            nfts = (window.otherGroupedNfts || {})[collectionKey] || [];
            const meta = (window.otherCollectionsMeta || {})[collectionKey] || {};
            colName = meta.name || 'Collection';
            console.log('Other collection:', { meta, nftsCount: nfts.length, nfts });
        }
        
        expandedTitle.textContent = `${colName} (${nfts.length})`;
        
        // Show view immediately with loading state
        document.getElementById('imu-section').style.display = 'none';
        document.getElementById('other-section').style.display = 'none';
        expandedView.style.display = 'block';
        
        // Show loading placeholders
        let loadingHtml = '';
        const displayCount = Math.min(nfts.length, 20);
        for (let i = 0; i < displayCount; i++) {
            loadingHtml += `
                <div class="nft-card loading-card">
                    <div class="nft-card-link">
                        <div class="nft-card-image">
                            <div style="width:100%;height:200px;background:linear-gradient(90deg,#1a1a2e 25%,#2a2a4e 50%,#1a1a2e 75%);background-size:200% 100%;animation:shimmer 1.5s infinite;border-radius:8px;"></div>
                        </div>
                        <div class="nft-card-info">
                            <h4 style="background:#2a2a4e;height:1.2em;border-radius:4px;width:60%;"></h4>
                        </div>
                    </div>
                </div>
            `;
        }
        expandedGrid.innerHTML = loadingHtml;
        window.scrollTo({ top: 0, behavior: 'smooth' });
        
        // Fetch metadata via WordPress proxy (avoids CORS issues)
        // Works for BOTH IMU and Other collections
        let metadataMap = {};
        if (nfts.length > 0) {
            const nftIds = nfts.map(n => n.nftokenID).filter(Boolean);
            console.log('Fetching metadata for', nftIds.length, 'NFTs via WP proxy...', isImu ? '(IMU)' : '(Other)');
            console.log('First NFT ID:', nftIds[0]);
            
            try {
                const response = await fetch(`${endpoints.myNftsHandler}?action=get_metadata&nonce=${encodeURIComponent(nonce)}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ids: nftIds })
                });
                
                if (response.ok) {
                    const data = await response.json();
                    console.log('Raw response:', data);
                    if (data.success && data.metadata) {
                        metadataMap = data.metadata;
                        console.log('Received metadata for', Object.keys(metadataMap).length, 'NFTs');
                        // Debug: show first entry's data
                        const firstKey = Object.keys(metadataMap)[0];
                        if (firstKey) {
                            console.log('First metadata key:', firstKey);
                            console.log('First metadata value:', metadataMap[firstKey]);
                            // Check if keys match NFT IDs
                            const hasFirstNft = metadataMap[nftIds[0]];
                            console.log('Match check - first NFT ID in metadata?', !!hasFirstNft, nftIds[0]);
                        }
                    }
                } else {
                    console.error('Response not OK:', response.status);
                }
            } catch (err) {
                console.warn('Metadata fetch failed:', err);
                // Continue with placeholders - graceful degradation
            }
        }
        
        // Render NFT cards with metadata
        let html = '';
        for (const nft of nfts) {
            const vpsMeta = metadataMap[nft.nftokenID] || {};
            const existingMeta = nft.metadata || {};
            const name = vpsMeta.name || existingMeta.name || 'Unnamed NFT';
            const image = vpsMeta.image || existingMeta.image || '/wp-content/uploads/fallback-nft.svg';
            
            // Debug first card
            if (nfts.indexOf(nft) === 0) {
                console.log('First card render:', { nftokenID: nft.nftokenID, vpsMeta, name, image });
            }
            
            // ALL NFTs link to our Single NFT page
            const link = `${homeUrl}/nft/${nft.nftokenID}/`;
            const actionText = 'View & List for Sale →';
            
            html += `
                <div class="nft-card" data-nft-id="${escapeHtml(nft.nftokenID)}"
                     style="cursor:pointer;" onclick="window.location.href='${link}'">
                    <a href="${link}" class="nft-card-link" tabindex="-1">
                        <div class="nft-card-image">
                            <img src="${escapeHtml(image)}" alt="${escapeHtml(name)}" loading="lazy" onerror="this.src='/wp-content/uploads/fallback-nft.svg'">
                        </div>
                        <div class="nft-card-info">
                            <h4>${escapeHtml(name)}</h4>
                            <span class="nft-card-action">${actionText}</span>
                        </div>
                    </a>
                </div>
            `;
        }
        
        expandedGrid.innerHTML = html;
        console.log('Rendered', nfts.length, 'NFT cards');
    }
    
    // Back to collections
    if (backBtn) {
        backBtn.addEventListener('click', () => {
            expandedView.style.display = 'none';
            currentCollection = null;
            // Restore sections based on active filter
            applyNftFilter();
        });
    }
    
    // Initialize
    // v535: reveal the Access section immediately (it is display:none by default) so the fast DB
    // render is visible right away. Without this the fast collections render into a hidden section
    // and the user keeps seeing the IMU spinner until the slow XRPL load reveals Access ~10s later.
    if (_activeFilter === 'access') {
        const _imuSecInit = document.getElementById('imu-section');
        const _accSecInit = document.getElementById('access-section');
        if (_imuSecInit) _imuSecInit.style.display = 'none';
        if (_accSecInit) _accSecInit.style.display = 'block';
    }
    // v534/P6B: fast DB Access first (instant), then full XRPL load in background to reconcile
    loadAccessNftsFast().finally(function() { loadMyNfts(); });
})();
</script>

<?php if (function_exists('imc_xrplto_attribution')) imc_xrplto_attribution(); ?>
<?php get_footer(); ?>