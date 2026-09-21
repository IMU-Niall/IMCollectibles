<?php
/**
 * Template Name: New Drops
 * File: page-recent-mints.php
 * Path: /wp-content/themes/astra/page-recent-mints.php
 * 
 * Shows collections minted ONLY on the IMCollectibles platform
 * Data sources: 
 *   - imc_listings: Available listings (mint-on-demand)
 *   - xumm_nft_mints: Completed mints
 * Ordered by most recent first
 */

get_header();

global $wpdb;
$mints_table = $wpdb->prefix . 'xumm_nft_mints';
$listings_table = $wpdb->prefix . 'imc_listings';

$collections = [];
$total_collections = 0;
$total_nfts = 0;
$available_listings = [];
$total_available = 0;

// 1. Query AVAILABLE LISTINGS from imc_listings (mint-on-demand)
// v62 FIX: Also include sold_out listings so they remain visible with "SOLD OUT" tag
// v85: Include pricing_mode and price_usd for dynamic pricing display
if ($wpdb->get_var("SHOW TABLES LIKE '$listings_table'") === $listings_table) {
    $available_listings = $wpdb->get_results("
        SELECT 
            l.id,
            l.nft_name,
            l.nft_type,
            l.artist_account,
            l.artist_name,
            l.collection_name,
            l.collection_taxon,
            l.price_xrp,
            l.pricing_mode,
            l.price_usd,
            l.accepted_currencies,
            l.total_editions,
            l.minted_count,
            GREATEST(0, CAST(l.total_editions AS SIGNED) - CAST(l.minted_count AS SIGNED)) as available_count,
            l.cover_ipfs,
            l.status,
            l.launch_type,
            l.launch_at,
            l.launch_timezone,
            l.edition_type,
            l.open_edition_ends_at,
            l.open_edition_closed_at,
            l.ai_generated, l.ai_mode, l.ai_platform,
            l.created_at
        FROM $listings_table l
        WHERE l.status IN ('active', 'sold_out', 'paused') AND l.is_hidden = 0
        ORDER BY CASE WHEN l.status = 'sold_out' THEN 1 ELSE 0 END, l.created_at DESC
    ", ARRAY_A) ?: [];
    
    // v235: Override cover_ipfs with artist-uploaded collection cover where available.
    // Post-fetch batch lookup — avoids JOIN complexity, safe if imc_collections missing.
    if (!empty($available_listings)) {
        $imc_coll_table = $wpdb->prefix . 'imc_collections';
        if ($wpdb->get_var("SHOW TABLES LIKE '$imc_coll_table'") === $imc_coll_table) {
            $cover_map = [];
            // Build unique pairs and fetch in individual prepared queries
            $seen = [];
            foreach ($available_listings as $l) {
                $key = $l['artist_account'] . '_' . intval($l['collection_taxon']);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $row = $wpdb->get_row($wpdb->prepare(
                        "SELECT artist_account, collection_taxon, cover_image_ipfs
                         FROM $imc_coll_table
                         WHERE artist_account = %s AND collection_taxon = %d
                         AND cover_image_ipfs IS NOT NULL AND cover_image_ipfs != ''
                         LIMIT 1",
                        $l['artist_account'], intval($l['collection_taxon'])
                    ), ARRAY_A);
                    if ($row && !empty($row['cover_image_ipfs'])) {
                        $cover_map[$key] = $row['cover_image_ipfs'];
                    }
                }
            }
            // Apply overrides
            foreach ($available_listings as &$listing) {
                // v572: preserve the listing's OWN cover before the collection-cover override
                $listing['listing_cover_ipfs'] = $listing['cover_ipfs'];
                $key = $listing['artist_account'] . '_' . intval($listing['collection_taxon']);
                if (isset($cover_map[$key])) {
                    $listing['cover_ipfs'] = $cover_map[$key];
                }
            }
            unset($listing);
        }
    }

    $total_collections = count($available_listings);
    $total_available = array_sum(array_column($available_listings, 'available_count'));
}

// 2. Query COMPLETED MINTS from xumm_nft_mints
// v280: Removed query on non-existent 'collection_name' column (caused DB error in logs).
// The mints table schema does not include this column — completed mint counts
// are not needed for the New Drops page which is driven by imc_listings.
$collections = [];
$total_nfts = 0;
?>

<style>
/* ============================================
   NEW DROPS PAGE STYLES
   ============================================ */
.drops-page-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 2rem 1.5rem 4rem;
    min-height: 80vh;
    position: relative;
    z-index: 1;
}

.drops-page-header {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
    gap: 1rem;
}

.drops-page-header h1 {
    font-size: 2rem;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-family: 'Cinzel Decorative', 'Cinzel', 'Times New Roman', serif;
    font-weight: 700;
    letter-spacing: 0.07em;
    background: linear-gradient(180deg, #ffe066 0%, var(--imu-gold, #d6ba66) 40%, #996515 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    filter: drop-shadow(0 0 18px rgba(212, 175, 55, 0.4));
}

.drops-page-header h1 span { font-size: 2.2rem; }

/* Platform Badge */
.drops-platform-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    background: rgba(var(--imu-gold-rgb), 0.1);
    border: 1px solid rgba(var(--imu-gold-rgb), 0.15);
    border-radius: 20px;
    color: var(--imu-gold, #d6ba66);
    font-size: 0.85rem;
    font-weight: 500;
}

/* Stats Bar */
.drops-stats-bar {
    display: flex;
    gap: 2rem;
    margin-bottom: 2rem;
    padding: 1rem 1.5rem;
    background: #12121a;
    border: 1px solid rgba(var(--imu-gold-rgb), 0.15);
    border-radius: 12px;
    flex-wrap: wrap;
}

.drops-stat {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.drops-stat .label {
    color: #8a8a8a;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.drops-stat .value {
    color: var(--imu-gold, #d6ba66);
    font-size: 1.25rem;
    font-weight: 700;
}

/* Filter Tabs */
.drops-filters-container {
    display: flex;
    flex-direction: row;   /* dropdowns sit in one wrapping row, not four stacked rows */
    flex-wrap: wrap;
    align-items: center;
    gap: 0.6rem;
    margin-bottom: 2rem;
}

/* v530: Stats + Type/Status side-by-side (desktop); stacked (mobile) */
.drops-top-row {
    display: flex;
    gap: 1.5rem;
    align-items: flex-start;
    margin-bottom: 2rem;
}
.drops-top-row .drops-stats-bar { margin-bottom: 0; }
.drops-top-row .drops-filters-container { margin-bottom: 0; flex: 1; }

.drops-filter-label {
    color: #8a8a8a;
    font-size: 0.78rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
    white-space: nowrap;
    padding: 0 0.25rem;
}

/* v23: Status tab inherits same look as type tab */
/* v571: Edition tab shares the status-tab styling */
.drops-status-tab,
.drops-edition-tab,
.drops-price-tab {
    padding: 0.6rem 1.25rem;
    border: none;
    background: transparent;
    color: #8a8a8a;
    cursor: pointer;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.9rem;
    transition: all 0.2s ease;
}

.drops-status-tab:hover,
.drops-edition-tab:hover,
.drops-price-tab:hover {
    color: var(--imu-gold, #d6ba66);
    background: rgba(var(--imu-gold-rgb), 0.1);
}

.drops-status-tab.active,
.drops-edition-tab.active,
.drops-price-tab.active {
    background: var(--imu-gold, #d6ba66);
    color: #000;
}

/* v650: price-sort disclaimer (shown only while a price sort is active) */
.drops-price-disclaimer {
    display: none;
    width: 100%;
    margin-top: 0.6rem;
    font-size: 0.78rem;
    color: #b3a575;
    background: rgba(var(--imu-gold-rgb), 0.06);
    border: 1px solid rgba(var(--imu-gold-rgb), 0.16);
    border-radius: 8px;
    padding: 0.5rem 0.85rem;
}
.drops-price-disclaimer.show { display: block; }

/* ------------------------------------------------------------------
   Filter dropdowns - four compact buttons instead of four long rows.
   Option buttons keep .drops-filter-tab / .drops-status-tab etc so the
   existing handlers and their .active styling continue to apply.
   ------------------------------------------------------------------ */
/* The whole filter area is lifted above the card grid: cards are positioned
   elements later in the DOM, so without this the open menu paints behind them. */
.drops-filters-container { position: relative; z-index: 100; }
.drops-top-row { position: relative; z-index: 100; }
.drops-dd { position: relative; display: inline-block; }
.drops-dd.open { z-index: 1000; }
.drops-dd-trigger {
    display: flex; align-items: center; gap: 0.5rem;
    background: #12121a; border: 1px solid rgba(var(--imu-gold-rgb), 0.15);
    color: #e8e3d5; border-radius: 10px; padding: 0.6rem 0.9rem;
    font-size: 0.9rem; font-weight: 600; cursor: pointer; transition: border-color .2s ease;
}
.drops-dd-trigger:hover { border-color: rgba(var(--imu-gold-rgb), 0.45); }
.drops-dd.open .drops-dd-trigger { border-color: var(--imu-gold, #d6ba66); }
.drops-dd-label { color: #8a8a8a; font-weight: 600; }
.drops-dd-value { color: var(--imu-gold, #d6ba66); }
.drops-dd-caret { opacity: .7; transition: transform .2s ease; }
.drops-dd.open .drops-dd-caret { transform: rotate(180deg); }
.drops-dd-menu {
    display: none; position: absolute; z-index: 1000; top: calc(100% + 6px); left: 0;
    min-width: 210px; flex-direction: column; gap: 0.25rem;
    background: #12121a; border: 1px solid rgba(var(--imu-gold-rgb), 0.25);
    border-radius: 10px; padding: 0.4rem; box-shadow: 0 12px 30px rgba(0,0,0,.55);
}
.drops-dd.open .drops-dd-menu { display: flex; }
.drops-dd-menu button { text-align: left; white-space: nowrap; width: 100%; }
@media (max-width: 768px) {
    .drops-dd { width: 100%; }
    .drops-dd-trigger { width: 100%; justify-content: space-between; }
    .drops-dd-menu { left: 0; right: 0; min-width: 0; }
}

.drops-filter-tabs {
    display: flex;
    gap: 0.5rem;
    background: #12121a;
    padding: 0.35rem;
    border-radius: 10px;
    border: 1px solid rgba(var(--imu-gold-rgb), 0.15);
    width: fit-content;
    align-items: center;
}

.drops-filter-tab {
    padding: 0.6rem 1.25rem;
    border: none;
    background: transparent;
    color: #8a8a8a;
    cursor: pointer;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.9rem;
    transition: all 0.2s ease;
}

.drops-filter-tab:hover {
    color: var(--imu-gold, #d6ba66);
    background: rgba(var(--imu-gold-rgb), 0.1);
}

.drops-filter-tab.active {
    background: var(--imu-gold, #d6ba66);
    color: #000;
}

/* Collections Grid */
.drops-grid {
    display: grid;
    /* Explicit column counts: auto-fill derived the count from the container
       width, which is narrower than the 1400px max, so it only ever fitted 4.
       minmax(0, 1fr) lets columns shrink below their content width. */
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 0.9rem;
}
@media (max-width: 1199px) { .drops-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); } }
@media (max-width: 1023px) { .drops-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
@media (max-width: 860px)  { .drops-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }

/* Collection Card */
.drops-card {
    background: #12121a;
    border: 1px solid rgba(var(--imu-gold-rgb), 0.15);
    border-radius: 12px;
    overflow: hidden;
    transition: all 0.3s ease;
    cursor: pointer;
    text-decoration: none;
    display: block;
    position: relative; /* v488: contain open-mint badge to its own card (open-edition cards have no .scheduled/.sold-out/.paused positioning context) */
}

.drops-card:hover {
    border-color: var(--imu-gold, #d6ba66);
    transform: translateY(-4px);
    box-shadow: 0 10px 30px rgba(var(--imu-gold-rgb), 0.15);
}

/* v633 Step6 Surface2: AI badge 2x larger on the Recent Mints page only (scoped; trading-hub badge unchanged) */
/* v633 scaled for the 6-up grid (was 22px for the old 3-up cards) */
.drops-card .imc-ai-badge{font-size:11px;padding:2px 6px;gap:3px;top:6px;right:6px;}

.drops-card-image {
    width: 100%;
    aspect-ratio: 1;
    object-fit: cover;
    background: #0a0a0f;
}

.drops-card-content {
    padding: 0.7rem;
}

.drops-card-name {
    color: #e8e6e3;
    font-weight: 600;
    font-size: 0.85rem;
    margin-bottom: 0.18rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.drops-card-artist {
    color: #999;
    font-size: 0.72rem;
    margin-bottom: 0.35rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.drops-card-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    color: #8a8a8a;
    font-size: 0.7rem;
}

.drops-card-type {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.2rem 0.5rem;
    background: rgba(var(--imu-gold-rgb), 0.1);
    border-radius: 20px;
    font-size: 0.62rem;
    color: var(--imu-gold, #d6ba66);
}

.drops-card-count { color: #8a8a8a; }

.drops-card-date {
    margin-top: 0.55rem;
    padding-top: 0.55rem;
    border-top: 1px solid rgba(var(--imu-gold-rgb), 0.15);
    color: #8a8a8a;
    font-size: 0.8rem;
    display: flex;
    align-items: center;
    gap: 0.4rem;
}
.drops-card-collected {
    margin-left: auto;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    color: #5dca8e;
    font-weight: 700;
    font-size: 0.78rem;
    background: rgba(93,202,142,.10);
    border: 1px solid rgba(93,202,142,.35);
    border-radius: 7px;
    padding: 2px 8px;
    white-space: nowrap;
}

/* Empty State */
.drops-empty {
    text-align: center;
    padding: 4rem 2rem;
    color: #8a8a8a;
    grid-column: 1 / -1;
    background: #12121a;
    border: 1px solid rgba(var(--imu-gold-rgb), 0.15);
    border-radius: 16px;
}

.drops-empty h3 {
    color: var(--imu-gold, #d6ba66);
    margin-bottom: 1rem;
    font-size: 1.5rem;
}

.drops-empty p {
    margin-bottom: 1.5rem;
    color: #8a8a8a;
}

.drops-empty a {
    display: inline-block;
    padding: 0.75rem 1.5rem;
    background: var(--imu-gold, #d6ba66);
    color: #000;
    text-decoration: none;
    border-radius: 8px;
    font-weight: 600;
    transition: background 0.2s;
}

.drops-empty a:hover {
    background: #e8d38a;
}

/* Back Link */
.drops-back-link {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    color: #8a8a8a;
    text-decoration: none;
    margin-bottom: 1.5rem;
    font-size: 0.9rem;
    transition: color 0.2s;
}

.drops-back-link:hover { color: var(--imu-gold, #d6ba66); }

/* Hidden for filtering */
.drops-card.hidden { display: none; }

/* v62 FIX: Sold Out styling */
.drops-card.sold-out {
    position: relative;
    opacity: 0.8;
}

.drops-card.sold-out .drops-card-image {
    filter: grayscale(40%);
}

.sold-out-badge {
    position: absolute;
    top: 12px;
    right: 12px;
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
    padding: 6px 12px;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.5px;
    border-radius: 6px;
    z-index: 10;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4);
    text-transform: uppercase;
}

.drops-card.sold-out:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.25);
}

/* v65 FIX: Paused styling */
.drops-card.paused {
    position: relative;
    opacity: 0.85;
}

.drops-card.paused .drops-card-image {
    filter: grayscale(20%) brightness(0.9);
}

.paused-badge {
    position: absolute;
    top: 12px;
    right: 12px;
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: #000;
    padding: 6px 12px;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.5px;
    border-radius: 6px;
    z-index: 10;
    box-shadow: 0 2px 8px rgba(245, 158, 11, 0.4);
    text-transform: uppercase;
}

.drops-card.paused:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.25);
}

/* v233: Scheduled mint badge */
.drops-card.scheduled { position: relative; }
.scheduled-badge {
    position: absolute;
    top: 12px;
    left: 12px;
    right: 12px;
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.95), rgba(139, 92, 246, 0.95));
    color: #fff;
    font-size: 0.72rem;
    font-weight: 700;
    padding: 6px 10px;
    border-radius: 8px;
    z-index: 10;
    text-align: center;
    backdrop-filter: blur(4px);
    letter-spacing: 0.03em;
    box-shadow: 0 2px 8px rgba(99, 102, 241, 0.4);
    text-transform: none;
}

/* v475: Open Mint badge — wide strip beside the type icon (does NOT overlap it).
   Mirrors .scheduled-badge styling for visual consistency across the grid. */
.open-mint-badge {
    position: absolute;
    top: 12px;
    left: 48px;            /* leaves room for the type icon at top-left */
    right: 12px;
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.95), rgba(5, 150, 105, 0.95));
    color: #fff;
    font-size: 0.65rem;    /* v477: reduced from 0.72rem to fit "Mint Closes in XD YH" comfortably */
    font-weight: 700;
    padding: 6px 10px;
    border-radius: 8px;
    z-index: 10;
    text-align: center;
    backdrop-filter: blur(4px);
    letter-spacing: 0.03em;
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.4);
    text-transform: none;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.open-mint-countdown {
    font-weight: 600;
    opacity: 0.95;
}

/* Responsive */
@media (max-width: 768px) {
    .drops-page-container { padding: 1rem 1rem 3rem; }
    .drops-page-header { flex-direction: column; align-items: flex-start; }
    .drops-page-header h1 { font-size: 1.5rem; }
    .drops-top-row { flex-direction: column; gap: 1rem; }
    .drops-filters-container { width: 100%; }
    .drops-filter-tabs { width: 100%; overflow-x: auto; }
    .drops-filter-tab { padding: 0.5rem 0.9rem; font-size: 0.8rem; white-space: nowrap; }
    .drops-status-tab, .drops-edition-tab, .drops-price-tab { padding: 0.5rem 0.9rem; font-size: 0.8rem; white-space: nowrap; }
    .drops-stats-bar { gap: 1rem; }
    .drops-stat .value { font-size: 1.1rem; }
    .drops-grid { grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
    .drops-card-content { padding: 0.75rem; }
    .drops-card-name { font-size: 0.85rem; }
    .drops-card-artist { font-size: 0.75rem; }
    .drops-card-meta { font-size: 0.75rem; }
    .drops-card-type { font-size: 0.65rem; padding: 0.2rem 0.5rem; }
    .drops-card-date { font-size: 0.75rem; margin-top: 0.5rem; padding-top: 0.5rem; }
}
</style>

<div class="drops-page-container">
    <a href="<?php echo esc_url(home_url('/')); ?>" class="drops-back-link">← Back to Marketplace</a>
    
    <div class="drops-page-header">
        <h1><span>🆕</span> New Drops</h1>
        <span class="drops-platform-badge">🏷️ Minted on IMCollectibles</span>
    </div>
    
    <div class="drops-top-row">
    <div class="drops-stats-bar">
        <div class="drops-stat">
            <span class="label">Available Collections</span>
            <span class="value"><?php echo esc_html($total_collections); ?></span>
        </div>
        <div class="drops-stat">
            <span class="label">Editions Available</span>
            <span class="value"><?php echo esc_html(number_format($total_available)); ?></span>
        </div>
    </div>
    
    <?php if (!empty($available_listings)): ?>
    <div class="drops-filters-container">
        <div class="drops-dd" id="drops-dd-type">
            <button type="button" class="drops-dd-trigger" aria-haspopup="true" aria-expanded="false">
                <span class="drops-dd-label">Type</span>
                <span class="drops-dd-value">All</span>
                <svg class="drops-dd-caret" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
            </button>
            <div class="drops-dd-menu" id="drops-type-filter">
                <button class="drops-filter-tab active" data-filter="all">All</button>
                <button class="drops-filter-tab" data-filter="music">🎵 Music</button>
                <button class="drops-filter-tab" data-filter="album">💿 Album</button>
                <button class="drops-filter-tab" data-filter="musicvideo">🎬 Music Video</button>
                <button class="drops-filter-tab" data-filter="art">🎨 Art</button>
                <button class="drops-filter-tab" data-filter="film">🎥 Film</button>
                <button class="drops-filter-tab" data-filter="audiobook">🎧 AudioBook</button>
                <button class="drops-filter-tab" data-filter="ebook">📖 eBook</button>
            </div>
        </div>
        <div class="drops-dd" id="drops-dd-status">
            <button type="button" class="drops-dd-trigger" aria-haspopup="true" aria-expanded="false">
                <span class="drops-dd-label">Status</span>
                <span class="drops-dd-value">All</span>
                <svg class="drops-dd-caret" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
            </button>
            <div class="drops-dd-menu" id="drops-status-filter">
                <button class="drops-status-tab active" data-status="all">All</button>
                <button class="drops-status-tab" data-status="available">✅ Available</button>
                <button class="drops-status-tab" data-status="scheduled">🚀 Coming Soon</button>
                <button class="drops-status-tab" data-status="sold-out">🔴 Sold Out</button>
            </div>
        </div>
        <div class="drops-dd" id="drops-dd-edition">
            <button type="button" class="drops-dd-trigger" aria-haspopup="true" aria-expanded="false">
                <span class="drops-dd-label">Edition</span>
                <span class="drops-dd-value">All</span>
                <svg class="drops-dd-caret" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
            </button>
            <div class="drops-dd-menu" id="drops-edition-filter">
                <button class="drops-edition-tab active" data-edition="all">All</button>
                <button class="drops-edition-tab" data-edition="open">🟢 Open Edition</button>
                <button class="drops-edition-tab" data-edition="fixed">📦 Fixed</button>
            </div>
        </div>
        <div class="drops-dd" id="drops-dd-price">
            <button type="button" class="drops-dd-trigger" aria-haspopup="true" aria-expanded="false">
                <span class="drops-dd-label">Price</span>
                <span class="drops-dd-value">Newest</span>
                <svg class="drops-dd-caret" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
            </button>
            <div class="drops-dd-menu" id="drops-price-filter">
                <button class="drops-price-tab" data-sort="asc">↑ Lowest to Highest</button>
                <button class="drops-price-tab" data-sort="desc">↓ Highest to Lowest</button>
            </div>
        </div>
        <div class="drops-price-disclaimer" id="drops-price-disclaimer">Sorted by XRP price. Listings priced in USD or XRPL tokens are shown at the end.</div>
    </div>
    <?php endif; ?>
    </div>
    
    <div class="drops-grid" id="drops-grid">
        <?php if (empty($available_listings)): ?>
            <div class="drops-empty">
                <h3>🎨 No Collections Yet</h3>
                <p>Be the first to mint a collection on IMCollectibles!</p>
                <a href="<?php echo esc_url(home_url('/upload/')); ?>">Create an NFT →</a>
            </div>
        <?php else: ?>
            <?php
            // v579: "Collected ×N" badge — per-viewer count of editions collected per listing.
            // Keyed by listing_id; counts minted+claimed only; empty when no wallet connected.
            // Kill-switch: define('IMC_COLLECTED_BADGE_DISABLED', true) in wp-config.php.
            $imc_collected_map = [];
            if (!defined('IMC_COLLECTED_BADGE_DISABLED') || !IMC_COLLECTED_BADGE_DISABLED) {
                $imc_viewer = function_exists('imc_session_wallet') ? imc_session_wallet() : '';
                if (preg_match('/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/', $imc_viewer)) {
                    $imc_pt = $wpdb->prefix . 'imc_purchases';
                    if ($wpdb->get_var("SHOW TABLES LIKE '$imc_pt'") === $imc_pt) {
                        $imc_rows = $wpdb->get_results($wpdb->prepare(
                            "SELECT listing_id, COUNT(*) AS n FROM $imc_pt
                             WHERE buyer_account = %s AND mint_status IN ('minted','claimed')
                             GROUP BY listing_id", $imc_viewer), ARRAY_A);
                        foreach (($imc_rows ?: []) as $imc_r) { $imc_collected_map[(int)$imc_r['listing_id']] = (int)$imc_r['n']; }
                    }
                }
            }
            ?>
            <?php $rm_order = 0; foreach ($available_listings as $listing): 
                // Get cover image
                $cover_url = '/wp-content/uploads/fallback-nft.svg';
                $card_cover = $listing['listing_cover_ipfs'] ?? $listing['cover_ipfs'];
                if (!empty($card_cover)) {
                    $cid = str_replace('ipfs://', '', $card_cover);
                    $cover_url = 'https://metadata.imcollectibles.io/img.php?url=' . urlencode('ipfs://' . $cid) . '&thumb=1'; // L3b: listing card — thumb
                }
                
                // Format date
                $date = new DateTime($listing['created_at']);
                $now = new DateTime();
                $diff = $now->diff($date);
                
                if ($diff->days === 0) {
                    $date_display = 'Today';
                } elseif ($diff->days === 1) {
                    $date_display = 'Yesterday';
                } elseif ($diff->days < 7) {
                    $date_display = $diff->days . ' days ago';
                } else {
                    $date_display = $date->format('M j, Y');
                }
                
                // Media type for filtering
                // v137: Use actual nft_type for filter matching
                $media_filter = $listing['nft_type'] ?: 'music';
                
                // Build collection URL
                $collection_url = home_url('/collections/') . '?issuer=' . urlencode($listing['artist_account']) . '&taxon=' . urlencode($listing['collection_taxon']);
                
                // v62 FIX: Check if sold out
                // v65 FIX: Also check for paused status
                // v233: Detect future scheduled mints (not paused or sold out — matches trading.js logic)
                // v393: Open editions are never sold out while active (closed OEs display as sold out)
                $is_open_edition = (($listing['edition_type'] ?? 'fixed') === 'open') && empty($listing['open_edition_closed_at']);
                $is_sold_out = !$is_open_edition && ($listing['status'] === 'sold_out' || intval($listing['available_count']) <= 0);
                $is_paused = ($listing['status'] === 'paused');
                $is_scheduled = (!$is_sold_out && !$is_paused
                    && $listing['launch_type'] === 'scheduled'
                    && !empty($listing['launch_at'])
                    && strtotime($listing['launch_at']) > time());
            ?>
            <?php
                // v23: Determine status for JS filtering
                if ($is_scheduled) { $card_status = 'scheduled'; }
                elseif ($is_sold_out) { $card_status = 'sold-out'; }
                elseif ($is_paused) { $card_status = 'paused'; }
                else { $card_status = 'available'; }
                // v571: edition dimension for the Open Edition / Fixed filter row
                $card_edition = $is_open_edition ? 'open' : 'fixed';
                // v650: XRP price-sort keys. Only static XRP-priced listings are sortable;
                // USD (dynamic) and token-priced (price_xrp=0) listings sort to the end.
                $rm_xrp = floatval($listing['price_xrp'] ?? 0);
                $rm_sortable = ((($listing['pricing_mode'] ?? 'static') !== 'dynamic') && $rm_xrp > 0) ? 1 : 0;
            ?>
            <a href="<?php echo esc_url($collection_url); ?>" class="drops-card<?php echo $is_sold_out ? ' sold-out' : ''; ?><?php echo $is_paused ? ' paused' : ''; ?><?php echo $is_scheduled ? ' scheduled' : ''; ?>" data-type="<?php echo esc_attr($media_filter); ?>" data-status="<?php echo esc_attr($card_status); ?>" data-edition="<?php echo esc_attr($card_edition); ?>" data-sort-price="<?php echo $rm_sortable ? number_format($rm_xrp, 6, '.', '') : '0'; ?>" data-price-sortable="<?php echo $rm_sortable; ?>" data-order="<?php echo $rm_order++; ?>">
                <?php if ($is_scheduled): ?>
                    <div class="scheduled-badge" data-launch="<?php echo esc_attr($listing['launch_at']); ?>" data-tz="<?php echo esc_attr($listing['launch_timezone'] ?? 'UTC'); ?>">
                        🚀 Mint Launch: <span class="launch-time-local"></span>
                    </div>
                <?php elseif ($is_sold_out): ?>
                    <div class="sold-out-badge">SOLD OUT</div>
                <?php elseif ($is_paused): ?>
                    <div class="paused-badge">PAUSED</div>
                <?php elseif ($is_open_edition): ?>
                    <?php if (!empty($listing['open_edition_ends_at'])): ?>
                        <div class="open-mint-badge" data-ends="<?php echo esc_attr($listing['open_edition_ends_at']); ?>">
                            <span class="open-mint-countdown">Mint Closes in --</span>
                        </div>
                    <?php else: ?>
                        <div class="open-mint-badge">OPEN MINT</div>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if (!empty($listing['ai_generated'])): ?>
                    <span class="imc-ai-badge" data-mode="<?php echo esc_attr($listing['ai_mode'] ?? ''); ?>" data-platform="<?php echo esc_attr($listing['ai_platform'] ?? ''); ?>" aria-label="AI generated — hover for details">🤖 AI</span>
                <?php endif; ?>
                <img src="<?php echo esc_url($cover_url); ?>" 
                     alt="<?php echo esc_attr($listing['nft_name'] ?: $listing['collection_name']); ?>" 
                     class="drops-card-image"
                     onerror="this.src='/wp-content/uploads/fallback-nft.svg'">
                <div class="drops-card-content">
                    <div class="drops-card-name"><?php echo esc_html($listing['nft_name'] ?: $listing['collection_name']); ?></div>
                    <?php
                    $rm_artist = function_exists('imc_resolve_artist_name')
                        ? imc_resolve_artist_name($listing['artist_account'] ?? '', $listing['artist_name'] ?? '', '')
                        : ($listing['artist_name'] ?? '');
                    if ($rm_artist !== ''): ?>
                        <div class="drops-card-artist"><?php echo esc_html($rm_artist); ?></div>
                    <?php endif; ?>
                    <div class="drops-card-meta">
                        <span class="drops-card-type">
                            <?php 
                            // F4: books were falling through to the Music Access label - the very types
                            // the filter above can select.
                            $type_labels = [
                                'music' => '🎵 Music Access',
                                'musicvideo' => '🎬 Music Video Access',
                                'art' => '🎨 Art Access',
                                'film' => '🎥 Film Access',
                                'album' => '💿 Album Access',
                                'audiobook' => '🎧 AudioBook Access',
                                'ebook' => '📖 eBook Access'
                            ];
                            echo $type_labels[$listing['nft_type']] ?? '🎵 Music Access';
                            ?>
                        </span>
                        <span class="drops-card-count">
                            <?php if ($is_open_edition && intval($listing['total_editions']) === 0): ?>
                                <?php echo esc_html($listing['minted_count']); ?> minted (???)
                            <?php elseif ($is_sold_out): ?>
                                <?php echo esc_html($listing['total_editions']); ?> minted
                            <?php else: ?>
                                <?php echo esc_html($listing['available_count']); ?> left
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="drops-card-price">
                        <?php 
                        // v85: Check for dynamic pricing
                        $pricing_mode = $listing['pricing_mode'] ?? 'static';
                        $price_usd = !empty($listing['price_usd']) ? floatval($listing['price_usd']) : null;
                        
                        // v665: token-agnostic primary price -- imc_primary_display_price()
                        // resolves free / dynamic USD / pwyw floor / XRP / first priced token,
                        // so this single output covers every pricing mode.
                        ?>
                            <span style="color: var(--imu-gold, #d6ba66); font-weight: 600;"><?php echo esc_html(imc_primary_display_price($listing)); ?></span>
                            <?php echo imc_token_prices_note($listing); ?>
                    </div>
                    <div class="drops-card-date">
                        <span>Added <?php echo esc_html($date_display); ?></span>
                        <?php $imc_cn = isset($imc_collected_map[(int)$listing["id"]]) ? (int)$imc_collected_map[(int)$listing["id"]] : 0; if ($imc_cn > 0): ?>
                        <span class="drops-card-collected" title="You have collected <?php echo esc_attr($imc_cn); ?> from this listing">&#10003; Collected &times;<?php echo esc_html($imc_cn); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($available_listings)): ?>
<script>
(function() {
    // v23: Combined type + status filter
    let activeTypeFilter = 'all';
    let activeStatusFilter = 'all';
    let activeEditionFilter = 'all';

    function applyFilters() {
        document.querySelectorAll('.drops-card').forEach(card => {
            const typeMatch = activeTypeFilter === 'all' || card.dataset.type === activeTypeFilter;
            const statusMatch = activeStatusFilter === 'all' || card.dataset.status === activeStatusFilter;
            const editionMatch = activeEditionFilter === 'all' || card.dataset.edition === activeEditionFilter;
            if (typeMatch && statusMatch && editionMatch) {
                card.classList.remove('hidden');
            } else {
                card.classList.add('hidden');
            }
        });
    }

    // Type filter tabs
    document.querySelectorAll('.drops-filter-tab').forEach(tab => {
        tab.addEventListener('click', function() {
            document.querySelectorAll('.drops-filter-tab').forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            activeTypeFilter = this.dataset.filter;
            applyFilters();
        });
    });

    // Status filter tabs (v23)
    document.querySelectorAll('.drops-status-tab').forEach(tab => {
        tab.addEventListener('click', function() {
            document.querySelectorAll('.drops-status-tab').forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            activeStatusFilter = this.dataset.status;
            applyFilters();
        });
    });

    // v571: Edition filter tabs (Open Edition / Fixed)
    document.querySelectorAll('.drops-edition-tab').forEach(tab => {
        tab.addEventListener('click', function() {
            document.querySelectorAll('.drops-edition-tab').forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            activeEditionFilter = this.dataset.edition;
            applyFilters();
        });
    });

    // v650: Price sort (XRP asc/desc). USD/token-priced listings pushed to the end
    // in both directions. Toggling the active direction returns to default order.
    var activePriceSort = 'none';
    var priceGrid = document.getElementById('drops-grid');
    var priceDisclaimer = document.getElementById('drops-price-disclaimer');
    function applyPriceSort() {
        if (!priceGrid) return;
        var cards = Array.prototype.slice.call(priceGrid.querySelectorAll('.drops-card'));
        cards.sort(function(a, b) {
            if (activePriceSort === 'none') {
                return (+a.dataset.order) - (+b.dataset.order);
            }
            var sa = +a.dataset.priceSortable, sb = +b.dataset.priceSortable;
            if (sa !== sb) return sb - sa;                 // sortable (1) before non-sortable (0)
            if (sa === 0) return (+a.dataset.order) - (+b.dataset.order); // stable at the end
            var pa = parseFloat(a.dataset.sortPrice) || 0, pb = parseFloat(b.dataset.sortPrice) || 0;
            if (pa !== pb) return activePriceSort === 'asc' ? (pa - pb) : (pb - pa);
            return (+a.dataset.order) - (+b.dataset.order); // price tie -> stable
        });
        cards.forEach(function(card) { priceGrid.appendChild(card); });
        if (priceDisclaimer) priceDisclaimer.classList.toggle('show', activePriceSort !== 'none');
    }
    /* ------------------------------------------------------------------
       Filter dropdowns. The option buttons keep their original classes and
       data attributes, so every existing filter handler above still fires;
       this only opens/closes the menus and mirrors the choice in the label.
       ------------------------------------------------------------------ */
    (function initDropsDropdowns() {
        const dds = document.querySelectorAll('.drops-dd');
        if (!dds.length) return;

        function closeAll(except) {
            dds.forEach(function (dd) {
                if (dd === except) return;
                dd.classList.remove('open');
                const tr = dd.querySelector('.drops-dd-trigger');
                if (tr) tr.setAttribute('aria-expanded', 'false');
            });
        }

        dds.forEach(function (dd) {
            const trigger = dd.querySelector('.drops-dd-trigger');
            const valueEl = dd.querySelector('.drops-dd-value');
            if (!trigger) return;

            trigger.addEventListener('click', function (e) {
                e.stopPropagation();
                const willOpen = !dd.classList.contains('open');
                closeAll(dd);
                dd.classList.toggle('open', willOpen);
                trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            });

            // Mirror the chosen option into the trigger, then close.
            dd.querySelectorAll('.drops-dd-menu button').forEach(function (opt) {
                opt.addEventListener('click', function () {
                    if (valueEl) valueEl.textContent = opt.textContent.trim();
                    dd.classList.remove('open');
                    trigger.setAttribute('aria-expanded', 'false');
                });
            });
        });

        document.addEventListener('click', function () { closeAll(null); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeAll(null);
        });
    })();

    document.querySelectorAll('.drops-price-tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            var dir = this.dataset.sort;
            if (activePriceSort === dir) {
                activePriceSort = 'none';
                this.classList.remove('active');
            } else {
                activePriceSort = dir;
                document.querySelectorAll('.drops-price-tab').forEach(function(t) { t.classList.remove('active'); });
                this.classList.add('active');
            }
            applyPriceSort();
        });
    });

    // v233: Convert scheduled mint launch times to user's local timezone
    document.querySelectorAll('.scheduled-badge').forEach(function(badge) {
        const utcTime = badge.dataset.launch;
        if (!utcTime) return;
        const localDate = new Date(utcTime.includes('Z') ? utcTime : utcTime + 'Z');
        const now = new Date();
        const diffMs = localDate - now;
        let timeStr;
        if (diffMs <= 0) {
            timeStr = 'Live Now!';
        } else {
            const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));
            const diffHours = Math.floor((diffMs % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const diffMins = Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60));
            if (diffDays > 0) {
                timeStr = localDate.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
                        + ' at ' + localDate.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
            } else if (diffHours > 0) {
                timeStr = 'In ' + diffHours + 'h ' + diffMins + 'm';
            } else {
                timeStr = 'In ' + diffMins + 'm';
            }
        }
        const span = badge.querySelector('.launch-time-local');
        if (span) span.textContent = timeStr;
    });

    // v475: Open Mint countdown ticker for the /recent-mints/ grid.
    // Mirrors the homepage trading.js setupOpenMintCountdowns logic — 60s tick,
    // days/hours/minutes display. Updates all .open-mint-badge[data-ends] in place.
    function updateOpenMintCountdowns() {
        const badges = document.querySelectorAll('.open-mint-badge[data-ends]');
        if (badges.length === 0) return;
        const now = Date.now();
        badges.forEach(function(badge) {
            const endsUtc = badge.dataset.ends;
            if (!endsUtc) return;
            const normalised = endsUtc.includes('Z') ? endsUtc : endsUtc.replace(' ', 'T') + 'Z';
            const endsMs = new Date(normalised).getTime();
            const countdownEl = badge.querySelector('.open-mint-countdown');
            if (!countdownEl) return;
            const diff = endsMs - now;
            if (diff <= 0) {
                countdownEl.textContent = 'Ending soon';
                return;
            }
            const totalMins = Math.floor(diff / 60000);
            const days = Math.floor(totalMins / 1440);
            const hours = Math.floor((totalMins % 1440) / 60);
            const mins = totalMins % 60;
            let text;
            if (days > 0) {
                text = 'Mint Closes in ' + days + 'D ' + hours + 'H';
            } else if (hours > 0) {
                text = 'Mint Closes in ' + hours + 'H ' + mins + 'M';
            } else {
                text = 'Mint Closes in ' + mins + 'M';
            }
            countdownEl.textContent = text;
        });
    }
    updateOpenMintCountdowns();
    setInterval(updateOpenMintCountdowns, 60000);
})();
</script>
<?php endif; ?>

<!-- v632 Step6 Surface2: AI badge hover tooltip (shared pattern; guarded against double-init) -->
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

<?php if (function_exists('imc_xrplto_attribution')) imc_xrplto_attribution(); ?>
<?php get_footer(); ?>